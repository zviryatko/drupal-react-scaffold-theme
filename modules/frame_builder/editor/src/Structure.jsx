// The "Structure" panel: Puck's own outline, as it looks and drags, with a selection of several components on top.
//
// - A click selects one component (on the canvas too, which shows its fields); Ctrl or Cmd + click adds or takes one
//   away; Shift + click selects all from the last one clicked; up and down go through the tree, with Shift they
//   extend the selection.
// - With several selected: "+" puts them into a new component of the group Layout (Columns, Stack…), Move up and
//   Move down move them together, Delete (here and in the header) removes them. Each is one step of Undo.
// - A click on a component without a key, here or on the canvas, is a selection of that one again.
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { createPortal } from 'react-dom';
import { createUsePuck, outlinePlugin } from '@puckeditor/core';
import { settings } from './api';
import { toPuckType } from './types';

const usePuck = createUsePuck();
const ROOT = 'root:default-zone';
// On a translation that shares the structure of the original nothing is added, moved or removed.
const locked = Boolean(settings.translation?.locked);

// --- The selection of several components, shared by the panel and the header.
// The component the panel asked Puck to select: Puck can clear its selection on the way (the layout changed).
const expected = { id: null };
let selection = [];
const listeners = new Set();
const subscribe = (listener) => { listeners.add(listener); return () => listeners.delete(listener); };
const setSelection = (ids) => { selection = ids; listeners.forEach((listener) => listener()); };
export const useSelection = () => useSyncExternalStore(subscribe, () => selection);

// --- The tree of the layout.

/** Is this value the items of a slot? */
const isItems = (value) => Array.isArray(value) && value.length > 0 && value.every((child) => child && typeof child === 'object' && typeof child.type === 'string' && child.props);

// --- Changes of the layout. Each works on a copy and gives the new content.

/** Every item of the content by id, with the list it is in. */
function index(content) {
  const found = new Map();
  const walk = (items, parent) => items.forEach((item, at) => {
    found.set(item.props.id, { item, list: items, parent });
    Object.values(item.props).forEach((value) => { if (isItems(value)) walk(value, item.props.id); });
  });
  walk(content, null);
  return found;
}

/** The ids without those inside another one of them, in the order of the tree. */
function outermost(content, ids) {
  const found = index(content);
  const chosen = new Set(ids);
  const inside = (id) => { let parent = found.get(id)?.parent; while (parent) { if (chosen.has(parent)) return true; parent = found.get(parent)?.parent; } return false; };
  return [...found.keys()].filter((id) => chosen.has(id) && !inside(id));
}

/** The content without these components (and what is in them). */
export function removeItems(content, ids) {
  const copy = structuredClone(content);
  const found = index(copy);
  outermost(copy, ids).forEach((id) => {
    const { list, item } = found.get(id);
    list.splice(list.indexOf(item), 1);
  });
  return copy;
}

/** The content with these components put, in their order, into the first slot of a new component, where the first was. */
export function wrapItems(content, ids, wrapper, slot) {
  const copy = structuredClone(content);
  const found = index(copy);
  const chosen = outermost(copy, ids);
  if (!chosen.length) return content;
  const first = found.get(chosen[0]);
  const marker = {};
  first.list.splice(first.list.indexOf(first.item), 1, marker);
  const moved = [first.item];
  chosen.slice(1).forEach((id) => {
    const { list, item } = found.get(id);
    list.splice(list.indexOf(item), 1);
    moved.push(item);
  });
  wrapper.props[slot] = moved;
  first.list.splice(first.list.indexOf(marker), 1, wrapper);
  return copy;
}

/**
 * The content with these components one place up (-1) or down (1) in the list each is in. A run of them next to each
 * other moves together; one that is already first (or last) stays, and so do those right behind it.
 */
