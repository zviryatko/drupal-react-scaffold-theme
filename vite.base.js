// Shared Vite configuration for the React Scaffold base theme and every subtheme.
//
// Base theme (builds the runtime: React UMD, helpers, apiClient):
//   createViteConfig({ root: import.meta.dirname, mode, runtime: true })
// Subtheme (builds only its own components, React and helpers come from the base theme):
//   createViteConfig({ root: import.meta.dirname, mode })
//
// Contract with Drupal: one bundle per `components/<name>/index.jsx` written to `<outDir>/<name>.{js,css}`,
// React and ReactDOM are externals (window.React / window.ReactDOM).
import { resolve } from 'node:path';
import { existsSync, readdirSync } from 'node:fs';
import react from '@vitejs/plugin-react';
import vitePluginExternal from 'vite-plugin-external';
import autoprefixer from 'autoprefixer';
import { vitePluginCopyReact } from './vite-plugin-copy-react.js';

const here = import.meta.dirname;

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
    build: {
      lib: false,
      outDir,
      emptyOutDir: true,
      rollupOptions: {
        input,
        external: ['react', 'react-dom'],
        output: {
          entryFileNames: '[name].js',
          chunkFileNames: '[name].js',
          assetFileNames: (asset) => (/\.(png|jpe?g|svg|gif|tiff|bmp|ico)$/i.test(asset.name) ? 'images/[name][extname]' : '[name][extname]'),
          globals: { react: 'React', 'react-dom': 'ReactDOM' },
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
      preprocessorOptions: { scss: { api: 'modern-compiler' } },
    },
    define: { global: 'globalThis' },
    esbuild: { jsxInject: `import React from 'react'`, loader: 'jsx' },
    plugins: [
      react({ jsxRuntime: 'classic' }),
      vitePluginExternal({
        externals: { react: 'React', 'react-dom': 'ReactDOM', 'react-dom/client': 'ReactDOM' },
      }),
      ...(runtime ? [vitePluginCopyReact({ root, outDir, mode })] : []),
    ],
  };
}
