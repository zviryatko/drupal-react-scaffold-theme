// Browser smoke test of the page builder module (modules/frame_builder), used by CI:
//   BASE_URL=http://127.0.0.1:8080 ADMIN_USER=admin ADMIN_PASS=admin node builder-smoke.mjs
// Needs the module enabled, its editor built, and a default theme based on Frame. It uses only what the
// module and the base theme ship: the "Landing page" content type and the layout components.
import { chromium } from 'playwright';

const base = process.env.BASE_URL || 'http://127.0.0.1:8080';
const user = process.env.ADMIN_USER || 'admin';
const pass = process.env.ADMIN_PASS || 'admin';

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1500, height: 950 } });
const errors = [];
page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));

const check = (condition, message) => {
  if (!condition) throw new Error(`Failed: ${message}`);
  console.log(`ok - ${message}`);
};

try {
  await page.goto(`${base}/user/login`);
  await page.fill('#edit-name', user);
  await page.fill('#edit-pass', pass);
  await page.click('#edit-submit');
  await page.waitForLoadState('networkidle');

  // A new landing page: the normal add form, then the builder is its edit page.
  const title = `Builder smoke ${Date.now()}`;
  await page.goto(`${base}/node/add/landing_page`);
  await page.fill('input[name="title[0][value]"]', title);
  await page.click('[data-drupal-selector="edit-submit"]');
  await page.waitForSelector('iframe.frame-builder-frame', { timeout: 30000 });
  check(/\/node\/\d+\/edit/.test(page.url()), 'a new landing page continues in the builder, as its edit page');
  const node = page.url().match(/\/node\/(\d+)\//)[1];

  const editor = page.frameLocator('iframe.frame-builder-frame');
  await editor.locator('#frame-builder [class*="PuckHeader"]').first().waitFor({ timeout: 30000 });
  const settings = await editor.locator('body').evaluate(() => window.drupalSettings.frameBuilder);
  const groups = [...new Set(settings.components.map((component) => component.group))];
  check(['Layout', 'Fields'].every((group) => groups.includes(group)), `the drawer has layout components and the fields of the page (${groups.join(', ')})`);
  check(settings.components.some((component) => component.id === 'frame:columns'), 'the Columns component of the base theme is offered');
  await editor.frameLocator('iframe[title="Page settings"]').locator('input[name="title[0][value]"]').waitFor({ timeout: 30000 });
  check(true, 'the page settings (node form) are shown in the right panel');

  // Save a layout through the editor's own endpoint, then look at the editor and the page.
  const status = await editor.locator('body').evaluate(async (element, body) => {
    const builder = window.drupalSettings.frameBuilder;
    const response = await fetch(builder.endpoints.save, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': builder.csrfToken },
      body: JSON.stringify(body),
    });
    return response.ok ? 200 : `${response.status} ${await response.text()}`.slice(0, 400);
  }, {
    publish: true,
    data: { root: { props: {} }, content: [
      { type: 'frame:stack', props: { id: 'stack', gap: { size: 'm' }, align: 'stretch', dividers: false, _spacing: { padding: { size: 'm', sides: 'all' } }, content: [
        { type: 'drupal_field:title', props: { id: 'title', formatter: 'string', label: 'hidden' } },
        { type: 'frame:columns', props: { id: 'columns', layout: '2', gap: { size: 'custom', custom: '10px' }, align: 'stretch', stack: true, content: [
          { type: 'drupal_field:title', props: { id: 'left', formatter: 'string', label: 'hidden' } },
          { type: 'drupal_field:title', props: { id: 'right', formatter: 'string', label: 'hidden' } },
        ] } },
      ] } },
    ] },
  });
  check(status === 200, `a layout is saved and published (${status})`);

  await page.goto(`${base}/node/${node}`);
  const published = await page.evaluate(() => {
    const items = document.querySelector('.layout-columns__items');
    return {
      stackPadding: getComputedStyle(document.querySelector('.layout-stack')).paddingTop,
      columns: items ? getComputedStyle(items).gridTemplateColumns.split(' ').length : 0,
      gap: items ? getComputedStyle(items).columnGap : '',
      titles: [...document.querySelectorAll('.layout-stack .frame-builder-field')].map((field) => field.textContent.trim()),
    };
  });
  check(published.columns === 2 && published.gap === '10px', 'the page shows the Columns layout with its gap');
  check(published.stackPadding === '16px', 'spacing from the theme scale is applied (padding M)');
  check(published.titles.length === 3 && published.titles.every((text) => text === title), 'Field blocks show the title of the page');

  await page.goto(`${base}/node/${node}/edit`);
  const again = page.frameLocator('iframe.frame-builder-frame');
  await again.locator('[data-puck-component="right"]', { hasText: title }).waitFor({ timeout: 30000 });
  check(true, 'the editor renders the saved layout, nested components included');
  const zone = await again.locator('[data-puck-dropzone="columns:content"]').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length);
  check(zone === 2, 'the Columns drop zone is a 2-column grid in the editor');

  check(errors.length === 0, `no JavaScript errors (${errors.join('; ') || 'none'})`);
  console.log('Page builder smoke test passed.');
}
finally {
  await browser.close();
}
