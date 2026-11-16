import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// The site's fields leaving Histeon for Secure Custom Fields (docs/spec/export-core.md §15, doc 25 §11 step 5): the
// field group the site theme would carry, imported into a real SCF; the values converted to its form; SCF reads
// them back as Histeon had them and shows them in the editor. Skipped when SCF is not in the dev WordPress
// (dev/install-third-party.sh secure-custom-fields.zip); SCF is switched on only for this test.
const PLUGIN = 'secure-custom-fields/secure-custom-fields.php';

const PRESENT = String.raw`<?php echo "\nE2E=" . ( file_exists( WP_PLUGIN_DIR . '/' . getenv( 'E2E_PLUGIN' ) ) ? 'si' : 'no' ) . "\n";`;

// SCF switched on in a request of its own: in the request that activates it, its field types are not set up yet.
const ACTIVATE = String.raw`<?php activate_plugin( getenv( 'E2E_PLUGIN' ) ); echo "\nE2E=ok\n";`;

const SETUP = String.raw`<?php
$run = getenv( 'E2E_RUN' );
update_option( 'xb_e2e_fields_backup', get_option( XB\Content\ContentTypes::OPTION ) );
update_option( XB\Content\ContentTypes::OPTION, XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => array(
	array( 'name' => 'e2edata', 'label' => 'Data', 'type' => 'date' ),
	array( 'name' => 'e2eattivo', 'label' => 'Attivo', 'type' => 'toggle' ),
	array( 'name' => 'e2eanni', 'label' => 'Anni', 'type' => 'number', 'multiple' => true ),
	array( 'name' => 'e2eorari', 'label' => 'Orari', 'type' => 'repeater', 'sub' => array(
		array( 'name' => 'giorno', 'label' => 'Giorno', 'type' => 'text' ),
		array( 'name' => 'turni', 'label' => 'Turni', 'type' => 'repeater', 'sub' => array( array( 'name' => 'ora', 'label' => 'Ora', 'type' => 'text' ) ) ),
	) ),
) ) ) ) );
XB\Content\ContentTypes::forget();
$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Campi SCF ' . $run ) );
foreach ( array(
	'e2edata'   => '2026-01-10',
	'e2eattivo' => true,
	'e2eanni'   => array( 2024, 2025 ),
	'e2eorari'  => array( array( 'giorno' => 'Lunedì ' . $run, 'turni' => array( array( 'ora' => '9' ), array( 'ora' => '15' ) ) ), array( 'giorno' => 'Martedì', 'turni' => array() ) ),
) as $key => $value ) {
	add_post_meta( $id, $key, $value, true );
}
$adapter = XB\Export\Core\Fields\Adapters::get( 'scf' );
XB\Export\Core\Fields\Adapters::write_post( $adapter, get_post( $id ) );
foreach ( $adapter->theme_files( XB\Content\ContentTypes::live() ) as $json ) {
	acf_import_field_group( json_decode( $json, true ) );
}
echo "\nE2E=" . $id . "\n";
`;

const READ = String.raw`<?php
$id = (int) getenv( 'E2E_ID' );
echo "\nE2E=" . wp_json_encode( array(
	'data'   => get_field( 'e2edata', $id ),
	'attivo' => get_field( 'e2eattivo', $id ),
	'anni'   => get_field( 'e2eanni', $id ),
	'orari'  => get_field( 'e2eorari', $id ),
) ) . "\n";
`;

