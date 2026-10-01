# Changelog

The public API of the base theme, which follows semantic versioning:

- library names: `react_scaffold/react`, `react_scaffold/react-api-client`, `react_scaffold/global-libraries`, `react_scaffold/global-styling`,
- globals: `apiClient`, `executeWhenVisible`, `rawHtml`, `attachBehaviors`, `detachBehaviors`, `reattachBehaviors`, `withDrupalBehaviors`,
- `drupalSettings.csrfToken`,
- regions and the CSS class names of `page.html.twig` (`.page`, `.page__header`, `.page__main`, ...),
- `react-scaffold/vite` (`createViteConfig` options) and `react-scaffold/jest`,
- the generator `scripts/create-subtheme.mjs` and the `starter/` layout.

## 1.0.0

- The scaffold is a base theme (`base theme: stable9`) with subthemes: the Umami specific code moved to the
  example subtheme `examples/react_scaffold_demo`.
- Shared Vite config (`createViteConfig`) and Jest config for subthemes, `npm run create-subtheme` generator and `starter/` template.
- Base theme runtime sources live in `src/` and the built `assets/` are committed.
- `helpers.jsx` reads `window.Drupal` / `drupalSettings` lazily.
- Page layout template and neutral layout CSS in the base theme.
