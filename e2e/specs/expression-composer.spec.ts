import { Builder } from '../lib/builder.ts';
import { ownPage } from '../lib/page.ts';
import { expect, test } from '../lib/test.ts';

// Lista della spesa 156: a piece of data without writing the expression. The composer picks the field and its
// format, shows the code it writes, adds it to the content, and the canvas shows the value.
test('compositore di espressioni: un dato con forma e testo, scritto nel codice e mostrato nella tela', async ({ page }) => {
  const builder = await Builder.open(page, ownPage(`Espressioni ${Date.now().toString(36)}`));
  await builder.tab('HTML');
  await builder.write(builder.html, '<p class="nota">Benvenuti su </p>');
  await builder.node('p.nota').click();

  await page.getByRole('button', { name: /Inserisci un dato/ }).click();
  await page.getByLabel('Dato').selectOption({ label: 'Nome del sito' });
  await page.getByLabel('Forma').selectOption({ label: 'MAIUSCOLO' });
  await page.getByLabel('Testo dopo').fill('!');
  await expect(page.getByLabel('Come si scrive nel codice')).toHaveText('{site.name.upper()}!');
  await page.getByRole('button', { name: 'Aggiungi al contenuto' }).click();
  await expect(builder.html).toContainText('<p class="nota">Benvenuti su {site.name.upper()}!</p>');
  // The site's name, in capitals (no lowercase letter left before the «!»).
  await expect(builder.canvas.locator('p.nota')).toHaveText(/^Benvenuti su [^a-z{}]+!$/);

  // A date, in words.
  await page.getByRole('button', { name: /Inserisci un dato/ }).click();
  await page.getByLabel('Dato').selectOption({ label: 'Data di pubblicazione' });
  await page.getByLabel('Forma della data').selectOption({ label: '04/11/2026' });
  await page.getByLabel('Testo prima').fill(' · ');
  await page.getByRole('button', { name: 'Aggiungi al contenuto' }).click();
  await expect(builder.html).toContainText('{site.name.upper()}! · {this.date.date("d/m/Y")}</p>');
  await expect(builder.canvas.locator('p.nota')).toContainText(/ · \d{2}\/\d{2}\/\d{4}/);
});
