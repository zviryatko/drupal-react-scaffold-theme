// Builds the token autocomplete of the node form frames into ../js/frame-tokens.js. Run after the editor build,
// which empties ../js.
import { defineConfig } from 'vite';

export default defineConfig(({ mode }) => ({
  build: {
    outDir: '../js',
    emptyOutDir: false,
    sourcemap: mode === 'development',
    lib: { entry: 'src/frameTokens.js', formats: ['iife'], name: 'ReactScaffoldBuilderFrameTokens', fileName: () => 'frame-tokens.js' },
  },
}));
