import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The «⋯» menu of a panel follows the WAI-ARIA menu pattern: focus on the first item, arrows, Home, End, Esc
// back to its button (lista migliorie A5).
test('il menu ⋯ di un pannello si usa con le frecce, Inizio e Fine, ed Esc torna al pulsante', async ({ page, data }) => {
  await Builder.open(page, data.pages.palette.id);
  const toggle = page.getByRole('button', { name: 'Sposta HTML', exact: true });
  await toggle.click();
  const menu = page.getByRole('menu', { name: 'Sposta HTML' });
  const items = menu.getByRole('menuitem');
  await expect(items.first()).toBeFocused();
  // Each entry on one line, as wide as its words: the header's square icon buttons never shape the menu's.
  for (const item of await items.all()) {
    const box = (await item.boundingBox())!;
    expect(box.height, await item.innerText()).toBeLessThan(34);
    expect(box.width).toBeGreaterThan(120);
  }
  await page.keyboard.press('ArrowDown');
  await expect(items.nth(1)).toBeFocused();
  await page.keyboard.press('End');
  await expect(items.last()).toBeFocused();
  await expect(items.last()).toHaveText('Ripristina la disposizione');
  await page.keyboard.press('ArrowDown');
  await expect(items.first()).toBeFocused();
  await page.keyboard.press('ArrowUp');
  await expect(items.last()).toBeFocused();
  await page.keyboard.press('Home');
  await expect(items.first()).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(menu).toHaveCount(0);
  await expect(toggle).toBeFocused();
});

// A section over the canvas takes the focus and gives it back; what it covers is inert meanwhile (A4).
test('una sezione sopra la tela prende il focus, rende inerte ciò che copre e lo restituisce alla chiusura', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const opener = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Controlli', exact: true });
  await opener.click();
  await expect(page.getByRole('dialog', { name: 'Controlli' }).getByRole('heading', { name: 'Controlli' })).toBeFocused();
  await expect(page.locator('main.center > .bottom')).toHaveAttribute('inert', '');
  await expect(page.locator('main.center > .canvas-area')).toHaveAttribute('inert', '');
  // Tab never reaches the code panels hidden behind it.
  for (let i = 0; i < 6; i++) {
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => !!document.activeElement?.closest('main.center > .bottom, main.center > .canvas-area'))).toBe(false);
  }
  await page.getByRole('dialog', { name: 'Controlli' }).getByRole('heading', { name: 'Controlli' }).focus();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog', { name: 'Controlli' })).toHaveCount(0);
  await expect(opener).toBeFocused();
  await expect(page.locator('main.center > .bottom')).not.toHaveAttribute('inert', '');
  await expect(builder.html).toBeVisible();
});

// In a code editor Tab indents: the way out by keyboard is said there, and works (A6, WCAG 2.1.2).
test('negli editor di codice il suggerimento «Esc, poi Tab» compare e funziona', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const hint = page.locator('.tab-hint', { hasText: 'Esc, poi Tab' }).first();
  await expect(hint).toBeHidden();
  await builder.html.click();
  await expect(page.getByText('Esc, poi Tab: esci dall’editor').filter({ visible: true })).toHaveCount(1);
  await expect(builder.html).toHaveAttribute('aria-describedby', /xb-tab-hint-/);
  await page.keyboard.press('Tab');
  await expect(builder.html).toBeFocused();
  await page.keyboard.press('Escape');
  await page.keyboard.press('Tab');
  await expect(builder.html).not.toBeFocused();
  expect(await page.evaluate(() => !!document.activeElement?.closest('.cm-editor'))).toBe(false);
});
