import { fromDrupal, toDrupalLayout, toDrupalType, toPuckType } from '../types';

const layout = {
  root: { props: { title: 'ignored' } },
  content: [
    { type: 'frame_demo:section', props: { id: 's1', heading: 'A:B', content: [
      { type: 'drupal_block:system_menu_block:main', props: { id: 'drupal_block:system_menu_block:main-123', title: 'Menu' } },
      { type: 'frame:columns', props: { id: 'col', gap: { size: 'm' }, content: [] } },
    ] } },
  ],
};

describe('layout types', () => {
  it('uses colon-free type names inside Puck and Drupal ids outside', () => {
    expect(toPuckType('drupal_view:recipes:builder_1')).toBe('drupal_view/recipes/builder_1');
    expect(toDrupalType('drupal_view/recipes/builder_1')).toBe('drupal_view:recipes:builder_1');
  });

  it('renames nested items, repairs ids with a colon and leaves other values alone', () => {
    const puck = fromDrupal(layout);
    const [section] = puck.content;
    expect(section.type).toBe('frame_demo/section');
    expect(section.props.heading).toBe('A:B');
    expect(section.props.content.map((entry) => entry.type)).toEqual(['drupal_block/system_menu_block/main', 'frame/columns']);
    expect(section.props.content[0].props.id).toBe('drupal_block/system_menu_block/main-123');
    expect(section.props.content[1].props.gap).toEqual({ size: 'm' });
    expect(section.props.content[1].props.content).toEqual([]);
  });

  it('gives Drupal its ids back and drops the root props', () => {
    const stored = toDrupalLayout(fromDrupal(layout));
    expect(stored.root).toEqual({ props: {} });
    expect(stored.content[0].type).toBe('frame_demo:section');
    expect(stored.content[0].props.content[0].type).toBe('drupal_block:system_menu_block:main');
  });

  it('accepts an empty layout', () => {
    expect(fromDrupal(null)).toBeNull();
    expect(toDrupalLayout({ content: [] }).content).toEqual([]);
  });

  it('carries the component version of an item as a prop inside Puck and next to the type for Drupal', () => {
    const stored = { version: 1, content: [
      { type: 'a:b', version: 3, props: { id: 'x', content: [{ type: 'a:c', version: 2, props: { id: 'y' } }] } },
    ] };
    const puck = fromDrupal(stored);
    expect(puck.version).toBe(1);
    expect(puck.content[0].version).toBeUndefined();
    expect(puck.content[0].props._version).toBe(3);
    expect(puck.content[0].props.content[0].props._version).toBe(2);
    // An item added in the editor has no version.
    puck.content.push({ type: 'a/c', props: { id: 'new' } });
    const back = toDrupalLayout(puck);
    expect(back.content[0]).toEqual({ type: 'a:b', version: 3, props: { id: 'x', content: [{ type: 'a:c', version: 2, props: { id: 'y' } }] } });
    expect(back.content[1]).toEqual({ type: 'a:c', props: { id: 'new' } });
  });
});
