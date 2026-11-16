import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('HTML: abbreviazioni Emmet con Tab e Ctrl+clic da una classe al suo CSS', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, '');
  await builder.html.click();
  await page.keyboard.type(`section.em${id}>h2.em${id}__title{Ciao}+p.em${id}__text`);
  await page.keyboard.press('Tab');
  await expect(builder.html).toContainText(`<section class="em${id}">`);
  await expect(builder.html).toContainText(`<h2 class="em${id}__title">Ciao</h2>`);
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao' })).toBeVisible();
  // The cursor is inside the first empty element: typing fills it.
  await page.keyboard.type('Testo');
  await expect(builder.canvas.getByText('Testo', { exact: true })).toBeVisible();

  // A plain word stays a word: Tab indents, nothing is invented.
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.type('ciao');
  await page.keyboard.press('Tab');
  await expect(builder.html).not.toContainText('<ciao>');
  await page.keyboard.press('ControlOrMeta+z');
  await page.keyboard.press('ControlOrMeta+z');

  // Ctrl+click on a class: its element is selected and the CSS panel shows that selector.
  await builder.tab('CSS');
  await builder.html.getByText(`em${id}__title`).click({ modifiers: ['ControlOrMeta'] });
  await expect(page.getByRole('tab', { name: `.em${id}__title` })).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('textbox', { name: `CSS di .em${id}__title` })).toBeVisible();
});
