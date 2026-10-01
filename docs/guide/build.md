# Build: Vite and webpack

## What the build has to do

Whatever the tool, the output must satisfy the Drupal side:

1. **One bundle per component**, named after the component folder, written to `assets/` (`assets/<name>.js`, `assets/<name>.css`),
   because the component's `libraryOverrides` points there.
2. **React and ReactDOM are externals** mapped to the globals `React` / `ReactDOM`, and the UMD builds are copied to `assets/react/`
   (library `react_scaffold/react`).
3. **Shared helpers** (`helpers.jsx`, `apiClient.js`, `common.js`) are built as their own entries and loaded by libraries, not bundled into components.
4. **CSS is extracted** from the `import './x.scss'` in the entry.
5. No JS dependency on jQuery or on Drupal internals except the globals `Drupal`, `once`, `drupalSettings`.

## Vite (what the scaffold uses)

`vite.config.js` highlights:

```js
// entries: one per folder in components/ that has index.jsx (or index.js)
const entries = { common, helpers, apiClient, ...componentFolders };

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
  vitePluginCopyReact(mode),   // copies node_modules/react/umd/*.js into assets/react/
],
esbuild: { jsxInject: `import React from 'react'` },   // classic runtime: React is the global
```

Things worth knowing:

- **Output is ES modules.** Rollup splits tiny shared chunks (`react.js`, `react-dom_client.js`: shims for the externals). Scripts with
  `import` statements only run as modules, so every component library entry uses `attributes: { type: module }` and the `helpers`
  library entry too. Module scripts are deferred and execute before `DOMContentLoaded`, which is when Drupal attaches behaviors, so ordering is fine.
- **`react-dom/client`** is mapped to `ReactDOM`: the UMD React 18 `ReactDOM` object has `createRoot`.
- **`jsxRuntime: 'classic'` + `jsxInject`**: JSX compiles to `React.createElement` with the global React.
- **Modes**: `npm run watch` = development (unminified React UMD, source maps), `npm run dist` = production.
- **New component = no config change**: the dynamic entries read `components/`.
- **CSS names**: the CSS of `index.jsx` is emitted as `assets/<entry>.css`.

## Webpack (equivalent sketch)

::: warning Not tested in this repository
The scaffold ships Vite only. This is the same contract expressed for webpack 5, use it as a starting point.
:::

```js
// webpack.config.js
const path = require('path');
const fs = require('fs');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CopyPlugin = require('copy-webpack-plugin');

const components = fs.readdirSync('components', { withFileTypes: true })
  .filter((d) => d.isDirectory() && fs.existsSync(`components/${d.name}/index.jsx`))
  .reduce((acc, d) => ({ ...acc, [d.name]: `./components/${d.name}/index.jsx` }), {});

module.exports = (env, argv) => ({
  entry: { common: './components/common.js', helpers: './components/helpers.jsx', apiClient: './components/apiClient.js', ...components },
  output: { path: path.resolve(__dirname, 'assets'), filename: '[name].js', clean: true },
  externals: { react: 'React', 'react-dom': 'ReactDOM', 'react-dom/client': 'ReactDOM' },
  optimization: { splitChunks: false },            // one self-contained file per entry
  module: {
    rules: [
      { test: /\.jsx?$/, exclude: /node_modules/, use: 'babel-loader' },   // @babel/preset-react with runtime: 'classic'
      { test: /\.s?css$/, use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'] },
    ],
  },
  resolve: { extensions: ['.js', '.jsx'] },
  plugins: [
    new MiniCssExtractPlugin({ filename: '[name].css' }),
    new CopyPlugin({ patterns: [
      { from: 'node_modules/react/umd/react.production.min.js', to: 'react/react.js' },
      { from: 'node_modules/react-dom/umd/react-dom.production.min.js', to: 'react/react-dom.js' },
    ] }),
  ],
});
```

Because `splitChunks: false` yields classic scripts without `import`, drop `attributes: { type: module }` from the libraries.
`helpers.jsx` uses `import { forwardRef } from 'react'`, which the external mapping turns into the global `React.forwardRef`.

## Checklist when a component does not show up

1. `ls assets/` has `<name>.js` and `<name>.css`.
2. The path in `libraryOverrides` matches (`../../assets/<name>.js`), and `drush cr` was run after editing the yml.
3. Page source contains the script, is it `type="module"`? If the console says "Cannot use import statement outside a module", it is not.
4. `React`, `ReactDOM`, `apiClient` are defined: the component library depends on `react_scaffold/react` / `react-api-client`.
5. The mount element has the class your `once()` selector uses.
