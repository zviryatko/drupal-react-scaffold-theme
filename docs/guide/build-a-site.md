# Build a site

From an empty Drupal 11 to a site where editors build pages from your components. Each step links to the page that
explains it; this page is the order to do them in.

You need PHP 8.3+, Composer, Node 22+ and drush.

## 1. Drupal and the base theme

```bash
composer create-project drupal/recommended-project my-site && cd my-site
composer require drush/drush
vendor/bin/drush site:install minimal --db-url=sqlite://sites/default/files/.ht.sqlite -y   # or your database

# The base theme, from a release archive (compiled) or from git (build it), see "Getting started".
mkdir -p web/themes/contrib && cd web/themes/contrib
git clone https://github.com/zviryatko/frame.git frame
cd frame
npm install && npm run dist                           # the runtime and the build tooling for subthemes
npm run builder:install && npm run builder:build      # the editor of the page builder
```

Details and the other ways to install: [Getting started](/guide/getting-started).

## 2. Your theme

```bash
# from the project root
node web/themes/contrib/frame/bin/frame.mjs new my_theme --name "My Theme"
cd web/themes/custom/my_theme && npm run build
```

You get a theme with an info file, a stylesheet, one React component and a test. It has no build configuration:
`npm run build`, `watch` and `test` call the base theme. See [Create a subtheme](/guide/create-your-theme).

## 3. Design tokens, once

Open `my_theme.info.yml` and put your design system into the `frame_builder` block: the spacing scale, the
palette, the type scale.

```yaml
frame_builder:
  uikit:
    spacing: { xs: 0.25rem, s: 0.5rem, m: 1rem, l: 2rem, xl: 4rem }
    colors:
      primary: { label: 'Primary', value: '#0b5fff' }
      ink: { label: 'Ink', value: '#1b1f29' }
      paper: { label: 'Paper', value: '#ffffff' }
    font_sizes: { s: 0.875rem, m: 1rem, l: 1.25rem, xl: 1.5rem, 2xl: 2rem }
    sizes: { s: 30rem, m: 45rem, l: 60rem, xl: 75rem, full: 100% }
```

Editors pick from these in the builder, and your SCSS reads the same file:

```scss
// scss/global.scss
@use 'frame/tokens' as frame;

@include frame.rules;        // custom properties and utility classes for every choice of the builder

body { color: frame.color(ink); font-size: frame.font-size(m); }
.page__main { padding: frame.space(m); @include frame.breakpoint(desktop) { padding: frame.space(xl); } }
```

Breakpoints come from the base theme (mobile, tablet at 48em, desktop at 75em) until you add a
`my_theme.breakpoints.yml`. See [Tokens, features and styling](/builder/styling).

## 4. Set the site up with a recipe

```bash
# from the project root
composer require drupal/paragraphs drupal/entity_usage drupal/field_group drupal/metatag drupal/ai
cd web
../vendor/bin/drush theme:install my_theme
../vendor/bin/drush recipe themes/contrib/frame/recipes/frame_builder_full --input=frame_builder.theme=my_theme
../vendor/bin/drush cr
```

This installs the builder with media, responsive images, paragraphs, field groups, meta tags and the assistant, makes `my_theme` the
default theme and gives the **Content editor** role what it needs. Take a smaller recipe if you want less:
[Recipes](/builder/recipes) lists them, and what each sets up.

For the assistant, add a provider module and its key: [AI assistant](/builder/ai#set-up).

## 5. Build a page

Log in, *Content → Add content → Landing page*, give it a title and save: the builder opens.

- **Blocks** (left) has the base theme's set to start with: Hero, Section, Columns, Heading, Text, Image, Button, Card
  and more. Drag them to the canvas.
- Select a block: its fields are on the right, then **Layout and style** with your tokens.
- **Preview** shows the page at each breakpoint; **Publish** saves.
- With the assistant: open **AI** and ask, for example, "a landing page for a bakery: hero with an image from the media
  library, three cards, a call to action".

The tour of the editor: [Page builder](/guide/page-builder).

## 6. Make it yours

| To | Do | Read |
|---|---|---|
| change the look of the base components | set the palette (`primary` or `accent` becomes their accent colour), or replace a component with your own of the same props (`replaces:`) | [Components of the base theme](/builder/base-components) |
| add a component | a folder in `components/` with a `*.component.yml` and a Twig file; an `index.jsx` if it is interactive | [Creating a component](/guide/components), [Components in the builder](/builder/components) |
| show lists of content | a view with a display of the type *Component* | [Views](/builder/views) |
| structured content with its own form | a paragraph type | [Paragraphs](/builder/paragraphs) |
| reuse a block on many pages | save it to the library | [Library](/builder/library) |
| lay out a region or a header | a layout component with slots | [Layouts](/builder/layouts) |
| the rest of the page (header, footer, menus) | Drupal blocks in the theme's regions, styled in `scss/global.scss` | [Base theme and subthemes](/guide/base-theme) |

While developing: `npm run watch` in the theme, and `drush cr` after changing a `*.component.yml`, a Twig file or the
`.info.yml`.

## 7. Deploy

Compiled files are not in git. On the server, or in CI, build them:

```bash
(cd web/themes/contrib/frame && npm ci && npm run dist && npm run builder:install && npm run builder:build)
(cd web/themes/custom/my_theme && npm run build)
drush deploy
```

With a release archive of the base theme the first line is only `npm ci` (the tooling for your theme's build).
Export the configuration the recipe created (`drush config:export`) and deploy it like the rest of your site; a recipe
is applied once, not on every deploy. See [Deploying](/guide/create-your-theme#deploying).

## Checklist

- The status report (*Reports → Status report*) has no warning from *Frame Builder*: the default theme is yours.
- *Structure → Content types → Landing page → Page builder* is on; switch it on for other content types there.
- Editors have the role **Content editor**, or the permission *Use the Frame page builder* in their own role.
- A component missing from **Blocks**: `drush cr`, then [Troubleshooting](/guide/troubleshooting).
