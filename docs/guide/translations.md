# Translations

Three kinds of text show up in a React component, and each has its own Drupal mechanism.

| Text | Where it comes from | How it is translated |
|---|---|---|
| Fixed UI text of the component (`Any`, `Sort by`, `Loading…`) | the JS: `Drupal.t()`, `Drupal.formatPlural()` | locale module: JS strings, `.po` import or the translation UI |
| Content and labels from Drupal (filter labels, view titles, pager text, row values) | the Views REST export, rendered for the request language | config translation of the view, entity translations |
| Text around the component | Twig: `'Recipes'|t`, SDC props and slots | normal Twig translation |

## 1. JS strings: `Drupal.t()`

```jsx
<label htmlFor="sort">{Drupal.t('Sort by')}</label>
<p>{Drupal.formatPlural(Number(total), '1 recipe found', '@count recipes found')}</p>
<span>{Drupal.t('@min min', { '@min': recipe.prep_time })}</span>
```

How Drupal finds them: the locale module scans every JS file that is attached to the page, **including your built and minified `assets/*.js`**, for calls of `Drupal.t()`
and `Drupal.formatPlural()`. The strings become translatable source strings (visible in *Configuration → Regional and language → User interface translation*
after the page with the component was visited), and the translations of the current language are added to the page automatically.

Rules that make this work:

- **Pass string literals.** `Drupal.t('Sort by')` is found, `Drupal.t(label)` or `Drupal.t('Sort ' + 'by')` is not.
- **Placeholders** use the Drupal syntax: `@name`, `%name`, `:url` with the second argument: `Drupal.t('Page @n', { '@n': 2 })`.
- **Plurals**: `Drupal.formatPlural(count, singular, plural)` where the plural uses `@count`.
- Do not use `Drupal.t()` at module top level before `Drupal` exists: call it inside components and functions (it is a global provided by `core/drupal`).

### Providing translations with the subtheme

Ship a `.po` file and import it. The example subtheme has `translations/es.po`:

```po
msgid "Any"
msgstr "Cualquiera"

msgid "1 recipe found"
msgid_plural "@count recipes found"
msgstr[0] "1 receta encontrada"
msgstr[1] "@count recetas encontradas"
```

Import: *Configuration → Regional and language → User interface translation → Import*, choose the file, language, tick *Replace existing translations*,
Drupal refreshes the JS translations itself (if a page still shows English, clear caches). The translations are loaded for pages in that language (`/es/...`).

To get a template for your own `.po` files, translate in the UI and use *Export*, or start from the strings in your components.

::: tip Why not `drupalSettings`?
You can also pass already translated strings from Twig (`data-label="{{ 'Sort by'|t }}"`). That is fine for a few strings and needs no locale module,
but it does not scale and splits the texts of one component between Twig and JS. Use `Drupal.t()` for UI text.
:::

## 2. Content and labels from Views

The Better REST export is rendered for the language of the request, so give the fetch URL a language prefix. The examples do this
with `path('view.recipe_explorer.better_rest_export_1')` in Twig (it returns `/es/api/recipes` on a Spanish page).

Labels of exposed filters and sorts, group titles, pager text and the view title belong to the view's configuration, so they are translated with config translation:
*Structure → Views → your view → Translate*, or as files in your project's config sync directory. The overrides are plain YAML, only the keys that differ:

```yaml
# <config sync directory>/language/es/views.view.recipe_explorer.yml
display:
  default:
    display_options:
      title: 'Explorador de recetas'
      filters:
        title:
          expose:
            label: Buscar
```

Put the files in `<sync directory>/language/es/` and run `drush config:import` (a normal full import: the `language/<langcode>` folders are config collections). The example subtheme
ships ready files in `config/optional/language/es/` to copy there.

::: warning Optional config translations are not installed by Drupal
Core installs `config/optional` of a module or theme for the default collection only, so `config/optional/language/<langcode>/` is **not** imported when the theme is installed
(only translations in `config/install/language/` are, and the view cannot live there because it needs modules that the theme install does not enable).
That is why the example ships the files for you to import. `drush config:import --partial` is not a way around it, partial imports do not carry config collections.
:::

Only the keys that are translatable in the Views configuration schema can be overridden. Row values (titles, summaries, categories) come from the
translated entities.

## 3. Twig

Strings in the SDC template and the surrounding templates use the usual filter: `{{ 'The recipe explorer needs JavaScript.'|t }}`,
`heading: 'Recipes'|t`. Pass them to React as props or slots.

## Result

On `/es/recipe-explorer` of the example subtheme everything is Spanish: the title, the filter labels, the options (`Cualquiera`), the count
(`10 recetas encontradas`), the pager and the category names.
