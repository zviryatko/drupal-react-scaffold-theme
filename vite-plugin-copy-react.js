import { copyFile, mkdir } from 'fs/promises';
import { resolve, dirname } from 'path';
import { existsSync } from 'fs';

export function vitePluginCopyReact(mode = 'development') {
  return {
    name: 'copy-react',
    writeBundle: {
      sequential: true,
      async handler() {
        const outDir = resolve(__dirname, 'assets');
        const reactDir = resolve(outDir, 'react');

        // Ensure react directory exists
        if (!existsSync(reactDir)) {
          await mkdir(reactDir, { recursive: true });
        }

        const isDev = mode === 'development';

        const reactSource = isDev
          ? 'node_modules/react/umd/react.development.js'
          : 'node_modules/react/umd/react.production.min.js';

        const reactDomSource = isDev
          ? 'node_modules/react-dom/umd/react-dom.development.js'
          : 'node_modules/react-dom/umd/react-dom.production.min.js';

        try {
          await copyFile(
            resolve(__dirname, reactSource),
            resolve(reactDir, 'react.js')
          );

          await copyFile(
            resolve(__dirname, reactDomSource),
            resolve(reactDir, 'react-dom.js')
          );

          console.log('React libraries copied successfully');
        } catch (error) {
          console.warn('Failed to copy React libraries:', error.message);
        }
      }
    }
  };
}