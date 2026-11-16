import { expect, test } from '../lib/test.ts';

test('primo avvio: framework, moduli e importazioni in una pagina; poi Histeon è pronto', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=xb-setup');
  await expect(page.getByRole('heading', { name: 'Primo avvio', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Il framework CSS' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'I moduli' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Da dove arrivi' })).toBeVisible();

  const histosCss = page.locator('label.xb-choice').filter({ hasText: 'consigliato' }).getByRole('radio');
  await histosCss.check();
  await page.getByRole('button', { name: 'Salva e inizia' }).click();
  await expect(page).toHaveURL(/page=xb(&|$)/);
  await expect(page.getByText('Histeon è pronto.')).toBeVisible();

  // The page stays reachable from Impostazioni.
  await page.goto('/wp-admin/admin.php?page=xb-settings');
  await page.getByRole('link', { name: 'Primo avvio' }).first().click();
  await expect(page.getByRole('heading', { name: 'Primo avvio', level: 1 })).toBeVisible();
});
