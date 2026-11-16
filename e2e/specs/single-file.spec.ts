import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('file unico: HTML, stili e script del documento in un file, applicati insieme', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<section class="uno${id}"><h2>Prima</h2></section>`);
  await builder.node(`section.uno${id}`).click();
  await builder.tab('CSS');
  await builder.write(page.getByRole('textbox', { name: `CSS di .uno${id}` }), 'color: rgb(1, 2, 3);');

  await page.getByRole('button', { name: 'File unico' }).click();
  const file = page.getByRole('textbox', { name: 'File unico del documento' });
  await expect(file).toContainText('<template>');
  await expect(file).toContainText(`<section class="uno${id}">`);
  await expect(file).toContainText(`.uno${id} {`);

  // A mistake says where it is, and nothing changes.
  const source = await builder.source(file);
  await builder.write(file, source.replace('</style>', '@media (x) {}\n</style>'));
  await page.getByRole('button', { name: 'Applica' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'solo regole' })).toContainText(/^Riga \d+/);

  // HTML and CSS changed together, applied together.
  await builder.write(file, source.replace('Prima', 'Dopo').replace('color: rgb(1, 2, 3);', 'color: rgb(4, 5, 6);'));
  await page.getByRole('button', { name: 'Applica' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Applicato' })).toBeVisible();
  await page.getByRole('button', { name: "Chiudi e torna all'anteprima" }).click();
  await expect(builder.canvas.getByRole('heading', { name: 'Dopo' })).toBeVisible();
  await expect(builder.canvas.locator(`section.uno${id}`)).toHaveCSS('color', 'rgb(4, 5, 6)');
});
