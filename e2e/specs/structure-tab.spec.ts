import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The structure tree is one Tab stop (lista della spesa 144): the selected row, or the first; the arrows move.
test('struttura: una sola fermata del Tab, sulla riga selezionata, e le frecce per le altre', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.review.id);
  const tree = page.getByRole('tree', { name: /Elementi della pagina|Page elements/ });
  await expect(tree).toBeVisible();
  const stops = tree.locator('button.name[tabindex="0"]');
  await expect(stops).toHaveCount(1);
  await builder.canvas.locator('p.due').click();
  await expect(stops).toHaveCount(1);
  await expect(stops).toHaveText(/due|p/);
  await stops.focus();
  await page.keyboard.press('ArrowUp');
  await expect(tree.locator('button.name:focus')).not.toHaveText(/due/);
  // Every other row is still reachable by the arrows, not by Tab.
  expect(await tree.locator('button.name[tabindex="-1"]').count()).toBeGreaterThan(0);
});
