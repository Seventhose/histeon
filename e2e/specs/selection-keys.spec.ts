import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Delete keeps a selection (the next element, else the previous one, else the parent) and the focus on its row,
// so a page is cleaned with the keyboard alone (lista migliorie U4).
test('Elimina seleziona il fratello successivo, poi il precedente, poi il genitore, e il focus resta nella struttura', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const selected = builder.structure.getByRole('treeitem', { selected: true });

  await builder.node('p.uno').click();
  await page.keyboard.press('Delete');
  await expect(selected).toHaveCount(1);
  await expect(builder.node('p.due')).toBeFocused();
  await expect(selected.locator(':scope > .row > button.name')).toHaveText(/p\.due/);

  await page.keyboard.press('Delete');
  await expect(builder.node('h1')).toBeFocused();

  await page.keyboard.press('Delete');
  await expect(builder.node('section.e2e-hero')).toBeFocused();
  await expect(builder.canvas.locator('section.e2e-hero')).toBeAttached();
  await expect(builder.canvas.locator('section.e2e-hero > *')).toHaveCount(0);
});

// Esc in a popover closes the popover only: the selection stays, and the focus goes back to its button (U5).
test('Esc chiude solo il popover: la selezione resta e il focus torna al pulsante', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const selected = builder.structure.getByRole('treeitem', { selected: true });
  await builder.node('p.due').click();
  // Something to undo, so the history button is enabled.
  await builder.edit(builder.html, (code) => code.replace('Secondo paragrafo', 'Secondo, cambiato'));
  await expect(builder.canvas.getByText('Secondo, cambiato')).toBeVisible();

  const history = page.getByRole('button', { name: 'Storia delle modifiche' });
  await history.click();
  await expect(page.getByRole('dialog', { name: 'Storia delle modifiche' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog', { name: 'Storia delle modifiche' })).toHaveCount(0);
  await expect(history).toBeFocused();
  await expect(selected).toHaveCount(1);

  await builder.tab('CSS');
  const more = page.getByRole('button', { name: /^Altro/ });
  await more.click();
  await expect(page.getByRole('dialog', { name: 'Altro' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog', { name: 'Altro' })).toHaveCount(0);
  await expect(more).toBeFocused();
  await expect(selected).toHaveCount(1);

  // The AI connector's popover closes with Esc and with a click elsewhere, like the others.
  const ai = page.locator('button.ai');
  await ai.click();
  const connector = page.getByRole('dialog', { name: 'Connettore AI' });
  await expect(connector).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(connector).toHaveCount(0);
  await expect(ai).toBeFocused();
  await expect(selected).toHaveCount(1);
  await ai.click();
  await expect(connector).toBeVisible();
  await builder.structure.click({ position: { x: 5, y: 5 } });
  await expect(connector).toHaveCount(0);

  // With nothing open, Esc still clears the selection.
  await builder.node('p.due').click();
  await page.keyboard.press('Escape');
  await expect(selected).toHaveCount(0);
});

// A notice in the status line (copied, pasted…) goes with the next edit instead of hiding «Modifiche non salvate»,
// and the unsaved mark on Save is there whatever the status line says (U3).
test('un avviso sparisce alla modifica successiva e il segno «non salvato» resta sempre visibile', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const unsaved = builder.saveButton.locator('.unsaved');
  await expect(unsaved).toHaveCount(0);

  await builder.node('p.uno').click();
  await page.keyboard.press('Delete');
  await expect(builder.status).toHaveText('Modifiche non salvate');
  await expect(unsaved).toBeVisible();

  await builder.node('p.due').click();
  await page.keyboard.press('ControlOrMeta+c');
  await expect(builder.status).toHaveText(/^Elemento copiato con i suoi stili/);
  await expect(unsaved).toBeVisible();

  await page.keyboard.press('Delete');
  await expect(builder.status).toHaveText('Modifiche non salvate');
});
