// Building a page: a new page continues in the builder, components are dragged in from the Blocks panel, their
// props are edited in the right column, the page is published and visitors see it.
// Needs: the landing_page content type, the components of the base theme, the users admin and editor (setup.sh).
import { test, expect } from '@playwright/test';
import {
  EDITOR, addBlock, asVisitor, block, blockIds, collectErrors, createPage, editorOf, field, headings, login, message, openEditor, openPanel,
  pageForm, panelLabels, rightColumn, save, savedTypes, selectBlock, setField, unique, unsavedMark, zone,
} from './helpers.mjs';

test.describe('Build a page', () => {
  test('a new page continues in the builder, which is its edit page', async ({ page }) => {
    await login(page);
    const title = unique('New page');
    const node = await createPage(page, 'landing_page', title);
    const editor = editorOf(page);

    expect(page.url()).toMatch(new RegExp(`/node/${node}/edit$`));
    // Landing page has no fields of its own (the title is in the page settings): no Content panel.
    expect(await panelLabels(editor)).toEqual(['Blocks', 'Structure']);
    await expect(pageForm(editor).locator('input[name="title[0][value]"]')).toHaveValue(title);
    await expect(blockIds(editor)).resolves.toEqual([]);
    // Nothing is unsaved: the header offers the saved page, not a preview.
    await expect(unsavedMark(editor)).toHaveCount(0);
    await expect(editor.getByRole('link', { name: 'View', exact: true })).toHaveAttribute('href', new RegExp(`/node/${node}$`));
    for (const name of ['Publish', 'Draft']) {
      await expect(editor.getByRole('button', { name, exact: true })).toBeVisible();
    }
  });

  test('components are dragged in, nested, edited and published; visitors see the page', async ({ page, browser }) => {
    const errors = collectErrors(page);
    await login(page);
    const title = unique('Built page');
    const node = await createPage(page, 'landing_page', title);
    const editor = editorOf(page);

    // A heading on the page, columns under it, a heading and the title of the page in the columns.
    const top = await addBlock(editor, 'Heading');
    await expect(block(editor, top)).toContainText('A heading');
    const columns = await addBlock(editor, 'Columns', zone(editor), { x: 0.5, y: 0.9 });
    expect(await blockIds(editor)).toEqual([top, columns]);
    const inner = await addBlock(editor, 'Heading', zone(editor, `${columns}:content`));
    const titleField = await addBlock(editor, 'Title', zone(editor, `${columns}:content`), { x: 0.9, y: 0.5 });
    await expect(block(editor, columns).locator('[data-puck-component]')).toHaveCount(2);
    await expect(block(editor, titleField)).toContainText(title);
    await expect(unsavedMark(editor)).toBeVisible();
    await expect(editor.getByRole('button', { name: 'Preview', exact: true })).toBeVisible();

    // Props are edited in the right column; Drupal renders the component again and the canvas follows.
    await selectBlock(editor, top);
    await expect(rightColumn(editor).locator('[class*="SidebarSection-title"]').first()).toContainText('Heading');
    await setField(editor, 'Text', 'Made in the browser');
    await expect(block(editor, top).locator('h2')).toHaveText('Made in the browser');
    await field(editor, 'Level').selectOption('h3');
    await expect(block(editor, top).locator('h3')).toHaveText('Made in the browser');
    await setField(editor, 'Line above', 'End to end');
    await expect(block(editor, top).locator('.heading-block__eyebrow')).toHaveText('End to end');
    // A token is replaced by Drupal.
    await selectBlock(editor, inner);
    await setField(editor, 'Text', 'About [node:title]');
    await expect(block(editor, inner)).toContainText(`About ${title}`);

    expect(await save(editor, 'Publish')).toContain('The page has been published.');
    await expect(unsavedMark(editor)).toHaveCount(0);
    await expect(editor.getByRole('link', { name: 'View', exact: true })).toBeVisible();

    // The published page, as a visitor.
    const seen = await asVisitor(browser, `/node/${node}`, async (visit, response) => ({
      status: response.status(),
      headings: await headings(visit),
      level: await visit.locator('.heading-block__text').first().evaluate((element) => element.tagName),
      eyebrow: await visit.locator('.heading-block__eyebrow').textContent(),
      inColumns: await visit.locator('.layout-columns__items > *').count(),
      columns: await visit.locator('.layout-columns__items').evaluate((element) => getComputedStyle(element).gridTemplateColumns.split(' ').length),
      field: await visit.locator('.layout-columns__items .frame-builder-field').innerText(),
      editorCode: await visit.locator('script[src*="frame_builder/js/editor"]').count(),
    }));
    expect(seen).toEqual({
      status: 200,
      headings: ['Made in the browser', `About ${title}`],
      level: 'H3',
      eyebrow: 'End to end',
      inColumns: 2,
      columns: 2,
      field: title,
      editorCode: 0,
    });

    // Opened again, the editor shows what was saved and nothing to restore.
    const again = await openEditor(page, node);
    expect(await savedTypes(again)).toEqual(['frame:heading', 'frame:columns', 'frame:heading', 'drupal_field:title']);
    await expect(block(again, top).locator('h3')).toHaveText('Made in the browser');
    await expect(block(again, columns).locator('[data-puck-component]')).toHaveCount(2);
    await expect(message(again)).toHaveCount(0);
    expect(errors, 'no JavaScript errors').toEqual([]);
  });

  test('the Blocks panel groups what can be placed and its search forgives typos', async ({ page }) => {
    await login(page);
    await createPage(page, 'landing_page', unique('Blocks'));
    const editor = editorOf(page);
    await openPanel(editor, 'Blocks');
    const groups = await editor.locator('.frame-builder-blocks__group > summary').evaluateAll((elements) => elements.map((element) => element.firstChild.textContent));
    expect(groups).toEqual(expect.arrayContaining(['Layout', 'Content', 'Fields', 'Blocks', 'Paragraphs']));

    const search = editor.getByRole('searchbox', { name: 'Search blocks' });
    const found = editor.locator('.frame-builder-block__label');
    await search.fill('Headng');
    await expect(found.first()).toContainText('Heading');
    await search.fill('colums');
    await expect(found.first()).toContainText('Columns');
    await search.fill('qqqqzzzz');
    await expect(editor.locator('.frame-builder-blocks__note')).toContainText('Nothing matches “qqqqzzzz”.');
    await expect(found).toHaveCount(0);
  });

  test('the Structure panel shows the tree, and a component is removed with the Delete key', async ({ page }) => {
    await login(page);
    await createPage(page, 'landing_page', unique('Structure'));
    const editor = editorOf(page);
    const stack = await addBlock(editor, 'Stack');
    const inner = await addBlock(editor, 'Heading', zone(editor, `${stack}:content`));
    const quote = await addBlock(editor, 'Quote', zone(editor), { x: 0.5, y: 0.95 });
    expect(await blockIds(editor)).toEqual([stack, inner, quote]);

    await openPanel(editor, 'Structure');
    const layers = editor.locator('[class*="LayerTreeRoot"] [class*="Layer-name"]');
    // Each with the group it comes from.
    await expect(layers).toHaveText(['Layout: Stack', 'Content: Heading', 'Content: Quote']);
    // A click on a layer selects the component.
    await layers.nth(1).click();
    await expect(rightColumn(editor).locator('[class*="SidebarSection-title"]').first()).toContainText('Heading');

    await selectBlock(editor, quote);
    await page.keyboard.press('Delete');
    await expect(block(editor, quote)).toHaveCount(0);
    await expect(layers).toHaveText(['Layout: Stack', 'Content: Heading']);
  });

  test('an editor with the Content editor role builds and saves a page', async ({ page, browser }) => {
    await login(page, EDITOR);
    const title = unique('By the editor');
    const node = await createPage(page, 'landing_page', title);
    const editor = editorOf(page);
    const id = await addBlock(editor, 'Heading');
    await selectBlock(editor, id);
    await setField(editor, 'Text', 'Written by an editor');
    await expect(block(editor, id)).toContainText('Written by an editor');
    // The role may not change the state of a page (in Drupal that takes "Administer content"): one button, Save.
    await expect(editor.getByRole('button', { name: 'Publish', exact: true })).toHaveCount(0);
    await expect(editor.getByRole('button', { name: 'Draft', exact: true })).toHaveCount(0);
    expect(await save(editor, 'Save')).toContain('The page has been saved.');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['Written by an editor']);
  });

  test('the labels of the right column belong to the fields that are shown', async ({ page }) => {
    test.fixme(!process.env.E2E_KNOWN_BUGS, 'Puck renders the fields twice (a hidden copy for narrow windows) with the same ids: a visible label points at the hidden input.');
    await login(page);
    await createPage(page, 'landing_page', unique('Labels'));
    const editor = editorOf(page);
    await selectBlock(editor, await addBlock(editor, 'Heading'));
    await expect(rightColumn(editor).getByLabel('Line above', { exact: true }).locator('visible=true')).toHaveCount(1);
    await rightColumn(editor).locator('label:visible', { hasText: 'Line above' }).click();
    await expect(field(editor, 'Line above')).toBeFocused();
  });
});
