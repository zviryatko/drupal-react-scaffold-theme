// Preview: the page as visitors would see it, with the unsaved layout and field values, at the widths of the
// theme's breakpoints. Nothing is saved by it.
// Needs: the landing_page content type; the breakpoints of the Frame theme (Mobile 375, Tablet 768, Desktop 1200).
import { test, expect } from '@playwright/test';
import {
  asVisitor, block, createPage, editorOf, fieldsKept, heading, headings, login, openEditor, pageForm, selectBlock, setField, settingsOf,
  storeLayout, unique, unsavedMark, waitForEditor,
} from './helpers.mjs';

const layout = [
  { type: 'drupal_field:title', props: { id: 'title', formatter: 'string', label: 'hidden' } },
  { type: 'frame:columns', props: { id: 'columns', layout: '2', align: 'stretch', stack: true, content: [heading('left', 'Left'), heading('right', 'Right')] } },
];

test.describe('Preview', () => {
  test('shows unsaved changes at the breakpoints of the theme, and saves nothing', async ({ page, browser }) => {
    await login(page);
    const title = unique('Preview');
    const node = await createPage(page, 'landing_page', title);
    await storeLayout(editorOf(page), layout);
    const editor = await openEditor(page, node);
    expect((await settingsOf(editor)).viewports).toEqual([{ label: 'Mobile', width: 375 }, { label: 'Tablet', width: 768 }, { label: 'Desktop', width: 1200 }]);
    // Nothing unsaved: the saved page is offered instead.
    await expect(editor.getByRole('button', { name: 'Preview', exact: true })).toHaveCount(0);
    await expect(editor.getByRole('link', { name: 'View', exact: true })).toBeVisible();

    // An unsaved title, and an unsaved text typed right before the preview is opened.
    const kept = fieldsKept(page, 'page', `${title} unsaved`);
    await pageForm(editor).locator('input[name="title[0][value]"]').fill(`${title} unsaved`);
    await kept;
    await selectBlock(editor, 'left');
    await setField(editor, 'Text', 'Left, unsaved');
    await editor.getByRole('button', { name: 'Preview', exact: true }).click();

    const dialog = editor.getByRole('dialog', { name: 'Preview' });
    const frame = dialog.locator('iframe.frame-builder-preview__frame');
    const preview = dialog.frameLocator('iframe.frame-builder-preview__frame');
    await expect(preview.locator('.messages--warning, [role="alert"], body').first()).toContainText('Preview of your unsaved layout. Visitors still see the saved page.');
    expect(await headings(preview)).toEqual(['Left, unsaved', 'Right']);
    await expect(preview.locator('.frame-builder-field').first()).toHaveText(`${title} unsaved`);
    // No admin toolbar or editor in the frame.
    await expect(preview.locator('#toolbar-administration, #frame-builder')).toHaveCount(0);
    await expect(dialog.getByRole('link', { name: 'Open in a new tab' })).toHaveAttribute('href', new RegExp(`/node/${node}/builder/preview$`));

    const measure = () => frame.evaluate((element) => ({
      width: element.contentWindow.innerWidth,
      columns: getComputedStyle(element.contentDocument.querySelector('.layout-columns__items')).gridTemplateColumns.split(' ').length,
    }));
    const pick = async (name) => {
      const button = dialog.getByRole('button', { name });
      await button.click();
      await expect(button).toHaveAttribute('aria-pressed', 'true');
    };
    await expect(dialog.getByRole('button', { name: 'Full width' })).toHaveAttribute('aria-pressed', 'true');
    // The page really is that wide: its media queries apply, and Columns become one column on a phone.
    await pick('Mobile · 375');
    await expect.poll(measure).toEqual({ width: 375, columns: 1 });
    await pick('Tablet · 768');
    await expect.poll(async () => (await measure()).width).toBe(768);
    await pick('Desktop · 1200');
    await expect.poll(measure).toEqual({ width: 1200, columns: 2 });
    await dialog.getByRole('button', { name: 'Full width' }).click();
    await expect(frame).toHaveAttribute('style', /width: 100%/);

    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(unsavedMark(editor)).toBeVisible();
    await expect(block(editor, 'left')).toContainText('Left, unsaved');

    // Visitors still see the saved page.
    expect(await asVisitor(browser, `/node/${node}`, async (visit) => ({ headings: await headings(visit), title: await visit.locator('.frame-builder-field').first().innerText() })))
      .toEqual({ headings: ['Left', 'Right'], title });
  });

  test('the chosen width is marked, also "Full width" after another width was used', async ({ page }) => {
    await login(page);
    await createPage(page, 'landing_page', unique('Preview widths'));
    const editor = editorOf(page);
    await storeLayout(editor, layout);
    await page.reload();
    await waitForEditor(page);
    await selectBlock(editor, 'left');
    await setField(editor, 'Text', 'Changed');
    await editor.getByRole('button', { name: 'Preview', exact: true }).click();
    const dialog = editor.getByRole('dialog', { name: 'Preview' });
    for (const name of ['Mobile · 375', 'Full width']) {
      await dialog.getByRole('button', { name }).click();
      await expect(dialog.getByRole('button', { name })).toHaveAttribute('aria-pressed', 'true');
      await expect(dialog.locator('button[aria-pressed="true"]')).toHaveCount(1);
    }
  });

  test('the preview page is only for those who may edit the page', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Preview access'));
    await storeLayout(editorOf(page), layout);
    expect(await asVisitor(browser, `/node/${node}/builder/preview`)).toBe(403);
  });
});