const CLEANUP = String.raw`<?php
foreach ( get_posts( array( 'post_type' => 'acf-field-group', 'post_status' => 'any', 'posts_per_page' => -1, 's' => 'Campi' ) ) as $group ) {
	acf_delete_field_group( $group->ID );
}
$backup = get_option( 'xb_e2e_fields_backup' );
update_option( XB\Content\ContentTypes::OPTION, false === $backup ? array() : $backup );
delete_option( 'xb_e2e_fields_backup' );
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

const out = (s: string) => s.split('\n').find((l) => l.startsWith('E2E='))!.slice(4);

test('i campi passano a Secure Custom Fields: SCF li legge come Histeon e li mostra nell\'editor', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: PLUGIN })) !== 'si', 'Secure Custom Fields non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: PLUGIN });
    const id = out(wpEval(SETUP, { E2E_RUN: run }));
    const values = JSON.parse(out(wpEval(READ, { E2E_ID: id })));
    expect(values.data).toBe('2026-01-10');
    expect(values.attivo).toBe(true);
    expect(values.anni).toEqual([{ valore: '2024' }, { valore: '2025' }]);
    expect(values.orari).toEqual([
      { giorno: `Lunedì ${run}`, turni: [{ ora: '9' }, { ora: '15' }] },
      // A repeater without rows is false for ACF, as when ACF itself saves it.
      { giorno: 'Martedì', turni: false },
    ]);
    // In the editor, SCF's own boxes show the rows (inputs carry the values).
    await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
    await expect(page.locator(`input[value="Lunedì ${run}"]`)).toHaveCount(1, { timeout: 30_000 });
    await expect(page.locator('input[value="15"]')).toHaveCount(1);
  } finally {
    wpEval(CLEANUP, { E2E_PLUGIN: PLUGIN });
  }
});

// Meta Box: the meta boxes as the site theme hands them over (a must-use plugin stands in for the site theme's
// functions.php here, and goes at the end). Meta Box without extensions: simple fields, clones and choices.
const MB_PLUGIN = 'meta-box/meta-box.php';

const MB_SETUP = String.raw`<?php
$run = getenv( 'E2E_RUN' );
update_option( 'xb_e2e_fields_backup', get_option( XB\Content\ContentTypes::OPTION ) );
update_option( XB\Content\ContentTypes::OPTION, XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => array(
	array( 'name' => 'e2edata', 'label' => 'Data', 'type' => 'date' ),
	array( 'name' => 'e2eattivo', 'label' => 'Attivo', 'type' => 'toggle' ),
	array( 'name' => 'e2eanni', 'label' => 'Anni', 'type' => 'number', 'multiple' => true ),
	array( 'name' => 'e2etag', 'label' => 'Etichette', 'type' => 'select', 'multiple' => true, 'options' => array( array( 'value' => 'x', 'label' => 'X' ), array( 'value' => 'y', 'label' => 'Y' ), array( 'value' => 'z', 'label' => 'Z' ) ) ),
) ) ) ) );
XB\Content\ContentTypes::forget();
$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Campi Meta Box ' . $run ) );
foreach ( array( 'e2edata' => '2026-01-10', 'e2eattivo' => true, 'e2eanni' => array( 2024, 2025 ), 'e2etag' => array( 'x', 'z' ) ) as $key => $value ) {
	add_post_meta( $id, $key, $value, true );
}
$adapter = XB\Export\Core\Fields\Adapters::get( 'metabox' );
XB\Export\Core\Fields\Adapters::write_post( $adapter, get_post( $id ) );
$files = $adapter->theme_files( XB\Content\ContentTypes::live() );
update_option( 'xb_e2e_meta_boxes', $files['campi/meta-box.json'] );
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/xb-e2e-meta-box.php', "<?php\nadd_filter( 'rwmb_meta_boxes', static fn ( array \$b ): array => array_merge( \$b, (array) json_decode( (string) get_option( 'xb_e2e_meta_boxes' ), true ) ) );\n" );
echo "\nE2E=" . $id . "\n";
`;

const MB_READ = String.raw`<?php
$id = (int) getenv( 'E2E_ID' );
echo "\nE2E=" . wp_json_encode( array(
	'data'   => rwmb_meta( 'e2edata', '', $id ),
	'attivo' => rwmb_meta( 'e2eattivo', '', $id ),
	'anni'   => rwmb_meta( 'e2eanni', '', $id ),
	'tag'    => rwmb_meta( 'e2etag', '', $id ),
) ) . "\n";
`;

const MB_CLEANUP = String.raw`<?php
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-meta-box.php' );
delete_option( 'xb_e2e_meta_boxes' );
$backup = get_option( 'xb_e2e_fields_backup' );
update_option( XB\Content\ContentTypes::OPTION, false === $backup ? array() : $backup );
delete_option( 'xb_e2e_fields_backup' );
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('i campi passano a Meta Box: Meta Box li legge come Histeon e li mostra nell\'editor', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: MB_PLUGIN })) !== 'si', 'Meta Box non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: MB_PLUGIN });
    const id = out(wpEval(MB_SETUP, { E2E_RUN: run }));
    const values = JSON.parse(out(wpEval(MB_READ, { E2E_ID: id })));
    expect(values.data).toBe('2026-01-10');
    expect(Number(values.attivo)).toBe(1);
    expect(values.anni.map(Number)).toEqual([2024, 2025]);
    expect(values.tag).toEqual(['x', 'z']);
    await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
    await expect(page.locator('input[name="e2edata"]')).toHaveValue('2026-01-10', { timeout: 30_000 });
    await expect(page.locator('select[name="e2etag[]"] option[value="z"]')).toHaveAttribute('selected', /.*/);
  } finally {
    wpEval(MB_CLEANUP, { E2E_PLUGIN: MB_PLUGIN });
  }
});

