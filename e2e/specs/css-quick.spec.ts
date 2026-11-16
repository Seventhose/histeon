import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('azioni rapide CSS e media query con nome', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.css.id);
  const hero = builder.canvas.locator('section.e2e-hero');
  await builder.node('section.e2e-hero').click();
  await builder.tab('CSS');
  const css = builder.editor('CSS di .e2e-hero');
  await builder.write(css, 'padding: 24px;');

  // Layout and spacing from the buttons (under «Altro»), written into the class as if typed.
  await page.getByRole('button', { name: /^Altro/ }).click();
  await page.getByRole('group', { name: 'Layout' }).getByRole('button', { name: 'Griglia 3' }).click();
  await expect(css).toContainText('grid-template-columns: repeat(3, minmax(0, 1fr));');
  await expect(hero).toHaveCSS('display', 'grid');
  await page.getByRole('button', { name: /^Altro/ }).click();
  await page.getByRole('button', { name: 'px → rem' }).click();
  await expect(css).toContainText('padding: 1.5rem;');

  // A named query, resolved in the canvas like on the site: (--stretta) is any width up to 10000px here.
  const name = `stretta${Date.now().toString(36)}`;
  await builder.tab('Selettori');
  await page.getByRole('button', { name: /Media query con nome/ }).click();
  await page.getByRole('button', { name: '+ Media query' }).click();
  const last = page.getByLabel(/^Nome della media query/).last();
  await last.fill(name);
  await page.getByLabel(`Condizione della media query ${name}`).fill('(width < 10000px)');
  await page.getByRole('button', { name: 'Salva le media query' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Salvate in sviluppo' })).toBeVisible();
  await builder.tab('CSS');
  await builder.node('section.e2e-hero').click();
  await builder.write(builder.editor('CSS di .e2e-hero'), `padding: 1.5rem; @media (--${name}) { border-top: 3px solid rgb(9, 9, 9); }`);
  await expect(hero).toHaveCSS('border-top-width', '3px');
});
