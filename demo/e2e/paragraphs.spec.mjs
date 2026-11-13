// Paragraphs: a paragraph type is a block, edited with the paragraph's own form in the right column and saved with
// the page. A Paragraph block can be saved to the library and used on other pages.
// Needs: the paragraph type text_block (a title and a formatted text) and the Paragraphs Library module, both of
// the recipe frame_builder_paragraphs (setup.sh).
import { test, expect } from '@playwright/test';
import {
  addBlock, asVisitor, block, createPage, draftKept, editorOf, login, message, openEditor, openPanel, rightColumn, save, savedTypes, selectBlock, unique,
  unsavedMark, waitForEditor,
} from './helpers.mjs';

/** The form of the selected Paragraph block. */
const paragraphForm = (editor) => rightColumn(editor).frameLocator('iframe.frame-builder-paragraph-frame');
const titleInput = (editor) => paragraphForm(editor).locator('input[name="field_frame_title[0][value]"]');

/** Waits until the form of a paragraph kept its values (0.7 s after typing). Start it before typing. */
const paragraphKept = (page) => page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/builder/paragraph') && response.request().method() === 'POST', { timeout: 15000 });

/** Fills the title and, when given, the formatted text (CKEditor) of the selected Text block. */
async function fill(page, editor, title, text = null) {
  await titleInput(editor).waitFor();
  let kept = paragraphKept(page);
  await titleInput(editor).fill(title);
  await kept;
  if (text !== null) {
    kept = paragraphKept(page);
    const body = paragraphForm(editor).locator('.ck-editor__editable');
    await body.click();
    await page.keyboard.type(text);
    await kept;
  }
}

/** The Library box of the selected component. */
const libraryBox = (editor) => rightColumn(editor).locator('.frame-builder-meta:visible');

/** What a visitor sees of the Text block paragraphs of a page. */
const paragraphs = (browser, node) => asVisitor(browser, `/node/${node}`, (visit) => visit.locator('.paragraph--type--text-block').allInnerTexts().then((texts) => texts.map((text) => text.replace(/\s+/g, ' ').trim())));

