import { defineConfig } from 'vite';
import { createViteConfig } from '../../vite.base.js';

// The example lives inside the base theme, so it imports the shared build directly. A generated subtheme finds the
// base theme through base-theme.cjs instead (see starterkit/vite.config.js).
export default defineConfig(({ mode }) => createViteConfig({
  root: import.meta.dirname,
  mode,
  entries: { global: './scss/global.scss' },
}));
