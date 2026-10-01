# Recipe explorer: filters from a Views REST export

A complete example, from the `react_scaffold_demo` subtheme (all paths below are inside `examples/react_scaffold_demo/`): a React recipe browser on the Umami demo where **everything the UI shows comes from one View**.
Add a filter to the view and a new control appears, no React change.

<Video src="/media/recipe-explorer-demo.mp4" poster="/media/screenshots/09-recipe-explorer-devtools.png" />

## How it is built

| Layer | File |
|---|---|
| Data: View with a *Better REST export* display | `config/optional/views.view.recipe_explorer.yml` |
| Page: another display of the same view + template that mounts the component | `templates/views/views-view--recipe-explorer--page-1.html.twig` |
| Component (SDC) | `components/recipe-explorer/` |
| Library + assets | `libraryOverrides` in `recipe-explorer.component.yml`, built to `assets/recipe-explorer.{js,css}` |

Pages: `/recipe-explorer` (UI), `/api/recipes` (JSON).

## 1. The view

Create a view of *Content* (type *Recipe*), then:

1. **Fields**: title, summary, image (through a relationship to the media entity, formatter *Image URL*), difficulty, preparation time,
   category, and *Link to content* with **Output the URL as text** enabled (gives a plain URL, not an `<a>`).
2. **Exposed filters**: title *contains* (identifier `search`), difficulty (`difficulty`), category (`category`, taxonomy index, select),
   and a **grouped** numeric filter on preparation time (`time`) with items like "Up to 10 minutes".
3. **Exposed sorts**: created, title, preparation time.
4. **Pager**: full, 6 items.
5. Add display **Better REST export** at `api/recipes`. Style: *Better REST serializer*, row: *Data field* with aliases
   (`field_difficulty` &rarr; `difficulty`, `view_node` &rarr; `url`, ...). Set the **pager on this display** (the REST display does not inherit it).
6. Add display **Page** at `recipe-explorer` that only exists to host the template.

Key pieces from the exported config:

```yaml
better_rest_export_1:
  display_plugin: better_rest_export
  display_options:
    path: api/recipes
    style:
      type: better_rest_resources_serializer
      options: { formats: { json: json } }
    row:
      type: data_field
      options:
        field_options:
          field_difficulty:       { alias: difficulty, raw_output: true }
          field_preparation_time: { alias: prep_time,  raw_output: true }
          view_node:              { alias: url,        raw_output: false }
```

::: tip Language and duplicates
Content is translated (en/es in Umami). Add the *Content language = current content language* filter for nodes **and** for the media
relationship, otherwise every recipe appears once per media translation.
:::

## 2. The JSON the view produces

`GET /en/api/recipes?difficulty=easy&time=2&sort_by=title&sort_order=ASC` (trimmed):

```json
{
  "endpoint": {
    "path": "api/recipes",
    "args": [],
    "requested": "/en/api/recipes"
  },
  "pager": {
    "active": true,
    "current_page": 0,
    "total_items": "4",
    "items_per_page": 6,
    "total_pages": 1
  },
  "exposed_filters": [
    {
      "label": "Search",
      "description": "Words in the recipe title",
      "identifier": "search",
      "submitted_values": [],
      "options": []
    },
    {
      "label": "Difficulty",
      "identifier": "difficulty",
      "submitted_values": "easy",
      "options": {
        "easy": "Easy",
        "medium": "Medium",
        "hard": "Hard"
      }
    },
    {
      "label": "Category",
      "identifier": "category",
      "submitted_values": [],
      "options": {
        "29": "Accompaniments",
        "30": "Desserts",
        "33": "Starters",
        "31": "Main courses",
        "32": "Snacks"
      }
    },
    {
      "label": "Preparation time",
      "identifier": "time",
      "group_items": {
        "1": {
          "title": "Up to 10 minutes",
          "operator": "<="
        },
        "2": {
          "title": "Up to 20 minutes",
          "operator": "<="
        },
        "3": {
          "title": "Over 20 minutes",
          "operator": ">"
        }
      },
      "options": {
        "1": "Up to 10 minutes",
        "2": "Up to 20 minutes",
        "3": "Over 20 minutes"
      }
    }
  ],
  "exposed_sorts": [
    {
      "label": "Newest",
      "field_identifier": "created"
    },
    {
      "label": "Title",
      "field_identifier": "title"
    },
    {
      "label": "Preparation time",
      "field_identifier": "field_preparation_time_value"
    }
  ],
  "rows": [
    {
      "title": "Fiery chili sauce",
      "summary": "<p>A rich and fiery chili sauce. Take care when handling chili peppers. And serve sparingly!</p>\n",
      "image": "/sites/default/files/styles/medium_3_2_600x400/public/chili-sauce-umami.jpg.avif?itok=aQpGwsvL",
      "difficulty": "easy",
      "prep_time": 10,
      "category": "Accompaniments",
      "url": "/en/recipes/fiery-chili-sauce"
    }
  ]
}
```

