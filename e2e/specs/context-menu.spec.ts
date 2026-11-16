import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('menu contestuale della struttura: avvolgi, togli il contenitore, duplica, crea componente', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="box${id}"><h2>Titolo</h2><p>Testo</p></div>`);

  // Right click: the menu, keyboard-navigable, with actions disabled when they cannot apply.
  await builder.node(`div.box${id}`).click({ button: 'right' });
  const menu = page.getByRole('menu', { name: /Azioni su/ });
  await expect(menu).toBeVisible();
  await expect(menu.getByRole('menuitem', { name: 'Copia', exact: true })).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Incolla qui' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();

  // Anywhere on the row, not only on the name: the empty space at its right end too.
  const row = builder.node(`div.box${id}`).locator('..');
  const box = await row.boundingBox();
  if (!box) throw new Error('riga non visibile');
  await page.mouse.click(box.x + box.width - 6, box.y + box.height / 2, { button: 'right' });
  await expect(menu).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();

  await builder.node(`div.box${id}`).click({ button: 'right' });
  await menu.getByRole('menuitem', { name: 'Avvolgi in una section' }).click();
  await expect(builder.html).toContainText(`<section>`);
  await expect(builder.html).toContainText(`<div class="box${id}">`);

  // Unwrap the new section again: the div is back at its place.
  await builder.structure.getByRole('treeitem', { selected: true }).getByRole('button', { name: /section/ }).first().click({ button: 'right' });
  await menu.getByRole('menuitem', { name: 'Togli il contenitore' }).click();
  await expect(builder.html).not.toContainText('<section>');

  // Duplicate from the keyboard (context-menu key on the focused node).
  await builder.node(`div.box${id}`).focus();
  await page.keyboard.press('Shift+F10');
  await menu.getByRole('menuitem', { name: 'Duplica' }).click();
  await expect(builder.structure.getByRole('button', { name: `div.box${id}`, exact: true })).toHaveCount(2);

  // A component from the right-clicked part.
  await builder.node(`div.box${id}`).first().click({ button: 'right' });
  await menu.getByRole('menuitem', { name: 'Crea componente…' }).click();
  await page.getByLabel('Nome del nuovo componente').fill(`Box ${id}`);
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  await expect(builder.status).toContainText('creato');
  await expect(builder.html).toContainText('<Box');
  const components = await builder.api<Array<{ ref: string; title: string }>>('GET', 'components');
  expect(components.map((c) => c.title)).toContain(`Box ${id}`);
  await expect(builder.structure.getByRole('button', { name: `div.box${id}`, exact: true })).toHaveCount(1);
});

test('menu contestuale in tela: tasto destro su un elemento della pagina (E3)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="tela${id}"><p class="dentro${id}">Testo</p></div>`);
  const target = builder.canvas.locator(`p.dentro${id}`);
  await expect(target).toBeVisible();

  // The structure's own menu, on the element under the pointer, which becomes the selection.
  await target.click({ button: 'right' });
  const menu = page.getByRole('menu', { name: /Azioni su/ });
  await expect(menu).toBeVisible();
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toContainText(`p.dentro${id}`);

  // Next to the pointer, not at the window's corner.
  const at = await target.boundingBox();
  const shown = await menu.boundingBox();
  if (!at || !shown) throw new Error('menu o elemento non visibili');
  expect(Math.abs(shown.x - (at.x + at.width / 2))).toBeLessThan(at.width / 2 + 300);
  expect(Math.abs(shown.y - (at.y + at.height / 2))).toBeLessThan(300);

  // A click in the page closes it.
  await builder.canvas.locator(`body`).click({ position: { x: 4, y: 4 } });
  await expect(menu).toBeHidden();

  // And it acts: wrap from the canvas.
  await target.click({ button: 'right' });
  await menu.getByRole('menuitem', { name: 'Avvolgi in una section' }).click();
  await expect(builder.canvas.locator(`div.tela${id} > section > p.dentro${id}`)).toHaveCount(1);

  // Rename from the canvas opens the name field in the structure.
  await target.click({ button: 'right' });
  await menu.getByRole('menuitem', { name: 'Rinomina' }).click();
  const field = builder.structure.getByRole('textbox', { name: /Etichetta di/ });
  await expect(field).toBeFocused();
  await field.fill(`Nota ${id}`);
  await page.keyboard.press('Enter');
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toContainText(`Nota ${id}`);
});

test('copia e incolla lo stile da un elemento all’altro (E12)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<p class="fonte${id} lead" style="color: red">Uno</p><p class="dest${id}">Due</p>`);
  const menu = page.getByRole('menu', { name: /Azioni su/ });
  await builder.node(`p.dest${id}`).click({ button: 'right' });
  await expect(menu.getByRole('menuitem', { name: 'Incolla stile' })).toHaveAttribute('aria-disabled', 'true');
  await page.keyboard.press('Escape');

  await builder.node(`p.fonte${id}.lead`).click({ button: 'right' });
  await menu.getByRole('menuitem', { name: 'Copia stile' }).click();
  await expect(builder.status).toContainText('Stile copiato');
  await builder.node(`p.dest${id}`).click({ button: 'right' });
  await menu.getByRole('menuitem', { name: 'Incolla stile' }).click();
  await expect(builder.canvas.locator(`p.fonte${id}.lead`)).toHaveCount(2);
  await expect(builder.canvas.locator(`p.fonte${id}.lead`).last()).toHaveText('Due');
  await expect(builder.canvas.locator(`p.fonte${id}.lead`).last()).toHaveAttribute('style', 'color: red');
});

test('selezione multipla: Ctrl+clic e Maiusc+clic, poi duplica, avvolgi ed elimina tutti insieme (E7)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<p class="m${id}a">A</p><p class="m${id}b">B</p><p class="m${id}c">C</p><p class="m${id}d">D</p>`);
  await page.keyboard.press('Escape');
  const row = (n: string) => builder.node(`p.m${id}${n}`);
  await row('a').click();
  await row('c').click({ modifiers: ['ControlOrMeta'] });
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toHaveCount(2);
  await expect(page.getByRole('navigation', { name: 'Percorso della selezione' })).toContainText('e altri 1 selezionati');
  // Shift+click in the canvas: the siblings from the main one to this one.
  await builder.canvas.locator(`p.m${id}d`).click({ modifiers: ['Shift'] });
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toHaveCount(4);

  // The menu on one of them acts on all.
  await row('b').click({ button: 'right' });
  const menu = page.getByRole('menu', { name: 'Azioni su 4 elementi selezionati' });
  await menu.getByRole('menuitem', { name: 'Avvolgi in un div' }).click();
  await expect(builder.canvas.locator(`div > p.m${id}a + p.m${id}b + p.m${id}c + p.m${id}d`)).toHaveCount(1);
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await expect(builder.canvas.locator(`div > p.m${id}a`)).toHaveCount(0);

  // The keys too: Ctrl+click two, then Delete removes both, in one undo step.
  await row('a').click();
  await row('d').click({ modifiers: ['ControlOrMeta'] });
  await page.keyboard.press('Delete');
  await expect(builder.canvas.locator(`p.m${id}a, p.m${id}d`)).toHaveCount(0);
  await expect(builder.canvas.locator(`p.m${id}b, p.m${id}c`)).toHaveCount(2);
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await expect(builder.canvas.locator(`p.m${id}a, p.m${id}d`)).toHaveCount(2);
});
