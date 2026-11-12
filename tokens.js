// Design tokens of a theme as SCSS.
//
// A theme declares its tokens once, in YAML: the spacing scale, the palette and the type scale under
// `frame_builder.uikit` of <theme>.info.yml, and its breakpoints in <theme>.breakpoints.yml. The page builder
// (ThemeKit in the frame_builder module) reads them in PHP; this file gives the same values to the theme's SCSS:
//
//   @use 'frame/tokens' as frame;
//
//   .teaser { padding: frame.space(m); color: frame.color(green); @include frame.breakpoint(tablet) { padding: frame.space(l); } }
//   @include frame.rules;   // custom properties and a utility class for every choice an editor has in the builder
//
// The shared Vite config resolves that module; `frame tokens` prints it. Keep the rules in step with ThemeKit.
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import yaml from 'js-yaml';

const here = import.meta.dirname;

/** The Sass module name themes import. */
export const TOKENS_MODULE = 'frame/tokens';

/** Used when no theme of the chain declares a value: the same as ThemeKit::DEFAULTS. */
export const DEFAULTS = {
  spacing: { xs: '0.25rem', s: '0.5rem', m: '1rem', l: '2rem', xl: '4rem' },
  colors: {},
  font_sizes: { xs: '0.75rem', s: '0.875rem', m: '1rem', l: '1.25rem', xl: '1.5rem', '2xl': '2rem', '3xl': '3rem' },
  sizes: { xs: '20rem', s: '30rem', m: '45rem', l: '60rem', xl: '75rem', full: '100%' },
  features: { spacing: true, size: true, align: true, text: true, background: true, position: true },
};

const LENGTH = /^-?\d+(\.\d+)?(px|rem|em|%|vw|vh|ch)$/;
const COLOR = /^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9.,%\s/]+\)|[a-z]+)$/i;

const isMap = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/** The .info.yml of the theme in a folder: [machine name, parsed file], or null. */
function themeInfo(dir) {
  if (!existsSync(dir)) return null;
  for (const file of readdirSync(dir)) {
    if (!file.endsWith('.info.yml')) continue;
    const info = yaml.load(readFileSync(join(dir, file), 'utf8'));
    if (isMap(info) && info.type === 'theme') return [file.slice(0, -'.info.yml'.length), info];
  }
  return null;
}

/** Folder of a base theme: next to the theme, in themes/contrib or themes/custom, or this base theme itself. */
function findTheme(name, from) {
  const themes = dirname(from);
  const candidates = [join(themes, name), join(dirname(themes), 'contrib', name), join(dirname(themes), 'custom', name)];
  if (themeInfo(here)?.[0] === name) candidates.unshift(here);
  return candidates.find((dir) => themeInfo(dir)?.[0] === name);
}

/** The theme in `root` and its base themes, the theme itself first. */
function themeChain(root) {
  const chain = [];
  let dir = root;
  while (dir && chain.length < 10) {
    const found = themeInfo(dir);
    if (!found) break;
    const [name, info] = found;
    chain.push({ name, dir, info });
    const base = info['base theme'];
    dir = typeof base === 'string' && base ? findTheme(base, dir) : null;
  }
  return chain;
}

/**
 * The tokens of the theme in `root`, merged over its base themes as ThemeKit does: a scale or the palette is replaced
 * as a whole by the nearest theme that declares it, features are merged one by one.
 *
 * @returns {{theme: string, spacing: object, colors: object, fontSizes: object, sizes: object, features: object, breakpoints: object, files: string[]}}
 *   `breakpoints`: name => media query, smallest first; `files`: the YAML files read (for a watcher).
 */
export function readTokens(root) {
  const chain = themeChain(root);
  const settings = structuredClone(DEFAULTS);
  const files = [];
  for (const { name, dir, info } of [...chain].reverse()) {
    files.push(join(dir, `${name}.info.yml`));
    const own = isMap(info.frame_builder) ? info.frame_builder : {};
    for (const key of ['spacing', 'colors', 'font_sizes', 'sizes']) {
      if (isMap(own.uikit) && isMap(own.uikit[key])) settings[key] = own.uikit[key];
    }
    if (isMap(own.features)) Object.assign(settings.features, own.features);
  }

  const lengths = (scale) => Object.fromEntries(
    Object.entries(scale).map(([name, value]) => [name, String(value).trim()]).filter(([, value]) => LENGTH.test(value) || value === '0'),
  );
  const colors = {};
  for (const [name, color] of Object.entries(settings.colors)) {
    const value = String(isMap(color) ? color.value ?? '' : color);
    if (COLOR.test(value)) colors[name] = value;
  }

  // Breakpoints of the first theme in the chain that declares any, as the builder's preview does.
  let breakpoints = {};
  for (const { name, dir } of chain) {
    const file = join(dir, `${name}.breakpoints.yml`);
    if (!existsSync(file)) continue;
    files.push(file);
    const declared = yaml.load(readFileSync(file, 'utf8'));
    if (!isMap(declared) || !Object.keys(declared).length) continue;
    breakpoints = Object.fromEntries(
      Object.entries(declared)
        .filter(([, breakpoint]) => isMap(breakpoint))
        .sort(([, a], [, b]) => (a.weight ?? 0) - (b.weight ?? 0))
        // "my_theme.tablet" => "tablet"; no braces or semicolons may leave the media query.
        .map(([id, breakpoint]) => [id.slice(id.indexOf('.') + 1).replace(/[^a-z0-9_-]/gi, '-'), String(breakpoint.mediaQuery ?? '').replace(/[{}<>;]/g, '').trim()]),
    );
    break;
  }

  return {
    theme: chain[0]?.name ?? '',
    spacing: lengths(settings.spacing),
    colors,
    fontSizes: lengths(settings.font_sizes),
    sizes: lengths(settings.sizes),
    features: Object.fromEntries(Object.keys(DEFAULTS.features).map((feature) => [feature, Boolean(settings.features[feature])])),
    breakpoints,
    files,
  };
}

