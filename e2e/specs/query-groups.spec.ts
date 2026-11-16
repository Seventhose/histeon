import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Doc 23 of Histeon Publish, item 205: the loop in groups of item 187 (source.groupBy) is chosen in the builder's Query
// panel too; the panel's preview says how many groups there are, and the loop put in the page is the same one Gutenberg
// and the code write.
test('pannello Query: In gruppi, l’anteprima conta i gruppi e il loop nuovo è a gruppi', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('Query');
  const name = `gruppi_${Date.now().toString(36)}`;
  await page.getByLabel('Nome della nuova query').fill(name);
  await page.getByRole('button', { name: 'Nuova query' }).click();
  const query = page.getByRole('region', { name: `Query ${name}` });
  await expect(query).toBeVisible();
  const cards = page.getByRole('list', { name: 'Risultati' }).getByRole('listitem');
  await expect(cards).toHaveCount(3);

  // Not grouped: no groups in the preview.
  const groups = page.getByLabel('Gruppi del loop');
  await expect(groups).toHaveCount(0);

  // By first letter of the title: the run's three posts are all «Articolo e2e …», one group «A» with three.
  const grouping = query.getByLabel('Raggruppa il risultato nel loop');
  await grouping.selectOption({ label: 'Per iniziale del titolo' });
  await expect(groups).toContainText('1 gruppo');
  await expect(groups.getByRole('listitem')).toHaveText(['A 3 contenuti']);
  await expect(query).toContainText('{gruppo.key}');
  // The records stay the query's records.
  await expect(cards).toHaveCount(3);

  // By year: the posts of this run are of this year.
  const year = String(new Date().getFullYear());
  await grouping.selectOption({ label: 'Per anno di pubblicazione' });
  await expect(groups.getByRole('listitem').first()).toContainText(year);

  // The server counts the groups of the whole result with the same groupBy the loop writes.
  const preset = { type: 'posts', args: { post_type: 'post', posts_per_page: 3, orderby: 'date', order: 'DESC' } };
  const answer = await builder.api<{ items: unknown[]; groups: Array<{ key: string; count: number }> }>('POST', 'queries/test', { id: name, preset, params: {}, groupBy: { field: 'date', how: 'Y' } });
  expect(answer.groups).toEqual([{ key: year, count: 3 }]);
  expect(await builder.apiStatus('POST', 'queries/test', { id: name, preset, groupBy: { field: '../x' } })).toBe(400);

  await page.getByRole('button', { name: /^Salva query/ }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Query e dati salvati in sviluppo' })).toBeVisible();

  // The new loop is in groups: a heading per group, the posts inside.
  await query.getByRole('button', { name: 'Inserisci un loop nuovo' }).click();
  await builder.tab('HTML');
  await expect(builder.html).toContainText(`{#loop ${name}(count: 3).groupBy("date", "Y") as gruppo}`);
  await expect(builder.html).toContainText('{#loop gruppo.items as item}');
  await expect(builder.canvas.getByRole('heading', { level: 2, name: year })).toBeVisible();
  await expect(builder.canvas.getByRole('heading', { name: data.posts.post2.title })).toBeVisible();

  // Leave the site's queries as they were for the other specs.
  const saved = await builder.api<{ presets: Record<string, unknown>; head: string; base: string }>('GET', 'queries');
  delete saved.presets[name];
  await builder.api('POST', 'queries', { presets: saved.presets, head: saved.head, base: saved.base });
});
