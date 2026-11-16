import { readFile } from 'node:fs/promises';
import { expect, test } from '../lib/test.ts';

test('registro: le operazioni importanti, filtrate per operazione, ed esportate in CSV', async ({ page }) => {
  // An operation to find: a site export writes «export_site» in the log.
  await page.goto('/wp-admin/admin.php?page=xb-releases');
  await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Esporta tutto il sito' }).click()]);

  await page.goto('/wp-admin/admin.php?page=xb-audit');
  await expect(page.getByRole('heading', { name: 'Registro', level: 1 })).toBeVisible();
  await page.getByLabel('Operazione').selectOption({ label: 'Sito esportato (trasloco)' });
  await page.getByRole('button', { name: 'Filtra' }).click();
  await expect(page).toHaveURL(/op=export_site/);
  const rows = page.locator('table.xb-audit tbody tr');
  await expect(rows.first()).toContainText('Sito esportato (trasloco)');
  expect(await rows.filter({ hasNotText: 'Sito esportato (trasloco)' }).count()).toBe(0);

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Esporta CSV' }).click()]);
  expect(download.suggestedFilename()).toMatch(/^histos-registro-\d{8}-\d{6}\.csv$/);
  const csv = await readFile(await download.path(), 'utf8');
  expect(csv).toContain('Sito esportato (trasloco)');
  expect(csv.split('\n')[0]).toContain('Operazione');

  await page.getByRole('link', { name: 'Azzera' }).click();
  await expect(page).not.toHaveURL(/op=/);
});
