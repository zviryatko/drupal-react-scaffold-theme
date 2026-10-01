# Troubleshooting

Problems hit while building this scaffold, with the fix.

| Symptom | Cause and fix |
|---|---|
| `Cannot use import statement outside a module` | Vite output has ES `import`s. Add `attributes: { type: module }` to the script in `libraryOverrides` / `libraries.yml`, or build to classic scripts (webpack sketch in [Build](/guide/build)) |
| Component script loads but `React`, `apiClient` or `executeWhenVisible` is undefined | Missing `react_scaffold/react` / `react_scaffold/react-api-client` in the component's `libraryOverrides.dependencies` |
| Old CSS/JS after a rebuild | Drupal keeps the `?query` token of asset URLs until a cache rebuild: `drush cr`. Turn aggregation off in development |
| `Unable to render component ... A render array or a scalar is expected for the slot` | The slot value was a markup object (e.g. `TranslatableMarkup` page title). Wrap: `['#markup' => $title]` |
| `/user/login` returns 500 after adding the page title component | Same as above: titles can be objects |
| Page display of a REST view: `Call to undefined method Page::getContentType()` | The page display inherits the REST *serializer* style. Override style and row on the page display (`defaults: { style: false, row: false }`) |
| Page display shows an empty title | Override the title on that display (`defaults: { title: false }`) |
| Each row appears twice in the API | A relationship (media) joins translations. Filter the related entity's language too |
| REST display ignores the pager / `total_pages` is 0 | The REST display has its own pager. Set it on the display (`defaults: { pager: false }`) |
| `views_better_rest` fatal `UrlNormalizer::normalize() must be compatible` | Drupal 11.4-dev change. Extend `NormalizerBase` instead of `ComplexDataNormalizer` in `UrlNormalizer` |
| Umami header (logo, menu) is empty in a subtheme | Umami's `region--header.html.twig` reads `elements.umami_branding` and `elements.umami_main_menu`. Block IDs are global, so a subtheme's blocks have other IDs: override the template with your block IDs |
| No blocks at all in the subtheme | Blocks are placed per theme. Ship them as `config/optional/block.block.*.yml` for your theme (or place them in the UI) |
| Ajax links inside React markup do nothing | Use `rawHtml()` / `attachBehaviors()` and add `core/drupal.ajax` to the library dependencies, see [Ajax](/guide/ajax) |
| `npm test`: `module is not defined in ES module scope` | `jest.config.js` must be `.cjs` when `package.json` has `"type": "module"` |
| `npm test`: `jest-environment-jsdom cannot be found` | Install it (`npm i -D jest-environment-jsdom`), it is not bundled since Jest 28 |
| React 19 and no `node_modules/react/umd` | React 19 dropped UMD builds. Stay on React 18 for this approach, or switch to bundling React per page (not covered here) |
