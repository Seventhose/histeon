import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('un componente aperto da solo mostra un contenuto di prova, non una pagina bianca (E5)', async ({ page, data }) => {
  const id = Date.now().toString(36);
  // A table of contents as on an imported site: a hidden nav whose list a script fills, plus a title prop.
  // Made from a part of a page, as the builder does it (the content is stored as Histeon markup).
  const opener = await Builder.open(page, data.pages.palette.id);
  await opener.tab('HTML');
  await opener.write(opener.html, `<nav class="toc${id}" hidden><h2 class="toc${id}__t">Indice</h2><ul class="toc${id}__list"></ul></nav>`);
  await opener.node(`nav.toc${id}`).click({ button: 'right' });
  await page.getByRole('menu', { name: /Azioni su/ }).getByRole('menuitem', { name: 'Crea componente…' }).click();
  await page.getByLabel('Nome del nuovo componente').fill(`Indice ${id}`);
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  await expect(opener.status).toContainText('creato');
  const components = await opener.api<Array<{ id: number; title: string }>>('GET', 'components');
  const created = components.find((c) => c.title === `Indice ${id}`);
  if (!created) throw new Error('componente non creato');
  page.once('dialog', (dialog) => void dialog.accept());

  const builder = await Builder.open(page, created.id);
  await builder.tab('HTML');
  await builder.write(builder.html, `<nav class="toc${id}" hidden><h2 class="toc${id}__t">{props.titolo}</h2><ul class="toc${id}__list"></ul></nav>`);
  const nav = builder.canvas.locator(`nav.toc${id}`);
  // Hidden on the page, shown here, outlined and labelled; the empty list says it is empty.
  await expect(nav).toBeVisible();
  await expect(nav).toHaveAttribute('data-xb-hidden', 'nascosto nella pagina');
  await expect(builder.canvas.locator(`ul.toc${id}__list`)).toHaveAttribute('data-xb-empty', 'vuoto');
  // A prop without a value reads its name; a sample typed in the props panel shows at once, before saving.
  await page.getByRole('button', { name: 'Props del componente' }).click();
  await page.getByLabel('Nome della nuova prop').fill('titolo');
  await page.getByLabel('Nome della nuova prop').press('Enter');
  await expect(builder.canvas.locator(`h2.toc${id}__t`)).toHaveText('Titolo');
  await page.getByLabel('Valore di prova di titolo').fill('Indice del libro');
  await page.getByLabel('Valore di prova di titolo').press('Tab');
  await expect(builder.canvas.locator(`h2.toc${id}__t`)).toHaveText('Indice del libro');

  // With a script in the component, the empty list says a script fills it.
  await builder.tab('HTML'); // closes the props section over the canvas and the code panels
  await builder.write(builder.editor('JavaScript del documento'), `document.querySelector('.toc${id}__list');`);
  await builder.write(builder.html, `<nav class="toc${id}" hidden><h2 class="toc${id}__t">{props.titolo}</h2><ul class="toc${id}__list"></ul><p class="x${id}">x</p></nav>`);
  await expect(builder.canvas.locator(`ul.toc${id}__list`)).toHaveAttribute('data-xb-empty', 'vuoto: lo riempie uno script');

  // Saved, the component keeps its markup as it is: hidden, empty, no sample text.
  await page.keyboard.press('ControlOrMeta+s');
  await expect(builder.status).toContainText(/[Ss]alvato/);
  const doc = await builder.api<{ content: string; schema: { props: Record<string, { sample?: string }> } }>('GET', `documents/${created.id}`);
  expect(doc.content).toContain('hidden');
  expect(doc.content).not.toContain('data-xb-');
  expect(doc.content).not.toContain('Indice del libro');
  expect(doc.schema.props.titolo?.sample).toBe('Indice del libro');
});
