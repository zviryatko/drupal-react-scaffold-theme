# Paragraphs

With the [Paragraphs](https://www.drupal.org/project/paragraphs) module, every paragraph type is a block of the
builder. Use it for content that is better edited as fields in a Drupal form than as props of a component: structured
content with its own field types, widgets, validation and templates.

Nothing needs enabling: with Paragraphs (and Entity Reference Revisions, which it requires) installed, the Blocks panel
has a **Paragraphs** group with one block per paragraph type.

## In the editor

Drag a paragraph type onto the page and select it. The right column shows **the paragraph's own form**: its form
display, with every widget (rich text, media, references…), and under it the layout options every block has (spacing,
alignment, background, position).

The form has no button. What you type is kept in the background, per user, and the canvas shows the paragraph with it
a moment later. The paragraph itself is created, or changed, when you **Publish** or save a **Draft**, together with
the rest of the page. Until then it is an unsaved change like any other: a reload brings it back, **Preview** shows
it, and discarding the unsaved changes drops it.

The paragraph is rendered by Drupal with its *default* view display and your theme's paragraph templates.

## What is stored

The content is in the paragraph, not in the layout. The layout says which paragraph, in which revision, and holds the
layout options:

```json
{ "type": "drupal_paragraph:text_block", "version": 1,
  "props": { "id": "…", "paragraph": { "id": 12, "vid": 57, "uuid": "6d9b…" }, "_spacing": { "padding": { "size": "m" } } } }
```

- **Saving the page saves the paragraph.** A paragraph that was changed in its form gets a new revision (a new block:
  a new paragraph) when the page is saved, and the layout names that revision (`vid`). The values are validated then;
  an invalid value stops the save with its message. Older revisions of the page keep showing the paragraph as it was.
- **Unsaved values** are kept by `ParagraphDrafts` (Drupal's private tempstore), by page, language and block. Only the
  editor and its preview show them.
- **The paragraphs belong to the page.** The page lists them in an Entity Reference Revisions field,
  `react_scaffold_paragraphs`, filled from the layout on every save, and each paragraph has the page as its parent.
  That is the relation Paragraphs expects: the paragraphs are deleted with the page and are not seen as orphans.
  The field is added to nodes when the modules are present (also when they are installed later; existing sites:
  `drush updb`).
- **Translations.** A translation names its own revision. In the *synced* mode the structure is shared and the
  paragraph reference is one of the things a translation may change, so a translator edits the form in their language
  and gets their own revision. When the paragraph type is translatable (Content Translation), that revision holds the
  translation of the paragraph; otherwise it is simply another revision with the translated text.
- **Entity Usage** does not list paragraphs themselves. What a paragraph uses (media, references) counts for the page
  it is on.

## Reusing a paragraph

Save the Paragraph block, or a component that contains it, to the [library](/builder/library). Pages that link to the
saved block show the same paragraph, in the revision the saved block names; updating the saved block updates them all.
The Library box is under the form of the paragraph, above *Layout and style*: with *Update the saved block*
it takes a revision log message. The saved block has a paragraph of its own, so it can be saved to the library before
the page was ever saved, and a copy on a page becomes the page's own paragraph when the page is saved.

With the **Paragraphs Library** module there is a second way: the block **From library** shows an item of
*Content → Paragraphs*, maintained outside the builder and usable in paragraph fields of other content types too.
The [recipe](/builder/recipes) `react_scaffold_builder_paragraphs` installs it, with Entity Usage and a starter
paragraph type.

## Limits

- Nested paragraphs work as far as the paragraph's own form supports them; the builder sees one paragraph.
- A Paragraph block whose form was never filled in is empty: the editor shows a placeholder, the page nothing.
- Undo and the history of the editor do not take back what was typed in the form; use the undo of the field you are
  in. Discarding the unsaved changes of the page drops it all.
- Duplicating a Paragraph block gives a second block that names the same paragraph until one of them is changed and
  the page saved.
- The form is shown in the page's language. Tokens are not replaced in paragraph fields.
