# Create a subtheme

## One command

```bash
# from the Drupal root
node web/themes/contrib/react_scaffold/bin/react-scaffold.mjs new my_theme --name "My Theme"
cd web/themes/custom/my_theme
npm run build
drush theme:install my_theme && drush config:set system.theme default my_theme -y && drush cr
```

| Option | Meaning |
|---|---|
| `my_theme` | machine name: lowercase letters, digits, underscores |
| `--name` | label for the `info.yml`, default is the machine name in title case |
| `--path` | folder to create the theme in. Default: `themes/custom` when the base theme is in `themes/contrib`, otherwise next to the base theme |

It needs only Node, no Drupal bootstrap. The base theme needs `npm install` once, see [Getting started](/guide/getting-started).

What you get:

```
my_theme/
  my_theme.info.yml            base theme: react_scaffold, libraries, regions
  my_theme.libraries.yml       global-styling → assets/global.css
  package.json                 three scripts that call the base theme: build, watch, test. No dependencies
  scss/global.scss             your global styles
  components/hello-react/      working example: yml, twig, index.jsx, HelloReact.jsx, scss, test
  config/optional/             block placement: branding, main menu, page title, content, messages...
  logo.svg  README.md  .gitignore
```

**There is no build configuration in a subtheme**: no `vite.config.js`, no `jest.config.cjs`, no Babel config, no `node_modules`. The whole
`package.json` is:

```json
{
  "name": "my_theme",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "node ../../contrib/react_scaffold/bin/react-scaffold.mjs build",
    "watch": "node ../../contrib/react_scaffold/bin/react-scaffold.mjs watch",
    "test": "node ../../contrib/react_scaffold/bin/react-scaffold.mjs test"
  }
}
```

The path is the relative path from your theme to the base theme, written by `new`. Vite, Sass, Jest and their configuration live in the base theme,
so updating the base theme updates the build of every subtheme and there is no scaffolding to re-sync. If you move a theme, fix this path.

Need an npm library in a component (a date picker, a table)? `npm install <library>` in the subtheme: it gets a `dependencies` entry and its own
`node_modules`, and the library is bundled into the components that import it.

## With core's generator

The same template is a core *starterkit* (`react_scaffold_starterkit`), see the [sub-theme documentation](https://www.drupal.org/node/2165673):

```bash
php web/core/scripts/drupal generate-theme my_theme --starterkit react_scaffold_starterkit --path themes/custom --name "My Theme"
```

(On Drupal 11.4+ use `web/core/scripts/dr`.) The result is the same theme, with `generator: react_scaffold_starterkit:<version>` in the info file.
Core cannot compute paths, so the scripts in `package.json` assume `themes/contrib/react_scaffold` and `themes/custom/my_theme`. Fix the path there if
your layout differs (for example composer installed the base theme as `themes/contrib/drupal-react-scaffold-theme`).

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

```yaml
# my_theme.libraries.yml
global-styling:
  css:
    theme:
      assets/global.css: {}
```

This is also what `drush generate theme` produces when you answer `react_scaffold` for the base theme. For the build and the tests add the three
scripts shown above to a `package.json` (or call the CLI directly: `node <base theme>/bin/react-scaffold.mjs build` in the theme folder). `scss/global.scss` is built to
`assets/global.css` when it exists, components are discovered from `components/`.

## Existing site, existing theme?

You do not need to rebase your whole theme. A subtheme is just a theme: if your current theme can have `react_scaffold` as base theme, change `base theme:`.
Otherwise keep your theme and add the React runtime to it:

- attach `react_scaffold/react` from your components' `libraryOverrides.dependencies` (libraries of any installed theme can be attached from anywhere),
- build with the same three `package.json` scripts as a subtheme.

This was tested with a theme that has `base theme: false`: the component mounts and `executeWhenVisible`, `apiClient` and `rawHtml` are available.
What you give up by not extending the base theme:

- the inherited page template and the `libraries:` auto-attach: add `react_scaffold/global-libraries` to your `libraries:` if you want the same libraries on every page,
- **the CSRF token**: `drupalSettings.csrfToken` is set by a hook of the base theme, which only runs when `react_scaffold` is in the active theme's chain.
  Without it `apiClient` sends no token, fine for GET requests, writes will be rejected. Add the hook to your own `.theme` file:

```php
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Asset\AttachedAssetsInterface;

function my_theme_js_settings_alter(array &$settings, AttachedAssetsInterface $assets) {
  $settings['csrfToken'] = \Drupal::service('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);
}
```

## Deploying

`assets/` of the subtheme is git-ignored. In CI run `npm ci` in the base theme, then `npm run build` in the subtheme (and `npm ci` there first if it has
dependencies of its own). The base theme's own `assets/` come with its release archive.
