import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('Ctrl+K apre la palette dei comandi e inserisce uno snippet', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.due').click();
  await page.keyboard.press('ControlOrMeta+k');
  const palette = page.getByRole('dialog', { name: 'Comandi' });
  await expect(palette).toBeVisible();
  await palette.getByRole('combobox').pressSequentially('paragrafo');
  await expect(palette.getByRole('option', { selected: true })).toContainText('Inserisci: Paragrafo');
  await page.keyboard.press('Enter');
  await expect(palette).toBeHidden();

  // Inserted after the selection, and selected.
  // Headings and paragraphs with only text stay closed (their text is on the row); the selection is open.
  expect(await builder.nodes()).toEqual(['section.e2e-hero', 'h1', 'p.uno', 'p.due', 'Secondo paragrafo', 'p', 'Testo']);
  // The selection is open: its text is the row right below it.
  const selected = builder.structure.getByRole('treeitem', { selected: true });
  await expect(selected).toContainText('Paragrafo');
  const below = Number(await selected.getAttribute('data-index')) + 1;
  await expect(builder.structure.locator(`li[data-index="${below}"]`)).toContainText('Testo');
  await expect(builder.canvas.getByText('Testo', { exact: true })).toBeVisible();
  await expect(builder.html).toContainText('<p>Testo</p>');

  // Escape closes it without running anything.
  await page.keyboard.press('ControlOrMeta+k');
  await expect(palette).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(palette).toBeHidden();
  expect((await builder.nodes()).length).toBe(7);
});

test('il drag & drop nella struttura riordina i nodi', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.dnd.id);
  // Top quarter of the target row: before it.
  await builder.node('p.due').dragTo(builder.node('p.uno'), { targetPosition: { x: 20, y: 2 } });

  expect(await builder.nodes()).toEqual(['section.e2e-hero', 'h1', 'p.due', 'Secondo paragrafo', 'p.uno']);
  // The moved node stays selected and the HTML panel shows only it: Escape goes back to the whole page.
  await page.keyboard.press('Escape');
  await expect(builder.html).toContainText('Primo paragrafo');
  const code = await builder.source(builder.html);
  expect(code.indexOf('Secondo paragrafo')).toBeLessThan(code.indexOf('Primo paragrafo'));
  await expect(builder.canvas.locator('section.e2e-hero > p')).toHaveText(['Secondo paragrafo', 'Primo paragrafo']);
  await expect(builder.status).toHaveText('Modifiche non salvate');

  // Bottom quarter: after it. (A heading cannot go inside a paragraph, so the middle of that row means an edge.)
  const target = builder.node('p.uno');
  const box = await target.boundingBox();
  await builder.node('h1').dragTo(target, { targetPosition: { x: 20, y: (box?.height ?? 20) - 2 } });
  await expect(builder.canvas.locator('section.e2e-hero > *')).toHaveText(['Secondo paragrafo', 'Primo paragrafo', 'Ciao e2e']);

  // One undo step per move.
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  expect((await builder.nodes()).filter((n) => !n.includes(' '))).toEqual(['section.e2e-hero', 'h1', 'p.uno', 'p.due']);
});

test('cerca e sostituisci nella pagina: anteprima, poi tutto in un passo di annullamento (E13)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<p class="vecchia${id}">Prezzo vecchio${id} <strong>vecchio${id}</strong></p><a class="vecchia${id}" href="https://vecchio.example/x">vecchio${id}</a>`);
  await page.keyboard.press('Escape');
  await page.keyboard.press('ControlOrMeta+k');
  await page.getByRole('dialog', { name: 'Comandi' }).getByRole('combobox').pressSequentially('Cerca e sostituisci');
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Cerca e sostituisci nella pagina' });
  await dialog.getByLabel('Testo da cercare').fill(`vecchio${id}`);
  await dialog.getByLabel('Testo che lo sostituisce').fill('nuovo');
  await expect(dialog.getByRole('status')).toHaveText('3 sostituzioni');
  await expect(dialog.getByRole('list', { name: 'Anteprima delle sostituzioni' }).getByRole('listitem')).toHaveCount(2); // the bold word is in the paragraph's text
  // Classes only by their whole name.
  await dialog.getByLabel('Testi').uncheck();
  await dialog.getByLabel('Classi (nome intero)').check();
  await dialog.getByLabel('Testo da cercare').fill(`vecchia${id}`);
  await dialog.getByLabel('Testo che lo sostituisce').fill(`nuova${id}`);
  await expect(dialog.getByRole('status')).toHaveText('2 sostituzioni');
  await dialog.getByRole('button', { name: 'Sostituisci tutte e 2' }).click();
  await expect(dialog).toBeHidden();
  await expect(builder.canvas.locator(`.nuova${id}`)).toHaveCount(2);
  await expect(builder.status).toContainText('Ctrl+Z');
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await expect(builder.canvas.locator(`.vecchia${id}`)).toHaveCount(2);
});
