import { expect, test } from '../lib/test.ts';

// «Histeon · Aspetto» in Gutenberg (lista della spesa 130-132): a button changes the page in the canvas, the CSS
// under the page shows the same declaration with a note on what changed, and «Salva in sviluppo» keeps it.
test('Gutenberg: Aspetto cambia la pagina, il CSS mostra la stessa riga e il salvataggio la tiene', async ({ page, data }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const open = async () => {
    await page.goto(`/wp-admin/post.php?post=${data.pages.aspetto.id}&action=edit`);
    const canvas = page.frameLocator('iframe[name="editor-canvas"]');
    await expect(canvas.locator('p.uno')).toBeVisible({ timeout: 30_000 });
    const guide = page.getByRole('dialog', { name: /Benvenut|Welcome/ });
    if (await guide.isVisible().catch(() => false)) await page.keyboard.press('Escape');
    // The paragraph element (class "uno") selected, with the block settings sidebar open.
    await page.evaluate(() => {
      const wp = (window as unknown as { wp: any }).wp;
      const find = (blocks: any[]): string | null => {
        for (const b of blocks) {
          if (b.name === 'xb/element' && b.attributes?.attributes?.class === 'uno') return b.clientId;
          const inner = find(b.innerBlocks ?? []);
          if (inner) return inner;
        }
        return null;
      };
      const id = find(wp.data.select('core/block-editor').getBlocks());
      if (!id) throw new Error('blocco .uno non trovato');
      wp.data.dispatch('core/block-editor').selectBlock(id);
      wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block');
    });
    return canvas;
  };

  const canvas = await open();
  const panel = page.locator('.xb-look');
  await expect(panel).toBeVisible({ timeout: 30_000 });
  await panel.getByRole('tab', { name: /Spazi|Spaces/ }).click();
  const before = await canvas.locator('p.uno').evaluate((el) => getComputedStyle(el).paddingTop);
  const inner = panel.getByRole('group', { name: /^(Spazio interno|Inner space)$/ });
  // The page's class may keep what an earlier run saved: start from a button that is not pressed.
  const title = (await inner.getByRole('button', { pressed: false }).nth(2).getAttribute('title'))!;
  const value = title.replace(/^padding: /, '');
  const option = inner.locator(`[title="${title}"]`);
  const active = inner.locator(`[title="Togli: ${title}"], [title="Remove: ${title}"]`);
  await option.click();
  await expect(active).toHaveAttribute('aria-pressed', 'true');

  // The canvas shows it at once, without asking the server.
  await expect.poll(() => canvas.locator('p.uno').evaluate((el) => getComputedStyle(el).paddingTop)).not.toBe(before);
  // The note says what changed, in the panel and over the CSS under the page.
  await expect(panel.locator('.xb-look-change')).toContainText('padding');
  const dock = page.locator('.xb-dock');
  if ((await dock.getByRole('button', { name: /Histeon · Codice/ }).getAttribute('aria-expanded')) !== 'true') await dock.getByRole('button', { name: /Histeon · Codice/ }).click();
  await expect(dock.locator('.cm-content').first()).toBeVisible({ timeout: 30_000 });
  await expect(dock.locator('.cm-content', { hasText: `padding: ${value}` })).toHaveCount(1);
  await expect(dock.locator('.xb-look-change')).toContainText('padding');

  // Undo says what it undoes, takes the declaration away, and redo brings it back (list 141).
  const undo = panel.getByRole('button', { name: /^↶ (Annulla|Undo): padding (di|of) \.uno$/ });
  await expect(undo).toBeVisible();
  await undo.click();
  await expect(option).toHaveAttribute('aria-pressed', 'false');
  await panel.getByRole('button', { name: /^↷ (Ripeti|Redo): padding (di|of) \.uno$/ }).click();
  await expect(active).toHaveAttribute('aria-pressed', 'true');

  // A second click on the active button removes the declaration again.
  await active.click();
  await expect(option).toHaveAttribute('aria-pressed', 'false');
  await expect(dock.locator('.cm-content', { hasText: `padding: ${value}` })).toHaveCount(0);
  await option.click();
  await expect(active).toHaveAttribute('aria-pressed', 'true');

  // Saved to development, it is still there after a reload.
  await panel.getByRole('button', { name: /Salva in sviluppo|Save to development/ }).click();
  await expect(panel.getByRole('button', { name: /Salva in sviluppo|Save to development/ })).toBeDisabled({ timeout: 30_000 });
  await open();
  await page.locator('.xb-look').getByRole('tab', { name: /Spazi|Spaces/ }).click();
  await expect(page.locator('.xb-look').getByRole('group', { name: /^(Spazio interno|Inner space)$/ }).getByRole('button', { pressed: true })).toHaveAttribute('title', new RegExp(`padding: ${value.replace(/[()]/g, '\\$&')}`));
});
