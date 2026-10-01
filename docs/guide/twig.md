# Placing components in Twig

This follows the standard Drupal SDC documentation. The component ID is `<provider>:<folder name>`; for a theme the provider is the
theme machine name.

## `include`

```twig
{{ include('react_scaffold:recipe-explorer', {
  endpoint: path('view.recipe_explorer.better_rest_export_1'),
  heading: 'Recipes'|t,
}, with_context = false) }}
```

or the tag form:

```twig
{% include 'react_scaffold:recipe-explorer' with { endpoint: '/api/recipes' } only %}
```

Always pass `only` / `with_context = false`, otherwise the whole template context leaks into the component and its props.

## `embed` (for slots)

```twig
{% embed 'react_scaffold:react-tooltip' with { text: 'This is the tooltip text' } %}
  {% block content %}Hover me to see the tooltip{% endblock %}
{% endembed %}
```

## Render array (PHP)

```php
$build['explorer'] = [
  '#type' => 'component',
  '#component' => 'react_scaffold:recipe-explorer',
  '#props' => ['endpoint' => '/api/recipes'],
];
```

With slots, and in a preprocess hook (this is what the theme does for the page title):

```php
function react_scaffold_preprocess_page_title(&$variables) {
  $title = $variables['title'];
  // Slots accept render arrays or scalars only, titles may be markup objects.
  if (!is_array($title) && !is_scalar($title)) {
    $title = ['#markup' => $title];
  }
  $variables['title'] = [
    '#type' => 'component',
    '#component' => 'react_scaffold:react-tooltip',
    '#props' => ['text' => 'Tooltip text'],
    '#slots' => ['content' => $title],
  ];
}
```

## From a Views template (how the examples are mounted)

The data comes from a *Better REST export* display. The visible page is another display of the same view whose template only mounts the
component. `templates/views/views-view--recipe-explorer--page-1.html.twig`:

```twig
{% set classes = ['view', 'view-' ~ id|clean_class, 'view-id-' ~ id, 'view-display-id-' ~ display_id] %}
<div{{ attributes.addClass(classes) }}>
  {% include 'react_scaffold:recipe-explorer' with {
    endpoint: path('view.recipe_explorer.better_rest_export_1'),
    heading: 'Recipes'|t,
  } only %}
</div>
```

`path('view.<view_id>.<display_id>')` gives the REST URL including the language prefix, so the multilingual setup keeps working.

## Other places

- **Block / node / paragraph template**: the same `include`, with values from the template's variables.
- **Another theme or a module**: use the provider that owns the component: `my_module:component`, `other_theme:component`.
- **Storybook**: `*.story.twig` files use `include('react_scaffold:...')`, they are meant for a Storybook
  setup with a Drupal/Twig addon. The files are provided, Storybook itself is not configured here.
