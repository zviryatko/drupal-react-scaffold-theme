// Conversion between the layout as Drupal stores it and as Puck holds it.
//
// Component ids of Drupal contain colons ("react_scaffold_demo:card", "drupal_field:title"). Puck derives the id of a
// new item from the name of its type and addresses drop zones as "<item id>:<slot>", so a colon in a type name makes
// the slots of every newly added component unreachable. Inside Puck the types therefore use "/" instead; the layout
// that is loaded from and saved to Drupal keeps Drupal's ids.
export const toPuckType = (id) => id.replaceAll(':', '/');
export const toDrupalType = (type) => type.replaceAll('/', ':');

const isItems = (value) => Array.isArray(value) && value.length > 0
  && value.every((child) => child && typeof child === 'object' && typeof child.type === 'string' && child.props);

/**
 * Renames the type of every item, at any depth (slot content is a list of items in the props).
 *
 * The schema version of an item's component is stored next to its type ("version"). Puck only promises to keep the
 * props of an item, so inside Puck the version travels as the prop "_version" (`versions: 'toProps'`) and is put back
 * for Drupal (`'toItem'`). An item added in the editor has none: Drupal gives it the current one.
 */
export const mapTypes = (items, rename, { fixIds = false, versions = null } = {}) => (items || []).map((entry) => {
  const props = Object.fromEntries(Object.entries(entry.props || {}).map(([name, value]) => [name, isItems(value) ? mapTypes(value, rename, { fixIds, versions }) : value]));
  // Items added before types were renamed have a colon in their id; without it their slots are usable again.
  if (fixIds && typeof props.id === 'string') props.id = props.id.replaceAll(':', '/');
  const result = { ...entry, type: rename(entry.type), props };
  if (versions === 'toProps' && entry.version != null) {
    props._version = entry.version;
    delete result.version;
  }
  if (versions === 'toItem' && props._version != null) {
    result.version = props._version;
    delete props._version;
  }
  return result;
});

/** The layout as Puck holds it, from the layout as Drupal stores it. */
export const fromDrupal = (data) => (data ? { ...data, content: mapTypes(data.content, toPuckType, { fixIds: true, versions: 'toProps' }) } : data);

/** The layout as Drupal stores it: Drupal's component ids, and nothing of the page (that is the node form's). */
export const toDrupalLayout = (data) => ({ ...data, content: mapTypes(data.content, toDrupalType, { versions: 'toItem' }), root: { props: {} } });