// Pods: pods.json as the site theme carries it (here in a folder of uploads that a must-use plugin hands to Pods,
// in place of the theme; both go at the end).
const PODS_PLUGIN = 'pods/init.php';

const PODS_SETUP = String.raw`<?php
$run = getenv( 'E2E_RUN' );
update_option( 'xb_e2e_fields_backup', get_option( XB\Content\ContentTypes::OPTION ) );
update_option( XB\Content\ContentTypes::OPTION, XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => array(
	array( 'name' => 'e2edata', 'label' => 'Data', 'type' => 'date' ),
	array( 'name' => 'e2eattivo', 'label' => 'Attivo', 'type' => 'toggle' ),
	array( 'name' => 'e2eanni', 'label' => 'Anni', 'type' => 'number', 'multiple' => true ),
	array( 'name' => 'e2etag', 'label' => 'Etichette', 'type' => 'select', 'multiple' => true, 'options' => array( array( 'value' => 'x', 'label' => 'X' ), array( 'value' => 'y', 'label' => 'Y' ), array( 'value' => 'z', 'label' => 'Z' ) ) ),
	array( 'name' => 'e2erif', 'label' => 'Collegate', 'type' => 'post', 'postType' => 'page', 'multiple' => true ),
) ) ) ) );
XB\Content\ContentTypes::forget();
$p1 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Collegata uno ' . $run ) );
$p2 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Collegata due ' . $run ) );
$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Campi Pods ' . $run ) );
foreach ( array( 'e2edata' => '2026-01-10', 'e2eattivo' => true, 'e2eanni' => array( 2024, 2025 ), 'e2etag' => array( 'x', 'z' ), 'e2erif' => array( $p1, $p2 ) ) as $key => $value ) {
	add_post_meta( $id, $key, $value, true );
}
$adapter = XB\Export\Core\Fields\Adapters::get( 'pods' );
XB\Export\Core\Fields\Adapters::write_post( $adapter, get_post( $id ) );
$files = $adapter->theme_files( XB\Content\ContentTypes::live() );
wp_mkdir_p( WP_CONTENT_DIR . '/uploads/xb-e2e-pods' );
file_put_contents( WP_CONTENT_DIR . '/uploads/xb-e2e-pods/pods.json', $files['pods.json'] );
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/xb-e2e-pods.php', "<?php\nadd_action( 'pods_config_pre_load_configs', static function ( \$h ): void { \$h->register_path( WP_CONTENT_DIR . '/uploads/xb-e2e-pods/' ); } );\n" );
pods_transient_clear( 'pods_config_handler_found_configs' );
echo "\nE2E=" . wp_json_encode( array( $id, $p1, $p2 ) ) . "\n";
`;

const PODS_READ = String.raw`<?php
$pod = pods( 'post', (int) getenv( 'E2E_ID' ) );
echo "\nE2E=" . wp_json_encode( array(
	'data'   => $pod->field( 'e2edata' ),
	'attivo' => $pod->field( 'e2eattivo' ),
	'anni'   => $pod->field( 'e2eanni' ),
	'tag'    => $pod->field( 'e2etag' ),
	'rif'    => $pod->field( 'e2erif', array( 'output' => 'ids' ) ),
) ) . "\n";
`;

