import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(__dirname, '../..');
const read = (file) => readFileSync(resolve(root, file), 'utf8');
const regions = (yml) => yml.slice(yml.indexOf('\nregions:')).trim();

describe('starterkit', () => {
  it('declares the same regions as the base theme (Drupal does not inherit regions)', () => {
    expect(regions(read('starterkit/react_scaffold_starterkit.info.yml'))).toBe(regions(read('react_scaffold.info.yml')));
  });

  it('is a valid core starterkit (generate-theme needs the .starterkit.yml and a version)', () => {
    expect(read('starterkit/react_scaffold_starterkit.starterkit.yml')).toContain('ignore:');
    expect(read('starterkit/react_scaffold_starterkit.info.yml')).toMatch(/^version: /m);
  });

  it('is hidden and extends the base theme', () => {
    const info = read('starterkit/react_scaffold_starterkit.info.yml');
    expect(info).toMatch(/^hidden: true/m);
    expect(info).toMatch(/'base theme': react_scaffold/);
  });
});
