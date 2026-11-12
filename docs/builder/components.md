# Components for the builder

Every Single Directory Component of your theme (and of its base themes) is offered in the builder. There is nothing to
register. This page is about what the builder reads from a `*.component.yml`, and what a component should do to work well
in it. For writing a component in the first place, see [Creating a component](/guide/components).

## A component, start to finish

```
components/promo/
├── promo.component.yml
├── promo.twig
└── promo.css
```

```yaml
# promo.component.yml
$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json
name: Promo
status: stable
description: 'A headline with a call to action over a picture.'
group: Content
props:
  type: object
  properties:
    heading:
      type: string
      title: Heading
      examples: ['Fresh every day']
    text:
      type: string
      title: Text
      contentMediaType: text/html
    url:
      type: string
      title: Link
      format: uri-reference
    image:
      type: string
      title: Picture
      contentMediaType: 'image/*'
      x-builder-picture: image_picture
    image_picture:
      type: string
      title: Picture markup
      x-builder-widget: hidden
    tone:
      type: string
      title: Tone
      enum: [calm, loud]
      meta:enum: { calm: Calm, loud: Loud }
      examples: [calm]
  required: [heading]
slots:
  actions:
    title: Actions
    description: 'Buttons or links under the text.'
thirdPartySettings:
  frame_builder:
    icon: card
```

```twig
{# promo.twig #}
<section {{ attributes.addClass('promo', 'promo--' ~ tone|default('calm')) }}>
  {% if image_picture %}<div class="promo__picture">{{ image_picture }}</div>{% endif %}
  <h2 class="promo__heading">{% if url %}<a href="{{ url }}">{{ heading }}</a>{% else %}{{ heading }}{% endif %}</h2>
  {% if text %}<div class="promo__text">{{ text }}</div>{% endif %}
  <div class="promo__actions">{% block actions %}{% endblock %}</div>
</section>
```

After a cache rebuild it is in the **Content** group of the Blocks panel, with five fields, a slot that takes other
components, and the features of the theme (spacing, alignment, background, position).

## Props become fields

The field of a prop is chosen from its JSON schema. The keywords are the ones Drupal Canvas uses where one exists, so a
component stays usable there.

