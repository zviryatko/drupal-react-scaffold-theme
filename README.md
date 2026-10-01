# Drupal React Scaffold: base theme

A Drupal **base theme** for React islands built as **core Single Directory Components** (SDC), for Drupal 10.3+ / 11.
Install it once, generate a **subtheme** with Drupal core's `generate-theme` and put your components there. The React runtime, helpers, API client,
CSRF hook and the Vite/Jest configuration come from the base theme, so updating the scaffold is updating one folder.

📖 **Documentation: https://zviryatko.github.io/drupal-react-scaffold-theme/**

![Recipe explorer demo](docs/public/media/recipe-explorer-demo.gif)

*Recipe explorer from the example subtheme. Filters, sorting and pager come from one Views REST export, DevTools (Network, Fetch/XHR)
shows the ajax calls.* [Full video (mp4)](docs/public/media/recipe-explorer-demo.mp4)

## What it gives you

- every React widget is an SDC (`*.component.yml` + twig): place it with `include`, `embed` or a render array,
- one shared React (UMD, loaded once), components are small Vite bundles attached through `libraryOverrides`,
- components mount from `Drupal.behaviors` + `once()` + `executeWhenVisible()`: they work after ajax, in modals, in hidden tabs, with BigPipe,
- `apiClient` (fetch with CSRF header) and helpers to render server HTML inside React and attach Drupal behaviors to it,
- shared build and test config (`vite.base.js`, `jest.base.cjs`) reused by every subtheme,
- a starterkit for core's `generate-theme` and an example subtheme with real components, views and blocks,
- no jQuery in the theme code.

## Quick start

```bash
# 1. base theme: a release archive has the compiled assets (or git clone + npm install + npm run dist)
cd web/themes/contrib
curl -L -o react_scaffold.zip https://github.com/zviryatko/drupal-react-scaffold-theme/releases/latest/download/react_scaffold-1.0.0.zip
unzip react_scaffold.zip && rm react_scaffold.zip
(cd react_scaffold && npm install)        # build/test tooling for subthemes, once
drush theme:install react_scaffold

# 2. your subtheme, with core's generator and this theme's starterkit (from the Drupal root)
php web/core/scripts/drupal generate-theme my_theme --starterkit react_scaffold_starterkit --path themes/custom --name "My Theme"
cd web/themes/custom/my_theme
npm install && npm run dist
drush theme:install my_theme && drush config:set system.theme default my_theme -y && drush cr
```

The generated `hello-react` component works out of the box:

```twig
{% embed 'my_theme:hello-react' with { greeting: 'Hello' } %}{% block name %}World{% endblock %}{% endembed %}
```

Compiled files are not kept in git. Pushing a version tag runs the release workflow, which builds them and attaches the theme archive
(`react_scaffold-<version>.zip`) to the GitHub release. See the [documentation](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/getting-started)
for composer and git installs.

## Repository layout

| Path | What |
|---|---|
| `react_scaffold.*`, `templates/`, `scss/base.scss` | the base theme (`base theme: false`): info, libraries, CSRF hook, page layout |
| `src/` | runtime sources: `helpers.jsx`, `apiClient.js`, `common.js` (built into `assets/`, git-ignored) |
| `vite.base.js`, `jest.base.cjs` | shared build and test configs, loaded by every subtheme |
| `starterkit/` | `react_scaffold_starterkit`, the template for core's `generate-theme` |
| `examples/react_scaffold_demo/` | example subtheme: `recipe-explorer`, `node-list`, `react-tooltip`, views, blocks |
| `docs/` | documentation site (VitePress) and demo media |
| `demo/` | Playwright recorder for the media |
| `.github/workflows/` | `ci.yml` (tests, builds, `generate-theme`), `release.yml` (compiled archive), `docs.yml` (Pages) |

## Example subtheme

For sites with the `demo_umami` content:

```bash
composer require drupal/views_better_rest
drush en node rest serialization views_better_rest
cd examples/react_scaffold_demo && npm install && npm run dist   # needs `npm install` in the base theme first
drush theme:install react_scaffold_demo && drush config:set system.theme default react_scaffold_demo -y
```

| Component | What it shows | Docs |
|---|---|---|
| `recipe-explorer` | Filters, sorts and pager built from a Views *Better REST export*, state in the URL | [walkthrough](https://zviryatko.github.io/drupal-react-scaffold-theme/examples/recipe-explorer) |
| `node-list` | React table (rsuite) with Drupal ajax "Edit in Modal" inside the cells | [walkthrough](https://zviryatko.github.io/drupal-react-scaffold-theme/examples/node-list) |
| `react-tooltip` | The smallest component: prop, slot, behavior | [walkthrough](https://zviryatko.github.io/drupal-react-scaffold-theme/examples/tooltip) |

![Node list demo](docs/public/media/node-list-modal-demo.gif)

[Full video (mp4)](docs/public/media/node-list-modal-demo.mp4)

> `views_better_rest` 1.2.0 / 1.x fatals on Drupal 11.4-dev (`UrlNormalizer` must extend `NormalizerBase`), see
> [Getting started](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/getting-started).

## Documentation

- [Getting started](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/getting-started) and [Base theme and subthemes](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/base-theme)
- [Create a subtheme](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/create-your-theme), [Creating a component](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/components), [placing it in Twig](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/twig)
- [React + Drupal behaviors](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/react-behaviors): why `once()` and `executeWhenVisible`
- [Ajax calls and HTML responses](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/ajax)
- [Build: Vite and webpack](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/build), [Testing](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/testing), [Troubleshooting](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/troubleshooting)

## Working on the base theme

| Command | Does |
|---|---|
| `npm install && npm run dist` | build the runtime into `assets/` (not committed) |
| `npm test` | Jest (tests of `src/` and of the starterkit) |
| `npm run docs:dev` / `docs:build` | the documentation site (installs from `docs/package.json`) |
| `npm run demo` | records screenshots and videos into `docs/public/media` (`BASE_URL`, Playwright, ffmpeg) |

See [`CHANGELOG.md`](CHANGELOG.md) for the public API and changes. The docs are published by `.github/workflows/docs.yml` (Pages source: GitHub Actions), releases by `.github/workflows/release.yml`.

# Credits

- Scaffolded by [zviryatko](https://github.com/zviryatko)
- Developed by [Nuvole.org](https://nuvole.org)
- The initial theme scaffolding was inspired by project of [Massimo Altafini](massimo@nuvole.org)
