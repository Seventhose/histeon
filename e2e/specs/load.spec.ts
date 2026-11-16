import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('apertura: un solo disegno della tela, chiesto dalla pagina prima che parta il builder (V6)', async ({ page, data }) => {
  const renders: number[] = [];
  const documents: number[] = [];
  const bundles: number[] = [];
  const t0 = Date.now();
  page.on('request', (r) => {
    if (/\/xb\/v1\/render(\?|$)/.test(r.url())) renders.push(Date.now() - t0);
    if (/\/xb\/v1\/(documents\/\d+|styles|components)(\?|$)/.test(r.url()) && r.method() === 'GET') documents.push(Date.now() - t0);
    if (/\/builder\.js(\?|$)/.test(r.url())) bundles.push(Date.now() - t0);
  });
  const builder = await Builder.open(page, data.pages.palette.id);
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).toBeVisible();
  await page.waitForTimeout(800);
  expect(renders, 'the content watcher does not render the same content again').toHaveLength(1);
  // The document, styles and components come with the page; the drawing starts before the bundle has run.
  expect(documents, 'no request for what the page already holds').toEqual([]);
  expect(renders[0]!).toBeLessThanOrEqual(bundles[0]! + 50);

  // Typing: one render after the pause, not one per keystroke.
  await builder.tab('HTML');
  await builder.edit(builder.html, (code) => code.replace('Primo paragrafo', 'Primo paragrafo scritto'));
  await expect(builder.canvas.getByText('Primo paragrafo scritto')).toBeVisible();
  await page.waitForTimeout(400);
  expect(renders.length).toBeLessThanOrEqual(3);
});
