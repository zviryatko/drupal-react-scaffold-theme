---
layout: home
hero:
  name: Frame
  text: The Last Page Builder You'll Ever Need. Drupal Edition.
  tagline: Editors build pages by dragging the components your theme already has. Drupal renders them. Works with SDC and UI Patterns 2 components, Paragraphs, Token, Metatag and an AI chat that builds the page for you. Free and open source.
  image:
    src: /frame-logo-animated.svg
    alt: Frame
  actions:
    - theme: brand
      text: Get started
      link: /guide/getting-started
    - theme: alt
      text: Watch the demo
      link: /demos
    - theme: alt
      text: See every feature
      link: /builder/gallery
    - theme: alt
      text: GitHub
      link: https://github.com/zviryatko/frame
features:
  - icon: 🧩
    title: Your SDCs are the blocks
    details: Every core Single Directory Component of your theme appears in the builder, with nothing to register. Props become fields, slots become drop zones. Components written for UI Patterns 2 are SDCs, so they are blocks too.
    link: /builder/components
    linkText: Components in the builder
  - icon: 💬
    title: An AI chat that builds the page
    details: Describe the page and the assistant composes it from your own components, looks at the result and corrects it. Then change anything by hand. Uses the AI module with the provider you choose.
    link: /builder/ai
    linkText: AI assistant
  - icon: 📚
    title: Paragraphs, with a shared library
    details: Every paragraph type is a block, edited with its own Drupal form. Save one to the Paragraphs Library and reuse it across pages, with revisions.
    link: /builder/paragraphs
    linkText: Paragraphs
  - icon: 🔤
    title: Tokens everywhere
    details: Type "[" in any text field for a token, or pick one in the token browser. Tokens of Drupal core, of the Token module and of any other module you have installed, replaced in the language of the page.
    link: /guide/page-builder
    linkText: The editor
  - icon: 🔎
    title: Metatag and SEO
    details: Landing pages get meta tags from the Metatag module, and the assistant can fill them. The page is server rendered HTML with Drupal's caching, so search engines see all of it.
    link: /builder/recipes
    linkText: Recipes
  - icon: ⚛️
    title: React for interactive components
    details: A component that needs behaviour in the browser is an SDC with a small React bundle. One React per page; it works after ajax, in modals and with BigPipe.
    link: /guide/components
    linkText: Creating a component
---

<div class="landing">

## Watch a page get built

The assistant composes a landing page from the theme's components. Then the hero, a button and a section are changed
by hand, a paragraph is dragged in, and the page is published.

<Video src="/media/page-builder-ai.mp4" poster="/media/screenshots/page-builder-ai.png" />

## Why this one is the last

<div class="pitch">
<div>

### It is your design system, not ours

The builder has no blocks of its own to restyle. It reads the components of your theme, and spacing, colours, type
scale, sizes and breakpoints from the theme's design tokens. Editors can only pick what the design allows, and your
SCSS uses the same values.

</div>
<div>

### It is Drupal all the way down

Fields with any formatter, views, blocks, the Media Library, responsive images, translations, revisions, content
moderation. The content form in the editor is Drupal's node form, with every widget.

</div>
<div>

### Nothing of the editor on the published page

Drupal renders the layout as normal SDC markup. Visitors load your components and nothing else: no editor code, no
runtime of the builder.

</div>
<div>

### No lock-in

Components are core SDCs and work in Twig without the builder. Content in paragraphs and fields stays in paragraphs
and fields. The layout is JSON on the page, with versions and migrations for your components.

</div>
</div>

## Works with what your site already runs

| | What you get in the builder |
|---|---|
| **Single Directory Components** (core) | every component of the theme and its base themes as a block; a ready set in the base theme: hero, card, columns, accordion, call to action… |
| **UI Patterns 2** | its components are core SDCs, so a theme's existing component library is offered as it is |
| **[Paragraphs](/builder/paragraphs)** and **Paragraphs Library** | paragraph types as blocks with their own form; shared paragraphs from the library, with revisions |
| **Token** | autocomplete and a token browser in text fields; core tokens, the Token module's and the ones other modules add |
| **[Metatag](/builder/recipes)** | meta tags on landing pages, filled by the assistant on request |
| **[AI](/builder/ai)** | a chat in the editor that builds and changes pages, with any provider of the AI module |
| **Media Library**, **Responsive Image** | pictures picked from the media library, with image styles and responsive image styles |
| **[Views](/builder/views)** and **blocks** | any view or Drupal block placed on the page; a view can be drawn by an interactive component |
| **Entity Usage** | where media, paragraphs and saved blocks are used |
| **Content Moderation**, **translations** | drafts, workflow states and translated layouts |

## In the editor

