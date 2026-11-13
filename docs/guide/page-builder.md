# Page builder

The base theme ships a module, **Frame Builder** (`modules/frame_builder`), that turns the components
of your theme into a visual page builder. Editors place components, fields, views and blocks on a page, and Drupal renders
the result as normal Single Directory Components: the published page loads no editor code.

The module lives inside the theme folder, and Drupal finds it there. It works only when the default theme is Frame
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
drush en frame_builder
```

Or apply a [recipe](/builder/recipes), which also sets up media, responsive images and a role for editors, and
optionally paragraphs, meta tags and the AI assistant:

```bash
drush recipe themes/contrib/frame/recipes/frame_builder --input=frame_builder.theme=my_theme
```

A release archive of the theme has the compiled editor. From a git checkout, build it once:

```bash
cd web/themes/contrib/frame
npm run builder:install && npm run builder:build
```

Then give editors the permission **Use the Frame page builder**, and switch the builder on for a content type:
*Structure → Content types → (type) → Page builder*. The module adds a ready content type, **Landing page**.

For such a content type:

- **Edit is the builder.** `/node/N/edit` opens it; other content types keep the normal form. A new page is created with
  the normal add form (it needs a title to exist) and then opens in the builder.
- **The full page is the layout.** Once a page has a layout, its full view shows only that layout. Fields appear where an
  editor places them. Teasers and other view modes keep their display.

## The editor

| Where | What |
|---|---|
| Left: **Content**, **Menu** | The fields of the page and its menu settings. This is Drupal's node form, with every widget. A content type without fields of its own (Landing page: its title is in the page settings) has no Content panel. |
| Left: your **field groups** | With the [Field Group](https://www.drupal.org/project/field_group) module, each top-level group of the form is a panel of its own. See [Field groups](#field-groups). |
| Left: **Blocks** | What can be placed: your components by group, **Fields** of the page, **Views**, Drupal **Blocks**. The search at the top forgives typos. |
| Left: **Structure** | The tree of the layout, where several components can be selected and moved, deleted or put into a layout together. See [Structure](#structure). |
| Left: **AI** | With the module *Frame Builder AI*: a chat that composes the page from your components. See [AI assistant](/builder/ai). |
| Right | The selected component: its fields, then **Layout and style** (spacing, alignment, background, position). With nothing selected: the **page settings** (title, state, URL alias, authoring…), also Drupal's node form. |
| Top | Undo, redo and **History** (a timeline of the changes since the page was opened or saved; click a step to go to it), **Preview** (or **View** when nothing is unsaved), **Publish**, **Draft**, **Delete**. |

### Structure

The **Structure** panel shows the components of the page as a tree. A click selects one, as a click on the canvas does.

The field **Search the page** at its top finds a component by its name or its text: as you type, the first one found is
opened in the tree, selected and scrolled to on the canvas. Enter or down goes to the next one, up to the one before,
Escape empties the field.

To work on several at once:

| To | Do |
|---|---|
| add one to the selection, or take it away | Ctrl + click (Cmd + click on a Mac) |
| select all from the last one clicked | Shift + click |
| go through the tree | up and down; with Shift the selection grows |
| put them into a new layout component (Columns, Stack, Section…) | **+** in the panel's title and the component: it takes the place of the first one, and they are in its first slot, in their order |
| move them together | drag one of them, in the tree or on the canvas: the others follow it; or the arrows next to **Collapse all** in the panel's title, or Alt + up and down |
| delete them | the bin next to **Collapse all**, the Delete key, or **Delete *n*** in the header |
| select one again | a click on a component without a key, in the panel or on the canvas; Escape |

Each of these is one step of **Undo**. The selected components are outlined on the canvas. On a translation that shares
its structure, components can be selected but not moved, put together or deleted.

### Field groups

A content type with many fields is easier to edit in parts. Install
[Field Group](https://www.drupal.org/project/field_group) (the recipe [`frame_builder_field_group`](/builder/recipes) does) and group the fields on *Manage form display* of the content
type: the editor shows every **top-level group** as a panel, with a button in the rail on the left, after **Content**.

<Video src="/media/page-builder-field-groups.mp4" poster="/media/builder/field-groups.png" />

The recipes of the Umami demo, with the fields of the form in four groups: *Ingredients*, *Method*, *Time and servings*
and *Categories*. The canvas follows what is typed in any of them, and **Publish** saves them all.

- Fields in no group stay in **Content**. When every field is in a group, there is no Content panel.
- The format of a top-level group does not matter (details, fieldset, HTML element): the panel replaces it. Of a
  top-level **Tabs** group, each tab is a panel.
- Groups inside a group keep their format, inside the panel.
- A **Details sidebar** group is where the sidebar of the edit page is: in the page settings on the right.
- A group with nothing to show (no fields, or none the user may edit) has no panel.

The button of a panel shows the initials of its label. To give it an icon, open the settings of the group (the cog on
*Manage form display*) and fill **Icon in the page builder**: a name of the
[editor's set](/builder/components#the-icon) (`search`, `image`, `list`, `link`…) or the CSS classes of an icon font.

<div class="shots">
<Shot src="/media/builder/field-groups.png" title="A group is a panel">The <em>Ingredients</em> group of the recipe form, with the icon <code>list</code>.</Shot>
<Shot src="/media/builder/field-groups-initials.png" title="Without an icon">The button of <em>Time and servings</em> shows initials.</Shot>
<Shot src="/media/builder/field-group-icon.png" title="The icon of a group">The settings of a group on <em>Manage form display</em>.</Shot>
</div>

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

The editor is a React app in `modules/frame_builder/editor`, built with Vite into `modules/frame_builder/js`
(not committed). [Architecture](/builder/architecture#the-editor-s-sources) lists its sources.

| Command (in the base theme folder) | Does |
|---|---|
| `npm run builder:install` | installs the editor's dependencies |
| `npm run builder:build` / `builder:watch` | builds the editor |
| `npm test` | includes the editor's unit tests |
| `BASE_URL=… npm run builder:smoke` | browser smoke test against a site with the module enabled (Playwright, see `demo/`) |
