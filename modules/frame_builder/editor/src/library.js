// The library of saved blocks, as the editor knows it: the components of the kind "drupal_saved:<uuid>" that Drupal
// sent, plus what was saved, renamed or deleted since. The Puck configuration and the Blocks panel follow it.
import { useSyncExternalStore } from 'react';
import { settings } from './api';
import { mapTypes, toDrupalType, toPuckType } from './types';

const listeners = new Set();
let version = 0;
const changed = () => { version += 1; listeners.forEach((listener) => listener()); };

export const subscribeLibrary = (listener) => { listeners.add(listener); return () => listeners.delete(listener); };
export const useLibraryVersion = () => useSyncExternalStore(subscribeLibrary, () => version);

/** The saved block with this UUID, when it (still) exists. */
export const savedBlock = (uuid) => settings.components.find((component) => component.saved === uuid && !component.deleted);

/** Adds a saved block, or replaces what is known about it. */
export function putSavedBlock(component) {
  const at = settings.components.findIndex((existing) => existing.id === component.id);
  if (at >= 0) settings.components[at] = component; else settings.components.push(component);
  changed();
}

/** A deleted block stays known, so what is on the canvas keeps working, but is no longer offered. */
export function dropSavedBlock(uuid) {
  const component = settings.components.find((existing) => existing.saved === uuid);
  if (component) { component.deleted = true; changed(); }
}

const newId = (type) => `${type}-${(crypto.randomUUID?.() || String(Math.random()).slice(2)).replaceAll('-', '').slice(0, 12)}`;

/** A Puck item as Drupal keeps it in the library. */
export const toSavedItem = (item) => mapTypes([item], toDrupalType, { versions: 'toItem' })[0];

/**
 * An item of the library as a Puck item of this page: new ids, so it can be on a page more than once. The first one
 * takes the id of the item it replaces (Puck replaces an item only by one with the same id).
 */
export function fromSavedItem(item, id) {
  const fresh = (entry) => ({
    ...entry,
    props: Object.fromEntries(Object.entries(entry.props).map(([name, value]) => [
      name,
      Array.isArray(value) && value.length && value.every((child) => child && typeof child === 'object' && typeof child.type === 'string' && child.props) ? value.map(fresh) : value,
    ]).concat([['id', newId(entry.type)]])),
  });
  const result = fresh(mapTypes([item], toPuckType, { versions: 'toProps' })[0]);
  result.props.id = id;
  return result;
}

/** The item that shows a saved block as it is saved: nothing of the block itself, only where it is. */
export const linkedItem = (uuid, id) => ({ type: toPuckType(`drupal_saved:${uuid}`), props: { id } });
