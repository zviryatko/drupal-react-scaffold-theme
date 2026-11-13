// Shared steps of the end-to-end tests of the page builder: what an editor does, as functions.
//
// The builder page (/node/N/edit) is an admin page with one frame, the editor (Puck). The editor has frames of its
// own: two parts of Drupal's node form ("Content" on the left, "Page settings" on the right) and, for a Paragraph
// block, the form of the paragraph. `editor` below is always the frame locator of the editor.
import { expect } from '@playwright/test';

export const ADMIN = { name: process.env.ADMIN_USER || 'admin', pass: process.env.ADMIN_PASS || 'admin' };
export const EDITOR = { name: 'editor', pass: 'editor' };
export const AUTHOR = { name: 'author', pass: 'author' };

const escape = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** A title no other run has used. */
export const unique = (label) => `${label} ${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;

export async function login(page, account = ADMIN) {
  await page.goto('/user/login');
  await page.fill('#edit-name', account.name);
  await page.fill('#edit-pass', account.pass);
  await page.click('#edit-submit');
  await expect(page).toHaveURL(/\/user\/\d+/);
}

/** The frame of the editor in the builder page. */
export const editorOf = (page) => page.frameLocator('iframe.frame-builder-frame');

/** Waits until the editor can be used: Puck is drawn and both parts of the node form are loaded. */
export async function waitForEditor(page) {
  const editor = editorOf(page);
  await editor.locator('#frame-builder [class*="PuckHeader"]').first().waitFor({ timeout: 30000 });
  await editor.frameLocator('iframe[title="Page settings"]').locator('form').first().waitFor({ state: 'attached', timeout: 30000 });
  await editor.frameLocator('iframe[title="Content"]').locator('form').first().waitFor({ state: 'attached', timeout: 30000 });
  // Changes in the first moments after loading are taken for Puck completing the data, not for edits (main.jsx).
  await page.waitForTimeout(1600);
  return editor;
}

/**
 * A new page of a content type, made with Drupal's add form as an editor does. The builder opens as its edit page.
 * `fields`: values of other inputs of the add form, by name. Returns the node id; the editor is ready.
 */
export async function createPage(page, type, title, fields = {}) {
  await page.goto(`/node/add/${type}`);
  await page.fill('input[name="title[0][value]"]', title);
  for (const [name, value] of Object.entries(fields)) {
    await page.fill(`[name="${name}"]`, value);
  }
  await page.click('[data-drupal-selector="edit-submit"]');
  await page.waitForURL(/\/node\/\d+\/edit/);
  await waitForEditor(page);
  return Number(page.url().match(/\/node\/(\d+)\//)[1]);
}

/** Opens the builder of a page, in a language when given ("de"). Returns the editor, ready. */
export async function openEditor(page, node, langcode = '') {
  await page.goto(`${langcode ? `/${langcode}` : ''}/node/${node}/edit`);
  return waitForEditor(page);
}

/** What Drupal gave the editor (drupalSettings.frameBuilder): the saved layout, the unsaved draft, endpoints. */
export const settingsOf = (editor) => editor.locator('body').evaluate(() => window.drupalSettings.frameBuilder);

/** The saved layout as the editor received it, as a list of "type#id" with nesting flattened. */
export async function savedTypes(editor) {
  const { data } = await settingsOf(editor);
  const walk = (items) => items.flatMap((item) => [item.type, ...Object.values(item.props || {}).filter(Array.isArray).flatMap((value) => walk(value.filter((child) => child?.type)))]);
  return walk(data?.content || []);
}

/**
 * Saves a layout through the editor's own endpoint: a shortcut for tests that are about something else than
 * building. `items` is the content of the layout; `as` is "publish", "draft" or null (keep the state).
 */
export async function storeLayout(editor, items, as = 'publish') {
  const result = await editor.locator('body').evaluate(async (element, body) => {
    const builder = window.drupalSettings.frameBuilder;
    const response = await fetch(builder.endpoints.save, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': builder.csrfToken },
      body: JSON.stringify(body),
    });
    return response.ok ? 'ok' : `${response.status} ${await response.text()}`.slice(0, 400);
  }, { data: { root: { props: {} }, content: items }, publish: as === 'publish', draft: as === 'draft' });
  expect(result, 'the layout is stored').toBe('ok');
}

/** A Heading of the base theme, as a layout item. */
export const heading = (id, text, more = {}) => ({ type: 'frame:heading', props: { id, text, level: 'h2', size: 'l', ...more } });

// --- the editor

/** Opens a panel of the left column by the label of its button in the rail: Content, Blocks, Structure, a group. */
export async function openPanel(editor, label) {
  const item = editor.locator('[class*="Nav-list"] > li', { has: editor.owner().page().getByText(label, { exact: true }) }).first();
  // A click on the open panel's button would close the column.
  if (!/NavItem--active/.test(await item.getAttribute('class'))) await item.locator('div[class*="NavItem-link"]').click();
  await expect(item).toHaveClass(/NavItem--active/);
}

/** Labels of the panels in the rail, in order. */
export const panelLabels = (editor) => editor.locator('[class*="Nav-list"] > li:not([class*="mobileOnly"]) div[class*="NavItem-link"] > span:last-child').allInnerTexts();

/** A component on the canvas, by its id. */
export const block = (editor, id) => editor.locator(`[data-puck-component="${id}"]`);

/** Ids of the components on the canvas, in document order. */
export const blockIds = (editor) => editor.locator('[data-puck-component]').evaluateAll((elements) => elements.map((element) => element.getAttribute('data-puck-component')));

/** The drop zone of the page, or of a slot of a component ("<id>:<slot>"). */
export const zone = (editor, name = '') => (name ? editor.locator(`[data-puck-dropzone="${name}"]`) : editor.locator('[class*="DropZone--isRootZone"]'));

/**
 * Drags a block from the Blocks panel onto a drop zone, with real mouse moves: Puck starts a drag only after the
 * pointer travelled, and picks the target while it moves. Returns the id of the new component.
 *
 * `label` is the name in the panel; `target` a locator to drop on (default: the page); `at` where in the target,
 * as fractions of its box.
 */
export async function addBlock(editor, label, target = zone(editor), at = { x: 0.5, y: 0.5 }) {
  const page = editor.owner().page();
  await openPanel(editor, 'Blocks');
  const search = editor.getByRole('searchbox', { name: 'Search blocks' });
  await search.fill(label);
  const item = editor.locator('.frame-builder-block', { has: page.locator('.frame-builder-block__label', { hasText: new RegExp(`^${escape(label)}`) }) }).first();
  await item.waitFor();
  const before = await blockIds(editor);
  const from = await item.boundingBox();
  await target.scrollIntoViewIfNeeded();
  const to = await target.boundingBox();
  const end = { x: to.x + to.width * at.x, y: to.y + Math.min(to.height * at.y, to.height - 4) };
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  await page.mouse.move(from.x + from.width / 2 + 12, from.y + from.height / 2 + 12, { steps: 4 });
  await page.mouse.move(end.x, end.y, { steps: 20 });
  // The target under the pointer is found while it moves: one more small move, then a pause for it to settle.
  await page.mouse.move(end.x + 2, end.y + 2, { steps: 3 });
  await page.waitForTimeout(250);
  await page.mouse.up();
  let added = [];
  await expect.poll(async () => {
    added = (await blockIds(editor)).filter((id) => !before.includes(id));
    return added.length;
  }, { message: `"${label}" is on the canvas` }).toBe(1);
  await search.fill('');
  return added[0];
}

/** Selects a component on the canvas: its fields show in the right column. */
export async function selectBlock(editor, id) {
  const target = block(editor, id);
  await target.scrollIntoViewIfNeeded();
  // The middle of a container is one of the components in it: a container is clicked in its top left corner.
  const position = await target.locator('[data-puck-component]').count() ? { x: 6, y: 6 } : undefined;
  // On a translation with a locked structure components cannot be dragged, which Playwright reads as "disabled".
  await target.click({ position, force: await target.getAttribute('aria-disabled') === 'true' });
  await expect(editor.locator('[class*="Sidebar--right"] [class*="SidebarSection-breadcrumb"], [class*="Sidebar--right"] [class*="SidebarSection-title"]').first()).toBeVisible();
}

/** Nothing selected: the right column shows the page settings. */
export async function deselect(editor) {
  await editor.owner().page().keyboard.press('Escape');
  const root = editor.locator('[class*="PuckCanvas-inner"]').first();
  const box = await root.boundingBox();
  await editor.owner().page().mouse.click(box.x + 3, box.y + box.height - 3);
}

/** The right column: fields of the selected component, the page settings, or the history. */
export const rightColumn = (editor) => editor.locator('[class*="Sidebar--right"]');

/** A field of the selected component, by its label. */
export const field = (editor, label) => rightColumn(editor)
  .locator('.frame-builder-form-item', { has: editor.owner().page().locator('label', { hasText: new RegExp(`^${escape(label)}$`) }) })
  .locator('input, textarea, select').first();

/** Types a value into a text field of the selected component. */
export async function setField(editor, label, value) {
  const input = field(editor, label);
  await input.fill(value);
  await expect(input).toHaveValue(value);
}

/** The floating message of the editor (saving, saved, error, restored). */
export const message = (editor) => editor.locator('.frame-builder-messages--floating');

/** Closes the message at the bottom right: it lies over the bottom of the right column. */
export async function dismissMessage(editor) {
  await message(editor).getByRole('button', { name: 'Dismiss' }).click();
  await expect(message(editor)).toHaveCount(0);
}

/**
 * Presses Publish, Draft or Save and waits for the answer of Drupal. Returns the text of the message; with
 * `expectError` the save is expected to fail.
 */
export async function save(editor, button = 'Publish', { expectError = false } = {}) {
  await editor.locator('[class*="PuckHeader"]').getByRole('button', { name: button, exact: true }).click();
  const done = editor.locator(expectError ? '.frame-builder-messages--error' : '.frame-builder-messages--status');
  await expect(done, `"${button}" ${expectError ? 'reports an error' : 'saves the page'}`).toBeVisible({ timeout: 45000 });
  return (await done.innerText()).replace(/\s+/g, ' ').trim();
}

/** "Unsaved changes" in the header. */
export const unsavedMark = (editor) => editor.locator('.frame-builder-unsaved');

/**
 * Waits until the unsaved layout was kept by Drupal (the autosave of the editor, 1.5 s after a change). Start it
 * before the change: `const kept = draftKept(page, 'New text'); …change…; await kept;`
 * `contains`: a piece of the layout that the change brings (a text, a component type), so that an autosave of an
 * earlier change is not taken for it.
 */
export const draftKept = (page, contains) => page.waitForResponse((response) => {
  const body = response.request().postData() || '';
  return /\/frame-builder\/node\/\d+\/draft$/.test(new URL(response.url()).pathname) && body.includes('"data"') && body.includes(contains);
}, { timeout: 15000 });

/** The parts of Drupal's node form inside the editor. */
export const contentForm = (editor) => editor.frameLocator('iframe[title="Content"]');
export const pageForm = (editor) => editor.frameLocator('iframe[title="Page settings"]');

/**
 * Waits until a part of the node form kept its values as a draft (0.7 s after typing). Start it before typing.
 * `part` is "fields" or "page"; `contains` a value that was typed, so that an earlier autosave is not taken for it.
 */
export const fieldsKept = (page, part = 'fields', contains = '') => page.waitForResponse((response) => {
  const url = new URL(response.url());
  const request = response.request();
  return url.pathname.endsWith('/builder/content') && url.searchParams.get('part') === part && request.method() === 'POST' && (request.postData() || '').includes(contains);
}, { timeout: 15000 });

/** Undo and redo of the header. */
export const undoButton = (editor) => editor.locator('[class*="MenuBar-history"] button').nth(0);
export const redoButton = (editor) => editor.locator('[class*="MenuBar-history"] button').nth(1);

/** Opens the history timeline in the right column; returns the locator of its steps, newest first. */
export async function openHistory(editor) {
  const button = editor.locator('.frame-builder-history-button');
  if (await button.getAttribute('aria-expanded') !== 'true') await button.click();
  // Puck keeps a second, hidden copy of the right column for narrow windows.
  const panel = editor.locator('.frame-builder-history:visible');
  await expect(panel).toBeVisible();
  return panel.locator('.frame-builder-timeline__step');
}

// --- the page as visitors see it

/**
 * Opens a path as an anonymous visitor, in a browser context of its own, and gives the page to `look`. Returns
 * what `look` returns; without it, the HTTP status.
 */
export async function asVisitor(browser, path, look = null) {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    const response = await page.goto(path);
    return look ? await look(page, response) : response.status();
  }
  finally {
    await context.close();
  }
}

/** Texts of the Heading components of a page, in order. `page` is a page or a frame locator. */
export const headings = (page) => page.locator('.heading-block__text').allInnerTexts();

/** JavaScript errors of the page and its frames, collected while a test runs. */
export function collectErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  return errors;
}
