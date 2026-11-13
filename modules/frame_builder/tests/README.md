# Tests of the page builder

Three layers, each for what it is good at:

| Layer | Where | Runs | For |
|---|---|---|---|
| Kernel (PHPUnit) | `tests/src/Kernel` | no web server | the services: storage, migration, rendering, catalog, drafts, paragraphs, library |
| Functional (PHPUnit, `BrowserTestBase`) | `tests/src/Functional` | a served site, no JavaScript | routes, access, the JSON endpoints, the node form parts, revisions, translations, moderation |
| End to end (Playwright) | `demo/e2e` of the theme | a served site with the editor built | what an editor does in the browser: build, save, restore, translate, revert, paragraphs, library |
| Unit (Jest) | `editor/src/__tests__` | Node | pure functions of the editor: types, history, tokens, search, icons |

## Run

On a Drupal 11 site with the theme in `web/themes/contrib/frame` and `drupal/core-dev`:

```bash
(cd web && php -S 127.0.0.1:8080 .ht.router.php) &      # for the functional tests
web/themes/contrib/frame/modules/frame_builder/tests/run.sh                         # everything
web/themes/contrib/frame/modules/frame_builder/tests/run.sh --group frame_builder   # by group
web/themes/contrib/frame/modules/frame_builder/tests/run.sh path/to/OneTest.php
```

The tests of the AI assistant are in `modules/frame_builder_ai/tests`; `run.sh` without arguments runs only this
folder, so name both to run everything:

```bash
T=web/themes/contrib/frame/modules/frame_builder
$T/tests/run.sh $T/tests/src $T/modules/frame_builder_ai/tests/src
```

End to end, with the editor built (`npm run builder:build`) and the browsers of Playwright installed in `demo/`:

```bash
demo/e2e/setup.sh --install                              # EMPTIES the site, then prepares it; without --install it only prepares
BASE_URL=http://127.0.0.1:8080 npm run builder:e2e       # all specs, or: npm run builder:e2e -- translate
```

`setup.sh` runs `vendor/bin/drush` from the project root; set `DRUSH` to run it elsewhere (`DRUSH="docker exec ..."`).
Tests marked `fixme` show known quirks of the editor; `E2E_KNOWN_BUGS=1` runs them.

Tests of integrations need the module they integrate with and are skipped without it: `paragraphs` (with
`paragraphs_library`), `entity_usage`, `field_group`, `metatag`, `token`, `ai`.

The tests use the theme `tests/themes/frame_builder_test_theme`, a subtheme of Frame with known tokens and components,
the `landing` content type (builder on) and the helpers of `BuilderTestTrait`.

## Coverage plan

What the module does that Drupal does not, and the test that covers it. Every row has its test. Known gaps are listed at the end.

### Layout: storage, versions, references (Kernel)

| What | Test |
|---|---|
| store and load on a revision; nothing stored stays nothing; enabled per type and theme | `LayoutStorageTest` |
| translations, synced: structure of the original, texts of the translation, strings diff, renamed props follow | `LayoutTranslationSyncedTest` |
| translations, independent: a copy that changes freely | `LayoutTranslationIndependentTest` |
| preview layout for a request (`setPreview`) | `LayoutStorageTest` |
| component versions and migration steps (rename, remove, default, move), document version, unknown components kept | `LayoutMigratorTest` |
| media and links a layout refers to | `LayoutReferencesTest` |
| content export and import: references by UUID, dependencies, ids of the importing site, Paragraph blocks | `ContentExportTest`, `ContentExportParagraphsTest` |

### Components and design tokens (Kernel)

| What | Test |
|---|---|
| SDC props to editor fields (text, html, number, boolean, enum, image, link, icon...), slots, defaults, groups, replaced components | `ComponentCatalogTest` |
| field, view, block, paragraph and saved block components of a page | `ComponentCatalogSourcesTest` |
| how a schema is read: unions, fixed values, formats, multi-choice, limits checked on save, depth | `SchemaTest` |
| lists and groups (props of the types array and object): fields, rendering of each value, formatted text found, synced translations | `ListPropsTest` |
| tokens of the theme merged over base themes; lengths, colours, sizes, image styles; unsafe values refused | `ThemeKitTest` |
| features (spacing, size, align, text, background, position) to CSS; per component switches | `ComponentFeaturesTest` |

### Rendering (Kernel)

| What | Test |
|---|---|
| layout to render arrays: SDC with props and slots, nested, missing components print nothing | `LayoutRendererTest` |
| Field blocks: formatter, settings, label position, access | `LayoutRendererFieldTest` |
| Drupal blocks and views; cache metadata | `LayoutRendererBlockViewTest` |
| tokens in text and links, formatted text and its format, URLs | `LayoutRendererValuesTest` |
| image media: description, resolve, link, background sources; responsive formatter | `MediaItemsTest`, `MediaResponsiveImageFormatterTest` |
| full view shows the layout only when there is one (`hook_node_view_alter`) | `NodeViewTest` |

### Unsaved work (Kernel + Functional)

