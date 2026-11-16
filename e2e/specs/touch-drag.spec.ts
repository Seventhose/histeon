import type { Page } from '@playwright/test';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// A tablet emulated by Playwright (touch events, mobile viewport): there is no real tablet on this machine, so this
// is what L3 is tested with (lista migliorie L3).
test.use({ hasTouch: true, isMobile: true, viewport: { width: 1366, height: 1024 } });

/** A finger from one point to another, with real touch events (Chromium's input pipeline, as on a device). */
async function swipe(page: Page, from: { x: number; y: number }, to: { x: number; y: number }, steps = 8): Promise<void> {
  const cdp = await page.context().newCDPSession(page);
  const touch = (type: 'touchStart' | 'touchMove' | 'touchEnd', x: number, y: number) =>
    cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x: Math.round(x), y: Math.round(y) }] });
  await touch('touchStart', from.x, from.y);
  for (let i = 1; i <= steps; i++) await touch('touchMove', from.x + ((to.x - from.x) * i) / steps, from.y + ((to.y - from.y) * i) / steps);
  await touch('touchEnd', to.x, to.y);
  await cdp.detach();
}
const centre = (box: { x: number; y: number; width: number; height: number } | null) => ({ x: box!.x + box!.width / 2, y: box!.y + box!.height / 2 });

test('al tocco: un elemento della Struttura si trascina per la sua icona', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const icon = builder.structure.locator('li[data-uid]', { has: page.getByRole('button', { name: 'p.due', exact: true }) }).locator('.icon').first();
  const h1 = builder.structure.locator('li[data-uid]', { has: page.getByRole('button', { name: 'h1', exact: true }) }).locator(':scope > .row');
  const target = await h1.boundingBox();
  // The top quarter of the heading's row: before it.
  await swipe(page, centre(await icon.boundingBox()), { x: target!.x + target!.width / 2, y: target!.y + 2 });
  await expect.poll(async () => (await builder.nodes()).filter((n) => n === 'p.due' || n === 'h1')).toEqual(['p.due', 'h1']);
  await expect(builder.html).toContainText('<p class="due">');
});

test('al tocco: un pannello si sposta in un’altra area per la sua maniglia', async ({ page, data }) => {
  await Builder.open(page, data.pages.palette.id);
  const grip = page.locator('[data-panel="css"] header .grip');
  const right = page.locator('.area-right');
  const box = await right.boundingBox();
  await swipe(page, centre(await grip.boundingBox()), { x: box!.x + box!.width / 2, y: box!.y + box!.height - 30 });
  await expect(page.locator('.area-right [data-panel="css"]')).toBeAttached();
  await expect(page.locator('.area-bottom [data-panel="css"]')).toHaveCount(0);
});
