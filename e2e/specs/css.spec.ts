import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('il pannello CSS scrive sulla classe selezionata, la tela si aggiorna e il salvataggio la conserva', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.css.id);
  const hero = builder.canvas.locator('section.e2e-hero');
  // Styles belong to the selector, site-wide: a value of this run, so there is always something to save.
  const blue = 10 + Math.floor(Math.random() * 240);
  const color = `rgb(1, 2, ${blue})`;

  await builder.node('section.e2e-hero').click();
  await builder.tab('CSS');
  await expect(page.getByRole('tab', { name: '.e2e-hero' })).toHaveAttribute('aria-selected', 'true');
  const css = builder.editor('CSS di .e2e-hero');
  await builder.write(css, `background-color: ${color}; padding: 7px; &__x { color: red }`);

  // Compiled in the browser: no round trip to the server.
  await expect(hero).toHaveCSS('background-color', color);
  await expect(hero).toHaveCSS('padding-top', '7px');
  await builder.save();

  await page.reload();
  await builder.ready();
  await expect(hero).toHaveCSS('background-color', color);
  await builder.node('section.e2e-hero').click();
  await builder.tab('CSS');
  await expect(builder.editor('CSS di .e2e-hero')).toContainText(`background-color: ${color}`);
});

test('il foglio globale vale per tutta la pagina', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.css.id);
  await builder.tab('CSS');
  // Nothing selected: the global sheet.
  await expect(page.getByRole('tab', { name: 'Foglio globale' })).toHaveAttribute('aria-selected', 'true');
  const global = builder.editor('CSS globale');
  const before = await builder.source(global);
  await builder.write(global, `${before}\n.e2e-hero h1 { letter-spacing: 3px; }`);
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).toHaveCSS('letter-spacing', '3px');
  // Undo restores the previous sheet (outside the code editor, Ctrl+Z is the builder's undo).
  await builder.node('h1').click();
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).not.toHaveCSS('letter-spacing', '3px');
});
