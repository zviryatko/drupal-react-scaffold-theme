// The help of a component behind a "?" next to its name, and the suggestions of a link field chosen with the keyboard.
// Needs: the landing_page content type; the Links and Button components of the base theme (Links has a help text).
import { test, expect } from '@playwright/test';
import { createPage, editorOf, heading, login, openEditor, rightColumn, selectBlock, storeLayout, unique } from './helpers.mjs';

test.describe('Help and link suggestions', () => {
  test('the help of a component opens and closes', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Help'));
    await storeLayout(editorOf(page), [
      { type: 'frame:link-list', props: { id: 'links', links: [{ label: 'About us', url: '/' }], layout: 'stacked', marker: 'arrow' } },
      heading('h', 'No help here'),
    ]);
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'links');
    const button = rightColumn(editor).getByRole('button', { name: 'Help: Links' });
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    await button.click();
    const popover = editor.getByRole('tooltip');
    await expect(popover).toContainText('A list of links. Drag the rows to order them');
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    // Escape closes it and gives the focus back to the button; a click elsewhere closes it too.
    await page.keyboard.press('Escape');
    await expect(popover).toHaveCount(0);
    await expect(button).toBeFocused();
    await button.click();
    await expect(popover).toHaveCount(1);
    await rightColumn(editor).locator('.frame-builder-form-item').first().click();
    await expect(popover).toHaveCount(0);
    // A component without help has no "?".
    await selectBlock(editor, 'h');
    await expect(rightColumn(editor).getByRole('button', { name: /^Help:/ })).toHaveCount(0);
  });

  test('a page is chosen from the suggestions of a link field with the arrow keys and Enter', async ({ page }) => {
    await login(page);
    const word = unique('Keyboard').replace(/\s+/g, '');
    await createPage(page, 'landing_page', `${word} first`);
    await createPage(page, 'landing_page', `${word} second`);
    const node = await createPage(page, 'landing_page', unique('Links by keyboard'));
    await storeLayout(editorOf(page), [{ type: 'frame:button', props: { id: 'b', label: 'Go', url: '/start', variant: 'primary', size: 'm' } }]);
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'b');
    const field = rightColumn(editor).locator('.frame-builder-form-item', { has: editor.locator('label', { hasText: /^Link$/ }) }).first();
    const input = field.locator('input').first();
    // The link the button has is cleared with the cross in the field.
    await input.hover();
    await field.getByRole('button', { name: 'Clear Link' }).click();
    await expect(input).toHaveValue('');
    await input.fill(word);
    const options = field.getByRole('option');
    await expect(options).toHaveCount(2);
    await expect(input).toHaveAttribute('aria-expanded', 'true');
    // Down twice, up once: the first suggestion; the field says which one is active.
    await input.press('ArrowDown');
    await input.press('ArrowDown');
    await input.press('ArrowUp');
    const active = field.locator('[role="option"][aria-selected="true"]');
    await expect(active).toHaveCount(1);
    await expect(input).toHaveAttribute('aria-activedescendant', await active.getAttribute('id'));
    const chosen = (await active.locator('.frame-builder-autocomplete__label').innerText()).trim();
    await input.press('Enter');
    // The content picked is shown as content of the site, not as typed text.
    await expect(field.locator('.frame-builder-chip__label')).toContainText(chosen);
    // Escape closes the suggestions without choosing.
    await field.getByRole('button', { name: 'Remove' }).click();
    const again = field.locator('input').first();
    await again.fill(word);
    await expect(field.getByRole('option')).toHaveCount(2);
    await again.press('Escape');
    await expect(field.getByRole('option')).toHaveCount(0);
    await expect(again).toHaveValue(word);
    // Tab takes the suggestion chosen, as Enter does.
    await again.fill(`${word} se`);
    await expect(field.getByRole('option')).toHaveCount(1);
    await again.press('ArrowDown');
    await again.press('Tab');
    await expect(field.locator('.frame-builder-chip__label')).toContainText(`${word} second`);
  });

  test('the title of the right column: a house to the page and the component it is in, small, above its name', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Title'));
    await storeLayout(editorOf(page), [
      { type: 'frame:stack', props: { id: 's', gap: { size: 'm' }, align: 'stretch', dividers: false, content: [heading('h', 'Inside')] } },
    ]);
    await page.setViewportSize({ width: 1100, height: 800 });
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'h');
    const title = rightColumn(editor).locator('[class*="SidebarSection-title"]').first();
    const parent = title.locator('.frame-builder-trail__parent');
    const name = title.locator('[class*="SidebarSection-heading"] h2');
    await expect(parent).toHaveText('Layout: Stack');
    await expect(name).toHaveText('Content: Heading');
    await expect(parent).toHaveAttribute('title', 'Layout: Stack');
    const [above, below] = [await parent.boundingBox(), await name.boundingBox()];
    expect(above.y + above.height).toBeLessThanOrEqual(below.y + 1);
    // Only the one it is in: Puck's breadcrumbs are not shown.
    await expect(title.locator('[class*="Breadcrumbs_"]')).toBeHidden();
    // The parent selects the parent; the house goes back to the page.
    await parent.click();
    await expect(name).toHaveText('Layout: Stack');
    await expect(title.locator('.frame-builder-trail__parent')).toHaveCount(0);
    await title.getByRole('button', { name: 'Page' }).click();
    await expect(rightColumn(editor).locator('[class*="SidebarSection-heading"] h2').first()).toHaveText('Page');
  });
});
