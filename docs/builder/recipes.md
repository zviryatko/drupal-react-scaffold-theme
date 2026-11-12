# Recipes

The base theme ships [Drupal recipes](https://www.drupal.org/docs/extending-drupal/drupal-recipes) that set a site up
for the page builder in one command each: the modules, their configuration, the permissions of the Content editor
role. They are in `recipes/` of the theme and are applied by path (Drupal 11).

| Recipe | Installs | Sets up |
|---|---|---|
| `frame_builder` | the base theme, Frame Builder, Media Library, Breakpoint, Responsive Image, Menu UI, Path, CKEditor | the **Landing page** content type, the Image media type, image styles and the responsive image styles *Narrow*, *Wide* and *Hero (per breakpoint)*, the Basic HTML text format, the default theme, the **Content editor** role |
| `frame_builder_paragraphs` | Paragraphs, Entity Reference Revisions, Paragraphs Library, Entity Usage | the paragraph types **Text block** (a starter) and **From library** (made by the builder for pages that use a paragraph of the library), a *Usage* tab on media, content and library items |
| `frame_builder_field_group` | Field Group, Field UI | nothing: group the fields on *Manage form display* of a content type, each top-level group is a panel of the editor |
| `frame_builder_seo` | Metatag, Metatag: Open Graph, Token | a **Meta tags** field on Landing page, in the page settings of the builder |
| `frame_builder_ai` | AI, Frame Builder AI | the permission for the assistant |
| `frame_builder_full` | all of the above | |

Each of the last five applies `frame_builder` first, so one command is enough. A recipe never replaces
configuration that exists: applying it to a site that has some of it adds the rest, and applying it twice changes nothing.

## Apply

Recipes install modules, they do not download them. Get the code first:

```bash
# for frame_builder: nothing, it uses core and the theme
composer require drupal/paragraphs drupal/entity_usage      # ..._paragraphs
composer require drupal/field_group                         # ..._field_group
composer require drupal/metatag                             # ..._seo
composer require drupal/ai                                  # ..._ai, plus a provider, see below
```

Then, from the Drupal root (`web/`), with your subtheme installed:

```bash
drush theme:install my_theme
drush recipe themes/contrib/frame/recipes/frame_builder_full --input=frame_builder.theme=my_theme
drush cr
```

Without drush: `php core/scripts/drupal recipe themes/contrib/frame/recipes/frame_builder_full`.

### The `theme` input

The builder works only when the default theme of the site is Frame or a subtheme of it. The recipe makes the
theme you name the default one:

| | |
|---|---|
| `--input=frame_builder.theme=my_theme` | your subtheme, installed before (`drush theme:install my_theme`) |
| `--input=frame_builder.theme=frame` | the base theme itself, to look around without a subtheme |
| no input | the default theme of the site is kept. Right when it is already your subtheme |

## What you get

### Media, breakpoints and responsive images

- The **Image** media type and the media library: image props and backgrounds of components pick from it.
- Image styles of 325, 650, 1300 and 2600 pixels (core's *Standard responsive images* recipe) with the responsive image
  styles **Narrow** and **Wide**, which let the browser choose by the width of the image (`sizes`).
- **Hero (per breakpoint)** (`frame_hero`): one image style per breakpoint of the base theme, mobile, tablet
  and desktop (`frame.breakpoints.yml`). Use this kind for backgrounds: a background image gets one
  `@media` rule per breakpoint. See [Images](/builder/styling#images).

A subtheme without its own `<theme>.breakpoints.yml` uses the base theme's breakpoints, in the preview of the builder
and [in SCSS](/builder/styling#tokens-in-scss). To offer only some image styles to editors, list them in the theme's
`image_styles` [token](/builder/styling#tokens).

### Revisions

Landing pages create a new revision on every save, and the role can view and revert them. Paragraphs are stored with
Entity Reference Revisions: a layout names the revision of each paragraph it shows, so an old revision of a page shows
its paragraphs as they were. See [Paragraphs](/builder/paragraphs#what-is-stored).

### Reused paragraphs

A Paragraph block is saved to the library from its **Library** box. With this recipe the library is the one of the
**Paragraphs Library** module (*Content → Paragraphs*), so the same paragraphs can be used in fields of other content
types; see [Reusing a paragraph](/builder/paragraphs#reusing-a-paragraph).

**Text block** is a starter type with a title and a text; add your own under *Structure → Paragraph types*, each becomes
a block.

### Field groups

Nothing is grouped for you: Landing page has few fields. On a content type with many, put them into groups on
*Structure → Content types → Manage form display*; every top-level group becomes a panel of the editor with its own
button, and the settings of a group have its icon. See [Field groups](/guide/page-builder#field-groups).

### Meta tags

The field is in the page settings (nothing selected in the editor). With the assistant, "fill the meta tags" writes
them from the page. See [AI assistant](/builder/ai).

### AI assistant

The recipe cannot choose a provider for you. After it:

```bash
composer require drupal/ai_provider_anthropic     # or ai_provider_openai, ai_provider_ollama...
drush en ai_provider_anthropic
```

then add the key (*Configuration → AI → Provider settings*) and choose the default model for **Chat**
(*Configuration → AI → AI settings*). Until then the AI panel says what is missing.

### The Content editor role

Created by core's recipe if the site has none, and given: the builder, creating and editing landing pages and their
revisions, media, URL aliases, the Basic HTML format, and with the other recipes the paragraphs library, usage
statistics and the assistant. The assistant makes paid requests to your provider: take *Use the AI assistant of the
page builder* away from the role if not every editor should have it.

## Not in the recipes

- Your subtheme: create it with `frame new`, see [Build a site](/guide/build-a-site).
- Content moderation: the builder saves with **Publish** and **Draft** and does not show workflow states.
- A provider for the AI module, and translation modules. For translations enable Content Translation and choose
  *Synced* or *Independent* on the content type, see [Translations](/guide/page-builder#translations).

## Write your own

A site recipe can build on these, for example to add your paragraph types:

```yaml
# recipes/my_site/recipe.yml
name: 'My site'
type: 'Site'
recipes:
  - ../web/themes/contrib/frame/recipes/frame_builder_paragraphs
install:
  - my_theme
config:
  strict: false
  actions:
    system.theme:
      simpleConfigUpdate:
        default: my_theme
```

Put the configuration of your paragraph types and fields into the recipe's `config/` folder (export it from a site
where you built them).
