import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('foglio → selettori, tutti i blocchi in una volta: anteprima, cascata intatta, un solo Ctrl+Z', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  const a = `bulka${id}`;
  const b = `bulkb${id}`;
  const gone = `bulkz${id}`; // in the sheet, on no page
  await builder.tab('HTML');
  await builder.write(builder.html, `<div class="${a}"><h3 class="${a}__t">Titolo</h3><p class="${a}--k">K</p></div><div class="${b}">B</div>`);
  await page.keyboard.press('Escape');
  await builder.tab('CSS');
  const global = builder.editor('CSS globale');
  const before = await builder.source(global);
  await builder.write(
    global,
    `${before}
.${a} { padding: 7px; }
.${a}__t { color: rgb(2, 3, 4); }
.${a}--k { gap: 1px; display: flex; }
.${b} { margin-top: 3px; }
@media (width > 1px) { .${a}__t { letter-spacing: 1px; } }
.${a}, .${b} { border-top: 2px solid rgb(9, 9, 9); }
.${a}--k, .${b} { column-gap: 2px; }
.${gone} { outline-width: 1px; }
`,
  );
  const title = builder.canvas.locator(`h3.${a}__t`);
  await expect(title).toHaveCSS('letter-spacing', '1px');

  // The preview: blocks and rules that move, the rule kept for the cascade (and why), the unused block.
  await page.locator('.sheet-bar').getByRole('button', { name: 'Nei selettori…' }).click();
  const bulk = page.getByRole('region', { name: 'Porta i blocchi nei selettori' });
  await expect(bulk.getByRole('list', { name: 'Blocchi da spostare' })).toContainText(`.${a} 3 regole`);
  await expect(bulk.getByRole('list', { name: 'Blocchi da spostare' })).toContainText(`.${b} 1 regola`);
  const kept = bulk.getByRole('list', { name: 'Regole che restano nel foglio' });
  await kept.getByRole('button', { name: /per la cascata|regol[ae] rest/ }).first().click();
  await expect(kept).toContainText(`.${a}--k → dopo .${a}--k, .${b} (gap / column-gap)`);
  await expect(kept).toContainText(`.${gone}`);
  await bulk.getByRole('button', { name: /^Sposta \d+ regol/ }).click();
  await expect(bulk.getByRole('status')).toContainText('Ctrl+Z annulla');

  // The page is the same; the sheet keeps only the shared, the held-back and the unused rules.
  await expect(title).toHaveCSS('color', 'rgb(2, 3, 4)');
  await expect(title).toHaveCSS('letter-spacing', '1px');
  await expect(builder.canvas.locator(`div.${a}`)).toHaveCSS('padding-top', '7px');
  await expect(builder.canvas.locator(`div.${a}`)).toHaveCSS('border-top-width', '2px');
  await expect(builder.canvas.locator(`div.${b}`)).toHaveCSS('margin-top', '3px');
  await expect(builder.canvas.locator(`p.${a}--k`)).toHaveCSS('column-gap', '2px');
  const sheet = await builder.source(global);
  expect(sheet).not.toContain(`.${a}__t {`);
  expect(sheet).not.toContain(`.${b} { margin-top`);
  expect(sheet).toContain(`.${a}--k { gap: 1px;`);
  expect(sheet).toContain(`.${a}, .${b} {`);
  expect(sheet).toContain(`.${gone} {`);

  // Each block's selector has its rules, nested as SCSS.
  await builder.node(`div.${a}`).click();
  const own = builder.editor(`CSS di .${a}`);
  await expect(own).toContainText('padding: 7px;');
  await expect(own).toContainText('&__t {');
  await expect(own).toContainText('@media (width > 1px) {');

  // One undo brings the sheet back as it was.
  await page.getByRole('tab', { name: 'Foglio globale' }).click();
  await page.keyboard.press('Control+z');
  await expect(builder.editor('CSS globale')).toContainText(`.${a}__t { color: rgb(2, 3, 4); }`);
  await expect(builder.editor('CSS globale')).toContainText(`.${b} { margin-top: 3px; }`);

  // The selector manager offers the same move, for every sheet.
  await builder.tab('Selettori');
  const manager = page.locator('.selectors');
  await manager.getByRole('button', { name: 'Nei selettori…' }).click();
  await expect(manager.getByRole('region', { name: 'Porta i blocchi nei selettori' }).getByRole('list', { name: 'Blocchi da spostare' })).toContainText(`.${a} 3 regole`);
});
