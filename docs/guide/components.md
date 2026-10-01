# Creating a component

Components live in your **subtheme** (see [Base theme and subthemes](/guide/base-theme)). The base theme only provides the React runtime and helpers that
`react_scaffold/react` attaches. Below, `my_theme` stands for your subtheme.

Each widget lives in its own folder under `components/`. Drupal discovers it because it contains `<name>.component.yml`
(theme components are namespaced by the theme machine name: `my_theme:node-list`).

```
components/
  recipe-explorer/
    recipe-explorer.component.yml           # SDC metadata: props, slots, library
    recipe-explorer.twig                    # server markup: the mount point
    index.jsx                               # entry: Drupal behavior that mounts React
    RecipeExplorer.jsx                      # the React component
    recipe-explorer.scss                    # imported from index.jsx
    recipe-explorer.story.twig              # optional, for Storybook
    __tests__/recipe-explorer.test.js       # optional, jest
```

## The three files that make a component

### `*.component.yml`

```yaml
$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json
name: Recipe Explorer
status: stable
group: Content
props:
  type: object
  properties:
    endpoint:
      type: string
      default: '/api/recipes'
    heading:
      type: string
      default: 'Recipes'
  required:
    - endpoint
libraryOverrides:
  js:
    ../../assets/recipe-explorer.js: { attributes: { type: module } }
  css:
    theme:
      ../../assets/recipe-explorer.css: { }
  dependencies:
    - react_scaffold/react
    - react_scaffold/react-api-client
```

- **`props`** are validated by core when the component renders. Wrong type or a missing required prop is an error, which is what you want
  while developing.
- **`libraryOverrides`** is the SDC way to attach assets. Core builds a library for the component and attaches it **only on pages where
  the component renders**. Paths are relative to the component folder, and the Vite output lives in the theme's `assets/`, hence `../../assets/`.
  (Core turns them into `/core/../themes/...` URLs, which browsers normalize, this is core behavior.)
- **`dependencies`** pull the global React libraries in before your entry runs.
- Because the Vite output is ES modules (shared chunks), the script needs `attributes: { type: module }`. See [Build](/guide/build).

::: tip Convention alternative
SDC also auto-attaches `recipe-explorer.js` and `recipe-explorer.css` found *next to* the twig file. That works if your build writes
there. This scaffold writes everything to `assets/` (one place to git-ignore and deploy), so it uses `libraryOverrides`.
:::

### `*.twig`: the mount point

```twig
<div {{ attributes.addClass('recipe-explorer').setAttribute('data-endpoint', endpoint).setAttribute('data-heading', heading|default('Recipes')) }}>
  <noscript>{{ 'The recipe explorer needs JavaScript.'|t }}</noscript>
</div>
```

Twig renders an empty, accessible-by-default element and passes props to React as `data-*` attributes. `attributes` is provided by SDC, so
callers can add classes and attributes. For structured data, build one JSON attribute (`{{ { items: items, mode: mode }|json_encode }}` into `data-props`) and `JSON.parse` it in `index.jsx`.

### `index.jsx`: the Drupal behavior

```jsx
import { RecipeExplorer } from './RecipeExplorer';
import './recipe-explorer.scss';
import { createRoot } from 'react-dom/client';

(function (Drupal, once) {
  const attach = (element) => {
    createRoot(element).render(
      <RecipeExplorer endpoint={element.dataset.endpoint} heading={element.dataset.heading}/>
    );
  };

  Drupal.behaviors.recipeExplorer = {
    attach(context) {
      once('react', '.recipe-explorer', context)
        .forEach((element) => executeWhenVisible(element, attach, 'recipe-explorer'));
    },
  };
})(Drupal, once);
```

Keep `index.jsx` thin: it only wires Drupal to React. Why it is written like this is explained in [React + Drupal behaviors](/guide/react-behaviors).

## Add a new component, step by step

1. **Folder**: `components/my-widget/`.
2. **`my-widget.component.yml`**: name, props, `libraryOverrides` pointing to `../../assets/my-widget.js` and `.css`
   (the file names come from the folder name, see below), dependency on `react_scaffold/react`.
3. **`my-widget.twig`**: the mount element with a class and `data-*` props.
4. **`index.jsx`**: copy the behavior above, change the selector, behavior name and the component.
5. **`MyWidget.jsx`** and **`my-widget.scss`**.
6. `npm run dist`. The Vite config scans `components/*/index.jsx` and names the bundle after the folder, so
   `my-widget/` produces `assets/my-widget.js` and `assets/my-widget.css`.
7. `drush cr`, then [place it in Twig](/guide/twig).

The folder name is also the bundle name, so keep the three in sync: folder `my-widget`, bundle `assets/my-widget.js`, and the path in `libraryOverrides`.

## Props or slots?

SDC gives you two ways to pass content in. Choose by *what the value is*:

