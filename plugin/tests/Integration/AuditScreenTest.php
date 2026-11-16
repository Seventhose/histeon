<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\AuditScreen;
use XB\Plugin;

/** Histeon → Registro (docs/spec/audit.md). */
final class AuditScreenTest extends IntegrationTestCase {

	/** @param array<string, mixed> $details */
	private function log( string $action, string $object, array $details = array() ): void {
		Plugin::instance()->versioning->store()->audit( $action, $object, $details );
	}

	public function test_filters_and_pages(): void {
		$editor = $this->user( 'editor' );
		$this->log( 'export_site', 'site', array( 'posts' => 3 ) );
		wp_set_current_user( $editor );
		$this->log( 'deploy_config', 'cloudflare', array( 'zone' => 'z' ) );
		wp_set_current_user( 1 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->log( 'preview_link', 'post:1', array( 'n' => $i ) );
		}
		$store = Plugin::instance()->versioning->store();

		$all = $store->audit_page( array(), 1, 2 );
		$this->assertGreaterThanOrEqual( 5, $all['total'] );
		$this->assertCount( 2, $all['rows'] );
		$this->assertSame( 'preview_link', $all['rows'][0]['action'], 'newest first' );
		$this->assertSame( array( 'n' => 2 ), json_decode( $all['rows'][0]['details'], true ) );
		$second = $store->audit_page( array(), 2, 2 );
		$this->assertNotSame( $all['rows'][0]['id'], $second['rows'][0]['id'] );

		$by_action = $store->audit_page( array( 'action' => 'deploy_config' ) );
		$this->assertSame( array( 'deploy_config' ), array_values( array_unique( array_column( $by_action['rows'], 'action' ) ) ) );
		$by_user = $store->audit_page( array( 'user' => $editor ) );
		$this->assertSame( 1, $by_user['total'] );
		$this->assertSame( 0, $store->audit_page( array( 'from' => '2000-01-01', 'to' => '2000-01-02' ) )['total'], 'outside the days' );
		$today = current_time( 'Y-m-d' );
		$this->assertGreaterThanOrEqual( 5, $store->audit_page( array( 'from' => $today, 'to' => $today ) )['total'] );
		$this->assertSame( 0, $store->audit_page( array( 'action' => "x' OR '1'='1" ) )['total'], 'values are bound, never SQL' );

		$facets = $store->audit_facets();
		$this->assertContains( 'export_site', $facets['actions'] );
		$this->assertContains( $editor, $facets['users'] );
	}

	public function test_the_screen_prints_everything_as_text_and_only_for_administrators(): void {
		$this->log( 'import_site', '<img src=x onerror=alert(1)>', array( 'site' => '<script>alert(2)</script>', 'docs' => array( 'post:1', 'post:2' ) ) );
		ob_start();
		AuditScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Sito importato (trasloco)', $html );
		$this->assertStringNotContainsString( '<script>alert(2)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(2)&lt;/script&gt;', $html );
		$this->assertStringContainsString( 'docs: post:1, post:2', $html );

		$editor = $this->user( 'editor' );
		$user   = get_user_by( 'id', $editor );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $editor );
		add_filter( 'wp_die_handler', static fn () => static function ( $message ): void {
			throw new \RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
		} );
		try {
			AuditScreen::render();
			$this->fail( 'an editor read the log' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'amministratore', $e->getMessage() );
		} finally {
			remove_all_filters( 'wp_die_handler' );
		}
	}

	public function test_details_and_objects_in_words(): void {
		$this->assertSame( array( 'posts: 3', 'published: sì', 'docs: a, b, c, d, e, f, g, h (e altri 2)' ), AuditScreen::detail_lines( (string) wp_json_encode( array( 'posts' => 3, 'published' => true, 'docs' => range( 'a', 'j' ) ) ) ) );
		$this->assertSame( array(), AuditScreen::detail_lines( '' ) );
		$this->assertSame( 'Release pubblicata', AuditScreen::label( 'publish' ) );
		$this->assertSame( 'qualcosa_di_nuovo', AuditScreen::label( 'qualcosa_di_nuovo' ), 'an operation Histeon does not know shows its key' );
		$this->assertSame( 'release:999999', AuditScreen::object_label( 'release:999999' ) );
		$this->assertSame( 'Stili', AuditScreen::object_label( 'styles' ) );
	}

	public function test_the_export_neutralises_formulas(): void {
		$this->assertSame( "'=HYPERLINK(\"x\")", AuditScreen::cell( '=HYPERLINK("x")' ) );
		$this->assertSame( "'+1", AuditScreen::cell( '+1' ) );
		$this->assertSame( "'-1", AuditScreen::cell( '-1' ) );
		$this->assertSame( "'@SUM(A1)", AuditScreen::cell( '@SUM(A1)' ) );
		$this->assertSame( 'Release 1.2', AuditScreen::cell( 'Release 1.2' ) );
	}
}
