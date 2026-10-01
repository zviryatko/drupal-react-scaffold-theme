# What is this

A Drupal theme scaffold for putting **React islands** into server rendered Drupal pages, built only from core features:

- every widget is a [Single Directory Component](https://www.drupal.org/docs/theming-drupal/using-single-directory-components) (SDC),
- JS/CSS is built by Vite into `assets/` and attached through the SDC library,
- React runs as a global (one copy for the page), components are tiny bundles,
- mounting happens in a Drupal behavior, so Drupal's own lifecycle (ajax, modals, BigPipe, hidden tabs) keeps working,
- data comes from Views via a *Better REST export* display.

Drupal stays the "index point" of the page. This is **not** a decoupled/headless setup: Drupal renders the page, menus, blocks and
SEO markup, and React only owns the interactive widgets.

## Architecture

In short: Drupal renders a mount element from an SDC, a Drupal behavior mounts React into it, React fetches JSON from a Views REST
export. The step by step flow is in [React + Drupal behaviors](/guide/react-behaviors#how-it-works-end-to-end).

| Piece | Where |
|---|---|
| Global React + helpers | `assets/react/react.js`, `assets/react/react-dom.js`, `assets/helpers.js` (library `react_scaffold/react`) |
| API client | `components/apiClient.js` (library `react_scaffold/react-api-client`) |
| Components | `components/<name>/` |
| Build | `vite.config.js`, `vite-plugin-copy-react.js` |
| Optional config (views, blocks) | `config/optional/` |

## What you get out of the box

- `react-tooltip`: the smallest possible React SDC, used on the page title.
- `node-list`: rsuite table with filters and sorting, plus Drupal ajax modal links inside React markup.
- `recipe-explorer`: a data-driven filter UI on top of a Views REST export (see [the walkthrough](/examples/recipe-explorer)).

## Requirements

- Drupal 10.3+ / 11 (SDC in core). Developed against 11.4-dev.
- Node.js 20+ to build.
- For the data-driven examples: [`drupal/views_better_rest`](https://www.drupal.org/project/views_better_rest), `rest`, `serialization`, `views`.
- The example blocks and pages target the `demo_umami` profile (the theme has `base theme: umami`).
