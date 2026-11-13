// Field groups: with the Field Group module every top-level group of the form is a panel of the editor.
// Needs: the content type e2e_grouped (setup.sh): "Lead" in no group (the Content panel), the group "Story" with
// "Story text", the group "Facts" (icon "list") with "Source" and the required "Must have".
import { test, expect } from '@playwright/test';
import { asVisitor, block, contentForm, createPage, editorOf, fieldsKept, login, message, openEditor, openPanel, panelLabels, save, storeLayout, unique, unsavedMark } from './helpers.mjs';

const input = (editor, name) => contentForm(editor).locator(`input[name="${name}[0][value]"]`);
const fieldBlock = (name) => ({ type: `drupal_field:${name}`, props: { id: name, formatter: 'string', label: 'hidden' } });
const fields = ['field_e2e_lead', 'field_e2e_story', 'field_e2e_source', 'field_e2e_must'];

/** A page of the grouped type with every field on the canvas, opened in the editor. */
async function groupedPage(page) {
  const node = await createPage(page, 'e2e_grouped', unique('Grouped'), { 'field_e2e_must[0][value]': 'Needed' });
  await storeLayout(editorOf(page), fields.map(fieldBlock));
  return { node, editor: await openEditor(page, node) };
}

/** Types into a field of the panel that is open and waits until the form kept the value. */
async function type(page, editor, name, value) {
  const kept = fieldsKept(page, 'fields', value);
  await input(editor, name).fill(value);
  await kept;
}

test.describe('Field groups', () => {
  test('every top-level group is a panel in the rail, with its own fields', async ({ page }) => {
    await login(page);
    const { editor } = await groupedPage(page);
    expect(await panelLabels(editor)).toEqual(['Content', 'Story', 'Facts', 'Blocks', 'Structure']);

    const shown = () => contentForm(editor).locator('input[type="text"]:visible').evaluateAll((elements) => elements.map((element) => element.name.replace('[0][value]', '')));
    await expect.poll(shown).toEqual(['field_e2e_lead']);
    await openPanel(editor, 'Story');
    await expect.poll(shown).toEqual(['field_e2e_story']);
    await openPanel(editor, 'Facts');
    await expect.poll(shown).toEqual(['field_e2e_source', 'field_e2e_must']);
    await openPanel(editor, 'Content');
    await expect.poll(shown).toEqual(['field_e2e_lead']);

    // The button of a group shows its icon ("list" for Facts) or the initials of its label.
    const button = (label) => editor.locator('[class*="Nav-list"] > li', { hasText: label }).locator('[class*="NavItem-linkIcon"]');
    await expect(button('Story')).toHaveText('St');
    await expect(button('Facts').locator('svg, i, [class*="icon"]').first()).toBeVisible();
    await expect(button('Facts')).toHaveText('');
  });

  test('fields edited in several panels show on the canvas and are stored by one save', async ({ page, browser }) => {
    await login(page);
    const { node, editor } = await groupedPage(page);

    await type(page, editor, 'field_e2e_lead', 'The lead');
    await openPanel(editor, 'Story');
    await type(page, editor, 'field_e2e_story', 'The story');
    await openPanel(editor, 'Facts');
    await type(page, editor, 'field_e2e_source', 'The source');
    await expect(unsavedMark(editor)).toBeVisible();
    // The canvas follows what was typed in any panel, before anything is saved.
    await expect(block(editor, 'field_e2e_lead')).toContainText('The lead');
    await expect(block(editor, 'field_e2e_story')).toContainText('The story');
    await expect(block(editor, 'field_e2e_source')).toContainText('The source');
    // Going back to a panel shows what was typed there.
    await openPanel(editor, 'Story');
    await expect(input(editor, 'field_e2e_story')).toHaveValue('The story');

    expect(await save(editor, 'Publish')).toContain('The page has been published.');
    await expect(unsavedMark(editor)).toHaveCount(0);
    expect(await asVisitor(browser, `/node/${node}`, (visit) => visit.locator('.frame-builder-field').allInnerTexts()))
      .toEqual(['The lead', 'The story', 'The source', 'Needed']);

    const again = await openEditor(page, node);
    await expect(message(again)).toHaveCount(0);
    await expect(input(again, 'field_e2e_lead')).toHaveValue('The lead');
    await openPanel(again, 'Facts');
    await expect(input(again, 'field_e2e_source')).toHaveValue('The source');
  });

  test('an empty required field in a panel that is not open stops the save with a message, and saving works once it is filled', async ({ page, browser }) => {
    await login(page);
    const { node, editor } = await groupedPage(page);

    // The required field is emptied in Facts; the save is pressed with Story open.
    await openPanel(editor, 'Facts');
    await type(page, editor, 'field_e2e_must', '');
    await openPanel(editor, 'Story');
    await type(page, editor, 'field_e2e_story', 'A story without its fact');

    const started = Date.now();
    const error = await save(editor, 'Publish', { expectError: true });
    expect(error).toContain('The page could not be saved. Check the panels with the fields of the page');
    // Drupal answered; the editor did not wait for its own time limit (30 seconds).
    expect(Date.now() - started).toBeLessThan(15000);
    await expect(unsavedMark(editor)).toBeVisible();
    expect(await asVisitor(browser, `/node/${node}`, (visit) => visit.locator('.frame-builder-field').allInnerTexts())).toEqual(['Needed']);

    // The form says which field, in its panel.
    await openPanel(editor, 'Facts');
    await expect(contentForm(editor).locator('.messages--error, .form-item--error-message').first()).toContainText('Must have field is required.');
    await expect(input(editor, 'field_e2e_must')).toHaveClass(/error/);

    await type(page, editor, 'field_e2e_must', 'Filled in');
    expect(await save(editor, 'Publish')).toContain('The page has been published.');
    expect(await asVisitor(browser, `/node/${node}`, (visit) => visit.locator('.frame-builder-field').allInnerTexts()))
      .toEqual(['A story without its fact', 'Filled in']);
  });
});
