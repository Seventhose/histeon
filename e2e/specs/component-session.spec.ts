import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('sessione componente: si modifica sul posto, si salva o si annulla, e la pagina torna com’era', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<article class="sess${id}"><h2>Titolo ${id}</h2></article><p class="fuori">Pagina non salvata</p>`);
  await builder.node(`article.sess${id}`).click();
  await page.getByRole('button', { name: /Crea componente/ }).click();
  await page.getByLabel('Nome del nuovo componente').fill(`Sess ${id}`);
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  await expect(builder.status).toContainText('creato');
  // Nothing is reloaded from here on: this marker would be gone.
  await page.evaluate(() => ((window as unknown as { marker: number }).marker = 1));

  // The instance is selected: edit its definition in place.
  await page.getByRole('button', { name: 'Modifica il componente' }).click();
  const trail = page.getByRole('navigation', { name: 'Sessione del componente' });
  await expect(trail).toContainText(`Sess ${id}`);
  await expect(builder.html).toContainText(`<article class="sess${id}">`);
  await expect(builder.html).not.toContainText('fuori');
  await builder.write(builder.html, `<article class="sess${id}"><h2>Titolo cambiato ${id}</h2></article>`);
  await page.getByRole('button', { name: 'Salva ed esci' }).click();

  // Back on the page: its unsaved changes are still there, and the instance shows the new definition.
  await expect(trail).toHaveCount(0);
  // The instance it was opened from is selected again.
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toBeVisible();
  await page.keyboard.press('Escape'); // the HTML panel shows the instance; the whole page is one Esc away
  await expect(builder.html).toContainText('<p class="fuori">Pagina non salvata</p>');
  await expect(builder.canvas.getByRole('heading', { name: `Titolo cambiato ${id}` })).toBeVisible();
  await expect(builder.status).toContainText(/salvato|Modifiche non salvate/);
  await expect(page.getByRole('button', { name: "Annulla l'ultima modifica" })).toBeEnabled();

  // A second session, dropped: the component stays as saved. Double click on the instance in the page opens it.
  await builder.canvas.getByRole('heading', { name: `Titolo cambiato ${id}` }).dblclick();
  await expect(trail).toBeVisible();
  await builder.write(builder.html, `<article class="sess${id}"><h2>Da buttare ${id}</h2></article>`);
  page.once('dialog', (dialog) => void dialog.accept());
  await trail.getByRole('button', { name: /Pagina|Palette|E2E/i }).first().click();
  await expect(trail).toHaveCount(0);
  await expect(builder.canvas.getByRole('heading', { name: `Titolo cambiato ${id}` })).toBeVisible();
  await expect(builder.canvas.getByText(`Da buttare ${id}`)).toHaveCount(0);
  expect(await page.evaluate(() => (window as unknown as { marker?: number }).marker)).toBe(1);
});
