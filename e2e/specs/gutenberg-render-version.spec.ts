import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// Server rendering in Gutenberg (lista della spesa 149): a page with many components asks the server in one batch,
// and when a component changes on the site the editor draws it again on returning to the tab, without a reload.
const MAKE = String.raw`<?php
$run  = getenv( 'E2E_RUN' );
$ref  = 'e2e/versione-' . $run;
$uid  = static fn ( string $s ): string => 'rv' . substr( md5( $s . $run ), 0, 10 );
$def  = '<!-- wp:xb/element {"uid":"' . $uid( 'b' ) . '","tag":"p","attributes":{"class":"e2e-rv"}} --><!-- wp:xb/text {"uid":"' . $uid( 't' ) . '","content":"Prima {props.n}"} /--><!-- /wp:xb/element -->';
$component = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Versione ' . $run, 'post_content' => wp_slash( $def ) ) );
update_post_meta( $component, \XB\Render\ComponentRegistry::META_KEY, $ref );
update_post_meta( $component, \XB\Render\ComponentRegistry::META_SCHEMA, wp_json_encode( array( 'props' => array( 'n' => array( 'type' => 'string', 'default' => '0' ) ) ) ) );
$page = '';
for ( $i = 1; $i <= 12; $i++ ) {
	$page .= '<!-- wp:xb/component {"uid":"' . $uid( 'u' . $i ) . '","ref":"' . $ref . '","props":{"n":"' . $i . '"}} /-->';
}
$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Versione ' . $run, 'post_content' => wp_slash( $page ) ) );
echo "\nE2E=" . wp_json_encode( array( 'page' => $id, 'component' => $component ) ) . "\n";
`;

test('Gutenberg: dodici componenti in una richiesta, e un componente cambiato si ridisegna tornando sulla scheda', async ({ page }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  const made = JSON.parse(wpEval(MAKE, { E2E_RUN: run }).split('\n').find((l) => l.startsWith('E2E='))!.slice(4)) as { page: number; component: number };
  let renders = 0;
  page.on('request', (r) => {
    if (r.url().includes('editor-render')) renders += 1;
  });
  await page.goto(`/wp-admin/post.php?post=${made.page}&action=edit`);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await expect(canvas.getByText('Prima 12')).toBeVisible({ timeout: 30_000 });
  await expect(canvas.locator('p.e2e-rv')).toHaveCount(12);
  // Twelve blocks, one batch (the SEO panel may make its own request for the whole page).
  expect(renders).toBeLessThanOrEqual(2);

  wpEval(String.raw`<?php
$id  = (int) getenv( 'E2E_COMPONENT' );
$def = str_replace( 'Prima ', 'Dopo ', get_post_field( 'post_content', $id, 'raw' ) );
wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $def ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 5 ) ) );
`, { E2E_COMPONENT: String(made.component) });
  // Coming back to the tab (5 s after the last check at the earliest).
  await page.waitForTimeout(5_100);
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(canvas.getByText('Dopo 12')).toBeVisible({ timeout: 15_000 });
  await expect(canvas.getByText('Prima 3')).toHaveCount(0);
});
