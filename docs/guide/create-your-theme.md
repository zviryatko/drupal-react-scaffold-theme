# Create a subtheme

## With core's generator (recommended)

Drupal core generates themes from a *starterkit*, see the [sub-theme documentation](https://www.drupal.org/node/2165673). React Scaffold ships one,
`react_scaffold_starterkit`, so the same command that creates a theme from core's Starterkit creates a React-ready subtheme:

```bash
# from the Drupal root (the folder with composer.json)
php web/core/scripts/drupal generate-theme my_theme \
  --starterkit react_scaffold_starterkit \
  --path themes/custom \
  --name "My Theme" \
  --description "My React theme"
```

| Option | Meaning |
|---|---|
| `my_theme` | machine name, also used in file names and in the file contents |
| `--starterkit` | the theme to copy, here `react_scaffold_starterkit` (hidden, lives in the base theme folder) |
| `--path` | where to create it, relative to the web root (default `themes`) |
| `--name`, `--description` | label and description for the `info.yml` |

(On Drupal 11.4+ use `web/core/scripts/dr`.) Then `npm install && npm run dist`, enable the theme and make it default, see [Getting started](/guide/getting-started).

What you get:

```
my_theme/
  my_theme.info.yml            base theme: react_scaffold, libraries, regions
  my_theme.libraries.yml       global-styling → assets/global.css
  my_theme.theme               (hooks go here)
  package.json                 vite, sass, jest
  vite.config.js               finds the base theme, createViteConfig({ root, mode, entries: { global } })
  jest.config.cjs              shared Jest config of the base theme
  base-theme.cjs               locates the base theme folder (REACT_SCAFFOLD_DIR overrides)
  scss/global.scss             your global styles
  components/hello-react/      working example: yml, twig, index.jsx, HelloReact.jsx, scss, test
  config/optional/             block placement: branding, main menu, page title, content, messages...
  logo.svg  README.md  .gitignore
```

The generator copies the starterkit, renames files and replaces the machine name and the label in file contents (core does this, so it is the
same behavior as for any starterkit). It sets `core_version_requirement` to the major version of the Drupal you run it on and records
`generator: react_scaffold_starterkit:<version>` in the info file.

## What to decide after generating

- **Look**: write your CSS in `scss/global.scss` (built to `assets/global.css`). The base layout is neutral: header, main with optional sidebar, footer,
  styled through the `.page__*` classes and the `--page-max-width` and `--page-gutter` variables. With `base theme: false` the markup is core's default
  templates (no classes on menus and blocks), style by structure or override the templates in your subtheme.
- **Page layout**: to change the HTML, copy `templates/layout/page.html.twig` from the base theme into the same path in your theme.
- **Blocks**: the generated `config/optional/block.block.my_theme_*.yml` place the basic blocks when the theme is installed. Edit them, or place blocks in the UI.
- **Regions**: keep the generated list unless you also override `page.html.twig`. Regions are not inherited, see [Base theme](/guide/base-theme#what-a-subtheme-inherits-and-what-it-does-not).
- **Remove the example**: delete `components/hello-react` when you have your own component.

## By hand

The generator is only a copy with renames. The minimum of a subtheme is:

```yaml
# my_theme.info.yml
name: My Theme
type: theme
base theme: react_scaffold
core_version_requirement: ^10.3 || ^11
libraries:
  - my_theme/global-styling
regions:               # copy from react_scaffold.info.yml, regions are not inherited
  header: Header
  # ...
```

This is also what `drush generate theme` produces when you answer `react_scaffold` for the base theme. For the build and the tests copy
`vite.config.js`, `jest.config.cjs`, `base-theme.cjs` and `package.json` from `react_scaffold/starterkit/`. Components are discovered from `components/`.

## Existing site, existing theme?

You do not need to rebase your whole theme. A subtheme is just a theme: if your current theme can have `react_scaffold` as base theme, change `base theme:`.
Otherwise keep your theme and add the React runtime to it:

- attach `react_scaffold/react` from your components' `libraryOverrides.dependencies` (libraries of any installed theme can be attached from anywhere),
- use `vite.config.js` and `base-theme.cjs` from the starterkit for the build.

You lose the inherited page template and the `libraries:` auto-attach (add `react_scaffold/global-libraries` to your `libraries:`).

## Deploying

`assets/` of the subtheme is git-ignored. In CI run `npm ci && npm run dist` in the subtheme, with the base theme installed next to it and `npm install` run there once
(or set `REACT_SCAFFOLD_DIR`). The base theme's own `assets/` come with its release archive.
