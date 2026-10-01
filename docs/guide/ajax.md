# Ajax calls and HTML responses

## `apiClient`

The base theme (`src/apiClient.js`, library `react_scaffold/react-api-client`) defines `window.apiClient(url, query, params)`: a thin `fetch` wrapper.

```js
const data = await apiClient('/en/api/recipes', { difficulty: 'easy', page: 1 });
```

What it does:

- sets `Accept` / `Content-Type` to `application/vnd.api+json` and `X-CSRF-Token` to `drupalSettings.csrfToken`,
- appends `query` as a query string and **drops `null`/`undefined` values**, so clearing a filter is `{ difficulty: null }`,
- returns `response.json()`.

The CSRF token comes from the base theme: `hook_js_settings_alter()` in `react_scaffold.theme` (it also runs for subthemes) puts a token into `drupalSettings.csrfToken`,
which Drupal needs for non-GET REST requests from cookie-authenticated users.

```php
function react_scaffold_js_settings_alter(array &$settings, AttachedAssetsInterface $assets) {
  $settings['csrfToken'] = \Drupal::service('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);
}
```

Writes use the third argument (illustrative: the REST resource you call must be enabled and allow the method for the user):

```js
await apiClient('/node/1?_format=json', null, {
  method: 'PATCH',
  body: JSON.stringify({ title: [{ value: 'New title' }] }),
});
```

Use the URL from Drupal (`path('view.x.y')` in Twig) instead of hardcoding it, to keep language prefixes and base paths right.

### In a component: ignore out-of-order responses

```jsx
const requestId = useRef(0);
useEffect(() => {
  const id = ++requestId.current;
  apiClient(endpoint, query).then((response) => id === requestId.current && setData(response));
}, [endpoint, query]);
```

## When the response contains HTML

Views fields, rendered entities and Drupal links come back as HTML strings, with behaviors attached by Drupal on the server
side markup (`use-ajax` links, dropbuttons, contextual links). Rendering them with `dangerouslySetInnerHTML` alone leaves them dead,
because no `Drupal.attachBehaviors()` ran on the new nodes. The helpers fix that.

### `rawHtml(html)`: the easy way

```jsx
<Cell dataKey="operations">{(row) => rawHtml(row.operations)}</Cell>
```

`rawHtml` returns a component that renders the string and calls `attachBehaviors(el)` right after it mounts (`withDrupalBehaviors`,
with a 1 ms timeout to avoid a race with Drupal's ajax.js). Plain text is returned untouched.

### Manual control

```js
attachBehaviors(el);     // Drupal.attachBehaviors(el) + the ajax fix below
detachBehaviors(el);     // Drupal.detachBehaviors(el, drupalSettings, 'unload') + cleanup of data-once
reattachBehaviors(el);   // detach, then attach, after you replaced the HTML
```

```jsx
const ref = useRef();
useEffect(() => { reattachBehaviors(ref.current); }, [html]);
return <div ref={ref} dangerouslySetInnerHTML={{ __html: html }}/>;
```

Why the helpers do two extra things:

1. **`once.remove('ajax', 'body')`** before attaching. Core's `Drupal.behaviors.AJAX` binds every `.use-ajax` link with
   `Drupal.ajax.bindAjaxLinks(document.body)` guarded by `once('ajax', ...)` on `body`. After the first run, links that React adds later are
   skipped. Removing the guard makes the next `attach` bind the new links.
2. **Stripping `[data-once]`** in `detachBehaviors`. HTML produced by Drupal (or copied from a rendered page) can carry `data-once`
   attributes from a previous attach, so behaviors would think they already ran on those nodes and ignore them.

### Libraries for the HTML you render

Behaviors need their JS. If your HTML contains ajax links or dropbuttons, declare them in the component library:

```yaml
libraryOverrides:
  dependencies:
    - react_scaffold/react
    - react_scaffold/react-api-client
    - core/drupal.ajax        # use-ajax links, modals
    - core/drupal.dropbutton  # dropbuttons
```

Core's `drupal.ajax` / `drupal.dropbutton` depend on jQuery, so a page with them still loads it. The theme code itself does not use jQuery.

## Example: "Edit in Modal"

In the `content_rest` view of the example subtheme a *Custom text* field (`nothing`) outputs this HTML:

```html
<a class="use-ajax" data-dialog-type="modal" data-dialog-options='{"width":600}' href="/en/node/19/edit">Edit in Modal</a>
```

The Better REST export puts it into the JSON row as a string, and `rawHtml(row.nothing)` renders it inside the React table and attaches behaviors, so clicking it opens a core Drupal modal. In DevTools you can see
the `edit?_wrapper_format=drupal_modal` request: that is Drupal's own ajax, triggered from inside a React cell.

<Video src="/media/node-list-modal-demo.mp4" poster="/media/screenshots/10-node-list-modal-devtools.png" />
