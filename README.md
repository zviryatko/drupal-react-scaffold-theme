# Drupal React Scaffold theme

React components as **core Single Directory Components** (SDC) for Drupal 10.3+ / 11. No contrib modules for components, Vite build,
data from Views through [`views_better_rest`](https://www.drupal.org/project/views_better_rest).

📖 **Documentation: https://zviryatko.github.io/drupal-react-scaffold-theme/**

![Recipe explorer demo](docs/public/media/recipe-explorer-demo.gif)

*Recipe explorer on the Umami demo. Filters, sorting and pager come from one Views REST export, DevTools (Network, Fetch/XHR)
shows the ajax calls.* [Full video (mp4)](docs/public/media/recipe-explorer-demo.mp4)

## What it gives you

- every React widget is an SDC (`*.component.yml` + twig): place it with `include`, `embed` or a render array,
- one shared React (UMD, loaded once as a library), components are small Vite bundles attached through `libraryOverrides`,
- components mount from `Drupal.behaviors` + `once()` + `executeWhenVisible()`: they work after ajax, in modals, in hidden tabs, with BigPipe,
- `apiClient` (fetch with CSRF header) and helpers to render server HTML inside React and attach Drupal behaviors to it (`rawHtml`, `attachBehaviors`),
- no jQuery in the theme code,
- Jest tests, Playwright demo recorder.

## Quick start

```bash
cd web/themes/custom
git clone git@github.com:zviryatko/drupal-react-scaffold-theme.git
cd drupal-react-scaffold-theme
npm install && npm run dist        # builds ./assets (git-ignored)

composer require drupal/views_better_rest
drush en node rest serialization views_better_rest
drush theme:install react_scaffold  # imports config/optional: views + Umami blocks
```

Pages on the `demo_umami` profile: `/recipe-explorer` and `/node-list`.

> `views_better_rest` 1.2.0 / 1.x fatals on Drupal 11.4-dev (`UrlNormalizer` must extend `NormalizerBase`), see
> [Getting started](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/getting-started).

## Examples

| Component | What it shows | Docs |
|---|---|---|
| `recipe-explorer` | Filters, sorts and pager built from a Views *Better REST export*, state in the URL | [walkthrough](https://zviryatko.github.io/drupal-react-scaffold-theme/examples/recipe-explorer) |
| `node-list` | React table (rsuite) with Drupal ajax "Edit in Modal" inside the cells | [walkthrough](https://zviryatko.github.io/drupal-react-scaffold-theme/examples/node-list) |
| `react-tooltip` | The smallest component: prop, slot, behavior | [walkthrough](https://zviryatko.github.io/drupal-react-scaffold-theme/examples/tooltip) |

![Node list demo](docs/public/media/node-list-modal-demo.gif)

[Full video (mp4)](docs/public/media/node-list-modal-demo.mp4)

## Use it for your own theme

Copy the scaffold and rename `react_scaffold`, then add components under `components/<name>/` (any folder with `index.jsx` is built
automatically). Everything is explained in the docs:

- [Create your own theme](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/create-your-theme)
- [Components (SDC)](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/components) and [placing them in Twig](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/twig)
- [React + Drupal behaviors](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/react-behaviors): why `once()` and `executeWhenVisible`
- [Ajax calls and HTML responses](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/ajax)
- [Build: Vite and webpack](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/build)
- [Troubleshooting](https://zviryatko.github.io/drupal-react-scaffold-theme/guide/troubleshooting)

## Scripts

| Command | Does |
|---|---|
| `npm run dist` | production build into `assets/` |
| `npm run watch` | development build, rebuilds on change |
| `npm test` | Jest |
| `npm run docs:dev` / `docs:build` | the documentation site (VitePress, sources in `docs/`) |
| `npm run demo` | records screenshots and videos into `docs/public/media` (Playwright, ffmpeg, a demo_umami site, set `BASE_URL`) |

## Documentation site

Sources are in [`docs/`](docs) (VitePress). The workflow `.github/workflows/docs.yml` builds and publishes it to GitHub Pages. In the repository
settings, set **Pages → Source: GitHub Actions**. By default the `github-pages` environment only allows deployments from the default branch.

# Credits

- Scaffolded by [zviryatko](https://github.com/zviryatko)
- Developed by [Nuvole.org](https://nuvole.org)
- The initial theme scaffolding was inspired by project of [Massimo Altafini](massimo@nuvole.org)
