import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('storia: gli ultimi passi in parole, e un clic per tornare a prima di uno (o rifarlo)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  const before = await builder.source(builder.html);
  await builder.write(builder.html, `${before}\n<div class="passo${id}"><p>Uno</p></div>`);
  await expect(builder.canvas.locator(`div.passo${id}`)).toBeVisible();
  await builder.node(`div.passo${id}`).click();
  await builder.tab('CSS');
  await builder.write(page.getByRole('textbox', { name: `CSS di .passo${id}` }), 'color: rgb(1, 2, 3);');
  await expect(builder.canvas.locator(`div.passo${id}`)).toHaveCSS('color', 'rgb(1, 2, 3)');

  await page.getByRole('button', { name: 'Storia delle modifiche' }).click();
  const history = page.getByRole('dialog', { name: 'Storia delle modifiche' });
  await expect(history.getByRole('button', { name: new RegExp(`CSS di \\.passo${id}`) })).toBeVisible();
  const added = history.getByRole('button', { name: new RegExp(`Aggiunto div\\.passo${id}`) });
  await expect(added).toBeVisible();

  // Back to before the element was added, in one click.
  await added.click();
  await expect(builder.canvas.locator(`div.passo${id}`)).toHaveCount(0);
  // The undone steps stay, greyed: forward again to the CSS.
  await history.getByRole('button', { name: new RegExp(`CSS di \\.passo${id}`) }).click();
  await expect(builder.canvas.locator(`div.passo${id}`)).toHaveCSS('color', 'rgb(1, 2, 3)');
  await page.keyboard.press('Escape');
  await expect(history).toBeHidden();
});
