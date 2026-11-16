import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('tela a una larghezza: le modifiche vanno da sole in quella @media; «Adatta» torna a tutte le larghezze', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="segui${id}"><p>Uno</p></div>`);
  await builder.node(`div.segui${id}`).click();
  await builder.tab('CSS');
  const css = page.getByRole('textbox', { name: `CSS di .segui${id}` });
  const display = () => page.getByRole('region', { name: /^Mini GUI di / }).getByRole('group', { name: 'display', exact: true });

  // The canvas at 600px: the pill says where edits go, and the mini GUI writes there.
  const width = page.getByLabel("Larghezza dell'anteprima in pixel");
  await width.fill('600');
  await width.press('Enter');
  await expect(page.getByRole('button', { name: /^Modifiche in @media/ })).toBeVisible();
  await display().getByRole('button', { name: 'flex', exact: true }).click();
  await expect(css).toContainText(/@media [^{]+\{\s*display: flex;/);
  const box = builder.canvas.locator(`div.segui${id}`);
  await expect(box).toHaveCSS('display', 'flex');

  // Back to the stage's width: the rule's own declarations again, and the canvas draws without the @media.
  await page.getByRole('button', { name: 'Adatta' }).click();
  await expect(page.getByRole('button', { name: /^Modifiche in/ })).toHaveCount(0);
  await expect(box).toHaveCSS('display', 'block');
  await display().getByRole('button', { name: 'grid', exact: true }).click();
  await expect(css).toContainText(/^display: grid;/);

  // Following can be turned off from the pill.
  await width.fill('600');
  await width.press('Enter');
  await page.getByRole('button', { name: /^Modifiche in @media/ }).click();
  await expect(page.getByRole('button', { name: 'Modifiche per tutte le larghezze' })).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Larghezza' })).toHaveValue('');
});
