# Troubleshooting

Problems hit while building this scaffold, with the fix.

| Symptom | Cause and fix |
|---|---|
| `Cannot use import statement outside a module` | Vite output has ES `import`s. Add `attributes: { type: module }` to the script in `libraryOverrides` / `libraries.yml`, or build to classic scripts (webpack sketch in [Build](/guide/build)) |
| Component script loads but `React`, `apiClient` or `executeWhenVisible` is undefined | Missing `frame/react` / `frame/react-api-client` in the component's `libraryOverrides.dependencies` |
| Old CSS/JS after a rebuild | Drupal keeps the `?query` token of asset URLs until a cache rebuild: `drush cr`. Turn aggregation off in development |
| `Unable to render component ... A render array or a scalar is expected for the slot` | The slot value was a markup object (e.g. `TranslatableMarkup` page title). Wrap: `['#markup' => $title]` |
| `/user/login` returns 500 after adding the page title component | Same as above: titles can be objects |
| Blocks placed in `page_title`, `breadcrumbs`, `pre_header`... are disabled or sit in `sidebar_first` after installing the subtheme | Drupal does not inherit `regions:` from a base theme, the subtheme got core's default regions. Copy the `regions:` block of `frame.info.yml` into your `info.yml`, uninstall and install the theme again (or move the blocks) |
| `npm run build`: `Cannot find module '.../bin/frame.mjs'` | The path to the base theme in the subtheme's `package.json` scripts is wrong (the theme was moved, or the base theme folder has another name). Fix the three scripts |
| `The build tooling of the base theme is not installed` | Run `npm install` in the base theme folder (once) |
| Pages 404 on `assets/react/react.js`, or `f.attachBehaviors is not a function` after an update | The base theme's compiled `assets/` are missing or stale (they are not in git): run `npm install && npm run dist` in the base theme, or use a release archive |
| Jest: hooks error "Invalid hook call" or two Reacts | A dependency installed its own `react`. The shared Jest config (`npm test`) maps `react`/`react-dom` to the base theme copy |
| Contextual links or other core UI appear unstyled/open in your theme | Your CSS matched core markup (`nav ul { display: flex }` also matches `.contextual-links`). Exclude them: `ul:not(.contextual-links)` |
| Core's default markup has no classes on menus and blocks (the base theme has `base theme: false`) | Style by structure (`.page__header nav ul`) or override the Twig templates in your subtheme |
| Page display of a REST view: `Call to undefined method Page::getContentType()` | The page display inherits the REST *serializer* style. Override style and row on the page display (`defaults: { style: false, row: false }`) |
| Page display shows an empty title | Override the title on that display (`defaults: { title: false }`) |
| Each row appears twice in the API | A relationship (media) joins translations. Filter the related entity's language too |
| REST display ignores the pager / `total_pages` is 0 | The REST display has its own pager. Set it on the display (`defaults: { pager: false }`) |
| `views_better_rest` fatal `UrlNormalizer::normalize() must be compatible` | Drupal 11.4-dev change. Extend `NormalizerBase` instead of `ComplexDataNormalizer` in `UrlNormalizer` |
| Umami header (logo, menu) is empty in a subtheme | Umami's `region--header.html.twig` reads `elements.umami_branding` and `elements.umami_main_menu`. Block IDs are global, so a subtheme's blocks have other IDs: override the template with your block IDs |
| No blocks at all in the subtheme | Blocks are placed per theme. Ship them as `config/optional/block.block.*.yml` for your theme (or place them in the UI) |
| Ajax links inside React markup do nothing | Use `rawHtml()` / `attachBehaviors()` and add `core/drupal.ajax` to the library dependencies, see [Ajax](/guide/ajax) |
| `npm test`: `module is not defined in ES module scope` | Your own `jest.config.js` must be `.cjs` when `package.json` has `"type": "module"` |
| React 19 and no `node_modules/react/umd` | React 19 dropped UMD builds. Stay on React 18 for this approach, or switch to bundling React per page (not covered here) |
