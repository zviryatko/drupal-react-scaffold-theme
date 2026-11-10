import { existsSync, readFileSync } from 'node:fs';
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

  it('has no build configuration or dependencies of its own, only scripts that call the base theme', () => {
    const pkg = JSON.parse(read('starterkit/package.json'));
    expect(pkg.dependencies ?? pkg.devDependencies).toBeUndefined();
    Object.values(pkg.scripts).forEach((script) => expect(script).toMatch(/react_scaffold\/bin\/react-scaffold\.mjs /));
    ['vite.config.js', 'jest.config.cjs'].forEach((file) => expect(existsSync(resolve(root, 'starterkit', file))).toBe(false));
  });
});
