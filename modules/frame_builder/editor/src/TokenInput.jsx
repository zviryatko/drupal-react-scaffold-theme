// Text input or textarea with tokens: typing "[" suggests token parts step by step (type, token, chained token). Two
// buttons in the field, shown on hover and focus: one opens the list of tokens to insert one where the caret is, the
// other clears the field.
import { useLayoutEffect, useRef, useState } from 'react';
import { loadTokens, openToken, suggest } from './tokens';
import { TokenBrowser } from './TokenBrowser';

// Drawn in a circle: a cross, and a T for tokens.
const circle = (children) => (
  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
    <circle cx="10" cy="10" r="8.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
    {children}
  </svg>
);
const CLEAR = circle(<path d="M7 7l6 6M13 7l-6 6" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />);
const TOKEN = circle(<path d="M6.5 6.5h7M10 6.5v7.5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />);

/**
 * The buttons inside a field, at its end: insert a token (when the field takes tokens) and clear it (when it has a
 * value). `label` names the field for screen readers.
 */
export function FieldActions({ label, empty, onClear, onTokens }) {
  if (empty && !onTokens) return null;
  return (
    <span className="frame-builder-field-actions">
      {onTokens && (
        <button type="button" className="frame-builder-field-action frame-builder-field-action--token" aria-haspopup="dialog"
                aria-label={`Insert a token into ${label}`} title="Insert a token" onMouseDown={(event) => event.preventDefault()} onClick={onTokens}>{TOKEN}</button>
      )}
      {!empty && (
        <button type="button" className="frame-builder-field-action frame-builder-field-action--clear"
                aria-label={`Clear ${label}`} title="Clear" onMouseDown={(event) => event.preventDefault()} onClick={onClear}>{CLEAR}</button>
      )}
    </span>
  );
}

export function TokenInput({ id, value, onChange, readOnly, multiline = false, tokens = true, placeholder, onInput, children, label = 'the field', onKeys, listbox }) {
  const element = useRef(null);
  const caret = useRef(null);
  // Where the caret was when the field lost the focus: a token picked in the list goes there.
  const lastSelection = useRef(null);
  const [tree, setTree] = useState(null);
  const [state, setState] = useState(null); // { start, items, active }
  const [browsing, setBrowsing] = useState(false);
  const text = value ?? '';

  // Put the caret back after a token was inserted: React moves it to the end when the value changes from outside.
  useLayoutEffect(() => {
    if (caret.current !== null && element.current) {
      element.current.setSelectionRange(caret.current, caret.current);
      caret.current = null;
    }
  }, [text]);

  const refresh = (nextText, position, knownTree = tree) => {
    const open = tokens ? openToken(nextText, position) : null;
    if (!open) return setState(null);
    if (!knownTree) {
      loadTokens().then((loaded) => { setTree(loaded); refresh(nextText, position, loaded); }).catch(() => {});
      return undefined;
    }
    const items = suggest(knownTree, open.segments);
    return setState(items.length ? { start: open.start, items, active: 0 } : null);
  };

  const replace = (from, to, insert) => {
    const next = text.slice(0, from) + insert + text.slice(to);
    caret.current = from + insert.length;
    onChange(next);
    return [next, caret.current];
  };

  const pick = (item) => {
    const [next, position] = replace(state.start, element.current.selectionStart, item.insert);
    element.current.focus();
    item.open ? refresh(next, position) : setState(null);
  };

  const onKeyDown = (event) => {
    // Without token suggestions open, the keys go to the suggestions of the field (the content of a link field).
    if (!state) { onKeys?.(event); return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const step = event.key === 'ArrowDown' ? 1 : -1;
      setState({ ...state, active: (state.active + step + state.items.length) % state.items.length });
    }
    else if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault();
      pick(state.items[state.active]);
    }
    else if (event.key === 'Escape') {
      event.stopPropagation();
      setState(null);
    }
  };

  const props = {
    id,
    ref: element,
    className: `frame-builder-input${multiline ? ' frame-builder-input--textarea' : ''}`,
    value: text,
    readOnly,
    placeholder,
    autoComplete: 'off',
    role: tokens || listbox ? 'combobox' : undefined,
    'aria-expanded': tokens || listbox ? Boolean(state) || Boolean(listbox?.open) : undefined,
    'aria-autocomplete': tokens || listbox ? 'list' : undefined,
    'aria-controls': listbox?.open ? listbox.id : undefined,
    'aria-activedescendant': listbox?.open && listbox.active ? listbox.active : undefined,
    onChange: (event) => {
      onChange(event.target.value);
      onInput?.(event.target.value);
      refresh(event.target.value, event.target.selectionStart);
    },
    onKeyDown,
    onClick: (event) => refresh(text, event.target.selectionStart),
    onBlur: (event) => {
      lastSelection.current = [event.target.selectionStart, event.target.selectionEnd];
      setTimeout(() => setState(null), 150);
    },
  };

  const clear = () => {
    onChange('');
    onInput?.('');
    setState(null);
    lastSelection.current = null;
    element.current?.focus();
  };
  const insert = (token) => {
    const [from, to] = lastSelection.current ?? [text.length, text.length];
    setBrowsing(false);
    replace(Math.min(from, text.length), Math.min(to, text.length), token);
    element.current?.focus();
  };
  const actions = !readOnly && (
    <FieldActions label={label} empty={text === ''} onClear={clear} onTokens={tokens ? () => setBrowsing(true) : null} />
  );

  return (
    <div className="frame-builder-combo">
      <div className={`frame-builder-input-wrap${multiline ? ' frame-builder-input-wrap--textarea' : ''}${!readOnly ? ` has-actions${tokens ? ' has-token' : ''}` : ''}`}>
        {multiline ? <textarea rows={4} {...props} /> : <input type="text" {...props} />}
        {actions}
      </div>
      {browsing && <TokenBrowser onClose={() => { setBrowsing(false); element.current?.focus(); }} onPick={insert} />}
      {state && (
        <ul className="frame-builder-autocomplete" role="listbox">
          {state.items.map((item, index) => (
            <li key={item.insert} role="option" aria-selected={index === state.active}
                className={index === state.active ? 'is-active' : undefined}
                onMouseDown={(event) => { event.preventDefault(); pick(item); }}
                onMouseEnter={() => setState({ ...state, active: index })}>
              <span className="frame-builder-autocomplete__label">{item.label}{item.open && ' ›'}</span>
              <code>{item.insert}</code>
            </li>
          ))}
        </ul>
      )}
      {children}
    </div>
  );
}
