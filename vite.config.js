import { defineConfig } from 'vite';
import { createViteConfig } from './vite.base.js';

// Base theme: builds the runtime (React UMD, helpers, apiClient) into ./assets.
export default defineConfig(({ mode }) => createViteConfig({ root: import.meta.dirname, mode, runtime: true }));
