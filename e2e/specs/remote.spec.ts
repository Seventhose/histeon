import { expect, test } from '../lib/test.ts';

test('staging e produzione: il codice si vede una volta, si revoca, e un codice sbagliato o di questo stesso sito non collega', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=xb-releases');
  const receive = page.locator('.xb-panel').filter({ has: page.getByRole('heading', { name: 'Ricevi da uno staging' }) });
  if (await receive.getByRole('button', { name: 'Revoca il collegamento' }).isVisible()) {
    await receive.getByRole('button', { name: 'Revoca il collegamento' }).click();
  }
  await receive.getByLabel('Nome dello staging').fill('staging di prova');
  await receive.getByRole('button', { name: 'Crea il codice di abbinamento' }).click();
  await expect(page.getByText('Codice creato: copialo sul sito di staging.')).toBeVisible();
  const code = await page.getByLabel(/Il codice di abbinamento/).inputValue();
  expect(code).toMatch(/^histos-link-1:[A-Za-z0-9_-]+$/);
  await expect(receive.getByText('può importare in sviluppo, non pubblicare')).toBeVisible();

  // Shown once: a reload no longer has it.
  await page.reload();
  await expect(page.getByLabel(/Il codice di abbinamento/)).toHaveCount(0);

  // The staging side refuses a broken code, and this site's own code.
  const send = page.locator('.xb-panel').filter({ has: page.getByRole('heading', { name: 'Invia a produzione' }) });
  await send.getByLabel('Codice di abbinamento del sito di produzione').fill('histos-link-1:rotto');
  await send.getByRole('button', { name: 'Collega' }).click();
  await expect(page.getByText(/Codice di abbinamento non valido/)).toBeVisible();
  await send.getByLabel('Codice di abbinamento del sito di produzione').fill(code);
  await send.getByRole('button', { name: 'Collega' }).click();
  await expect(page.getByText(/Questo è il codice di questo stesso sito|Il sito di produzione deve usare HTTPS/)).toBeVisible();

  await receive.getByRole('button', { name: 'Revoca il collegamento' }).click();
  await expect(page.getByText('Collegamento revocato')).toBeVisible();
  await expect(receive.getByRole('button', { name: 'Crea il codice di abbinamento' })).toBeVisible();
});
