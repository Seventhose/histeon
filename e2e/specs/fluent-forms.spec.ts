import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('Fluent Forms nel builder: si inserisce un modulo e si modifica nel suo editor, senza uscire', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.html.id);
  const available = await page.evaluate(() => (window as unknown as { xbBuilder: { fluentForms?: boolean } }).xbBuilder.fluentForms === true);
  test.skip(!available, 'Fluent Forms non è attivo su questo sito di prova');

  await page.keyboard.press('ControlOrMeta+i');
  const menu = page.getByRole('listbox', { name: 'Elementi da aggiungere' });
  await expect(menu).toBeVisible();
  await menu.getByRole('option', { name: /Contact Form Demo/ }).click();
  await expect(builder.canvas.locator('form.fluent_form_1, form[data-form_id="1"], .fluentform').first()).toBeVisible();

  // The form selected: «Modifica il modulo» opens Fluent Forms' editor inside the builder.
  await builder.structure.locator('button.name').last().click();
  await page.getByRole('button', { name: 'Modifica il modulo' }).click();
  const dialog = page.getByRole('dialog', { name: /Modulo: Contact Form Demo/ });
  await expect(dialog).toBeVisible();
  const editor = dialog.frameLocator('iframe');
  await expect(editor.locator('#wpadminbar')).toBeHidden();
  await expect(editor.locator('body')).toContainText(/Contact Form Demo|Submit Form|Input Fields/);
  await dialog.getByRole('button', { name: /Chiudi e aggiorna/ }).click();
  await expect(dialog).toBeHidden();
});
