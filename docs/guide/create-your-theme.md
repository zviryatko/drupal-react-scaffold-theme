# Create a subtheme

## With the generator (recommended)

```bash
cd themes/contrib/react_scaffold
npm run create-subtheme -- my_theme "My Theme" ../../custom      # name, label, destination
```

It copies [`starter/`](https://github.com/zviryatko/drupal-react-scaffold-theme/tree/main/starter) and replaces the tokens:

| Token | Replaced by |
|---|---|
| `__THEME__` | machine name, also in file names (`__THEME__.info.yml.tpl` → `my_theme.info.yml`) |
| `__LABEL__` | label |
| `__BASE_PATH__` | relative path from the new theme to the base theme (for the `file:` dependency and `preinstall`) |
| `__REGIONS__` | the `regions:` block of the base theme's `info.yml` |

You get:

```
my_theme/
  my_theme.info.yml            base theme: react_scaffold, libraries, regions
  my_theme.libraries.yml       global-styling → assets/global.css
  my_theme.theme               (hooks go here)
  package.json                 react-scaffold linked from the base theme, vite, sass, jest
  vite.config.js               createViteConfig({ root, mode, entries: { global } })
  jest.config.cjs              require('react-scaffold/jest')(__dirname)
  scss/global.scss             your global styles
  components/hello-react/      working example: yml, twig, index.jsx, HelloReact.jsx, scss, test
  logo.svg  .gitignore         logo and ignore rules (node_modules, assets)
```

Then `npm install && npm run dist`, enable the theme and make it default, see [Getting started](/guide/getting-started).

## What to decide after generating

- **Look**: write your CSS in `scss/global.scss` (built to `assets/global.css`). The base layout is neutral: header, main with optional sidebar, footer,
  styled through the `.page__*` classes, the `--page-max-width` and `--page-gutter` variables and the markup of stable9.
- **Page layout**: to change the HTML, copy `templates/layout/page.html.twig` from the base theme into the same path in your theme.
- **Blocks**: blocks are placed per theme. Place yours in the UI, or ship them as `config/optional/block.block.my_theme_*.yml`.
- **Regions**: keep the generated list unless you also override `page.html.twig`.
- **Remove the example**: delete `components/hello-react` when you have your own component.

## By hand

The generator is only a copy with renames, the minimum of a subtheme is:

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

plus `package.json` / `vite.config.js` as in [Base theme and subthemes](/guide/base-theme#the-build-contract). Components are discovered from `components/`.

## Existing site, existing theme?

You do not need to rebase your whole theme. A subtheme is just a theme: if your current theme can use `react_scaffold` as base theme
(its markup is stable9), change `base theme:`. Otherwise keep your theme and add the React runtime to it:

- attach `react_scaffold/react` from your components' `libraryOverrides.dependencies` (libraries of an installed theme can be attached from anywhere),
- import `createViteConfig` from `react-scaffold/vite` for the build.

You lose the inherited page template and the `libraries:` auto-attach (add `react_scaffold/global-libraries` to your `libraries:`).

## Deploying

`assets/` of the subtheme is git-ignored. In CI run `npm ci && npm run dist` in the subtheme (npm ci installs the base theme tooling
through `preinstall`). The base theme `assets/` come with the base theme.