<div class="shots">
<Shot src="/media/builder/ai-assistant.png" title="AI assistant">Ask for a page, get one made of your components.</Shot>
<Shot src="/media/builder/component-fields.png" title="Fields from props">Each prop of a component is a field.</Shot>
<Shot src="/media/builder/layout-and-style.png" title="Layout and style">Spacing, size, alignment and background from the theme's tokens.</Shot>
<Shot src="/media/builder/paragraph.png" title="Paragraphs">A paragraph, edited with its own form.</Shot>
<Shot src="/media/builder/tokens.png" title="Tokens">The token browser.</Shot>
<Shot src="/media/builder/library.png" title="Library">Saved blocks, reused on other pages.</Shot>
<Shot src="/media/builder/preview-mobile.png" title="Preview">The page at the theme's breakpoints.</Shot>
<Shot src="/media/builder/history.png" title="History">Every change, one click to go back.</Shot>
<Shot src="/media/builder/published-page.png" title="Published">The page, rendered by Drupal.</Shot>
</div>

All of them, with explanations: [Gallery](/builder/gallery).

## One command sets a site up

With the theme in `themes/contrib/frame` ([Getting started](/guide/getting-started)), a recipe adds the Landing page
content type, media, responsive images, paragraphs with the library, meta tags, the assistant and a role for editors:

```bash
composer require drupal/paragraphs drupal/entity_usage drupal/field_group drupal/metatag drupal/ai
drush recipe themes/contrib/frame/recipes/frame_builder_full --input=frame_builder.theme=my_theme
```

Take only what you need: each part is [its own recipe](/builder/recipes). The whole way from an empty Drupal to a
published page is in [Build a site](/guide/build-a-site).

## The price

<div class="price">
<div class="price__tag">$0</div>
<div>

Free, GPL-2.0-or-later, for Drupal 10.3+ and 11. No licence key, no paid tier, no service to sign up for. The AI
assistant uses the provider and the key you configure in the AI module.

[Get started](/guide/getting-started) · [Source on GitHub](https://github.com/zviryatko/frame)

</div>
</div>

## Interactive components use React

Components that need behaviour in the browser (filters, tables, tooltips) are written in React and stay normal Drupal
components: place them in the builder or in Twig. The recipe explorer of the example theme: a Views REST export drives
filters, sorting and pager.

<Video src="/media/recipe-explorer-demo.mp4" poster="/media/screenshots/09-recipe-explorer-devtools.png" />

More in [Demos](/demos).

### This is a whole component

A React tooltip as a Drupal component in a subtheme: five small files. Nothing else to register, the build discovers the folder.

::: code-group

<<< ../examples/frame_demo/components/react-tooltip/react-tooltip.component.yml{yaml} [react-tooltip.component.yml]

<<< ../examples/frame_demo/components/react-tooltip/react-tooltip.twig{twig} [react-tooltip.twig]

<<< ../examples/frame_demo/components/react-tooltip/index.jsx{jsx} [index.jsx]

<<< ../examples/frame_demo/components/react-tooltip/TextWithTooltip.jsx{jsx} [TextWithTooltip.jsx]

<<< ../examples/frame_demo/components/react-tooltip/react-tooltip.scss{scss} [react-tooltip.scss]

:::

See the [full walkthrough](/examples/tooltip) and the bigger [recipe explorer](/examples/recipe-explorer).

</div>

<style>
/* The animated logo: the mark is a small part of its canvas, so the picture is larger than the default 320px. */
.VPHero .VPImage.image-src { width: 560px !important; max-width: 160vw !important; max-height: none !important; }
/* Its transparent canvas may reach past the page: no horizontal scroll for that. */
.VPHome { overflow-x: clip; }
.dark .VPHero .VPImage.image-src { filter: brightness(1.35) drop-shadow(0 0 20px rgb(255 255 255 / 18%)); }
@media (min-width: 960px) { .VPHero .VPImage.image-src { width: 900px !important; } }
.landing { margin-top: 3rem; }
.landing h2 { margin-top: 4rem; border-top: 0; padding-top: 0; font-size: 1.9rem; letter-spacing: -0.02em; }
.landing .pitch { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 26rem), 1fr)); gap: 1rem; margin-top: 1.5rem; }
.landing .pitch > div { padding: 0.25rem 1.5rem 0.75rem; border: 1px solid var(--vp-c-divider); border-radius: 12px; background: var(--vp-c-bg-soft); }
.landing .pitch h3 { margin-top: 1.25rem; }
.landing .price { display: flex; flex-wrap: wrap; align-items: center; gap: 2rem; margin-top: 1.5rem; padding: 1.5rem 2rem; border: 1px solid var(--vp-c-brand-1); border-radius: 12px; }
.landing .price__tag { font-size: 4rem; font-weight: 700; line-height: 1; color: var(--vp-c-brand-1); }
.landing .price > div:last-child { flex: 1 1 18rem; }
</style>
