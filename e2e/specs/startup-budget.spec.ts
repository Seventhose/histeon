import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Startup budget (lista della spesa 150): opening the builder and the block editor with Histeon is measured on every
// run, and a run that gets clearly slower, or loads clearly more script at startup, fails. Each time is the median
// of three opens; the limits leave room for a busy machine and still catch a real step back (docs/spec/performance.md).
const BUDGET = {
  builderMs: Number(process.env.XB_BUDGET_BUILDER_MS ?? 1500),
  gutenbergMs: Number(process.env.XB_BUDGET_GUTENBERG_MS ?? 4000),
  /** Script bytes (as sent, compressed or not) the builder loads before its canvas is drawn. */
  builderScriptBytes: 1_250_000,
};

const median = (values: number[]) => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)]!;

test('budget di avvio: builder e Gutenberg con Histeon si aprono entro le soglie', async ({ page, data }) => {
  test.setTimeout(180_000);
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const builderTimes: number[] = [];
  let scriptBytes = 0;
  for (let i = 0; i < 3; i++) {
    const sizes: Promise<number>[] = [];
    const listener = (response: import('@playwright/test').Response) => {
      if (response.request().resourceType() === 'script' && response.url().includes('/plugins/xb/')) {
        sizes.push(response.body().then((b) => b.length, () => 0));
      }
    };
    page.on('response', listener);
    const t0 = Date.now();
    const builder = await Builder.open(page, data.pages.palette.id);
    await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).toBeVisible();
    builderTimes.push(Date.now() - t0);
    page.off('response', listener);
    if (i === 0) scriptBytes = (await Promise.all(sizes)).reduce((a, b) => a + b, 0);
  }

  const gutenbergTimes: number[] = [];
  for (let i = 0; i < 3; i++) {
    const t0 = Date.now();
    await page.goto(`/wp-admin/post.php?post=${data.pages.aspetto.id}&action=edit`);
    await expect(page.frameLocator('iframe[name="editor-canvas"]').locator('p.uno')).toBeVisible({ timeout: 30_000 });
    gutenbergTimes.push(Date.now() - t0);
  }

  const report = { builder: builderTimes, gutenberg: gutenbergTimes, scriptBytes };
  console.log(`[budget di avvio] ${JSON.stringify(report)}`);
  test.info().annotations.push({ type: 'budget', description: JSON.stringify(report) });
  expect(median(builderTimes), `builder: ${builderTimes.join(', ')} ms`).toBeLessThanOrEqual(BUDGET.builderMs);
  expect(median(gutenbergTimes), `Gutenberg: ${gutenbergTimes.join(', ')} ms`).toBeLessThanOrEqual(BUDGET.gutenbergMs);
  expect(scriptBytes, 'script del builder all\'avvio').toBeLessThanOrEqual(BUDGET.builderScriptBytes);
});
