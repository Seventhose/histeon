import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The HTML and CSS panels apply typing after a short pause: saving right after the last keystroke must not lose it.
test('Ctrl+S subito dopo aver scritto nel pannello HTML salva anche le ultime modifiche', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.save.id);
  const changed = `Salvato al volo ${Date.now().toString(36)}`;

  await builder.edit(builder.html, (code) => code.replace('Primo paragrafo', changed));
  // No wait for the pause (nor for the canvas): the shortcut straight after typing.
  await page.keyboard.press('ControlOrMeta+s');
  await expect(builder.status).toHaveText('Salvato in sviluppo: non ancora pubblicato');
  await expect(builder.saveButton).toBeDisabled();

  await page.reload();
  await builder.ready();
  await expect(builder.canvas.getByText(changed)).toBeVisible();
  await expect(builder.html).toContainText(changed);
});

test('il pulsante Salva subito dopo aver scritto nel pannello CSS salva anche le ultime modifiche', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.save.id);
  const color = `rgb(3, 4, ${10 + Math.floor(Math.random() * 240)})`;

  await builder.node('p.due').click();
  await builder.tab('CSS');
  await builder.write(builder.editor('CSS di .due'), `color: ${color};`);
  // Typing counts as a change at once: the button is enabled before the pause ends.
  await builder.saveButton.click();
  await expect(builder.status).toHaveText('Salvato in sviluppo: non ancora pubblicato');
  await expect(builder.saveButton).toBeDisabled();

  await page.reload();
  await builder.ready();
  await expect(builder.canvas.locator('p.due')).toHaveCSS('color', color);
});

test('dopo un salvataggio, una nuova modifica non risulta più salvata', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.save.id);

  await builder.edit(builder.html, (code) => code.replace('Secondo paragrafo', `Secondo ${Date.now().toString(36)}`));
  await builder.save();
  await expect(builder.status).toHaveText('Salvato in sviluppo: non ancora pubblicato');

  await builder.edit(builder.html, (code) => code.replace('Ciao e2e', 'Ciao di nuovo'));
  await expect(builder.status).toHaveText('Modifiche non salvate');
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao di nuovo' })).toBeVisible();
  await expect(builder.status).toHaveText('Modifiche non salvate');
  await builder.save();
  await expect(builder.status).toHaveText('Salvato in sviluppo: non ancora pubblicato');
});

test('Ctrl+S subito dopo aver scritto nel gestore dei selettori salva anche le ultime modifiche', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.save.id);
  // The manager lists saved selectors: save one first.
  await builder.node('p.due').click();
  await builder.tab('CSS');
  await builder.write(builder.editor('CSS di .due'), 'color: rgb(1, 1, 1);');
  await builder.save();

  const color = `rgb(5, 6, ${10 + Math.floor(Math.random() * 240)})`;
  await builder.tab('Selettori');
  const manager = page.locator('.selectors');
  await manager.getByRole('searchbox', { name: 'Cerca un selettore' }).fill('due');
  await manager.getByRole('button', { name: /^\.due\b/ }).first().click();
  await builder.write(manager.getByRole('textbox', { name: 'CSS di .due', exact: true }), `color: ${color};`);
  // The shortcut straight after typing: the manager's pause must not swallow it.
  await page.keyboard.press('ControlOrMeta+s');
  await expect(builder.status).toHaveText('Salvato in sviluppo: non ancora pubblicato');
  await expect(builder.saveButton).toBeDisabled();

  await page.reload();
  await builder.ready();
  await expect(builder.canvas.locator('p.due')).toHaveCSS('color', color);
});
