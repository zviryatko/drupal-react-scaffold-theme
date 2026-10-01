import { defineConfig } from 'vite';
import { createViteConfig } from 'react-scaffold/vite';

// Reuses the React Scaffold build: every components/<name>/index.jsx becomes assets/<name>.{js,css},
// React and ReactDOM are externals provided by the base theme.
export default defineConfig(({ mode }) => createViteConfig({
  root: import.meta.dirname,
  mode,
  entries: { global: './scss/global.scss' },
}));
