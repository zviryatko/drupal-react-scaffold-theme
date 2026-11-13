// The page "Components of the page builder": a component left out there is not in the list of blocks, and a page that
// has it still shows it.
// Needs: the landing_page content type; the Badge component of the base theme.
import { test, expect } from '@playwright/test';
import { asVisitor, createPage, editorOf, login, openEditor, openPanel, storeLayout, unique } from './helpers.mjs';

const settings = '/admin/config/content/frame-builder/components';
const badge = (page) => page.getByRole('checkbox', { name: /^Badge frame:badge/ });

test.describe('Components of the page builder', () => {
  test.afterEach(async ({ page }) => {
    // Offered again, for the other tests.
    await page.goto(settings);
    await badge(page).check();
    await page.getByRole('button', { name: 'Save configuration' }).click();
  });

  test('a component left out is not offered, and a page that has it still shows it', async ({ page, browser }) => {
    await login(page);
    await page.goto('/admin/config/content');
    await page.getByRole('link', { name: 'Components of the page builder' }).click();
    await expect(page).toHaveURL(new RegExp(`${settings}$`));
    await expect(badge(page)).toBeChecked();
    await badge(page).uncheck();
    await page.getByRole('button', { name: 'Save configuration' }).click();
    await expect(page.getByText('The configuration options have been saved.')).toBeVisible();
    await expect(badge(page)).not.toBeChecked();

    const node = await createPage(page, 'landing_page', unique('Not offered'));
    await storeLayout(editorOf(page), [{ type: 'frame:badge', props: { id: 'b', text: 'Still shown', shape: 'pill' } }]);
    const editor = await openEditor(page, node);
    await openPanel(editor, 'Blocks');
    await expect(editor.locator('.frame-builder-block__label', { hasText: /^Button/ }).first()).toBeVisible();
    await expect(editor.locator('.frame-builder-block__label', { hasText: /^Badge$/ })).toHaveCount(0);
    await expect(editor.locator('[data-puck-component="b"]')).toContainText('Still shown');
    await asVisitor(browser, `/node/${node}`, async (visitor) => {
      await expect(visitor.getByText('Still shown')).toBeVisible();
    });
  });
});
