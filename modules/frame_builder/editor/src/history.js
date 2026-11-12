// What changed between two states of the layout, in words, for the history timeline.

const isItems = (value) => Array.isArray(value) && value.length > 0 && value.every((child) => child && typeof child === 'object' && typeof child.type === 'string' && child.props);

/** Every item of a layout by id: its type, where it is (parent slot and place in it) and its own props. */
export function flatten(items, parent = 'root', found = new Map()) {
  (items || []).forEach((item, index) => {
    const props = {};
    Object.entries(item.props || {}).forEach(([name, value]) => {
      if (isItems(value)) flatten(value, `${item.props.id}:${name}`, found);
      else if (name !== 'id' && !(Array.isArray(value) && value.length === 0)) props[name] = value;
    });
    found.set(item.props.id, { type: item.type, parent, index, props });
  });
  return found;
}

/**
 * Describes the step from one layout to the next.
 *
 * @param {object} before, after Layout data (`{ content }`).
 * @param {(type: string) => string} label Name of a component type.
 * @param {(type: string, prop: string) => string} fieldLabel Name of a prop of a component type.
 * @returns {{ kind: 'add'|'remove'|'move'|'change'|'none', text: string }}
 */
export function describe(before, after, label, fieldLabel) {
  const was = flatten(before?.content);
  const is = flatten(after?.content);
  const added = [...is.keys()].filter((id) => !was.has(id));
  const removed = [...was.keys()].filter((id) => !is.has(id));
  // Of several at once (a component with what is in it), the outermost says it.
  const outermost = (ids, map) => ids.find((id) => !ids.includes(map.get(id).parent.split(':')[0])) || ids[0];
  const more = (ids) => (ids.length > 1 ? ` and ${ids.length - 1} inside it` : '');

  if (added.length && !removed.length) {
    return { kind: 'add', text: `Added ${label(is.get(outermost(added, is)).type)}${more(added)}` };
  }
  if (removed.length && !added.length) {
    return { kind: 'remove', text: `Removed ${label(was.get(outermost(removed, was)).type)}${more(removed)}` };
  }
  if (added.length && removed.length) {
    return { kind: 'change', text: `Replaced ${label(was.get(outermost(removed, was)).type)} with ${label(is.get(outermost(added, is)).type)}` };
  }
  // Same items: moved, or changed.
  const moved = [...is.keys()].filter((id) => was.get(id).parent !== is.get(id).parent);
  if (moved.length) {
    return { kind: 'move', text: `Moved ${label(is.get(moved[0]).type)} to another place` };
  }
  for (const [id, item] of is) {
    const old = was.get(id);
    const changed = [...new Set([...Object.keys(item.props), ...Object.keys(old.props)])]
      .filter((name) => JSON.stringify(item.props[name]) !== JSON.stringify(old.props[name]));
    if (changed.length) {
      const names = changed.map((name) => fieldLabel(item.type, name));
      return { kind: 'change', text: `Changed ${names.slice(0, 2).join(' and ')}${names.length > 2 ? ` and ${names.length - 2} more` : ''} of ${label(item.type)}` };
    }
  }
  const reordered = [...is.keys()].find((id) => was.get(id).index !== is.get(id).index);
  if (reordered) {
    return { kind: 'move', text: `Moved ${label(is.get(reordered).type)}` };
  }
  return { kind: 'none', text: 'No change to the layout' };
}