| Use a **prop** when | Use a **slot** when |
|---|---|
| it is a value: string, number, boolean, enum, URL, ID | it is content: markup, links, a render array, translated rich text |
| you want core to validate it (type, enum, required, default) | the caller may want to put any HTML or other components inside |
| React needs it as data (`endpoint`, `variant`, `limit`) | Drupal should render it (page title, field output, a block) |
| it is small and safe in an HTML attribute | it could contain user content you do not want to double-encode |

Rules of thumb:

- **Config for React → props.** `endpoint`, `variant`, `heading`. They end up in `data-*` attributes and `element.dataset`.
- **Anything Drupal renders → slots.** Never pass rendered HTML as a prop string, it is escaped and bypasses Drupal's render pipeline.
- **Enums over free text** for anything that changes behavior (`variant: light | dark`), the schema documents and enforces them.
- **Optional props need `|default()` in twig.** The `default:` in `component.yml` documents the value, but core SDC does **not** apply it to the Twig variables: an omitted
  prop is simply undefined, and `data-start="{{ start }}"` becomes an empty attribute (`Number(undefined)` is `NaN` in JS). Write `start|default(0)` in the template and, for numbers, `Number(el.dataset.start) || 0` in `index.jsx`.
  Keep the same default in `component.yml` so the documentation matches.
- **Prop values are strings in the browser.** `data-limit="10"` arrives as `"10"`. Parse numbers and booleans in `index.jsx`, or send
  structured data as one JSON attribute and `JSON.parse` it.
- **Slot content is server markup.** React replaces the element's children when it mounts, so read what you need first
  (`element.innerText`, `element.innerHTML`) and render it yourself. The tooltip does this with its trigger text.

### Slot example (tooltip)

```yaml
# react-tooltip.component.yml
props: { type: object, properties: { text: { type: string } }, required: [text] }
slots:
  content: { title: Content }
```

```twig
<span {{ attributes.addClass('react-tooltip').setAttribute('data-text', text) }}>{% block content %}{% endblock %}</span>
```

`text` is a value React needs, `content` is whatever Drupal wants shown in the span.

## Gotchas and advice

**Naming and wiring**

- The **folder name is the bundle name**: folder `my-widget` → `assets/my-widget.js`/`.css`. The path in `libraryOverrides`,
  the folder and the component ID (`my_theme:my-widget`) must agree. A typo gives a 404 for the script, not an error.
- **`index.jsx` is required** for the build to pick the folder up (`index.js` also works). Other files are free.
- **One behavior per component**, named uniquely (`Drupal.behaviors.myWidget`). A clashing name silently replaces another behavior.
- Use a **specific mount class** (`.my-widget`) in `once()`. A generic selector mounts React into unrelated markup.
- The `once()` id (`'react'`) can be shared: `once` tracks element and id together, different elements never conflict.

**Libraries**

- Always depend on `react_scaffold/react` (and `react_scaffold/react-api-client` if you call `apiClient`). Without it `React`, `apiClient`
  and `executeWhenVisible` are undefined and the component fails silently.
- If the HTML you render contains ajax links or dropbuttons, add `core/drupal.ajax` / `core/drupal.dropbutton`, see [Ajax](/guide/ajax).
  These pull jQuery in through core, your code does not need it.
- Scripts that are ES modules need `attributes: { type: module }`, see [Build](/guide/build).

**JavaScript**

- `import './my-widget.scss'` in `index.jsx`. Vite extracts the CSS into `assets/my-widget.css`. Third-party CSS (`react-tippy/dist/tippy.css`)
  must be imported the same way, it is not automatic.
- Use `Drupal.t()` / `Drupal.formatPlural()` for text. `Drupal`, `once` and `drupalSettings` are globals, do not bundle them.
- React is an external, so it is not bundled: `import { useState } from 'react'` is fine, it resolves to the global `React`. Do not add a second React to a component's dependencies.
- Keep React component files free of Drupal globals where you can. Pass `endpoint` and text in as props, so Jest tests do not need Drupal.
- Handle loading, error and empty states. The server markup is a bare mount element, users see it until your first render.

**Markup and accessibility**

- Put server-rendered fallback inside the mount element (`<noscript>`, or real content that React replaces). It is what search engines and no-JS users get.
- Prefer real form controls, labels and `aria-live` for results. React does not add them for you.
- SDC `attributes` lets callers add classes and attributes, keep `attributes.addClass(...)` in twig instead of hardcoding a bare `<div>`.

**Workflow**

- After editing `*.component.yml`, twig or `libraries.yml`: `drush cr`. After editing JSX/SCSS: rebuild (`npm run watch` does it).
- Component props are validated on every render, a wrong type is a visible error. Fix the schema or the caller, do not loosen the schema to silence it.
- If something does not show up, follow the checklist in [Build](/guide/build#checklist-when-a-component-does-not-show-up).
