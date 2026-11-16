<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Render\ComponentRegistry;
use XB\Scripts\Scripts;
use XB\Versioning\Documents;
use XB\Versioning\Store;
use XB\Versioning\Transfer;

/** Release diff, export and import (docs/spec/versioning.md §8). */
final class TransferTest extends IntegrationTestCase {

	/** Files built by hand here are unsigned: accepted explicitly, as an administrator does in the preview (P8). */
	private const UNSIGNED = array( 'allowUnsigned' => true );

	private function published_release( int $page ): int {
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->el( 'p', array( 'class' => 'x' ), array( $this->text( 'Vai su http://other.test/chi-siamo/' ) ) ) ) ) )->get_status() );
		$response = $this->publish( array( Documents::for_post( $page ) ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return (int) $response->get_data()['id'];
	}

	public function test_diff_of_a_change_is_scoped_to_what_the_user_may_see(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->text( 'prima' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'dopo' ) ) ) )->get_status() );
		$res = $this->request( 'GET', '/changes/diff', array( 'doc' => Documents::for_post( $page ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringContainsString( 'prima', (string) $res->get_data()['prod']['content'] );
		$this->assertStringContainsString( 'dopo', (string) $res->get_data()['dev']['content'] );
		$this->assertSame( 403, $this->request( 'GET', '/changes/diff', array( 'doc' => 'post:999999' ) )->get_status() );

		$author = $this->user( 'author' );
		$user = get_user_by( 'id', $author );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $author );
		$this->assertSame( 403, $this->request( 'GET', '/changes/diff', array( 'doc' => Documents::for_post( $page ) ) )->get_status(), 'someone else\'s page' );
	}

	public function test_export_is_portable_and_hashed(): void {
		$page    = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-export-test', 'post_title' => 'Esportata' ) );
		$release = $this->published_release( $page );
		$file    = Transfer::instance()->export( $release );
		$this->assertSame( 'xb-release', $file['format'] );
		// Other tracked documents may be in the release too (the dev site is shared with the e2e suite).
		$post = array_values( array_filter( $file['documents'], static fn ( array $d ): bool => 'post' === $d['kind'] && 'xb-export-test' === $d['key'] ) );
		$this->assertCount( 1, $post );
		$this->assertSame( 'page', $post[0]['type'] );
		$this->assertSame( 64, strlen( $file['hash'] ) );
		Transfer::validate( $file );

		$tampered                                   = $file;
		$tampered['documents'][0]['payload']['x'] = 'y';
		$this->expectException( \InvalidArgumentException::class );
		Transfer::validate( $tampered );
	}

	/** A draft has no slug yet: its key in the file is the slug of its title, or `page-<id>`, and the file stays valid. */
	public function test_a_draft_without_slug_exports_a_valid_key(): void {
		$named   = $this->post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Bozza senza slug' ) );
		$unnamed = $this->post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => '' ) );
		$this->assertSame( '', get_post_field( 'post_name', $named ) );
		$this->assertSame( 200, $this->save_dev( $named, $this->markup( array( $this->text( 'a' ) ) ) )->get_status() );
		$this->assertSame( 200, $this->save_dev( $unnamed, $this->markup( array( $this->text( 'b' ) ) ) )->get_status() );
		$response = $this->publish( array( Documents::for_post( $named ), Documents::for_post( $unnamed ) ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$file = Transfer::instance()->export( (int) $response->get_data()['id'] );
		$keys = array_column( array_filter( $file['documents'], static fn ( array $d ): bool => 'post' === $d['kind'] ), 'key' );
		$this->assertContains( 'bozza-senza-slug', $keys );
		$this->assertContains( 'page-' . $unnamed, $keys );
		Transfer::validate( $file );
	}

	public function test_invalid_files_are_refused(): void {
		$base = array( 'format' => 'xb-release', 'version' => 1, 'site' => 'https://a.test/', 'documents' => array() );
		$base['hash'] = hash( 'sha256', '[]' );
		Transfer::validate( $base );
		$cases = array(
			array( 'format' => 'other' ) + $base,
			array( 'version' => 2 ) + $base,
			array( 'site' => 'javascript:alert(1)' ) + $base,
			array( 'documents' => array( array( 'kind' => 'post', 'type' => 'page', 'key' => '../x', 'payload' => array( 'content' => '' ) ) ) ) + $base,
			array( 'documents' => array( array( 'kind' => 'post', 'type' => 'wp_global_styles', 'key' => 'x', 'payload' => array( 'content' => '' ) ) ) ) + $base,
		);
		foreach ( $cases as $i => $case ) {
			if ( array() !== $case['documents'] ) {
				$case['hash'] = hash( 'sha256', (string) wp_json_encode( $case['documents'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			}
			try {
				Transfer::validate( $case );
				$this->fail( "case $i accepted" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * @param list<array<string, mixed>> $documents
	 * @return array<string, mixed>
	 */
	private function file( array $documents ): array {
		return array(
			'format'    => 'xb-release',
			'version'   => 1,
			'site'      => 'http://other.test/',
			'documents' => $documents,
			'hash'      => hash( 'sha256', (string) wp_json_encode( $documents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
			'release'   => array( 'version' => '9.9.9', 'notes' => '' ),
		);
	}

	public function test_import_lands_in_dev_rewrites_the_site_and_creates_what_is_missing(): void {
		$existing = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-imp-esistente', 'post_content' => $this->markup( array( $this->text( 'vecchio' ) ) ) ) );
		$content  = $this->markup( array( $this->text( 'Link http://other.test/pagina/' ) ) );
		$file     = $this->file(
			array(
				array( 'kind' => 'post', 'type' => 'page', 'key' => 'xb-imp-esistente', 'title' => 'Esistente', 'payload' => array( 'content' => $content, 'script' => '' ) ),
				array( 'kind' => 'post', 'type' => 'page', 'key' => 'xb-imp-nuova', 'title' => 'Nuova', 'payload' => array( 'content' => $content, 'script' => '' ) ),
				array( 'kind' => 'post', 'type' => 'wp_block', 'key' => 'xb-imp-card', 'title' => 'Card', 'payload' => array( 'content' => $content, 'script' => '', 'ref' => 'xb-imp-card', 'schema' => array( 'props' => array() ) ) ),
			)
		);
		$plan = Transfer::instance()->import( $file, false );
		$this->assertSame( array( 'aggiorna', 'crea', 'crea' ), array_column( $plan['documents'], 'action' ) );
		$before = ComponentRegistry::owner( 'xb-imp-card' );
		$this->assertNull( $before, 'a dry run writes nothing' );

		$report = Transfer::instance()->import( $file, true, self::UNSIGNED );
		$this->assertTrue( $report['written'] );
		$store = Plugin::instance()->versioning->store();
		$dev   = $store->get( (string) $store->head( Documents::for_post( $existing ), Store::DEV ) );
		$this->assertNotNull( $dev );
		$this->assertStringContainsString( untrailingslashit( home_url() ) . '/pagina/', (string) $dev['payload']['content'], 'the source site address is rewritten' );
		$this->assertStringContainsString( 'vecchio', $this->content( $existing ), 'nothing is live before a release' );

		$new = get_posts( array( 'post_type' => 'page', 'name' => 'xb-imp-nuova', 'post_status' => 'any', 'numberposts' => 1 ) );
		$this->assertCount( 1, $new );
		$this->track( $new[0]->ID );
		$this->assertSame( 'draft', $new[0]->post_status );
		$card = ComponentRegistry::owner( 'xb-imp-card' );
		$this->assertNotNull( $card );
		$this->track( (int) $card );
		$again = array_column( Transfer::instance()->import( $file, false )['documents'], 'action' );
		$this->assertSame( array( 'aggiorna', 'aggiorna', 'aggiorna' ), $again, 'a second run finds what the first created' );
	}

	public function test_import_uses_the_importers_trust_level(): void {
		add_role( 'xb_test_manager', 'XB test', array( 'read' => true, 'manage_options' => true, 'xb_build' => true, 'edit_pages' => true, 'edit_others_pages' => true, 'edit_published_pages' => true, 'publish_pages' => true ) );
		$manager = $this->user( 'xb_test_manager' );
		$page    = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-imp-trust' ) );
		$hostile = $this->markup( array( $this->el( 'div', array( 'onclick' => 'alert(1)' ), array( $this->text( 'x' ) ) ) ) );
		wp_set_current_user( $manager );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$report = Transfer::instance()->import( $this->file( array( array( 'kind' => 'post', 'type' => 'page', 'key' => 'xb-imp-trust', 'title' => 'T', 'payload' => array( 'content' => $hostile, 'script' => 'alert(2)' ) ) ) ), true, self::UNSIGNED );
		wp_set_current_user( 1 );
		remove_role( 'xb_test_manager' );
		$this->assertSame( 'errore', $report['documents'][0]['action'], 'JavaScript from an importer without unfiltered_html is refused' );
		$this->assertSame( '', Scripts::get( $page ) );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/releases/import', array( 'data' => $this->file( array() ), 'write' => false ) )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/releases/1/export' )->get_status() );
	}

	public function test_settings_bring_their_validated_parts_and_admin_only_ones_need_an_administrator(): void {
		$settings = array(
			'kind'    => Documents::SETTINGS,
			'payload' => array(
				'tokens'       => array( 'xb-non-esiste' => '1px' ),
				'content'      => array( 'types' => array(), 'taxonomies' => array() ),
				'publicFields' => array(),
			),
		);
		$admin = Transfer::instance()->import( $this->file( array( $settings ) ), false );
		$this->assertNotEmpty( array_filter( $admin['issues'], static fn ( string $i ): bool => str_contains( $i, 'xb-non-esiste' ) ), 'an unknown token is refused with the reason' );
		$this->assertSame( array(), array_filter( $admin['issues'], static fn ( string $i ): bool => str_contains( $i, 'solo un amministratore' ) ) );

		$editor = $this->user( 'editor' );
		$user   = get_user_by( 'id', $editor );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $editor );
		$report = Transfer::instance()->import( $this->file( array( $settings ) ), false );
		$this->assertCount( 2, array_filter( $report['issues'], static fn ( string $i ): bool => str_contains( $i, 'solo un amministratore' ) ), 'content types and public fields' );
	}
}
