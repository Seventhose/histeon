import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('controlli: accessibilità, SEO e prestazioni della pagina disegnata, con il clic che porta all’elemento', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(
    builder.html,
    `<section class="chk${id}"><h1>Uno</h1><h1>Due</h1><h4 class="salto${id}">Salto</h4><img class="foto${id}" src="/wp-includes/images/w-logo-blue.png"/><a class="vago${id}" href="/x">Clicca qui</a><p class="pallido${id}">Testo grigio</p><button class="vuoto${id}"></button><input class="campo${id}" type="text"/><a class="doppio${id}" href="/y" tabindex="-1" aria-hidden="true"><img src="/wp-includes/images/w-logo-blue.png" alt=""/></a></section>`,
  );
  await builder.node(`p.pallido${id}`).click();
  await builder.tab('CSS');
  await builder.write(page.getByRole('textbox', { name: `CSS di .pallido${id}` }), 'color: rgb(200, 200, 200);');

  const rail = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Controlli' });
  await expect(rail).toHaveAttribute('title', /errori/);
  await rail.click();
  const panel = page.getByRole('dialog', { name: 'Controlli' });
  await page.getByRole('button', { name: 'Ricontrolla' }).click();
  for (const text of ['Immagine senza testo alternativo', 'Un secondo H1', 'Da H1 a H4', 'Link «Clicca qui»', 'Contrasto', 'Pulsante senza testo', 'Campo senza etichetta']) {
    await expect(panel.getByText(text).first()).toBeVisible();
  }

  // The contrast comes with the nearest colour that passes, same hue, in OKLCH (lista della spesa 164).
  await expect(panel.getByText(/Contrasto .*Con la stessa tinta, più (scuro|chiaro): oklch\(/).first()).toBeVisible();

  // A duplicate link hidden on purpose (aria-hidden, as in cards) is not a problem.
  await expect(panel.getByText('Link senza testo')).toHaveCount(0);
  // "Mostra": the element is selected and the canvas is back in view.
  await panel.getByRole('listitem').filter({ hasText: 'Immagine senza testo alternativo' }).getByRole('button', { name: 'Mostra' }).click();
  await expect(panel).toHaveCount(0);
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toContainText(`foto${id}`);
  // Fixed in the Properties panel: the check follows.
  await page.getByRole('group', { name: 'Immagine' }).getByLabel('Testo alternativo').fill('Logo');
  await page.getByRole('group', { name: 'Immagine' }).getByLabel('Testo alternativo').press('Enter');
  await rail.click();
  await page.getByRole('button', { name: 'Ricontrolla' }).click();
  await expect(panel.getByText('Immagine senza testo alternativo')).toHaveCount(0);
});

// With the panel closed only the quick rules run after each drawing; contrast and image sizes wait for the panel
// (lista migliorie V11). Counted on the canvas window: the quick rules read no computed style.
test('a pannello chiuso i controlli non leggono gli stili calcolati; aprendolo girano tutti', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const count = () =>
    builder.canvasFrame.evaluate((frame: HTMLIFrameElement) => (frame.contentWindow as unknown as { xbStyleReads?: number }).xbStyleReads ?? 0);
  const spy = () =>
    builder.canvasFrame.evaluate((frame: HTMLIFrameElement) => {
      const win = frame.contentWindow as Window & { xbStyleReads?: number };
      if (win.xbStyleReads !== undefined) return;
      win.xbStyleReads = 0;
      const read = win.getComputedStyle.bind(win);
      win.getComputedStyle = ((el: Element, pseudo?: string | null) => {
        win.xbStyleReads = (win.xbStyleReads ?? 0) + 1;
        return read(el, pseudo);
      }) as typeof win.getComputedStyle;
    });
  await spy();
  await builder.edit(builder.html, (code) => code.replace('Ciao e2e', 'Ciao di nuovo'));
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao di nuovo' })).toBeVisible();
  await spy();
  // The checks run 600 ms after a drawing.
  await page.waitForTimeout(1200);
  expect(await count()).toBe(0);

  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Controlli', exact: true }).click();
  await expect.poll(count).toBeGreaterThan(0);
});
