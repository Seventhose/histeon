import { expect, test } from '../lib/test.ts';

test('nuovo template: dal pannello di Histeon → Template al builder, e un nome già usato viene rifiutato', async ({ page }) => {
  const slug = `xb-e2e-${Date.now()}`;
  await page.goto('/wp-admin/admin.php?page=xb-templates');
  await page.getByText('Nuovo template').click();
  await page.getByLabel('Nome tecnico').fill(slug);
  await page.getByLabel('Titolo').fill('Landing di prova');
  await page.getByLabel('Parti da').selectOption({ index: 0 });
  await page.getByRole('button', { name: 'Crea e apri in Histeon' }).click();
  await expect(page).toHaveURL(/action=xb[-_]builder|xb-builder/);
  await expect(page.locator('header.topbar')).toBeVisible();

  // Listed among the custom templates; the same name again is refused with the reason.
  await page.goto('/wp-admin/admin.php?page=xb-templates');
  await expect(page.getByText(slug, { exact: true })).toBeVisible();
  await page.getByText('Nuovo template').click();
  await page.getByLabel('Nome tecnico').fill(slug);
  await page.getByRole('button', { name: 'Crea e apri in Histeon' }).click();
  await expect(page.getByText(/Esiste già un template con questo nome tecnico/)).toBeVisible();
});
