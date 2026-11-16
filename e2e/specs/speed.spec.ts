import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('azioni rapide: classi BEM, componente dalla selezione, SVG incollato e ripulito', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<article class="scheda${id}"><h2>Titolo</h2><p>Testo</p></article>`);

  // Auto-BEM: the children without a class get blocco__parte.
  await builder.node(`article.scheda${id}`).click();
  await page.getByRole('button', { name: 'Classi BEM' }).click();
  await expect(page.getByRole('status').filter({ hasText: `scheda${id}__title` })).toBeVisible();
  await expect(builder.html).toContainText(`<h2 class="scheda${id}__title">`);
  await expect(builder.html).toContainText(`<p class="scheda${id}__text">`);

  // Component from the selection: the subtree becomes the component, the selection an instance.
  await page.getByRole('button', { name: /Crea componente/ }).click();
  await page.getByLabel('Nome del nuovo componente').fill(`Scheda ${id}`);
  await expect(page.getByLabel('Chiave del nuovo componente')).toHaveValue(`scheda-${id}`.replace(/-([0-9])/g, '$1'));
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  await expect(builder.status).toContainText('creato');
  await expect(builder.html).not.toContainText('<article');
  await expect(builder.canvas.getByRole('heading', { name: 'Titolo' })).toBeVisible();
  const components = await builder.api<Array<{ ref: string }>>('GET', 'components');
  expect(components.map((c) => c.ref)).toContain(`scheda-${id}`.replace(/-([0-9])/g, '$1'));

  // SVG pasted: drawing kept, handlers, scripts and outside references dropped.
  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('svg');
  await page.keyboard.press('Enter');
  await page.getByLabel('Codice SVG').fill('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" onload="alert(1)"><script>alert(2)</script><path d="M0 0h24v24" fill="currentColor"/><use href="https://evil.example/x.svg#a"/><text>{a}</text></svg>');
  await page.getByRole('button', { name: 'Inserisci', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Tolti' })).toContainText('onload');
  await page.getByRole('button', { name: 'Chiudi', exact: true }).click();
  await expect(builder.html).toContainText('<path d="M0 0h24v24" fill="currentColor"');
  await expect(builder.html).not.toContainText('alert');
  await expect(builder.html).not.toContainText('evil.example');
  await expect(builder.html).toContainText('\\{a\\}');
});

test('icone dalla libreria inclusa: si cercano e diventano SVG nella pagina (E10)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.due').click();
  // Nothing loads from outside the site.
  const outside: string[] = [];
  page.on('request', (r) => {
    if (!r.url().startsWith(new URL(page.url()).origin) && !r.url().startsWith('data:')) outside.push(r.url());
  });
  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('Icona dalla libreria');
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Icone' });
  await expect(dialog).toContainText('licenza ISC');
  await dialog.getByRole('searchbox', { name: 'Cerca un’icona' }).fill('arrow right');
  const choice = dialog.getByRole('button', { name: 'Inserisci l’icona arrow-right', exact: true });
  await expect(choice.locator('svg path')).not.toHaveCount(0);
  await choice.click();
  await expect(dialog).toBeHidden();
  const icon = builder.canvas.locator('svg.icon--arrow-right');
  await expect(icon).toHaveAttribute('stroke', 'currentColor');
  await expect(icon).toHaveAttribute('aria-hidden', 'true');
  await expect(icon.locator('path')).not.toHaveCount(0);
  expect(outside).toEqual([]);
});
