import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The selection is drawn in two tones, white around indigo, so it shows on any section (lista migliorie A3).
test('la selezione ha un contorno a due toni, sopra l’elemento, che si vede su ogni sfondo', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('h1').click();
  const frame = builder.canvas.locator('xb-overlay xb-frame');
  await expect(frame).toBeVisible();
  await expect(frame).toHaveCSS('border-top-color', 'rgb(63, 75, 196)');
  await expect(frame).toHaveCSS('box-shadow', /rgb\(255, 255, 255\) 0px 0px 0px 1px.*inset/);
  const heading = await builder.canvas.locator('h1').boundingBox();
  const box = await frame.boundingBox();
  expect(Math.abs((box?.x ?? 0) - (heading?.x ?? 0))).toBeLessThan(1);
  expect(Math.abs((box?.width ?? 0) - (heading?.width ?? 0))).toBeLessThan(1);

  await page.keyboard.press('Escape');
  await expect(frame).toBeHidden();
});
