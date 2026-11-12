import { initials } from '../icons';

describe('initials of a panel without an icon', () => {
  it('takes the first letters of the first two words', () => {
    expect(initials('Search engine optimisation')).toBe('Se');
    expect(initials('related content')).toBe('Rc');
  });

  it('takes two letters of a single word', () => {
    expect(initials('Media')).toBe('Me');
    expect(initials('SEO')).toBe('Se');
    expect(initials('Теги')).toBe('Те');
  });

  it('has something to show for a label without letters', () => {
    expect(initials('')).toBe('?');
    expect(initials('—')).toBe('?');
  });
});
