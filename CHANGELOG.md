# Changelog

The public API of the base theme, which follows semantic versioning:

- library names: `frame/react`, `frame/react-api-client`, `frame/global-libraries`, `frame/global-styling`,
- globals: `apiClient`, `executeWhenVisible`, `rawHtml`, `attachBehaviors`, `detachBehaviors`, `reattachBehaviors`, `withDrupalBehaviors`,
- `drupalSettings.csrfToken`,
- regions and the CSS class names of `page.html.twig` (`.page`, `.page__header`, `.page__main`, ...),
- `bin/frame.mjs` (`new`, `build`, `watch`, `test`, `tokens`), `vite.base.js` (`createViteConfig` options) and `jest.base.cjs`,
- the Sass module `frame/tokens` (functions `space`, `color`, `font-size`; mixins `breakpoint`, `custom-properties`, `utilities`, `rules`),
- the recipes in `recipes/` and their inputs,
- the starterkit `frame_starterkit` used with `generate-theme`.

## Unreleased

- **Breaking: the project is renamed to Frame.** Every name that had the old one changed, and this file uses the new
  names for earlier entries too. There is no upgrade path for an installed site: uninstall the old module and theme, then install the new ones.
  - theme `react_scaffold` is `frame`; starterkit `frame_starterkit`; example `frame_demo`; libraries `frame/react`, …
  - modules `react_scaffold_builder` and `react_scaffold_builder_ai` are `frame_builder` and `frame_builder_ai`, with
    their hooks, services, routes (`/frame-builder/…`), permissions (`use frame builder`, `use frame builder ai`),
    config, the entity type `frame_saved` and `drupalSettings.frameBuilder`,
  - recipes `frame_builder`, `frame_builder_paragraphs`, `frame_builder_seo`, `frame_builder_ai`, `frame_builder_full`;
    the fields of the *Text block* paragraph are `field_frame_title` and `field_frame_text`,
  - command line `bin/frame.mjs`, release archive `frame-<version>.zip`,
  - Sass module `@use 'frame/tokens' as frame;`, custom properties `--frame-*` (were `--rs-*`), utility classes
    `.frame-*` (were `.rs-*`),
  - classes, attributes and custom properties of the builder: `frame-builder-*`, `data-frame-builder-*`,
    `--frame-builder-*` (were `rsb-*`, `data-rsb-*`, `--rsb-*`).

- Page builder: a new feature **Size** in *Layout and style*: how wide a component may be (`max-width`) and how tall it
  is at least (`min-height`), one of the theme's sizes or a custom length. Sizes are a new token, `uikit: sizes:` in the
  theme's `.info.yml` (defaults `xs` 20rem to `xl` 75rem and `full`), with `features: size:`; in the Sass module
  `frame.size()`, `frame.$sizes`, `--frame-size-*` and the classes `.frame-w-<size>`, `.frame-h-<size>`. Stored as `_size`.
- Page builder: with the **Paragraphs Library** module a Paragraph block saved to the library is an item of that
  library, with its revisions and log messages; a page that uses it gets a *From library* paragraph, made and dropped
  by the builder. *From library* is no longer offered in the Blocks panel (blocks of it on existing pages keep working).
  The Library box shows the type of the paragraph in place of a status.
- Page builder: a Paragraph block saved to the library before its page was saved, or updated in the library, lost what
  was typed: the saved block now gets a paragraph of its own with those values (and a new revision on update); a copy on
  a page, and a duplicated block, get their own paragraph when the page is saved.
- Page builder: the **Library** part of the right column is a box like the one of Drupal's node form (status, name,
  last saved, author), not a section that folds, with a **Revision log message** for saving and updating a saved block.
  It is above *Layout and style*, and offered for Paragraph blocks only: other components show it when they are a saved
  block or a copy of one. Run `drush updb` (a new field on saved blocks).
- Page builder: the sections of *Layout and style* look like Claro's accordion beside the node form; what is set shows
  on a line under the title.
