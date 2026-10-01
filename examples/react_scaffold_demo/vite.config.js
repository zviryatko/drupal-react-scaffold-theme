import { defineConfig } from 'vite';
import { createViteConfig } from 'react-scaffold/vite';

// Reuses the base theme build. Builds components/*/index.jsx and the global stylesheet into ./assets.
export default defineConfig(({ mode }) => createViteConfig({
  root: import.meta.dirname,
  mode,
  entries: { global: './scss/global.scss' },
}));
