// Revisions: every save of the builder is a revision of the page, and reverting one in Drupal's Revisions tab
// brings its layout back, on the page and in the editor.
// Needs: the landing_page content type (setup.sh).
import { test, expect } from '@playwright/test';
import { asVisitor, block, blockIds, createPage, editorOf, addBlock, headings, login, openEditor, save, selectBlock, setField, unique } from './helpers.mjs';

test.describe('Revisions', () => {
  test('two saves are two revisions; a revert brings the first layout back to the page and the editor', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Revisions'));
    let editor = editorOf(page);

    // First version: one heading.
    const first = await addBlock(editor, 'Heading');
    await selectBlock(editor, first);
    await setField(editor, 'Text', 'Version one');
    await expect(block(editor, first)).toContainText('Version one');
    await save(editor, 'Publish');
    // Second version: another text, and a quote.
    await setField(editor, 'Text', 'Version two');
    await expect(block(editor, first)).toContainText('Version two');
    const quote = await addBlock(editor, 'Quote', block(editor, first), { x: 0.5, y: 0.95 });
    await save(editor, 'Publish');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['Version two']);

    // The Revisions tab: the creation of the page and the two saves, newest first.
    await page.goto(`/node/${node}/revisions`);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0)).toContainText('Current revision');
    await expect(rows.nth(0)).toContainText('Edited with the page builder.');
    await expect(rows.nth(1)).toContainText('Edited with the page builder.');
    await rows.nth(1).getByRole('link', { name: 'Revert', exact: true }).click();
    await page.getByRole('button', { name: 'Revert', exact: true }).click();
    await expect(page.locator('.messages--status')).toContainText('has been reverted to the revision');
    await expect(rows).toHaveCount(4);

    expect(await asVisitor(browser, `/node/${node}`, async (visit) => ({ headings: await headings(visit), quotes: await visit.locator('blockquote').count() })))
      .toEqual({ headings: ['Version one'], quotes: 0 });

    editor = await openEditor(page, node);
    expect(await blockIds(editor)).toEqual([first]);
    await expect(block(editor, first)).toContainText('Version one');
    await expect(block(editor, quote)).toHaveCount(0);

    // Work goes on from the reverted layout.
    await selectBlock(editor, first);
    await setField(editor, 'Text', 'Version three');
    await save(editor, 'Publish');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['Version three']);
  });
});
