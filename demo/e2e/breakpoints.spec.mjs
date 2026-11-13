// Layout and style per breakpoint: what a component has at every width, and what it has from a breakpoint of the
// theme on. The canvas shows the breakpoint being edited; the page follows the width of the window.
// Needs: the landing_page content type; the breakpoints of the Frame theme (Tablet from 768px, Desktop from 1200px)
// and its spacing scale (s, l) and sizes.
import { test, expect } from '@playwright/test';
import { asVisitor, block, createPage, editorOf, heading, login, openEditor, rightColumn, save, selectBlock, settingsOf, storeLayout, unique } from './helpers.mjs';

const paddingOf = (locator) => locator.evaluate((element) => getComputedStyle(element).paddingTop);

test.describe('Breakpoints', () => {
  test('a component has its spacing at every width and another from a breakpoint on', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Breakpoints'));
    await storeLayout(editorOf(page), [heading('h', 'Padded')]);
    const editor = await openEditor(page, node);
    expect((await settingsOf(editor)).breakpoints.map((entry) => entry.id)).toEqual(['tablet', 'desktop']);
    await selectBlock(editor, 'h');
    const column = rightColumn(editor);
    const widths = column.locator('.frame-builder-tabs').first();
    const canvasTabs = editor.locator('.frame-builder-canvas-breakpoints');
    const canvasWidth = () => editor.locator('#puck-canvas-root').evaluate((element) => Math.round(element.getBoundingClientRect().width));
    const fullWidth = await canvasWidth();
    const spacing = column.locator('.frame-builder-feature').filter({ has: page.frameLocator('iframe.frame-builder-frame').locator('summary', { hasText: 'Spacing' }) }).first();
    const padding = () => column.locator('.frame-builder-feature:visible select[aria-label="Padding"]').first();
    const openSpacing = async () => { if (!(await padding().isVisible().catch(() => false))) await column.locator('summary:visible', { hasText: 'Spacing' }).first().click(); };

    // At every width: a small padding.
    await expect(widths.getByRole('tab', { name: 'All' })).toHaveAttribute('aria-selected', 'true');
    await expect(canvasTabs.getByRole('tab', { name: 'Full width' })).toHaveAttribute('aria-selected', 'true');
    await openSpacing();
    await padding().selectOption('s');
    const small = await expect.poll(() => paddingOf(block(editor, 'h').locator('.heading-block'))).not.toBe('0px').then(() => paddingOf(block(editor, 'h').locator('.heading-block')));

    // From the tablet on: a large one. The fields show the values of the breakpoint, which has none yet.
    await widths.getByRole('tab', { name: 'Tablet' }).click();
    // The canvas shows the same choice, as wide as the tablet breakpoint starts.
    await expect(canvasTabs.getByRole('tab', { name: /^Tablet/ })).toHaveAttribute('aria-selected', 'true');
    await expect.poll(canvasWidth).toBe(768);
    await openSpacing();
    await expect(padding()).toHaveValue('');
    await padding().selectOption('l');
    await expect(column.locator('.frame-builder-feature__at:visible').first()).toHaveText('from Tablet');
    await expect.poll(() => paddingOf(block(editor, 'h').locator('.heading-block'))).not.toBe(small);
    const large = await paddingOf(block(editor, 'h').locator('.heading-block'));

    // Back at every width the canvas shows the small one again, and the feature says that it differs.
    // The tabs of the canvas choose too: back to every width, and the canvas is as wide as before.
    await canvasTabs.getByRole('tab', { name: 'Full width' }).click();
    await expect(widths.getByRole('tab', { name: 'All' })).toHaveAttribute('aria-selected', 'true');
    await expect.poll(canvasWidth).toBe(fullWidth);
    // The canvas starts right under its tabs.
    const gap = await editor.locator('.frame-builder-canvas-breakpoints .frame-builder-tabs').evaluate((tabs) => document.getElementById('puck-canvas-root').getBoundingClientRect().top - tabs.getBoundingClientRect().bottom);
    expect(gap).toBeLessThanOrEqual(16);
    // The arrow keys go from tab to tab: Desktop, 1200px wide, which this window shows by scrolling sideways.
    await canvasTabs.getByRole('tab', { name: 'Full width' }).focus();
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await expect(canvasTabs.getByRole('tab', { name: /^Desktop/ })).toHaveAttribute('aria-selected', 'true');
    await expect(canvasTabs.getByRole('tab', { name: /^Desktop/ })).toBeFocused();
    await expect(widths.getByRole('tab', { name: 'Desktop' })).toHaveAttribute('aria-selected', 'true');
    await expect.poll(canvasWidth).toBe(1200);
    expect(await editor.locator('[class*="PuckCanvas_"]').first().evaluate((canvas) => canvas.scrollWidth > canvas.clientWidth)).toBe(true);
    await canvasTabs.getByRole('tab', { name: 'Full width' }).click();
    await expect.poll(() => paddingOf(block(editor, 'h').locator('.heading-block'))).toBe(small);
    await expect(column.locator('.frame-builder-feature__summary:visible', { hasText: 'differs from Tablet' })).toHaveCount(1);
    await openSpacing();
    await expect(padding()).toHaveValue('s');

    await save(editor);
    // The page: small on a phone, large from the tablet on.
    for (const [width, expected] of [[500, small], [900, large], [1400, large]]) {
      const context = await browser.newContext({ viewport: { width, height: 800 } });
      const visitor = await context.newPage();
      await visitor.goto(`/node/${node}`);
      expect(await paddingOf(visitor.locator('.heading-block').first()), `at ${width}px`).toBe(expected);
      await context.close();
    }
  });

  test('the size of a component: at most, at least and exactly, for width and height', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Sizes'));
    // A layout saved before there were six: "width" was at most, "height" at least.
    await storeLayout(editorOf(page), [heading('h', 'Sized', { _size: { width: { size: 'custom', custom: '300px' }, height: { size: 'custom', custom: '120px' } } })]);
    const editor = await openEditor(page, node);
    const box = block(editor, 'h').locator('.heading-block');
    await expect.poll(() => box.evaluate((element) => [element.style.maxWidth, element.style.minHeight])).toEqual(['300px', '120px']);
    await selectBlock(editor, 'h');
    const column = rightColumn(editor);
    // The two most components need are there; the others are in Advanced.
    await expect(column.locator('.frame-builder-feature:visible .frame-builder-label', { hasText: /^(Width|Height), / })).toHaveText(['Width, at most', 'Height, at least']);
    await column.locator('button:visible', { hasText: /^Advanced$/ }).first().click();
    await expect(column.locator('.frame-builder-feature:visible .frame-builder-label', { hasText: /^(Width|Height), / })).toHaveText(
      ['Width, at most', 'Width, at least', 'Width, exactly', 'Height, at least', 'Height, at most', 'Height, exactly'],
    );
    await column.locator('select[aria-label="Width, at least"]:visible').first().selectOption('custom');
    await column.locator('input[aria-label="Width, at least: custom value"]:visible').first().fill('200px');
    await expect.poll(() => box.evaluate((element) => [element.style.minWidth, element.style.maxWidth])).toEqual(['200px', '300px']);
    // Simple hides the other four and clears what is set in them; Reset clears every size.
    await column.locator('button:visible', { hasText: /^Simple$/ }).first().click();
    await expect.poll(() => box.evaluate((element) => [element.style.minWidth, element.style.maxWidth])).toEqual(['', '300px']);
    await expect(column.locator('.frame-builder-feature:visible .frame-builder-label', { hasText: /^(Width|Height), / })).toHaveText(['Width, at most', 'Height, at least']);
    await column.locator('button:visible', { hasText: /^Reset$/ }).first().click();
    await expect.poll(() => box.evaluate((element) => [element.style.maxWidth, element.style.minHeight])).toEqual(['', '']);
  });

  test('Full width fills the canvas between the panels, also in a narrow window, and nothing drawn on it spills over them', async ({ page }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Fluid'));
    await storeLayout(editorOf(page), [heading('a', 'Alpha'), { type: 'frame:quote', props: { id: 'q', text: 'A long quote '.repeat(30), size: 'l' } }]);
    await page.setViewportSize({ width: 1100, height: 800 });
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'a');
    const canvas = editor.locator('[class*="PuckCanvas_"]').first();
    const sizes = () => canvas.evaluate((element) => ({
      scrolls: element.scrollWidth > element.clientWidth,
      root: Math.round(document.getElementById('puck-canvas-root').getBoundingClientRect().width),
      room: element.clientWidth - 16,
    }));
    await expect.poll(async () => { const now = await sizes(); return !now.scrolls && Math.abs(now.root - now.room) <= 2; }).toBe(true);
    // A width wider than the canvas scrolls; the outline of the selected component is cut at the edge of the canvas.
    await canvas.locator('.frame-builder-canvas-breakpoints').getByRole('tab', { name: /^Tablet/ }).evaluate((tab) => tab.click());
    await expect.poll(async () => (await sizes()).scrolls).toBe(true);
    expect(await canvas.evaluate((element) => getComputedStyle(element).clipPath)).toBe('inset(0px)');
  });

  test('a border: a width of the theme on the sides chosen, a line style, and rounded corners', async ({ page, browser }) => {
    await login(page);
    const node = await createPage(page, 'landing_page', unique('Border'));
    await storeLayout(editorOf(page), [heading('a', 'Framed')]);
    const editor = await openEditor(page, node);
    await selectBlock(editor, 'a');
    const border = rightColumn(editor).locator('details.frame-builder-feature', { has: page.locator('summary', { hasText: /^Border/ }) }).first();
    await border.locator('summary').click();
    await border.getByLabel('Border width').selectOption('medium');
    await border.getByLabel('Border: sides').selectOption('bottom');
    await border.getByLabel('Border: line').selectOption('dashed');
    await border.getByLabel('Rounded corners').selectOption('m');
    // What is set is said in the title of the section.
    await expect(border.locator('.frame-builder-feature__summary').first()).toHaveText('MEDIUM bottom, rounded M');
    const look = (root) => root.locator('.heading-block').first().evaluate((element) => {
      const wrapper = element.closest('[style*="border"]') || element;
      const style = getComputedStyle(wrapper);
      return [style.borderBottomWidth, style.borderBottomStyle, style.borderTopWidth, style.borderTopLeftRadius];
    });
    await expect.poll(() => look(block(editor, 'a'))).toEqual(['2px', 'dashed', '0px', '8px']);
    await save(editor);
    await asVisitor(browser, `/node/${node}`, async (visitor) => {
      expect(await look(visitor)).toEqual(['2px', 'dashed', '0px', '8px']);
    });
  });
});
