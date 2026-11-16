import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// Histeon's own forms leaving Histeon for Fluent Forms (docs/spec/export-core.md §16, doc 25 §11 step 6): the form
// made in the real plugin from a Histeon form, the messages already received carried over as entries, the converted
// page showing Fluent Forms' form, and a visitor sending it. Skipped when Fluent Forms is not in the dev WordPress
// (dev/install-third-party.sh fluentform.zip); switched on only for this test.
const PLUGIN = 'fluentform/fluentform.php';
const out = (s: string) => s.split('\n').find((l) => l.startsWith('E2E='))!.slice(4);

const PRESENT = String.raw`<?php echo "\nE2E=" . ( file_exists( WP_PLUGIN_DIR . '/' . getenv( 'E2E_PLUGIN' ) ) ? 'si' : 'no' ) . "\n";`;
const ACTIVATE = String.raw`<?php activate_plugin( getenv( 'E2E_PLUGIN' ) ); echo "\nE2E=ok\n";`;

const SETUP = String.raw`<?php
// An administrator: Histeon keeps a form in a page only for who may write it.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
$run  = getenv( 'E2E_RUN' );
$name = 'e2e' . $run;
$n    = 0;
$uid  = static function () use ( $run, &$n ): string { return 'ef' . substr( md5( $run . ( $n++ ) ), 0, 10 ); };
$b    = static function ( string $block, array $attrs, array $inner = array() ) use ( $uid ): array {
	return array( 'blockName' => $block, 'attrs' => array( 'uid' => $uid() ) + $attrs, 'innerBlocks' => $inner, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $inner ), null ) );
};
$el    = static fn ( string $tag, array $attributes, array $inner = array() ): array => $b( 'xb/element', array( 'tag' => $tag ) + ( array() === $attributes ? array() : array( 'attributes' => $attributes ) ), $inner );
$t     = static fn ( string $content ): array => $b( 'xb/text', array( 'content' => $content ) );
$label = static fn ( string $text, array $control ): array => $el( 'label', array(), array( $t( $text . ' ' ), $control ) );
$thanks = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Grazie ' . $run, 'post_name' => 'grazie-' . $run ) );
$form  = $el( 'form', array( 'method' => 'post', 'action' => '/xb-form' ), array(
	$el( 'input', array( 'type' => 'hidden', 'name' => 'xb_form', 'value' => $name ) ),
	$el( 'input', array( 'type' => 'hidden', 'name' => 'xb_redirect', 'value' => '/grazie-' . $run . '/' ) ),
	$label( 'Nome', $el( 'input', array( 'name' => 'nome', 'required' => '' ) ) ),
	$label( 'Email', $el( 'input', array( 'name' => 'email', 'type' => 'email', 'required' => '' ) ) ),
	$label( 'Messaggio', $el( 'textarea', array( 'name' => 'messaggio' ) ) ),
	$el( 'button', array(), array( $t( 'Invia' ) ) ),
) );
$source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Form sorgente ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( array( $el( 'section', array( 'class' => 'scrivici' ), array( $el( 'h2', array(), array( $t( 'Scrivici ' . $run ) ) ), $form ) ) ) ) ) ) );
foreach ( array( 'Anna', 'Bruno' ) as $i => $who ) {
	wp_insert_post( array( 'post_type' => 'xb_submission', 'post_status' => 'private', 'post_title' => $name . ' — 2026-10-0' . ( $i + 1 ) . ' 10:00', 'post_date' => '2026-10-0' . ( $i + 1 ) . ' 10:00:00', 'post_content' => "nome: $who\nemail: " . strtolower( $who ) . "@esempio.test\nmessaggio: Ciao\n" ) );
}
$adapter = XB\Export\Core\Forms\Adapters::get( getenv( 'E2E_ADAPTER' ) );
$def     = XB\Export\Core\Forms\Reader::all()[ $name ];
$id      = $adapter->create( $def );
$carried = $adapter->messages( $id, XB\Export\Core\Forms\Adapters::messages_of( $name ) );
XB\Export\Core\Converter::$forms = array( $name => $adapter->block( $id ) );
$done = XB\Export\Core\Prova::convert( get_post( $source ) );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Form convertito ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( $done['blocks'] ) ) ) );
echo "\nE2E=" . wp_json_encode( array( 'page' => $page, 'form' => $id, 'carried' => $carried, 'thanks' => $thanks, 'source' => $source ) ) . "\n";
`;

