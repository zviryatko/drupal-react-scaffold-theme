# Node list: React table with Drupal ajax inside

`components/node-list`, page `/node-list`. Shows how React and Drupal's own ajax/dialog system live together.

<Video src="/media/node-list-modal-demo.mp4" poster="/media/screenshots/10-node-list-modal-devtools.png" />

## Parts

- **View** `content_rest` (`config/optional/views.view.content_rest.yml`): *Better REST export* at `/api/node` with an exposed **Content type** filter
  and a **Created** sort, plus a Page display at `/node-list` with a template that mounts the SDC.
- **Component**: rsuite `Table`, `SelectPicker` and `Form`. Columns are generated from the keys of `rows[0]`. A column is sortable if
  its key is in `exposed_sorts[].field_identifier`.
- **HTML cells**: the view's `operations` (dropbutton) and `nothing` (custom text with a `use-ajax` link) fields are HTML strings.
  The cell renders them with `rawHtml(row[col])`, which also attaches Drupal behaviors, see [Ajax](/guide/ajax#when-the-response-contains-html).
- **Look**: `node-list.scss` themes rsuite with Umami's colors and fonts through rsuite's CSS variables.

```jsx
<Cell rowKey="nid" dataKey={col}>{(row) => rawHtml(row[col])}</Cell>
```

```yaml
# node-list.component.yml, libraries needed by the HTML inside the cells
libraryOverrides:
  dependencies:
    - react_scaffold/react
    - react_scaffold/react-api-client
    - core/drupal.ajax
    - core/drupal.dropbutton
```

## Full source

::: code-group

<<< ../../components/node-list/node-list.component.yml{yaml} [node-list.component.yml]

<<< ../../components/node-list/node-list.twig{twig} [node-list.twig]

<<< ../../components/node-list/index.jsx{jsx} [index.jsx]

<<< ../../components/node-list/NodeList.jsx{jsx} [NodeList.jsx]

<<< ../../components/node-list/node-list.scss{scss} [node-list.scss]

:::

## Look in DevTools

Filter the Network tab by **Fetch/XHR**:

- `node?sort_order=...&type=recipe` are `apiClient` calls from React (initiator `apiClient.js`),
- `edit?_wrapper_format=drupal_modal` is Drupal's ajax opening the node form (initiator `jquery.min.js`, because core's ajax uses jQuery).

![Node list with DevTools](/media/screenshots/10-node-list-modal-devtools.png)

"Edit in Modal" needs permission to edit the node, log in to see it working.
