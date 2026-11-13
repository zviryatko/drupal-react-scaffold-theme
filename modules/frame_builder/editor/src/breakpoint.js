// The breakpoint of the theme whose layout and style are being edited. A component has its layout and style at every
// width; from a breakpoint on it can have others ("on tablets and wider: more padding"). The choice is one for the
// whole editor: the fields of every component show the values of that breakpoint, and the canvas shows the page as
// it is from that breakpoint on, whatever the width of the window.
import { useSyncExternalStore } from 'react';

// '' is every width.
let current = '';
const listeners = new Set();
const subscribe = (listener) => { listeners.add(listener); return () => listeners.delete(listener); };

export const getBreakpoint = () => current;
export const setBreakpoint = (id) => { current = id || ''; listeners.forEach((listener) => listener()); };
export const useBreakpoint = () => useSyncExternalStore(subscribe, getBreakpoint);

/**
 * The value of a feature at a breakpoint, and the whole value with a part of it changed.
 *
 * A value is what the feature has at every width, with the values per breakpoint under `at`:
 * `{ margin: …, at: { tablet: { margin: … } } }`. A feature whose value is a word (the alignment) is that word while
 * nothing differs, and `{ value: 'center', at: { tablet: 'start' } }` when something does.
 */
export function splitValue(value, plain) {
  const object = value && typeof value === 'object' && !Array.isArray(value);
  const overrides = (object && value.at && typeof value.at === 'object' && value.at) || {};
  if (plain) return { base: object ? value.value || '' : value || '', overrides };
  const { at, ...base } = object ? value : {};
  return { base: Object.keys(base).length ? base : null, overrides };
}

const empty = (value) => value === null || value === undefined || value === '' || (typeof value === 'object' && !Object.keys(value).length);

export function joinValue(base, overrides, plain) {
  const at = Object.fromEntries(Object.entries(overrides).filter(([, value]) => !empty(value)));
  const differs = Object.keys(at).length > 0;
  if (plain) return differs ? { value: base || '', at } : base || '';
  if (!differs) return empty(base) ? null : base;
  return { ...(base || {}), at };
}