const READ = String.raw`<?php
global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT response FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d ORDER BY id", (int) getenv( 'E2E_FORM' ) ), ARRAY_A );
echo "\nE2E=" . wp_json_encode( array_map( static fn ( array $r ): mixed => json_decode( $r['response'], true ), $rows ) ) . "\n";
`;

const CLEANUP = String.raw`<?php
global $wpdb;
$form = (int) getenv( 'E2E_FORM' );
if ( $form > 0 ) {
	foreach ( array( 'fluentform_entry_details', 'fluentform_submissions', 'fluentform_form_meta' ) as $table ) {
		$wpdb->delete( $wpdb->prefix . $table, array( 'form_id' => $form ) );
	}
	$wpdb->delete( $wpdb->prefix . 'fluentform_forms', array( 'id' => $form ) );
}
foreach ( get_posts( array( 'post_type' => 'xb_submission', 'post_status' => 'private', 'posts_per_page' => -1, 's' => 'e2e' . getenv( 'E2E_RUN' ) ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('un form di Histeon diventa un form di Fluent Forms: messaggi portati, pagina convertita, invio vero', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: PLUGIN })) !== 'si', 'Fluent Forms non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  let form = '0';
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: PLUGIN });
    const made = JSON.parse(out(wpEval(SETUP, { E2E_RUN: run, E2E_ADAPTER: 'fluentforms' })));
    form = String(made.form);
    expect(made.carried).toBe(2);
    await page.context().clearCookies();
    await page.goto(`/?page_id=${made.page}`);
    await expect(page.getByText(`Scrivici ${run}`)).toBeVisible();
    const ff = page.locator(`form.frm-fluent-form`);
    await expect(ff).toBeVisible();
    await ff.locator('input[name="nome"]').fill('Carla');
    await ff.locator('input[name="email"]').fill('carla@esempio.test');
    await ff.locator('textarea[name="messaggio"]').fill(`Dal test ${run}`);
    await ff.locator('button[type="submit"]').click();
    await page.waitForURL(new RegExp(`grazie-${run}`), { timeout: 30_000 });
    const entries = JSON.parse(out(wpEval(READ, { E2E_FORM: form })));
    expect(entries.map((e: Record<string, string>) => e.nome)).toEqual(['Anna', 'Bruno', 'Carla']);
    expect(entries[2].messaggio).toBe(`Dal test ${run}`);
  } finally {
    wpEval(CLEANUP, { E2E_PLUGIN: PLUGIN, E2E_FORM: form, E2E_RUN: run });
  }
});

// Contact Form 7: the same Histeon form; Contact Form 7 keeps no messages and stays on the page after sending. A
// must-use plugin stands in for the mail server while the test runs (without mail Contact Form 7 reports a failure).
const CF7_PLUGIN = 'contact-form-7/wp-contact-form-7.php';

const MAIL_ON = String.raw`<?php
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/xb-e2e-mail.php', "<?php\nadd_filter( 'pre_wp_mail', static function () { update_option( 'xb_e2e_mail', (string) ( (int) get_option( 'xb_e2e_mail', 0 ) + 1 ) ); return true; } );\n" );
delete_option( 'xb_e2e_mail' );
echo "\nE2E=ok\n";
`;

const CF7_CLEANUP = String.raw`<?php
$id = (int) getenv( 'E2E_FORM' );
if ( $id > 0 ) {
	wp_delete_post( $id, true );
}
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-mail.php' );
$mails = get_option( 'xb_e2e_mail', '0' );
delete_option( 'xb_e2e_mail' );
foreach ( get_posts( array( 'post_type' => 'xb_submission', 'post_status' => 'private', 'posts_per_page' => -1, 's' => 'e2e' . getenv( 'E2E_RUN' ) ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=" . $mails . "\n";
`;

test('un form di Histeon diventa un form di Contact Form 7: pagina convertita e invio vero', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: CF7_PLUGIN })) !== 'si', 'Contact Form 7 non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  let form = '0';
  let mails = '0';
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: CF7_PLUGIN });
    wpEval(MAIL_ON, {});
    const made = JSON.parse(out(wpEval(SETUP, { E2E_RUN: run, E2E_ADAPTER: 'cf7' })));
    form = String(made.form);
    expect(made.carried).toBe(0);
    await page.context().clearCookies();
    await page.goto(`/?page_id=${made.page}`);
    await expect(page.getByText(`Scrivici ${run}`)).toBeVisible();
    const cf7 = page.locator('form.wpcf7-form');
    await expect(cf7).toBeVisible();
    await expect(cf7.getByText('Nome')).toBeVisible();
    await cf7.locator('input[name="nome"]').fill('Carla');
    await cf7.locator('input[name="email"]').fill('carla@esempio.test');
    await cf7.locator('textarea[name="messaggio"]').fill(`Dal test ${run}`);
    await cf7.locator('input[type="submit"]').click();
    await expect(cf7).toHaveClass(/sent/, { timeout: 30_000 });
  } finally {
    mails = out(wpEval(CF7_CLEANUP, { E2E_PLUGIN: CF7_PLUGIN, E2E_FORM: form, E2E_RUN: run }));
  }
  expect(mails).toBe('1');
});

// Forminator: made through its API, the messages as its entries (by its field ids: text-1, email-1…), the page
// with its shortcode, a visitor sending it and reaching the thank-you page.
const FMN_PLUGIN = 'forminator/forminator.php';

const FMN_READ = String.raw`<?php
global $wpdb;
$entries = $wpdb->get_col( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}frmt_form_entry WHERE form_id = %d ORDER BY entry_id", (int) getenv( 'E2E_FORM' ) ) );
$out     = array();
foreach ( $entries as $entry ) {
	$out[] = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}frmt_form_entry_meta WHERE entry_id = %d AND meta_key = 'text-1'", (int) $entry ) );
}
echo "\nE2E=" . wp_json_encode( $out ) . "\n";
`;

const FMN_CLEANUP = String.raw`<?php
global $wpdb;
$id = (int) getenv( 'E2E_FORM' );
if ( $id > 0 ) {
	$entries = $wpdb->get_col( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}frmt_form_entry WHERE form_id = %d", $id ) );
	foreach ( $entries as $entry ) {
		$wpdb->delete( $wpdb->prefix . 'frmt_form_entry_meta', array( 'entry_id' => (int) $entry ) );
	}
	$wpdb->delete( $wpdb->prefix . 'frmt_form_entry', array( 'form_id' => $id ) );
	wp_delete_post( $id, true );
}
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-mail.php' );
delete_option( 'xb_e2e_mail' );
foreach ( get_posts( array( 'post_type' => 'xb_submission', 'post_status' => 'private', 'posts_per_page' => -1, 's' => 'e2e' . getenv( 'E2E_RUN' ) ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('un form di Histeon diventa un form di Forminator: messaggi portati, pagina convertita, invio vero', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: FMN_PLUGIN })) !== 'si', 'Forminator non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  let form = '0';
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: FMN_PLUGIN });
    wpEval(MAIL_ON, {});
    const made = JSON.parse(out(wpEval(SETUP, { E2E_RUN: run, E2E_ADAPTER: 'forminator' })));
    form = String(made.form);
    expect(made.carried).toBe(2);
    await page.context().clearCookies();
    await page.goto(`/?page_id=${made.page}`);
    await expect(page.getByText(`Scrivici ${run}`)).toBeVisible();
    const fmn = page.locator('form.forminator-custom-form');
    await expect(fmn).toBeVisible();
    await fmn.locator('input[name="text-1"]').fill('Carla');
    await fmn.locator('input[name="email-1"]').fill('carla@esempio.test');
    await fmn.locator('textarea[name="textarea-1"]').fill(`Dal test ${run}`);
    await fmn.locator('button.forminator-button-submit').click();
    await page.waitForURL(new RegExp(`grazie-${run}`), { timeout: 30_000 });
    expect(JSON.parse(out(wpEval(FMN_READ, { E2E_FORM: form })))).toEqual(['Anna', 'Bruno', 'Carla']);
  } finally {
    wpEval(FMN_CLEANUP, { E2E_PLUGIN: FMN_PLUGIN, E2E_FORM: form, E2E_RUN: run });
  }
});