| What | Test |
|---|---|
| field drafts per user and node: merge, apply, forget | `FieldDraftsTest` |
| paragraph drafts per block | `ParagraphDraftsTest` |
| layout draft endpoint: keep, restore, stale after another save, discard | `DraftEndpointTest` |
| preview page shows unsaved layout and field values | `PreviewTest` |

### Saving, revisions, publishing (Functional)

| What | Test |
|---|---|
| access: permission, node access, type not enabled, theme not supported; edit route swap | `EditorAccessTest` |
| editor settings given to the script | `EditorSettingsTest` |
| save endpoint: new revision each time, publish and draft, validation of input, CSRF | `SaveEndpointTest` |
| revisions: revert brings a layout back; latest revision is edited | `RevisionTest` |
| content moderation: Publish and Draft follow the workflow | `ModerationTest`, `NodePropertiesTest` (Kernel) |
| translations through the endpoints: synced locked structure, independent copy | `TranslationEndpointTest` |
| render endpoint for the canvas; urls, media, tokens endpoints | `RenderEndpointTest`, `SuggestionEndpointsTest` |

### The node form in the editor (Functional)

| What | Test |
|---|---|
| two parts, each saves its own fields on the latest revision | `ContentFormTest` |
| autosave as a draft without validation; restored on reload | `ContentFormDraftTest` |
| Field Group: panels, nested groups, tabs, sidebar groups, the icon setting | `ContentFormFieldGroupTest`, `FieldGroupSectionsTest` (Kernel) |
| formatter settings form of a Field block | `FormatterSettingsFormTest` |
| content type setting; new page continues in the builder; requirements; install and update | `NodeTypeSettingsTest`, `InstallTest` |

### Paragraphs and the library (Kernel + Functional)

| What | Test |
|---|---|
| paragraph types as blocks; load, display, drafts shown | `ParagraphsTest` |
| persist on save: new, changed, removed paragraphs; revisions; ownership; errors | `ParagraphsPersistTest` |
| paragraph form in the editor: autosave, save | `ParagraphFormTest` |
| Paragraphs Library: save to, use from, wrapper | `ParagraphsLibraryTest` |
| saved blocks: create, linked and copied use, log, list page | `LibraryTest`, `LibraryEndpointTest` |
| Entity Usage of layouts | `EntityUsageTest` |

### Views (Kernel)

| What | Test |
|---|---|
| "Component" display offered to the builder; React component style with a REST display | `ViewsPluginsTest` |

### AI assistant, module `frame_builder_ai` (Kernel + Functional)

| What | Test |
|---|---|
| a proposed layout is made valid: unknown components, props, enums, lengths, images, html, paragraphs, depth | `LayoutSanitizerTest` |
| parsing answers; only texts on a locked translation; instructions; tools (tokens, media, content search) | `PageAssistantTest` |
| meta tags offered, read and proposed | `MetaTagsTest` |
| chat endpoint: access, input, a scripted provider | `AssistantEndpointTest` |

### In the browser (Playwright, `demo/e2e`)

| Workflow | Spec |
|---|---|
| new page continues in the builder; add components; edit props; publish; the page shows it | `build.spec.mjs` |
| unsaved work: reload restores layout and fields; discard; history undo and redo | `restore.spec.mjs` |
| revisions: save twice, revert, the editor opens the reverted layout | `revisions.spec.mjs` |
| translations: synced (structure locked, texts translated) and independent | `translate.spec.mjs` |
| paragraphs: add a Paragraph block, fill its form, publish; library | `paragraphs.spec.mjs` |
| field groups: panels, save across panels, required field in another panel | `field-groups.spec.mjs` |
| content moderation: Draft and Publish | `moderation.spec.mjs` |
| preview at breakpoints | `preview.spec.mjs` |
| layout and style per breakpoint; the six choices of Size | `breakpoints.spec.mjs` |
| lists: add, fill in, move and remove the values of a list prop | `lists.spec.mjs` |
| buttons in text fields: insert a token where the caret was, clear | `field-buttons.spec.mjs` |
| the help of a component; link suggestions chosen with the keyboard | `help-and-links.spec.mjs` |
| Structure: several selected, put into a layout, moved, deleted; Undo | `structure.spec.mjs` |

## Known gaps

- Update hooks `frame_builder_update_11001`–`11003`, `hook_frame_builder_editor_settings_alter`, the 422 answer of the
  render endpoint.
- In the browser: image and media library fields, link suggestions, the token browser, formatter settings, reordering
  by drag, the notice of a draft made stale by another user, the Menu panel, the AI panel.
- One test is skipped on purpose (`ParagraphsPersistTest::testThePageListsTheRevisionsOfTheLayout`): saving a page as
  a new revision gives each of its paragraphs a new revision too (Entity Reference Revisions does), so the field of the
  page names a newer revision than the layout. Pages render the revision the layout names.
- Two browser tests are `fixme`: labels of the right column point at a hidden copy of the field (Puck renders the
  fields twice with the same ids), and undo pressed within a quarter of a second of a change goes one step too far.
