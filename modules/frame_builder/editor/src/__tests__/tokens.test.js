import { openToken, suggest } from '../tokens';

const tree = {
  roots: ['node', 'site'],
  types: {
    node: { label: 'Nodes', description: '', tokens: [
      { name: 'title', label: 'Title', description: '', type: null, dynamic: false },
      { name: 'author', label: 'Author', description: '', type: 'user', dynamic: false },
      { name: 'created', label: 'Date created', description: '', type: null, dynamic: true },
    ] },
    user: { label: 'Users', description: '', tokens: [
      { name: 'display-name', label: 'Display name', description: '', type: null, dynamic: false },
      { name: 'mail', label: 'Email', description: '', type: null, dynamic: false },
    ] },
    site: { label: 'Site information', description: '', tokens: [{ name: 'name', label: 'Name', description: '', type: null, dynamic: false }] },
  },
};
const inserts = (segments) => suggest(tree, segments).map((item) => item.insert);

describe('openToken', () => {
  it('finds the token being typed before the caret', () => {
    expect(openToken('By [node:au', 11)).toEqual({ start: 3, segments: ['node', 'au'] });
    expect(openToken('[', 1)).toEqual({ start: 0, segments: [''] });
  });

  it('ignores closed tokens, text with spaces and the text after the caret', () => {
    expect(openToken('[node:title] and', 16)).toBeNull();
    expect(openToken('see [note 1', 11)).toBeNull();
    expect(openToken('[node:title]', 5)).toEqual({ start: 0, segments: ['node'] });
  });
});

describe('suggest', () => {
  it('offers the root types first, filtered by what is typed', () => {
    expect(inserts([''])).toEqual(['[node:', '[site:']);
    expect(inserts(['si'])).toEqual(['[site:']);
  });

  it('closes a final token and keeps chained and dynamic ones open', () => {
    const items = suggest(tree, ['node', '']);
    expect(items.map((item) => [item.insert, item.open])).toEqual([['[node:title]', false], ['[node:author:', true], ['[node:created:', true]]);
  });

  it('follows a chain, offering the chained token itself as well', () => {
    expect(inserts(['node', 'author', ''])).toEqual(['[node:author]', '[node:author:display-name]', '[node:author:mail]']);
    expect(inserts(['node', 'author', 'ma'])).toEqual(['[node:author:mail]']);
  });

  it('offers nothing after a token that leads nowhere', () => {
    expect(inserts(['node', 'title', ''])).toEqual([]);
    expect(inserts(['nope', ''])).toEqual([]);
  });
});
