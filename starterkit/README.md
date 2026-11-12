# Frame Starterkit

A subtheme of [Frame](https://zviryatko.github.io/frame/).

```bash
npm run build         # builds ./assets (git-ignored)
drush theme:install frame_starterkit
drush config:set system.theme default frame_starterkit -y
drush cr
```

- Components live in `components/<name>/`, any folder with `index.jsx` is built automatically.
- `components/hello-react` is a working example, copy it to start a new component.
- Place it in Twig: `{% include 'frame_starterkit:hello-react' with { greeting: 'Hello' } only %}`
- Design tokens (spacing, colours, font sizes) are declared once in `frame_starterkit.info.yml`. The page builder offers them to
  editors and `scss/global.scss` reads them: `@use 'frame/tokens' as frame;` then `frame.space(m)`, `frame.color(dark)`,
  `@include frame.breakpoint(tablet) { ... }`.
- `npm test` runs Jest, `npm run watch` rebuilds on change.
- There is no build configuration and nothing to install here: the scripts in `package.json` call the `frame` base theme, which has
  the Vite and Jest setup, React, the helpers and `apiClient`. The base theme needs `npm install` once. If it is moved, fix the path in `package.json`.
- Need an npm library in a component? `npm install <library>` here, it is bundled into that component.
- Blocks (branding, main menu, page title...) are placed by `config/optional`, edit or replace them.
