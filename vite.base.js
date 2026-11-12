// Shared Vite configuration for the Frame base theme and every subtheme.
//
// Base theme (builds the runtime: React UMD, helpers, apiClient):
//   createViteConfig({ root: import.meta.dirname, mode, runtime: true })
// Subtheme (builds only its own components, React and helpers come from the base theme), done by bin/frame.mjs:
//   createViteConfig({ root: import.meta.dirname, mode })
//
// Contract with Drupal: one bundle per `components/<name>/index.jsx` written to `<outDir>/<name>.{js,css}`,
// React and ReactDOM are externals (window.React / window.ReactDOM).
import { resolve } from 'node:path';
import { existsSync, readdirSync } from 'node:fs';
import react from '@vitejs/plugin-react';
import { createRequire } from 'node:module';
import autoprefixer from 'autoprefixer';
import { vitePluginCopyReact } from './vite-plugin-copy-react.js';
import { tokensImporter, tokensWatch } from './tokens.js';

const here = import.meta.dirname;
const require = createRequire(import.meta.url);

// React is loaded once per page by the `frame/react` library (UMD, window.React / window.ReactDOM).
const reactGlobals = { react: 'React', 'react-dom': 'ReactDOM', 'react-dom/client': 'ReactDOM' };

/**
 * Resolves `react`, `react-dom` and `react-dom/client` to the page globals instead of bundling them, also for the
 * npm libraries a component imports. Each import becomes a virtual module that re-exports the global.
 */
export function reactFromGlobals() {
  const prefix = '\0frame-global:';
  const identifier = /^[A-Za-z_$][\w$]*$/;
  return {
    name: 'frame-globals',
    enforce: 'pre',
    resolveId: (id) => (id in reactGlobals ? prefix + id : null),
    load(id) {
      if (!id.startsWith(prefix)) return null;
      const lib = id.slice(prefix.length);
      const names = Object.keys(require(lib)).filter((name) => name !== 'default' && identifier.test(name));
      return `const lib = window.${reactGlobals[lib]};\nexport default lib;\nexport const { ${names.join(', ')} } = lib;\n`;
    },
  };
}

/** One entry per folder of `<root>/<componentsDir>` that has an index.jsx or index.js. */
export function componentEntries(root, componentsDir = 'components') {
  const dir = resolve(root, componentsDir);
  if (!existsSync(dir)) return {};
  return Object.fromEntries(
    readdirSync(dir, { withFileTypes: true })
      .filter((item) => item.isDirectory())
      .map((item) => {
        const index = ['index.jsx', 'index.js'].find((file) => existsSync(resolve(dir, item.name, file)));
        if (!index) console.warn(`No index file found for ${item.name}`);
        return index ? [item.name, `./${componentsDir}/${item.name}/${index}`] : null;
      })
      .filter(Boolean),
  );
}

/**
 * @param {object} options
 * @param {string} options.root Theme directory (use import.meta.dirname).
 * @param {string} [options.mode] Vite mode: `production` minifies, `development` keeps source maps and React dev builds.
 * @param {boolean} [options.runtime] Base theme only: also build src/{common,helpers,apiClient} and copy React UMD.
 * @param {Record<string,string>} [options.entries] Extra entries, e.g. `{ global: './scss/global.scss' }`.
 * @param {string} [options.componentsDir] Folder with components, default `components`.
 * @param {string} [options.outDir] Output folder, default `assets`. Must match the paths in the libraries.
 */
export function createViteConfig({ root, mode = 'production', runtime = false, entries = {}, componentsDir = 'components', outDir = 'assets' }) {
  const input = {
    ...(runtime && {
      common: resolve(here, 'src/common.js'),
      helpers: resolve(here, 'src/helpers.jsx'),
      apiClient: resolve(here, 'src/apiClient.js'),
    }),
    ...componentEntries(root, componentsDir),
    ...entries,
  };

  return {
    root,
    // Relative URLs for files referenced from CSS (fonts, images): the bundles are served from the theme folder,
    // not from the web root.
    base: './',
    build: {
      lib: false,
      outDir,
      emptyOutDir: true,
      rollupOptions: {
        input,
        output: {
          entryFileNames: '[name].js',
          chunkFileNames: '[name].js',
          assetFileNames: (asset) => (/\.(png|jpe?g|svg|gif|tiff|bmp|ico)$/i.test(asset.name) ? 'images/[name][extname]' : '[name][extname]'),
        },
      },
      sourcemap: mode === 'development',
      minify: mode === 'production',
    },
    resolve: {
      alias: { Components: resolve(root, componentsDir) },
      extensions: ['.js', '.jsx', '.ts', '.tsx'],
    },
    css: {
      postcss: { plugins: [autoprefixer] },
      // `@use 'frame/tokens'`: the theme's tokens from its YAML, see tokens.js.
      preprocessorOptions: { scss: { api: 'modern-compiler', importers: [tokensImporter(root)] } },
    },
    define: { global: 'globalThis' },
    esbuild: { jsxInject: `import React from 'react'`, loader: 'jsx' },
    plugins: [
      react({ jsxRuntime: 'classic' }),
      reactFromGlobals(),
      tokensWatch(root),
      ...(runtime ? [vitePluginCopyReact({ root, outDir, mode })] : []),
    ],
  };
}
