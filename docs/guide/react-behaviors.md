# React + Drupal behaviors

React must not start from a plain `DOMContentLoaded` script. In Drupal, markup appears and disappears after the initial load, and
the thing that knows about it is `Drupal.behaviors`.

## The pattern

```jsx
Drupal.behaviors.recipeExplorer = {
  attach(context) {
    once('react', '.recipe-explorer', context)
      .forEach((element) => executeWhenVisible(element, mountReact, 'recipe-explorer'));
  },
};
```

Three pieces, each with a reason.

### 1. `Drupal.behaviors`

Drupal calls `attach(context)` on page load **and** every time it inserts new markup: after an ajax response (`use-ajax` links,
Views ajax, forms), when a modal opens, when BigPipe replaces a placeholder. Your component then mounts wherever it appears.
`context` is the inserted subtree, so you only look inside it.

### 2. `once('react', selector, context)`

`attach()` runs again for the same element (every ajax call attaches behaviors on the whole page region, not only on the new part).
`once()` is core's idempotency guard (it sets `data-once`). It returns only elements it has not processed under that id, so React never
mounts twice on one node. It works with a plain selector and does not need jQuery:

```js
once('react', '.my-widget', context)      // → array of elements, safe to forEach
```

### 3. `executeWhenVisible(element, callback, id)`

Widgets inside collapsed `<details>`, inactive tabs or closed modals have no size, and libraries that measure their container
(tables, charts) render wrongly there. `helpers.jsx` waits until the element is visible: if it has layout boxes it calls
`callback(element)` and re-attaches Drupal behaviors inside it, otherwise it observes the outermost hidden ancestor with a
`MutationObserver` (attribute changes like `class`, `style`, `hidden`, `open`) and tries again. A `WeakMap` ensures one observer per
ancestor and id. Use it for anything that is not trivially visible, it costs nothing when the element is visible.

## Why React is a global

`react_scaffold/react` loads `assets/react/react.js` and `react-dom.js` (UMD builds) once. Components are built with React as an
**external**: `import React from 'react'` becomes `window.React`. Benefits:

- N components on a page share one React (no duplicated 140 kB, no "two copies of React" hook errors),
- every component bundle stays small,
- the build of one component never depends on another.

React 18 is the last version with UMD builds, which is why `package.json` pins `^18.3.1`. See [Build](/guide/build).

## Passing data in

| Need | How |
|---|---|
| A few strings/numbers | `data-*` attributes on the mount element, read from `element.dataset` |
| Structured data | one `data-props` attribute with JSON, `JSON.parse(element.dataset.props)` |
| Translatable strings | `Drupal.t('Loading...')`, `Drupal.formatPlural(n, '1 item', '@count items')` |
| Site-wide values | `drupalSettings` (add via `hook_js_settings_alter()` or `#attached`) |
| Data from the server | [`apiClient`](/guide/ajax) calling a Views REST export |

## Unmounting (recommended addition)

The scaffold examples never unmount, which is fine for page-lifetime widgets. If your widgets live inside ajax-replaced regions,
add a `detach` so React cleans up when Drupal removes the markup:

```jsx
const roots = new WeakMap();

Drupal.behaviors.myWidget = {
  attach(context) {
    once('react', '.my-widget', context).forEach((el) => {
      const root = createRoot(el);
      roots.set(el, root);
      root.render(<MyWidget {...el.dataset}/>);
    });
  },
  detach(context, settings, trigger) {
    if (trigger !== 'unload') return;
    once.remove('react', '.my-widget', context).forEach((el) => {
      roots.get(el)?.unmount();
      roots.delete(el);
    });
  },
};
```

## Gotchas

- Put `import './x.scss'` in `index.jsx`, Vite extracts it to `assets/<name>.css`.
- Use `Drupal.t()` instead of hardcoded English; `Drupal` is a global.
- `window.executeWhenVisible`, `apiClient`, `rawHtml`... are globals from the shared libraries. Depend on `react_scaffold/react` (and
  `react-api-client`) in the component's `libraryOverrides.dependencies`, otherwise they are undefined.
