// Captures screenshots and demo videos of the theme running on demo_umami.
//   BASE_URL=http://localhost:8088 npm run demo
// Requires: `npx playwright install chromium`, ffmpeg. The demos with DevTools use the real Chromium
// DevTools frontend (served over the remote debugging port) in a second window, stacked under the site.
import { chromium } from 'playwright';
import { mkdir, rm, rename, readdir } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const BASE = process.env.BASE_URL || 'http://localhost:8088';
const PORT = 9333;
const docs = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../docs/public/media');
const shots = path.join(docs, 'screenshots');
const tmp = path.join(docs, '.tmp');
const SITE = { width: 1280, height: 800 };
const TOOLS = { width: 1280, height: 380 };
await mkdir(shots, { recursive: true });
await rm(tmp, { recursive: true, force: true });
await mkdir(tmp, { recursive: true });

// A visible cursor: neither screenshots nor headless video include the pointer.
const cursor = () => {
  window.addEventListener('DOMContentLoaded', () => {
    const dot = document.createElement('div');
    dot.style.cssText = 'position:fixed;z-index:99999;left:-50px;top:0;width:18px;height:18px;margin:-9px 0 0 -9px;border-radius:50%;' +
      'background:rgba(218,60,19,.55);border:2px solid #da3c13;pointer-events:none;transition:transform .08s';
    document.body.appendChild(dot);
    window.addEventListener('mousemove', (e) => { dot.style.left = e.clientX + 'px'; dot.style.top = e.clientY + 'px'; });
    window.addEventListener('mousedown', () => { dot.style.transform = 'scale(.7)'; });
    window.addEventListener('mouseup', () => { dot.style.transform = ''; });
  });
};

const browser = await chromium.launch({ channel: 'chromium', args: [`--remote-debugging-port=${PORT}`, '--remote-allow-origins=*'] });
const settle = (page) => page.waitForLoadState('networkidle').then(() => page.waitForTimeout(400));
const helpers = (page) => {
  const pause = (ms = 900) => page.waitForTimeout(ms);
  const move = async (target) => {
    const box = await (typeof target === 'string' ? page.locator(target).first() : target.first()).boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 18 });
  };
  const click = async (target) => {
    await move(target); await pause(250);
    await (typeof target === 'string' ? page.locator(target).first() : target.first()).click();
  };
  const pick = async (selector, value) => { await move(selector); await pause(300); await page.selectOption(selector, value); await settle(page); await pause(); };
  return { pause, move, click, pick };
};

// Saved admin session, needed for "Edit in Modal".
const loginState = path.join(tmp, 'admin.json');
{
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(`${BASE}/user/login`);
  await page.fill('input[name=name]', 'admin'); await page.fill('input[name=pass]', 'admin');
  await Promise.all([page.waitForURL((u) => !u.pathname.endsWith('/user/login')), page.click('main input[type=submit], form.user-login-form input[type=submit]')]);
  await ctx.storageState({ path: loginState });
  await ctx.close();
}

