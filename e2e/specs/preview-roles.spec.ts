import { Builder } from '../lib/builder.ts';
import { ownPage } from '../lib/page.ts';
import { expect, test } from '../lib/test.ts';

// Lista della spesa 161: the preview seen as a visitor without an account or as a subscriber, from its own bar.
test('anteprima vista come ruolo: senza accesso, abbonato, chi visita ora', async ({ page }) => {
  const builder = await Builder.open(page, ownPage(`Ruoli ${Date.now().toString(36)}`));
  await builder.tab('HTML');
  await builder.write(builder.html, '{#if user.loggedIn}<p class="ruolo">Ruolo {user.role}</p>{:else}<p class="ruolo">Accedi</p>{/if}');
  await page.getByRole('button', { name: 'Anteprima', exact: true }).first().click();
  const dialog = page.getByRole('dialog', { name: 'Anteprima della pagina' });
  const frame = dialog.frameLocator('iframe');
  await expect(frame.locator('p.ruolo')).toHaveText('Ruolo administrator');
  await dialog.getByLabel('Vedi come').selectOption('subscriber');
  await expect(frame.locator('p.ruolo')).toHaveText('Ruolo subscriber');
  await dialog.getByLabel('Vedi come').selectOption('guest');
  await expect(frame.locator('p.ruolo')).toHaveText('Accedi');
  await expect(frame.locator('#wpadminbar')).toHaveCount(0);
  await dialog.getByRole('button', { name: /Torna al builder/ }).click();
});

// V3.1: how long the shared link lasts, chosen in the preview bar; the copied link opens without an account and
// carries the expiry chosen, which the bar shows as «Scade il …».
test('anteprima: durata del link, «Copia link» e scadenza; il link copiato si apre senza accesso', async ({ page, context, browser, baseURL }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseURL });
  const builder = await Builder.open(page, ownPage(`Durata ${Date.now().toString(36)}`));
  await builder.edit(builder.html, (code) => code.replace('Pagina di prova', 'Solo in sviluppo, da condividere'));
  await builder.save();
  await page.getByRole('button', { name: 'Anteprima', exact: true }).first().click();
  const dialog = page.getByRole('dialog', { name: 'Anteprima della pagina' });
  const share = dialog.getByRole('group', { name: 'Link da condividere' });
  const duration = share.getByLabel('Durata del link');
  const expires = share.getByText(/^Scade il /);
  const copy = async (): Promise<{ url: string; expiry: number }> => {
    await share.getByRole('button', { name: 'Copia link' }).click();
    await expect(share.getByRole('button', { name: 'Copiato' })).toBeVisible();
    const url = await page.evaluate(() => navigator.clipboard.readText());
    const token = new URL(url).searchParams.get('xb_preview') ?? '';
    return { url, expiry: Number(token.split('.')[0]) };
  };
  const shown = (expiry: number) => page.evaluate((s) => new Date(s * 1000).toLocaleString(document.documentElement.lang || 'it-IT', { dateStyle: 'medium', timeStyle: 'short' }), expiry);

  // Two days by default.
  await expect(duration).toHaveValue('172800');
  await expect(duration.locator('option')).toHaveText(['1 ora', '1 giorno', '2 giorni', '7 giorni']);
  await expect(expires).toBeVisible();
  const two = await copy();
  expect(Math.abs(two.expiry - (Date.now() / 1000 + 172800))).toBeLessThan(120);
  await expect(expires).toHaveText(`Scade il ${await shown(two.expiry)}`);

  // One hour: a new link, and the line follows.
  await duration.selectOption('3600');
  await expect(expires).not.toHaveText(`Scade il ${await shown(two.expiry)}`);
  const hour = await copy();
  expect(hour.url).not.toBe(two.url);
  expect(Math.abs(hour.expiry - (Date.now() / 1000 + 3600))).toBeLessThan(120);
  await expect(expires).toHaveText(`Scade il ${await shown(hour.expiry)}`);
  await expect(dialog.getByRole('link', { name: /Nuova scheda/ })).toHaveAttribute('href', hour.url);
  const shots = process.env.XB_E2E_SHOTS;
  if (shots) await page.screenshot({ path: `${shots}/v3-anteprima.png` });

  // Seven days.
  await duration.selectOption('604800');
  await expect(expires).not.toHaveText(`Scade il ${await shown(hour.expiry)}`);
  const week = await copy();
  expect(Math.abs(week.expiry - (Date.now() / 1000 + 604800))).toBeLessThan(120);

  // The copied link, in a browser without any account: the development version, with the preview banner.
  const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    const tab = await guest.newPage();
    await tab.goto(hour.url);
    await expect(tab.getByText('Solo in sviluppo, da condividere')).toBeVisible();
    await expect(tab.getByText('Anteprima di sviluppo Histeon: non è il sito pubblicato')).toBeVisible();
    await expect(tab.locator('#wpadminbar')).toHaveCount(0);
  } finally {
    await guest.close();
  }
  await dialog.getByRole('button', { name: /Torna al builder/ }).click();
});
