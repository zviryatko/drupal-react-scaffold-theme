# Architecture

How the page builder is put together: what is stored, how a page is rendered, and how the editor talks to Drupal. Read
[Page builder](/guide/page-builder) first for what it does.

## The idea

- **Drupal renders, the editor only edits.** A page is a tree of [Single Directory Components](/guide/components). The
  published page is built by Drupal from Twig and loads no editor code. The editor ([Puck](https://puckeditor.com), a React
  app) shows the same HTML, which it asks Drupal for, component by component.
- **The theme is the design system.** Components, spacing scale, palette, image styles, breakpoints and which features a
  component has all come from the active theme and its base themes. The module adds no look of its own to the page.
- **Drupal's forms stay Drupal's.** Fields of the page, page settings, menu settings, the media library and formatter
  settings are the real Drupal forms, shown inside the editor. Nothing about them is reimplemented.

## Where things are

```
frame/                         the base theme
├── components/                         layout components: columns, flex, stack, image (Twig + CSS)
└── modules/frame_builder/     the module (Drupal finds it inside the theme folder)
    ├── src/
    │   ├── ThemeKit.php                design tokens and feature defaults of the active theme
    │   ├── ComponentCatalog.php        what the editor can place, and the field of every prop
    │   ├── ComponentFeatures.php       spacing, align, background, position → CSS
    │   ├── LayoutStorage.php           reads and writes the layout of a node, per translation
    │   ├── LayoutMigrator.php          versions of the layout and of its items; migrations
    │   ├── LayoutReferences.php        media and content a layout refers to (for Entity Usage)
    │   ├── Library.php, Entity/SavedBlock.php   the library of saved blocks
    │   ├── Paragraphs.php              paragraph types as blocks (with the Paragraphs module)
    │   ├── LayoutRenderer.php          layout → render arrays
    │   ├── MediaItems.php              picked images: URL, <picture>, link, background sources
    │   ├── FieldDrafts.php             unsaved values of the node form, per user
    │   ├── NodeProperties.php          Publish and Draft
    │   ├── Controller/BuilderController.php   the editor page and its endpoints
    │   ├── Form/BuilderContentForm.php        the node form inside the editor
    │   ├── Form/FormatterSettingsForm.php     settings of a field formatter
    │   ├── Routing/RouteSubscriber.php        makes the builder the edit page
    │   └── Plugin/…                    Views display "Component", Views style "React component (SDC)",
    │                                   field formatter "Responsive image" for media
    ├── modules/frame_builder_ai/   the AI assistant (needs the AI module)
    ├── editor/                         the editor: React sources, built with Vite into js/
    ├── js-static/                      small scripts that run inside the frames
    └── css/
```

## What is stored

One base field on nodes, `frame_layout` (long text, revisionable, translatable), holds the layout as JSON:

```json
{
  "version": 1,
  "root": { "props": {} },
  "content": [
    {
      "type": "frame_demo:section",
      "version": 1,
      "props": {
        "id": "section-1",
        "heading": "Built on [site:name]",
        "variant": "tinted",
        "_spacing": { "padding": { "size": "l", "sides": "vertical" } },
        "content": [
          { "type": "drupal_field:field_media_image", "props": { "id": "f1", "formatter": { "type": "media_thumbnail", "settings": { "image_style": "large" } }, "label": "hidden" } }
        ]
      }
    }
  ]
}
```

- `type` is a component id: an SDC (`<theme>:<name>`), or one of `drupal_field:<field>`, `drupal_view:<view>:<display>`,
  `drupal_block:<block plugin id>`, `drupal_paragraph:<paragraph type>` ([Paragraphs](/builder/paragraphs)),
  `drupal_saved:<uuid>` (a block of the [library](/builder/library)).
- `props` holds the values of the component's props, the content of its slots (a list of items under the slot's name)
  and the values of its [features](/builder/styling#features) under `_spacing`, `_align`, `_background`, `_position`.
- `id` is unique in the layout. Translations refer to it.
- `version` of the document is the layout format (owned by the module, `LayoutMigrator::VERSION`); `version` of an item
  is the schema version of its component when it was saved. `LayoutMigrator` brings both up to date whenever a layout is
  read, see [Versions and migrations](/builder/components#versions-and-migrations).

Because the field is revisionable and translatable, revisions, content moderation and translations cover the layout
without anything extra. Which content types use the builder, and how they translate, are third-party settings of the
content type (`enabled`, `translation_mode`).

### Translations

`LayoutStorage` implements the two modes:

- **Synced**: the original language holds the layout. A translation's field holds only `{"strings": {"<id>.<prop>": value}}`,
  the translatable props it changed (and `versions`, the component version those values were saved for). Reading a translation takes the original's tree and puts those values in. Saving a
  translation walks the *original's* tree and keeps only values of translatable props that differ, so a request cannot
  change the structure.
- **Independent**: every translation holds a whole layout. Drupal copies it when the translation is created.

Text, textarea, rich text and link props are translatable; a prop opts out with `x-builder-translatable: false`.

### What a layout uses

With the [Entity Usage](https://www.drupal.org/project/entity_usage) module installed (tested with 5.0), the module's
tracking plugin *Page builder layout* reports what a layout refers to, per revision and translation of the page: media
picked as an image or a background, media embedded in rich text, content picked as a link, saved blocks the page
links to, and what the paragraphs of Paragraph blocks use. Media then show the
pages that use them on their *Usage* tab, and Entity Usage's delete warnings apply. Nothing needs configuring, unless
the site limits the enabled tracking plugins: enable *Page builder layout* there. For pages that existed before, rebuild
the statistics once (`drush entity-usage:recreate`, or the *Batch update* tab of its settings).

`LayoutReferences` finds the references by walking the stored document, without the components' schemas, so it does not
depend on the active theme.

## Rendering a page

```
node view (full) ──► hook_node_view_alter ──► LayoutStorage::load() ──► LayoutRenderer::build()
                                                                              │
                 one render array per item:  #type => component, #props, #slots (children, recursively)
```

`frame_builder_node_view_alter()` runs for the full view mode of content types that use the builder. When the
page has a layout, it replaces everything else in the build with it. Other view modes are untouched.

For every item `LayoutRenderer` makes a core `#type => component` element. On the way it:

| Value | What happens |
|---|---|
| text props | tokens replaced (`[node:title]`), in the language of the page |
| link props | `entity:node/N` becomes the URL of that page in the page's language; dangerous protocols are stripped |
| rich text props | tokens replaced, then filtered by the stored text format (`check_markup`) |
| image props | resolved by `MediaItems`: URL of the image style, and `<picture>` / srcset / alt for the props the component names |
| `length` props | a step of the theme's scale or a custom length, as a CSS length |
| features | CSS declarations, put on the root element through the `attributes` prop |
| `drupal_field:*` | `$node->get($field)->view()` with the chosen formatter and its settings |
| `drupal_view:*` | a `#type => view` element, only for displays of the type *Component* |
| `drupal_block:*` | the block plugin's `build()`, in the theme's block markup, after its access check |

Cache metadata of everything used (tokens, URLs, media, image styles, blocks) bubbles to the page, so the result is
cached like any other render array.

## The editor

```
/node/N/edit  (admin theme: toolbar, navigation, breadcrumb)
└── iframe  /node/N/builder/frame        (default theme: its CSS and JS, all component libraries)
    ├── Puck: rail │ canvas │ right column
    ├── iframe  /node/N/builder/content?part=fields    node form: Content and Menu panels   (admin theme)
    ├── iframe  /node/N/builder/content?part=page      node form: page settings             (admin theme)
    ├── iframe  /frame-builder/media-library  Drupal's media library, in a dialog  (admin theme)
    └── iframe  /node/N/builder/preview                the page with unsaved changes, in a dialog
```

`RouteSubscriber` points `entity.node.edit_form` at `BuilderController::edit()`, which shows the builder for content
types that use it and the normal form for all others.

**Why frames.** The preview must look and behave as on the site, so the editor runs in a page of the *default* theme with
every component's library attached. Drupal's forms must look and behave as in the admin UI, so they run in pages of the
*admin* theme. A frame per theme keeps their CSS apart.

### The canvas

Components are not React components in the editor. For each item the editor posts its type and props to
`/frame-builder/render` and shows the HTML that comes back, then runs `Drupal.attachBehaviors()` on it, so React
islands mount as on the site.

Slots need care, because the content of a slot is edited by Puck, not rendered by Drupal:

1. Drupal renders the component with an empty marker in each slot: `<div data-frame-builder-slot="content"></div>`.
2. The editor mounts Puck's drop zone into that marker with a React portal.

So inside the editor a slot looks like `…slot container > [data-frame-builder-slot] > [data-puck-dropzone] > items`. Components that
lay their slot out as grid or flex account for that in their CSS, see [Layouts](/builder/layouts#your-own-layout-component).
A component with a slot is not mounted as a React island in the editor, because mounting replaces its children.

Two details that are easy to trip over:

- **Type names.** Puck addresses drop zones as `<item id>:<slot>` and derives the id of a new item from its type. Drupal's
  ids contain colons, so inside Puck types use `/` (`frame_demo/card`); `editor/src/types.js` converts when the
  layout is loaded and saved.
- **Position.** A component that is placed or pinned gets its position on Puck's wrapper of it, through a small style
  sheet, not on the component itself. The wrapper is the box its parent lays out, and it is what Puck selects and drags.

### The node form

`BuilderContentForm` extends `NodeForm` and reports itself as the edit form, so everything modules add to node forms is
there. It is shown twice, as two parts:

| Part | Where | Contains |
|---|---|---|
| `fields` | left: **Content** and **Menu** | the fields of the content type; the menu settings |
| `page` | right, with nothing selected | title, state, and the sidebar of the edit page: URL alias, authoring, promotion, revision log, translation, whatever modules add |

A form-level `#process` callback decides for every element which part it belongs to (the "advanced" group of the edit
page is the page part) and removes the elements of the other part, so they are neither validated nor saved by this form.

The two parts are separate forms that can be changed at the same time. `save()` therefore does not save the node it was
built with: it takes the **latest revision**, puts only its own fields on it, and saves that.

### Unsaved changes

Nothing is written to the node until **Publish** or **Draft**. Two per-user stores (Drupal's private tempstore, which
expires by itself) hold what is unsaved:

- the **layout**, posted by the editor a moment after each change (`…/draft`);
- the **field values** of the node form. A script in the frame presses a hidden "Keep as draft" button in the background
  after each change (and after each Drupal Ajax update, which is how media and multi-value widgets change the form).
  `FieldDrafts` keeps the values; validation errors are dropped for that button, so a half-filled form still previews.

Both are used by the render endpoint and the preview page (the draft values are put on a *copy* of the node), and both
are restored when the editor is opened again.

### Saving

```
Publish / Draft
  1. each changed part of the node form is submitted (Drupal validates; an error stops here)
  2. POST …/save  { data, publish | draft }
       - text formats: a format the user may not use is accepted only for text that already had it
       - new revision from the latest values of every language (storage->createRevision)
       - LayoutStorage::store(); state set for Publish / Draft; node validated and saved
       - the drafts are deleted
```

A save can therefore make up to three revisions (fields, page settings, layout). Drupal's "modified by another user"
check is not applied to the layout save: it compares every translation with the published revision and misfires when
another language was saved in between. There is no other protection against two people editing the same page.

### Endpoints

All need the permission *Use the Frame page builder*; those under `/node/N/` also need the builder enabled for
the content type and update access to the node. POST requests carry Drupal's `X-CSRF-Token` header.

| Path | Purpose |
|---|---|
| `GET /node/N/edit`, `/node/N/builder` | the builder page (admin theme) |
| `GET /node/N/builder/frame` | the editor (default theme) |
| `POST /frame-builder/render` | HTML of one component, for the canvas |
| `POST /frame-builder/node/N/save` | save the layout; `publish` or `draft` set the state |
| `POST /frame-builder/node/N/draft` | keep or discard the unsaved layout |
| `GET /node/N/builder/preview` | the page with the unsaved layout and field values |
| `GET, POST /node/N/builder/content?part=fields\|page` | the node form |
| `GET, POST /node/N/builder/formatter-form` | formatter settings, as an HTMX fragment |
| `GET /node/N/builder/formatter` | the same in a frame, where core has no HTMX |
| `GET /frame-builder/media-library` | Drupal's media library |
| `GET, POST /frame-builder/library` | the library of saved blocks: read, save, update, delete |
| `GET, POST /node/N/builder/paragraph` | the form of the paragraph of a Paragraph block |
| `POST /frame-builder/node/N/ai` | one turn of the AI assistant (module `frame_builder_ai`) |
| `GET /frame-builder/urls`, `/media`, `/tokens` | link autocomplete, image search without Media Library, token tree |

## What is checked on the way out

The layout is data typed by editors, so nothing in it is printed as is:

- Text is escaped by Twig; rich text goes through its text format.
- Links: `javascript:` and other dangerous protocols are removed.
- Lengths are a token of the theme or match `number + unit`; colours are a name of the theme's palette; alignment and
  position are one of a fixed list. Anything else is dropped.
- Image URLs in CSS are escaped for `url("")`.
- A view is rendered only if its display is of the type *Component* and the user may see it; a block only after its
  own access check.

## The editor's sources

`modules/frame_builder/editor/src/`:

| File | What |
|---|---|
| `main.jsx` | the Puck configuration, the canvas (`ServerComponent`), header actions, save and drafts |
| `fields.jsx`, `featureFields.jsx` | the fields of the right column |
| `BlocksPanel.jsx`, `fuzzy.js`, `icons.jsx` | the list of blocks, its search and icons |
| `library.js` | the saved blocks the editor knows, and conversion of their items |
| `AiPanel.jsx` | the chat of the AI assistant |
| `contentFrame.jsx` | the frames of the node form |
| `History.jsx`, `history.js` | the history timeline |
| `TokenInput.jsx`, `TokenBrowser.jsx`, `tokens.js`, `ckeditorTokens.js` | tokens: autocomplete and browser |
| `types.js` | conversion of component ids |
| `frameTokens.js` | token autocomplete for rich text fields of the node form (a bundle of its own) |

`npm run builder:build` builds them into `js/editor.js`, `js/editor.css` and `js/frame-tokens.js`. Unit tests of the pure
parts (`types`, `tokens`, `fuzzy`, `history`) run with the base theme's `npm test`.
