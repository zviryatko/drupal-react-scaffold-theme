import { copyFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { createRequire } from 'node:module';

// Copies the React UMD builds into <root>/<outDir>/react. They are loaded once per page by the
// `react_scaffold/react` library, every component bundle treats React as an external (window.React).
export function vitePluginCopyReact({ root, outDir = 'assets', mode = 'development' }) {
  const require = createRequire(import.meta.url);
  const umd = (pkg, file) => resolve(require.resolve(`${pkg}/package.json`), '..', 'umd', file);
  const dev = mode === 'development';

  return {
    name: 'copy-react',
    writeBundle: {
      sequential: true,
      async handler() {
        const target = resolve(root, outDir, 'react');
        await mkdir(target, { recursive: true });
        await copyFile(umd('react', dev ? 'react.development.js' : 'react.production.min.js'), resolve(target, 'react.js'));
        await copyFile(umd('react-dom', dev ? 'react-dom.development.js' : 'react-dom.production.min.js'), resolve(target, 'react-dom.js'));
        console.log('React libraries copied successfully');
      },
    },
  };
}
