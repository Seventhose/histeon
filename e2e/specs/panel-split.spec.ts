import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

const width = (page: import('@playwright/test').Page, id: string) => page.locator(`.area-bottom > .slot[data-id="${id}"]`).evaluate((el) => el.getBoundingClientRect().width);

// Panels of one area share it as the user wants, and a maximized panel stays maximized (lista migliorie L4);
// resetting the layout can be undone for a few seconds (L5).
test('separatore fra pannelli, massimizzato che resta, ripristino che si annulla', async ({ page, data }) => {
  await Builder.open(page, data.pages.palette.id);
  const split = page.getByRole('separator', { name: 'Spazio fra HTML e CSS' });
  const before = await width(page, 'html');
  await split.focus();
  for (let i = 0; i < 5; i++) await page.keyboard.press('ArrowRight');
  await expect.poll(() => width(page, 'html')).toBeGreaterThan(before + 60);
  await expect(split).toHaveAttribute('aria-valuenow', /^\d+$/);
  const wider = await width(page, 'html');

  // By pointer too.
  const box = (await split.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x - 40, box.y + box.height / 2, { steps: 4 });
  await page.mouse.up();
  await expect.poll(() => width(page, 'html')).toBeLessThan(wider - 30);
  const kept = await width(page, 'html');

  await page.getByRole('button', { name: 'Allarga CSS' }).click();
  await expect(page.locator('.area-bottom > .slot[data-id="html"]')).toBeHidden();
  await page.reload();
  await expect(page.locator('.area-bottom > .slot[data-id="html"]')).toBeHidden();
  await page.getByRole('button', { name: 'Riduci CSS' }).click();
  await expect.poll(() => width(page, 'html')).toBeGreaterThan(kept - 3);
  expect(await width(page, 'html')).toBeLessThan(kept + 3);

  await page.getByRole('button', { name: 'Sposta HTML', exact: true }).click();
  await page.getByRole('menuitem', { name: 'Ripristina la disposizione' }).click();
  const toast = page.getByRole('status').filter({ hasText: 'Disposizione dei pannelli ripristinata.' });
  await expect(toast).toBeVisible();
  await toast.getByRole('button', { name: 'Annulla' }).click();
  await expect(toast).toHaveCount(0);
  await expect.poll(() => width(page, 'html')).toBeGreaterThan(kept - 3);
});
