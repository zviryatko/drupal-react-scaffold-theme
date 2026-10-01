# Build: Vite and webpack

## What the build has to do

Whatever the tool, the output must satisfy the Drupal side:

1. **One bundle per component**, named after the component folder, written to the subtheme's `assets/` (`assets/<name>.js`, `assets/<name>.css`),
   because the component's `libraryOverrides` points there.
2. **React and ReactDOM are externals** mapped to the globals `React` / `ReactDOM`, and the UMD builds are copied to `assets/react/`
   (library `react_scaffold/react`, provided by the base theme, a subtheme does not copy it).
3. **Shared helpers** (`helpers.jsx`, `apiClient.js`) are built once by the base theme and loaded by its libraries, not bundled into components.
4. **CSS is extracted** from the `import './x.scss'` in the entry.
5. No JS dependency on jQuery or on Drupal internals except the globals `Drupal`, `once`, `drupalSettings`.

## Vite (what the scaffold uses)

The configuration lives once, in the base theme: `vite.base.js`. The base theme, the example subtheme and generated subthemes call it:

```js
// vite.config.js of a generated subtheme, see Base theme and subthemes for the whole file
const { createViteConfig } = await import(pathToFileURL(resolve(baseTheme, 'vite.base.js')).href);

export default defineConfig(({ mode }) => createViteConfig({
  root: import.meta.dirname,
  mode,
  entries: { global: './scss/global.scss' },
}));
```

Options are listed in [Base theme and subthemes](/guide/base-theme#createviteconfig-options). What the factory does:

```js
// entries: one per folder in components/ that has index.jsx (or index.js), plus your `entries`
build: {
  outDir: 'assets',
  emptyOutDir: true,
  rollupOptions: {
    input: entries,
    external: ['react', 'react-dom'],
    output: { entryFileNames: '[name].js', chunkFileNames: '[name].js', assetFileNames: '[name][extname]' },
  },
},
plugins: [
  react({ jsxRuntime: 'classic' }),
  vitePluginExternal({ externals: { react: 'React', 'react-dom': 'ReactDOM', 'react-dom/client': 'ReactDOM' } }),
  // runtime: true (base theme only) → also copies node_modules/react/umd/*.js into assets/react/
],
esbuild: { jsxInject: `import React from 'react'` },   // classic runtime: React is the global
css: { postcss: { plugins: [autoprefixer] } },
```

Things worth knowing:

- **Output is ES modules** when there is more than one entry. Rollup splits tiny shared chunks (`react-dom.js`, `react-dom_client.js`: shims for the externals).
  Scripts with `import` statements only run as modules, so component libraries use `attributes: { type: module }`. Module scripts are deferred and run before
  `DOMContentLoaded`, which is when Drupal attaches behaviors, so ordering is fine. The base theme runtime has no shared chunks, it is classic scripts.
- **`react-dom/client`** is mapped to `ReactDOM`: the UMD React 18 `ReactDOM` object has `createRoot`.
- **`jsxRuntime: 'classic'` + `jsxInject`**: JSX compiles to `React.createElement` with the global React.
- **Modes**: `npm run watch` = development (source maps), `npm run dist` = production.
- **New component = no config change**: the entries are read from `components/`.
- **CSS names**: the CSS imported by `index.jsx` is emitted as `assets/<entry>.css`.
- **Dependencies**: the Vite plugins, Babel and Testing Library packages come from the base theme's `node_modules` (run `npm install` in the base theme once).
  Your subtheme adds what its components import (`rsuite`, `react-tippy`...), plus `vite`, `sass` and `jest`.
- **Base theme build**: `npm run dist` in the base theme builds its runtime into `assets/` (React UMD, helpers, apiClient). The compiled files are not committed,
  releases build and attach them, see [Releases](/guide/base-theme#releases-and-updating-the-base-theme).

## Webpack in a subtheme

Vite is what the scaffold uses, but the contract above is tool independent. This `webpack.config.cjs` follows it for a subtheme. It was tested with
webpack 5 on a generated subtheme: all components mounted in Drupal from its output.

```bash
npm i -D webpack webpack-cli babel-loader css-loader sass-loader mini-css-extract-plugin @babel/core @babel/preset-react
npx webpack --mode production -c webpack.config.cjs
```

```js
// webpack.config.cjs: the same contract as the Vite build (see "What the build has to do").
const path = require('path');
const fs = require('fs');
const webpack = require('webpack');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

// One entry per folder of components/ with an index.jsx.
const components = Object.fromEntries(
  fs.readdirSync('components', { withFileTypes: true })
    .filter((d) => d.isDirectory() && fs.existsSync(`components/${d.name}/index.jsx`))
    .map((d) => [d.name, `./components/${d.name}/index.jsx`]),
);

module.exports = {
  entry: { ...components, global: './scss/global.scss' },
  output: { path: path.resolve(__dirname, 'assets'), filename: '[name].js', clean: true },
  externals: { react: 'React', 'react-dom': 'ReactDOM', 'react-dom/client': 'ReactDOM' },
  optimization: { splitChunks: false },        // one self-contained classic script per entry
  module: {
    rules: [
      { test: /\.jsx?$/, exclude: /node_modules/, use: { loader: 'babel-loader', options: { presets: [['@babel/preset-react', { runtime: 'classic' }]] } } },
      { test: /\.s?css$/, use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'] },
    ],
  },
  resolve: { extensions: ['.js', '.jsx'] },
  plugins: [
    new webpack.ProvidePlugin({ React: 'react' }),   // classic JSX needs React in scope, 'react' is the external window.React
    new MiniCssExtractPlugin({ filename: '[name].css' }),
  ],
};
```

What differs from the Vite build:

- `optimization.splitChunks: false` gives one self-contained **classic** script per entry, so drop `attributes: { type: module }` from the `libraryOverrides`
  (it also works with the attribute, a classic script is valid as a module).
- The React runtime is not built here: the base theme provides it (`react_scaffold/react`), so there is no UMD copy step.
- Classic JSX needs `React` in scope: `ProvidePlugin` injects the external `window.React`. The automatic JSX runtime would import `react/jsx-runtime`, which the UMD build does not have.
- webpack also emits an empty `global.js` for the SCSS-only entry, ignore it.
- Keep `assets/` as the output folder, the libraries point there. Do not run both tools into the same folder.
- Build and tests of the **base theme** itself stay on Vite. Subthemes can use any bundler.

## Checklist when a component does not show up

1. `ls assets/` has `<name>.js` and `<name>.css`.
2. The path in `libraryOverrides` matches (`../../assets/<name>.js`), and `drush cr` was run after editing the yml.
3. Page source contains the script, is it `type="module"`? If the console says "Cannot use import statement outside a module", it is not.
4. `React`, `ReactDOM`, `apiClient` are defined: the component library depends on `react_scaffold/react` / `react-api-client`.
5. The mount element has the class your `once()` selector uses.
