# Drupal React Scaffold: base theme

A Drupal **base theme** for React islands built as **core Single Directory Components** (SDC), for Drupal 10.3+ / 11.
Install it once, generate a **subtheme** for your project, put your components there. The React runtime, helpers, API client,
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
- `react-scaffold/vite` and `react-scaffold/jest`: shared build and test config for every subtheme,
- a generator (`npm run create-subtheme`) and an example subtheme with real components, views and blocks,
- no jQuery in the theme code.

## Quick start

```bash
# 1. base theme (built assets are committed, nothing to build)
cd web/themes/contrib
git clone https://github.com/zviryatko/drupal-react-scaffold-theme.git react_scaffold
# or: composer config repositories.react_scaffold vcs https://github.com/zviryatko/drupal-react-scaffold-theme
#     composer require zviryatko/drupal-react-scaffold-theme
drush theme:install react_scaffold

# 2. your subtheme
cd react_scaffold
npm run create-subtheme -- my_theme "My Theme" ../../custom
cd ../../custom/my_theme
npm install && npm run dist
drush theme:install my_theme && drush config:set system.theme default my_theme -y && drush cr
```

The generated `hello-react` component works out of the box:

```twig
{% embed 'my_theme:hello-react' with { greeting: 'Hello' } %}{% block name %}World{% endblock %}{% endembed %}
```

## Repository layout

| Path | What |
|---|---|
| `react_scaffold.*`, `templates/`, `scss/base.scss` | the base theme: info, libraries, CSRF hook, page layout |
| `src/` | runtime sources: `helpers.jsx`, `apiClient.js`, `common.js` (built into the committed `assets/`) |
| `vite.base.js`, `jest.base.cjs` | shared configs, exported as `react-scaffold/vite` and `react-scaffold/jest` |
| `starter/`, `scripts/create-subtheme.mjs` | template and generator for subthemes |
| `examples/react_scaffold_demo/` | example subtheme: `recipe-explorer`, `node-list`, `react-tooltip`, views, blocks |
| `docs/` | documentation site (VitePress) and demo media |
| `demo/` | Playwright recorder for the media |

## Example subtheme

For sites with the `demo_umami` content:

```bash
composer require drupal/views_better_rest
drush en node rest serialization views_better_rest
cd examples/react_scaffold_demo && npm install && npm run dist
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
| `npm install && npm run dist` | rebuild the runtime into `assets/` (commit the result, CI checks it is current) |
| `npm test` | Jest (tests of `src/`) |
| `npm run docs:dev` / `docs:build` | the documentation site (installs from `docs/package.json`) |
| `npm run demo` | records screenshots and videos into `docs/public/media` (`BASE_URL`, Playwright, ffmpeg) |

See [`CHANGELOG.md`](CHANGELOG.md) for the public API and changes. The docs are published by `.github/workflows/docs.yml` (Pages source: GitHub Actions).

# Credits

- Scaffolded by [zviryatko](https://github.com/zviryatko)
- Developed by [Nuvole.org](https://nuvole.org)
- The initial theme scaffolding was inspired by project of [Massimo Altafini](massimo@nuvole.org)
