import { describe as describeStep, flatten } from '../history';

const item = (id, type, props = {}) => ({ type, props: { id, ...props } });
const layout = (...content) => ({ content });
const label = (type) => ({ card: 'Card', section: 'Section', text: 'Text' }[type] || type);
const fieldLabel = (type, prop) => ({ heading: 'Heading', _spacing: 'Spacing', variant: 'Variant' }[prop] || prop);
const step = (before, after) => describeStep(before, after, label, fieldLabel).text;

describe('flatten', () => {
  it('lists nested items with their parent slot and place', () => {
    const found = flatten(layout(item('s', 'section', { heading: 'A', content: [item('c', 'card', { content: [] }), item('t', 'text')] })).content);
    expect([...found.keys()]).toEqual(['c', 't', 's']);
    expect(found.get('t')).toEqual({ type: 'text', parent: 's:content', index: 1, props: {} });
    expect(found.get('s').props).toEqual({ heading: 'A' });
  });
});

describe('describe', () => {
  const base = layout(item('s', 'section', { heading: 'A', content: [item('c', 'card', { heading: 'One', content: [] })] }));

  it('names what was added, with what came inside it', () => {
    expect(step(base, layout(...base.content, item('t', 'text')))).toBe('Added Text');
    const filled = layout(...base.content, item('s2', 'section', { content: [item('c2', 'card', { content: [item('t2', 'text')] })] }));
    expect(step(base, filled)).toBe('Added Section and 2 inside it');
  });

  it('names what was removed', () => {
    expect(step(base, layout())).toBe('Removed Section and 1 inside it');
  });

  it('tells a move to another slot from a change of order', () => {
    const two = layout(item('a', 'card', { content: [] }), item('b', 'card', { content: [item('t', 'text')] }));
    const moved = layout(item('a', 'card', { content: [item('t', 'text')] }), item('b', 'card', { content: [] }));
    expect(step(two, moved)).toBe('Moved Text to another place');
    expect(step(two, layout(two.content[1], two.content[0]))).toBe('Moved Card');
  });

  it('names the changed fields', () => {
    const changed = layout(item('s', 'section', { heading: 'B', variant: 'tinted', content: base.content[0].props.content }));
    expect(step(base, changed)).toBe('Changed Heading and Variant of Section');
    const styled = layout(item('s', 'section', { heading: 'A', _spacing: { padding: { size: 'm' } }, content: base.content[0].props.content }));
    expect(step(base, styled)).toBe('Changed Spacing of Section');
  });

  it('says so when nothing changed', () => {
    expect(describeStep(base, base, label, fieldLabel).kind).toBe('none');
  });
});
