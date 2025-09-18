import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';
import { readdir, lstat } from 'fs/promises';
import { vitePluginCopyReact } from './vite-plugin-copy-react.js';

// Helper function to get dynamic entries from components directory
async function getDynamicEntries() {
  const entries = {
    // Static entries
    common: './components/common.js',
    helpers: './components/helpers.jsx',
    apiClient: './components/apiClient.js',
  };

  try {
    const componentsDir = resolve(__dirname, 'components');
    const items = await readdir(componentsDir);

    for (const item of items) {
      const itemPath = resolve(componentsDir, item);
      const stats = await lstat(itemPath);

      if (stats.isDirectory()) {
        // Check if index.jsx exists, otherwise use index.js
        const jsxPath = resolve(itemPath, 'index.jsx');
        const jsPath = resolve(itemPath, 'index.js');
        try {
          await lstat(jsxPath);
          entries[item] = `./components/${item}/index.jsx`;
        } catch {
          try {
            await lstat(jsPath);
            entries[item] = `./components/${item}/index.js`;
          } catch {
            console.warn(`No index file found for ${item}`);
          }
        }
      }
    }
  } catch (error) {
    console.warn('Error reading components directory:', error);
  }

  return entries;
}

export default defineConfig(async ({ mode }) => {
  const entries = await getDynamicEntries();

  return {
    build: {
      lib: false,
      outDir: 'assets',
      emptyOutDir: true,
      rollupOptions: {
        input: entries,
        output: {
          entryFileNames: '[name].js',
          chunkFileNames: '[name].js',
          assetFileNames: (assetInfo) => {
            const info = assetInfo.name.split('.');
            const extType = info[info.length - 1];
            if (/png|jpe?g|svg|gif|tiff|bmp|ico/i.test(extType)) {
              return `images/[name][extname]`;
            }
            if (/css/i.test(extType)) {
              return `[name][extname]`;
            }
            return `[name][extname]`;
          },
          globals: {
            'react': 'React',
            'react-dom': 'ReactDOM',
          },
        },
        external: ['react', 'react-dom'],
      },
      sourcemap: mode === 'development',
      minify: mode === 'production',
    },

    resolve: {
      alias: {
        Components: resolve(__dirname, 'components'),
      },
      extensions: ['.js', '.jsx', '.ts', '.tsx'],
    },

    css: {
      preprocessorOptions: {
        scss: {
          api: 'modern-compiler',
          includePaths: [resolve(__dirname, 'scss')],
        }
      }
    },

    define: {
      global: 'globalThis',
    },

    esbuild: {
      jsxInject: `import React from 'react'`,
      loader: 'jsx',
    },

    plugins: [
      react({
        jsxRuntime: 'classic',
      }),
      vitePluginCopyReact(mode),
    ],
  };
});