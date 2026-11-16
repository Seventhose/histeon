import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('gestore dei media: raccolta, caricamento compresso, testo alternativo', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);

  // A 3000×2000 PNG made in the page: the "Web" preset sends it as WebP, 2560 px wide. A gradient under the
  // rectangles, so the PNG is heavy in every engine (WebKit's PNG of flat rectangles alone is lighter than its WebP).
  const png = await page.evaluate(() => {
    const c = document.createElement('canvas');
    c.width = 3000;
    c.height = 2000;
    const g = c.getContext('2d')!;
    const gradient = g.createLinearGradient(0, 0, 3000, 2000);
    gradient.addColorStop(0, '#20476b');
    gradient.addColorStop(1, '#e9c46a');
    g.fillStyle = gradient;
    g.fillRect(0, 0, 3000, 2000);
    for (let i = 0; i < 200; i++) {
      g.fillStyle = `hsl(${i * 7}, 70%, 50%)`;
      g.fillRect((i * 37) % 3000, (i * 53) % 2000, 300, 200);
    }
    return c.toDataURL('image/png').split(',')[1]!;
  });

  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('immagine dalla');
  await page.keyboard.press('Enter');
  const manager = page.getByRole('dialog', { name: 'Gestore dei media' });
  await expect(manager).toBeVisible();
  await expect(manager.getByRole('status').or(manager.getByRole('list')).first()).toBeVisible(); // Empty library: both are there.

  await manager.getByLabel('Nome della nuova raccolta').fill(`Raccolta ${id}`);
  await page.keyboard.press('Enter');
  await manager.getByRole('navigation', { name: 'Raccolte' }).getByRole('button', { name: new RegExp(`Raccolta ${id}`) }).click();

  await manager.locator('input[type="file"]').setInputFiles({ name: `grande-${id}.png`, mimeType: 'image/png', buffer: Buffer.from(png, 'base64') });
  const item = manager.getByRole('button', { name: `Dettagli di grande-${id}` });
  await expect(item).toBeVisible({ timeout: 30_000 });

  const collections = await builder.api<Array<{ id: number; name: string; count: number }>>('GET', '../../wp/v2/xb_media_collection?per_page=100');
  const mine = collections.find((c) => c.name === `Raccolta ${id}`);
  expect(mine?.count).toBe(1);
  const media = await builder.api<Array<{ mime_type: string; media_details: { width: number } }>>('GET', `../../wp/v2/media?xb_media_collection=${mine?.id}&_fields=mime_type,media_details`);
  expect(media[0]?.mime_type).toBe('image/webp');
  expect(media[0]?.media_details.width).toBe(2560);

  await item.click();
  await manager.getByLabel('Testo alternativo').fill('Rettangoli colorati');
  await manager.getByRole('button', { name: 'Salva testo alternativo' }).click();
  await expect(manager.getByRole('status')).toHaveText('Immagine aggiornata.');
  await manager.getByRole('button', { name: 'Usa questa immagine' }).click();
  await expect(builder.html).toContainText('alt="Rettangoli colorati"');
});
