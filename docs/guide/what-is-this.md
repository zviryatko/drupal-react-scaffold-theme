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
| Global React + helpers | base theme: `assets/react/react.js`, `react-dom.js`, `assets/helpers.js` (library `react_scaffold/react`) |
| API client | base theme: `src/apiClient.js` (library `react_scaffold/react-api-client`) |
| Components | your subtheme: `components/<name>/` |
| Build | `vite.base.js` in the base theme, a short `vite.config.js` in each subtheme that loads it |
| Views, blocks, templates | your subtheme: `config/optional/`, `templates/` |

## Base theme and subthemes

The scaffold is a **base theme**: it ships the runtime (React, helpers, API client, CSRF hook), a neutral page layout and the shared Vite/Jest
configuration. You create a **subtheme** with core's `generate-theme` and put your components there. Updating the scaffold then means updating
one folder. Details: [Base theme and subthemes](/guide/base-theme).

## What you get out of the box

- the **base theme** `react_scaffold` and a **starterkit** for `generate-theme` (a working `hello-react` component, blocks, build and tests),
- the example subtheme `react_scaffold_demo` with `react-tooltip` (smallest component), `node-list` (rsuite table with Drupal ajax modal links) and
  `recipe-explorer` (data-driven filter UI on a Views REST export, see [the walkthrough](/examples/recipe-explorer)).

## Requirements

- Drupal 10.3+ / 11 (SDC in core). Developed against 11.4-dev.
- Node.js 20+ to build.
- For the data-driven examples: [`drupal/views_better_rest`](https://www.drupal.org/project/views_better_rest), `rest`, `serialization`, `views`.
- The example subtheme targets the content of the `demo_umami` profile (recipes, articles), the base theme and the generated subthemes do not depend on it.
