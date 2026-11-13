// Unsaved work: the layout and the values of the node form are kept per user in the background. A reload brings
// them back, "Discard them" drops them, a save ends them. Undo, redo and the History timeline cover the layout.
// Needs: the landing_page content type (setup.sh).
import { test, expect } from '@playwright/test';
import {
  addBlock, asVisitor, block, blockIds, createPage, deselect, dismissMessage, draftKept, editorOf, fieldsKept, heading, headings, login, message,
  openEditor, openHistory, pageForm, redoButton, save, selectBlock, setField, storeLayout, undoButton, unique, unsavedMark, waitForEditor,
} from './helpers.mjs';

/** A published page with two headings, opened in the editor. */
async function twoHeadings(page, label) {
  const title = unique(label);
  const node = await createPage(page, 'landing_page', title);
  await storeLayout(editorOf(page), [heading('one', 'First'), heading('two', 'Second')]);
  return { node, title, editor: await openEditor(page, node) };
}

const titleInput = (editor) => pageForm(editor).locator('input[name="title[0][value]"]');

test.describe('Unsaved work', () => {
  test('a reload restores the unsaved layout and field values; visitors still see the saved page', async ({ page, browser }) => {
    await login(page);
    let { node, title, editor } = await twoHeadings(page, 'Restore');

    // A changed prop, a new component and a new title: nothing of it is saved.
    await selectBlock(editor, 'one');
    await setField(editor, 'Text', 'Changed, not saved');
    await expect(block(editor, 'one')).toContainText('Changed, not saved');
    let kept = draftKept(page, 'frame:quote');
    const added = await addBlock(editor, 'Quote', block(editor, 'two'), { x: 0.5, y: 0.95 });
    await kept;
    await deselect(editor);
    kept = fieldsKept(page, 'page', `${title} retitled`);
    await titleInput(editor).fill(`${title} retitled`);
    await kept;
    await expect(unsavedMark(editor)).toBeVisible();

    await page.reload();
    editor = await waitForEditor(page);
    await expect(message(editor)).toContainText(/Your unsaved changes from .+ were restored\./);
    await expect(block(editor, 'one')).toContainText('Changed, not saved');
    expect(await blockIds(editor)).toEqual(['one', 'two', added]);
    await expect(titleInput(editor)).toHaveValue(`${title} retitled`);
    await expect(unsavedMark(editor)).toBeVisible();

    expect(await asVisitor(browser, `/node/${node}`, async (visit) => ({ headings: await headings(visit), title: await visit.title() })))
      .toEqual({ headings: ['First', 'Second'], title: expect.stringContaining(title) });
    expect(await asVisitor(browser, `/node/${node}`, (visit) => visit.title())).not.toContain('retitled');
  });

  test('"Discard them" drops the unsaved layout and field values', async ({ page }) => {
    await login(page);
    let { title, editor } = await twoHeadings(page, 'Discard');
    await selectBlock(editor, 'two');
    let kept = draftKept(page, 'To be dropped');
    await setField(editor, 'Text', 'To be dropped');
    await kept;
    await deselect(editor);
    kept = fieldsKept(page, 'page', 'A title to be dropped');
    await titleInput(editor).fill('A title to be dropped');
    await kept;

    await page.reload();
    editor = await waitForEditor(page);
    await expect(block(editor, 'two')).toContainText('To be dropped');
    // The editor loads again, with what is saved.
    const reloaded = page.waitForResponse((response) => /\/builder\/frame$/.test(new URL(response.url()).pathname));
    await message(editor).getByRole('button', { name: 'Discard them' }).click();
    await reloaded;
    editor = await waitForEditor(page);
    await expect(block(editor, 'two')).toContainText('Second');
    await expect(titleInput(editor)).toHaveValue(title);
    await expect(message(editor)).toHaveCount(0);
    await expect(unsavedMark(editor)).toHaveCount(0);
  });

  test('a save ends the unsaved state: nothing to restore afterwards', async ({ page, browser }) => {
    await login(page);
    let { node, editor } = await twoHeadings(page, 'Saved');
    await selectBlock(editor, 'one');
    const kept = draftKept(page, 'Kept, then saved');
    await setField(editor, 'Text', 'Kept, then saved');
    await kept;
    await page.reload();
    editor = await waitForEditor(page);
    await expect(message(editor)).toContainText('were restored');
    expect(await save(editor, 'Publish')).toContain('The page has been published.');
    await expect(unsavedMark(editor)).toHaveCount(0);

    editor = await openEditor(page, node);
    await expect(message(editor)).toHaveCount(0);
    await expect(block(editor, 'one')).toContainText('Kept, then saved');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['Kept, then saved', 'Second']);
  });

  test('undo and redo step through the changes, and going back to the saved layout leaves nothing unsaved', async ({ page }) => {
    await login(page);
    const { editor } = await twoHeadings(page, 'Undo');
    // The number on the History button: the steps since the save. A step is recorded a moment after the change.
    const count = editor.locator('.frame-builder-history-button__count');
    await expect(undoButton(editor)).toBeDisabled();

    await selectBlock(editor, 'one');
    await setField(editor, 'Text', 'Edited');
    await expect(block(editor, 'one')).toContainText('Edited');
    await expect(count).toHaveText('1');
    await expect(undoButton(editor)).toBeEnabled();
    const quote = await addBlock(editor, 'Quote', block(editor, 'two'), { x: 0.5, y: 0.95 });
    await expect(count).toHaveText('2');
    await expect(unsavedMark(editor)).toBeVisible();

    await undoButton(editor).click();
    await expect(block(editor, quote)).toHaveCount(0);
    await expect(block(editor, 'one')).toContainText('Edited');
    await expect(count).toHaveText('1');
    await expect(redoButton(editor)).toBeEnabled();

    // Back at the saved layout: nothing is unsaved, and the saved page is offered instead of a preview.
    await undoButton(editor).click();
    await expect(block(editor, 'one')).toContainText('First');
    await expect(count).toHaveCount(0);
    await expect(undoButton(editor)).toBeDisabled();
    await expect(unsavedMark(editor)).toHaveCount(0);
    await expect(editor.getByRole('link', { name: 'View', exact: true })).toBeVisible();

    await redoButton(editor).click();
    await expect(block(editor, 'one')).toContainText('Edited');
    await redoButton(editor).click();
    await expect(block(editor, quote)).toHaveCount(1);
    await expect(redoButton(editor)).toBeDisabled();
    await expect(count).toHaveText('2');
    await expect(unsavedMark(editor)).toBeVisible();
  });

  test('undo pressed right after a change takes back that change', async ({ page }) => {
    test.fixme(!process.env.E2E_KNOWN_BUGS, 'Puck records a step about a quarter of a second after the change. Undo inside that time goes one step too far, and the late record then makes the undone state the current step: canvas and history disagree, redo is off.');
    await login(page);
    const { editor } = await twoHeadings(page, 'Undo at once');
    const count = editor.locator('.frame-builder-history-button__count');
    await selectBlock(editor, 'one');
    await setField(editor, 'Text', 'Edited');
    await expect(count).toHaveText('1');
    const quote = await addBlock(editor, 'Quote', block(editor, 'two'), { x: 0.5, y: 0.95 });
    await undoButton(editor).click();
    await expect(block(editor, quote)).toHaveCount(0);
    await expect(redoButton(editor)).toBeEnabled();
    await expect(block(editor, 'one')).toContainText('Edited');
    await page.waitForTimeout(1000);
    await expect(block(editor, 'one')).toContainText('Edited');
    await expect(count).toHaveText('1');
  });

  test('the History timeline names the steps, goes to a step on a click and marks the saved one', async ({ page }) => {
    await login(page);
    const { editor } = await twoHeadings(page, 'History');
    const count = editor.locator('.frame-builder-history-button__count');
    await expect(count).toHaveCount(0);

    await selectBlock(editor, 'one');
    await setField(editor, 'Text', 'Edited');
    await expect(count).toHaveText('1');
    const quote = await addBlock(editor, 'Quote', block(editor, 'two'), { x: 0.5, y: 0.95 });
    await expect(count).toHaveText('2');

    const steps = await openHistory(editor);
    const texts = steps.locator('.frame-builder-timeline__text');
    // Newest first. Typing a word is one line.
    await expect(texts).toHaveText(['Added Quote', 'Changed Text of Heading', 'Opened']);
    await expect(steps.nth(0)).toContainText('now');
    await expect(steps.nth(2)).toContainText('as saved');

    // Back to before the quote: it leaves the canvas and its step is marked undone.
    await steps.nth(1).getByRole('button').click();
    await expect(block(editor, quote)).toHaveCount(0);
    await expect(block(editor, 'one')).toContainText('Edited');
    await expect(steps.nth(1)).toHaveClass(/is-current/);
    await expect(steps.nth(0)).toHaveClass(/is-undone/);
    await expect(steps.nth(0)).toContainText('undone');
    await expect(count).toHaveText('1');
    // And forward again.
    await steps.nth(0).getByRole('button').click();
    await expect(block(editor, quote)).toHaveCount(1);

    // After a save the current step is the saved one and nothing counts as unsaved.
    await save(editor, 'Publish');
    await expect(steps.nth(0)).toContainText('saved');
    await expect(steps.nth(2)).not.toContainText('saved');
    await expect(count).toHaveCount(0);

    await editor.getByRole('button', { name: 'Back to settings' }).click();
    await expect(editor.locator('.frame-builder-history:visible')).toHaveCount(0);
    await dismissMessage(editor);
  });
});
