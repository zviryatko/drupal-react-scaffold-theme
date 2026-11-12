// Text input or textarea with tokens: typing "[" suggests token parts step by step (type, token, chained token), and
// "Browse available tokens" opens the token tree in a dialog.
import { useLayoutEffect, useRef, useState } from 'react';
import { loadTokens, openToken, suggest } from './tokens';
import { TokenBrowser } from './TokenBrowser';

export function TokenInput({ id, value, onChange, readOnly, multiline = false, tokens = true, placeholder, onInput, children }) {
  const element = useRef(null);
  const caret = useRef(null);
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
    if (!state) return;
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
    role: tokens ? 'combobox' : undefined,
    'aria-expanded': tokens ? Boolean(state) : undefined,
    'aria-autocomplete': tokens ? 'list' : undefined,
    onChange: (event) => {
      onChange(event.target.value);
      onInput?.(event.target.value);
      refresh(event.target.value, event.target.selectionStart);
    },
    onKeyDown,
    onClick: (event) => refresh(text, event.target.selectionStart),
    onBlur: () => setTimeout(() => setState(null), 150),
  };

  return (
    <div className="frame-builder-combo">
      {multiline ? <textarea rows={4} {...props} /> : <input type="text" {...props} />}
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
      {tokens && !readOnly && (
        <button type="button" className="frame-builder-link frame-builder-token-link" onClick={() => setBrowsing(true)}>Browse available tokens</button>
      )}
      {browsing && (
        <TokenBrowser
          onClose={() => setBrowsing(false)}
          onPick={(token) => {
            const at = element.current?.selectionStart ?? text.length;
            replace(at, at, token);
            setBrowsing(false);
          }}
        />
      )}
    </div>
  );
}
