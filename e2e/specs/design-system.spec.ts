import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

/** The test's own recipe out of the site's settings, before and after: a run that stops halfway leaves it there. */
const forgetRecipe = () =>
  wpEval(String.raw`<?php
wp_set_current_user( 1 );
$v = \XB\Plugin::instance()->versioning;
$c = $v->working_copy( 'settings' );
$p = $c['payload'];
if ( is_array( $p['recipes'] ?? null ) && array_key_exists( 'mia', $p['recipes'] ) ) {
	unset( $p['recipes']['mia'] );
	$v->save_dev( 'settings', $p, (string) $c['head'], (string) $c['base'], 'e2e: ricetta di prova tolta' );
}
$live = get_option( 'xb_site_recipes' );
if ( is_array( $live ) && array_key_exists( 'mia', $live ) ) {
	unset( $live['mia'] );
	update_option( 'xb_site_recipes', $live, false );
}`, {});

/** Reads the framework option, or sets it ('' deletes it: the site's default). Exactly as it was, afterwards. */
const framework = (id?: string) =>
  wpEval(`<?php ${id === undefined ? '' : id === '' ? "delete_option('xb_framework');" : `update_option('xb_framework', '${id.replace(/[^a-z-]/g, '')}');`} echo (string) get_option('xb_framework');`, {}).trim();

test('design system: le variabili del framework con i valori della pagina; Histeon Tokens si modificano e si salvano', async ({ page, data }) => {
  const before = framework();
  try {
    framework('xb-tokens');
    const builder = await Builder.open(page, data.pages.palette.id);
    await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Design system' }).click();
    const panel = page.getByRole('dialog', { name: 'Design system' });
    await expect(panel.getByRole('region', { name: 'Colori' })).toContainText('--xb-primary');
    await expect(panel.getByRole('region', { name: 'Spazi' })).toContainText('--xb-space-m');
    await expect(panel.getByRole('region', { name: 'Testi' })).toContainText('--xb-text-l');

    // A value that could break the stylesheet is refused before anything is sent.
    const primary = panel.getByLabel('Valore di --xb-primary', { exact: true });
    await primary.fill('red;} body{display:none');
    await expect(panel.getByText('Caratteri non ammessi').first()).toBeVisible();
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('status')).toContainText('--xb-primary');

    // A good one previews at once on the page and is saved in dev.
    await primary.fill('rgb(10, 120, 60)');
    const root = page.frameLocator('iframe[title="Anteprima della pagina"]').locator('html');
    await expect(root).toHaveAttribute('style', /--xb-primary: rgb\(10, 120, 60\)/);
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('status')).toContainText('Salvato in sviluppo');
    const saved = await builder.api<{ tokens: Record<string, string> }>('GET', 'tokens');
    expect(saved.tokens['xb-primary']).toBe('rgb(10, 120, 60)');
    // The canvas draws with it (the page's own value, not the preview's inline one).
    await expect(root).not.toHaveAttribute('style', /xb-primary/);
    expect(await root.evaluate((el) => getComputedStyle(el).getPropertyValue('--xb-primary').trim())).toBe('rgb(10, 120, 60)');

    // Back to the default: the override goes.
    // The token's own row (inside its colour family's row).
    await panel.getByRole('listitem').filter({ has: page.getByLabel('Valore di --xb-primary', { exact: true }) }).last().getByRole('button', { name: '↺' }).click();
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('status')).toContainText('Salvato in sviluppo');
    expect((await builder.api<{ tokens: Record<string, string> }>('GET', 'tokens')).tokens['xb-primary']).toBeUndefined();
  } finally {
    framework(before);
  }
});

test('design system: colori per famiglia, variabili e ricette del sito', async ({ page, data }) => {
  forgetRecipe();
  const before = framework();
  try {
    framework('xb-tokens');
    const builder = await Builder.open(page, data.pages.palette.id);
    await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Design system' }).click();
    const panel = page.getByRole('dialog', { name: 'Design system' });
    const colours = panel.getByRole('region', { name: 'Colori' });

    // Only the base colour of each family; its shades open with a click.
    await expect(colours).toContainText('--xb-primary');
    await expect(colours.getByText('--xb-primary-700', { exact: true })).toHaveCount(0);
    await colours.getByRole('button', { name: /^\+\d+$/ }).first().click();
    await expect(colours.getByText('--xb-primary-700', { exact: true })).toBeVisible();

    // A site variable: previewed at once, saved in dev, offered to the CSS editor.
    const site = panel.getByRole('region', { name: 'Variabili del sito' });
    await site.getByLabel('Nome della nuova variabile').fill('Bagliore');
    await expect(site.getByText('Nome: da 1 a 40')).toBeVisible();
    await site.getByLabel('Nome della nuova variabile').fill('bagliore');
    await site.getByLabel('Valore della nuova variabile').fill('rgb(200, 30, 90)');
    await site.getByRole('button', { name: '＋ Variabile' }).click();
    const root = page.frameLocator('iframe[title="Anteprima della pagina"]').locator('html');
    await expect(root).toHaveAttribute('style', /--xb-site-bagliore: rgb\(200, 30, 90\)/);

    // A recipe of the site replaces Histeon's with the same name; a new one is added.
    await panel.getByTestId('design-recipes').locator('summary').click();
    const recipes = panel.getByTestId('design-recipes');
    await recipes.getByRole('button', { name: /^@centra Histeon$/ }).click();
    await recipes.getByLabel('CSS della ricetta @centra').fill('display: grid;\nplace-items: start;');
    await recipes.getByLabel('Nome della nuova ricetta').fill('mia');
    await recipes.getByLabel('CSS della nuova ricetta').fill('background: url(https://x.example/a.png);');
    await expect(recipes.getByText('Non sono ammessi markup')).toBeVisible();
    await recipes.getByLabel('CSS della nuova ricetta').fill('gap: var(--xb-site-bagliore);');
    await recipes.getByRole('button', { name: '＋ Ricetta' }).click();
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('status')).toContainText('Salvato in sviluppo');

    const saved = await builder.api<{ siteTokens: Record<string, string>; recipes: { site: Record<string, string> } }>('GET', 'tokens');
    expect(saved.siteTokens).toEqual({ bagliore: 'rgb(200, 30, 90)' });
    expect(saved.recipes.site).toEqual({ centra: 'display: grid;\nplace-items: start;', mia: 'gap: var(--xb-site-bagliore);' });
    const fw = await builder.api<{ recipes: Record<string, string>; variables: Array<{ name: string }> }>('GET', 'framework');
    expect(fw.recipes['centra']).toBe('display: grid;\nplace-items: start;');
    expect(fw.variables.map((v) => v.name)).toContain('xb-site-bagliore');
    expect(await root.evaluate((el) => getComputedStyle(el).getPropertyValue('--xb-site-bagliore').trim())).toBe('rgb(200, 30, 90)');

    // ↺ brings Histeon's recipe back; the variable goes with ✕.
    await recipes.getByRole('button', { name: /^@centra Sito/ }).click();
    await recipes.getByRole('button', { name: '↺ Torna alla ricetta originale' }).click();
    await site.getByRole('button', { name: 'Elimina --xb-site-bagliore' }).click();
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('status')).toContainText('Salvato in sviluppo');
    const after = await builder.api<{ siteTokens: Record<string, string>; recipes: { site: Record<string, string> } }>('GET', 'tokens');
    expect(after.siteTokens).toEqual({});
    expect(Object.keys(after.recipes.site)).toEqual(['mia']);
  } finally {
    framework(before);
    forgetRecipe();
  }
});
