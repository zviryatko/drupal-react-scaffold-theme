// Translations of a page, in the two modes of a content type:
// - synced (landing_page): one structure for all languages, a translation changes only text and links;
// - independent (e2e_independent): a translation is a copy made when it is created, then changed freely.
// Needs: German as the second language with the /de prefix, both content types translatable (setup.sh).
import { test, expect } from '@playwright/test';
import {
  addBlock, asVisitor, block, blockIds, createPage, editorOf, field, heading, headings, login, openEditor, openPanel, save, selectBlock, setField,
  settingsOf, storeLayout, unique, zone,
} from './helpers.mjs';

/** Adds the German translation of a page with Drupal's translation form. */
async function translate(page, node, title) {
  await page.goto(`/de/node/${node}/translations/add/en/de`);
  await page.fill('input[name="title[0][value]"]', title);
  await page.click('[data-drupal-selector="edit-submit"]');
  await expect(page).toHaveURL(new RegExp(`/de/node/${node}$`));
}

const quotes = (page) => page.locator('blockquote').count();
const look = async (visit) => ({ headings: await headings(visit), quotes: await quotes(visit) });

test.describe('Translations', () => {
  test('synced: the structure is locked on the translation, texts are translated, the original is unchanged', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Synced'));
    await storeLayout(editorOf(page), [heading('one', 'First'), heading('two', 'Second')]);
    await translate(page, node, unique('Synchron'));
    // Before anything is translated the translation shows the original.
    expect(await asVisitor(browser, `/de/node/${node}`, headings)).toEqual(['First', 'Second']);

    const editor = await openEditor(page, node, 'de');
    expect((await settingsOf(editor)).translation).toMatchObject({ mode: 'synced', locked: true });
    await expect(editor.locator('[class*="PuckHeader-title"]')).toHaveText('German translation · text and links only');

    // Nothing can be added, moved or removed.
    await openPanel(editor, 'Blocks');
    await expect(editor.locator('.frame-builder-blocks__note')).toContainText('This translation shares the structure of the original');
    await expect(editor.locator('.frame-builder-block').first()).toHaveClass(/is-disabled/);
    await expect(block(editor, 'one')).toHaveAttribute('aria-disabled', 'true');
    await selectBlock(editor, 'two');
    await page.keyboard.press('Delete');
    expect(await blockIds(editor)).toEqual(['one', 'two']);

    // Text can be translated; what is not text follows the original.
    await selectBlock(editor, 'one');
    await expect(field(editor, 'Level')).toBeDisabled();
    await expect(field(editor, 'Size')).toBeDisabled();
    await setField(editor, 'Text', 'Erste');
    await expect(block(editor, 'one')).toContainText('Erste');
    await save(editor, 'Publish');

    expect(await asVisitor(browser, `/de/node/${node}`, headings)).toEqual(['Erste', 'Second']);
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['First', 'Second']);

    // A change of the structure of the original shows on the translation, which keeps its texts.
    const original = await openEditor(page, node);
    expect((await settingsOf(original)).translation).toMatchObject({ mode: 'synced', locked: false });
    await addBlock(original, 'Quote', block(original, 'two'), { x: 0.5, y: 0.95 });
    await selectBlock(original, 'two');
    await field(original, 'Level').selectOption('h4');
    await expect(block(original, 'two').locator('h4')).toHaveText('Second');
    await save(original, 'Publish');

    expect(await asVisitor(browser, `/node/${node}`, look)).toEqual({ headings: ['First', 'Second'], quotes: 1 });
    expect(await asVisitor(browser, `/de/node/${node}`, async (visit) => ({ ...(await look(visit)), level: await visit.locator('.heading-block__text').nth(1).evaluate((element) => element.tagName) })))
      .toEqual({ headings: ['Erste', 'Second'], quotes: 1, level: 'H4' });

    // The editor of the translation opens with its texts and the new structure.
    const german = await openEditor(page, node, 'de');
    await expect(block(german, 'one')).toContainText('Erste');
    await expect(german.locator('[data-puck-component]')).toHaveCount(3);
  });

  test('independent: the translation starts as a copy and is then changed freely, without touching the original', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'e2e_independent', unique('Independent'));
    await storeLayout(editorOf(page), [heading('one', 'First'), heading('two', 'Second')]);
    await translate(page, node, unique('Eigenständig'));
    expect(await asVisitor(browser, `/de/node/${node}`, headings)).toEqual(['First', 'Second']);

    const editor = await openEditor(page, node, 'de');
    expect((await settingsOf(editor)).translation).toMatchObject({ mode: 'independent', locked: false });
    await expect(editor.locator('[class*="PuckHeader-title"]')).toHaveText('German · independent copy, not synced');
    expect(await blockIds(editor)).toEqual(['one', 'two']);

    // Structure and props can be changed here.
    await selectBlock(editor, 'one');
    await expect(field(editor, 'Level')).toBeEnabled();
    await setField(editor, 'Text', 'Erste');
    await field(editor, 'Level').selectOption('h3');
    await expect(block(editor, 'one').locator('h3')).toHaveText('Erste');
    await selectBlock(editor, 'two');
    await page.keyboard.press('Delete');
    await expect(block(editor, 'two')).toHaveCount(0);
    await addBlock(editor, 'Quote', zone(editor), { x: 0.5, y: 0.9 });
    await save(editor, 'Publish');

    expect(await asVisitor(browser, `/de/node/${node}`, look)).toEqual({ headings: ['Erste'], quotes: 1 });
    expect(await asVisitor(browser, `/node/${node}`, look)).toEqual({ headings: ['First', 'Second'], quotes: 0 });

    // And the other way round: a change of the original does not reach the translation.
    const original = await openEditor(page, node);
    await selectBlock(original, 'two');
    await setField(original, 'Text', 'Second, changed');
    await save(original, 'Publish');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['First', 'Second, changed']);
    expect(await asVisitor(browser, `/de/node/${node}`, look)).toEqual({ headings: ['Erste'], quotes: 1 });
    // The title bar of the original says how its translations relate to it.
    await expect((await openEditor(page, node)).locator('[class*="PuckHeader-title"]')).toHaveText('English · translations are independent copies');
  });

  for (const [type, note] of [['landing_page', 'English · structure shared with translations'], ['e2e_independent', 'English · translations are independent copies']]) {
    test(`the editor of the original says that it has translations, right after one was added (${type})`, async ({ page }) => {
      // The editor opens the latest revision that changed English, which is older than the translation.
      await login(page);
      const node = await createPage(page, type, unique('Original'));
      await storeLayout(editorOf(page), [heading('one', 'First')]);
      await translate(page, node, unique('Übersetzung'));
      const editor = await openEditor(page, node);
      await expect(editor.locator('[class*="PuckHeader-title"]')).toHaveText(note);
    });
  }
});
