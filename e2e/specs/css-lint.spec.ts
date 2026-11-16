import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The CSS is checked while it is written, and the Checks panel lists the same problems (lista migliorie C1).
test('il CSS di un selettore si controlla mentre si scrive, e i Controlli mostrano gli stessi problemi', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.due').click();
  await builder.tab('CSS');
  const editor = builder.editor('CSS di .due');
  await builder.write(editor, 'color: var(--inesistente) !important;\n@media (--nessuna) { color: red; }');
  await expect(editor.locator('.cm-lintRange-warning')).toHaveCount(3);

  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Controlli', exact: true }).click();
  await page.getByRole('button', { name: /^CSS/ }).click();
  const list = page.locator('.checks li');
  await expect(list.filter({ hasText: '.due: Variabile sconosciuta: --inesistente' })).toHaveCount(1);
  await expect(list.filter({ hasText: '.due: !important' })).toHaveCount(1);
  await expect(list.filter({ hasText: '.due: Media query con nome sconosciuta: --nessuna' })).toHaveCount(1);

  // Fixed: nothing left.
  await builder.tab('CSS');
  await builder.write(editor, 'color: red;');
  await expect(editor.locator('.cm-lintRange-warning, .cm-lintRange-error')).toHaveCount(0);
});