export function moveItems(content, ids, step) {
  const copy = structuredClone(content);
  const found = index(copy);
  const chosen = new Set(outermost(copy, ids));
  const lists = new Set([...chosen].map((id) => found.get(id).list));
  let moved = false;
  lists.forEach((list) => {
    const picked = list.map((item) => chosen.has(item.props.id));
    const order = step < 0 ? [...list.keys()] : [...list.keys()].reverse();
    order.forEach((at) => {
      const to = at + step;
      if (picked[at] && to >= 0 && to < list.length && !picked[to]) {
        [list[at], list[to]] = [list[to], list[at]];
        [picked[at], picked[to]] = [picked[to], picked[at]];
        moved = true;
      }
    });
  });
  return moved ? copy : content;
}

// --- The panel.

/** Components of the group Layout that have a slot: what a selection can be put into. */
const layouts = () => settings.components.filter((definition) => definition.group === 'Layout' && definition.slots.length && !definition.hidden && !definition.deleted);

const ROW = '[data-puck-layer-tree-id]';

/** The words of a component, for the search: its texts, without ids, links to entities and markup. */
function wordsOf(item) {
  const words = [];
  const walk = (value, name) => {
    if (name === 'id' || isItems(value)) return;
    if (typeof value === 'string') { if (!value.startsWith('entity:')) words.push(value.replace(/<[^>]*>/g, ' ')); }
    else if (Array.isArray(value)) value.forEach((each) => walk(each));
    else if (value && typeof value === 'object') Object.entries(value).forEach(([key, each]) => walk(each, key));
  };
  Object.entries(item.props || {}).forEach(([name, value]) => walk(value, name));
  return words.join(' ');
}

/** The components whose name or text has every word typed, in the order of the tree, with the components they are in. */
function find(content, labels, query) {
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  if (!words.length) return [];
  const found = [];
  const walk = (items, parents) => items.forEach((item) => {
    const text = `${labels(item.type)} ${wordsOf(item)}`.toLowerCase();
    if (words.every((word) => text.includes(word))) found.push({ id: item.props.id, parents });
    Object.values(item.props).forEach((value) => { if (isItems(value)) walk(value, [...parents, item.props.id]); });
  });
  walk(content || [], []);
  return found;
}
const rowId = (element) => element?.closest(ROW)?.getAttribute('data-puck-layer-tree-id') ?? null;

/** The panel of Puck's outline, with this behaviour around it. */
export function structurePlugin(options) {
  const outline = outlinePlugin(options);
  return { ...outline, render: () => <StructurePanel>{outline.render()}</StructurePanel> };
}

