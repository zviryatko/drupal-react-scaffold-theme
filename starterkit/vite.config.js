import { defineConfig } from 'vite';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';

// Loaded with require, not imported: Vite would bundle a .cjs import into this ESM config and break its `require` calls.
const baseTheme = createRequire(import.meta.url)('./base-theme.cjs');

// Reuses the React Scaffold build: every components/<name>/index.jsx becomes assets/<name>.{js,css},
// React and ReactDOM are externals provided by the base theme.
const { createViteConfig } = await import(pathToFileURL(resolve(baseTheme, 'vite.base.js')).href);

export default defineConfig(({ mode }) => createViteConfig({
  root: import.meta.dirname,
  mode,
  entries: { global: './scss/global.scss' },
}));
