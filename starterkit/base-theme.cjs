// Locates the React Scaffold base theme, which provides the build and test tooling (vite.base.js, jest.base.cjs)
// and its dependencies. Used by vite.config.js and jest.config.cjs.
//
// The base theme is found by content, not by folder name: any folder next to this theme or in the usual theme
// folders that has react_scaffold.info.yml and vite.base.js. Set REACT_SCAFFOLD_DIR to use another location.
const { existsSync, readdirSync } = require('node:fs');
const { resolve } = require('node:path');

const isBaseTheme = (dir) => existsSync(resolve(dir, 'react_scaffold.info.yml')) && existsSync(resolve(dir, 'vite.base.js'));

const searchRoots = ['..', '../contrib', '../custom', '../../contrib', '../../custom'].map((dir) => resolve(__dirname, dir));

const candidates = [
  process.env.REACT_SCAFFOLD_DIR && resolve(__dirname, process.env.REACT_SCAFFOLD_DIR),
  ...searchRoots.flatMap((root) => (existsSync(root)
    ? readdirSync(root, { withFileTypes: true }).filter((entry) => entry.isDirectory()).map((entry) => resolve(root, entry.name))
    : [])),
].filter(Boolean);

const baseTheme = candidates.find(isBaseTheme);

if (!baseTheme) {
  throw new Error(
    'React Scaffold base theme not found next to this theme or in ' + searchRoots.join(', ') + '.\n' +
    'Install it (themes/contrib/react_scaffold), run `npm install` there, or set REACT_SCAFFOLD_DIR.',
  );
}

module.exports = baseTheme;
