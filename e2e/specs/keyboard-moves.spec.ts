import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

/** The structure's row of a node: its level in the tree. */
async function level(builder: Builder, label: string): Promise<string | null> {
  return builder.structure.getByRole('treeitem').filter({ has: builder.page.locator(':scope > .row > button.name', { hasText: label }) }).first().getAttribute('aria-level');
}

// From the keyboard an element changes parent (Alt+← and Alt+→), and says so when it cannot go further (U6).
test('Alt+← porta fuori dal contenitore, Alt+→ dentro l’elemento prima, e ai bordi un messaggio', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.due').click();
  expect(await level(builder, 'p.due')).toBe('2');

  await page.keyboard.press('Alt+ArrowLeft');
  await expect.poll(() => level(builder, 'p.due')).toBe('1');
  await expect(builder.html).toContainText('<p class="due">');
  await page.keyboard.press('Alt+ArrowLeft');
  await expect(builder.status).toHaveText('È già al primo livello');

  await page.keyboard.press('Alt+ArrowRight');
  await expect.poll(() => level(builder, 'p.due')).toBe('2');

  await builder.node('h1').click();
  await page.keyboard.press('Alt+ArrowUp');
  await expect(builder.status).toHaveText('È già il primo del suo contenitore');
  await page.keyboard.press('Alt+ArrowRight');
  await expect(builder.status).toHaveText('Prima di questo elemento non c’è un contenitore in cui entrare');
});

test('Ctrl+X taglia l’elemento con i suoi stili e Ctrl+Z lo rimette', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.uno').click();
  await page.keyboard.press('ControlOrMeta+x');
  await expect(builder.canvas.getByText('Primo paragrafo')).toHaveCount(0);
  await expect(builder.status).toHaveText('Elemento tagliato con i suoi stili: incollalo con Ctrl+V.');
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await expect(builder.canvas.getByText('Primo paragrafo')).toBeVisible();
});

// The context menu's actions are commands of the palette (U8), so they can have shortcuts too.
test('le azioni del menu contestuale sono comandi della palette', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.uno').click();
  const run = async (label: string) => {
    await page.keyboard.press('ControlOrMeta+k');
    await page.getByRole('dialog', { name: 'Comandi' }).getByRole('combobox').fill(label);
    await page.keyboard.press('Enter');
  };
  await run('Avvolgi in una section');
  await expect(builder.html).toContainText('<section>');
  await expect(builder.canvas.locator('section.e2e-hero > section > p.uno')).toBeAttached();
  // The new section is selected: off again, and the selection goes to what it held.
  await run('Togli il contenitore');
  await expect(builder.canvas.locator('section.e2e-hero > p.uno')).toBeAttached();
  await run('Seleziona il genitore');
  await expect(builder.structure.getByRole('treeitem', { selected: true }).locator(':scope > .row > button.name')).toContainText('section.e2e-hero');

  await builder.node('p.uno').click();
  await run('Crea componente dalla selezione…');
  await expect(page.getByLabel('Nome del nuovo componente')).toBeFocused();
});

// The insert menu says exactly where the element goes, and offers before, inside and after (U7).
test('il menu + dice dove va l’elemento e permette prima, dentro e dopo', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('section.e2e-hero').click();
  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  const where = page.locator('.where');
  await expect(where).toHaveText('Dentro section.e2e-hero, in fondo');
  const place = page.getByRole('group', { name: 'Dove inserire' });
  await place.getByRole('button', { name: 'Prima' }).click();
  await expect(where).toHaveText('Prima di section.e2e-hero');
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('Titolo');
  await page.keyboard.press('Enter');
  await expect(builder.html).toContainText('<h2>Titolo</h2>');
  const nodes = await builder.nodes();
  expect(nodes[0]).toBe('h2');
  expect(nodes.indexOf('section.e2e-hero')).toBeGreaterThan(0);
});
