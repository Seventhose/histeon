import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// The exit from the panel (docs/spec/export-core.md §17, doc 25 D7): Histeon → Impostazioni → «Uscita verso
// Gutenberg». The preview with a field plugin chosen, the write with its fingerprint, and the restore that puts a page
// back exactly as it was.
const out = (s: string) => s.split('\n').find((l) => l.startsWith('E2E='))!.slice(4);

const MAKE = String.raw`<?php
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$run = getenv( 'E2E_RUN' );
$id  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Uscita dal pannello ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( array( array( 'blockName' => 'xb/element', 'attrs' => array( 'uid' => 'ed' . substr( md5( $run ), 0, 10 ), 'tag' => 'section', 'attributes' => array( 'class' => 'e2e-uscita' ) ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ) ) ) ) );
echo "\nE2E=" . $id . "\n";
`;

const READ = String.raw`<?php
global $wpdb;
echo "\nE2E=" . wp_json_encode( array( (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", (int) getenv( 'E2E_ID' ) ) ), count( XB\Export\Core\Departure::backups() ) ) ) . "\n";
`;

const CLEANUP = String.raw`<?php
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
while ( array() !== XB\Export\Core\Departure::backups() ) {
	XB\Export\Core\Departure::restore();
}
echo "\nE2E=ok\n";
`;

test('dal pannello: anteprima, scrittura e ripristino dell\'uscita', async ({ page }) => {
  const run = Date.now().toString(36);
  const id = out(wpEval(MAKE, { E2E_RUN: run }));
  const [original] = JSON.parse(out(wpEval(READ, { E2E_ID: id })));
  try {
    await page.goto('/wp-admin/admin.php?page=xb-settings#xb-core-export');
    const panel = page.locator('#xb-core-export');
    await expect(panel.getByRole('heading', { name: 'Uscita verso Gutenberg' })).toBeVisible();
    await panel.locator('select[name="xb_campi"]').selectOption('scf');
    await panel.getByRole('button', { name: 'Anteprima dell\'uscita' }).click();
    await expect(page.locator('#xb-core-export').getByText('Anteprima: nulla è stato modificato.')).toBeVisible();
    await page.locator('#xb-core-export').getByLabel(/Ho letto l'anteprima/).check();
    await page.locator('#xb-core-export').getByRole('button', { name: 'Scrivi l\'uscita' }).click();
    await expect(page.locator('#xb-core-export').getByText(/Uscita scritta: \d+ documenti convertiti/)).toBeVisible();
    const [written, backups] = JSON.parse(out(wpEval(READ, { E2E_ID: id })));
    expect(written).toContain('<!-- wp:fondamenta/elemento');
    expect(written).not.toContain('<!-- wp:xb/');
    expect(backups).toBe(1);
    await page.locator('#xb-core-export').getByLabel(/Rimetti contenuti/).check();
    await page.locator('#xb-core-export').getByRole('button', { name: 'Ripristina' }).click();
    await expect(page.getByText(/Ripristinato lo stato di prima della scrittura/)).toBeVisible();
    const [restored, left] = JSON.parse(out(wpEval(READ, { E2E_ID: id })));
    expect(restored).toBe(original);
    expect(left).toBe(0);
  } finally {
    wpEval(CLEANUP, {});
  }
});
