import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// «Histeon · Libreria» in Gutenberg (lista della spesa 136): a section saved in the library goes into the page after
// the selected block, with its styles saved to development, as the builder's paste does it.
const MAKE = String.raw`<?php
$run    = getenv( 'E2E_RUN' );
$markup = '<!-- wp:xb/element {"uid":"lib' . $run . 'a","tag":"section","attributes":{"class":"e2e-lib-' . $run . '"}} -->'
	. '<!-- wp:xb/element {"uid":"lib' . $run . 'b","tag":"h2","attributes":{"class":"e2e-lib-' . $run . '__t"}} --><!-- wp:xb/text {"uid":"lib' . $run . 'c","content":"Sezione dalla libreria"} /--><!-- /wp:xb/element -->'
	. '<!-- /wp:xb/element -->';
$clip = wp_json_encode( array( 'xb' => 'clip', 'version' => 1, 'origin' => 'e2e', 'markup' => $markup, 'styles' => array( array( 'selector' => '.e2e-lib-' . $run, 'css' => "padding: 33px;\n&__t { color: rgb(1, 2, 3); }\n" ) ) ) );
echo "\nE2E=" . \XB\Library\Sections::save( 'Sezione e2e ' . $run, $clip ) . "\n";
`;

test('Gutenberg: una sezione della libreria entra nella pagina con i suoi stili', async ({ page, data }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  wpEval(MAKE, { E2E_RUN: run });
  await page.goto(`/wp-admin/post.php?post=${data.pages.dnd.id}&action=edit`);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await expect(canvas.locator('p.uno')).toBeVisible({ timeout: 30_000 });
  const guide = page.getByRole('dialog', { name: /Benvenut|Welcome/ });
  if (await guide.isVisible().catch(() => false)) await page.keyboard.press('Escape');
  await page.evaluate(() => (window as unknown as { wp: any }).wp.data.dispatch('core/edit-post').openGeneralSidebar('xb-library/xb-library'));
  const panel = page.locator('.xb-library');
  await expect(panel).toBeVisible({ timeout: 30_000 });
  const row = panel.locator('li', { hasText: `Sezione e2e ${run}` });
  await row.getByRole('button', { name: /Inserisci|Insert/ }).click();
  await expect(page.locator('.components-snackbar')).toContainText(`Sezione e2e ${run}`, { timeout: 30_000 });

  // In the page, styled as the section was (the nested &__t rule included), before any page save.
  const title = canvas.getByText('Sezione dalla libreria');
  await expect(title).toBeVisible();
  await expect.poll(() => title.evaluate((el) => getComputedStyle(el.closest('h2')!).color)).toBe('rgb(1, 2, 3)');
  await expect.poll(() => title.evaluate((el) => getComputedStyle(el.closest('section')!).paddingTop)).toBe('33px');
  // The styles are saved in development: a second editor of the site sees them.
  const saved = wpEval(String.raw`<?php echo wp_json_encode( \XB\Plugin::instance()->versioning->working_copy( \XB\Versioning\Documents::STYLES )['payload'] );`, {});
  expect(saved).toContain(`e2e-lib-${run}`);
});
