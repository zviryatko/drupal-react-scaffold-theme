# Getting started

You install the **base theme** once and create a **subtheme** for your components. See [Base theme and subthemes](/guide/base-theme)
for why.

## 1. Install the base theme

::: code-group
```bash [composer]
# not on Packagist yet: add the repository, then require it
composer config repositories.react_scaffold vcs https://github.com/zviryatko/drupal-react-scaffold-theme
composer require zviryatko/drupal-react-scaffold-theme
# the package type is drupal-theme, it lands where your installer-paths put themes, e.g. web/themes/contrib/
```
```bash [git]
cd web/themes/contrib
git clone https://github.com/zviryatko/drupal-react-scaffold-theme.git react_scaffold
```
:::

The built runtime (`assets/`) is committed, nothing to build for the base theme. Enable it (and `stable9`, its base) so Drupal knows
its libraries:

```bash
drush theme:install react_scaffold
```

## 2. Create a subtheme

```bash
cd web/themes/contrib/react_scaffold
npm run create-subtheme -- my_theme "My Theme" ../../custom
```

Arguments: machine name, label, destination folder (default: next to the base theme). The generator writes a working subtheme with a
`hello-react` component, `vite.config.js` and `package.json` linked to the base theme, the region list, and a test.

```bash
cd ../../custom/my_theme
npm install          # also installs the base theme tooling (preinstall hook)
npm run dist         # builds ./assets (git-ignored)
drush theme:install my_theme
drush config:set system.theme default my_theme -y
drush cr
```

Place the starter component to see it work (for example in `page.html.twig` of your theme, or any template):

```twig
{% embed 'my_theme:hello-react' with { greeting: 'Hello' } %}
  {% block name %}World{% endblock %}
{% endembed %}
```

You get "Hello, World!" and a counter button, rendered by React inside a Drupal page.

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

Pages: `/recipe-explorer`, `/node-list`.

::: warning views_better_rest and Drupal 11.4-dev
`views_better_rest` 1.2.0 and the 1.x branch fatal on Drupal 11.4-dev: `UrlNormalizer` extends `ComplexDataNormalizer`, whose
`normalize()` now returns `array`. Extending `NormalizerBase` instead fixes it (a two-line change). Until it is released upstream,
patch the module locally. Released Drupal 10/11 versions are not affected.
:::
