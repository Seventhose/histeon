<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Merge;
use XB\Versioning\Preview;
use XB\Versioning\ReleaseChecks;
use XB\Versioning\Schema;
use XB\Versioning\Store;
use XB\Versioning\VersioningException;

final class VersioningTest extends IntegrationTestCase {

	/** A page whose texts have stable uids (a = heading, b = paragraph). */
	private function page( string $heading = 'Titolo', string $text = 'Testo' ): int {
		return $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( $this->tree( $heading, $text ) ),
			)
		);
	}

	/** @return list<array<string, mixed>> */
	private function tree( string $heading, string $text, string $extra_class = '' ): array {
		$attrs = '' === $extra_class ? array() : array( 'class' => $extra_class );
		return array(
			$this->el(
				'section',
				$attrs,
				array(
					$this->el( 'h1', array(), array( $this->text( $heading, 'vtexta01' ) ), 'vtagh101' ),
					$this->el( 'p', array(), array( $this->text( $text, 'vtextb01' ) ), 'vtagp101' ),
				),
				'vtsect01'
			),
		);
	}

	/** @return array<string, string> */
	private function live_texts( int $id ): array {
		return Merge::texts( parse_blocks( $this->content( $id ) ) );
	}

	public function test_saving_is_dev_publishing_goes_live_with_a_release(): void {
		$id = $this->page();
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Nuovo titolo', 'Testo', 'hero' ) ) )->get_status() );
		$this->assertSame( 'Titolo', $this->live_texts( $id )['vtexta01'], 'not live yet' );
		$this->assertContains( 'post:' . $id, array_column( (array) $this->request( 'GET', '/changes' )->get_data(), 'doc' ) );

		$published = $this->publish( array( 'post:' . $id ), 'Prima release' );
		$this->assertSame( 200, $published->get_status() );
		$this->assertSame( 'Nuovo titolo', $this->live_texts( $id )['vtexta01'] );
		$this->assertStringContainsString( '"class":"hero"', $this->content( $id ) );

		$release = Plugin::instance()->versioning->store()->get_release( (int) $published->get_data()['id'] );
		$this->assertNotNull( $release );
		$this->assertArrayHasKey( 'post:' . $id, $release['items'] );
		$this->assertSame( (int) $published->get_data()['id'], (int) get_option( 'xb_current_release' ) );
		$this->assertTrue( $this->request( 'GET', "/documents/$id" )->get_data()['published'] );
		$this->assertSame( 'publish', Plugin::instance()->versioning->store()->audit_log( 1 )[0]['action'] );
	}

	/**
	 * Runs $test with ReleaseChecks::OPTION as it was put back afterwards (not autoloaded, as the plugin writes it).
	 *
	 * @param callable(): void $test
	 */
	private function keeping_last_checks( callable $test ): void {
		$before = get_option( ReleaseChecks::OPTION );
		delete_option( ReleaseChecks::OPTION );
		try {
			$test();
		} finally {
			false === $before ? delete_option( ReleaseChecks::OPTION ) : update_option( ReleaseChecks::OPTION, $before, false );
		}
	}

	/** @return array<string, mixed> The details of the newest audit entry. */
	private function last_audit_details(): array {
		$details = json_decode( Plugin::instance()->versioning->store()->audit_log( 1 )[0]['details'], true );
		return is_array( $details ) ? $details : array();
	}

	public function test_the_outcome_of_the_checks_is_kept_with_the_release(): void {
		$this->keeping_last_checks(
			function (): void {
				$id = $this->page();
				$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Con controlli', 'Testo' ) ) )->get_status() );
				$published = $this->request(
					'POST',
					'/releases',
					array(
						'notes'  => 'test',
						'docs'   => array( 'post:' . $id ),
						'checks' => array(
							'errors'   => 1,
							'warnings' => 3,
							'rules'    => array(
								'img-alt' => 1,
								'noindex' => 1,
								'made-up' => 9,
							),
							'at'       => 1,
							// M-B1: 4 of the 10 pages of the release were checked.
							'pages'    => array(
								'checked' => 4,
								'total'   => 10,
							),
						),
					)
				);
				$this->assertSame( 200, $published->get_status() );
				$expected = array(
					'errors'   => 1,
					'warnings' => 3,
					'rules'    => array(
						'img-alt' => 1,
						'noindex' => 1,
					),
					'pages'    => array(
						'checked' => 4,
						'total'   => 10,
					),
				);
				$this->assertSame( $expected, $this->last_audit_details()['checks'] ?? null, 'in the Registro with the release, unknown rules dropped' );

				$last = ReleaseChecks::last();
				$this->assertNotNull( $last );
				$this->assertSame( $published->get_data()['version'], $last['version'] );
				$this->assertSame( $expected, array_intersect_key( $last, $expected ) );
				$this->assertGreaterThan( time() - 120, strtotime( $last['at'] ), 'dated by the server, not by the browser' );
				$this->assertFalse( wp_load_alloptions()[ ReleaseChecks::OPTION ] ?? false, 'not autoloaded' );

				$listed = array_column( (array) $this->request( 'GET', '/releases' )->get_data()['releases'], 'checks', 'id' );
				$this->assertSame(
					array(
						'errors'   => 1,
						'warnings' => 3,
						'pages'    => array(
							'checked' => 4,
							'total'   => 10,
						),
					),
					$listed[ (int) $published->get_data()['id'] ] ?? null,
					'the Versioni page reads it, with the pages checked of those of the release'
				);
			}
		);
	}

	public function test_checks_with_text_instead_of_numbers_are_dropped_and_the_release_still_goes_out(): void {
		$this->keeping_last_checks(
			function (): void {
				update_option(
					ReleaseChecks::OPTION,
					array(
						'version'  => '9.9.9',
						'at'       => '2026-01-01T00:00:00+00:00',
						'errors'   => 0,
						'warnings' => 2,
						'rules'    => array(),
					),
					false
				);
				$id = $this->page();
				$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Testo al posto dei numeri', 'Testo' ) ) )->get_status() );
				$published = $this->request(
					'POST',
					'/releases',
					array(
						'notes'  => 'test',
						'docs'   => array( 'post:' . $id ),
						'checks' => array(
							'errors'   => 'nessuno',
							'warnings' => 0,
						),
					)
				);
				$this->assertSame( 200, $published->get_status(), 'a wrong outcome never stops a release' );
				$this->assertSame( 'Testo al posto dei numeri', $this->live_texts( $id )['vtexta01'] );
				$this->assertArrayNotHasKey( 'checks', $this->last_audit_details() );
				$this->assertSame( '9.9.9', ReleaseChecks::last()['version'] ?? null, 'the last real outcome is not replaced' );
				$listed = array_column( (array) $this->request( 'GET', '/releases' )->get_data()['releases'], 'checks', 'id' );
				$this->assertNull( $listed[ (int) $published->get_data()['id'] ] ?? null );
			}
		);
	}

	public function test_a_release_without_checks_writes_no_outcome(): void {
		$this->keeping_last_checks(
			function (): void {
				$id = $this->page();
				$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Da wp-admin', 'Testo' ) ) )->get_status() );
				// As wp-admin, a file or Histeon CMS publish: no outcome at all.
				Plugin::instance()->versioning->publish( null, 'senza controlli', array( 'post:' . $id ) );
				$this->assertArrayNotHasKey( 'checks', $this->last_audit_details() );
				$this->assertFalse( get_option( ReleaseChecks::OPTION ) );
				$this->assertNull( ReleaseChecks::last() );
			}
		);
	}

	public function test_the_last_outcome_is_checked_again_when_read(): void {
		$this->keeping_last_checks(
			function (): void {
				$good = array(
					'version'  => '1.2.3',
					'at'       => '2026-11-13T08:00:00+00:00',
					'errors'   => 0,
					'warnings' => 1,
					'rules'    => array(
						'noindex' => 1,
						'made-up' => 1,
					),
				);
				update_option( ReleaseChecks::OPTION, $good, false );
				$last = ReleaseChecks::last();
				$this->assertNotNull( $last );
				$this->assertSame( array( 'noindex' => 1 ), $last['rules'] );
				foreach ( array( array( 'errors' => '0' ), array( 'version' => '<b>1</b>' ), array( 'at' => 'ieri' ), array( 'version' => 12 ) ) as $bad ) {
					update_option( ReleaseChecks::OPTION, array_merge( $good, $bad ), false );
					$this->assertNull( ReleaseChecks::last(), (string) wp_json_encode( $bad ) );
				}
				update_option( ReleaseChecks::OPTION, 'tutto bene', false );
				$this->assertNull( ReleaseChecks::last() );
			}
		);
	}

	public function test_a_page_using_a_template_not_published_yet_still_goes_live(): void {
		$id = $this->page();
		// A Histeon template sent by Histeon CMS is a draft until a release: the theme does not list it yet.
		update_post_meta( $id, '_wp_page_template', 'histos-template-not-published' );
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Con template', 'Testo' ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ), 'Release con template' )->get_status() );
		$this->assertSame( 'Con template', $this->live_texts( $id )['vtexta01'] );
		$this->assertSame( 'histos-template-not-published', get_post_meta( $id, '_wp_page_template', true ), 'the assignment is left as it was' );
	}

	public function test_client_texts_edited_in_gutenberg_are_merged_not_lost(): void {
		$id = $this->page();
		// The developer starts working on dev (structure change)…
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Titolo', 'Testo', 'restyled' ) ) )->get_status() );
		// …meanwhile the client fixes a typo in Gutenberg (a live edit).
		$editor = $this->user( 'editor' );
		wp_set_current_user( $editor );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $this->markup( $this->tree( 'Titolo', 'Testo corretto dal cliente' ) ) ),
			)
		);
		wp_set_current_user( 1 );

		$doc = $this->request( 'GET', "/documents/$id" )->get_data();
		$this->assertSame( 1, $doc['merged'], 'the builder sees the client fix' );
		$this->assertStringContainsString( 'Testo corretto dal cliente', (string) $doc['content'] );
		$this->assertStringContainsString( 'restyled', (string) $doc['content'] );

		// Publishing without reloading the builder still keeps the client's text.
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ) )->get_status() );
		$this->assertSame( 'Testo corretto dal cliente', $this->live_texts( $id )['vtextb01'] );
		$this->assertStringContainsString( 'restyled', $this->content( $id ) );
	}

	public function test_conflicting_text_edits_are_reported(): void {
		$id = $this->page();
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Titolo dev', 'Testo' ) ) )->get_status() );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $this->markup( $this->tree( 'Titolo cliente', 'Testo' ) ) ),
			)
		);
		$doc = $this->request( 'GET', "/documents/$id" )->get_data();
		$this->assertCount( 1, $doc['conflicts'] );
		$this->assertSame( 'vtexta01', $doc['conflicts'][0]['uid'] );
		$this->assertStringContainsString( 'Titolo dev', (string) $doc['content'], 'dev wins, the conflict is shown' );
	}

	public function test_rollback_restores_design_and_keeps_newer_texts(): void {
		$id = $this->page();
		$this->save_dev( $id, $this->markup( $this->tree( 'Titolo', 'Testo', 'v1' ) ) );
		$v1 = (int) $this->publish( array( 'post:' . $id ) )->get_data()['id'];
		$this->save_dev( $id, $this->markup( $this->tree( 'Titolo', 'Testo', 'v2' ) ) );
		$this->publish( array( 'post:' . $id ) );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $this->markup( $this->tree( 'Titolo', 'Testo aggiornato', 'v2' ) ) ),
			)
		);

		$restored = $this->request( 'POST', "/releases/$v1/restore" );
		$this->assertSame( 200, $restored->get_status() );
		$this->assertStringContainsString( '"class":"v1"', $this->content( $id ) );
		$this->assertSame( 'Testo aggiornato', $this->live_texts( $id )['vtextb01'], 'content written after v1 survives' );
		$release = Plugin::instance()->versioning->store()->get_release( (int) $restored->get_data()['id'] );
		$this->assertSame( $v1, $release['restored_from'] ?? null );

		$this->request( 'POST', "/releases/$v1/restore", array( 'keepContent' => false ) );
		$this->assertSame( 'Testo', $this->live_texts( $id )['vtextb01'], 'or the exact old content, on request' );
	}

	public function test_publishing_requires_xb_publish(): void {
		$id      = $this->page();
		$builder = $this->user( 'editor' );
		$user    = get_userdata( $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'X', 'Y' ) ) )->get_status() );
		$this->assertSame( 403, $this->publish()->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/releases/1/restore' )->get_status() );
		$this->assertSame( 'Titolo', $this->live_texts( $id )['vtexta01'] );
	}

	public function test_a_failing_release_changes_nothing(): void {
		$id     = $this->page();
		$before = $this->content( $id );
		$this->save_dev( $id, $this->markup( $this->tree( 'Cambiato', 'Testo' ) ) );
		// A settings change that cannot be applied (framework not installed) in the same release.
		$versioning = Plugin::instance()->versioning;
		$versioning->ensure( 'settings' );
		$store  = $versioning->store();
		$commit = $store->commit( 'settings', Store::DEV, array( 'framework' => 'framework-inesistente' ), $store->head( 'settings', Store::DEV ), $store->head( 'settings', Store::PROD ), 'test' );
		$store->move( 'settings', Store::DEV, $commit['id'] );
		$prod_page = $store->head( 'post:' . $id, Store::PROD );
		$releases  = count( $store->releases() );

		$response = $this->publish( array( 'post:' . $id, 'settings' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $before, $this->content( $id ), 'the page change was rolled back' );
		$this->assertSame( $prod_page, $store->head( 'post:' . $id, Store::PROD ) );
		$this->assertCount( $releases, $store->releases() );
	}

	public function test_tampered_commits_are_detected(): void {
		global $wpdb;
		$id   = $this->page();
		$head = Plugin::instance()->versioning->ensure( 'post:' . $id );
		$wpdb->update( Schema::table( 'commits' ), array( 'payload' => '{"content":"<script>"}' ), array( 'id' => $head ) );
		$this->expectException( VersioningException::class );
		$this->expectExceptionMessage( 'non si legge' );
		Plugin::instance()->versioning->store()->get( $head );
	}

	public function test_versions_are_validated(): void {
		$id = $this->page();
		$this->save_dev( $id, $this->markup( $this->tree( 'A', 'B' ) ) );
		$this->assertSame( 400, $this->request( 'POST', '/releases', array( 'version' => '1.0; DROP', 'notes' => '' ) )->get_status() );
		$ok = $this->request( 'POST', '/releases', array( 'version' => '9.9.9', 'notes' => '' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->save_dev( $id, $this->markup( $this->tree( 'C', 'D' ) ) );
		$this->assertSame( 409, $this->request( 'POST', '/releases', array( 'version' => '9.9.9', 'notes' => '' ) )->get_status() );
	}

	/** Note 114 of the 3 November audit: untrusted settings in development block the preview's scripts too. */
	public function test_untrusted_development_settings_block_preview_scripts(): void {
		$id   = $this->page();
		$copy = \XB\Plugin::instance()->versioning->working_copy( \XB\Versioning\Documents::SETTINGS );
		\XB\Plugin::instance()->versioning->save_dev( \XB\Versioning\Documents::SETTINGS, array( 'reset' => true ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'], 'test', false, true );
		$_GET[ Preview::PARAM ]  = Preview::token( $id );
		$_SERVER['REQUEST_URI'] = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
		try {
			Preview::detect();
			$this->assertTrue( Preview::active() );
			$this->assertTrue( Preview::scripts_blocked() );
		} finally {
			Preview::reset();
			unset( $_GET[ Preview::PARAM ] );
		}
	}

	public function test_preview_tokens(): void {
		$id    = $this->page();
		$token = Preview::token( $id );
		$this->assertSame( $id, Preview::validate( $token ) );
		$this->assertNull( Preview::validate( 'x' ) );
		$parts = explode( '.', $token );
		$this->assertNull( Preview::validate( ( time() - 10 ) . '.' . $parts[1] . '.' . $parts[2] . '.' . $parts[3] ), 'expired' );
		$this->assertNull( Preview::validate( $parts[0] . '.' . ( $id + 1 ) . '.' . $parts[2] . '.' . $parts[3] ), 'bound to the post' );
		$this->assertNull( Preview::validate( $parts[0] . '.' . $parts[1] . '.' . ( (int) $parts[2] + 1 ) . '.' . $parts[3] ), 'bound to the issuer' );

		$issuer = $this->user( 'editor' );
		$user   = get_userdata( $issuer );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $issuer );
		$theirs = Preview::token( $id );
		$this->assertSame( $id, Preview::validate( $theirs ) );
		$user->remove_cap( 'xb_build' );
		$this->assertNull( Preview::validate( $theirs ), 'stops working when the issuer loses access' );

		Preview::revoke();
		$this->assertNull( Preview::validate( $token ), 'revoked' );
	}

	public function test_preview_link_shows_dev_without_login_and_is_not_cached(): void {
		$id = $this->page();
		$this->save_dev( $id, $this->markup( $this->tree( 'Versione in sviluppo', 'Testo' ) ) );
		$url  = str_replace( home_url(), 'http://wordpress', (string) $this->request( 'GET', '/preview-link', array( 'post' => $id ) )->get_data()['url'] );
		$host = array( 'Host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . (string) wp_parse_url( home_url(), PHP_URL_PORT ) );

		$preview = wp_remote_get( $url, array( 'headers' => $host, 'redirection' => 0 ) );
		$this->assertIsArray( $preview );
		$this->assertStringContainsString( 'Versione in sviluppo', wp_remote_retrieve_body( $preview ) );
		$this->assertStringContainsString( 'noindex', implode( ',', (array) wp_remote_retrieve_header( $preview, 'x-robots-tag' ) ) );
		// The preview's own robots tag is marked, so the page checks of the Publish window tell it from the page's (V1.4).
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow" data-xb-preview>', wp_remote_retrieve_body( $preview ) );
		$this->assertStringContainsString( 'no-cache', implode( ',', (array) wp_remote_retrieve_header( $preview, 'cache-control' ) ) );

		$public = wp_remote_get( (string) strtok( $url, '?' ), array( 'headers' => $host, 'redirection' => 0 ) );
		$this->assertIsArray( $public );
		$this->assertStringNotContainsString( 'Versione in sviluppo', wp_remote_retrieve_body( $public ) );

		$forged = wp_remote_get( (string) strtok( $url, '?' ) . '?xb_preview=' . ( time() + 3600 ) . '.1.1.deadbeef', array( 'headers' => $host, 'redirection' => 0 ) );
		$this->assertIsArray( $forged );
		$this->assertStringNotContainsString( 'Versione in sviluppo', wp_remote_retrieve_body( $forged ) );

		// A token for another page does not unlock this one.
		$other = $this->page();
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );
		$param = is_string( $params['xb_preview'] ?? null ) ? $params['xb_preview'] : '';
		$wrong = wp_remote_get( str_replace( home_url(), 'http://wordpress', (string) get_permalink( $other ) ) . '?xb_preview=' . $param, array( 'headers' => $host, 'redirection' => 0 ) );
		$this->assertIsArray( $wrong );
		$this->assertStringNotContainsString( 'Anteprima di sviluppo Histeon', wp_remote_retrieve_body( $wrong ) );

		// Unpublished content from an author without unfiltered_html: the preview runs without scripts.
		$builder = $this->user( 'author' );
		$user    = get_userdata( $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		$user->add_cap( 'edit_others_pages' );
		$user->add_cap( 'edit_published_pages' );
		wp_set_current_user( $builder );
		$this->save_dev( $id, $this->markup( $this->tree( 'Dal builder limitato', 'Testo' ) ) );
		$link = str_replace( home_url(), 'http://wordpress', (string) $this->request( 'GET', '/preview-link', array( 'post' => $id ) )->get_data()['url'] );
		$csp  = wp_remote_get( $link, array( 'headers' => $host, 'redirection' => 0 ) );
		$this->assertIsArray( $csp );
		$this->assertStringContainsString( "script-src 'none'", implode( ',', (array) wp_remote_retrieve_header( $csp, 'content-security-policy' ) ) );
	}

	public function test_preview_link_gives_the_live_page_only_when_there_is_one(): void {
		$id   = $this->page();
		$data = $this->request( 'GET', '/preview-link', array( 'post' => $id ) )->get_data();
		$this->assertSame( get_permalink( $id ), $data['live'], 'a published page: the address visitors see' );

		$draft = $this->post( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$this->assertNull( $this->request( 'GET', '/preview-link', array( 'post' => $draft ) )->get_data()['live'], 'not online yet' );

		$block = $this->post( array( 'post_type' => 'wp_block' ) );
		$this->assertNull( $this->request( 'GET', '/preview-link', array( 'post' => $block ) )->get_data()['live'], 'a component has no page of its own' );
	}

	/** A preview with scripts blocked leaves them out of the page: nothing for the browser to refuse. */
	public function test_a_blocked_preview_prints_no_scripts(): void {
		$html = '<head><link rel="modulepreload" href="/x.js"><script type="importmap">{}</script></head><body><p>Testo</p><script type="module" src="/view.js"></script><SCRIPT>alert(1)</SCRIPT ></body>';
		$this->assertSame( '<head></head><body><p>Testo</p></body>', \XB\Versioning\Preview::without_scripts( $html ) );
	}

}
