import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

// A page of its own: the rename reaches every document of the site.
let created = 0;
test.afterEach(() => {
  if (created) wpEval(`<?php wp_delete_post( ${created}, true );`, {});
  created = 0;
});

// With unsaved changes, the selector manager no longer leaves you stuck: one click saves and renames (C2).
test('con modifiche non salvate, «Salva ora e rinomina» salva e poi rinomina', async ({ page }) => {
  const id = Date.now().toString(36);
  const from = `c2x${id}`;
  const to = `c2y${id}`;
  const content = Buffer.from(el('div', { class: from }, text('Da rinominare'))).toString('base64');
  created = Number(wpEval(`<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'C2', 'post_content' => wp_slash( base64_decode( '${content}' ) ) ) );`, {}).trim().split('\n').pop());
  const builder = await Builder.open(page, created);

  await builder.node(`div.${from}`).click();
  await builder.tab('CSS');
  await builder.write(builder.editor(`CSS di .${from}`), 'color: rgb(1, 2, 3);');
  await builder.save();
  // Something unsaved again.
  await builder.edit(builder.html, (code) => code.replace('Da rinominare', 'Da rinominare, cambiato'));
  await expect(builder.status).toHaveText('Modifiche non salvate');

  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Stili e selettori', exact: true }).click();
  await expect(page.getByText("Salva le modifiche per rinominare o eliminare: l'elenco riflette gli stili salvati.")).toBeVisible();
  await page.getByRole('searchbox', { name: 'Cerca un selettore' }).fill(from);
  await page.getByRole('button', { name: new RegExp(`^\\.${from}`) }).click();
  await page.getByLabel(new RegExp(`^Rinomina \\.${from}`)).fill(to);
  await page.locator('form.rename').getByRole('button', { name: 'Anteprima', exact: true }).click();
  const saveAndRename = page.getByRole('button', { name: 'Salva ora e rinomina' });
  await expect(saveAndRename).toBeEnabled();
  page.once('dialog', (dialog) => void dialog.accept());
  await saveAndRename.click();
  await expect(page.getByRole('status').filter({ hasText: `Rinominata .${from} → .${to}` })).toBeVisible();
  await expect(builder.saveButton).toBeDisabled();

  await builder.tab('CSS');
  await expect(builder.canvas.locator(`div.${to}`)).toHaveCSS('color', 'rgb(1, 2, 3)');
  await expect(builder.canvas.locator(`div.${to}`)).toHaveText('Da rinominare, cambiato');
});
