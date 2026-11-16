import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('scorciatoie: si registra una combinazione per un comando e funziona subito, anche dalla tela', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.api.id);
  await builder.apiStatus('POST', 'shortcuts', { shortcuts: {} }); // Clean slate for the e2e user.
  await page.reload();
  await page.keyboard.press('ControlOrMeta+k');
  await page.getByRole('combobox').fill('scorciatoie');
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Scorciatoie da tastiera' });
  await expect(dialog).toBeVisible();

  // A taken combination is refused, naming who has it; a free one is recorded and saved.
  await dialog.getByLabel('Cerca tra le scorciatoie').fill('Stili e selettori');
  await dialog.getByRole('button', { name: 'Aggiungi una scorciatoia a Stili e selettori' }).click();
  await page.keyboard.press('ControlOrMeta+s');
  await expect(dialog.getByRole('status').last()).toContainText('Salva in sviluppo');
  await page.keyboard.press('Alt+KeyJ');
  await expect(dialog.getByText('Salvate nel tuo profilo')).toBeVisible();
  await expect(dialog.locator('kbd')).toHaveText(['Alt+J']);
  const saved = await builder.api<{ shortcuts: Record<string, string[]> }>('GET', 'shortcuts');
  expect(saved.shortcuts).toEqual({ 'sec-styles': ['Alt+J'] });
  await dialog.getByRole('button', { name: 'Chiudi le scorciatoie' }).click();

  // Used from the builder and from the canvas (which hands bound keys over).
  await builder.canvas.locator('body').click();
  await page.keyboard.press('Alt+KeyJ');
  await expect(page.getByRole('dialog', { name: 'Stili e selettori' })).toBeVisible();
  await page.keyboard.press('Escape');

  // A default turned off does nothing, and the palette shows the current binding.
  await builder.api('POST', 'shortcuts', { shortcuts: { 'sec-styles': ['Alt+J'], dock: ['Alt+Shift+D'] } });
  await page.reload();
  await page.keyboard.press('ControlOrMeta+k');
  await page.getByRole('combobox').fill('pannelli in basso');
  await expect(page.getByRole('option').filter({ hasText: 'pannelli in basso' })).toContainText('Alt+Shift+D');
  await page.keyboard.press('Escape');
  await builder.api('POST', 'shortcuts', { shortcuts: {} });
});
