# Getting started

You install the **base theme** once and generate a **subtheme** for your components. See [Base theme and subthemes](/guide/base-theme) for why.

## 1. Install the base theme

Compiled files (`assets/`) are not kept in git. Pick the way that fits you:

::: code-group
```bash [Release archive]
# Compiled assets included, nothing to build to run the base theme.
cd web/themes/contrib
curl -L -o frame.zip https://github.com/zviryatko/frame/releases/latest/download/frame-1.0.0.zip
unzip frame.zip && rm frame.zip
```
```json [composer]
// composer.json: a release archive as a package (pin the version, the archive has the compiled assets)
"repositories": [
  {
    "type": "package",
    "package": {
      "name": "zviryatko/frame",
      "version": "1.0.0",
      "type": "drupal-theme",
      "dist": {
        "url": "https://github.com/zviryatko/frame/releases/download/v1.0.0/frame-1.0.0.zip",
        "type": "zip"
      }
    }
  }
]
// then: composer require zviryatko/frame:1.0.0
```
```bash [git]
# Latest source: build the assets yourself.
cd web/themes/contrib
git clone https://github.com/zviryatko/frame.git frame
cd frame && npm install && npm run dist
```
:::

Replace `1.0.0` with the [latest release](https://github.com/zviryatko/frame/releases).

Then install the tooling dependencies of the base theme once. The build and the tests of your subthemes use them (the Vite plugins, Babel presets, Testing Library):

```bash
cd web/themes/contrib/frame && npm install
```

Enable the base theme so Drupal knows its libraries:

```bash
drush theme:install frame
```

## 2. Create a subtheme

```bash
# from the Drupal root
node web/themes/contrib/frame/bin/frame.mjs new my_theme --name "My Theme"

cd web/themes/custom/my_theme
npm run build        # builds ./assets (git-ignored), nothing to install
drush theme:install my_theme
drush config:set system.theme default my_theme -y
drush cr
```

The subtheme is small on purpose: an info file, a libraries file, your components and a `package.json` with three scripts that call the base theme.
The build configuration and its dependencies stay in the base theme. Core's `generate-theme` works too, see [Create a subtheme](/guide/create-your-theme).

The generated theme has a working `hello-react` component with a test, the block placement for a basic page and a README. Place the component to see it work (in any template):

```twig
{% embed 'my_theme:hello-react' with { greeting: 'Hello' } %}
  {% block name %}World{% endblock %}
{% endembed %}
```

You get "Hello, World!" and a counter button, rendered by React inside a Drupal page.

::: tip drush generate theme
`drush generate theme` asks for a base theme and creates an *empty* subtheme (info file, libraries), without components, build scripts or tests. Use it only when you want to write everything yourself: answer `frame` for the base theme and copy the
regions, see [Create a subtheme](/guide/create-your-theme#by-hand).
:::

## 3. Develop

```bash
npm run watch        # rebuild on change (development mode)
npm run build        # production build
npm test             # Jest
```

After editing a `*.component.yml`, a twig file or a `*.libraries.yml`: `drush cr`. With CSS/JS aggregation off, rebuilt files are picked up
without a cache clear, but the `?query` token of asset URLs changes only after a cache rebuild, so a browser can keep an old file.

Next: [Creating a component](/guide/components), [Placing it in Twig](/guide/twig). For a site where editors build
pages from your components, follow [Build a site](/guide/build-a-site).

## Try the example subtheme

The repository contains a ready subtheme with the recipe explorer, node list and tooltip, for sites with the `demo_umami` content:

```bash
composer require 'drupal/views_better_rest:^1.3'
drush en node rest serialization views_better_rest
cd web/themes/contrib/frame/examples/frame_demo
npm install && npm run dist
drush theme:install frame_demo
drush config:set system.theme default frame_demo -y
```

Pages: `/recipe-explorer`, `/node-list`. Installing the theme creates the two views and the block placement (`config/optional`).

Spanish translations are an extra step, see [Translations](/guide/translations): import `translations/es.po` in the UI (JS strings) and import `config/optional/language/es/*.yml`
through your config sync directory (view labels).

::: tip views_better_rest 1.3
Use version 1.3 or newer. It supports Drupal 11, including 11.4 where 1.2.0 fatals
(`UrlNormalizer::normalize() must be compatible`), and views of Search API with Facets.
:::
