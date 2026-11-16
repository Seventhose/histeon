import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('tela: maniglie delle spaziature agganciate alla scala e spostamento trascinando', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<section class="box${id}"><h2 class="uno${id}">Uno</h2><p class="due${id}">Due</p></section>`);
  await builder.node(`section.box${id}`).click();
  const frame = page.frameLocator('iframe[title="Anteprima della pagina"]');
  const handle = frame.locator('xb-handle[data-side="padding-top"]');
  await expect(handle).toBeVisible();
  // The canvas may still be drawing what was just written: take the handle's place once it stays put.
  let last = '';
  await expect
    .poll(async () => {
      const b = JSON.stringify(await handle.boundingBox());
      const settled = b === last;
      last = b;
      return settled;
    }, { intervals: [250] })
    .toBe(true);

  // Drag the top padding down: it snaps to the framework's spacing scale and lands in the class's CSS.
  const box = (await handle.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + 40, { steps: 6 });
  await expect(frame.locator('xb-readout')).toContainText('padding-top');
  await page.mouse.up();
  await builder.tab('CSS');
  await expect(page.getByRole('textbox', { name: `CSS di .box${id}` })).toContainText(/padding-top: var\(--[a-z0-9-]+\);/);
  // The status says what was written.
  await expect(builder.status).toContainText(`.box${id} { padding-top: var(--`);

  // The grip moves the paragraph before the heading.
  await builder.node(`p.due${id}`).click();
  const grip = frame.locator('xb-grip');
  await expect(grip).toContainText('Paragrafo');
  const g = (await grip.boundingBox())!;
  const target = (await frame.locator(`h2.uno${id}`).boundingBox())!;
  await page.mouse.move(g.x + 10, g.y + g.height / 2);
  await page.mouse.down();
  await page.mouse.move(target.x + 20, target.y + 2, { steps: 8 });
  await expect(frame.locator('xb-marker')).toBeVisible();
  await page.mouse.up();
  await expect(builder.canvas.locator(`section.box${id} > *`)).toHaveText(['Due', 'Uno']);
  await expect(builder.html).toContainText(`<p class="due${id}">Due</p>`);
});

test('tela: Esc durante il trascinamento di una spaziatura non scrive nulla', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<section class="esc${id}"><p>Uno</p></section>`);
  await builder.node(`section.esc${id}`).click();
  const frame = page.frameLocator('iframe[title="Anteprima della pagina"]');
  const handle = frame.locator('xb-handle[data-side="padding-top"]');
  await expect(handle).toBeVisible();
  let last = '';
  await expect
    .poll(async () => {
      const b = JSON.stringify(await handle.boundingBox());
      const settled = b === last;
      last = b;
      return settled;
    }, { intervals: [250] })
    .toBe(true);
  const box = (await handle.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + 40, { steps: 6 });
  await expect(frame.locator('xb-readout')).toContainText('padding-top');
  await page.keyboard.press('Escape');
  await expect(frame.locator('xb-readout')).toBeHidden();
  // The button is still down and then goes up: nothing follows it, nothing is written, the element stays selected.
  await page.mouse.move(box.x + box.width / 2, box.y + 80, { steps: 4 });
  await page.mouse.up();
  await expect(builder.canvas.locator(`section.esc${id}`)).toHaveCSS('padding-top', '0px');
  await expect(handle).toBeVisible();
  await builder.tab('CSS');
  await expect(page.getByRole('textbox', { name: `CSS di .esc${id}` })).not.toContainText('padding-top');
});