- **Tokens in SCSS**: a theme's design tokens (`frame_builder: uikit:` in its `.info.yml`, and its `.breakpoints.yml`) are a
  Sass module in the shared build, `@use 'frame/tokens' as frame;`: `frame.space()`, `frame.color()`, `frame.font-size()`,
  `@include frame.breakpoint()`, and `@include frame.rules` for the custom properties and a utility class per choice of each builder feature.
  `frame tokens [--out file]` prints or writes the module for other builds. The starterkit and the demo use it.
- **Recipes** (`recipes/`): `frame_builder` (builder, media library, breakpoints, responsive image styles, Content editor role),
  `frame_builder_paragraphs` (Paragraphs, Entity Reference Revisions, Paragraphs Library, Entity Usage),
  `frame_builder_seo` (Metatag), `frame_builder_ai` (AI) and `frame_builder_full`.
- The base theme has breakpoints (`frame.breakpoints.yml`: mobile, tablet, desktop), used by subthemes that declare none.
- New dependency of the build tooling: `js-yaml`.
- Docs: *Build a site*, a step-by-step manual, and *Recipes*.

- **Page builder**: the module `frame_builder`, inside the theme folder (`modules/frame_builder`). For content types
  that use it, the edit page is a visual editor (Puck) over the theme's Single Directory Components; the page is rendered by Drupal.
  It works only with Frame or a subtheme as the default theme. See the new *Page builder* section of the docs. Tested on Drupal 11.4.
- Public API added with it, read from a theme's `.info.yml` and `*.component.yml`:
  - `frame_builder: uikit:` (`spacing`, `colors`, `image_styles`) and `features:` in the theme info,
  - `thirdPartySettings.frame_builder.features` and `.icon` in a component,
  - prop schema keywords `x-builder-widget` (`length`, `icon`, `hidden`, or a field name), `x-builder-icon-class`, `x-builder-format`,
    `x-builder-translatable`, `x-builder-picture`, `x-builder-srcset`, `x-builder-sizes`, `x-builder-alt`,
  - the Views display type **Component** and the Views style **React component (SDC)**,
  - the marker attributes `data-frame-builder-slot` and `data-puck-dropzone`, for the CSS of components that lay out a slot.
- Responsive image styles in the builder: an image prop gets Drupal's own `<picture>` / `<img srcset>` (`x-builder-picture`), and a
  background image with a responsive image style is set by media queries.
- Base theme components `frame:columns`, `frame:flex`, `frame:stack` (classes `layout-*`) and
  `frame:image` (class `image-block`), Twig and CSS only.
- Field formatter **Responsive image** for media reference fields (`frame_builder_media_responsive`), also for *Manage display*.
- Component features `spacing`, `align` (start, centre, end; follows the reading direction), `background` and `position`
  (placed in the flow, or pinned to a corner of the parent).
- Editor: a Blocks panel with icons and a search that forgives typos; a history timeline next to undo and redo.
- Page builder: components have a schema `version` with declared `migrations` (and a hook for code); layouts store the
  version of the format and of every item and are migrated when read.
- Page builder: a tracking plugin for the Entity Usage module reports the media and linked content of a layout.
- Page builder: margin *Auto*; the Image component follows the alignment; link fields suggest pages by path when typing `/`;
  images no longer flash when a component is rendered again; descriptions for the props of the shipped components.