// JetFormBuilder: a post of its type made of its field blocks, the messages as its records, its form block in the
// page, a visitor sending it and reaching the thank-you page; the entry saved as a record.
const JFB_PLUGIN = 'jetformbuilder/jet-form-builder.php';

const JFB_READ = String.raw`<?php
global $wpdb;
$records = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jet_fb_records WHERE form_id = %d ORDER BY id", (int) getenv( 'E2E_FORM' ) ) );
$out     = array();
foreach ( $records as $record ) {
	$out[] = $wpdb->get_var( $wpdb->prepare( "SELECT field_value FROM {$wpdb->prefix}jet_fb_records_fields WHERE record_id = %d AND field_name = 'nome'", (int) $record ) );
}
echo "\nE2E=" . wp_json_encode( $out ) . "\n";
`;

const JFB_CLEANUP = String.raw`<?php
global $wpdb;
$id = (int) getenv( 'E2E_FORM' );
if ( $id > 0 ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jet_fb_records WHERE form_id = %d", $id ) ) as $record ) {
		foreach ( array( 'jet_fb_records_fields', 'jet_fb_records_actions', 'jet_fb_records_errors' ) as $table ) {
			$wpdb->delete( $wpdb->prefix . $table, array( 'record_id' => (int) $record ) );
		}
	}
	$wpdb->delete( $wpdb->prefix . 'jet_fb_records', array( 'form_id' => $id ) );
	wp_delete_post( $id, true );
}
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-mail.php' );
delete_option( 'xb_e2e_mail' );
foreach ( get_posts( array( 'post_type' => 'xb_submission', 'post_status' => 'private', 'posts_per_page' => -1, 's' => 'e2e' . getenv( 'E2E_RUN' ) ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('un form di Histeon diventa un form di JetFormBuilder: messaggi portati, pagina convertita, invio vero', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: JFB_PLUGIN })) !== 'si', 'JetFormBuilder non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  let form = '0';
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: JFB_PLUGIN });
    wpEval(MAIL_ON, {});
    const made = JSON.parse(out(wpEval(SETUP, { E2E_RUN: run, E2E_ADAPTER: 'jetformbuilder' })));
    form = String(made.form);
    expect(made.carried).toBe(2);
    await page.context().clearCookies();
    await page.goto(`/?page_id=${made.page}`);
    await expect(page.getByText(`Scrivici ${run}`)).toBeVisible();
    const jfb = page.locator('form.jet-form-builder');
    await expect(jfb).toBeVisible();
    await jfb.locator('input[name="nome"]').fill('Carla');
    await jfb.locator('input[name="email"]').fill('carla@esempio.test');
    await jfb.locator('textarea[name="messaggio"]').fill(`Dal test ${run}`);
    await jfb.locator('.jet-form-builder__submit').click();
    await page.waitForURL(new RegExp(`grazie-${run}`), { timeout: 30_000 });
    expect(JSON.parse(out(wpEval(JFB_READ, { E2E_FORM: form })))).toEqual(['Anna', 'Bruno', 'Carla']);
  } finally {
    wpEval(JFB_CLEANUP, { E2E_PLUGIN: JFB_PLUGIN, E2E_FORM: form, E2E_RUN: run });
  }
});

// Ninja Forms: made with its models, the messages as its submissions, its block in the page (drawn by its script),
// a visitor sending it and reaching the thank-you page.
const NF_PLUGIN = 'ninja-forms/ninja-forms.php';

const NF_READ = String.raw`<?php
$out = array();
foreach ( Ninja_Forms()->form( (int) getenv( 'E2E_FORM' ) )->get_subs() as $sub ) {
	$out[ $sub->get_seq_num() ] = $sub->get_field_value( 'nome' );
}
ksort( $out );
echo "\nE2E=" . wp_json_encode( array_values( $out ) ) . "\n";
`;

const NF_CLEANUP = String.raw`<?php
$id = (int) getenv( 'E2E_FORM' );
if ( $id > 0 ) {
	foreach ( Ninja_Forms()->form( $id )->get_subs() as $sub ) {
		wp_delete_post( (int) $sub->get_id(), true );
	}
	Ninja_Forms()->form( $id )->get()->delete();
}
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-mail.php' );
delete_option( 'xb_e2e_mail' );
foreach ( get_posts( array( 'post_type' => 'xb_submission', 'post_status' => 'private', 'posts_per_page' => -1, 's' => 'e2e' . getenv( 'E2E_RUN' ) ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('un form di Histeon diventa un form di Ninja Forms: messaggi portati, pagina convertita, invio vero', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: NF_PLUGIN })) !== 'si', 'Ninja Forms non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  let form = '0';
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: NF_PLUGIN });
    wpEval(MAIL_ON, {});
    const made = JSON.parse(out(wpEval(SETUP, { E2E_RUN: run, E2E_ADAPTER: 'ninjaforms' })));
    form = String(made.form);
    expect(made.carried).toBe(2);
    await page.context().clearCookies();
    await page.goto(`/?page_id=${made.page}`);
    await expect(page.getByText(`Scrivici ${run}`)).toBeVisible();
    // Ninja Forms draws its form with its script: the fields by their labels.
    await expect(page.getByRole('textbox', { name: /Nome/ })).toBeVisible({ timeout: 30_000 });
    await page.getByRole('textbox', { name: /Nome/ }).fill('Carla');
    await page.getByRole('textbox', { name: /Email/ }).fill('carla@esempio.test');
    await page.getByRole('textbox', { name: /Messaggio/ }).fill(`Dal test ${run}`);
    await page.getByRole('button', { name: 'Invia' }).click();
    await page.waitForURL(new RegExp(`grazie-${run}`), { timeout: 30_000 });
    expect(JSON.parse(out(wpEval(NF_READ, { E2E_FORM: form })))).toEqual(['Anna', 'Bruno', 'Carla']);
  } finally {
    wpEval(NF_CLEANUP, { E2E_PLUGIN: NF_PLUGIN, E2E_FORM: form, E2E_RUN: run });
  }
});

