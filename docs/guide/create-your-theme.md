# Create your own theme from the scaffold

The scaffold is meant to be copied. `react_scaffold` is the machine name everywhere, replace it with yours (here `my_theme`).

## 1. Copy and rename

```bash
cd web/themes/custom
git clone -b v2 git@github.com:zviryatko/drupal-react-scaffold-theme.git my_theme
cd my_theme
rm -rf .git docs demo node_modules assets          # you do not need the docs site and demo recorder
for f in react_scaffold.*; do mv "$f" "${f/react_scaffold/my_theme}"; done
grep -rl react_scaffold . --exclude-dir=node_modules | xargs sed -i 's/react_scaffold/my_theme/g'
sed -i 's/^name: .*/name: My Theme/' my_theme.info.yml
```

What the replace touches (check the diff, these must all agree):

| Place | Example |
|---|---|
| File names | `my_theme.info.yml`, `my_theme.libraries.yml`, `my_theme.theme` |
| Library references | `my_theme/react`, `my_theme/react-api-client` in `libraries.yml` and every `*.component.yml` |
| SDC namespace | `my_theme:recipe-explorer` in twig, render arrays and Views templates |
| Hook names | `my_theme_js_settings_alter()`, `my_theme_preprocess_page_title()` in `my_theme.theme` |

## 2. Decide the base theme and what to keep

`my_theme.info.yml` has `base theme: umami` because the examples target the demo_umami profile. For a real project:

```yaml
base theme: stable9   # or false, or your design system theme
```

and then remove what only makes sense on Umami:

- `config/optional/block.block.*` (copies of Umami's blocks) and `templates/layout/region--header.html.twig`,
- the `recipe-explorer` component and `views.view.recipe_explorer.yml`, if you do not need them.

Keep these, they are the scaffold:

| Keep | Why |
|---|---|
| `components/apiClient.js`, `common.js`, `helpers.jsx` | global helpers (`apiClient`, `executeWhenVisible`, `rawHtml`, behaviors helpers) |
| `vite.config.js`, `vite-plugin-copy-react.js` | the build, see [Build](/guide/build) |
| `my_theme.libraries.yml` | libraries `global-styling`, `global-libraries`, `react`, `react-api-client` |
| `my_theme.theme` (`js_settings_alter`) | exposes the CSRF token to `apiClient` as `drupalSettings.csrfToken` |
| `package.json`, `.babelrc`, `jest.config.cjs`, `setupTests.js` | build and tests |

## 3. Build and enable

```bash
npm install && npm run dist
drush theme:install my_theme && drush config:set system.theme default my_theme -y
drush cr
```

## 4. Add your first component

Follow [Components (SDC)](/guide/components#add-a-new-component-step-by-step). The build picks up any folder under `components/`
that has an `index.jsx` (or `index.js`), no config change needed.

## Deploying

`assets/` is git-ignored. Run `npm ci && npm run dist` in CI before packaging the theme, or commit `assets/` if your pipeline
cannot run Node.