const PODS_CLEANUP = String.raw`<?php
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-pods.php' );
@unlink( WP_CONTENT_DIR . '/uploads/xb-e2e-pods/pods.json' );
@rmdir( WP_CONTENT_DIR . '/uploads/xb-e2e-pods' );
if ( function_exists( 'pods_transient_clear' ) ) {
	pods_transient_clear( 'pods_config_handler_found_configs' );
}
$backup = get_option( 'xb_e2e_fields_backup' );
update_option( XB\Content\ContentTypes::OPTION, false === $backup ? array() : $backup );
delete_option( 'xb_e2e_fields_backup' );
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('i campi passano a Pods: Pods li legge come Histeon e li mostra nell\'editor', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: PODS_PLUGIN })) !== 'si', 'Pods non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: PODS_PLUGIN });
    const [id, p1, p2] = JSON.parse(out(wpEval(PODS_SETUP, { E2E_RUN: run })));
    const values = JSON.parse(out(wpEval(PODS_READ, { E2E_ID: String(id) })));
    expect(values.data).toBe('2026-01-10');
    expect(values.attivo).toBe('1');
    expect(values.anni).toEqual(['2024', '2025']);
    expect(values.tag).toEqual(['x', 'z']);
    expect(values.rif).toEqual([p1, p2]);
    await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
    // Pods' box is in the editor's «Meta Boxes» area (closed): there, with the date field filled in.
    await expect(page.getByText('Campi: post')).toBeAttached({ timeout: 30_000 });
    await expect(page.locator('input[name="pods_meta_e2edata"]')).toHaveValue(/2026-01-10|10\/01\/2026|01\/10\/2026/);
  } finally {
    wpEval(PODS_CLEANUP, { E2E_PLUGIN: PODS_PLUGIN });
  }
});

// JetEngine (a copy of the owner's licensed plugin, only in the dev WordPress): its meta boxes as the site theme's
// functions.php adds them (here a must-use plugin), and its own box in the editor showing the converted values.
const JET_PLUGIN = 'jet-engine/jet-engine.php';

const JET_SETUP = String.raw`<?php
$run = getenv( 'E2E_RUN' );
update_option( 'xb_e2e_fields_backup', get_option( XB\Content\ContentTypes::OPTION ) );
update_option( XB\Content\ContentTypes::OPTION, XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => array(
	array( 'name' => 'e2edata', 'label' => 'Data', 'type' => 'date' ),
	array( 'name' => 'e2eattivo', 'label' => 'Attivo', 'type' => 'toggle' ),
	array( 'name' => 'e2eorari', 'label' => 'Orari', 'type' => 'repeater', 'sub' => array( array( 'name' => 'giorno', 'label' => 'Giorno', 'type' => 'text' ) ) ),
) ) ) ) );
XB\Content\ContentTypes::forget();
$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Campi JetEngine ' . $run ) );
foreach ( array( 'e2edata' => '2026-01-10', 'e2eattivo' => true, 'e2eorari' => array( array( 'giorno' => 'Lunedì ' . $run ), array( 'giorno' => 'Martedì ' . $run ) ) ) as $key => $value ) {
	add_post_meta( $id, $key, $value, true );
}
$adapter = XB\Export\Core\Fields\Adapters::get( 'jetengine' );
XB\Export\Core\Fields\Adapters::write_post( $adapter, get_post( $id ) );
$files = $adapter->theme_files( XB\Content\ContentTypes::live() );
update_option( 'xb_e2e_jet_boxes', $files['campi/jet-engine.json'] );
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/xb-e2e-jet-engine.php', "<?php\n\$f = static fn ( mixed \$b ): array => ( is_array( \$b ) ? \$b : array() ) + (array) json_decode( (string) get_option( 'xb_e2e_jet_boxes' ), true );\nadd_filter( 'option_jet_engine_meta_boxes', \$f );\nadd_filter( 'default_option_jet_engine_meta_boxes', \$f );\n" );
echo "\nE2E=" . $id . "\n";
`;

const JET_CLEANUP = String.raw`<?php
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-jet-engine.php' );
delete_option( 'xb_e2e_jet_boxes' );
$backup = get_option( 'xb_e2e_fields_backup' );
update_option( XB\Content\ContentTypes::OPTION, false === $backup ? array() : $backup );
delete_option( 'xb_e2e_fields_backup' );
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('i campi passano a JetEngine: il suo riquadro nell\'editor mostra i valori convertiti', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: JET_PLUGIN })) !== 'si', 'JetEngine non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: JET_PLUGIN });
    const id = out(wpEval(JET_SETUP, { E2E_RUN: run }));
    await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
    await expect(page.getByText('Campi: post')).toBeAttached({ timeout: 30_000 });
    await expect(page.locator('input[name="e2edata"]')).toHaveValue('2026-01-10');
    await expect(page.locator(`input[value="Lunedì ${run}"]`)).toHaveCount(1);
    await expect(page.locator(`input[value="Martedì ${run}"]`)).toHaveCount(1);
  } finally {
    wpEval(JET_CLEANUP, { E2E_PLUGIN: JET_PLUGIN });
  }
});

