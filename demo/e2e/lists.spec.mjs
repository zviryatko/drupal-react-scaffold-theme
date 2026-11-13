// Lists: a prop of the type array is a field with several values, as a multiple value field of Drupal. Values are
// added, filled in with the fields of their kind, dragged into order (or put in order with row weights) and removed; the canvas and the published page follow.
// Needs: the landing_page content type and the Links component of the base theme (frame:link-list).
import { test, expect } from '@playwright/test';
import { asVisitor, createPage, dismissMessage, editorOf, login, message, openEditor, rightColumn, save, selectBlock, storeLayout, unique } from './helpers.mjs';

const links = (root) => root.locator('.link-list__link').evaluateAll((elements) => elements.map((element) => [element.textContent, element.getAttribute('href')]));

test.describe('Lists', () => {
  test('the links of a Links block are added, filled in, moved and removed, and the page shows them', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Lists'));
    await storeLayout(editorOf(page), [{ type: 'frame:link-list', props: { id: 'links', links: [{ label: 'About us', url: '/' }, { label: 'Contact', url: '/' }], layout: 'stacked', marker: 'arrow' } }]);
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'links');
    // As Drupal draws a multiple value field: a table with the label in its header, a row per value.
    const list = rightColumn(editor).locator('.frame-builder-multiple').first();
    const rows = list.locator('.frame-builder-multiple__row');
    // The text of each row: its fields are open in it, as the URL and the link text of Drupal's link widget.
    const texts = () => rows.evaluateAll((elements) => elements.map((row) => row.querySelector('input[type="text"], textarea')?.value));
    await expect(list.locator('th h4')).toHaveText('Links');
    await expect.poll(texts).toEqual(['About us', 'Contact']);

    // A new value has the fields the component says: a text, a link, a checkbox.
    await list.getByRole('button', { name: 'Add another item' }).click();
    await expect(rows).toHaveCount(3);
    const fields = rows.nth(2).locator('.frame-builder-multiple__fields > .frame-builder-form-item');
    await expect(fields.locator('.frame-builder-label')).toHaveText(['Text', 'Link', 'Open in a new tab']);
    await fields.nth(0).locator('input, textarea').first().fill('Our programmes');
    await fields.nth(1).locator('input, textarea').first().fill('https://example.org/programmes');
    await expect.poll(() => links(editor)).toEqual([['About us', '/'], ['Contact', '/'], ['Our programmes', 'https://example.org/programmes']]);

    // Ordered by the handle of a row, as in Drupal: with the arrow keys...
    const handles = list.locator('.frame-builder-multiple__handle');
    await handles.nth(2).focus();
    await page.keyboard.press('ArrowUp');
    await expect.poll(texts).toEqual(['About us', 'Our programmes', 'Contact']);
    await expect(rows.nth(1)).toHaveClass(/drag-previous/);
    // A row that moved is marked, and the table says that there are unsaved changes.
    await expect(rows.nth(1).locator('.frame-builder-multiple__marker')).toHaveText('*');
    await expect(list.locator('.frame-builder-multiple__changed')).toHaveText('* You have unsaved changes.');
    // ...and by dragging: the first value below the last one. The row is yellow while it is dragged.
    await handles.nth(0).scrollIntoViewIfNeeded();
    const from = await handles.nth(0).boundingBox();
    const last = await rows.nth(2).boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2, last.y + last.height - 2, { steps: 12 });
    await expect(list.locator('.frame-builder-multiple__row.drag')).toHaveCount(1);
    await page.mouse.up();
    await expect(list.locator('.frame-builder-multiple__row.drag')).toHaveCount(0);
    await expect.poll(texts).toEqual(['Our programmes', 'Contact', 'About us']);
    // "Show row weights": an Order column in place of the handles.
    await list.getByRole('button', { name: 'Show row weights' }).click();
    await expect(handles).toHaveCount(0);
    await list.getByLabel('Weight for row 1').selectOption('1');
    await expect.poll(texts).toEqual(['Contact', 'Our programmes', 'About us']);
    await list.getByRole('button', { name: 'Hide row weights' }).click();
    await list.getByRole('button', { name: 'Remove value 3' }).click();
    await expect.poll(() => links(editor)).toEqual([['Contact', '/'], ['Our programmes', 'https://example.org/programmes']]);

    await save(editor);
    await asVisitor(browser, `/node/${node}`, async (visitor) => {
      expect(await links(visitor)).toEqual([['Contact', '/'], ['Our programmes', 'https://example.org/programmes']]);
    });

    // After a reload the list is as it was saved, with nothing marked as changed.
    const again = await openEditor(page, node);
    await selectBlock(again, 'links');
    const saved = rightColumn(again).locator('.frame-builder-multiple').first();
    await expect.poll(() => saved.locator('.frame-builder-multiple__row').evaluateAll((elements) => elements.map((row) => row.querySelector('input[type="text"]')?.value))).toEqual(['Contact', 'Our programmes']);
    await expect(saved.locator('.frame-builder-multiple__changed')).toHaveCount(0);
  });
  test('what a value of a list requires must be filled in before the page is saved', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Required in a list'));
    await storeLayout(editorOf(page), [{ type: 'frame:link-list', props: { id: 'links', links: [{ label: 'About us', url: '/' }], layout: 'stacked', marker: 'arrow' } }]);
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'links');
    const list = rightColumn(editor).locator('.frame-builder-multiple').first();
    await list.getByRole('button', { name: 'Add another item' }).click();
    const fields = list.locator('.frame-builder-multiple__row').nth(1).locator('.frame-builder-multiple__fields > .frame-builder-form-item');
    await fields.nth(0).locator('input, textarea').first().fill('No address yet');
    // The save is refused and says which value of which component lacks what.
    await save(editor, 'Publish', { expectError: true });
    await expect(message(editor)).toContainText('Links › Links 2: Link is required.');
    await asVisitor(browser, `/node/${node}`, async (visitor) => {
      expect(await links(visitor)).toEqual([['About us', '/']]);
    });
    await dismissMessage(editor);
    await fields.nth(1).locator('input, textarea').first().fill('/contact');
    await save(editor);
    await asVisitor(browser, `/node/${node}`, async (visitor) => {
      expect(await links(visitor)).toEqual([['About us', '/'], ['No address yet', '/contact']]);
    });
  });
});
