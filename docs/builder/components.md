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
| `format: date`, `time`, `date-time`, `email` | The browser's input for it | a string: `2026-11-12`, `09:30`, `2026-11-12T09:30`, an address |
| `type: array` whose `items` have an `enum` | Checkboxes: several of the choices | a list of the chosen values |
| `type: array` with `items` | A list: several values, added, dragged into order and removed, each with the field of its kind. See [Lists and groups](#lists-and-groups) | a list |
| `type: object` with `properties` | A group: the fields of its properties together | an object |

Other keywords:

| Keyword | Meaning |
|---|---|
| `help` (top level of the file) | help for editors about the whole component: a **?** at the end of its name in the right column shows it on a click. Plain text; an empty line starts a paragraph. `thirdPartySettings.frame_builder.help` works too |
| `title`, `description` | label and help text of the field. Write a description for every prop whose effect is not obvious from its label: it is all the guidance an editor gets |
| `examples: [value]` | the value a newly added component starts with (`default` is used when there are no examples) |
| `required` | marks the field, and a page is not saved while a required prop has no value: the message names the component and the field, also inside lists and groups (*Links › Links 2: Link is required.*). `minItems` is checked the same way |
| `x-builder-format: basic_html` | pins the text format of a rich text prop |
| `x-builder-icon-class: 'bi bi-{name}'` | how an icon of an icon picker is drawn; `{name}` is the enum value |
| `x-builder-translatable: false` | the prop is not translated in the *synced* translation mode |

Details worth knowing:

- **Optional props that are empty are not passed**, so `|default()` in Twig applies. Core does not apply schema defaults
  itself.
- **A choice left empty gets its declared default** (`default`, else the first example).
- **Tokens** work in text, link and rich text props: typing `[` suggests them step by step
  (the type, then the token, then what it leads to). In a text or link field the **T** button (shown on hover and
  focus) lists every token, filtered by the words typed, and inserts the one picked where the caret was; the **×**
  button next to it clears the field. They are replaced when the page is rendered, in the language of the page.
- **Link suggestions** are chosen with the mouse or with the keyboard: up and down move through them, Enter or Tab
  takes the one chosen, Escape closes them.
- **Links** picked from the suggestions are stored as `entity:node/N` and printed as the URL of that page in the language
  of the page being viewed, so they need no translating.
- **Rich text** is stored with its text format and filtered by it on output. A user can only use formats they have
  access to.
- An `object` without `properties` and an `array` without `items` have no field: the builder cannot tell what is
  in them.
- **How a schema is read.** `oneOf` and `anyOf`: the first part that names a type is the one offered. `allOf`: its
  parts together. `type: [integer, string]`: a text field, and the component gets a number when the text is one. A
  prop with `const` has no field and always gets that value. A prop that only points elsewhere (`$ref`) has no field.
  A prop named `id` is not passed: `id` is the id of the item in the layout.
- **Limits are checked when the page is saved**, with a message that names the component and the field: `required`,
  `minItems`, `maxItems`, `uniqueItems`, `minimum`, `maximum`, `multipleOf`, `minLength`, `maxLength`, `pattern`, the
  formats above, and a choice that is not one of the `enum`. Text with a token in it is not measured.
- **Text in square brackets** stays as typed. A token of a kind Drupal knows that has no value on this page
  (`[node:summary]` without a summary) leaves nothing.

### Lists and groups

A prop that takes several values is a field with several values, drawn as Drupal draws a multiple value field: a table
with the label in its header and a row for each value, with the field of its kind and a **Remove** button. **Add
another item** adds one. Values are put in order by dragging the handle of their row (or with the arrow keys while the
handle has the focus), or with the **Order** column that **Show row weights** shows. A row that was moved is marked
with an asterisk.

```yaml
links:
  type: array
  title: Links
  maxItems: 30            # no more can be added; minItems: no fewer can be left
  items:
    type: object
    properties:
      label: { type: string, title: Text }
      url: { type: string, title: Link, format: uri-reference }
      new_tab: { type: boolean, title: 'Open in a new tab' }
    required: [label, url]
```

```twig
{% for link in links %}
  <a href="{{ link.url }}"{% if link.new_tab %} target="_blank" rel="noopener"{% endif %}>{{ link.label }}</a>
{% endfor %}
```

- **What a value is** is the schema under `items`: any of the kinds of the table above. A plain kind
  (`items: { type: string }`) gives one field per value; an `object` gives its fields, folded under a line that shows
  the first text of the value.
- **A group** (`type: object` with `properties`) is the same without the list: fields that belong together, such as a
  picture with its caption. Lists and groups can be inside each other.
- Values are handled one by one, as props are: tokens are replaced, a link picked from the site's content follows
  the language of the page, rich text is filtered by its text format, an image is the URL of the picked media item
  in its image style. A value that ends up empty is left out, so the template gets only what has content.
- **Translations**, in the *synced* mode: a list with text or links in it is translated as a whole. On the
  translation its values are edited in place; adding, removing and moving are for the original.
  `x-builder-translatable: false` on the list keeps it the same in every language.
- An item of a list cannot have both a `type` and a `props` property: that is what an item of a slot looks like.
- **A list of lists** (the rows of a table: `items: { type: array, items: { type: string } }`) keeps empty values in
  the inner lists, so a cell without text stays a cell. Elsewhere a value that is empty is left out.
- **A group with nothing set** is not passed, whatever a choice in it defaults to.
- Lists and groups go four levels deep. Deeper, a prop has no field and is not passed.
- **A new value** starts with the `examples` (or `default`) of its properties, or, as a whole, with the first of the
  `examples` of `items`: `items: { type: object, properties: {…}, examples: [{ label: 'New link', url: '/' }] }`.
- **A value that is an object** has the fields of the object in its row, as Drupal's link widget has the URL and the
  link text. With more than four fields they fold under a line that names the value.
- **The line of a folded value** shows its first text. `x-builder-summary: label` on the list names the property to
  show instead.
- **An image with more than its URL.** `contentMediaType: image/*` in a list or a group gives the URL. A property of
  `type: object` with `x-builder-widget: image` gives an object: `url`, `alt`, `width`, `height`, and with a responsive
  image style `srcset` and `sizes`; when the schema names `properties`, only those. A property `sources` gets the
  images of the responsive image style with their media queries, `[{ media, url }]`, for components that choose the
  image themselves.

```yaml
picture:
  type: object
  title: Picture
  x-builder-widget: image
  properties: { url: { type: string }, alt: { type: string }, width: { type: integer }, height: { type: integer } }
```

The base theme's [Links](/builder/base-components) component is an example.

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

## Components that are no blocks

A theme has components that are parts of pages or of other components: a pagination, a loading indicator, an icon, a
tooltip. The builder does not need to offer them. There are two ways to leave one out of the list of blocks:

- **The theme**, in the component's file:

  ```yaml
  thirdPartySettings:
    frame_builder:
      offer: false
  ```

- **The site**, on *Configuration → Content authoring → Components of the page builder*
  (`/admin/config/content/frame-builder/components`): every component of the default theme and its base themes, by
  group, checked when it is offered. Needs the permission *Administer site configuration*.

A component left out is not in the list of blocks and is not offered to the AI assistant. A page that already has one
still shows it, and its fields can still be edited there. `noUi: true` (core) hides a component entirely: it is not on
the settings page either.

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
