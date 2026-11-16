import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

/** A minimal, valid one-page PDF. */
function pdf(title: string): Buffer {
  const body = `%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n4 0 obj<</Title(${title})>>endobj\ntrailer<</Root 1 0 R/Info 4 0 R>>\n%%EOF\n`;
  return Buffer.from(body);
}

// The media manager picks video, audio and PDF too, with a filter by kind (lista migliorie M1).
test('dalla libreria si inserisce anche un PDF, come link, con il filtro per tipo', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.node('p.due').click();
  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('Video, audio o PDF');
  await page.keyboard.press('Enter');
  const manager = page.getByRole('dialog', { name: 'Gestore dei media' });
  await expect(manager).toBeVisible();
  const kind = manager.getByLabel('Tipo di file');
  await expect(kind).toHaveValue('all');
  await expect(manager.locator('input[type="file"]')).toHaveAttribute('accept', 'image/*,video/*,audio/*,application/pdf');

  await manager.locator('input[type="file"]').setInputFiles({ name: `listino-${id}.pdf`, mimeType: 'application/pdf', buffer: pdf(`Listino ${id}`) });
  const item = manager.locator('ul.grid > li').filter({ hasText: `listino-${id}` });
  await expect(item).toBeVisible({ timeout: 30_000 });
  await kind.selectOption('pdf');
  await expect(item).toBeVisible();
  await kind.selectOption('video');
  await expect(item).toHaveCount(0);
  await kind.selectOption('pdf');
  await item.getByRole('button').first().click();
  await expect(manager).toBeHidden();
  await expect(builder.html).toContainText(`listino-${id}`);
  await expect(builder.canvas.locator(`a[href*="listino-${id}"]`)).toBeAttached();
});

// A 2×2 PNG, small enough to upload without the browser's compression changing its kind.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGN4kOAARAwQCgAsjgYBgHOtdAAAAABJRU5ErkJggg==', 'base64');

test('la libreria dei media dalla barra: dettagli, dove è usato, inserimento nella pagina (E1)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.node('p.due').click();
  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Libreria dei media' }).click();
  const manager = page.getByRole('dialog', { name: 'Gestore dei media' });
  await expect(manager).toBeVisible();
  // Every kind of file, not only images.
  await expect(manager.getByLabel('Tipo di file')).toHaveValue('all');
  await expect(manager.getByRole('button', { name: 'Tutti i file' })).toBeVisible();

  await manager.getByLabel('Compressione prima del caricamento').selectOption({ index: 0 });
  await manager.locator('input[type="file"]').setInputFiles({ name: `logo-${id}.png`, mimeType: 'image/png', buffer: PNG });
  const item = manager.locator('ul.grid > li').filter({ hasText: `logo-${id}` });
  await expect(item).toBeVisible({ timeout: 30_000 });

  // A click shows the details instead of placing the file.
  await item.getByRole('button').first().click();
  await expect(manager).toBeVisible();
  const details = manager.getByRole('complementary', { name: /Dettagli/ });
  await expect(details).toContainText('image/');
  await expect(details.getByLabel('Indirizzo del file')).toHaveValue(new RegExp(`logo-${id}`));
  await expect(details.getByRole('region', { name: 'Dove è usato' })).toContainText('Non risulta usato in nessun contenuto.');
  await details.getByLabel('Testo alternativo').fill(`Logo ${id}`);
  await details.getByLabel('Didascalia').fill(`Didascalia ${id}`);
  await details.getByRole('button', { name: 'Salva i dettagli' }).click();
  await expect(manager.getByRole('status')).toHaveText('File aggiornato.');
  await expect(details.getByRole('link', { name: 'Apri in WordPress' })).toHaveAttribute('href', /post\.php\?post=\d+&action=edit/);

  // Inserted after the selection, with its alt text.
  await details.getByRole('button', { name: 'Inserisci nella pagina' }).click();
  await expect(manager).toBeHidden();
  await expect(builder.canvas.locator(`img[src*="logo-${id}"]`)).toHaveAttribute('alt', `Logo ${id}`);

  // The caption is saved on the file; the page is not saved, so no content uses it yet.
  const media = await builder.api<Array<{ id: number; caption: { raw: string } }>>('GET', `../../wp/v2/media?search=logo-${id}&context=edit&_fields=id,caption`);
  expect(media[0]?.caption.raw).toBe(`Didascalia ${id}`);
  const usage = await builder.api<{ items: unknown[]; hidden: number }>('GET', `media/${media[0]!.id}/usage`);
  expect(usage).toEqual({ items: [], hidden: 0 });
});

// Publish doc 23, item 176: the link field of an <a> offers a file of the library; the link becomes its address.
test('il campo del link sceglie un file dalla libreria: PDF o immagine, e il link diventa l’indirizzo del file', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, '<a class="scarica" href="/x">Listino</a>');
  await builder.node('a.scarica').click();
  const links = page.getByRole('group', { name: 'Link' });
  await links.getByRole('button', { name: 'Scegli un file dalla libreria…' }).click();
  const manager = page.getByRole('dialog', { name: 'Gestore dei media' });
  await expect(manager).toBeVisible();
  // Only PDF and images, PDF first; uploads take the same two kinds.
  const kind = manager.getByLabel('Tipo di file');
  await expect(kind).toHaveValue('pdf');
  await expect(kind.locator('option')).toHaveText(['PDF', 'Immagini']);
  await expect(manager.locator('input[type="file"]')).toHaveAttribute('accept', 'image/*,application/pdf');

  await manager.locator('input[type="file"]').setInputFiles({ name: `menu-${id}.pdf`, mimeType: 'application/pdf', buffer: pdf(`Menu ${id}`) });
  const item = manager.locator('ul.grid > li').filter({ hasText: `menu-${id}` });
  await expect(item).toBeVisible({ timeout: 30_000 });
  await item.getByRole('button').first().click();
  await expect(manager).toBeHidden();
  await expect(links.getByLabel('Indirizzo')).toHaveValue(new RegExp(`^https?://[^ ]+/wp-content/uploads/[^ ]*menu-${id}\\.pdf$`));
  await expect(builder.html).toContainText(`class="scarica" href="`);
  await expect(builder.canvas.locator(`a.scarica[href$="menu-${id}.pdf"]`)).toHaveText('Listino');

  // Closing the manager without a choice leaves the link as it is.
  await links.getByRole('button', { name: 'Scegli un file dalla libreria…' }).click();
  await expect(manager).toBeVisible();
  await manager.getByRole('button', { name: 'Chiudi' }).click();
  await expect(links.getByLabel('Indirizzo')).toHaveValue(new RegExp(`menu-${id}\\.pdf$`));
});
