# Views in the builder

A view becomes something an editor can place on a page in two steps: give it a display of the type **Component**, and
choose how that display is drawn. It can be drawn by Views as usual (a list, a table, a grid), or by one of your
**interactive components**, which then gets its data from a REST display of the same view.

The module adds two Views plugins for this:

| Plugin | Kind | What it is for |
|---|---|---|
| **Component** | display | Offers the view in the builder. No path, no block: it only exists to be placed. |
| **React component (SDC)** | style | Draws a display as a component that reads a REST export of the view. |

## Offer a view in the builder

1. Open the view, **Add → Component**.
2. Configure it like any display: fields, filters, sort, pager, style.
3. Save. The view is now in the **Views** group of the Blocks panel, under its name (with the display's name when a view
   has more than one such display).

Only displays of this type are offered, and only they are rendered, whatever a layout says. Pages, blocks and feeds of a
view stay what they are. That way a view is made available to editors on purpose.

Placed on a page, the display is rendered as Views renders it, with the user's access to the view. With a normal style
(*Unformatted list*, *Table*, *Grid*…) that is a server-rendered list: exposed filters reload the page, and that is
often all you need.

## An interactive view

For filters, sorting and paging without page loads, the view is drawn by a component, and the component asks the view
for data as JSON:

```
Component display ── style "React component (SDC)" ──► <div class="recipe-explorer" data-endpoint="/en/api/recipes">
                                                              │  fetch
REST export display of the same view  ◄──────────────────────┘  JSON: rows, exposed filters, sorts, pager
```

### 1. A REST display

Add a display that exports the view as JSON, with a path, for example `api/recipes`:

- **Better REST export** (module [`views_better_rest`](https://www.drupal.org/project/views_better_rest)) is the one to
  use: besides the rows it returns the definitions of the exposed filters and sorts and the pager, so the component can
  build its controls from the view. Add a filter to the view and a control appears, with no change to the component.
- Core's **REST export** works too, for a component that only needs rows.

Set fields, aliases and the pager **on this display**; a REST display does not inherit the pager. The
[recipe explorer example](/examples/recipe-explorer) walks through such a view and shows the JSON.

::: tip views_better_rest 1.3
Install version 1.3 or newer (`composer require 'drupal/views_better_rest:^1.3'`): it supports Drupal 11, and views of
Search API with Facets.
:::

### 2. The style

On the **Component** display choose **Format → React component (SDC)**:

| Setting | |
|---|---|
| **Component** | The component that draws the view. Listed: components that have a prop named `endpoint`. |
| **REST display** | The REST display of this view to read. |
| **Extra props (JSON)** | Other props of the component, for example `{"heading": "Recipes"}`. |

With this style the display runs **no query and prints no rows**: it outputs the component, with the URL of the REST
display as its `endpoint` prop and the extra props. The exposed form of the display is not printed either; the component
draws the controls.

The style is not tied to the builder. It works on a *Page* or *Block* display as well, which replaces a template override
such as the one the recipe explorer example uses to mount its component.

### 3. The component

The contract is small: **a prop `endpoint`**, a URL the component fetches.

```yaml
# recipe-explorer.component.yml
props:
  type: object
  properties:
    endpoint:
      type: string
      title: Endpoint
      examples: ['/api/recipes']
    heading:
      type: string
      title: Heading
  required: [endpoint]
```

```twig
<div {{ attributes.addClass('recipe-explorer').setAttribute('data-endpoint', endpoint).setAttribute('data-heading', heading|default('Recipes')) }}></div>
```

The React side reads `element.dataset.endpoint`, fetches it with the query string for filters, sort and page, and renders
what comes back. [Recipe explorer](/examples/recipe-explorer) is a complete one: it builds every control from the
response of *Better REST export*, keeps the state in the URL, and is about 190 lines. Use it as the starting point for
your own listing.

A component written this way can be used three ways with the same code: placed in the builder as a view, placed in the
builder as a component with a typed `endpoint`, and included from Twig.

## On the page and in the editor

- The component runs in the editor's canvas as on the page: it loads its data and its controls work.
- A view block has no settings of its own in the editor, only the features every block has (spacing, alignment,
  background, position). What the view shows is configured in Views.
- The preview of a view that prints nothing (no results, no access) is a placeholder, so it can still be selected.

## Limits

- **No contextual filters.** A view is rendered without arguments, so it cannot yet follow the page it is on (for
  example "related to this node").
- **One REST display per component display.** The component gets one `endpoint`.
- Filters a component keeps in the URL (as the recipe explorer does) are shared by all instances on a page.
