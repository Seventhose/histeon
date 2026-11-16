import { Builder } from '../lib/builder.ts';
import { ownPage } from '../lib/page.ts';
import { expect, test } from '../lib/test.ts';

// Lista della spesa 157: a variant of a component is a switch. Adding it writes a yes/no prop, the BEM modifier on
// the first element and the modifier's CSS with the variables to redefine; a page turns it on with the switch.
test('varianti dei componenti: una «card scura» è un interruttore', async ({ page }) => {
  const id = Date.now().toString(36);
  const card = `card${id}`;
  const host = ownPage(`Varianti ${id}`);
  const opener = await Builder.open(page, host);
  await opener.tab('HTML');
  await opener.write(opener.html, `<div class="${card}"><p class="${card}__t">Scheda</p></div>`);
  await opener.node(`div.${card}`).click({ button: 'right' });
  await page.getByRole('menu', { name: /Azioni su/ }).getByRole('menuitem', { name: 'Crea componente…' }).click();
  await page.getByLabel('Nome del nuovo componente').fill(`Scheda ${id}`);
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  await expect(opener.status).toContainText('creato');
  await opener.save();
  const created = (await opener.api<Array<{ id: number; title: string }>>('GET', 'components')).find((c) => c.title === `Scheda ${id}`);
  if (!created) throw new Error('componente non creato');

  const builder = await Builder.open(page, created.id);
  await builder.tab('CSS');
  await builder.node(`div.${card}`).click();
  await builder.write(page.getByRole('textbox', { name: `CSS di .${card}` }), '--fondo: white;\nbackground: var(--fondo);');
  await page.getByRole('button', { name: 'Props del componente' }).click();
  await page.getByLabel('Nome della nuova variante').fill('Scura');
  await page.getByRole('button', { name: 'Aggiungi una variante' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Variante pronta' })).toContainText(`.${card}--scura`);
  await builder.tab('HTML');
  await expect(builder.html).toContainText(`class="${card} {props.scura ? "${card}--scura" : ""}"`);
  // The modifier's CSS starts from the variables of the block.
  await builder.tab('CSS');
  await builder.node(`div.${card}`).click();
  await page.getByRole('tab', { name: `.${card}--scura`, exact: true }).click();
  await expect(page.getByRole('textbox', { name: `CSS di .${card}--scura` })).toContainText('--fondo: white;');
  await builder.edit(page.getByRole('textbox', { name: `CSS di .${card}--scura` }), () => '--fondo: black;');
  await builder.save();

  // On the page that uses it (the selection became an instance), the switch turns it on.
  const user = await Builder.open(page, host);
  await user.canvas.locator(`div.${card}`).click();
  await page.getByRole('checkbox', { name: 'scura' }).check();
  await expect(user.canvas.locator(`div.${card}`)).toHaveClass(new RegExp(`${card}--scura`));
  await expect(user.canvas.locator(`div.${card}`)).toHaveCSS('background-color', 'rgb(0, 0, 0)');
});
