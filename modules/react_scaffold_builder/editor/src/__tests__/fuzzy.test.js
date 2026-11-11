import { distance, score, search } from '../fuzzy';

describe('distance', () => {
  it('counts edits, a swap of neighbours as one', () => {
    expect(distance('columns', 'columns')).toBe(0);
    expect(distance('colums', 'columns')).toBe(1);
    expect(distance('colmuns', 'columns')).toBe(1);
    expect(distance('kolumnz', 'columns')).toBe(2);
  });

  it('gives up beyond the limit', () => {
    expect(distance('tooltip', 'columns')).toBe(3);
    expect(distance('a', 'columns')).toBe(3);
  });
});

describe('score', () => {
  it('prefers the start of a word, then inside a word, then a typo', () => {
    const start = score('col', 'Columns');
    const inside = score('umn', 'Columns');
    const typo = score('colmuns', 'Columns');
    expect(start).toBeGreaterThan(inside);
    expect(inside).toBeGreaterThan(typo);
    expect(typo).toBeGreaterThan(0);
  });

  it('forgives a typo in a word that is not finished yet', () => {
    expect(score('reciep', 'Recipe Explorer')).toBeGreaterThan(0);
    expect(score('tooltpi', 'React Tooltip')).toBeGreaterThan(0);
  });

  it('needs every word of the query, in any order, ignoring case and accents', () => {
    expect(score('explorer recipe', 'Recipe Explorer')).toBeGreaterThan(0);
    expect(score('recipe table', 'Recipe Explorer')).toBe(0);
    expect(score('MENU', 'Menú principal')).toBeGreaterThan(0);
  });

  it('does not guess from one or two letters with a typo', () => {
    expect(score('xo', 'Columns')).toBe(0);
    expect(score('zzz', 'Columns')).toBe(0);
  });
});

describe('search', () => {
  const items = [
    { label: 'Columns', group: 'Layout' },
    { label: 'Stack', group: 'Layout' },
    { label: 'Card', group: 'Content' },
    { label: 'Main navigation', group: 'Blocks' },
  ];
  const text = (item) => [item.label, item.group];

  it('returns everything for an empty query', () => {
    expect(search(items, '  ', text)).toEqual(items);
  });

  it('ranks a match in the name above a match in the group', () => {
    expect(search(items, 'stack', text).map((item) => item.label)).toEqual(['Stack']);
    expect(search(items, 'layout', text).map((item) => item.label)).toEqual(['Columns', 'Stack']);
    expect(search(items, 'card content', text).map((item) => item.label)).toEqual(['Card']);
  });

  it('finds with typos', () => {
    expect(search(items, 'navgation', text).map((item) => item.label)).toEqual(['Main navigation']);
    expect(search(items, 'colunms', text).map((item) => item.label)).toEqual(['Columns']);
  });
});