function StructurePanel({ children }) {
  const data = usePuck((state) => state.appState.data);
  const dispatch = usePuck((state) => state.dispatch);
  const config = usePuck((state) => state.config);
  const selectedId = usePuck((state) => state.selectedItem?.props?.id ?? null);
  const chosen = useSelection();
  const [menu, setMenu] = useState(false);
  const [query, setQuery] = useState('');
  const [hit, setHit] = useState(0);
  // Places in Puck's header of the panel: the actions on the selection before "Collapse all", the search under it.
  const [host, setHost] = useState(null);
  const [actionsHost, setActionsHost] = useState(null);
  useEffect(() => {
    const place = () => {
      const header = box.current?.querySelector('[class*="OutlineHeader"]');
      if (!header) return;
      let element = header.nextElementSibling;
      if (!element?.classList.contains('frame-builder-structure__tools')) {
        element = document.createElement('div');
        element.className = 'frame-builder-structure__tools';
        header.after(element);
      }
      let actions = header.querySelector(':scope > .frame-builder-structure__actions');
      if (!actions) {
        actions = document.createElement('span');
        actions.className = 'frame-builder-structure__actions';
        header.insertBefore(actions, header.querySelector(':scope > [class*="CollapseAll"]'));
      }
      setHost((before) => (before === element ? before : element));
      setActionsHost((before) => (before === actions ? before : actions));
    };
    place();
    const observer = new MutationObserver(place);
    observer.observe(box.current, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, []);
  const box = useRef(null);
  const anchor = useRef(null);
  // The latest of everything, for the listeners on the DOM of the outline.
  const now = useRef({});
  now.current = { data, dispatch, selectedId };

  // What is no longer in the layout is no longer selected.
  useEffect(() => {
    const found = index(data.content || []);
    const kept = selection.filter((id) => found.has(id));
    if (kept.length !== selection.length) setSelection(kept);
  }, [data.content]);
  // The rows of the selection look as Puck's selected row.
  useEffect(() => {
    const mark = () => box.current?.querySelectorAll(ROW).forEach((row) => {
      row.toggleAttribute('data-frame-chosen', chosen.includes(row.getAttribute('data-puck-layer-tree-id')));
    });
    mark();
    const observer = new MutationObserver(mark);
    if (box.current) observer.observe(box.current, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, [chosen]);

  const selectorOf = (content, id) => {
    const at = index(content).get(id);
    return at ? { index: at.list.indexOf(at.item), zone: zoneOf(content, id) } : null;
  };
  const select = (id) => {
    const { data: current, dispatch: send, selectedId: selected } = now.current;
    // Wait for Puck to select it, unless it is selected already (nothing will change then).
    expected.id = id === selected ? null : id;
    send({ type: 'setUi', ui: { itemSelector: selectorOf(current.content, id) } });
  };
  /** The rows shown, in order: what is in a folded component is not. */
  const shown = () => [...(box.current?.querySelectorAll(ROW) || [])].map((row) => row.getAttribute('data-puck-layer-tree-id'));
  const range = (from, to) => {
    const ids = shown();
    const [a, b] = [ids.indexOf(from), ids.indexOf(to)];
    return a < 0 || b < 0 ? [to] : ids.slice(Math.min(a, b), Math.max(a, b) + 1);
  };
  const focusRow = (id) => box.current?.querySelector(`[data-puck-layer-tree-id="${CSS.escape(id)}"] [class*="Layer-clickable"]`)?.focus();

  const change = (next, after = []) => {
    const { data: current, dispatch: send } = now.current;
    send({ type: 'setData', data: { ...current, content: next }, recordHistory: true });
    setSelection(after);
    expected.id = after[0] ?? null;
    if (!after.length) send({ type: 'setUi', ui: { itemSelector: null } });
  };
  const remove = () => change(removeItems(now.current.data.content, selection));
  const move = (step) => {
    const { data: current, dispatch: send, selectedId: selected } = now.current;
    const next = moveItems(current.content, selection.length ? selection : [selected], step);
    if (next === current.content) return;
    send({ type: 'setData', data: { ...current, content: next }, recordHistory: true });
    // The selected one, where it is now.
    if (selected) send({ type: 'setUi', ui: { itemSelector: selectorOf(next, selected) } });
  };
  const wrap = (definition) => {
    setMenu(false);
    const type = toPuckType(definition.id);
    const wrapper = { type, props: { ...structuredClone(config.components[type]?.defaultProps || {}), id: `${type}-${crypto.randomUUID()}` } };
    definition.slots.forEach((slot) => { wrapper.props[slot.name] = []; });
    const next = wrapItems(data.content, selection, wrapper, definition.slots[0].name);
    change(next, [wrapper.props.id]);
    // The new component, selected and open: its fields are in the right column, what is in it is in the tree.
    setTimeout(() => {
      expected.id = wrapper.props.id;
      dispatch({ type: 'setUi', ui: (ui) => ({ itemSelector: selectorOf(next, wrapper.props.id), itemExpanded: { ...ui.itemExpanded, [wrapper.props.id]: true } }), recordHistory: false });
    }, 0);
  };

  // Clicks and keys on the rows of the outline. Listened to before Puck: what is handled here does not reach it.
  useEffect(() => {
    const element = box.current;
    const onClick = (event) => {
      if (!event.target.closest('[class*="Layer-clickable"]')) return;
      const id = rowId(event.target);
      if (!id) return;
      if (event.shiftKey && anchor.current) {
        event.preventDefault(); event.stopPropagation();
        setSelection(range(anchor.current, id));
        select(id);
        return;
      }
      anchor.current = id;
      if (event.ctrlKey || event.metaKey) {
        event.preventDefault(); event.stopPropagation();
        const next = selection.includes(id) ? selection.filter((other) => other !== id) : [...selection, id];
        setSelection(next);
        if (next.length) select(next.includes(id) ? id : next[next.length - 1]);
        else { expected.id = null; now.current.dispatch({ type: 'setUi', ui: { itemSelector: null } }); }
        return;
      }
      // A plain click: that one alone. Puck selects it.
      setSelection([id]);
    };
    const onKey = (event) => {
      const id = rowId(event.target);
      if (!id || !event.target.closest('[class*="Layer-clickable"]')) return;
      const step = { ArrowDown: 1, ArrowUp: -1 }[event.key];
      const stop = () => { event.preventDefault(); event.stopPropagation(); };
      if (step && event.altKey) { stop(); if (!locked) move(step); return; }
      if (step) {
        stop();
        const ids = shown();
        const next = ids[Math.max(0, Math.min(ids.length - 1, ids.indexOf(id) + step))];
        if (!next || next === id) return;
        focusRow(next);
        if (event.shiftKey) {
          anchor.current ??= id;
          setSelection(range(anchor.current, next));
        }
        else { anchor.current = next; setSelection([next]); }
        select(next);
        return;
      }
      if ((event.key === 'Delete' || event.key === 'Backspace') && selection.length > 1) { stop(); if (!locked) remove(); return; }
      if (event.key === 'Escape' && selection.length > 1) { stop(); setSelection(now.current.selectedId ? [now.current.selectedId] : []); return; }
      // Right opens a component, left folds it: Puck's arrow before its name.
      const row = event.target.closest(ROW);
      const open = /Layer--isExpanded/.test(row.className);
      if ((event.key === 'ArrowRight' && !open) || (event.key === 'ArrowLeft' && open)) {
        const caret = row.querySelector(':scope > [class*="Layer-inner"] > [class*="Layer-caret"] button');
        if (caret && /Layer--containsZone/.test(row.className)) { stop(); caret.click(); }
      }
    };
    element.addEventListener('click', onClick, true);
    element.addEventListener('keydown', onKey, true);
    return () => { element.removeEventListener('click', onClick, true); element.removeEventListener('keydown', onKey, true); };
  }, []);

  // The search: the component found is opened in the tree, selected, and shown on the canvas.
  const label = (type) => config.components[type]?.label || type;
  const hits = find(data.content, label, query);
  const go = (at) => {
    if (!hits.length) return;
    const next = (at + hits.length) % hits.length;
    setHit(next);
    goTo(hits[next]);
  };
  const search = (value) => {
    setQuery(value);
    setHit(0);
    const first = find(data.content, label, value)[0];
    if (first) setTimeout(() => goTo(first), 0);
  };
  const goTo = (target) => {
    if (target.parents.length) {
      dispatch({ type: 'setUi', ui: (ui) => ({ itemExpanded: { ...ui.itemExpanded, ...Object.fromEntries(target.parents.map((id) => [id, true])) } }), recordHistory: false });
    }
    anchor.current = target.id;
    setSelection([target.id]);
    select(target.id);
    setTimeout(() => {
      box.current?.querySelector(`[data-puck-layer-tree-id="${CSS.escape(target.id)}"]`)?.scrollIntoView({ block: 'nearest' });
      document.querySelector(`[data-puck-component="${CSS.escape(target.id)}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }, 50);
  };

  useEffect(() => {
    if (!menu) return undefined;
    const close = (event) => { if (!event.target.closest?.('.frame-builder-structure__menu')) setMenu(false); };
    document.addEventListener('pointerdown', close, true);
    return () => document.removeEventListener('pointerdown', close, true);
  }, [menu]);
  const many = chosen.length > 1;
  const offered = layouts();
  return (
    <div className="frame-builder-structure" ref={box}>
      {children}
      {host && createPortal(<>
      <div className="frame-builder-structure__search">
        <input type="search" className="frame-builder-input" placeholder="Search the page" aria-label="Search the components of the page"
               value={query} onChange={(event) => search(event.target.value)}
               onKeyDown={(event) => {
                 if (event.key === 'Enter' || event.key === 'ArrowDown') { event.preventDefault(); go(hit + (event.shiftKey ? -1 : 1)); }
                 else if (event.key === 'ArrowUp') { event.preventDefault(); go(hit - 1); }
                 else if (event.key === 'Escape' && query) { event.preventDefault(); search(''); }
               }} />
        {query && <span className="frame-builder-structure__found" aria-live="polite">{hits.length ? `${hit + 1} of ${hits.length}` : 'Nothing found'}</span>}
      </div>
      </>, host)}
      {actionsHost && many && createPortal(<>
        <span className="frame-builder-structure__count" aria-live="polite" title={`${chosen.length} components selected`}>{chosen.length} selected</span>
        {!locked && <>
          {offered.length > 0 && (
            <span className="frame-builder-structure__menu">
              <button type="button" className="frame-builder-structure__action" aria-haspopup="menu" aria-expanded={menu}
                      title="Put them into a layout component" aria-label="Put the selected components into a layout component" onClick={() => setMenu(!menu)}>
                <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" /></svg>
              </button>
              {menu && (
                <span className="frame-builder-structure__popover" role="menu" aria-label="Put them into"
                      onKeyDown={(event) => {
                        const items = [...event.currentTarget.querySelectorAll('[role="menuitem"]')];
                        const at = items.indexOf(document.activeElement);
                        if (event.key === 'Escape') { setMenu(false); event.currentTarget.previousElementSibling?.focus(); }
                        else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); items[(at + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]?.focus(); }
                      }}>
                  {offered.map((definition, position) => (
                    <button type="button" role="menuitem" key={definition.id} className="frame-builder-structure__option" autoFocus={position === 0} onClick={() => wrap(definition)}>
                      {definition.label}
                    </button>
                  ))}
                </span>
              )}
            </span>
          )}
          <button type="button" className="frame-builder-structure__action" title="Move up (Alt + ↑)" aria-label="Move the selected components up" onClick={() => move(-1)}>
            <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 15V5M5.5 9.5 10 5l4.5 4.5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></svg>
          </button>
          <button type="button" className="frame-builder-structure__action" title="Move down (Alt + ↓)" aria-label="Move the selected components down" onClick={() => move(1)}>
            <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 5v10M5.5 10.5 10 15l4.5-4.5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></svg>
          </button>
          <button type="button" className="frame-builder-structure__action frame-builder-structure__action--danger" title="Delete (Delete key)" aria-label="Delete the selected components" onClick={remove}>
            <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 6h10M8 6V4.5h4V6M6.5 6l.7 9.5h5.6l.7-9.5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" /></svg>
          </button>
        </>}
      </>, actionsHost)}
    </div>
  );
}

/** The zone of an item: "<parent id>:<slot>", or the root. */
function zoneOf(content, id) {
  let zone = ROOT;
  const walk = (items, current) => items.some((item) => {
    if (item.props.id === id) { zone = current; return true; }
    return Object.entries(item.props).some(([name, value]) => isItems(value) && walk(value, `${item.props.id}:${name}`));
  });
  walk(content, ROOT);
  return zone;
}

/** Delete in the header of the editor, while several components are selected. */
export function DeleteSelected() {
  const data = usePuck((state) => state.appState.data);
  const dispatch = usePuck((state) => state.dispatch);
  const chosen = useSelection();
  if (chosen.length < 2 || locked) return null;
  return (
    <button type="button" className="frame-builder-button frame-builder-button--small frame-builder-button--danger" title="Delete the selected components (Undo brings them back)"
            onClick={() => {
              dispatch({ type: 'setData', data: { ...data, content: removeItems(data.content, chosen) }, recordHistory: true });
              setSelection([]);
              dispatch({ type: 'setUi', ui: { itemSelector: null } });
            }}>
      Delete {chosen.length}
    </button>
  );
}

/** The components of the selection, marked on the canvas while there are several. */
export function SelectionOnCanvas() {
  const chosen = useSelection();
  const selectedId = usePuck((state) => state.selectedItem?.props?.id ?? null);
  const dragging = usePuck((state) => Boolean(state.appState.ui.isDragging));
  // A selection made elsewhere (a click on the canvas) is a selection of that one component. While a component is
  // dragged Puck selects nothing for a moment: the selection stays, the others follow it (followMove).
  useEffect(() => {
    if (dragging) return;
    if (expected.id !== null) {
      if (selectedId === expected.id) {
        expected.id = null;
        if (!selection.includes(selectedId)) setSelection([selectedId]);
      }
      return;
    }
    if (!selection.includes(selectedId) || selection.length < 2) setSelection(selectedId ? [selectedId] : []);
  }, [selectedId, dragging]);
  useEffect(() => {
    let sheet = document.getElementById('frame-builder-selection');
    if (!sheet) sheet = document.head.appendChild(Object.assign(document.createElement('style'), { id: 'frame-builder-selection' }));
    sheet.textContent = chosen.length > 1
      ? `${chosen.map((id) => `[data-puck-component="${CSS.escape(id)}"]`).join(', ')} { outline: 2px solid #003ecc !important; outline-offset: -2px; background-color: rgb(0 62 204 / 6%); }`
      : '';
  }, [chosen]);
  return null;
}

/** The items of a zone: the page ("root:default-zone") or a slot ("<id>:<slot>"). */
function itemsOf(content, zone) {
  if (zone === ROOT) return content;
  const at = zone.lastIndexOf(':');
  return index(content).get(zone.slice(0, at))?.item.props[zone.slice(at + 1)] || [];
}

/**
 * The content with the other selected components next to the one just dragged: those before it in the tree before it,
 * the rest after it, in their order. A component is not put into itself, and one dragged out of a selected component
 * moves alone.
 */
export function gatherAround(content, order, dragged) {
  const copy = structuredClone(content);
  const found = index(copy);
  if (!found.has(dragged)) return content;
  const inside = (id, of) => { let parent = found.get(id)?.parent; while (parent) { if (parent === of) return true; parent = found.get(parent)?.parent; } return false; };
  const others = order.filter((id) => id !== dragged && found.has(id) && !inside(dragged, id));
  if (!others.length) return content;
  const pulled = new Map(others.map((id) => {
    const { list, item } = found.get(id);
    list.splice(list.indexOf(item), 1);
    return [id, item];
  }));
  const { list, item } = found.get(dragged);
  const before = others.filter((id) => order.indexOf(id) < order.indexOf(dragged)).map((id) => pulled.get(id));
  const after = others.filter((id) => order.indexOf(id) > order.indexOf(dragged)).map((id) => pulled.get(id));
  list.splice(list.indexOf(item), 0, ...before);
  list.splice(list.indexOf(item) + 1, 0, ...after);
  return copy;
}

/**
 * Called with each action of Puck: when one of several selected components is dragged (on the canvas or in the tree),
 * the others follow it. Right after the drop, so that Puck records both as one step of Undo (it waits 250 ms).
 */
export function followMove(action, state, before, dispatch) {
  if (action.type !== 'move' || selection.length < 2 || !before?.data) return;
  const dragged = itemsOf(before.data.content || [], action.sourceZone)?.[action.sourceIndex]?.props?.id;
  if (!dragged || !selection.includes(dragged)) return;
  const order = outermost(before.data.content, selection);
  if (!order.includes(dragged)) return;
  const kept = [...selection];
  setTimeout(() => {
    const next = gatherAround(state.data.content, order, dragged);
    if (next === state.data.content) return;
    dispatch({ type: 'setData', data: { ...state.data, content: next }, recordHistory: true });
    expected.id = dragged;
    setSelection(kept);
    dispatch({ type: 'setUi', ui: { itemSelector: { index: itemsOf(next, zoneOf(next, dragged)).findIndex((item) => item.props.id === dragged), zone: zoneOf(next, dragged) } } });
  }, 0);
}

/** The component a component is in, or null at the top of the page. */
export function parentOf(content, id) {
  const found = index(content || []);
  const parent = found.get(id)?.parent;
  return parent ? found.get(parent).item : null;
}

/** What Puck selects a component by: its index in its zone, and the zone. */
export function selectorFor(content, id) {
  const at = index(content || []).get(id);
  return at ? { index: at.list.indexOf(at.item), zone: zoneOf(content, id) } : null;
}