The module adds what a UI needs next to `rows`:

| Key | Meaning |
|---|---|
| `exposed_filters[]` | one entry per exposed filter: `identifier` (= query param), `label`, `description`, `options` for selects or `group_items` for grouped filters, `submitted_values` |
| `exposed_sorts[]` | `field_identifier` and `label`. Pass `sort_by=<field_identifier>&sort_order=ASC|DESC` |
| `pager` | `current_page`, `total_pages`, `total_items`, `items_per_page`. Pass `page=<n>` |
| `rows[]` | the aliased fields |

## 3. The React side

The control for each filter is chosen from its shape:

```jsx
const filterType = (filter) => {
  if (filter.group_items && Object.keys(filter.group_items).length) return 'group';   // grouped filter → select of titles
  if (filter.options && Object.keys(filter.options).length) return 'select';          // select of options
  return 'text';                                                                       // no options → search input
};
```

State is one flat object that is exactly the query string:

```jsx
const [query, setQuery] = useState(readUrl);          // initial state from window.location.search
useEffect(() => {
  apiClient(endpoint, { ...query }).then(setData);    // null/'' values are dropped by apiClient
  window.history.replaceState(null, '', location.pathname + '?' + new URLSearchParams(query)); // shareable URLs
}, [endpoint, query]);
```

- Changing a filter resets `page`, changing `page` keeps the filters.
- The text input is debounced (300 ms), responses that arrive out of order are ignored.
- Accessibility: labelled controls, `role="search"`, `aria-live` result count, `aria-busy` while loading, `aria-current` on the pager.
- Summaries come from Drupal's `text_trimmed` formatter, so they are filtered markup and are rendered with `dangerouslySetInnerHTML`.

## 4. Mounting it from the View page

`templates/views/views-view--recipe-explorer--page-1.html.twig` replaces the Views output for the page display and includes the SDC
with the REST URL of the sibling display:

```twig
{% include 'react_scaffold_demo:recipe-explorer' with {
  endpoint: path('view.recipe_explorer.better_rest_export_1'),
  heading: 'Recipes'|t,
} only %}
```

## Full source of the component

The files below are imported from the repository, so they are always the real code.

::: code-group

<<< ../../examples/react_scaffold_demo/components/recipe-explorer/recipe-explorer.component.yml{yaml} [recipe-explorer.component.yml]

<<< ../../examples/react_scaffold_demo/components/recipe-explorer/recipe-explorer.twig{twig} [recipe-explorer.twig]

<<< ../../examples/react_scaffold_demo/components/recipe-explorer/index.jsx{jsx} [index.jsx]

<<< ../../examples/react_scaffold_demo/components/recipe-explorer/RecipeExplorer.jsx{jsx} [RecipeExplorer.jsx]

<<< ../../examples/react_scaffold_demo/components/recipe-explorer/recipe-explorer.scss{scss} [recipe-explorer.scss]

:::

## Adapting it

- **Another entity type**: change the base table and fields, keep the row aliases you use in `Card`.
- **Another filter**: add an exposed filter to the view. Selects, text and grouped filters show up automatically. A widget the component does not know (date range, multi-select) needs a new branch in `filterType`.
- **Routing without Views page**: put the component in any template or block, see [Twig](/guide/twig).
