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

- Page builder: **Field Group**. With the Field Group module, every top-level group of the form of a content type is a
  panel of the editor, next to *Content*, with its own button in the rail on the left; of a top-level *Tabs* group the
  tabs are the panels. Groups inside a panel stay groups, a *Details sidebar* group is in the page settings. The
  settings of a group have **Icon in the page builder** (a name of the editor's icon set or classes of an icon font;
  without one the button shows the initials of the label). `drupalSettings.frameBuilder.sections` is now a list of
  `{ name, label, icon }`. The recipe `frame_builder_field_group` installs the module; `frame_builder_full` includes it.
- Page builder: **lists and groups**. A prop of the type `array` is a field with several values, as a multiple value
  field of Drupal and drawn as Claro draws one: a table with the label in its header, a row per value with a handle,
  the field of its kind (or the fields of an object) and Remove, *Add another item*, *Show row weights* with an Order
  column, the marks and the warning of a changed order, and the column scrolls while dragging near its edge. A prop of the type `object` is a group of fields. Tokens, links to content,
  rich text and images work inside them; the AI assistant fills them; in the synced translation mode a list is
  translated as a whole. New base component **Links** (`frame:link-list`).
- Page builder: **what a component requires is checked when a page is saved**: props listed under `required`, also
  in the values of lists and in groups, and `minItems`. The message names the component and the field. A page with a
  required prop that is empty must be completed before it can be saved again.
- Page builder, **schemas of props** (from audits of two design systems): `oneOf`, `anyOf` and `allOf` are read (one
  part, or all together) instead of becoming a text field that loses its value; `const` has no field and is always
  passed; a prop named `id` no longer receives the id of the layout item; a yes/no `enum` is a checkbox; a number or a
  text (`type: [integer, string]`) keeps texts; `format: date`, `time`, `email` have their inputs; a list of an `enum`
  is checkboxes; limits of the schema (`minimum`, `maxLength`, `pattern`, `uniqueItems`…) and choices are checked on
  save; a choice that is no longer one of the `enum` is not passed; empty cells keep their place in a list of lists; a
  group with nothing set is not passed; text in square brackets that is no token of Drupal is no longer deleted.
- Page builder, lists: `examples` of `items` is what a new value starts with; `x-builder-summary` names the property
  shown for a folded value; an image can be an object (`type: object`, `x-builder-widget: image`) with `url`, `alt`,
  `width`, `height`, `srcset` and `sizes`. `MediaItems::resolve()` also gives `width` and `height`.
- Page builder: a **Border** feature on every component: a width of the theme on the sides chosen, a line style, a colour
  of the palette, and rounded corners, per breakpoint too. New tokens `border_widths` and `radii` (with defaults), as
  `--frame-border-*` and `--frame-radius-*`, `frame.border-width()` and `frame.radius()` in SCSS, and
  `.frame-border-*`, `.frame-rounded-*` classes; the AI assistant can set borders.
- Page builder: the title of the right column shows a house that goes back to the page and the component the selected
  one is in, small, above its name, in place of Puck's breadcrumbs.
- Page builder: a component can be left out of the list of blocks by the site (*Components of the page builder* under
  Content authoring) or by its theme (`thirdPartySettings.frame_builder.offer: false`); pages that have it still show it.
- Page builder: messages of the editor are notifications at the bottom right; the widths of the canvas are a bar across
  its top; Full width fills the space between the panels.
- Page builder: **Structure** has a search at its top that opens the tree to the component found and selects it, and
  several components are selected with Ctrl/Cmd + click,
  Shift + click or Shift + arrows, put into a new component of the group Layout with **+**, moved together (dragged,
  buttons or Alt + arrows) and deleted together (the panel, the Delete key, or **Delete *n*** in the header). Each is one step
  of Undo. Undo and redo keep the side panel that is open.
- Page builder: a component can have **help** for editors (`help:` in its `*.component.yml`, or
  `thirdPartySettings.frame_builder.help`): a **?** at the end of its name in the right column opens it. Columns, Hero,
  Button and Links have one.
