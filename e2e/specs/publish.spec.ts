import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

const BANNER = 'Anteprima di sviluppo Histeon: non è il sito pubblicato';

// One page through its whole life: open, edit the code, save to dev, signed preview, publish with the diff.
test('modifica nel pannello HTML, salvataggio, anteprima firmata e pubblicazione', async ({ page, data }) => {
  const doc = data.pages.html;
  const builder = await Builder.open(page, doc.id);

  await test.step('il builder apre la pagina', async () => {
    await expect(page).toHaveTitle(`Histeon — ${doc.title}`);
    await expect(page.locator('header.topbar h1')).toHaveText(doc.title);
    // Headings and paragraphs with only text stay closed: their text is on their row.
    expect(await builder.nodes()).toEqual(['section.e2e-hero', 'h1', 'p.uno', 'p.due']);
    await expect(builder.node('h1')).toContainText('“Ciao e2e”');
    await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).toBeVisible();
    await expect(builder.html).toContainText('<section class="e2e-hero">');
  });

  await test.step('il testo cambiato nel codice arriva nella tela', async () => {
    await builder.edit(builder.html, (code) => code.replace('Primo paragrafo', 'Paragrafo modificato in e2e'));
    await expect(builder.canvas.getByText('Paragrafo modificato in e2e')).toBeVisible();
    await expect(builder.canvas.getByText('Primo paragrafo')).toHaveCount(0);
    await expect(builder.node('p.uno')).toContainText('Paragrafo modificato in e2e');
    await expect(builder.status).toHaveText('Modifiche non salvate');
  });

  await test.step('salva in sviluppo', async () => {
    await builder.save();
    await expect(builder.status).toHaveText('Salvato in sviluppo: non ancora pubblicato');
    // Live is untouched until a release.
    const live = await page.request.get(doc.url);
    expect(await live.text()).toContain('Primo paragrafo');
  });

  await test.step("l'anteprima firmata mostra lo sviluppo con il banner", async () => {
    // The builder steps aside: the page shows over it, with its scripts, until Esc.
    await page.getByRole('button', { name: 'Anteprima' }).click();
    const layer = page.getByRole('dialog', { name: 'Anteprima della pagina' });
    await expect(layer).toBeVisible();
    await expect(page.getByRole('button', { name: /Torna al builder/ })).toBeFocused();
    const preview = page.frameLocator('iframe[title="Anteprima della pagina in sviluppo"]');
    await expect(preview.getByText(BANNER)).toBeVisible();
    await expect(preview.getByText('Paragrafo modificato in e2e')).toBeVisible();
    const src = (await page.locator('iframe[title="Anteprima della pagina in sviluppo"]').getAttribute('src')) ?? '';
    // The same link opens in a new tab, without a way back to the builder window.
    await expect(layer.getByRole('link', { name: /Nuova scheda/ })).toHaveAttribute('rel', 'noopener noreferrer');
    // The preview stays out of search engines.
    const response = await page.request.get(src);
    expect(response.headers()['x-robots-tag']).toBe('noindex, nofollow');
    expect(response.headers()['referrer-policy']).toBe('no-referrer');
    await layer.getByRole('button', { name: 'Telefono' }).click();
    await expect(page.locator('iframe[title="Anteprima della pagina in sviluppo"]')).toHaveCSS('width', '390px');
    await page.keyboard.press('Escape');
    await expect(layer).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Anteprima' })).toBeFocused();
    // Without the signed token the same URL is the live page.
    const live = await page.request.get(doc.url);
    expect(await live.text()).not.toContain(BANNER);
  });

  await test.step('il dialogo di pubblicazione mostra le differenze e pubblica', async () => {
    await page.getByRole('button', { name: 'Pubblica…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Pubblica' });
    const row = dialog.locator('.change', { hasText: doc.title });
    await expect(row.getByRole('checkbox')).toBeChecked();
    await row.getByRole('button', { name: 'Differenze' }).click();
    const diff = dialog.getByRole('region', { name: new RegExp(`^Differenze di ${doc.title}`) });
    await expect(diff.locator('.add', { hasText: 'Paragrafo modificato in e2e' })).toBeVisible();
    await expect(diff.locator('.del', { hasText: 'Primo paragrafo' })).toBeVisible();
    const version = await dialog.getByLabel('Versione').inputValue();
    expect(version).toMatch(/^\d+\.\d+\.\d+$/);
    await dialog.getByLabel('Note').fill('e2e: pubblicazione dal dialogo');
    await dialog.getByRole('button', { name: 'Pubblica', exact: true }).click();
    await expect(dialog.getByRole('status')).toHaveText(new RegExp(`^Pubblicata la versione ${version.replace(/\./g, '\\.')}`));
    await dialog.getByRole('button', { name: 'Chiudi' }).click();
    await expect(page.getByRole('button', { name: 'Pubblica…' })).toBeDisabled();

    const live = await page.request.get(doc.url);
    const html = await live.text();
    expect(html).toContain('Paragrafo modificato in e2e');
    expect(html).not.toContain(BANNER);

    await builder.tab('Versioni');
    const release = builder.release(version);
    await expect(release).toContainText('e2e: pubblicazione dal dialogo');
    await expect(release.locator('.badge')).toHaveText('online');
  });
});
