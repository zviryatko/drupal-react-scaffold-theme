# Page builder

The base theme ships a module, **React Scaffold Builder** (`modules/react_scaffold_builder`), that turns the components
of your theme into a visual page builder. Editors place components, fields, views and blocks on a page, and Drupal renders
the result as normal Single Directory Components: the published page loads no editor code.

The module lives inside the theme folder, and Drupal finds it there. It works only when the default theme is React Scaffold
or one of its subthemes (the status report says so otherwise). It was developed and tested on Drupal 11.4.

<Video src="/media/page-builder-ai.mp4" poster="/media/screenshots/page-builder-ai.png" />

| Read | For |
|---|---|
| this page | switching it on, and a tour of the editor |
| [Gallery](/builder/gallery) | every feature in a screenshot |
| [Build a site](/guide/build-a-site) | the whole way, from an empty Drupal to a page |
| [Recipes](/builder/recipes) | setting a site up in one command: media, responsive images, paragraphs, meta tags, AI |
| [Components](/builder/components) | making your components work in the builder: props, slots, images, icons |
| [Views](/builder/views) | offering views, and drawing a view with an interactive component |
| [Tokens, features and styling](/builder/styling) | the theme's spacing scale, palette and image styles; spacing, alignment, background |
| [Components of the base theme](/builder/base-components) | the ready set: hero, heading, text, button, card, quote, accordion, call to action… |
| [Layouts](/builder/layouts) | Columns, Flex, Stack, position, and writing a layout component |
| [Library of saved blocks](/builder/library) | saving a block and using it on other pages, linked or as a copy |
| [Paragraphs](/builder/paragraphs) | paragraph types as blocks, edited with their own form |
| [AI assistant](/builder/ai) | composing a page in a chat, with the AI module |
| [Architecture](/builder/architecture) | what is stored, how a page is rendered, how the editor works |

## Enable it

```bash
drush en react_scaffold_builder
```

Or apply a [recipe](/builder/recipes), which also sets up media, responsive images and a role for editors, and
optionally paragraphs, meta tags and the AI assistant:

```bash
drush recipe themes/contrib/react_scaffold/recipes/react_scaffold_builder --input=react_scaffold_builder.theme=my_theme
```

A release archive of the theme has the compiled editor. From a git checkout, build it once:

```bash
cd web/themes/contrib/react_scaffold
npm run builder:install && npm run builder:build
```

Then give editors the permission **Use the React Scaffold page builder**, and switch the builder on for a content type:
*Structure → Content types → (type) → Page builder*. The module adds a ready content type, **Landing page**.

For such a content type:

- **Edit is the builder.** `/node/N/edit` opens it; other content types keep the normal form. A new page is created with
  the normal add form (it needs a title to exist) and then opens in the builder.
- **The full page is the layout.** Once a page has a layout, its full view shows only that layout. Fields appear where an
  editor places them. Teasers and other view modes keep their display.

## The editor

| Where | What |
|---|---|
| Left: **Content**, **Menu** | The fields of the page and its menu settings. This is Drupal's node form, with every widget. |
| Left: **Blocks** | What can be placed: your components by group, **Fields** of the page, **Views**, Drupal **Blocks**. The search at the top forgives typos. |
| Left: **Structure** | The tree of the layout. |
| Left: **AI** | With the module *React Scaffold Builder AI*: a chat that composes the page from your components. See [AI assistant](/builder/ai). |
| Right | The selected component: its fields, then **Layout and style** (spacing, alignment, background, position). With nothing selected: the **page settings** (title, state, URL alias, authoring…), also Drupal's node form. |
| Top | Undo, redo and **History** (a timeline of the changes since the page was opened or saved; click a step to go to it), **Preview** (or **View** when nothing is unsaved), **Publish**, **Draft**, **Delete**. |

Links inside the editor's forms and inside the preview (help links of a field, links of the page) open in a new tab,
so the editor keeps its place.

While you edit, nothing is saved to the page. Changes to the layout and to the forms are kept per user in the background,
so the canvas follows what you type, a reload brings everything back, and **Preview** shows the page with them at the
widths of the theme's breakpoints. **Publish** and **Draft** save.

To drop next to a component that has a slot of its own, aim at the gap between items or at the strip at the top or bottom
of the container; a drop on the component goes into it.

## What can be placed

- **Components** of your theme and its base themes: each prop is a field, each slot takes other components. Text fields
  take tokens (`[node:title]`); typing `[` suggests them. See [Components](/builder/components).
- **The base theme's set**: Hero, Section, Container, Heading, Text, Image, Button, Card, Quote, Accordion, Call to
  action and more. See [Components of the base theme](/builder/base-components) and [Layouts](/builder/layouts).
- **Fields** of the page, one item per field, with a formatter. A new Field block starts with the format the content
  type uses in its default display (a media reference shows the image, not the name of the media item). A formatter's settings open under it and apply as you
  change them. Tokens typed into a field value are replaced too.
- **Views** that have a display of the type *Component*, drawn by Views or by an interactive component. See
  [Views](/builder/views).
- **Blocks** of Drupal that need no context, with an optional title.
- **Paragraphs**, with the Paragraphs module: one block per paragraph type, edited with the paragraph's own form.
  See [Paragraphs](/builder/paragraphs).
- **Saved blocks** from the library: anything you saved there, shown as it is saved now.
  See [Library](/builder/library).

Every one of them has the theme's [features](/builder/styling#features): spacing, alignment, background, position.

## Translations

Set per content type, next to the checkbox that enables the builder:

- **Synced**: one structure for all languages. A translation can change only text and links; everything else follows the
  original. Links picked from site content already point to the translation of the page's language.
- **Independent**: a translation starts as a copy of the original, made when it is created, and is then changed freely.

The editor says which applies in its title bar. With the [AI assistant](/builder/ai), a translation can be translated
by asking for it.

## Limits

- Interactive (React) components that have a slot are shown without their behaviour in the editor, so their slot stays editable.
- The canvas of the editor does not change width; use **Preview** to see the page at a breakpoint.
- Two people editing the same page are not warned about each other: the last save wins.
- Views are placed without contextual filters. Blocks that need a context, and settings forms of blocks, are not supported.
- History covers the layout, not the node form, and starts anew when the editor is reloaded.
- Without core's HTMX (before Drupal 11.2) formatter settings open in a dialog with an Apply button.

## Develop the editor

The editor is a React app in `modules/react_scaffold_builder/editor`, built with Vite into `modules/react_scaffold_builder/js`
(not committed). [Architecture](/builder/architecture#the-editor-s-sources) lists its sources.

| Command (in the base theme folder) | Does |
|---|---|
| `npm run builder:install` | installs the editor's dependencies |
| `npm run builder:build` / `builder:watch` | builds the editor |
| `npm test` | includes the editor's unit tests |
| `BASE_URL=… npm run builder:smoke` | browser smoke test against a site with the module enabled (Playwright, see `demo/`) |
