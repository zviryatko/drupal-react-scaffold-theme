// Content moderation: Draft and Publish of the editor are the transitions of the workflow, the editor works on the
// latest revision, and visitors see the published one.
// Needs: the content type e2e_moderated under the workflow e2e_editorial (Draft, Published), the user author who
// may only save drafts (setup.sh).
import { test, expect } from '@playwright/test';
import { AUTHOR, addBlock, asVisitor, block, createPage, editorOf, headings, login, message, openEditor, pageForm, save, selectBlock, setField, settingsOf, unique } from './helpers.mjs';

const status = (browser, node) => asVisitor(browser, `/node/${node}`);

test.describe('Content moderation', () => {
  test('Draft keeps the page from visitors, Publish shows it, a later draft does not replace the published page', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'e2e_moderated', unique('Moderated'));
    let editor = editorOf(page);
    await expect(pageForm(editor).locator('body')).toContainText('Current state Draft');
    expect(await status(browser, node)).toBe(403);

    const id = await addBlock(editor, 'Heading');
    await selectBlock(editor, id);
    await setField(editor, 'Text', 'First draft');
    await expect(block(editor, id)).toContainText('First draft');
    expect(await save(editor, 'Draft')).toContain('Saved as a draft. Visitors do not see it until it is published.');
    expect(await status(browser, node)).toBe(403);

    expect(await save(editor, 'Publish')).toContain('The page has been published.');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['First draft']);

    // A draft after publishing is a newer revision next to the published one.
    await setField(editor, 'Text', 'Second draft');
    await expect(block(editor, id)).toContainText('Second draft');
    await save(editor, 'Draft');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['First draft']);
    // "View" goes to the latest revision, which is what the editor works on.
    await expect(editor.getByRole('link', { name: 'View', exact: true })).toHaveAttribute('href', new RegExp(`/node/${node}/latest$`));
    await page.goto(`/node/${node}/latest`);
    expect(await headings(page)).toEqual(['Second draft']);

    editor = await openEditor(page, node);
    await expect(message(editor)).toHaveCount(0);
    await expect(block(editor, id)).toContainText('Second draft');
    await expect(pageForm(editor).locator('body')).toContainText('Current state Draft');

    await save(editor, 'Publish');
    expect(await asVisitor(browser, `/node/${node}`, headings)).toEqual(['Second draft']);
    await expect(editor.getByRole('link', { name: 'View', exact: true })).toHaveAttribute('href', new RegExp(`/node/${node}$`));
  });

  test('an author who may not publish has Save and Draft, and the page stays a draft', async ({ page, browser }) => {
    await login(page, AUTHOR);
    const node = await createPage(page, 'e2e_moderated', unique('By an author'));
    const editor = editorOf(page);
    expect((await settingsOf(editor)).actions).toMatchObject({ publish: false, draft: true });
    await expect(editor.getByRole('button', { name: 'Publish', exact: true })).toHaveCount(0);
    await expect(editor.getByRole('button', { name: 'Save', exact: true })).toBeVisible();

    const id = await addBlock(editor, 'Heading');
    await selectBlock(editor, id);
    await setField(editor, 'Text', 'Waiting for review');
    await expect(block(editor, id)).toContainText('Waiting for review');
    expect(await save(editor, 'Draft')).toContain('Saved as a draft.');
    expect(await status(browser, node)).toBe(403);
    await setField(editor, 'Text', 'Still waiting');
    expect(await save(editor, 'Save')).toContain('The page has been saved.');
    expect(await status(browser, node)).toBe(403);

    // The author sees the draft.
    await page.goto(`/node/${node}`);
    expect(await headings(page)).toEqual(['Still waiting']);
  });
});
