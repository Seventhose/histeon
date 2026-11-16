import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('foglio → selettore: le regole di un blocco sparse nel foglio diventano il suo CSS annidato, e la pagina resta uguale', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  const block = `blocco${id}`;
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="${block}"><h3 class="${block}__titolo">Titolo</h3></div>`);
  await page.keyboard.press('Escape');
  await builder.tab('CSS');
  const global = builder.editor('CSS globale');
  const before = await builder.source(global);
  await builder.write(global, `${before}\n.${block} { padding: 7px; }\n.${block}:hover { color: rgb(1, 1, 1); }\n.${block}__titolo { color: rgb(2, 3, 4); }\n@media (width > 1px) { .${block}__titolo { letter-spacing: 1px; } }\n`);
  const title = builder.canvas.locator(`h3.${block}__titolo`);
  await expect(title).toHaveCSS('letter-spacing', '1px');

  await builder.node(`div.${block}`).click();
  const scss = page.getByRole('region', { name: `SCSS di .${block}` });
  await expect(scss).toBeVisible();
  await expect(scss.getByRole('textbox', { name: `Anteprima SCSS di .${block}` })).toContainText('&__titolo {');
  await scss.getByRole('button', { name: /^Porta nel selettore/ }).click();

  const own = page.getByRole('textbox', { name: `CSS di .${block}` });
  await expect(own).toContainText('padding: 7px;');
  await expect(own).toContainText('&:hover {');
  await expect(own).toContainText('@media (width > 1px) {');
  await expect(title).toHaveCSS('color', 'rgb(2, 3, 4)');
  await expect(title).toHaveCSS('letter-spacing', '1px');
  await expect(builder.canvas.locator(`div.${block}`)).toHaveCSS('padding-top', '7px');

  // The sheet no longer has them; one undo brings everything back.
  await page.getByRole('tab', { name: 'Foglio globale' }).click();
  await expect(builder.editor('CSS globale')).not.toContainText(`.${block}__titolo`);
  await page.keyboard.press('Control+z');
  await expect(builder.editor('CSS globale')).toContainText(`.${block}__titolo`);
});
