# Library of saved blocks

A component with everything in it (a section with its cards, a call to action, a footer strip) can be saved to the
library and used on other pages. The same mechanism serves as a component library: save one configured component, and
it is one drag away everywhere.

## In the editor

Every component has a **Library** section at the end of the right column.

| You have | You can |
|---|---|
| an ordinary component | **Save to library as…**: give it a name. It is saved with everything in it, and here it becomes a *saved block* |
| a **saved block** (it is in the **Library** group of the Blocks panel, drag it in like any block) | **Edit a copy here**: replace it with a copy you can change on this page. **Delete from library** |
| a **copy** of a saved block | **Update the saved block**: what is here becomes the saved block, on every page. **Use the saved block again**: drop the changes |

The two ways a page can use a saved block:

- **Linked.** The page has the saved block, not its content. It shows the block *as it is saved now*: update the saved
  block and every page that links to it changes, without being saved again. On the canvas a linked block is one item;
  what is in it cannot be selected.
- **A copy.** Made with *Edit a copy here*. The items are the page's own: it no longer follows the saved block. The
  first item remembers where it came from, and its Library section says "a copy of … changed on this page".

So to change a saved block: make a copy where you see it, change it, *Update the saved block*. The copy becomes a link
again.

Spacing, alignment, background and position of a linked block are set where it is placed, per page; what is inside is
the same everywhere.

All saved blocks are listed under *Content → Page builder library* (`/admin/content/page-builder-library`): what
component each one is, how many blocks are in it, when it changed, with **Edit** (rename) and **Delete**. New ones are
made in the builder: build the block on any page, then *Save to library*.

Deleting a saved block does not touch copies. Pages that link to it show nothing in its place, and the editor says so.

## How it is stored

A saved block is a content entity, `react_scaffold_saved` (class `SavedBlock`): a name and, in the same field and
format as the layout of a page, a document with one item. `Library` (the service `react_scaffold_builder.library`)
reads and writes them.

In a layout:

```json
{ "type": "drupal_saved:0b6f…-uuid", "version": 1, "props": { "id": "…", "_spacing": { "margin": { "size": "l" } } } }
```

```json
{ "type": "my_theme:section", "version": 2,
  "props": { "id": "…", "heading": "…", "content": [ … ], "_library": { "uuid": "0b6f…", "label": "Promo", "overridden": true } } }
```

The first is a link, the second a copy.

- A saved block is rendered by `LayoutRenderer` like the rest of the page, in the context of the page it is on: tokens
  such as `[node:title]` and Field blocks in it show that page's values.
- Its cache tag is on every page that links to it, so an update is visible at once.
- [Versions and migrations](/builder/components#versions-and-migrations) apply to saved blocks as to pages.
- A saved block may contain other saved blocks; one that would contain itself is shown once.
- With [Entity Usage](/builder/architecture#what-a-layout-uses), pages are listed as using the saved blocks they link
  to, and a saved block as using its media and links.

Saved blocks are not translated: a linked block shows the same text in every language (tokens and Field blocks follow
the page). Where text must differ per language, use a copy. Everyone with the builder permission can save, update and
delete saved blocks. Existing sites get the entity type with `drush updb`.
