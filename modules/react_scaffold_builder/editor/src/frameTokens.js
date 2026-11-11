// Token autocomplete for the rich text fields of the node form (the frames of the editor): typing "[" suggests token
// parts step by step, as in the text of components. Built separately (vite.frame.config.js): the frames are pages
// of their own, without the editor and without React.
import { attachTokenAutocomplete } from './ckeditorTokens';

const attached = new WeakSet();
let list = null;

/** Draws the suggestions at the caret, or removes them. */
function draw(state, controller) {
  list?.remove();
  list = null;
  if (!state) return;
  list = document.body.appendChild(document.createElement('ul'));
  list.className = 'rsb-autocomplete';
  list.setAttribute('role', 'listbox');
  list.style.left = `${Math.min(state.left, window.innerWidth - 290)}px`;
  list.style.top = `${state.top}px`;
  state.items.forEach((item, index) => {
    const entry = list.appendChild(document.createElement('li'));
    entry.setAttribute('role', 'option');
    entry.setAttribute('aria-selected', String(index === state.active));
    if (index === state.active) entry.className = 'is-active';
    entry.appendChild(document.createElement('span')).textContent = item.label + (item.open ? ' ›' : '');
    entry.appendChild(document.createElement('code')).textContent = item.insert;
    // mousedown, not click: the editor must keep the focus.
    entry.addEventListener('mousedown', (event) => { event.preventDefault(); controller.pick(item); });
    entry.addEventListener('mouseenter', () => controller.activate(index));
  });
  list.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' });
}

// Drupal creates the editors asynchronously, and more of them as fields are added.
setInterval(() => {
  window.Drupal?.CKEditor5Instances?.forEach((editor) => {
    if (attached.has(editor)) return;
    attached.add(editor);
    const controller = attachTokenAutocomplete(editor, (state) => draw(state, controller));
  });
}, 500);