// SureForms: a post of its type made of its field blocks, the messages as entries of its table (by the names it
// gives the fields), its form block in the page, a visitor sending it and reaching the thank-you page.
const SRFM_PLUGIN = 'sureforms/sureforms.php';

const SRFM_READ = String.raw`<?php
global $wpdb;
$rows = $wpdb->get_col( $wpdb->prepare( "SELECT form_data FROM {$wpdb->prefix}srfm_entries WHERE form_id = %d ORDER BY ID", (int) getenv( 'E2E_FORM' ) ) );
$out  = array();
foreach ( $rows as $row ) {
	$data  = json_decode( (string) $row, true );
	$data  = is_array( $data ) ? $data : (array) maybe_unserialize( $row );
	$nome  = null;
	foreach ( $data as $key => $value ) {
		if ( str_ends_with( (string) $key, '-nome' ) ) {
			$nome = array( $key, $value );
		}
	}
	$out[] = $nome;
}
echo "\nE2E=" . wp_json_encode( $out ) . "\n";
`;

const SRFM_CLEANUP = String.raw`<?php
global $wpdb;
$id = (int) getenv( 'E2E_FORM' );
if ( $id > 0 ) {
	$wpdb->delete( $wpdb->prefix . 'srfm_entries', array( 'form_id' => $id ) );
	wp_delete_post( $id, true );
}
@unlink( WPMU_PLUGIN_DIR . '/xb-e2e-mail.php' );
delete_option( 'xb_e2e_mail' );
foreach ( get_posts( array( 'post_type' => 'xb_submission', 'post_status' => 'private', 'posts_per_page' => -1, 's' => 'e2e' . getenv( 'E2E_RUN' ) ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
deactivate_plugins( getenv( 'E2E_PLUGIN' ) );
echo "\nE2E=ok\n";
`;

