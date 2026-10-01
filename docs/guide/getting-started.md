# Getting started

## 1. Install the theme into a Drupal site

```bash
cd web/themes/custom
git clone git@github.com:zviryatko/drupal-react-scaffold-theme.git
cd drupal-react-scaffold-theme
npm install
npm run dist          # builds ./assets (git-ignored)
```

`assets/` is not committed, so build before enabling the theme (and in your deploy pipeline).

## 2. Enable the modules and the theme

```bash
composer require drupal/views_better_rest
drush en node rest serialization views_better_rest
drush theme:install react_scaffold      # imports config/optional: views + Umami blocks
drush config:set system.theme default react_scaffold -y
```

Then open `/recipe-explorer` and `/node-list`.

::: warning views_better_rest and Drupal 11.4-dev
`views_better_rest` 1.2.0 and the 1.x branch fatal on Drupal 11.4-dev: `UrlNormalizer` extends `ComplexDataNormalizer`, whose
`normalize()` now returns `array`. Extending `NormalizerBase` instead fixes it (a two-line change). Until it is released upstream,
patch the module locally. Released Drupal 10/11 versions are not affected.
:::

## 3. Develop

```bash
npm run watch        # vite build --watch (development mode, source maps, unminified React)
npm run dist         # production build
npm test             # jest
npm run docs:dev     # this documentation
```

After changing a `*.component.yml`, a twig file or `libraries.yml`, clear the Drupal cache. With CSS/JS aggregation off,
rebuilt files are picked up without a cache clear, but the `?query` string on asset URLs only changes after a cache rebuild,
so a stubborn browser cache can keep an old file.
