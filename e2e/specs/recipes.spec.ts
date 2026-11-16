import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('pannello Ricette: si prova sulla tela passando sopra, si applica alla classe con un clic, anche il JavaScript', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.css.id);
  const hero = builder.canvas.locator('section.e2e-hero');
  await builder.node('section.e2e-hero').click();
  const display = await hero.evaluate((el) => getComputedStyle(el).display);

  // The panel starts collapsed on the right: it opens from its header.
  const panel = page.locator('[data-panel="recipes"]');
  const expand = panel.getByRole('button', { name: 'Espandi Ricette' });
  if (await expand.isVisible()) await expand.click();
  await panel.getByRole('button', { name: 'Allarga Ricette' }).click();
  await expect(panel.getByText('Si applica a')).toContainText('.e2e-hero');

  // Pointing at a recipe tries it: the sample in the panel and the element in the canvas, nothing written yet.
  const recipe = panel.getByRole('button', { name: 'Applica la ricetta @griglia-3', exact: true });
  await recipe.hover();
  await expect(panel.frameLocator('iframe[title="Anteprima della ricetta"]').locator('.r')).toHaveCSS('display', 'grid');
  await expect(hero).toHaveCSS('display', 'grid');
  await panel.getByRole('heading', { name: 'Disposizione' }).hover();
  await page.mouse.move(5, 5);
  await expect(hero).toHaveCSS('display', display);

  // An alternative name finds the Histeon recipe.
  await panel.getByLabel('Cerca una ricetta').fill('auto-grid');
  await expect(panel.getByRole('button', { name: 'Applica la ricetta @griglia-auto', exact: true })).toBeVisible();
  await panel.getByLabel('Cerca una ricetta').fill('');

  await recipe.click();
  await expect(panel.getByRole('status')).toContainText('Ricetta @griglia-3 aggiunta a .e2e-hero');
  await panel.getByRole('button', { name: 'Riduci Ricette' }).click();
  await builder.tab('CSS');
  await expect(builder.editor('CSS di .e2e-hero')).toContainText('/* @griglia-3 */');
  await expect(hero).toHaveCSS('display', 'grid');

  // A JavaScript recipe goes into the document's module, for this class.
  await panel.getByRole('button', { name: 'Allarga Ricette' }).click();
  await panel.getByRole('tab', { name: 'JavaScript' }).click();
  await panel.getByRole('button', { name: 'Aggiungi il JavaScript «Anno corrente»' }).click();
  await expect(panel.getByRole('status')).toContainText('«Anno corrente» aggiunto');
  await builder.tab('JS');
  await expect(builder.editor('JavaScript del documento')).toContainText("document.querySelectorAll('.e2e-hero')");
});