- Page builder: the suggestions of a link field are chosen with the arrow keys, Enter or Tab, and closed with Escape.
- Page builder: the breakpoints are tabs in the look of Claro's tabs, above the features and at the top of the canvas;
  choosing one makes the canvas as wide as that breakpoint starts.
- Page builder: text, link and plain long text fields have two buttons at their end, shown on hover and focus: **T**
  opens the list of tokens (filtered by every word typed) and inserts the one picked where the caret was, **×** clears
  the field. Email fields have **×**. They replace the *Browse available tokens* link under each field; typing `[`
  still suggests tokens. The selected component's bar (name, duplicate, delete) is no longer under the header; long names in the Blocks
  panel take two lines; paired controls stack in a narrow column; a long choice of a select is its tooltip.
- Page builder: **layout and style per breakpoint**. Spacing, size, alignment, text and background of a component can
  differ from a breakpoint of the theme on (*All*, *Tablet*, *Desktop* above the features). The canvas shows the
  breakpoint being edited; on the page they are media query rules. `drupalSettings.frameBuilder.breakpoints`,
  `ThemeKit::breakpoints()`, `ComponentFeatures::responsive()`.
- Page builder: **Size** sets width and height each *at most*, *at least* or *exactly*; *Advanced* shows the four that
  are not shown at first, *Simple* hides and clears them, *Reset* clears every size. The format of layouts is
  version 2: `_size.width` and `_size.height` of saved layouts are read as `max_width` and `min_height`.
- Page builder: **content export and import**. Layouts are part of Drupal's content export (`content:export`, the
  `content` folder of a recipe): the layout is exported as a readable document, the media, pages, paragraphs and saved
  blocks it refers to are named by UUID and exported as dependencies of the page, and an import gives them the ids of
  the importing site.
- Page builder: the selected item, the heading above its fields and the Structure tree name the group an item comes
  from: *Fields: Title*, *Layout: Columns*, *Views: …*.
- Page builder: the **Content** panel is left out when the content type has no fields to edit in it.
- **Tests of the page builder.** PHPUnit Kernel and Functional tests for `frame_builder` and `frame_builder_ai`
  (`modules/frame_builder/tests`, run with `tests/run.sh`; the plan of what is covered is `tests/README.md`), and
  Playwright tests of the editor's workflows (`demo/e2e`, `npm run builder:e2e`): build and publish, unsaved work,
  revisions, translations, paragraphs and the library, field groups, moderation, preview.
- Page builder, found by those tests and by trial themes over two design systems:
  - synced translations: a default of a component migration no longer replaces the original's text in a translation;
    a translation that differs from the original only as text ("10" and "10.0") is stored,
  - link suggestions leave out unpublished content the user may not view; the image search and the media library frame
    ask for the right to view media; the media library frame answers 404 without Media Library and 400 without a state,
  - the editor of the original says that its structure is shared as soon as a translation exists,
  - a new page made by someone who may not use the builder stays on the page,
  - a component the theme replaced still renders in layouts that name it, and is known to the editor without being
    offered; a replacement with `noUi: true` hides the base component, as documented,
  - Entity Usage: what a paragraph of a layout uses counts for its page (the plugin of Entity Usage 5 was not found),
  - an image picked with an image style depends on that style (cache tags); a custom image link to content that has no
    page is no link instead of an error; a spacing, size or font size token of `0` is kept,
  - the React component style of Views ignores extra props that are no JSON object; the *Component* display of Views
    has a config schema,
  - the preview marks the chosen width; checkboxes of the editor keep their look under a reset of the theme.
- Page builder AI: the **Size** of an item is applied; a choice given as a number is the option; date tokens are listed
  without the Token module; the media search asks for the right to view media.
- Base theme: wide content no longer widens `.page__columns`.
- Base theme: templates of themes kept inside the folder of Frame (`examples/`) that are not installed are no longer
  used by other themes based on Frame.
- Page builder: an image of a Field block (an image field, a media item) is never wider than the place the block is in.
  Labels in the rail of the editor are a little smaller and wrap, so a long name of a panel stays inside.
- Page builder: an empty required field no longer leaves a save waiting for half a minute. The browser's own check of
  the node form is off, Drupal's messages say what is missing, whatever panel is open and whatever the admin theme.
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