test('un form di Histeon diventa un form di SureForms: messaggi portati, pagina convertita, invio vero', async ({ page }) => {
  test.skip(out(wpEval(PRESENT, { E2E_PLUGIN: SRFM_PLUGIN })) !== 'si', 'SureForms non è nel WordPress di sviluppo');
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  let form = '0';
  try {
    wpEval(ACTIVATE, { E2E_PLUGIN: SRFM_PLUGIN });
    wpEval(MAIL_ON, {});
    const made = JSON.parse(out(wpEval(SETUP, { E2E_RUN: run, E2E_ADAPTER: 'sureforms' })));
    form = String(made.form);
    expect(made.carried).toBe(2);
    await page.context().clearCookies();
    await page.goto(`/?page_id=${made.page}`);
    await expect(page.getByText(`Scrivici ${run}`)).toBeVisible();
    await expect(page.getByRole('textbox', { name: /Nome/ })).toBeVisible({ timeout: 30_000 });
    await page.getByRole('textbox', { name: /Nome/ }).fill('Carla');
    await page.getByRole('textbox', { name: /Email/ }).fill('carla@esempio.test');
    await page.getByRole('textbox', { name: /Messaggio/ }).fill(`Dal test ${run}`);
    await page.getByRole('button', { name: 'Invia' }).click();
    await page.waitForURL(new RegExp(`grazie-${run}`), { timeout: 30_000 });
    const entries = JSON.parse(out(wpEval(SRFM_READ, { E2E_FORM: form })));
    // The same field name for the messages carried over and the visitor's: SureForms shows them as one field.
    expect(entries.map((e: [string, string]) => e[1])).toEqual(['Anna', 'Bruno', 'Carla']);
    expect(new Set(entries.map((e: [string, string]) => e[0])).size).toBe(1);
  } finally {
    wpEval(SRFM_CLEANUP, { E2E_PLUGIN: SRFM_PLUGIN, E2E_FORM: form, E2E_RUN: run });
  }
});

