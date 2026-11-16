import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

const LOGO = '/wp-includes/images/w-logo-blue.png';
let created = 0;
test.afterEach(() => {
  if (created) wpEval(`<?php wp_delete_post( ${created}, true );`, {});
  created = 0;
});

function page(): number {
  const content = Buffer.from(
    el('section', { class: 'galleria' }, el('h2', null, text('Galleria')), el('img', { src: LOGO, alt: 'Logo' }), el('img', { src: `${LOGO}?due`, class: 'senza' }), el('img', { src: `${LOGO}?tre`, alt: '' })),
  ).toString('base64');
  return Number(wpEval(`<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Alt', 'post_content' => wp_slash( base64_decode( '${content}' ) ) ) );`, {}).trim().split('\n').pop());
}

// Every image of the page with its alt in one table, with a filter for the missing ones (lista migliorie M4).
test('tabella dei testi alternativi della pagina, con il filtro «senza alt»', async ({ page: tab }) => {
  created = page();
  const builder = await Builder.open(tab, created);
  await tab.keyboard.press('ControlOrMeta+k');
  await tab.getByRole('dialog', { name: 'Comandi' }).getByRole('combobox').fill('Testi alternativi della pagina');
  await tab.keyboard.press('Enter');
  const table = tab.getByRole('dialog', { name: 'Testi alternativi della pagina' });
  await expect(table.getByText('3 immagini · 1 senza testo alternativo')).toBeVisible();
  await table.getByLabel('Solo senza testo alternativo').check();
  await expect(table.getByRole('row')).toHaveCount(2);
  await table.getByRole('textbox', { name: 'Testo alternativo di w-logo-blue.png?due' }).fill('Il logo, {di nuovo}');
  await table.getByRole('textbox', { name: 'Testo alternativo di w-logo-blue.png?due' }).press('Enter');
  await expect(table.getByText('Ogni immagine ha il suo testo alternativo.')).toBeVisible();
  await builder.tab('HTML');
  await expect(builder.canvas.locator('img.senza')).toHaveAttribute('alt', 'Il logo, {di nuovo}');
});

// The focal point: where an image is held when its box crops it, as object-position (M4).
test('il punto focale di un’immagine si sceglie con un clic e diventa object-position', async ({ page: tab }) => {
  created = page();
  const builder = await Builder.open(tab, created);
  await builder.node('img.senza').click();
  const pick = tab.getByRole('button', { name: 'Punto focale: al centro' });
  const box = (await pick.boundingBox())!;
  await tab.mouse.click(box.x + box.width * 0.25, box.y + box.height * 0.75);
  await expect(builder.canvas.locator('img.senza')).toHaveCSS('object-position', '25% 75%');
  await expect(tab.getByRole('button', { name: 'Punto focale: 25% da sinistra, 75% dall’alto' })).toBeFocused();
  await tab.keyboard.press('ArrowRight');
  await expect(builder.canvas.locator('img.senza')).toHaveCSS('object-position', '30% 75%');
  await tab.getByRole('button', { name: 'Al centro' }).click();
  await expect(builder.canvas.locator('img.senza')).not.toHaveAttribute('style');
});
