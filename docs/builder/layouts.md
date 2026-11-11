# Layouts

A page is built by nesting: a layout component has a slot, and what is put in the slot is arranged by it. The base theme
ships the layout components below, in `components/`, so every subtheme has them. They are Twig and CSS only.

## The components

### Columns

A grid with a fixed number of columns. Every item is one cell; more items than columns continue on the next row.

| Option | Values |
|---|---|
| **Columns** | 2, 3 or 4 equal columns; 1/3 + 2/3, 2/3 + 1/3, 1/4 + 3/4, 3/4 + 1/4 |
| **Gap** | a step of the theme's spacing scale, or a custom length |
| **Vertical alignment** | same height, top, middle, bottom |
| **One column on small screens** | on by default: below 48em the grid becomes one column |

### Flex

A flex container: items in a row or a column, each as large as its content.

| Option | Values | CSS |
|---|---|---|
| **Direction** | row, row reversed, column, column reversed | `flex-direction` |
| **Along the direction** | start, centred, end, spread apart, space around each, equal space everywhere, grow to fill equally | `justify-content`; *grow* is `flex: 1` on the items |
| **Across the direction** | stretched, start, centred, end, text baseline | `align-items` |
| **Gap** | a step of the scale, or a custom length | `gap` |
| **Wrap** | on by default | `flex-wrap` |

A row follows the reading direction, so it runs right to left in a right-to-left language.

### Stack

Items below each other with the same gap, optionally with a line between them.

| Option | Values |
|---|---|
| **Gap** | a step of the scale, or a custom length |
| **Alignment** | full width, start, centred, end |
| **Lines between the items** | off by default |

### Image

Not a layout, but in the same folder: an image from the media library with an image style or responsive image style,
lazy or eager loading, a link and an optional caption.

### Section, Hero, Container and others

The base theme also has a *Section* (a titled container), a *Hero*, a *Container* (a width), a *Divider* and a
*Spacer*: see [Components of the base theme](/builder/base-components). Any component with a slot is a container.

## Using them in the editor

- **Nest freely.** Columns in a Section, a Stack in a column, a Flex of buttons in a Card.
- **Drop into a slot** by dragging a block from the Blocks panel onto it. An empty slot shows as a dashed area.
- **Drop next to something** that has a slot of its own (a Card in Columns) by aiming at the gap between the items, or at
  the strip at the top or bottom of the container. A drop *on* the component goes *into* it.
- **Reorder** by dragging in the canvas or in the **Structure** panel, which shows the tree.
- **Gaps and spacing come from the theme's scale**, so layouts on different pages line up.

## Position

Every component has a **Position** section (a [feature](/builder/styling#features); a theme or a component can switch it
off). Three kinds:

| Kind | What it does | CSS |
|---|---|---|
| **In the flow** | the default: where its container puts it | none |
| **Placed** | one of nine places in the free space of its spot: top / middle / bottom and left / centre / right | automatic margins: `margin-block`, `margin-inline`; placed horizontally, the component is as wide as its content |
| **Pinned** | on a corner of the component it is in, over its content, at a distance from the scale; optionally centred on the corner | `position: absolute` with `top`/`bottom`, `left`/`right`; the parent gets `position: relative` |

*Placed* is how CSS aligns one item in a flex or grid cell, so it does what you expect in Columns, Flex and Stack.
Vertical places need room: a component is only pushed down if its row or cell is taller than it. Left and right swap in
right-to-left languages.

*Pinned* is for badges, labels and tooltips on a card or an image. A pinned component leaves the flow, so its parent
does not make room for it.

## Your own layout component

A layout component is a component with a slot whose CSS arranges the slot's children.

```yaml
# components/grid/grid.component.yml
name: Grid
group: Layout
props:
  type: object
  properties:
    gap:
      type: string
      title: Gap
      x-builder-widget: length     # a step of the theme's scale, or a custom length
      examples: ['1rem']
slots:
  content:
    title: Content
thirdPartySettings:
  react_scaffold_builder:
    icon: columns
```

```twig
<div {{ attributes.addClass('my-grid') }}>
  <div class="my-grid__items"{% if gap %} style="--my-gap: {{ gap }}"{% endif %}>
    {%- block content %}{% endblock -%}
  </div>
</div>
```

Three rules, all visible in the base theme's components:

**1. The slot goes in an inner element.** The root element's attributes belong to whoever places the component: the
builder puts spacing, background and position there. Your own inline style (the gap) goes on the inner element.

**2. Two selectors for the element that lays out.** On the page the items are children of `.my-grid__items`. In the
editor the slot holds a marker with the editor's drop zone in it, and the items are children of that drop zone. So the
drop zone must be the grid there:

```css
.my-grid__items,
.my-grid__items > [data-rsb-slot] > [data-puck-dropzone] {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
  gap: var(--my-gap, 1rem);
}
/* In the editor the inner element holds only the marker: it must not be a grid of one. */
.my-grid__items:has(> [data-rsb-slot]) { display: block; }
```

Custom properties set on the inner element (`--my-gap`) are inherited by the drop zone, so one rule serves both.

Rules for the *items* need the same treatment. In the editor an item is wrapped, so `> *` is Puck's wrapper of it:

```css
.my-grid__items > *,
.my-grid__items > [data-rsb-slot] > [data-puck-dropzone] > * { min-width: 0; }
```

**3. A length prop is a CSS length.** `x-builder-widget: length` gives the editor the theme's scale, and the template a
value such as `1rem` or `12px` that was checked. Used from Twig without the builder, pass a CSS length yourself.

Pick class names that cannot collide with a library you load. The base theme uses `layout-…`; an earlier `rs-…` clashed
with a React UI library that styles `.rs-stack`.

## Changing the base theme's layouts

They are ordinary components, so the usual ways apply:

- **Restyle**: override their classes (`.layout-columns__items`, `.layout-flex__items`, `.layout-stack__items`) in your
  theme's CSS, for example the width below which Columns become one column.
- **Replace**: copy a component into your theme and declare `replaces: react_scaffold:columns` in its `*.component.yml`.
  The builder then offers your version instead of the original, and existing layouts that use the original show
  yours.
- **Add**: write your own, as above. It appears next to them in the **Layout** group.
