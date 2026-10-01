# Components (SDC)

Each widget lives in its own folder under `components/`. Drupal discovers it because it contains `<name>.component.yml`
(theme components are namespaced by the theme machine name: `react_scaffold:node-list`).

```
components/
  apiClient.js  common.js  helpers.jsx      # shared entries, not components
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

Why it is written like this is explained in [React + Drupal behaviors](/guide/react-behaviors).

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

## Slots

Use slots for content that comes from Drupal and may be markup, for example the tooltip's trigger text:

```yaml
# react-tooltip.component.yml
props: { type: object, properties: { text: { type: string } }, required: [text] }
slots:
  content: { title: Content }
```

```twig
<span {{ attributes.addClass('react-tooltip').setAttribute('data-text', text) }}>{% block content %}{% endblock %}</span>
```

The React side reads the rendered text (`element.innerText`) and replaces the element. Props are for values, slots for markup.
