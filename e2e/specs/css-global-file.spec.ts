import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// The global sheets can travel as one cached file, while each page keeps its own CSS inline (lista migliorie C5).
test('fogli globali in un file in cache: la scelta nelle Impostazioni e la pagina pubblica che lo collega', async ({ page, data }) => {
  const saved = wpEval(`<?php echo wp_json_encode( get_option( 'xb_global_styles', null ) );`, {}).trim().split('\n').pop() ?? 'null';
  const live = Buffer.from(JSON.stringify(saved)).toString('base64');
  try {
    wpEval(`<?php update_option( 'xb_global_styles', array( array( 'id' => 'global', 'css' => '.e2e-hero { border-top: 3px solid rgb(1, 2, 3); }' ) ) );`, {});

    await page.goto('/wp-admin/admin.php?page=xb-settings');
    const option = page.getByRole('checkbox', { name: /Fogli globali in un file in cache/ });
    await option.check();
    await page.getByRole('button', { name: 'Salva le impostazioni' }).click();
    await expect(page.getByRole('checkbox', { name: /Fogli globali in un file in cache/ })).toBeChecked();

    const response = await page.goto(data.pages.palette.url);
    expect(response?.status()).toBe(200);
    const link = page.locator('link#xb-global-css');
    await expect(link).toHaveAttribute('href', /\/xb-cache\/global-css-[0-9a-f]{32}\.css$/);
    await expect(page.locator('section.e2e-hero')).toHaveCSS('border-top-color', 'rgb(1, 2, 3)');
    const sheet = await page.request.get((await link.getAttribute('href'))!);
    expect(sheet.status()).toBe(200);
    expect(sheet.headers()['content-type']).toContain('text/css');
    // Not in the page any more: the page's own <style> has only its selectors.
    expect(await page.locator('style#xb-styles').evaluateAll((s) => s.map((e) => e.textContent).join(''))).not.toContain('rgb(1, 2, 3)');

    await page.goto('/wp-admin/admin.php?page=xb-settings');
    await page.getByRole('checkbox', { name: /Fogli globali in un file in cache/ }).uncheck();
    await page.getByRole('button', { name: 'Salva le impostazioni' }).click();
    await page.goto(data.pages.palette.url);
    await expect(page.locator('link#xb-global-css')).toHaveCount(0);
    await expect(page.locator('section.e2e-hero')).toHaveCSS('border-top-color', 'rgb(1, 2, 3)');
  } finally {
    wpEval(`<?php $v = json_decode( json_decode( base64_decode( '${live}' ) ), true ); if ( null === $v ) { delete_option( 'xb_global_styles' ); } else { update_option( 'xb_global_styles', $v ); } update_option( 'xb_css_global_file', false );`, {});
  }
});
