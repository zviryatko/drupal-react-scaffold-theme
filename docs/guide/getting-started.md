# Getting started

You install the **base theme** once and generate a **subtheme** for your components. See [Base theme and subthemes](/guide/base-theme) for why.

## 1. Install the base theme

Compiled files (`assets/`) are not kept in git. Pick the way that fits you:

::: code-group
```bash [Release archive]
# Compiled assets included, nothing to build to run the base theme.
cd web/themes/contrib
curl -L -o react_scaffold.zip https://github.com/zviryatko/drupal-react-scaffold-theme/releases/latest/download/react_scaffold-1.0.0.zip
unzip react_scaffold.zip && rm react_scaffold.zip
```
```json [composer]
// composer.json: a release archive as a package (pin the version, the archive has the compiled assets)
"repositories": [
  {
    "type": "package",
    "package": {
      "name": "zviryatko/drupal-react-scaffold-theme",
      "version": "1.0.0",
      "type": "drupal-theme",
      "dist": {
        "url": "https://github.com/zviryatko/drupal-react-scaffold-theme/releases/download/v1.0.0/react_scaffold-1.0.0.zip",
        "type": "zip"
      }
    }
  }
]
// then: composer require zviryatko/drupal-react-scaffold-theme:1.0.0
```
```bash [git]
# Latest source: build the assets yourself.
cd web/themes/contrib
git clone https://github.com/zviryatko/drupal-react-scaffold-theme.git react_scaffold
cd react_scaffold && npm install && npm run dist
```
:::

Replace `1.0.0` with the [latest release](https://github.com/zviryatko/drupal-react-scaffold-theme/releases).

Then install the tooling dependencies of the base theme once. The build and the tests of your subthemes use them (the Vite plugins, Babel presets, Testing Library):

```bash
cd web/themes/contrib/react_scaffold && npm install
```

Enable the base theme so Drupal knows its libraries:

```bash
drush theme:install react_scaffold
```

## 2. Generate a subtheme

Use Drupal core's generator with this theme's starterkit, as described in the [official sub-theme documentation](https://www.drupal.org/node/2165673):

```bash
# from the Drupal root
php web/core/scripts/drupal generate-theme my_theme \
  --starterkit react_scaffold_starterkit \
  --path themes/custom \
  --name "My Theme"
```

`--path` is relative to the web root (the folder with `core/`). On Drupal 11.4+ the script is `web/core/scripts/dr`
(`drupal` still works and prints a deprecation notice).

```bash
cd web/themes/custom/my_theme
npm install          # vite, sass, jest
npm run dist         # builds ./assets (git-ignored)
drush theme:install my_theme
drush config:set system.theme default my_theme -y
drush cr
```

The generated theme has a working `hello-react` component, the block placement for a basic page, `vite.config.js`, a Jest setup and a
README. Place the component to see it work (in any template):

```twig
{% embed 'my_theme:hello-react' with { greeting: 'Hello' } %}
  {% block name %}World{% endblock %}
{% endembed %}
```

You get "Hello, World!" and a counter button, rendered by React inside a Drupal page.

::: tip drush generate theme
`drush generate theme` asks for a base theme and creates an *empty* subtheme (info file, libraries). It does not know this starterkit, so there are
no components, build or tests. Use it only when you want to write everything yourself: answer `react_scaffold` for the base theme and copy the
regions, see [Create a subtheme](/guide/create-your-theme#by-hand).
:::

## 3. Develop

```bash
npm run watch        # rebuild on change (development mode)
npm run dist         # production build
npm test             # Jest
```

After editing a `*.component.yml`, a twig file or a `*.libraries.yml`: `drush cr`. With CSS/JS aggregation off, rebuilt files are picked up
without a cache clear, but the `?query` token of asset URLs changes only after a cache rebuild, so a browser can keep an old file.

Next: [Creating a component](/guide/components), [Placing it in Twig](/guide/twig).

## Try the example subtheme

The repository contains a ready subtheme with the recipe explorer, node list and tooltip, for sites with the `demo_umami` content:

```bash
composer require drupal/views_better_rest
drush en node rest serialization views_better_rest
cd web/themes/contrib/react_scaffold/examples/react_scaffold_demo
npm install && npm run dist
drush theme:install react_scaffold_demo
drush config:set system.theme default react_scaffold_demo -y
```

Pages: `/recipe-explorer`, `/node-list`. Installing the theme creates the two views and the block placement (`config/optional`).

Spanish translations are an extra step, see [Translations](/guide/translations): import `translations/es.po` in the UI (JS strings) and import `config/optional/language/es/*.yml`
through your config sync directory (view labels).

::: warning views_better_rest and Drupal 11.4-dev
`views_better_rest` 1.2.0 and the 1.x branch fatal on Drupal 11.4-dev: `UrlNormalizer` extends `ComplexDataNormalizer`, whose
`normalize()` now returns `array`. Extending `NormalizerBase` instead fixes it (a two-line change). Until it is released upstream,
patch the module locally. Released Drupal 10/11 versions are not affected.
:::
