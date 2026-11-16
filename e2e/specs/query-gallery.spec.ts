import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Lista della spesa 165: ready queries from a gallery. Each one is accepted by the server as written, and the latest
// posts show the run's posts with the data preview.
test('galleria di query: ultimi articoli, correlati ed eventi futuri si aggiungono con un clic e il server li accetta', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('Query');
  const gallery = page.getByLabel('Galleria di query pronte');
  for (const [label, base] of [['Ultimi articoli', 'ultimiArticoli'], ['Articoli correlati', 'articoliCorrelati'], ['Eventi futuri', 'eventiFuturi']] as const) {
    await gallery.selectOption({ label });
    await expect(page.getByRole('status').filter({ hasText: new RegExp(`Query «${base}\\d*» aggiunta dalla galleria`) })).toBeVisible();
  }
  // WooCommerce is not on the test site: its query is not offered.
  await expect(gallery.locator('option', { hasText: 'Prodotti in evidenza' })).toHaveCount(0);

  await page.getByRole('button', { name: /^Salva query/ }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Query e dati salvati in sviluppo' })).toBeVisible();

  const saved = await builder.api<{ presets: Record<string, { args: Record<string, unknown> }> }>('GET', 'queries');
  const names = Object.keys(saved.presets).filter((n) => /^(ultimiArticoli|articoliCorrelati|eventiFuturi)\d*$/.test(n));
  expect(names.length).toBeGreaterThanOrEqual(3);
  const latest = names.filter((n) => n.startsWith('ultimiArticoli')).at(-1)!;
  const result = await builder.api<{ items: Array<{ title: string }> }>('POST', 'queries/test', { id: latest, preset: { type: 'posts', args: saved.presets[latest]!.args }, params: { count: 3 } });
  expect(result.items.map((i) => i.title)).toContain(data.posts.post2.title);

  // Leave the site's queries as they were for the other specs.
  for (const name of names) delete saved.presets[name];
  const fresh = await builder.api<{ head: string; base: string }>('GET', 'queries');
  await builder.api('POST', 'queries', { presets: saved.presets, head: fresh.head, base: fresh.base });
});
