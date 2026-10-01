#!/usr/bin/env node
// Creates a subtheme of React Scaffold from ./starter.
//
//   node scripts/create-subtheme.mjs my_theme "My Theme" [destination-directory]
//   npm run create-subtheme -- my_theme "My Theme"
//
// The destination defaults to the folder that contains the base theme (a sibling of it), so
// themes/custom/react_scaffold + themes/custom/my_theme.
import { cp, mkdir, readdir, readFile, rename, stat, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';

const [machineName, label = machineName, destArg] = process.argv.slice(2);
const baseDir = path.resolve(import.meta.dirname, '..');

if (!machineName || !/^[a-z][a-z0-9_]*$/.test(machineName)) {
  console.error('Usage: create-subtheme.mjs <machine_name> ["Label"] [destination-directory]\n' +
    'The machine name must be lowercase letters, digits and underscores, starting with a letter.');
  process.exit(1);
}

const destRoot = path.resolve(destArg ?? path.dirname(baseDir));
const target = path.join(destRoot, machineName);
if (existsSync(target)) {
  console.error(`${target} already exists.`);
  process.exit(1);
}

// Path from the new theme to the base theme, used for the `file:` npm dependency.
const basePath = path.relative(target, baseDir).split(path.sep).join('/') || '.';
// Drupal does not inherit regions from a base theme: copy the base theme's list.
const baseInfo = await readFile(path.join(baseDir, 'react_scaffold.info.yml'), 'utf8');
const regions = baseInfo.slice(baseInfo.indexOf('regions:')).trimEnd() + '\n';
const tokens = {
  __REGIONS__: regions, __THEME__: machineName, __LABEL__: label.replace(/'/g, "''"), __BASE_PATH__: basePath };
const replaceTokens = (text) => Object.entries(tokens).reduce((out, [token, value]) => out.replaceAll(token, value), text);

async function copyTemplate(from, to) {
  await mkdir(to, { recursive: true });
  for (const entry of await readdir(from, { withFileTypes: true })) {
    const name = replaceTokens(entry.name).replace(/\.tpl$/, '');
    const source = path.join(from, entry.name);
    const destination = path.join(to, name);
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === 'assets') continue;
      await copyTemplate(source, destination);
    } else {
      await writeFile(destination, replaceTokens(await readFile(source, 'utf8')));
    }
  }
}

await copyTemplate(path.join(baseDir, 'starter'), target);

console.log(`Created ${target}

Next steps:
  1. cd ${path.relative(process.cwd(), baseDir) || '.'} && npm install && npm run dist   (once: builds the base theme runtime, if assets/ is missing)
  2. cd ${path.relative(process.cwd(), target)} && npm install && npm run dist   (npm install also installs the base theme tooling)
  3. drush theme:install ${machineName} && drush config:set system.theme default ${machineName} -y && drush cr
`);
