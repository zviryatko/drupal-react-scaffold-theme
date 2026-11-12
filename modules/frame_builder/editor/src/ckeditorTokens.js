// Token autocomplete for CKEditor 5, attached to an editor instance.
//
// Drupal builds the editor from the text format, so this is not a toolbar plugin that every format would have to
// enable: it listens to the instance the builder created. It watches the text before the caret, offers the same
// suggestions as the plain text fields and replaces the typed part through the editor model (undo works).
import { loadTokens, openToken, suggest } from './tokens';

const KEYS = { ArrowDown: 40, ArrowUp: 38, Enter: 13, Tab: 9, Escape: 27 };

/**
 * @param editor CKEditor 5 instance.
 * @param {(state: null | { items: object[], active: number, left: number, top: number }) => void} onState
 *   Called with what to show; the caller draws the list.
 * @returns {{ pick: (item: object) => void, activate: (index: number) => void }}
 */
export function attachTokenAutocomplete(editor, onState) {
  let tree = null;
  let state = null; // + `length`: characters of the open token before the caret.
  let timer = null;

  const set = (next) => { state = next; onState(next); };

  /** Text of the current block before the caret, from the last inline object on. */
  const textBeforeCaret = () => {
    const selection = editor.model.document.selection;
    if (!selection.isCollapsed) return null;
    const position = selection.getFirstPosition();
    let text = '';
    for (const child of position.parent.getChildren()) {
      if (child.startOffset >= position.offset) break;
      text = child.is('$text') ? text + child.data.slice(0, position.offset - child.startOffset) : '';
    }
    return text;
  };

  const caretPoint = () => {
    const selection = window.getSelection();
    const range = selection.rangeCount ? selection.getRangeAt(0) : null;
    const rect = range?.getClientRects()[0] || range?.startContainer.parentElement?.getBoundingClientRect() || editor.ui.getEditableElement().getBoundingClientRect();
    return { left: rect.left, top: rect.bottom + 4 };
  };

  const refresh = () => {
    if (!editor.ui.focusTracker.isFocused) return set(null);
    const text = textBeforeCaret();
    const open = text === null ? null : openToken(text, text.length);
    if (!open) return set(null);
    if (!tree) {
      loadTokens().then((loaded) => { tree = loaded; refresh(); }).catch(() => {});
      return undefined;
    }
    const items = suggest(tree, open.segments);
    if (!items.length) return set(null);
    const active = state && state.items.length === items.length ? state.active : 0;
    return set({ items, active, length: text.length - open.start, ...caretPoint() });
  };

  // The DOM selection follows the model after the view is rendered.
  const schedule = () => { clearTimeout(timer); timer = setTimeout(refresh, 0); };

  const pick = (item) => {
    if (!state) return;
    const { length } = state;
    editor.model.change((writer) => {
      const selection = editor.model.document.selection;
      const end = selection.getFirstPosition();
      const start = end.getShiftedBy(-length);
      const attributes = Object.fromEntries(selection.getAttributes());
      writer.remove(writer.createRange(start, end));
      writer.insertText(item.insert, attributes, start);
      writer.setSelection(start.getShiftedBy(item.insert.length));
    });
    editor.editing.view.focus();
    if (!item.open) set(null);
  };

  editor.model.document.on('change:data', schedule);
  editor.model.document.selection.on('change:range', schedule);
  editor.ui.focusTracker.on('change:isFocused', (event, name, focused) => { if (!focused) setTimeout(() => set(null), 150); });
  editor.editing.view.document.on('keydown', (event, data) => {
    if (!state) return;
    const stop = () => { data.preventDefault(); data.stopPropagation(); event.stop(); };
    if (data.keyCode === KEYS.ArrowDown || data.keyCode === KEYS.ArrowUp) {
      const step = data.keyCode === KEYS.ArrowDown ? 1 : -1;
      set({ ...state, active: (state.active + step + state.items.length) % state.items.length });
      stop();
    }
    else if (data.keyCode === KEYS.Enter || data.keyCode === KEYS.Tab) {
      pick(state.items[state.active]);
      stop();
    }
    else if (data.keyCode === KEYS.Escape) {
      set(null);
      stop();
    }
  }, { priority: 'highest' });

  return { pick, activate: (index) => state && set({ ...state, active: index }) };
}
