import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('il costruttore di query crea una query, mostra i dati e il loop si inserisce', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('Query');
  const name = `e2e_${Date.now().toString(36)}`;

  await page.getByLabel('Nome della nuova query').fill(name);
  await page.getByRole('button', { name: 'Nuova query' }).click();
  const query = page.getByRole('region', { name: `Query ${name}` });
  await expect(query).toBeVisible();

  // Visual view first: the blank query lists posts, newest first, the loop's count deciding how many.
  await expect(query.getByLabel('Tipo di contenuto')).toHaveValue('post');
  await expect(query.getByRole('button', { name: 'Decrescente' })).toHaveClass(/active/);

  // The data preview follows by itself, with count = 3: the three newest posts are this run's.
  const cards = page.getByRole('list', { name: 'Risultati' }).getByRole('listitem');
  await expect(cards).toHaveCount(3);
  for (const post of Object.values(data.posts)) await expect(cards.filter({ hasText: post.title })).toHaveCount(1);
  await expect(page.getByText('{item.title}', { exact: true })).toBeVisible();

  // The code view holds the same arguments; forbidden ones are refused before reaching the server.
  await query.getByRole('tab', { name: 'Codice' }).click();
  const args = builder.editor(`Parametri della query ${name}`);
  await expect(args).toContainText('"post_type": "post"');
  const valid = await builder.source(args);
  await builder.write(args, '{ "post_status": "draft" }');
  await expect(query.locator('.problem')).toContainText('"post_status" non si può usare');
  await builder.write(args, valid);
  await expect(query.locator('.problem')).toHaveCount(0);

  await page.getByRole('button', { name: /^Salva query/ }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Query e dati salvati in sviluppo' })).toBeVisible();

  await query.getByRole('button', { name: 'Inserisci un loop nuovo' }).click();
  await builder.tab('HTML');
  await expect(builder.html).toContainText(`{#loop ${name}(count: 3) as item}`);
  await expect(builder.canvas.getByRole('heading', { name: data.posts.post2.title })).toBeVisible();
});
