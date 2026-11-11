// The theme's design tokens as SCSS (tokens.js), through the command line as a subtheme uses it.
const { execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync, readFileSync } = require('node:fs');
const { tmpdir } = require('node:os');
const { join, resolve } = require('node:path');

const base = resolve(__dirname, '..');
const cli = join(base, 'bin/react-scaffold.mjs');

/** A theme folder next to a copy-free pointer to the base theme: themes/custom/<name>. */
function theme(name, info, breakpoints) {
  const dir = join(mkdtempSync(join(tmpdir(), 'rs-tokens-')), name);
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, `${name}.info.yml`), info);
  if (breakpoints) writeFileSync(join(dir, `${name}.breakpoints.yml`), breakpoints);
  return dir;
}

const tokens = (cwd, ...args) => execFileSync(process.execPath, [cli, 'tokens', ...args], { cwd, encoding: 'utf8' });

/** Compiles SCSS that uses the tokens of the theme in `cwd`. */
function compile(cwd, scss) {
  const script = `
    const loaded = await import(${JSON.stringify(require.resolve('sass'))});
    const sass = loaded.compileString ? loaded : loaded.default;
    const { tokensImporter } = await import(${JSON.stringify(join(base, 'tokens.js'))});
    process.stdout.write(sass.compileString(${JSON.stringify(scss)}, { importers: [tokensImporter(process.cwd())] }).css);`;
  return execFileSync(process.execPath, ['--input-type=module', '-e', script], { cwd, encoding: 'utf8' });
}

describe('tokens', () => {
  const dir = theme('my_theme', `name: My theme
type: theme
base theme: react_scaffold
react_scaffold_builder:
  uikit:
    spacing: { s: 4px, m: 1rem, bad: 'red; }' }
    colors:
      brand: { label: Brand, value: '#123456' }
      plain: '#fff'
      evil: 'url(x)'
  features: { position: false }
`, `my_theme.small:
  label: Small
  mediaQuery: ''
  weight: 0
my_theme.wide:
  label: Wide
  mediaQuery: 'all and (min-width: 60em)'
  weight: 1
`);

  it('reads the scales, the palette and the breakpoints from YAML, and drops what is not a length or a colour', () => {
    const scss = tokens(dir);
    expect(scss).toContain("$spacing: ('s': 4px, 'm': 1rem);");
    expect(scss).toContain("$colors: ('brand': #123456, 'plain': #fff);");
    expect(scss).toContain("$breakpoints: ('small': '', 'wide': 'all and (min-width: 60em)');");
    // Not declared by the theme: the default type scale. Features are merged over the defaults.
    expect(scss).toContain("'2xl': 2rem");
    expect(scss).toContain("'text': true, 'background': true, 'position': false");
  });

  it('gives functions, a breakpoint mixin and the rules of the builder features', () => {
    const css = compile(dir, `@use 'react-scaffold/tokens' as rs;
      .a { padding: rs.space(m); color: rs.color(brand); font-size: rs.font-size(xl); @include rs.breakpoint(wide) { padding: rs.space(s); } @include rs.breakpoint(small) { margin: 0; } }
      @include rs.rules($responsive: true);`);
    expect(css).toMatch(/\.a \{\s*padding: 1rem;\s*color: #123456;\s*font-size: 1\.5rem;/);
    // A breakpoint without a media query (the smallest screens) applies always.
    expect(css).toMatch(/\.a \{\s*margin: 0;/);
    expect(css).toMatch(/@media all and \(min-width: 60em\) \{\s*\.a \{\s*padding: 4px;/);
    expect(css).toContain('--rs-space-m: 1rem;');
    expect(css).toContain('--rs-accent: #123456;');
    expect(css).toMatch(/\.rs-px-m \{\s*padding-inline: 1rem;/);
    expect(css).toMatch(/\.rs-bg-brand \{\s*background-color: #123456;/);
    expect(css).toMatch(/\.rs-text-2xl \{\s*font-size: 2rem;/);
    expect(css).toContain('.rs-m-s\\@wide');
    // The theme switched the position feature off.
    expect(css).not.toContain('.rs-pin-');
  });

  it('fails on a token the theme does not declare', () => {
    expect(() => compile(dir, `@use 'react-scaffold/tokens' as rs; .a { padding: rs.space(huge); }`)).toThrow(/Unknown spacing step "huge"/);
  });

  it('uses the breakpoints of the base theme when the theme has none, and writes a file', () => {
    const plain = theme('plain_theme', 'name: Plain\ntype: theme\nbase theme: react_scaffold\n');
    tokens(plain, '--out', 'scss/_tokens.scss');
    const scss = readFileSync(join(plain, 'scss/_tokens.scss'), 'utf8');
    expect(scss).toContain("'tablet': 'all and (min-width: 48em)'");
    expect(scss).toContain("$spacing: ('xs': 0.25rem");
  });
});
