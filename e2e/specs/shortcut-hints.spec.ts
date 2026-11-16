import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

let created = 0;
test.afterEach(() => {
  if (created) wpEval(`<?php wp_delete_post( ${created}, true );`, {});
  created = 0;
});

// The hints say the user's own keys, and the canvas saves with them too (lista migliorie U9).
test('i suggerimenti mostrano le scorciatoie dell’utente, e la tela salva con la stessa combinazione', async ({ page, data }) => {
  const content = Buffer.from(el('section', null, el('h2', null, text('Scorciatoie')), el('p', { class: 'tasti' }, text('Da cambiare')))).toString('base64');
  created = Number(wpEval(`<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'U9', 'post_content' => wp_slash( base64_decode( '${content}' ) ) ) );`, {}).trim().split('\n').pop());
  const builder = await Builder.open(page, data.pages.api.id);
  await builder.api('POST', 'shortcuts', { shortcuts: { save: ['Alt+Shift+S'], dup: ['Alt+Shift+K'] } });
  try {
    await page.goto(Builder.url(created));
    await builder.ready();
    await expect(builder.saveButton).toHaveAttribute('title', 'Salva in sviluppo (Alt+Shift+S)');
    await expect(page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' })).toHaveAttribute('title', 'Aggiungi un elemento (Ctrl+I)');

    await builder.node('p.tasti').click({ button: 'right' });
    const duplicate = page.getByRole('menuitem', { name: /^Duplica/ });
    await expect(duplicate).toContainText('Alt+Shift+K');
    await expect(duplicate).toHaveAttribute('aria-keyshortcuts', 'Alt+Shift+K');
    await page.keyboard.press('Escape');

    // Writing in the canvas: the user's save keys confirm and save.
    await builder.canvas.getByText('Da cambiare').dblclick();
    await page.keyboard.press('End');
    await page.keyboard.type(' salvato');
    await page.keyboard.press('Alt+Shift+S');
    await expect(builder.status).toHaveText(/^Salvato in sviluppo/);
    await expect(builder.canvas.getByText('Da cambiare salvato')).toBeVisible();
  } finally {
    await builder.api('POST', 'shortcuts', { shortcuts: {} });
  }
});
