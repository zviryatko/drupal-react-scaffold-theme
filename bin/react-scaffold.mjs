#!/usr/bin/env node
// Command line of the React Scaffold base theme. Subthemes have no build configuration and no build dependencies
// of their own: their npm scripts call this file, so a new base theme version updates the build of every subtheme.
//
//   node <base theme>/bin/react-scaffold.mjs new my_theme [--name "My Theme"] [--path <folder>]
//   node <base theme>/bin/react-scaffold.mjs build | watch | test [jest options]     (run in a subtheme folder)
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, readdirSync, readFileSync, renameSync, statSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { basename, dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const base = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
const [command, ...args] = process.argv.slice(2);

const fail = (message) => {
  console.error(message);
  process.exit(1);
};

const usage = `React Scaffold

  new <machine_name> [--name "Label"] [--path <folder>]   create a subtheme (default folder: themes/custom, or next to the base theme)
  build [--dev]                                           build the theme in the current folder into ./assets
  watch                                                   rebuild on change (development mode)
  test [jest options]                                     run Jest in the current folder`;

/** Value of `--flag value` or `--flag=value`. */
function option(name) {
  const index = args.findIndex((arg) => arg === `--${name}` || arg.startsWith(`--${name}=`));
  if (index === -1) return undefined;
  return args[index].includes('=') ? args[index].slice(name.length + 3) : args[index + 1];
}

function needTooling() {
  if (!existsSync(join(base, 'node_modules', 'vite'))) {
    fail(`The build tooling of the base theme is not installed. Run once:\n  npm install --prefix ${relative(process.cwd(), base) || '.'}`);
  }
}

/** Build configuration: the theme's own vite.config.js when it has one, the shared one otherwise. */
async function viteConfig(mode) {
  const root = process.cwd();
  if (['js', 'mjs', 'ts'].some((ext) => existsSync(join(root, `vite.config.${ext}`)))) return { root, mode };
  const { createViteConfig } = await import('../vite.base.js');
  const entries = existsSync(join(root, 'scss/global.scss')) ? { global: './scss/global.scss' } : {};
  return { ...createViteConfig({ root, mode, entries }), mode, configFile: false };
}

async function build(mode, watch = false) {
  needTooling();
  const { build: viteBuild } = await import('vite');
  const config = await viteConfig(mode);
  await viteBuild(watch ? { ...config, build: { ...config.build, watch: {} } } : config);
}

function test() {
  needTooling();
  const root = process.cwd();
  const own = ['cjs', 'js', 'mjs'].map((ext) => join(root, `jest.config.${ext}`)).find(existsSync);
  const config = own || JSON.stringify(require('../jest.base.cjs')(root));
  const jest = join(dirname(require.resolve('jest/package.json')), 'bin/jest.js');
  const result = spawnSync(process.execPath, [jest, '--config', config, '--rootDir', root, ...args], { stdio: 'inherit', cwd: root });
  process.exit(result.status ?? 1);
}

function create() {
  const name = args[0];
  if (!name || !/^[a-z][a-z0-9_]*$/.test(name)) fail('Usage: react-scaffold new <machine_name> [--name "Label"] [--path <folder>]\nThe machine name has lowercase letters, digits and underscores.');
  const label = option('name') || name.split('_').map((word) => word[0].toUpperCase() + word.slice(1)).join(' ');
  const themes = dirname(base);
  const folder = option('path') ? resolve(option('path')) : basename(themes) === 'contrib' ? join(dirname(themes), 'custom') : themes;
  const target = join(folder, name);
  if (existsSync(target)) fail(`${target} already exists.`);

  const skip = ['node_modules', 'assets', 'coverage', 'package-lock.json'];
  cpSync(join(base, 'starterkit'), target, {
    recursive: true,
    filter: (source) => !skip.includes(basename(source)) && !source.endsWith('.starterkit.yml'),
  });

  const toBase = relative(target, base).split(sep).join('/');
  const walk = (dir) => readdirSync(dir).forEach((entry) => {
    let file = join(dir, entry);
    if (entry.includes('react_scaffold_starterkit')) {
      renameSync(file, file = join(dir, entry.replace('react_scaffold_starterkit', name)));
    }
    if (statSync(file).isDirectory()) return walk(file);
    if (/\.(svg|png|jpe?g|gif|ico)$/i.test(file)) return;
    let text = readFileSync(file, 'utf8')
      .replaceAll('react_scaffold_starterkit', name)
      .replaceAll('React Scaffold Starterkit', label);
    if (file.endsWith('.info.yml')) {
      text = text
        .replace(/^description: .*$/m, `description: 'Subtheme of React Scaffold.'`)
        .replace(/^version: .*\n/m, '')
        .replace(/^# Never shown in the UI.*\nhidden: true\n/m, '');
    }
    if (entry === 'package.json') text = text.replaceAll('../../contrib/react_scaffold', toBase);
    writeFileSync(file, text);
  });
  walk(target);

  const shown = relative(process.cwd(), target) || '.';
  console.log(`Created ${shown}

  cd ${shown}
  npm run build
  drush theme:install ${name} && drush config:set system.theme default ${name} -y && drush cr`);
  if (!existsSync(join(base, 'node_modules'))) console.log(`\nFirst install the build tooling of the base theme, once:\n  npm install --prefix ${relative(process.cwd(), base) || '.'}`);
}

switch (command) {
  case 'new': create(); break;
  case 'build': case 'dist': await build(args.includes('--dev') ? 'development' : 'production'); break;
  case 'watch': await build('development', true); break;
  case 'test': test(); break;
  default: command && command !== 'help' && command !== '--help' ? fail(`Unknown command "${command}".\n\n${usage}`) : console.log(usage);
}
