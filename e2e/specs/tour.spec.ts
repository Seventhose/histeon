import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The builder's first-run tour (lista della spesa 142): five steps once, then only when asked.
test('il primo avvio mostra la guida in cinque passi una volta sola, e la palette la riapre', async ({ page, data }) => {
  await page.addInitScript(() => {
    if (!sessionStorage.getItem('xb-tour-test')) {
      localStorage.removeItem('xb-tour-done');
      sessionStorage.setItem('xb-tour-test', '1');
    }
  });
  const builder = await Builder.open(page, data.pages.review.id);
  const tour = page.getByRole('dialog', { name: /La pagina|The page/ });
  await expect(tour).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('.xb-tour .count')).toHaveText(/1 (di|of) 5/);
  for (let i = 2; i <= 5; i++) {
    await page.locator('.xb-tour [data-next]').click();
    await expect(page.locator('.xb-tour .count')).toHaveText(new RegExp(`${i} (di|of) 5`));
  }
  await page.locator('.xb-tour [data-next]').click();
  await expect(page.locator('.xb-tour')).toHaveCount(0);
  expect(await page.evaluate(() => localStorage.getItem('xb-tour-done'))).toBe('1');

  // Not again on the next visit; the palette opens it on purpose.
  await page.reload();
  await expect(builder.canvas.locator('p.uno')).toBeVisible({ timeout: 30_000 });
  await page.waitForTimeout(1600);
  await expect(page.locator('.xb-tour')).toHaveCount(0);
  await page.keyboard.press('ControlOrMeta+k');
  await page.getByRole('combobox').last().fill('Guida');
  await page.getByRole('option', { name: /Guida in cinque passi|Five-step guide/ }).click();
  await expect(page.locator('.xb-tour .count')).toHaveText(/1 (di|of) 5/);
  await page.keyboard.press('Escape');
  await expect(page.locator('.xb-tour')).toHaveCount(0);
});