// Codeideal Open Fields (0.4, a young plugin, tried here as doc 25 asks): the values converted, read back by its own
// functions; the group file of the site theme imported through its import endpoint.
const OF_PLUGIN = 'codeideal-open-fields/codeideal-open-fields.php';

const OF_SETUP = String.raw`<?php
$run = getenv( 'E2E_RUN' );
update_option( 'xb_e2e_fields_backup', get_option( XB\Content\ContentTypes::OPTION ) );
update_option( XB\Content\ContentTypes::OPTION, XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => array(
	array( 'name' => 'e2edata', 'label' => 'Data', 'type' => 'date' ),
	array( 'name' => 'e2eattivo', 'label' => 'Attivo', 'type' => 'toggle' ),
	array( 'name' => 'e2eorari', 'label' => 'Orari', 'type' => 'repeater', 'sub' => array( array( 'name' => 'giorno', 'label' => 'Giorno', 'type' => 'text' ) ) ),
) ) ) ) );
XB\Content\ContentTypes::forget();
$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Campi Open Fields ' . $run ) );
foreach ( array( 'e2edata' => '2026-01-10', 'e2eattivo' => true, 'e2eorari' => array( array( 'giorno' => 'Lunedì ' . $run ), array( 'giorno' => 'Martedì' ) ) ) as $key => $value ) {
	add_post_meta( $id, $key, $value, true );
}
$adapter = XB\Export\Core\Fields\Adapters::get( 'openfields' );
XB\Export\Core\Fields\Adapters::write_post( $adapter, get_post( $id ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$imported = array();
foreach ( $adapter->theme_files( XB\Content\ContentTypes::live() ) as $json ) {
	$request = new WP_REST_Request( 'POST', '/' . COFLD_REST_API::NAMESPACE . '/fieldsets/import' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( $json );
	$response   = rest_do_request( $request );
	$imported[] = $response->get_data()['id'] ?? null;
}
echo "\nE2E=" . wp_json_encode( array( $id, $imported ) ) . "\n";
`;

const OF_READ = String.raw`<?php
$id = (int) getenv( 'E2E_ID' );
global $wpdb;
echo "\nE2E=" . wp_json_encode( array(
	'data'   => cofld_get_field( 'e2edata', $id ),
	'attivo' => cofld_get_field( 'e2eattivo', $id ),
	'orari'  => cofld_get_rows( 'e2eorari', $id ),
	'campi'  => $wpdb->get_col( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}cofld_fields WHERE fieldset_id = %d ORDER BY menu_order", (int) getenv( 'E2E_SET' ) ) ),
) ) . "\n";
`;

const OF_CLEANUP = String.raw`<?php
global $wpdb;
$set = (int) getenv( 'E2E_SET' );
if ( $set > 0 ) {
	$wpdb->delete( $wpdb->prefix . 'cofld_fields', array( 'fieldset_id' => $set ) );
	$wpdb->delete( $wpdb->prefix . 'cofld_locations', array( 'fieldset_id' => $set ) );
	$wpdb->delete( $wpdb->prefix . 'cofld_fieldsets', array( 'id' => $set ) );
}
$backup = get_option( 'xb_e2e_fields_backup' );
update_option( XB\Content\ContentTypes::OPTION, false === $backup ? array() : $backup );
delete_option( 'xb_e2e_fields_backup' );
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('i campi passano a Open Fields: le sue funzioni leggono i valori convertiti e il gruppo si importa', async () => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: OF_PLUGIN })) !== 'si', 'Open Fields non è nel WordPress di sviluppo');
  const run = Date.now().toString(36);
  let set = '0';
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: OF_PLUGIN });
    const [id, imported] = JSON.parse(out(wpEval(OF_SETUP, { E2E_RUN: run })));
    set = String(imported[0] ?? 0);
    const values = JSON.parse(out(wpEval(OF_READ, { E2E_ID: String(id), E2E_SET: set })));
    expect(values.data).toBe('2026-01-10');
    expect(String(values.attivo)).toBe('1');
    expect(values.orari).toEqual([{ giorno: `Lunedì ${run}` }, { giorno: 'Martedì' }]);
    expect(values.campi).toEqual(['e2edata', 'e2eattivo', 'e2eorari']);
  } finally {
    wpEval(OF_CLEANUP, { E2E_PLUGIN: OF_PLUGIN, E2E_SET: set });
  }
});

