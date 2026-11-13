// The buttons inside text fields: a T in a circle opens the list of tokens and inserts the one picked where the caret
// was; a cross in a circle clears the field. Shown on hover and on focus.
// Needs: the landing_page content type and the Heading component of the base theme.
import { test, expect } from '@playwright/test';
import { createPage, editorOf, heading, headings, login, openEditor, rightColumn, selectBlock, storeLayout, unique } from './helpers.mjs';

test.describe('Buttons in text fields', () => {
  test('a token is inserted where the caret was, and the field is cleared', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Field buttons'));
    await storeLayout(editorOf(page), [heading('h', 'Welcome to our site')]);
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'h');
    const column = rightColumn(editor);
    const field = column.locator('.frame-builder-form-item', { has: editor.locator('label', { hasText: /^Text$/ }) }).first();
    const input = field.locator('input').first();
    const actions = field.locator('.frame-builder-field-actions');
    const clear = field.getByRole('button', { name: 'Clear Text' });
    const token = field.getByRole('button', { name: 'Insert a token into Text' });

    // Hidden until the pointer is over the field or it has the focus.
    await editor.locator('[class*="PuckHeader"]').first().hover();
    await expect(actions).toHaveCSS('opacity', '0');
    await input.hover();
    await expect(actions).toHaveCSS('opacity', '1');
    await input.focus();
    await page.keyboard.press('Tab');
    await expect(token).toBeFocused();

    // A token goes where the caret was: after "Welcome to ".
    await input.focus();
    await input.evaluate((element) => element.setSelectionRange(11, 11));
    await token.click();
    const dialog = editor.getByRole('dialog', { name: 'Available tokens' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('searchbox', { name: 'Filter tokens' }).fill('site name');
    await dialog.getByRole('button', { name: '[site:name]' }).first().click();
    await expect(dialog).toHaveCount(0);
    await expect(input).toHaveValue('Welcome to [site:name]our site');
    await expect(input).toBeFocused();

    // Cleared: the field is empty, keeps the focus, and the cross is gone until there is a value again.
    await clear.click();
    await expect(input).toHaveValue('');
    await expect(input).toBeFocused();
    await expect(clear).toHaveCount(0);
    await expect(token).toHaveCount(1);
    await input.fill('Hello');
    await expect.poll(() => headings(editor)).toEqual(['Hello']);
  });

  test('the buttons are in long text fields and in the values of a list too', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Field buttons more'));
    await storeLayout(editorOf(page), [
      { type: 'frame:quote', props: { id: 'q', text: 'The best evening of our trip.', size: 'm' } },
      { type: 'frame:link-list', props: { id: 'l', links: [{ label: 'About us', url: '/' }], layout: 'stacked', marker: 'arrow' } },
    ]);
    const editor = await openEditor(page, node);

    // A long text field: the buttons at the top of the field; the token goes where the caret was.
    await selectBlock(editor, 'q');
    const quote = rightColumn(editor).locator('textarea').first();
    await quote.focus();
    await quote.evaluate((element) => element.setSelectionRange(element.value.length, element.value.length));
    await rightColumn(editor).getByRole('button', { name: 'Insert a token into Quote' }).click();
    const dialog = editor.getByRole('dialog', { name: 'Available tokens' });
    await dialog.getByRole('searchbox', { name: 'Filter tokens' }).fill('site name');
    await dialog.getByRole('button', { name: '[site:name]' }).first().click();
    await expect(quote).toHaveValue('The best evening of our trip.[site:name]');
    await rightColumn(editor).getByRole('button', { name: 'Clear Quote' }).click();
    await expect(quote).toHaveValue('');

    // In a row of a list: the label names the value.
    await selectBlock(editor, 'l');
    const row = rightColumn(editor).locator('.frame-builder-multiple__row').first();
    const text = row.locator('input[type="text"]').first();
    await text.hover();
    await row.getByRole('button', { name: 'Clear Text' }).click();
    await expect(text).toHaveValue('');
    await expect(row.getByRole('button', { name: 'Insert a token into Text' })).toHaveCount(1);
  });
});