- Page builder: a **library of saved blocks**. A component with everything in it is saved and used on other pages,
  linked (follows the saved block) or as a copy (the page's own, marked as changed), with update and reset.
- Page builder: **Paragraphs**. Every paragraph type is a block, edited with the paragraph's own form and saved with the page; the layout names
  the paragraph revision, the page lists its paragraphs in an Entity Reference Revisions field.
- Page builder: **AI assistant**, in the new module `frame_builder_ai` (needs the AI module). A chat that
  composes a page from the theme's components; the answer is validated against the components before it is shown.
- Page builder AI: the assistant searches the media library, checks the HTML its layout renders to and, if allowed,
  a screenshot of the canvas; images, position and layout options are part of what it may set.
- Page builder: the paragraph form has no Apply: it is kept as you type and saved with the page. A list of the library
  under *Content*. Folding sections of the right column look like Drupal's details elements.
- Page builder: a new Field block starts with the format of the content type's default display. The assistant gives
  chosen images an image style and is told example values of props.
- Base theme: a set of general components: Hero, Section, Container, Divider, Spacer, Heading, Text, Button, Card,
  Quote, Call to action, Accordion, Number with a label, Badge, Notice. Each has descriptions, an icon and an example.
  The theme's tokens are on every page as CSS custom properties (`--frame-space-*`, `--frame-color-*`, `--frame-accent`).
- Page builder: `x-builder-widget: color` (a colour of the palette); a component a theme replaces is offered once, as
  the theme's version; components declare an `example`.
- Page builder AI: the assistant is told the UI kit with its values, the measured look of the frontend theme and each
  component's example; it can search media by alternative text and file name, existing paragraphs (and start a block
  as a copy of one) and pages to link to, and ask for the page the theme renders around the layout.
- Page builder AI: on a translation the assistant translates (texts and links only where the structure is shared);
  with the Metatag module it proposes meta tags, shown unsaved in the page settings; it can list the tokens that exist.
- Page builder: a **Text** feature on every component (size from the theme's new type scale `font_sizes`, weight,
  italic, case, line height, colour of the palette) and a **Label style** for the label of Field blocks.
- Documentation: a *Page builder* section (overview, components, views, tokens and styling, layouts, library, paragraphs, AI assistant, architecture).
- `vite.base.js`: `base: './'`, so fonts and images referenced from CSS get URLs relative to the bundle instead of the web root.
- Starterkit: a `frame_builder` block with default tokens in the generated `.info.yml`.
- Example subtheme: components `card`, `section` and `rich-text`; `react-tooltip` has a trigger text and an icon (Bootstrap Icons);
  design tokens and breakpoints for the builder.
- New scripts: `npm run builder:install`, `builder:build`, `builder:watch`, `builder:smoke`. Releases build the editor into the archive.

## 1.0.0

- The scaffold is a base theme (`base theme: false`, no dependency on stable9 or Umami) with subthemes. The Umami specific code moved to the
  example subtheme `examples/frame_demo`.
- Subthemes are created with `node <base theme>/bin/frame.mjs new my_theme`, or with core's `generate-theme --starterkit frame_starterkit`.
- A subtheme has no build configuration and no build dependencies: its `package.json` scripts call `bin/frame.mjs build|watch|test` of the base theme,
  which owns Vite, Sass, Jest and their configuration (`createViteConfig`, `jest.base.cjs`). A theme can still add its own `vite.config.js` / `jest.config.cjs`.
- React imports are mapped to the page globals by a small plugin in `vite.base.js` (`reactFromGlobals`). `vite-plugin-external` is removed: it wrote its
  stub files concurrently and a build could fail at random with `"useState" is not exported`. Subthemes get no `node_modules/.vite_external` folder any more.
- Base theme runtime sources live in `src/`. Compiled `assets/` are not committed: releases (`release.yml`) build them and attach archives.
- `helpers.jsx` reads `window.Drupal` / `drupalSettings` lazily.
- Page layout template and neutral layout CSS in the base theme.
- Example subtheme: node-list headers are readable (`Author`, `Modal edit`), dates are short, the table shows all rows.
- Every guide was run in a clean environment and fixed where it failed:
  - starterkit `hello-react`: optional props use `|default()` (core SDC does not apply schema defaults, an omitted `start` gave `NaN`),
  - example view `recipe_explorer` declared a dependency on a config that does not exist, so Drupal skipped it on theme install,
  - docs: webpack config is tested (classic scripts, no `type: module`), "existing theme" needs the CSRF hook, `create-your-theme` minimum includes the libraries file.
- Example subtheme: Spanish translations (`translations/es.po`, `config/optional/language/es`), node-list cards on narrow screens.
- New guide: Translations.