| Schema of the prop | Field in the editor | The component receives |
|---|---|---|
| `type: string` | Text, with tokens | a string, tokens replaced |
| `enum` (+ `meta:enum` for labels) | Select | one of the values |
| `type: boolean` | Checkbox | `true` / `false` |
| `type: integer` / `number` | Number | a number |
| `format: uri` / `uri-reference` | Link: text, or content of the site by title or by path (type `/`) | a URL |
| `contentMediaType: image/*` | Image: media library, image style, loading, link | a URL, see [Images](#images) |
| `contentMediaType: text/html` | Rich text: CKEditor 5 of a Drupal text format | safe markup |
| `x-builder-widget: length` | A step of the theme's spacing scale, or a custom length | a CSS length: `1rem`, `12px` |
| `x-builder-widget: icon` + `enum` | Icon picker | one of the values |
| `x-builder-widget: color` | Swatches of the theme's palette | a CSS colour: `#008068` |
| `x-builder-widget: hidden` | none | filled by the builder |
| `x-builder-widget: textarea` | Multi-line text, with tokens | a string |

Other keywords:

| Keyword | Meaning |
|---|---|
| `title`, `description` | label and help text of the field. Write a description for every prop whose effect is not obvious from its label: it is all the guidance an editor gets |
| `examples: [value]` | the value a newly added component starts with (`default` is used when there are no examples) |
| `required` | marks the field |
| `x-builder-format: basic_html` | pins the text format of a rich text prop |
| `x-builder-icon-class: 'bi bi-{name}'` | how an icon of an icon picker is drawn; `{name}` is the enum value |
| `x-builder-translatable: false` | the prop is not translated in the *synced* translation mode |

Details worth knowing:

- **Optional props that are empty are not passed**, so `|default()` in Twig applies. Core does not apply schema defaults
  itself.
- **A choice left empty gets its declared default** (`default`, else the first example).
- **Tokens** work in text, link and rich text props: typing `[` suggests them step by step, *Browse available tokens*
  lists them. They are replaced when the page is rendered, in the language of the page.
- **Links** picked from the suggestions are stored as `entity:node/N` and printed as the URL of that page in the language
  of the page being viewed, so they need no translating.
- **Rich text** is stored with its text format and filtered by it on output. A user can only use formats they have
  access to.
- Props of the types `object` and `array` have no field yet and are not offered.

## Slots

A slot takes any other component. Give it a `title`; the `description` is a good place to say what belongs in it.

```twig
<div class="promo__actions">{% block actions %}{% endblock %}</div>
```

Two things follow from how the editor fills slots (see [Architecture](/builder/architecture#the-canvas)):

- **A slot that is laid out** (grid, flex) needs a second CSS selector for the editor. See
  [Your own layout component](/builder/layouts#your-own-layout-component).
- **A React island with a slot is static in the editor.** Mounting it would replace the slot. On the page it mounts as
  usual. If the island reads its slot as text (as `hello-react` and the tooltip do), nested components are flattened to
  their text on the page: use such slots for text.

## Images

An image prop holds a URL, so a component works with a plain `<img src>`. In the builder the editor picks the image in
Drupal's media library and chooses an image style or a **responsive image style**, and with `x-builder-picture` also how
it loads and what it links to. To get all of that, name a prop that receives the image **as Drupal renders it**:

```yaml
image:
  type: string
  contentMediaType: 'image/*'
  x-builder-picture: image_picture
image_picture: { type: string, title: 'Image markup', x-builder-widget: hidden }
```

```twig
{% if image_picture %}
  <div class="promo__picture">{{ image_picture }}</div>
{% elseif image %}
  <img class="promo__picture" src="{{ image }}" alt="">
{% endif %}
```

`image_picture` is then an `<img>` with the image style, or for a responsive image style a `<picture>` with a source per
breakpoint or an `<img srcset sizes>`, exactly as the style is configured, with `width`, `height`, `loading` and the
alternative text of the media item, wrapped in a link if one was chosen. Style it by its container
(`.promo__picture img { … }`).

A template that must write its own `<img>` can ask for the parts instead:

```yaml
image:
  type: string
  contentMediaType: 'image/*'
  x-builder-srcset: image_srcset   # all image styles of the responsive style, by width
  x-builder-sizes: image_sizes
  x-builder-alt: image_alt
```

Which image styles are offered is a [token of the theme](/builder/styling#tokens).

## Features and the root element

Spacing, alignment, text, background and position need no code in a component. They arrive as attributes of its root element,
so the template must print `{{ attributes }}` there, and must not replace its `style`:

```twig
<section {{ attributes.addClass('promo') }}>          {# good #}
<section class="promo">                               {# features are lost #}
<section {{ attributes.setAttribute('style', …) }}>   {# features are overwritten: put your own style on an inner element #}
```

A component changes which features it has, and names its icon, under `thirdPartySettings`:

```yaml
thirdPartySettings:
  frame_builder:
    features: { background: false, position: false }
    icon: card
```

See [Tokens, features and global styling](/builder/styling) for what each feature does.

## An example

Give a component an example of its typical use, as a layout item. The [AI assistant](/builder/ai) is shown it with the
component's description and follows its shape; it also documents the component for people.

```yaml
thirdPartySettings:
  frame_builder:
    example:
      type: 'my_theme:promo'
      props:
        heading: 'Fresh every day'
        tone: calm
        actions:
          - { type: 'frame:button', props: { label: 'Order', url: '/order', variant: primary } }
```

Use real-looking text, the props an editor would normally set, and for slots the components that usually go in them.
For images write `{ media: 12 }`: any number, it only shows the shape.

## The icon

`icon` is a name of the editor's set, or the CSS classes of an icon font your theme loads:

`component` (the default), `columns`, `flex`, `stack`, `image`, `text`, `card`, `section`, `tooltip`, `table`, `search`,
`link`, `button`, `form`, `video`, `list`, `heading`, `quote`, `map`, `slider`, `accordion`

```yaml
icon: quote          # from the set
icon: 'bi bi-star'   # classes of an icon font
```

Fields of the page, views and Drupal blocks each have one icon for all of them.

## React components

A React island needs nothing extra: the editor attaches Drupal behaviors to the HTML it shows, so the component mounts
and runs in the canvas (the recipe explorer loads its recipes there). Keep to what
[React + Drupal behaviors](/guide/react-behaviors) says, in particular `once()`, so it mounts once per element.

Props still travel as `data-*` attributes of the mount element. When a prop changes in the editor, Drupal renders the
component again and it mounts anew.

## Versions and migrations

Pages keep the props of a component as they were saved. When you rename a prop, change the values of a choice or
restructure a component, give it a new **version** and say how to get there. Old pages are then brought up to date when
they are read.

```yaml
thirdPartySettings:
  frame_builder:
    version: 3                           # the current schema version; 1 when not set
    migrations:
      2:                                 # from version 1 to 2
        rename: { title: heading, content: body }   # props and slots
        values: { tone: { big: loud } }             # old value: new value
        defaults: { size: m }                       # where the prop has no value
        remove: [subtitle]
      3:
        rename: { body: text }
```

The steps of one version are applied in the order rename, values, defaults, remove. What cannot be declared is done in
code, in a module or in your theme's `<theme>.theme`, after the declared changes of the same step:

```php
function my_theme_frame_builder_item_migrate_alter(array &$props, array $context) {
  if ($context['type'] === 'my_theme:promo' && $context['to'] === 3) {
    // Version 3 replaced the "wide" checkbox with a "width" choice.
    $props['width'] = !empty($props['wide']) ? 'full' : 'content';
    unset($props['wide']);
  }
}
```

How it works:

- Every item of a layout is stored with the version of its component at that moment; the layout itself has the version
  of the document format, which the module owns. Content saved before versions existed counts as version 1.
- A layout is migrated **when it is read**, for viewing and for editing, one step at a time (1 → 2 → 3). The migrated
  layout is stored the next time the page is saved. So keep the migrations for as long as content of that version can
  exist; after a rebuild of caches the new version applies at once, without a batch run.
- Translated text of a *synced* translation follows the prop it belongs to, also through renames.
- Unsaved drafts of editors are migrated the same way when they are restored.
- A change that old content still fits (a new optional prop, a new choice) needs no version.

## Check a component

1. `drush cr`, open a page in the builder: the component is in its group, with its icon.
2. Drag it in. It should be visible and selectable when empty; give an empty component a minimum height or a
   placeholder if it would collapse to nothing.
3. Every field changes the preview.
4. Set a spacing and a background colour: they apply to the root element.
5. Drop something into each slot, and next to it.
6. Publish and compare the page with the canvas.

A component of a base theme that your theme replaces (`replaces: frame:card`) is offered once, as your
version. Pages that used the base one show yours.

A component that is removed or renamed later does not break pages that use it: Drupal prints nothing for it, and the
editor shows a stand-in that can be selected and deleted.
