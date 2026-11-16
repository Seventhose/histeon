import { readFile } from 'node:fs/promises';
import { expect, test } from '../lib/test.ts';

test('trasloco del sito: esportazione, anteprima dell’importazione, annullamento', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=xb-releases');
  // Export and import are two sections of the page (lista P10): «Esporta» and the single «Importa un file».
  await expect(page.getByRole('heading', { name: /^Esporta/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: /Importa un file/ })).toBeVisible();

  // Playwright's download API: the file is captured by the test, never written to a user folder.
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Esporta tutto il sito' }).click()]);
  expect(download.suggestedFilename()).toMatch(/\.xbsite$/);
  // A ZIP with the manifest (its contents are checked by the integration tests, SiteTransferTest).
  const bytes = await readFile(await download.path());
  expect(bytes.subarray(0, 2).toString('latin1')).toBe('PK');
  expect(bytes.includes(Buffer.from('site.json'))).toBe(true);

  // Check: the plan, nothing written; then cancel.
  // The single field recognises a whole-site file (site.json version 1) and opens the site check.
  await page.getByLabel('File da importare').setInputFiles(await download.path());
  await page.locator('form').filter({ has: page.getByLabel('File da importare') }).getByRole('button', { name: 'Controlla il file' }).click();
  await expect(page.getByRole('heading', { name: 'Importare questo sito?' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Importa in sviluppo' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Importa e pubblica' })).toBeVisible();
  await page.getByRole('button', { name: 'Annulla' }).click();
  await expect(page.getByText('Trasloco annullato.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Importare questo sito?' })).toHaveCount(0);

  // A file that is none of the three is refused with what the field accepts.
  await page.getByLabel('File da importare').setInputFiles({ name: 'finto.xbsite', mimeType: 'application/zip', buffer: Buffer.from('non sono un sito') });
  await page.locator('form').filter({ has: page.getByLabel('File da importare') }).getByRole('button', { name: 'Controlla il file' }).click();
  await expect(page.getByText(/Non riconosco questo file/)).toBeVisible();
});
