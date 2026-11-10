// Browser check of a subtheme on a real Drupal site (CI job "drupal").
//   BASE_URL=http://127.0.0.1:8080 node smoke.mjs
// Expects the subtheme to be the default theme and its `hello-react` component embedded in the content region
// with greeting "Hello", name "World" and start 2 (ci.yml writes that template).
import { chromium } from 'playwright';
import assert from 'node:assert/strict';

const base = process.env.BASE_URL || 'http://127.0.0.1:8080';
const browser = await chromium.launch();
const page = await browser.newPage();

const problems = [];
page.on('pageerror', (error) => problems.push(`JS error: ${error.message}`));
page.on('console', (message) => message.type() === 'error' && problems.push(`console: ${message.text()}`));
page.on('response', (response) => response.status() >= 400 && problems.push(`${response.status()} ${response.url()}`));

try {
  // Not the front page: the generated page title and breadcrumb blocks are hidden there.
  await page.goto(`${base}/user/password`, { waitUntil: 'networkidle' });

  // The component is rendered by Drupal (SDC) and mounted by React from the subtheme's bundle.
  const widget = page.locator('.hello-react');
  await widget.locator('p').filter({ hasText: 'Hello, World!' }).waitFor({ timeout: 10000 });
  const button = widget.getByRole('button');
  assert.equal(await button.innerText(), 'Clicked 2 times', 'props reach React');
  await button.click();
  assert.equal(await button.innerText(), 'Clicked 3 times', 'React handles events');

  // Inherited from the base theme: runtime libraries and the CSRF hook.
  const runtime = await page.evaluate(() => ({
    react: typeof window.React?.createElement,
    reactDom: typeof window.ReactDOM?.createRoot,
    helpers: typeof window.executeWhenVisible,
    csrfToken: typeof window.drupalSettings?.csrfToken,
  }));
  assert.deepEqual(runtime, { react: 'function', reactDom: 'function', helpers: 'function', csrfToken: 'string' }, 'base theme runtime');

  // Inherited page template, with the subtheme's regions and block placement.
  assert.ok(await page.locator('header.page__header a[rel="home"]').count(), 'branding block in the header region');
  assert.ok(await page.locator('.page__content form.user-pass').count(), 'main content block in the content region');
  assert.ok(await page.locator('.page__main h1').count(), 'page title block in the page_title region');
  assert.ok(await page.locator('.page__main nav[aria-labelledby="system-breadcrumb"]').count(), 'breadcrumb block in the breadcrumbs region');

  // Styles of both themes apply: the base layout and the subtheme's global.scss.
  const styles = await page.evaluate(() => ({
    bodyMargin: getComputedStyle(document.body).marginTop,
    layout: getComputedStyle(document.querySelector('.page__main')).maxWidth,
  }));
  assert.equal(styles.bodyMargin, '0px', 'subtheme global.css is attached');
  assert.notEqual(styles.layout, 'none', 'base theme layout CSS is attached');

  assert.deepEqual(problems, [], 'no failed requests or JS errors');
  console.log(`OK: subtheme works on ${base}`);
} catch (error) {
  console.error(error.message);
  if (problems.length) console.error(problems.join('\n'));
  process.exitCode = 1;
} finally {
  await browser.close();
}
