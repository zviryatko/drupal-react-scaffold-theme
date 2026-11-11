// Builds the editor into ../js/editor.{js,css}. React and Puck are bundled: the editor does not depend on the React the
// theme loads for its islands.
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => ({
  plugins: [react()],
  define: { 'process.env.NODE_ENV': JSON.stringify(mode === 'development' ? 'development' : 'production') },
  build: {
    outDir: '../js',
    emptyOutDir: true,
    sourcemap: mode === 'development',
    cssCodeSplit: false,
    lib: { entry: 'src/main.jsx', formats: ['iife'], name: 'ReactScaffoldBuilder', fileName: () => 'editor.js' },
    rollupOptions: { output: { assetFileNames: 'editor[extname]' } },
  },
}));
