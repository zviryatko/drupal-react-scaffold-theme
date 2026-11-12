# What is this

**The Last Page Builder You'll Ever Need. Drupal Edition.** Frame is a Drupal base theme with a visual
[page builder](/guide/page-builder) inside. Editors compose pages from the components of your theme; Drupal stores the
layout and renders it:

- every block of the builder is a [Single Directory Component](https://www.drupal.org/docs/theming-drupal/using-single-directory-components) (SDC) of your theme,
  and a ready set comes with the base theme,
- fields of the page, views, Drupal blocks, media and paragraphs can be placed next to them,
- spacing, colours, sizes and breakpoints come from the design tokens of the theme, the same values its SCSS uses,
- the published page is normal Drupal markup, with caching, translations and SEO; it loads no editor code.

**React** is used in two places: the editor itself, and **interactive components**. A component that needs behaviour in
the browser (a filter UI, a table, a tooltip) is an SDC with a small React bundle:

- JS/CSS is built by Vite into `assets/` and attached through the SDC library,
- React runs as a global (one copy for the page), components are tiny bundles,
- mounting happens in a Drupal behavior, so Drupal's own lifecycle (ajax, modals, BigPipe, hidden tabs) keeps working,
- data comes from Views via a *Better REST export* display.

Components without behaviour need no React and no build: a `component.yml`, a Twig file and CSS.

Drupal stays the "index point" of the page. This is **not** a decoupled/headless setup: Drupal renders the page, menus, blocks and
SEO markup, and React only owns the interactive widgets.

## Architecture of an interactive component

How the builder stores and renders a page is in [its architecture](/builder/architecture). For a React component, in short: Drupal renders a mount element from an SDC, a Drupal behavior mounts React into it, React fetches JSON from a Views REST
export. The step by step flow is in [React + Drupal behaviors](/guide/react-behaviors#how-it-works-end-to-end).

| Piece | Where |
|---|---|
| Global React + helpers | base theme: `assets/react/react.js`, `react-dom.js`, `assets/helpers.js` (library `frame/react`) |
| API client | base theme: `src/apiClient.js` (library `frame/react-api-client`) |
| Components | your subtheme: `components/<name>/` |
| Build and tests | the base theme: Vite, Jest and their configuration. A subtheme only has `npm run build` / `npm test` scripts that call it |
| Views, blocks, templates | your subtheme: `config/optional/`, `templates/` |

## Base theme and subthemes

The scaffold is a **base theme**: it ships the runtime (React, helpers, API client, CSRF hook), a neutral page layout and the shared Vite/Jest
configuration. You create a **subtheme** with one command and put your components there. Updating the scaffold then means updating
one folder. Details: [Base theme and subthemes](/guide/base-theme).

## What you get out of the box

- the **page builder** (the `frame_builder` module, inside the theme), its [recipes](/builder/recipes) and the
  [components of the base theme](/builder/base-components),
- the **base theme** `frame` and a **subtheme generator** (a working `hello-react` component, blocks, build and tests),
- the example subtheme `frame_demo` with `react-tooltip` (smallest component), `node-list` (rsuite table with Drupal ajax modal links) and
  `recipe-explorer` (data-driven filter UI on a Views REST export, see [the walkthrough](/examples/recipe-explorer)).

## Requirements

- Drupal 10.3+ / 11 (SDC in core). Developed against 11.4-dev.
- Node.js 20+ to build.
- For the data-driven examples: [`drupal/views_better_rest`](https://www.drupal.org/project/views_better_rest) 1.3+, `rest`, `serialization`, `views`.
- The example subtheme targets the content of the `demo_umami` profile (recipes, articles), the base theme and the generated subthemes do not depend on it.
