import { expect, test } from '../lib/test.ts';

// Histeon in the block editor's command palette (lista della spesa 140).
test('Gutenberg: Ctrl+K trova i comandi di Histeon e la libreria si apre da lì', async ({ page, data }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  await page.goto(`/wp-admin/post.php?post=${data.pages.review.id}&action=edit`);
  await expect(page.frameLocator('iframe[name="editor-canvas"]').locator('p.uno')).toBeVisible({ timeout: 30_000 });
  const guide = page.getByRole('dialog', { name: /Benvenut|Welcome/ });
  if (await guide.isVisible().catch(() => false)) await page.keyboard.press('Escape');
  await page.keyboard.press('ControlOrMeta+k');
  const palette = page.getByRole('dialog').filter({ has: page.getByRole('combobox') });
  await palette.getByRole('combobox').fill('Histeon');
  await expect(palette.getByRole('option', { name: /apri questa pagina nel builder|open this page in the builder/ })).toBeVisible();
  await expect(palette.getByRole('option', { name: /versioni di questa pagina|versions of this page/ })).toBeVisible();
  await palette.getByRole('option', { name: /sezione dalla libreria|section from the library/ }).click();
  await expect(page.locator('.xb-library')).toBeVisible({ timeout: 30_000 });
});
