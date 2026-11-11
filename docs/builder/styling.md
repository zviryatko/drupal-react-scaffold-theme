# Tokens, features and global styling

The builder has no styling options of its own. Everything an editor can choose is a value your theme declares, and
everything it prints is your theme's CSS plus a few declarations made from those values. This page is about that
contract.

## Who styles what

| Layer | Where | Decides |
|---|---|---|
| Global look | the theme's global CSS (`scss/global.scss` in a subtheme) | fonts, colours, page layout, base elements |
| Component look | `components/<name>/*.css` or `.scss` | everything inside a component |
| Tokens | `react_scaffold_builder: uikit:` in the theme's `.info.yml` | the values editors can pick |
| Features | the same block, and a component's `thirdPartySettings` | which adjustments editors can make to a component |
| Editor chrome | the module's `css/builder.css` | the editor's own UI, never the page |

An editor cannot type a colour, and cannot type CSS. The most free value is a length such as `12px` or `5%`, which is
checked before it is printed.

## Tokens

Declared in the `.info.yml` of a theme. A subtheme overrides what its base theme declares; a scale or a palette is
replaced as a whole.

```yaml
react_scaffold_builder:
  uikit:
    # Spacing scale: name => CSS length. Used for margin, padding, gaps and offsets.
    spacing:
      xs: 0.25rem
      s: 0.5rem
      m: 1rem
      l: 2rem
      xl: 4rem
    # Type scale: name => CSS length. The sizes an editor can give text.
    font_sizes:
      s: 0.875rem
      m: 1rem
      l: 1.25rem
      xl: 1.5rem
    # Palette: the only colours an editor can pick.
    colors:
      green: { label: 'Green', value: '#008068' }
      cream: { label: 'Cream', value: '#fcf4ed' }
    # Image styles offered for images: an image style, or "responsive:<responsive image style>".
    # Leave the key out to offer all of them.
    image_styles:
      - large
      - 'responsive:wide'
  # Offered on every component (see below).
  features:
    spacing: true
    align: true
    text: true
    background: true
    position: true
```

Without the block a theme gets the spacing scale above (XS to XL), a type scale from XS (0.75rem) to 3XL (3rem), no
colours, all image styles and all features. The starterkit
puts a block with defaults into a new subtheme. After changing it, rebuild caches (`drush cr`).

The names are what is stored in a layout (`"size": "m"`, `"color": "green"`), not the values. Changing `m` from `1rem`
to `1.25rem` changes every page that uses it; removing a name makes the places that used it fall back to nothing.

