// The Structure panel: several components selected with Ctrl/Cmd + click, Shift + click or Shift + arrows; put into a
// new layout component with "+", moved together, deleted from the panel or from the header; a plain click selects
// one again.
// Needs: the landing_page content type; Heading, Quote and Stack of the base theme (Stack is in the group Layout).
import { test, expect } from '@playwright/test';
// A row of Puck's outline, and the rows of the selection.
const rowOf = (panel) => (id) => panel.locator(`[data-puck-layer-tree-id="${id}"] > [class*="Layer-inner"] [class*="Layer-clickable"]`);
const chosenOf = (panel) => () => panel.locator('[data-frame-chosen]').evaluateAll((rows) => rows.map((element) => element.getAttribute('data-puck-layer-tree-id')));
import { blockIds, createPage, editorOf, heading, login, openEditor, openPanel, storeLayout, undoButton, unique } from './helpers.mjs';

const layout = [heading('a', 'Alpha'), heading('b', 'Bravo'), { type: 'frame:quote', props: { id: 'c', text: 'Charlie', size: 'm' } }, heading('d', 'Delta')];

test.describe('Structure', () => {
  test('several components are selected, put into a Stack, moved and deleted together', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Structure'));
    await storeLayout(editorOf(page), layout);
    const editor = await openEditor(page, node);
    await openPanel(editor, 'Structure');
    const panel = editor.locator('.frame-builder-structure');
    const row = rowOf(panel);
    const chosen = chosenOf(panel);
    const roots = () => editor.locator('[data-puck-dropzone="root:default-zone"] > [data-puck-component]').evaluateAll((elements) => elements.map((element) => element.getAttribute('data-puck-component')));
    const deleteInHeader = editor.locator('[class*="PuckHeader"]').getByRole('button', { name: /^Delete \d+$/ });
    const ctrl = process.platform === 'darwin' ? 'Meta' : 'Control';

    // The rows are Puck's own.
    await expect(panel.locator('[class*="Layer-name"]')).toHaveText(['Content: Heading', 'Content: Heading', 'Content: Quote', 'Content: Heading']);

    // Ctrl + click adds, Shift + click selects all from the last one clicked. The actions show in the title row of the
    // panel, which keeps its height.
    const header = panel.locator('[class*="OutlineHeader"]');
    const tall = (await header.boundingBox()).height;
    await row('a').click();
    await row('c').click({ modifiers: [ctrl] });
    await expect(header.getByText('2 selected')).toBeVisible();
    expect((await header.boundingBox()).height).toBe(tall);
    await expect.poll(chosen).toEqual(['a', 'c']);
    await expect(deleteInHeader).toHaveText('Delete 2');
    await expect(editor.locator('[data-puck-component="c"]')).toHaveCSS('outline-style', 'solid');
    await row('d').click({ modifiers: ['Shift'] });
    await expect.poll(chosen).toEqual(['c', 'd']);
    // Ctrl + click takes one away.
    await row('d').click({ modifiers: [ctrl] });
    await expect.poll(chosen).toEqual(['c']);
    await expect(deleteInHeader).toHaveCount(0);

    // Up and down go through the tree; with Shift they extend the selection.
    await row('a').click();
    await row('a').press('Shift+ArrowDown');
    await expect(row('b')).toBeFocused();
    await page.keyboard.press('Shift+ArrowDown');
    await expect.poll(chosen).toEqual(['a', 'b', 'c']);

    // "+": into a component of the group Layout, where the first one was.
    await panel.getByRole('button', { name: 'Put the selected components into a layout component' }).click();
    // The menu takes the keyboard: its first item has the focus, Escape closes it.
    await expect(panel.getByRole('menuitem').first()).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(panel.getByRole('menu')).toHaveCount(0);
    await panel.getByRole('button', { name: 'Put the selected components into a layout component' }).click();
    await panel.getByRole('menuitem', { name: 'Stack' }).click();
    await expect.poll(roots).toHaveLength(2);
    const [stack, last] = await roots();
    expect(stack).toMatch(/^frame\/stack-/);
    expect(last).toBe('d');
    await expect.poll(() => editor.locator(`[data-puck-dropzone="${stack}:content"] > [data-puck-component]`).evaluateAll((elements) => elements.map((element) => element.getAttribute('data-puck-component')))).toEqual(['a', 'b', 'c']);
    await expect.poll(chosen).toEqual([stack]);
    // One step of Undo takes it back.
    await undoButton(editor).click();
    await expect.poll(roots).toEqual(['a', 'b', 'c', 'd']);

    // Moved together: Bravo and Delta up by one.
    await row('b').click();
    await row('d').click({ modifiers: [ctrl] });
    await panel.getByRole('button', { name: 'Move the selected components up' }).click();
    await expect.poll(roots).toEqual(['b', 'a', 'd', 'c']);
    // Bravo is first: it stays, Delta goes on.
    await panel.getByRole('button', { name: 'Move the selected components up' }).click();
    await expect.poll(roots).toEqual(['b', 'd', 'a', 'c']);
    // Alt + arrow does the same: both one place down.
    await row('d').focus();
    await page.keyboard.press('Alt+ArrowDown');
    await expect.poll(roots).toEqual(['a', 'b', 'd', 'c']);

    // A plain click on the canvas selects that one again.
    await editor.locator('[data-puck-component="c"]').click({ position: { x: 10, y: 10 } });
    await expect.poll(chosen).toEqual(['c']);
    await expect(deleteInHeader).toHaveCount(0);

    // Delete in the header removes all that are selected.
    await row('a').click();
    await row('c').click({ modifiers: [ctrl] });
    await deleteInHeader.click();
    await expect.poll(roots).toEqual(['b', 'd']);
    await expect(deleteInHeader).toHaveCount(0);
    expect(await blockIds(editor)).toEqual(['b', 'd']);
  });

  test('Escape, the Delete key, a branch folded, and the panel kept open by Undo', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Structure keys'));
    await storeLayout(editorOf(page), [
      { type: 'frame:stack', props: { id: 's', gap: { size: 'm' }, align: 'stretch', dividers: false, content: [heading('in1', 'Inside one'), heading('in2', 'Inside two')] } },
      heading('x', 'Xray'), heading('y', 'Yankee'),
    ]);
    const editor = await openEditor(page, node);
    await openPanel(editor, 'Structure');
    const panel = editor.locator('.frame-builder-structure');
    const row = rowOf(panel);
    const chosen = chosenOf(panel);
    const ctrl = process.platform === 'darwin' ? 'Meta' : 'Control';

    // Right opens a component in the tree, left folds it, as Puck's arrow does.
    await expect(row('in1')).toHaveCount(0);
    await row('s').click();
    await page.keyboard.press('ArrowRight');
    await expect(row('in1')).toBeVisible();
    await page.keyboard.press('ArrowLeft');
    await expect(row('in1')).toHaveCount(0);
    await page.keyboard.press('ArrowRight');
    await expect(row('in1')).toBeVisible();

    // Escape: back to the one that was selected last.
    await row('x').click();
    await row('y').click({ modifiers: [ctrl] });
    await expect.poll(chosen).toEqual(['x', 'y']);
    await page.keyboard.press('Escape');
    await expect.poll(chosen).toEqual(['y']);

    // The Delete key removes the selection; Undo brings it back and Structure stays the open panel.
    await row('in2').click();
    await row('x').click({ modifiers: [ctrl] });
    await row('x').press('Delete');
    await expect(editor.locator('[data-puck-component="in2"]')).toHaveCount(0);
    await expect(row('x')).toHaveCount(0);
    await undoButton(editor).click();
    await expect(editor.locator('[data-puck-component="in2"]')).toHaveCount(1);
    await expect(row('x')).toBeVisible();
    await expect(editor.locator('[class*="Nav-list"] > li', { hasText: 'Structure' }).first()).toHaveClass(/NavItem--active/);
  });

  test('on a translation that shares the structure, components are selected but not changed', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Structure locked'));
    await storeLayout(editorOf(page), [heading('one', 'First'), heading('two', 'Second')]);
    await page.goto(`/de/node/${node}/translations/add/en/de`);
    await page.fill('input[name="title[0][value]"]', unique('Gesperrt'));
    await page.click('[data-drupal-selector="edit-submit"]');
    const editor = await openEditor(page, node, 'de');
    await openPanel(editor, 'Structure');
    const panel = editor.locator('.frame-builder-structure');
    const ctrl = process.platform === 'darwin' ? 'Meta' : 'Control';
    await rowOf(panel)('one').click();
    await rowOf(panel)('two').click({ modifiers: [ctrl] });
    await expect(panel.locator('[data-frame-chosen]')).toHaveCount(2);
    await expect(panel.locator('.frame-builder-structure__action')).toHaveCount(0);
    await expect(editor.locator('[class*="PuckHeader"]').getByRole('button', { name: /^Delete \d+$/ })).toHaveCount(0);
    await rowOf(panel)('two').press('Delete');
    await expect(rowOf(panel)('two')).toBeVisible();
  });

  test('the search finds a component by its name or text, opens the tree to it and selects it', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Structure search'));
    await storeLayout(editorOf(page), [
      heading('h', 'Welcome'),
      { type: 'frame:stack', props: { id: 's', gap: { size: 'm' }, align: 'stretch', dividers: false, content: [heading('in1', 'Our kitchen'), { type: 'frame:quote', props: { id: 'q', text: 'Best pasta in town', size: 'm' } }] } },
    ]);
    const editor = await openEditor(page, node);
    await openPanel(editor, 'Structure');
    const panel = editor.locator('.frame-builder-structure');
    const search = panel.getByRole('searchbox', { name: 'Search the components of the page' });
    const chosen = chosenOf(panel);
    // Inside a folded Stack: the tree opens to it, and its fields are in the right column.
    await expect(rowOf(panel)('q')).toHaveCount(0);
    await search.pressSequentially('pasta');
    await expect.poll(chosen).toEqual(['q']);
    await expect(rowOf(panel)('q')).toBeVisible();
    await expect(panel.locator('.frame-builder-structure__found')).toHaveText('1 of 1');
    await expect(search).toBeFocused();
    // By the name of the component, every word; Enter goes to the next one found.
    await search.fill('');
    await search.pressSequentially('heading');
    await expect.poll(chosen).toEqual(['h']);
    await expect(panel.locator('.frame-builder-structure__found')).toHaveText('1 of 2');
    await search.press('Enter');
    await expect.poll(chosen).toEqual(['in1']);
    await search.press('ArrowUp');
    await expect.poll(chosen).toEqual(['h']);
    await search.fill('nothing like this');
    await expect(panel.locator('.frame-builder-structure__found')).toHaveText('Nothing found');
  });

  test('several selected are dragged together, in the tree and on the canvas, as one step of Undo', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Structure drag'));
    await storeLayout(editorOf(page), [heading('a', 'Alpha'), heading('b', 'Bravo'), heading('c', 'Charlie'), heading('d', 'Delta')]);
    const editor = await openEditor(page, node);
    await openPanel(editor, 'Structure');
    const panel = editor.locator('.frame-builder-structure');
    const row = rowOf(panel);
    const chosen = chosenOf(panel);
    const roots = () => editor.locator('[data-puck-dropzone="root:default-zone"] > [data-puck-component]').evaluateAll((elements) => elements.map((element) => element.getAttribute('data-puck-component')));
    const ctrl = process.platform === 'darwin' ? 'Meta' : 'Control';
    const drag = async (from, to, below) => {
      const start = await from.boundingBox();
      const end = await to.boundingBox();
      await page.mouse.move(start.x + 20, start.y + start.height / 2);
      await page.mouse.down();
      await page.mouse.move(start.x + 20, start.y + start.height / 2 + 6, { steps: 4 });
      await page.mouse.move(end.x + 20, below ? end.y + end.height - 3 : end.y + 3, { steps: 16 });
      await page.waitForTimeout(150);
      await page.mouse.up();
    };

    // In the tree: Alpha below Delta; Charlie, selected too, follows it.
    await row('a').click();
    await row('c').click({ modifiers: [ctrl] });
    await drag(row('a'), row('d'), true);
    await expect.poll(roots).toEqual(['b', 'd', 'a', 'c']);
    await expect.poll(chosen).toEqual(['a', 'c']);
    // One step of Undo takes both back.
    await page.waitForTimeout(400);
    await undoButton(editor).click();
    await expect.poll(roots).toEqual(['a', 'b', 'c', 'd']);

    // On the canvas: Delta above Alpha; Bravo, selected and before it, goes before it.
    await row('b').click();
    await row('d').click({ modifiers: [ctrl] });
    await drag(editor.locator('[data-puck-component="d"]'), editor.locator('[data-puck-component="a"]'), false);
    await expect.poll(roots).toEqual(['b', 'd', 'a', 'c']);
    await expect.poll(chosen).toEqual(['b', 'd']);
  });
});