const key = (name) => `'${String(name).replace(/[^a-z0-9_-]/gi, '-')}'`;
const map = (entries, value = (v) => v) => `(${Object.entries(entries).map(([name, v]) => `${key(name)}: ${value(v)}`).join(', ')})`;
const quoted = (text) => `'${String(text).replace(/['\\]/g, '')}'`;

/** The SCSS module for a set of tokens (see readTokens). */
export function tokensScss(tokens) {
  return `// Generated from ${tokens.theme || 'the defaults'}: .info.yml (frame_builder) and .breakpoints.yml. Do not edit.
@use 'sass:list';
@use 'sass:map';

$theme: ${quoted(tokens.theme)};
// Spacing scale: margin, padding, gaps, offsets.
$spacing: ${map(tokens.spacing)};
// Palette: the colours an editor can pick.
$colors: ${map(tokens.colors)};
// Type scale.
$font-sizes: ${map(tokens.fontSizes)};
// Sizes: how wide and how tall a component can be.
$sizes: ${map(tokens.sizes)};
// Breakpoints: name => media query ('' for the smallest screens), smallest first.
$breakpoints: ${map(tokens.breakpoints, quoted)};
// Builder features offered on components by default.
$features: ${map(tokens.features)};
// The fixed choices of the Text feature.
$font-weights: ('normal': 400, 'medium': 500, 'bold': 700);
$line-heights: ('tight': 1.2, 'normal': 1.5, 'loose': 1.8);

@function -token($tokens, $name, $kind) {
  $name: '#{$name}';
  @if not map.has-key($tokens, $name) {
    @error 'Unknown #{$kind} "#{$name}". The theme declares: #{map.keys($tokens)}.';
  }
  @return map.get($tokens, $name);
}

/// A step of the spacing scale: \`padding: frame.space(m)\`.
@function space($name) {
  @return -token($spacing, $name, 'spacing step');
}

/// A colour of the palette: \`color: frame.color(green)\`.
@function color($name) {
  @return -token($colors, $name, 'colour');
}

/// A step of the type scale: \`font-size: frame.font-size(xl)\`.
@function font-size($name) {
  @return -token($font-sizes, $name, 'font size');
}

/// One of the theme's sizes: \`max-width: frame.size(l)\`.
@function size($name) {
  @return -token($sizes, $name, 'size');
}

/// Styles from a breakpoint of the theme up: \`@include frame.breakpoint(tablet) { ... }\`.
@mixin breakpoint($name) {
  $query: -token($breakpoints, $name, 'breakpoint');
  @if $query == '' {
    @content;
  } @else {
    @media #{$query} {
      @content;
    }
  }
}

/// The tokens as CSS custom properties, the names the page builder uses: --frame-space-*, --frame-font-*, --frame-color-*
/// and --frame-accent (the palette's "accent" or "primary", else its first colour).
@mixin custom-properties($selector: ':root') {
  #{$selector} {
    @each $name, $value in $spacing {
      --frame-space-#{$name}: #{$value};
    }
    @each $name, $value in $font-sizes {
      --frame-font-#{$name}: #{$value};
    }
    @each $name, $value in $colors {
      --frame-color-#{$name}: #{$value};
    }
    @each $name, $value in $sizes {
      --frame-size-#{$name}: #{$value};
    }
    @if map.has-key($colors, 'accent') {
      --frame-accent: #{map.get($colors, 'accent')};
    } @else if map.has-key($colors, 'primary') {
      --frame-accent: #{map.get($colors, 'primary')};
    } @else if list.length($colors) > 0 {
      --frame-accent: #{list.nth(map.values($colors), 1)};
    }
  }
}

// One set of utility classes; $at is '' or an escaped suffix such as '\\@tablet'.
@mixin -utilities($prefix, $at) {
  $sides: ('': '', 'x': '-inline', 'y': '-block', 't': '-block-start', 'b': '-block-end', 's': '-inline-start', 'e': '-inline-end');

  @if map.get($features, 'spacing') {
    @each $side, $suffix in $sides {
      @each $name, $value in $spacing {
        .#{$prefix}-m#{$side}-#{$name}#{$at} { margin#{$suffix}: $value; }
        .#{$prefix}-p#{$side}-#{$name}#{$at} { padding#{$suffix}: $value; }
      }
      .#{$prefix}-m#{$side}-0#{$at} { margin#{$suffix}: 0; }
      .#{$prefix}-p#{$side}-0#{$at} { padding#{$suffix}: 0; }
      .#{$prefix}-m#{$side}-auto#{$at} { margin#{$suffix}: auto; }
    }
    @each $name, $value in $spacing {
      .#{$prefix}-gap-#{$name}#{$at} { gap: $value; }
    }
  }

  @if map.get($features, 'size') {
    @each $name, $value in $sizes {
      .#{$prefix}-w-#{$name}#{$at} { max-width: $value; }
      .#{$prefix}-h-#{$name}#{$at} { min-height: $value; }
    }
  }

  @if map.get($features, 'align') {
    @each $value in (start, center, end) {
      .#{$prefix}-align-#{$value}#{$at} { text-align: $value; }
    }
  }

  @if map.get($features, 'text') {
    @each $name, $value in $font-sizes {
      .#{$prefix}-text-#{$name}#{$at} { font-size: $value; }
    }
    @each $name, $value in $font-weights {
      .#{$prefix}-weight-#{$name}#{$at} { font-weight: $value; }
    }
    @each $name, $value in $line-heights {
      .#{$prefix}-leading-#{$name}#{$at} { line-height: $value; }
    }
    .#{$prefix}-italic#{$at} { font-style: italic; }
    @each $value in (uppercase, lowercase, capitalize) {
      .#{$prefix}-#{$value}#{$at} { text-transform: $value; }
    }
    @each $name, $value in $colors {
      .#{$prefix}-color-#{$name}#{$at} { color: $value; }
    }
  }

  @if map.get($features, 'background') {
    @each $name, $value in $colors {
      .#{$prefix}-bg-#{$name}#{$at} { background-color: $value; }
    }
    @each $name, $value in ('cover': cover, 'contain': contain, 'stretch': 100% 100%, 'auto': auto) {
      .#{$prefix}-bg-#{$name}#{$at} { background-repeat: no-repeat; background-size: $value; }
    }
  }

  @if map.get($features, 'position') {
    .#{$prefix}-relative#{$at} { position: relative; }
    @each $name, $corner in ('top-left': (top, left), 'top-right': (top, right), 'bottom-left': (bottom, left), 'bottom-right': (bottom, right)) {
      .#{$prefix}-pin-#{$name}#{$at} { position: absolute; z-index: 1; #{list.nth($corner, 1)}: 0; #{list.nth($corner, 2)}: 0; }
    }
  }
}

/// A utility class for every choice an editor has in the builder, for the features the theme offers:
/// .frame-m-l, .frame-px-m, .frame-my-auto, .frame-gap-s, .frame-align-center, .frame-text-xl, .frame-weight-bold, .frame-leading-tight,
/// .frame-uppercase, .frame-color-green, .frame-bg-cream, .frame-bg-cover, .frame-pin-top-right...
/// With $responsive, each class also exists per breakpoint that has a media query: .frame-p-l\\@tablet.
@mixin utilities($prefix: 'frame', $responsive: false) {
  @include -utilities($prefix, '');
  @if $responsive {
    @each $name, $query in $breakpoints {
      @if $query != '' {
        @media #{$query} {
          @include -utilities($prefix, '\\\\@#{$name}');
        }
      }
    }
  }
}

/// Everything at once: the custom properties and the utility classes.
@mixin rules($prefix: 'frame', $responsive: false) {
  @include custom-properties;
  @include utilities($prefix, $responsive);
}
`;
}

/** Sass importer (modern API) that serves the tokens of the theme in `root` as the module 'frame/tokens'. */
export function tokensImporter(root) {
  const url = 'frame:tokens';
  return {
    canonicalize: (name) => (name === TOKENS_MODULE || name === url ? new URL(url) : null),
    load: (canonical) => (canonical.href === url ? { contents: tokensScss(readTokens(root)), syntax: 'scss' } : null),
  };
}

/** Vite plugin: rebuild in watch mode when the YAML the tokens come from changes. */
export function tokensWatch(root) {
  return {
    name: 'frame-tokens',
    buildStart() {
      readTokens(root).files.forEach((file) => this.addWatchFile(file));
    },
  };
}