The module also prints the tokens on every page of the theme as CSS custom properties: `--rs-space-<name>` for each
step of the scale, `--rs-font-<name>` for each font size, `--rs-color-<name>` for each colour, and `--rs-accent` (the colour named `accent` or `primary`, else
the first one), which the [components of the base theme](/builder/base-components#colours-and-tokens) use. Your CSS can
use them: `padding: var(--rs-space-m)`.

## Tokens in SCSS

The tokens are declared once, in YAML, and the theme's stylesheets read the same file. The build of the theme gives
them as a Sass module:

```scss
@use 'react-scaffold/tokens' as rs;

.teaser {
  padding: rs.space(m);
  color: rs.color(green);
  font-size: rs.font-size(l);

  @include rs.breakpoint(tablet) {
    padding: rs.space(l);
  }
}
```

So "padding M" chosen by an editor and `rs.space(m)` in a component are the same `1rem`, and changing the scale in the
`.info.yml` changes both. A name the theme does not declare stops the build with the list of the names it has.

| In the module | Is |
|---|---|
| `rs.space($name)`, `rs.color($name)`, `rs.font-size($name)` | a value of the spacing scale, the palette, the type scale |
| `@include rs.breakpoint($name) { … }` | the media query of a breakpoint of `<theme>.breakpoints.yml`; the name is the part after the theme (`my_theme.tablet` is `tablet`). A breakpoint without a media query applies always |
| `rs.$spacing`, `rs.$colors`, `rs.$font-sizes`, `rs.$breakpoints`, `rs.$features` | the same as maps, to loop over |
| `@include rs.custom-properties;` | `:root { --rs-space-m: 1rem; … }`: the custom properties the module prints, for pages and tools that load only your CSS |
| `@include rs.utilities;` | a class for every choice an editor has (below) |
| `@include rs.rules;` | both of the above |

Values are merged as the builder does: the theme's own block, else its base theme's, else the defaults. Breakpoints are
those of the first theme in the chain that has a `.breakpoints.yml`; the base theme has `mobile`, `tablet` (48em) and
`desktop` (75em).

### Rules for the features

`rs.utilities` writes one class per value of each [feature](#features) the theme offers, so templates and components
can use what editors use in the builder:

| Feature | Classes (prefix `rs`) |
|---|---|
| spacing | `.rs-m-<step>`, `.rs-p-<step>`; a side with `x`, `y`, `t`, `b`, `s`, `e` (`.rs-px-m`, `.rs-mt-l`); `-0` and, for margin, `-auto` (`.rs-mx-auto`); `.rs-gap-<step>` |
| align | `.rs-align-start`, `-center`, `-end` |
| text | `.rs-text-<size>`, `.rs-weight-normal`/`-medium`/`-bold`, `.rs-leading-tight`/`-normal`/`-loose`, `.rs-italic`, `.rs-uppercase`/`-lowercase`/`-capitalize`, `.rs-color-<colour>` |
| background | `.rs-bg-<colour>`, `.rs-bg-cover`/`-contain`/`-stretch`/`-auto` |
| position | `.rs-relative`, `.rs-pin-top-left`/`-top-right`/`-bottom-left`/`-bottom-right` |

Sides are logical (`s` is the start of the line: left in English, right in Arabic). A feature switched off in the
theme's `features:` writes no classes.

```scss
@include rs.utilities($prefix: 'u', $responsive: true);
```

`$prefix` changes `rs`; `$responsive` adds each class per breakpoint that has a media query, as `.u-p-l\@tablet`
(`class="u-p-m u-p-l@tablet"`).

The builder itself does not need these classes: what an editor chooses is printed as inline declarations on the
component. They are for the markup you write.

### Outside the shared build

`@use 'react-scaffold/tokens'` is resolved by the base theme's Vite config (`vite.base.js`). With your own build, write
the module to a file and import that:

```bash
node ../../contrib/react_scaffold/bin/react-scaffold.mjs tokens --out scss/_tokens.scss
```

Run it again after changing the YAML (or in a `prebuild` script). Without `--out` it prints the SCSS.

## Features

A feature is an adjustment an editor can make to any component, in the **Layout and style** part of the right column.
A section there is folded while nothing is set in it.

| Feature | The editor chooses | What is printed on the root element |
|---|---|---|
| **spacing** | margin and padding: a step of the scale or a custom length; all sides, horizontal or vertical. Margin can also be *Auto* | `margin`, `margin-inline` or `margin-block`; the same for `padding` |
| **align** | start, centre or end | `text-align: start \| center \| end` |
| **text** | size (a step of the type scale, or a custom length), weight, italic, case, line height, a colour of the palette | `font-size`, `font-weight`, `font-style`, `text-transform`, `line-height`, `color` |
| **background** | a colour of the palette; an image with fill and visible part | `background-color`; `background-image`, `-size`, `-repeat`, `-position` |
| **position** | *placed* or *pinned* | see [Layouts](/builder/layouts#position) |

They are inline declarations on the root element of the component, passed through its `attributes`. A component
therefore needs no code for them, only `{{ attributes }}` on its root element
([Components](/builder/components#features-and-the-root-element)). Fields, views and Drupal blocks have no root element
of their own, so they get a wrapping `<div>` when a feature is set.

Which features a component has:

1. the theme's `features:` (default: all on);
2. changed per component in its `*.component.yml`:

```yaml
thirdPartySettings:
  react_scaffold_builder:
    features: { background: false }
```

Switch a feature off where it makes no sense or would fight the component's own CSS: a background on a component that
draws its own, padding on one whose layout depends on exact spacing.

### Things to know about each

- **Spacing and your CSS.** Inline declarations win over your stylesheet. A margin set by an editor replaces the margin
  your component has on that side.
- **Alignment is logical.** `start` is the left in English and the right in Arabic or Hebrew; the control says so. It
  sets `text-align`, so it aligns text and inline content inside the component, not the component itself (that is
  *position*, or a horizontal margin of *Auto* for a component with a width). Block-level content does not follow
  `text-align`: a component that shows an image or a button as a block should make it inline
  (`display: inline-block`), as the base theme's Image does.
- **Text is inherited.** The Text feature sets the look on the root element of the component; what is in it follows,
  except elements your CSS gives a size or colour of their own (most headings have a size). Use sizes relative to the
  parent (`em`) in a component if its heading should follow.
- **Background colour does not change the text colour.** Choose a text colour with it (the Text feature), or give
  components a variant prop that sets a matching one.
- **Background image.** *Cover* fills and crops, *Fit* shows the whole image, *Stretch* distorts it to the box,
  *Original size* does not scale. The small picture with a frame chooses which part shows (`background-position`).
- **Responsive background.** With a responsive image style the image is not inline: it is set by media queries in the
  head of the page, on a `data-rsb-bg` attribute. The queries are the breakpoints of the responsive image style, or for a
  style that uses `sizes`, steps by the width of each image.

## Images

- **Image styles** offered to editors are the `image_styles` token.
- **Responsive image styles** are rendered by Drupal exactly as configured: a `<picture>` with a source per breakpoint,
  or an `<img srcset sizes>`. Define the breakpoints in your theme's `<theme>.breakpoints.yml` as usual. A component opts
  in with `x-builder-picture` ([Components](/builder/components#images)).
- For a **media field** of the page there is the field formatter **Responsive image** (added by the module, also on
  *Manage display*): the image of the media item with a responsive image style, lazy or eager loading, and a link to the
  page, the media item, the image file or a custom URL.

## Breakpoints

`<theme>.breakpoints.yml` (Drupal's Breakpoint module) does two things here: responsive image styles are built on it, and
the editor's **Preview** offers one width per breakpoint. A breakpoint with a `min-width` gives that width; one without
gives a phone width (375px).

```yaml
my_theme.mobile:  { label: Mobile,  mediaQuery: '',                          weight: 0, multipliers: [1x] }
my_theme.tablet:  { label: Tablet,  mediaQuery: 'all and (min-width: 48em)', weight: 1, multipliers: [1x] }
my_theme.desktop: { label: Desktop, mediaQuery: 'all and (min-width: 75em)', weight: 2, multipliers: [1x] }
```

A subtheme without the file uses the breakpoints of its base theme; React Scaffold has `mobile`, `tablet` (48em) and
`desktop` (75em). In SCSS they are `@include rs.breakpoint(tablet)`, see [Tokens in SCSS](#tokens-in-scss).

The preview is a real page in a frame of that width, so your media queries apply. The canvas of the editor does not
change width.

## Rich text

Rich text props use the text formats of the site. The editor shows the CKEditor 5 toolbar of the format, and Drupal
filters the text with that format on output. Style the result in the component (`.rich-text p { … }`), and decide per
prop whether editors may switch format (`x-builder-format` pins one).

## Field labels

A **Field** block prints a field of the page with a formatter and a label position. Its **Label style** sets the
size, weight, case, line height and colour of the label alone, from the same type scale and palette; the Text feature
of the block applies to label and value together. The label style reaches the label as custom properties
(`--rsb-label-font-size`, `--rsb-label-color`…), which a field template of your own can use too. Core's field template has no classes,
so the builder wraps a field in `.rsb-field` and styles the label by position: bold; *inline* puts label and value on one
line with a colon. If your theme has its own field template with `.field__label`, that styling is left alone. Override
either in your theme's CSS:

```css
.rsb-field--label-inline > div > div:first-child { font-weight: 400; text-transform: uppercase; }
```

## Right-to-left

Everything the builder prints is logical: `margin-inline`, `text-align: start`, positions by start and end. A page in a
right-to-left language mirrors without separate values. Do the same in your component CSS (`margin-inline-start`, not
`margin-left`).

## What exists only in the editor

The editor adds a little CSS to the canvas, scoped to `#react-scaffold-builder`, and two attributes your CSS can see:

| | |
|---|---|
| `[data-rsb-slot]` | the marker Drupal renders in a slot, where the editor mounts its drop zone |
| `[data-puck-dropzone]` | the drop zone; the items of a slot are inside it |
| a 12px strip above and below the content of a slot | room to drop next to a child that has a slot of its own |
| a placeholder for an empty Image, field, view or block | so that it can be seen and selected |

None of it is on the published page. The only CSS of yours that needs to know about it is a slot that is laid out, see
[Layouts](/builder/layouts#your-own-layout-component).

One limitation follows from where the editor runs: it is a page of your theme, so very broad rules in your global CSS
(`button { … }`, `label { … }`, `* { … }`) also reach the editor's own controls. Scope such rules to your page markup
(`.page button`) if the editor looks off.
