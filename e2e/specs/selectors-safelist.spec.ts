import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

let created = 0;
test.afterEach(() => {
  if (created) wpEval(`<?php wp_delete_post( ${created}, true );`, {});
  created = 0;
});

// A class that only a script adds looks unused; from the selector manager it goes in the safelist (lista migliorie C6).
test('una classe aggiunta da uno script si mette nella safelist dal gestore dei selettori', async ({ page }) => {
  const id = Date.now().toString(36);
  const cls = `c6js${id}`;
  const content = Buffer.from(el('section', null, el('h2', null, text('Safelist')), el('p', { class: cls }, text('Aperto')))).toString('base64');
  created = Number(wpEval(`<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'C6', 'post_content' => wp_slash( base64_decode( '${content}' ) ) ) );`, {}).trim().split('\n').pop());
  const builder = await Builder.open(page, created);
  await builder.node(`p.${cls}`).click();
  await builder.tab('CSS');
  await builder.write(builder.editor(`CSS di .${cls}`), 'display: block;');
  await builder.save();
  // Now only a script would add the class: no page has it any more.
  await builder.edit(builder.html, (code) => code.replace(` class="${cls}"`, ''));
  await builder.save();

  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Stili e selettori', exact: true }).click();
  await page.getByRole('searchbox', { name: 'Cerca un selettore' }).fill(cls);
  await page.getByRole('button', { name: new RegExp(`^\\.${cls}`) }).click();
  await expect(page.getByRole('button', { name: 'Elimina selettore' })).toBeVisible();
  await page.getByRole('button', { name: 'Usata da script, props o widget: tienila sempre' }).click();
  await expect(page.getByRole('status').filter({ hasText: `.${cls} è nella safelist` })).toBeVisible();
  // The row stays open after the list reloads.
  await expect(page.getByText('Nella safelist (Impostazioni → Histeon): sempre incluso.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Elimina selettore' })).toHaveCount(0);

  await page.getByRole('button', { name: 'Togli dalla safelist' }).click();
  await expect(page.getByRole('status').filter({ hasText: `.${cls} non è più nella safelist.` })).toBeVisible();
});
