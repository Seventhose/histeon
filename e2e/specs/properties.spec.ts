import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('proprietà: campi del link, dell’immagine e del titolo, in parole', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('HTML');
  await builder.write(builder.html, '<h2 class="t">Titolo</h2><a class="cta" href="/x">Vai</a><img class="foto" src="/wp-includes/images/w-logo-blue.png"/>');

  // Heading: the level, one click.
  await builder.node('h2.t').click();
  await page.getByRole('group', { name: 'Livello' }).getByRole('button', { name: 'H3' }).click();
  await expect(builder.html).toContainText('<h3 class="t">Titolo</h3>');

  // Link: address and new tab, which also adds noopener.
  await builder.node('a.cta').click();
  const links = page.getByRole('group', { name: 'Link' });
  await expect(links.getByLabel('Indirizzo')).toHaveValue('/x');
  await links.getByLabel('Indirizzo').fill('/contatti/');
  await links.getByLabel('Indirizzo').press('Enter');
  await links.getByLabel('Apri in una nuova scheda').check();
  await expect(builder.html).toContainText('<a class="cta" href="/contatti/" target="_blank" rel="noopener">Vai</a>');
  await links.getByLabel('Apri in una nuova scheda').uncheck();
  await expect(builder.html).toContainText('<a class="cta" href="/contatti/">Vai</a>');

  // Image: a missing alt is explained; writing it clears the note.
  await builder.node('img.foto').click();
  const image = page.getByRole('group', { name: 'Immagine' });
  await expect(image).toContainText('Senza testo alternativo');
  await image.getByLabel('Testo alternativo').fill('Logo');
  await image.getByLabel('Testo alternativo').press('Enter');
  await expect(builder.html).toContainText('alt="Logo"');
  await expect(image).not.toContainText('Senza testo alternativo');
  await image.getByLabel('Larghezza').fill('larga');
  await image.getByLabel('Larghezza').press('Enter');
  await expect(image).toContainText('Serve un numero intero.');

  // Every element: an anchor id.
  await page.getByRole('group', { name: 'Identità' }).getByLabel('Id (ancora)').fill('logo');
  await page.getByRole('group', { name: 'Identità' }).getByLabel('Id (ancora)').press('Enter');
  await expect(builder.html).toContainText('id="logo"');
});
