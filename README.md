# Drupal React Scaffold: base theme

A Drupal **base theme** for React islands built as **core Single Directory Components** (SDC), for Drupal 10.3+ / 11.
Install it once, create a small **subtheme** with one command and put your components there. The React runtime, helpers, API client,
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
- build and tests live in the base theme: a subtheme has no Vite/Jest config and no `node_modules`, only `npm run build|watch|test` scripts that call it,
- a one-command subtheme generator (also a starterkit for core's `generate-theme`) and an example subtheme with real components, views and blocks,
- a visual **page builder** for those components (the `react_scaffold_builder` module, inside the theme): drag components, fields, views and
  blocks onto a page; Drupal renders the result as normal SDCs,
- no jQuery in the theme code.

## Quick start

```bash
# 1. base theme: a release archive has the compiled assets (or git clone + npm install + npm run dist)
cd web/themes/contrib
curl -L -o react_scaffold.zip https://github.com/zviryatko/drupal-react-scaffold-theme/releases/latest/download/react_scaffold-1.0.0.zip
unzip react_scaffold.zip && rm react_scaffold.zip
(cd react_scaffold && npm install)        # build/test tooling for subthemes, once
drush theme:install react_scaffold

# 2. your subtheme (from the Drupal root)
node web/themes/contrib/react_scaffold/bin/react-scaffold.mjs new my_theme --name "My Theme"
cd web/themes/custom/my_theme
npm run build
drush theme:install my_theme && drush config:set system.theme default my_theme -y && drush cr
```

A subtheme is an info file, a libraries file, your components and a `package.json` with three scripts and no dependencies. The Vite and Jest
setup stays in the base theme, so updating the base theme updates every subtheme's build.

The generated `hello-react` component works out of the box:

```twig
{% embed 'my_theme:hello-react' with { greeting: 'Hello' } %}{% block name %}World{% endblock %}{% endembed %}
```

Compiled files are not kept in git. Pushing a version tag runs the release workflow, which builds them and attaches the theme archive
(`react_scaffold-<version>.zip`) to the GitHub release. See the [documentation](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/getting-started)
for composer and git installs.

## Page builder

```bash
drush en react_scaffold_builder      # the module is inside the theme folder
```

Or set a whole site up with a recipe (media, responsive images, paragraphs with the library, meta tags, the AI assistant):

```bash
composer require drupal/paragraphs drupal/entity_usage drupal/metatag drupal/ai
drush recipe themes/contrib/react_scaffold/recipes/react_scaffold_builder_full --input=react_scaffold_builder.theme=my_theme
```

The theme declares its design tokens once, in its `.info.yml`: editors pick from them in the builder, and the theme's SCSS reads the same
values with `@use 'react-scaffold/tokens' as rs;` (`rs.space(m)`, `rs.color(primary)`, `@include rs.breakpoint(tablet)`).

Switch it on for a content type (*Structure → Content types → Page builder*; a **Landing page** type is included) and its edit page becomes
a visual editor over your theme's components: layouts (columns, flex row, stack), spacing and backgrounds from your theme's tokens, fields
of the page with any formatter, views, Drupal blocks, translations. See the
[Page builder guide](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/page-builder). From a git checkout, build the editor once
with `npm run builder:install && npm run builder:build`; release archives include it.

## Repository layout

| Path | What |
|---|---|
| `react_scaffold.*`, `templates/`, `scss/base.scss` | the base theme (`base theme: false`): info, libraries, CSRF hook, page layout |
| `src/` | runtime sources: `helpers.jsx`, `apiClient.js`, `common.js` (built into `assets/`, git-ignored) |
| `bin/react-scaffold.mjs` | command line used by subthemes: `new`, `build`, `watch`, `test` |
| `vite.base.js`, `jest.base.cjs` | shared build and test configs |
| `starterkit/` | template of a subtheme, for `react-scaffold new` and core's `generate-theme` |
| `components/` | layout components of the base theme: `columns`, `flex`, `stack` (Twig and CSS only) |
| `modules/react_scaffold_builder/` | the page builder module: PHP in `src/`, the editor (React, Puck) in `editor/`, built into `js/` |
| `recipes/` | Drupal recipes that set a site up for the page builder |
| `tokens.js` | the theme's design tokens (YAML) as a Sass module, used by `vite.base.js` and `react-scaffold tokens` |
| `examples/react_scaffold_demo/` | example subtheme: `recipe-explorer`, `node-list`, `react-tooltip`, views, blocks |
| `docs/` | documentation site (VitePress) and demo media |
| `demo/` | Playwright: recorder for the media, `smoke.mjs` browser check used by CI |
| `.github/workflows/` | `ci.yml` (tests, builds, subthemes on a real Drupal with SQLite), `release.yml` (compiled archive), `docs.yml` (Pages) |

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
- [Build a site](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/build-a-site): from an empty Drupal to a page, step by step
- [Page builder](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/page-builder): the visual editor. With it:
  [recipes](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/recipes),
  [components](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/components),
  [views](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/views),
  [tokens, features and styling](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/styling),
  [layouts](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/layouts),
  [library of saved blocks](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/library),
  [paragraphs](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/paragraphs),
  [AI assistant](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/ai),
  [architecture](https://zviryatko.github.io/drupal-react-scaffold-theme/builder/architecture)
- [Ajax calls and HTML responses](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/ajax)
- [Build: Vite and webpack](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/build), [Testing](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/testing), [Troubleshooting](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/troubleshooting)

## Working on the base theme

| Command | Does |
|---|---|
| `npm install && npm run dist` | build the runtime into `assets/` (not committed) |
| `npm test` | Jest (tests of `src/` and of the starterkit) |
| `npm run docs:dev` / `docs:build` | the documentation site (installs from `docs/package.json`) |
| `npm run demo` | records screenshots and videos into `docs/public/media` (`BASE_URL`, Playwright, ffmpeg) |
| `npm run builder:install && npm run builder:build` | build the editor of the page builder into `modules/react_scaffold_builder/js/` (not committed) |
| `BASE_URL=… npm run builder:smoke` | browser smoke test of the page builder (`demo/builder-smoke.mjs`) |

See [`CHANGELOG.md`](CHANGELOG.md) for the public API and changes. The docs are published by `.github/workflows/docs.yml` (Pages source: GitHub Actions), releases by `.github/workflows/release.yml`.

# Credits

- Scaffolded by [zviryatko](https://github.com/zviryatko)
- Developed by [Nuvole.org](https://nuvole.org)
- The initial theme scaffolding was inspired by project of [Massimo Altafini](massimo@nuvole.org)
