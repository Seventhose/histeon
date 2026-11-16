import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The site's own scripts (lista della spesa 148), from the JavaScript tab: a file of another site is refused without
// its integrity hash, one of this site is saved in development (never published here: the other specs keep a clean
// site), and removing it leaves the list as it was.
test('Script del sito: si aggiungono dal pannello JavaScript, un file esterno vuole l\'impronta', async ({ page, data, consoleErrors }) => {
  const builder = await Builder.open(page, data.pages.css.id);
  const before = await builder.api<{ scripts: unknown[] }>('GET', 'site-scripts');
  await builder.tab('JS');
  const section = page.locator('.xb-site-scripts');
  await section.locator('summary').click();
  await section.getByRole('button', { name: 'Aggiungi uno script' }).click();
  const entry = section.locator('fieldset').last();
  await entry.getByLabel('Nome').fill('Mappa e2e');
  await entry.getByLabel('Indirizzo del file').fill('https://cdn.example.com/mappa.js');
  // Another site's file: the hash field appears, and the server refuses the save without it.
  await expect(entry.getByLabel('Impronta di integrità')).toBeVisible();
  await section.getByRole('button', { name: 'Salva in sviluppo' }).click();
  await expect(section.getByRole('alert')).toContainText(/impronta di integrità/);
  // The refusal is the expected 400: the browser logs it, nothing else may.
  await expect.poll(() => consoleErrors.filter((e) => e.includes('status of 400')).length).toBe(1);
  consoleErrors.splice(consoleErrors.findIndex((e) => e.includes('status of 400')), 1);

  await entry.getByLabel('Indirizzo del file').fill('/wp-content/uploads/js/mappa-e2e.js');
  await expect(entry.getByLabel('Impronta di integrità')).toHaveCount(0);
  await entry.getByLabel('Dove').selectOption('type:page');
  await entry.getByLabel('Caricamento').selectOption('module');
  await section.getByRole('button', { name: 'Salva in sviluppo' }).click();
  await expect(section.getByRole('status')).toContainText('prossima release');
  const saved = await builder.api<{ scripts: Array<{ label: string; src: string; scope: string; strategy: string }> }>('GET', 'site-scripts');
  expect(saved.scripts.at(-1)).toMatchObject({ label: 'Mappa e2e', src: '/wp-content/uploads/js/mappa-e2e.js', scope: 'type:page', strategy: 'module' });

  await entry.getByRole('button', { name: 'Togli' }).click();
  await section.getByRole('button', { name: 'Salva in sviluppo' }).click();
  await expect(section.getByRole('status')).toBeVisible();
  expect((await builder.api<{ scripts: unknown[] }>('GET', 'site-scripts')).scripts).toEqual(before.scripts);
});
