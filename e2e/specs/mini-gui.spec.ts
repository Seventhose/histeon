import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('mini GUI: un clic scrive il CSS del selettore e la pagina cambia subito; un secondo clic lo toglie', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="mini${id}"><p>Uno</p><p>Due</p></div>`);
  await builder.node(`div.mini${id}`).click();
  await builder.tab('CSS');
  const gui = page.getByRole('region', { name: `Mini GUI di .mini${id}` });
  await expect(gui).toBeVisible();
  const css = page.getByRole('textbox', { name: `CSS di .mini${id}` });
  const box = builder.canvas.locator(`div.mini${id}`);

  await gui.getByRole('group', { name: 'display', exact: true }).getByRole('button', { name: 'flex', exact: true }).click();
  await expect(css).toContainText('display: flex;');
  await expect(box).toHaveCSS('display', 'flex');

  // Scales come from the framework's variables.
  const gap = gui.getByRole('group', { name: 'gap', exact: true }).getByRole('button').first();
  await gap.click();
  await expect(css).toContainText('gap: var(--');
  await expect(gap).toHaveAttribute('aria-pressed', 'true');

  // The active value again: removed.
  await gui.getByRole('group', { name: 'display', exact: true }).getByRole('button', { name: 'flex', exact: true }).click();
  await expect(css).not.toContainText('display: flex');
  await expect(box).toHaveCSS('display', 'block');

  // Other groups; and the GUI can be turned off.
  await gui.getByRole('tab', { name: 'Testo' }).click();
  await gui.getByRole('group', { name: 'text-align', exact: true }).getByRole('button', { name: 'center' }).click();
  await expect(box).toHaveCSS('text-align', 'center');
  await page.getByRole('button', { name: /^Mini GUI/ }).click();
  await expect(gui).toBeHidden();
  await page.getByRole('button', { name: /^Mini GUI/ }).click();
  await expect(gui).toBeVisible();
});

test('mini GUI anche per una classe con le regole nel foglio globale; e la classe da aggiungere se l’elemento non ne ha', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="foglio${id}"><p>Senza classe</p></div>`);
  await page.keyboard.press('Escape');
  await builder.tab('CSS');
  const global = builder.editor('CSS globale');
  const before = await builder.source(global);
  await builder.write(global, `${before}\n.foglio${id} { color: rgb(9, 9, 9); }`);
  // The sheet edit is applied after a short pause: wait for it before moving on.
  await expect(builder.canvas.locator(`div.foglio${id}`)).toHaveCSS('color', 'rgb(9, 9, 9)');

  // An element without classes: the panel asks for one (the mini GUI works on a class).
  await builder.node('p').last().click();
  await expect(page.getByText(/la mini GUI lavora sulla classe/)).toBeVisible();

  // A class styled in the sheet: the panel opens on its sheet rules, and the mini GUI is there too.
  await builder.node(`div.foglio${id}`).click();
  await expect(page.getByRole('button', { name: /^Nel foglio/ })).toHaveClass(/active/);
  const gui = page.getByRole('region', { name: `Mini GUI di .foglio${id}` });
  await expect(gui).toBeVisible();
  await expect(page.getByText(`scrive nel CSS proprio di .foglio${id}`)).toBeVisible();
  await gui.getByRole('group', { name: 'display', exact: true }).getByRole('button', { name: 'grid', exact: true }).click();
  await expect(page.getByRole('button', { name: /^CSS proprio/ })).toHaveClass(/active/);
  await expect(page.getByRole('textbox', { name: `CSS di .foglio${id}` })).toContainText('display: grid;');
  await expect(builder.canvas.locator(`div.foglio${id}`)).toHaveCSS('display', 'grid');
});

// The mini GUI writes in a state or at a width from «Nel foglio» too, and inside @container blocks written by
// hand (lista migliorie C3).
test('mini GUI: stato e larghezza anche dalla vista «Nel foglio» e nei blocchi @container scritti a mano', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="stato${id}">Stato</div>`);
  await page.keyboard.press('Escape');
  await builder.tab('CSS');
  const global = builder.editor('CSS globale');
  const before = await builder.source(global);
  await builder.write(global, `${before}\n.stato${id} { color: rgb(9, 9, 9); }`);
  await expect(builder.canvas.locator(`div.stato${id}`)).toHaveCSS('color', 'rgb(9, 9, 9)');

  await builder.node(`div.stato${id}`).click();
  await expect(page.getByRole('button', { name: /^Nel foglio/ })).toHaveClass(/active/);
  await page.getByRole('combobox', { name: 'Stato' }).selectOption(':hover');
  const gui = page.getByRole('region', { name: `Mini GUI di .stato${id} &:hover` });
  await gui.getByRole('group', { name: 'display', exact: true }).getByRole('button', { name: 'grid', exact: true }).click();
  const own = page.getByRole('textbox', { name: `CSS di .stato${id}` });
  await expect(own).toContainText('&:hover {');
  await expect(own).toContainText('display: grid;');

  // A @container block written by hand is offered as a width, and the mini GUI writes inside it.
  await builder.write(own, '@container (width > 1px) {\n  gap: 1px;\n}');
  await page.getByRole('combobox', { name: 'Stato' }).selectOption('');
  await page.getByRole('combobox', { name: 'Larghezza' }).selectOption('@container (width > 1px)');
  const inner = page.getByRole('region', { name: `Mini GUI di .stato${id} @container (width > 1px)` });
  await inner.getByRole('group', { name: 'display', exact: true }).getByRole('button', { name: 'flex', exact: true }).click();
  await expect(own).toContainText('display: flex;');
  expect((await builder.source(own)).indexOf('display: flex;')).toBeGreaterThan((await builder.source(own)).indexOf('@container'));
});

test('Mini GUI in stile compatto: gruppi che compaiono quando servono, valori di partenza scritti nel CSS e tolti al secondo clic', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.css.id);
  await builder.node('section.e2e-hero').click();
  await builder.tab('CSS');
  const css = builder.editor('CSS di .e2e-hero');
  await builder.write(css, 'padding: 1rem;');
  await page.getByLabel('Stile della Mini GUI').selectOption('compact');
  const mini = page.getByRole('region', { name: 'Mini GUI di .e2e-hero' });
  try {
    // Grid settings appear once the element is a grid.
    await expect(mini.getByRole('group', { name: 'Griglia', exact: true })).toHaveCount(0);
    await mini.getByRole('group', { name: 'Display' }).getByRole('button', { name: 'Griglia' }).click();
    await expect(css).toContainText('display: grid;');
    await expect(builder.canvas.locator('section.e2e-hero')).toHaveCSS('display', 'grid');
    const grid = mini.getByRole('group', { name: 'Griglia', exact: true });
    await grid.getByRole('button', { name: 'Colonne' }).click();
    await expect(css).toContainText('grid-template-columns: repeat(2, minmax(0, 1fr));');
    await expect(grid.getByRole('button', { name: 'Colonne' })).toHaveAttribute('aria-pressed', 'true');
    await grid.getByRole('button', { name: 'Colonne' }).click();
    await expect(css).not.toContainText('grid-template-columns');
    await expect(css).toContainText('padding: 1rem;');
    // The choice is remembered.
    await page.reload();
    await builder.node('section.e2e-hero').click();
    await builder.tab('CSS');
    await expect(page.getByLabel('Stile della Mini GUI')).toHaveValue('compact');
  } finally {
    await page.getByLabel('Stile della Mini GUI').selectOption('histos');
  }
});
