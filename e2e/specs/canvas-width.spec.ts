import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('anteprima a qualsiasi larghezza e media query a partire da quella larghezza', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await expect(page.getByRole('button', { name: 'Tablet', exact: true })).toHaveCount(0);
  const frame = page.locator('iframe[title="Anteprima della pagina"]');
  const width = page.getByLabel("Larghezza dell'anteprima in pixel");

  await width.fill('720');
  await width.press('Enter');
  await expect(frame).toHaveCSS('width', '720px');
  const handle = page.getByRole('slider', { name: "Larghezza dell'anteprima", exact: true });
  await handle.focus();
  await page.keyboard.press('ArrowRight');
  await expect(handle).toHaveAttribute('aria-valuenow', '730');
  await page.keyboard.press('Shift+ArrowLeft');
  await expect(handle).toHaveAttribute('aria-valuenow', '630');
  // Dragging stops at the edge of the stage: no shrinking by accident.
  const box = await handle.boundingBox();
  if (!box) throw new Error('maniglia non visibile');
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 3000, box.y + box.height / 2, { steps: 5 });
  await page.mouse.up();
  await expect(page.getByText(/ridotta al/)).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Adatta' })).toBeDisabled();
  // A wider width typed on purpose is shown scaled, and says so.
  await width.fill('3000');
  await width.press('Enter');
  await expect(page.getByText(/ridotta al \d+%/)).toBeVisible();
  await width.fill('720');
  await width.press('Enter');

  // A media query from this width: it applies in the canvas at 720px and not at 1024px.
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<p class="largo${id}">Larghezza</p>`);
  await builder.node(`p.largo${id}`).click();
  await builder.tab('CSS');
  await page.getByRole('button', { name: /^Altro/ }).click();
  await page.getByRole('button', { name: '@media fino a qui' }).click();
  const css = page.getByRole('textbox', { name: `CSS di .largo${id}` });
  await expect(css).toContainText('@media (width <= 720px) {');
  await builder.write(css, `@media (width <= 720px) {\n  color: rgb(1, 2, 3);\n}`);
  const p = builder.canvas.locator(`p.largo${id}`);
  await expect(p).toHaveCSS('color', 'rgb(1, 2, 3)');

  await page.getByRole('group', { name: 'Punti di rottura del sito' }).getByRole('button', { name: /tablet/ }).click();
  await expect(frame).toHaveCSS('width', '1024px');
  await expect(p).not.toHaveCSS('color', 'rgb(1, 2, 3)');

  await page.getByRole('button', { name: 'Adatta' }).click();
  await expect(page.getByRole('button', { name: 'Adatta' })).toBeDisabled();
});
