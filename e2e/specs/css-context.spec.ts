import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('CSS: stati e larghezze a un clic, la mini GUI scrive nel blocco giusto e la tela lo mostra', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="ctx${id}"><p>Uno</p></div>`);
  await builder.node(`div.ctx${id}`).click();
  await builder.tab('CSS');
  const css = page.getByRole('textbox', { name: `CSS di .ctx${id}` });
  const box = builder.canvas.locator(`div.ctx${id}`);
  const display = () => page.getByRole('region', { name: /^Mini GUI di / }).getByRole('group', { name: 'display', exact: true });

  // :hover: the mini GUI writes inside &:hover, and the canvas shows the selection hovered.
  const state = page.getByRole('combobox', { name: 'Stato' });
  await state.selectOption('&:hover');
  await display().getByRole('button', { name: 'flex', exact: true }).click();
  await expect(css).toContainText('&:hover {');
  await expect(css).toContainText('display: flex;');
  await expect(box).toHaveCSS('display', 'flex');

  // Back to normal: the rule itself is untouched, and the canvas shows the element as it is.
  await state.selectOption('');
  await expect(box).toHaveCSS('display', 'block');
  await expect(display().getByRole('button', { name: 'flex', exact: true })).toHaveAttribute('aria-pressed', 'false');

  // A width: the block is created around the declarations; a state at that width nests inside it.
  const widths = page.getByRole('combobox', { name: 'Larghezza' });
  const atCanvas = (await widths.locator('option').filter({ hasText: /px$/ }).first().getAttribute('value')) ?? '';
  await widths.selectOption(atCanvas);
  await display().getByRole('button', { name: 'grid', exact: true }).click();
  await expect(css).toContainText(/@media \(width <= \d+px\) \{/);
  await expect(box).toHaveCSS('display', 'grid');
  await expect(widths.locator('option').filter({ hasText: /px$/ }).first()).toHaveText(/^● /);

  // A second click removes the declaration, and the emptied block goes with it.
  await display().getByRole('button', { name: 'grid', exact: true }).click();
  await expect(css).not.toContainText('@media (width');
});
