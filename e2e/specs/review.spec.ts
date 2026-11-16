import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('revisione: dal link di anteprima un cliente commenta un elemento e approva; nel builder si vede, si mostra e si risolve', async ({ page, data, browser }) => {
  const builder = await Builder.open(page, data.pages.review.id);
  const { url } = await builder.api<{ url: string }>('GET', `preview-link?post=${data.pages.review.id}&context=0`);

  // The client: no account, only the signed link.
  const client = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    const visit = await client.newPage();
    await visit.goto(url);
    const bar = visit.getByRole('region', { name: 'Revisione' });
    await expect(bar).toBeVisible();
    await bar.getByRole('button', { name: 'Commenta' }).click();
    await visit.getByRole('heading', { name: 'Ciao e2e' }).click();
    const box = visit.getByRole('form', { name: 'Commento' });
    await box.getByLabel('Commento').fill('Titolo più grande, per favore.');
    await box.getByLabel('Il tuo nome').fill('Cliente Rossi');
    await box.getByRole('button', { name: 'Invia' }).click();
    await expect(bar.getByRole('status')).toHaveText('Commento inviato. Grazie!');
    visit.once('dialog', (d) => void d.accept('Cliente Rossi'));
    await bar.getByRole('button', { name: 'Approva' }).click();
    await expect(bar.getByRole('status')).toHaveText('Approvata da Cliente Rossi.');
    // Without the link, nothing.
    const anon = await client.request.post(new URL('/wp-json/xb/v1/review/comments', url).href, { data: { token: 'x.1.1.y', uid: 'abc', text: 'spam' } });
    expect(anon.status()).toBe(403);
  } finally {
    await client.close();
  }

  // The builder: the comment, on its element, and the approval.
  await page.reload();
  await builder.ready();
  const rail = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Commenti' });
  await expect(rail).toHaveAttribute('title', 'Commenti: 1 da fare');
  await rail.click();
  const panel = page.getByRole('dialog', { name: 'Commenti' });
  await expect(panel.getByRole('status')).toContainText('Approvata da Cliente Rossi');
  const item = panel.getByRole('listitem').filter({ hasText: 'Titolo più grande, per favore.' });
  await expect(item).toContainText('Cliente Rossi');
  await item.getByRole('button', { name: 'Risolto' }).click();
  await expect(panel.getByRole('listitem')).toHaveCount(0);
  await panel.getByRole('button', { name: /Risolti/ }).click();
  await panel.getByRole('listitem').getByRole('button', { name: "Mostra l'elemento" }).click();
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toContainText('Titolo H1');
});
