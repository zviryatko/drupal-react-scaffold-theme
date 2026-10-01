# React Scaffold Starterkit

Generated from the `react_scaffold_starterkit` starterkit: a subtheme of [React Scaffold](https://zviryatko.github.io/drupal-react-scaffold-theme/).

```bash
npm install
npm run dist          # builds ./assets (git-ignored)
drush theme:install react_scaffold_starterkit
drush config:set system.theme default react_scaffold_starterkit -y
drush cr
```

- Components live in `components/<name>/` (any folder with `index.jsx` is built automatically).
- `components/hello-react` is a working example, copy it to start a new component.
- Place it in Twig: `{% include 'react_scaffold_starterkit:hello-react' with { greeting: 'Hello' } only %}`
- `npm test` runs Jest, `npm run watch` rebuilds on change.
- The React runtime, `apiClient`, the CSRF hook, the build and the test config come from the `react_scaffold` base theme, found next to this
  theme (`../react_scaffold`, `../contrib/react_scaffold`...) or through `REACT_SCAFFOLD_DIR`. The base theme needs `npm install` once.
  See `base-theme.cjs`.
- Blocks (branding, main menu, page title...) are placed by `config/optional`, edit or replace them.
