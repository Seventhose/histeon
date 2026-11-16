import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

let created = 0;
test.afterEach(() => {
  if (created) wpEval(`<?php wp_delete_post( ${created}, true );`, {});
  created = 0;
});

// The rename reaches free HTML too, occurrence by occurrence, as chosen in the preview (lista migliorie C4).
test('la rinomina di una classe riscrive anche l’HTML libero, solo le occorrenze scelte', async ({ page }) => {
  const id = Date.now().toString(36);
  const from = `c4x${id}`;
  const to = `c4y${id}`;
  const raw = (html: string, uid: string) => `<!-- wp:xb/raw ${JSON.stringify({ uid, content: html }).replace(/</g, '\\u003c').replace(/>/g, '\\u003e').replace(/--/g, '\\u002d\\u002d')} /-->`;
  const content = el('section', null, el('h2', { class: from }, text('Titolo'))) + raw(`<div class="${from} libero">uno</div>`, `c4ra${id}`.slice(0, 12)) + raw(`<div class="${from}">due</div>`, `c4rb${id}`.slice(0, 12));
  const encoded = Buffer.from(content).toString('base64');
  created = Number(wpEval(`<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'C4', 'post_content' => wp_slash( base64_decode( '${encoded}' ) ) ) );`, {}).trim().split('\n').pop());
  const builder = await Builder.open(page, created);
  await builder.node(`h2.${from}`).click();
  await builder.tab('CSS');
  await builder.write(builder.editor(`CSS di .${from}`), 'letter-spacing: 1px;');
  await builder.save();

  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Stili e selettori', exact: true }).click();
  await page.getByRole('searchbox', { name: 'Cerca un selettore' }).fill(from);
  await page.getByRole('button', { name: new RegExp(`^\\.${from}`) }).click();
  await page.getByLabel(new RegExp(`^Rinomina \\.${from}`)).fill(to);
  await page.locator('form.rename').getByRole('button', { name: 'Anteprima', exact: true }).click();
  const free = page.getByRole('group', { name: 'HTML libero: scegli le occorrenze da rinominare' });
  await expect(free.getByRole('checkbox')).toHaveCount(2);
  await expect(free).toContainText(`class="${from} libero"`);
  await expect(free).toContainText(`class="${to} libero"`);
  await free.getByRole('checkbox').first().check();
  page.once('dialog', (dialog) => void dialog.accept());
  await page.getByRole('button', { name: 'Rinomina ovunque' }).click();
  await expect(page.getByRole('status').filter({ hasText: `Rinominata .${from} → .${to}` })).toBeVisible();

  await builder.tab('CSS');
  await expect(builder.canvas.locator(`h2.${to}`)).toHaveCSS('letter-spacing', '1px');
  await expect(builder.canvas.locator(`div.${to}.libero`)).toHaveText('uno');
  // Not chosen: left as it was.
  await expect(builder.canvas.locator(`div.${from}`)).toHaveText('due');
});