// --- Screenshots (site only) -------------------------------------------------
{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await ctx.addInitScript(cursor);
  const page = await ctx.newPage();
  const { move } = helpers(page);
  const shot = (name, opts = {}) => page.screenshot({ path: path.join(shots, name + '.png'), ...opts });
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  const cards = page.locator('.recipe-explorer__card');

  await page.goto(`${BASE}/en`); await settle(page);
  await shot('01-umami-home');

  await page.goto(`${BASE}/recipe-explorer`); await cards.first().waitFor(); await settle(page);
  await shot('02-recipe-explorer');
  await shot('02-recipe-explorer-full', { fullPage: true });

  await page.selectOption('#recipe-explorer-difficulty', 'easy');
  await page.selectOption('#recipe-explorer-category', { label: 'Desserts' });
  await settle(page);
  await shot('03-recipe-explorer-filtered');

  await page.goto(`${BASE}/recipe-explorer?search=zzz`); await settle(page);
  await shot('04-recipe-explorer-empty');

  await page.goto(`${BASE}/recipe-explorer?page=1&sort_by=title`); await settle(page);
  await shot('05-recipe-explorer-page-2-sorted');

  await page.goto(`${BASE}/node-list`); await page.waitForSelector('.rs-table-row'); await settle(page);
  await shot('06-node-list');
  await move(page.getByText('Select', { exact: true }).first()); await page.mouse.down(); await page.mouse.up();
  await page.waitForSelector('.rs-picker-select-menu'); await page.waitForTimeout(300);
  await shot('06-node-list-filter-open');

  // Real hover on the page title, tooltip visible with the pointer on it.
  await page.goto(`${BASE}/recipe-explorer`); await cards.first().waitFor(); await settle(page);
  await move('.react-tooltip'); await page.locator('.react-tooltip').first().hover();
  await page.waitForSelector('.tippy-popper', { state: 'visible' }); await page.waitForTimeout(500);
  await shot('07-title-tooltip', { clip: { x: 240, y: 230, width: 800, height: 170 } });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${BASE}/recipe-explorer`); await cards.first().waitFor(); await settle(page);
  await shot('08-recipe-explorer-mobile', { fullPage: true });

  await ctx.close();
  if (errors.length) { console.error('Browser errors:\n' + errors.join('\n')); process.exitCode = 1; }
}

// --- Demos with the real DevTools (Network, filtered by Fetch/XHR) -----------
const stack = (a, b, out, kind) => {
  const args = ['-y', '-loglevel', 'error', ...a.flatMap((x) => x)];
  const fc = `[0:v]scale=${SITE.width}:${SITE.height}[a];[1:v]scale=${TOOLS.width}:${TOOLS.height}[b];[a][b]vstack`;
  execFileSync('ffmpeg', [...args, ...b, '-filter_complex', fc, '-shortest', ...kind, out]);
};

async function demo(name, { storageState, run, finalShot }) {
  const dir = path.join(tmp, name);
  const siteCtx = await browser.newContext({ viewport: SITE, storageState, recordVideo: { dir, size: SITE } });
  await siteCtx.addInitScript(cursor);
  const site = await siteCtx.newPage();
  const tA = Date.now();
  await site.goto('about:blank');
  const targets = await (await fetch(`http://localhost:${PORT}/json`)).json();
  const target = targets.filter((t) => t.type === 'page' && t.url === 'about:blank').pop();

  const toolsCtx = await browser.newContext({ viewport: TOOLS, recordVideo: { dir, size: TOOLS } });
  const tools = await toolsCtx.newPage();
  const tB = Date.now();
  await tools.goto(`http://localhost:${PORT}/devtools/devtools_app.html?ws=localhost:${PORT}/devtools/page/${target.id}&panel=network`);
  await tools.getByText('Fetch/XHR', { exact: true }).click();
  // Hide the filter bar to save space, the Fetch/XHR filter stays applied.
  await tools.getByRole('button', { name: 'Filter', exact: true }).first().click();
  await tools.waitForTimeout(500);

  const stillSite = path.join(tmp, name + '-site.png');
  const stillTools = path.join(tmp, name + '-tools.png');
  let snapped = false;
  // Scenarios call snap() at the moment that best represents the demo (used for the poster screenshot).
  const snap = async () => { snapped = true; await site.screenshot({ path: stillSite }); await tools.screenshot({ path: stillTools }); };

  await run(site, helpers(site), snap);
  if (!snapped) await snap();
  const vA = site.video(); const vB = tools.video();
  await siteCtx.close(); await toolsCtx.close();

  // Videos start when the pages are created, trim whichever started earlier.
  const delta = (tB - tA) / 1000;
  const offA = delta > 0 ? ['-ss', String(delta)] : [];
  const offB = delta < 0 ? ['-ss', String(-delta)] : [];
  stack([[...offA, '-i', await vA.path()]], [...offB, '-i', await vB.path()], path.join(docs, name + '.mp4'),
    ['-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-r', '25', '-movflags', '+faststart']);
  // Small GIF for places that cannot play video (README).
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', path.join(docs, name + '.mp4'), '-vf',
    'fps=8,scale=720:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96[p];[b][p]paletteuse=dither=bayer', path.join(docs, name + '.gif')]);
  execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', stillSite, '-i', stillTools, '-filter_complex',
    `[0:v]scale=${SITE.width}:${SITE.height}[a];[1:v]scale=${TOOLS.width}:${TOOLS.height}[b];[a][b]vstack`, '-frames:v', '1',
    path.join(shots, finalShot + '.png')]);
}

await demo('recipe-explorer-demo', {
  finalShot: '09-recipe-explorer-devtools',
  async run(page, { pause, move, click, pick }, snap) {
    const cards = page.locator('.recipe-explorer__card');
    await page.goto(`${BASE}/recipe-explorer`); await cards.first().waitFor(); await settle(page); await pause(2000);
    await pick('#recipe-explorer-difficulty', 'easy');
    await pick('#recipe-explorer-category', { label: 'Desserts' });
    await click('.recipe-explorer__reset'); await settle(page); await pause();
    await pick('#recipe-explorer-time', { label: 'Up to 10 minutes' });
    await pick('#recipe-explorer-time', '');
    await click('#recipe-explorer-search');
    await page.keyboard.type('chocolate', { delay: 140 }); await settle(page); await pause(1200); await snap(); await pause(600);
    await page.fill('#recipe-explorer-search', ''); await settle(page); await pause(600);
    await pick('#recipe-explorer-sort', 'title');
    await click('.recipe-explorer__order'); await settle(page); await pause();
    await page.mouse.wheel(0, 450); await pause(700);
    await click('.recipe-explorer__pager button[aria-label="Page 2"]'); await settle(page); await pause(2200);
  },
});

await demo('node-list-modal-demo', {
  storageState: loginState,
  finalShot: '10-node-list-modal-devtools',
  async run(page, { pause, move, click }, snap) {
    await page.goto(`${BASE}/node-list`); await page.waitForSelector('.rs-table-row');
    // Collapse the Drupal admin sidebar to give the demo the full width.
    await page.locator('.admin-toolbar__expand-button').first().click().catch(() => {});
    await settle(page); await pause(2000);
    await click(page.getByText('Select', { exact: true }).first());
    await page.waitForSelector('.rs-picker-select-menu'); await pause(600);
    await click(page.locator('.rs-picker-select-menu-item', { hasText: 'Recipe' })); await settle(page); await pause(1400);
    await click(page.locator('.rs-table-header-row-wrapper .rs-table-cell-content', { hasText: 'Created' }).first()); await settle(page); await pause(1400);
    await click(page.getByText('Edit in Modal').first());
    await page.waitForSelector('.ui-dialog', { state: 'visible' }); await settle(page); await pause(1500); await snap(); await pause(1000);
    await page.locator('.ui-dialog-content').evaluate((el) => el.scrollBy({ top: 300, behavior: 'smooth' })); await pause(1500);
  },
});

await rm(tmp, { recursive: true, force: true });
await browser.close();
console.log('Done:', (await readdir(shots)).length, 'screenshots in', shots);
