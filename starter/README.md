# __LABEL__

Subtheme of [React Scaffold](https://zviryatko.github.io/drupal-react-scaffold-theme/).

```bash
npm install
npm run dist          # builds ./assets (git-ignored)
drush theme:install __THEME__
drush config:set system.theme default __THEME__ -y
drush cr
```

- Components live in `components/<name>/` (any folder with `index.jsx` is built automatically).
- `components/hello-react` is a working example, copy it to start a new component.
- Place it in Twig: `{% include '__THEME__:hello-react' with { greeting: 'Hello' } only %}`
- `npm test` runs Jest, `npm run watch` rebuilds on change.
- The React runtime, `apiClient`, the CSRF hook and the build config come from the `react_scaffold` base theme.
  Update the base theme (git pull, `npm run dist` there) and rebuild this theme.