test.describe('Paragraphs', () => {
  test('a Paragraph block is filled in with its own form, shown on the canvas and published with the page', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Paragraph'));
    let editor = editorOf(page);

    const id = await addBlock(editor, 'Text block');
    await selectBlock(editor, id);
    await expect(rightColumn(editor)).toContainText('Kept as you type, and saved with the page');
    await fill(page, editor, 'A paragraph title', 'Its formatted text.');
    // The canvas shows the paragraph as it is in the form; nothing is saved yet.
    await expect(block(editor, id)).toContainText('A paragraph title');
    await expect(block(editor, id)).toContainText('Its formatted text.');
    await expect(unsavedMark(editor)).toBeVisible();
    expect(await paragraphs(browser, node)).toEqual([]);

    expect(await save(editor, 'Publish')).toContain('The page has been published.');
    await expect(unsavedMark(editor)).toHaveCount(0);
    expect(await paragraphs(browser, node)).toEqual(['A paragraph title Its formatted text.']);

    // Opened again: the layout names the saved paragraph and its form has the values.
    editor = await openEditor(page, node);
    await expect(message(editor)).toHaveCount(0);
    expect(await savedTypes(editor)).toEqual(['drupal_paragraph:text_block']);
    await expect(block(editor, id)).toContainText('A paragraph title');
    await selectBlock(editor, id);
    await expect(titleInput(editor)).toHaveValue('A paragraph title');

    // A change of the form is a new revision of the paragraph on the next save.
    await fill(page, editor, 'A changed title');
    await expect(block(editor, id)).toContainText('A changed title');
    expect(await paragraphs(browser, node)).toEqual(['A paragraph title Its formatted text.']);
    await save(editor, 'Publish');
    expect(await paragraphs(browser, node)).toEqual(['A changed title Its formatted text.']);
  });

  test('what was typed in the form of a paragraph survives a reload, and is dropped with the unsaved changes', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Paragraph draft'));
    let editor = editorOf(page);
    const kept = draftKept(page, 'drupal_paragraph:text_block');
    const id = await addBlock(editor, 'Text block');
    await kept;
    await selectBlock(editor, id);
    // The block is marked as having unsaved values of its form.
    const again = draftKept(page, '"draft"');
    await fill(page, editor, 'Typed, not saved');
    await expect(block(editor, id)).toContainText('Typed, not saved');
    await again;

    await page.reload();
    editor = await waitForEditor(page);
    await expect(message(editor)).toContainText('were restored');
    await expect(block(editor, id)).toContainText('Typed, not saved');
    const reloaded = page.waitForResponse((response) => /\/builder\/frame$/.test(new URL(response.url()).pathname));
    await message(editor).getByRole('button', { name: 'Discard them' }).click();
    await reloaded;
    editor = await waitForEditor(page);
    await expect(editor.locator('[data-puck-component]')).toHaveCount(0);
    expect(await paragraphs(browser, node)).toEqual([]);
  });

  test('a Paragraph block saved to the library is used on another page, and an update of it shows on every page', async ({ page, browser }) => {
    await login(page);
    const name = unique('Promo');

    // Page one: a paragraph, saved to the library. Here it becomes the saved block.
    const first = await createPage(page, 'landing_page', unique('Library source'));
    let editor = editorOf(page);
    const id = await addBlock(editor, 'Text block');
    await selectBlock(editor, id);
    await fill(page, editor, 'Shared title', 'Shared text.');
    const box = libraryBox(editor);
    await expect(box).toContainText('Type: Text block');
    await expect(box.getByRole('button', { name: 'Save to library' })).toBeDisabled();
    await box.getByPlaceholder('Name of the saved block').fill(name);
    await box.getByRole('button', { name: 'Save to library' }).click();
    await expect(box).toContainText('Used from the library: shown as it is saved now.');
    await expect(box).toContainText(`Name: ${name}`);
    await expect(block(editor, id)).toContainText('Shared title');
    await save(editor, 'Publish');
    expect(await paragraphs(browser, first)).toEqual(['Shared title Shared text.']);

    // Page two: the saved block is in the Library group of the Blocks panel.
    const second = await createPage(page, 'landing_page', unique('Library user'));
    editor = editorOf(page);
    await openPanel(editor, 'Blocks');
    await expect(editor.locator('.frame-builder-blocks__group', { hasText: 'Library' }).locator('.frame-builder-block__label', { hasText: name }).first()).toBeVisible();
    const linked = await addBlock(editor, name);
    await expect(block(editor, linked)).toContainText('Shared title');
    await save(editor, 'Publish');
    expect(await savedTypes(await openEditor(page, second))).toEqual([expect.stringMatching(/^drupal_saved:[0-9a-f-]{36}$/)]);
    expect(await paragraphs(browser, second)).toEqual(['Shared title Shared text.']);

    // Changed through a copy on page two, then "Update the saved block": page one follows without being saved.
    editor = editorOf(page);
    await selectBlock(editor, linked);
    await libraryBox(editor).getByRole('button', { name: 'Edit a copy here' }).click();
    await expect(libraryBox(editor)).toContainText('A copy of the saved block');
    await fill(page, editor, 'Shared title, updated');
    await expect(block(editor, linked)).toContainText('Shared title, updated');
    // Not by its label: the ids of the right column exist twice (see build.spec.mjs).
    await libraryBox(editor).locator('.frame-builder-meta__revision textarea').fill('A new title');
    await libraryBox(editor).getByRole('button', { name: 'Update the saved block' }).click();
    await expect(libraryBox(editor)).toContainText('Used from the library');
    await expect(libraryBox(editor)).toContainText('Log: A new title');
    expect(await paragraphs(browser, first)).toEqual(['Shared title, updated Shared text.']);
    await save(editor, 'Publish');
    expect(await paragraphs(browser, second)).toEqual(['Shared title, updated Shared text.']);

    // With the Paragraphs Library module the saved block is an item of that library.
    await page.goto('/admin/content/paragraphs');
    await expect(page.locator('table')).toContainText(name);
  });
});
