import { readFile } from 'node:fs/promises';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('libreria di sezioni: si salva l’elemento selezionato, si reinserisce dalla finestra di incolla, si esporta e reimporta', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.html.id);
  const name = `Sezione e2e ${Date.now()}`;
  await builder.structure.locator('button.name').first().click();

  const library = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Libreria', exact: true });
  await library.click();
  await page.getByPlaceholder(/Nome della sezione/).fill(name);
  await page.getByRole('button', { name: 'Salva nella libreria' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'salvata nella libreria' })).toBeVisible();
  const row = page.locator('li').filter({ hasText: name });
  await expect(row).toBeVisible();

  // Export: a .xblibrary file with this section.
  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Esporta', exact: true }).click()]);
  expect(download.suggestedFilename()).toMatch(/\.xblibrary$/);
  const file = JSON.parse(await readFile(await download.path(), 'utf8')) as { format: string; sections: Array<{ name: string }> };
  expect(file.format).toBe('xb-library');
  expect(file.sections.map((s) => s.name)).toContain(name);

  // Importing the same file adds nothing new.
  await page.locator('input[type="file"][accept*="xblibrary"]').setInputFiles(await download.path());
  await expect(page.getByRole('status').filter({ hasText: /Importate 0 sezioni/ })).toBeVisible();

  // As instructions for the agent (lista della spesa 166): the request waits in the ✦ panel, with the brief.
  await row.getByRole('button', { name: 'Fai ricostruire all’agente' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Richiesta mandata all’agente' })).toBeVisible();
  await page.getByRole('button', { name: 'Agente AI' }).click();
  const agentPanel = page.getByRole('dialog', { name: 'Connettore AI' });
  await expect(agentPanel.getByText(`Ricostruisci in questa pagina la sezione «${name}» della libreria`)).toBeVisible();
  await page.keyboard.press('Escape');
  if (!(await row.isVisible())) await library.click();

  // Insert: the paste dialog, as for a clip from another site.
  const before = (await builder.nodes()).length;
  await row.getByRole('button', { name: 'Inserisci' }).click();
  const dialog = page.getByRole('dialog', { name: /Incolla da/ });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Incolla' }).click();
  await expect.poll(async () => (await builder.nodes()).length).toBeGreaterThan(before);

  // Remove it again (the dev site is shared with other specs).
  await library.click();
  page.once('dialog', (d) => void d.accept());
  await page.locator('li').filter({ hasText: name }).getByRole('button', { name: /Togli/ }).click();
  await expect(page.locator('li').filter({ hasText: name })).toHaveCount(0);
});
