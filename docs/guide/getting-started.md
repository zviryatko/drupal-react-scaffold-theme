# Getting started

## 1. Install the theme into a Drupal site

```bash
cd web/themes/custom
git clone -b v2 git@github.com:zviryatko/drupal-react-scaffold-theme.git
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

## Local environment used for the demos (Drupal core checkout)

If you work in a Drupal core checkout, the repository's author uses Docker with the SQLite file on a tmpfs for speed:

```yaml
# docker-compose.yaml (php service)
tmpfs:
  - /var/www/html/web/db:size=2g,mode=1777,uid=1000,gid=1000
ports:
  - "8088:80"
```

```bash
./dev-install.sh     # installs demo_umami with sites/default/files/.sqlite symlinked into the tmpfs
```

The core installer hardcodes `sites/default/files/.sqlite`, so the script symlinks that path into the tmpfs. The data is lost when
the container is recreated, run the script again. This is a dev convenience, not part of the theme.

`dev-install.sh` (lives in the Drupal checkout root, next to `docker-compose.yaml`):

```sh
#!/bin/sh
set -e
docker exec -u web-user core sh -c '
  cd /var/www/html/web
  rm -f sites/default/settings.php sites/default/files/.sqlite*
  ln -sf /var/www/html/web/db/site.sqlite sites/default/files/.sqlite
  php core/scripts/dr install demo_umami --site-name="Drupal Umami" --password=admin
'
```
