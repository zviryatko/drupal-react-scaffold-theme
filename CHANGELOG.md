# Changelog

The public API of the base theme, which follows semantic versioning:

- library names: `react_scaffold/react`, `react_scaffold/react-api-client`, `react_scaffold/global-libraries`, `react_scaffold/global-styling`,
- globals: `apiClient`, `executeWhenVisible`, `rawHtml`, `attachBehaviors`, `detachBehaviors`, `reattachBehaviors`, `withDrupalBehaviors`,
- `drupalSettings.csrfToken`,
- regions and the CSS class names of `page.html.twig` (`.page`, `.page__header`, `.page__main`, ...),
- `vite.base.js` (`createViteConfig` options) and `jest.base.cjs`,
- the starterkit `react_scaffold_starterkit` used with `generate-theme`.

## 1.0.0

- The scaffold is a base theme (`base theme: false`, no dependency on stable9 or Umami) with subthemes. The Umami specific code moved to the
  example subtheme `examples/react_scaffold_demo`.
- Subthemes are generated with core's `generate-theme --starterkit react_scaffold_starterkit` (official starterkit mechanism).
- Shared Vite config (`createViteConfig`) and Jest config; a subtheme finds the base theme folder at build time (`base-theme.cjs`).
- Base theme runtime sources live in `src/`. Compiled `assets/` are not committed: releases (`release.yml`) build them and attach archives.
- `helpers.jsx` reads `window.Drupal` / `drupalSettings` lazily.
- Page layout template and neutral layout CSS in the base theme.
- Example subtheme: node-list headers are readable (`Author`, `Modal edit`), dates are short, the table shows all rows.
