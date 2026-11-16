<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Remote\Link;
use XB\Remote\Receiver;
use XB\Remote\Sender;
use XB\Versioning\Documents;
use XB\Versioning\SiteTransfer;

/** Staging → production (docs/spec/remote.md). One WordPress plays both sites; HTTP goes straight to REST. */
final class RemoteTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$_SERVER['HTTPS'] = 'on';
		// A live site, whatever the dev stack says (plain HTTP refused).
		add_filter( 'xb_remote_plain_http', '__return_false' );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTPS'] );
		Link::revoke_inbound();
		Link::forget_outbound();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'xb_remote_plain_http' );
		parent::tearDown();
	}

	/** The pairing code production shows, created as if production lived at https://prod.test. */
	private function code( bool $publish = false ): string {
		$prod = static fn (): string => 'https://prod.test';
		add_filter( 'home_url', $prod );
		$code = Link::create_inbound( $publish, 'staging.test' );
		remove_filter( 'home_url', $prod );
		return $code;
	}

	/** Sends HTTP requests to this site's REST API instead of the network. */
	private function loopback(): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, array $args, string $url ) {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$request = new \WP_REST_Request( 'POST', is_string( $query['rest_route'] ?? null ) ? $query['rest_route'] : '' );
				$request->set_body( (string) $args['body'] );
				foreach ( $args['headers'] as $name => $value ) {
					$request->set_header( $name, $value );
				}
				$user = get_current_user_id();
				wp_set_current_user( 0 );
				$response = rest_do_request( $request );
				wp_set_current_user( $user );
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode( rest_get_server()->response_to_data( $response, false ) ),
					'response' => array( 'code' => $response->get_status(), 'message' => '' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/** @return array{secret: string, user: int, publish: bool, label: string, created: int, last: int, origin: string} */
	private function inbound(): array {
		$link = Link::inbound();
		$this->assertNotNull( $link );
		return $link;
	}

	public function test_pairing_code_holds_the_address_and_a_secret_kept_encrypted(): void {
		$code = $this->code();
		$this->assertStringStartsWith( Link::CODE_PREFIX, $code );
		$stored = get_option( Link::INBOUND );
		$this->assertIsArray( $stored );
		$this->assertStringStartsWith( 'v1:', $stored['secret'], 'encrypted at rest' );
		$this->assertNotNull( Link::inbound() );
		$this->assertSame( 'https://prod.test', Link::save_outbound( $code ) );
		$outbound = Link::outbound();
		$this->assertNotNull( $outbound );
		$this->assertSame( $this->inbound()['secret'], $outbound['secret'] );

		foreach ( array( 'nonsense', Link::CODE_PREFIX . 'e30', $this->code_for( 'http://plain.test' ), $this->code_for( (string) home_url() ), $this->code_for( 'https://prod.test/?x=1' ), $this->code_for( 'https://user:pw@prod.test' ), $this->code_for( 'javascript:alert(1)' ) ) as $bad ) {
			try {
				Link::save_outbound( $bad );
				$this->fail( "accepted: $bad" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	private function code_for( string $url ): string {
		return Link::CODE_PREFIX . rtrim( strtr( base64_encode( (string) wp_json_encode( array( 'url' => $url, 'secret' => rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ) ) ) ), '+/', '-_' ), '=' );
	}

	public function test_signatures_refuse_altered_old_and_replayed_requests(): void {
		$this->code();
		$secret = $this->inbound()['secret'];
		$route  = Receiver::route( Receiver::PLAN );
		$h      = Link::headers( $secret, 'POST', $route, 'body' );
		$check  = static fn ( array $h, string $body = 'body', string $r = '' ): string => Link::verify( 'POST', '' === $r ? $route : $r, $h['X-Histos-Time'], $h['X-Histos-Nonce'], $h['X-Histos-Signature'], $body );
		$this->assertSame( '', $check( $h ) );
		$this->assertSame( 'replay', $check( $h ), 'a nonce works once' );
		$this->assertSame( 'signature', $check( Link::headers( $secret, 'POST', $route, 'body' ), 'other body' ) );
		$this->assertSame( 'signature', $check( Link::headers( $secret, 'POST', $route, 'body' ), 'body', Receiver::route( Receiver::IMPORT ) ), 'a dry run cannot be replayed as an import' );
		$import = Receiver::route( Receiver::IMPORT );
		$this->assertSame( 'signature', $check( Link::headers( $secret, 'POST', $import, 'body' ), 'body', Receiver::route( Receiver::IMPORT_DELETE ) ), 'nor an import as one that trashes' );
		$this->assertSame( 'signature', $check( Link::headers( random_bytes( 32 ), 'POST', $route, 'body' ) ) );
		$old  = time() - Link::SKEW - 5;
		$nonce = bin2hex( random_bytes( 16 ) );
		$this->assertSame( 'time', Link::verify( 'POST', $route, (string) $old, $nonce, Link::signature( $secret, 'POST', $route, $old, $nonce, 'body' ), 'body' ) );
		$this->assertSame( 'format', Link::verify( 'POST', $route, (string) time(), 'x', str_repeat( 'a', 64 ), 'body' ) );
		Link::revoke_inbound();
		$this->assertSame( 'no link', $check( Link::headers( $secret, 'POST', $route, 'body' ) ) );
	}

	public function test_the_routes_need_a_valid_signature_https_and_an_administrator_behind_the_link(): void {
		$this->code();
		$secret  = $this->inbound()['secret'];
		$route   = Receiver::route( Receiver::PLAN );
		$request = static function ( array $headers, string $body = 'x' ) use ( $route ): \WP_REST_Response {
			$r = new \WP_REST_Request( 'POST', $route );
			$r->set_body( $body );
			foreach ( $headers as $k => $v ) {
				$r->set_header( $k, $v );
			}
			wp_set_current_user( 0 );
			return rest_do_request( $r );
		};
		$this->assertSame( 401, $request( array() )->get_status() );
		$this->assertSame( 401, $request( Link::headers( random_bytes( 32 ), 'POST', $route, 'x' ) )->get_status() );
		$this->assertSame( 400, $request( Link::headers( $secret, 'POST', $route, 'x' ) )->get_status(), 'signed, but not a site file' );

		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 403, $request( Link::headers( $secret, 'POST', $route, 'x' ) )->get_status(), 'plain HTTP on a live site' );
		$_SERVER['HTTPS'] = 'on';

		$owner = $this->user( 'administrator' );
		$user  = get_user_by( 'id', $owner );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $owner );
		$this->code();
		$secret = $this->inbound()['secret'];
		$user->set_role( 'editor' );
		$this->assertSame( 403, $request( Link::headers( $secret, 'POST', $route, 'x' ) )->get_status(), 'the owner is no longer an administrator' );
		wp_set_current_user( 1 );
	}

	/** A signed request to a production route, as the staging site would send it. */
	private function signed( string $route, string $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', Receiver::route( $route ) );
		$request->set_body( $body );
		foreach ( Link::headers( $this->inbound()['secret'], 'POST', Receiver::route( $route ), $body ) as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$response = rest_do_request( $request );
		wp_set_current_user( $user );
		return $response;
	}

	/** This site's package, as if it came from `$site` (the manifest's hash made again). */
	private function package_from( string $site ): string {
		$file = (string) wp_tempnam( 'xb-remote-test' );
		SiteTransfer::instance()->export( $file );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $file ) );
		$manifest         = json_decode( (string) $zip->getFromName( 'site.json' ), true );
		$manifest['site'] = $site;
		unset( $manifest['hash'], $manifest['signature'] );
		$manifest['hash'] = ( new \ReflectionMethod( SiteTransfer::class, 'hash' ) )->invoke( null, $manifest );
		$zip->addFromString( 'site.json', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$zip->close();
		$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $file );
		return $body;
	}

	/**
	 * doc 23, voce 173: production keeps the staging site's address from the pairing and takes packages only from it;
	 * the address a package names cannot stamp contents with another origin or list what arrived from elsewhere.
	 */
	public function test_the_staging_address_is_bound_at_pairing_and_other_origins_are_refused(): void {
		$here = untrailingslashit( home_url( '/' ) );
		Link::save_outbound( $this->code() );
		$this->assertSame( '', $this->inbound()['origin'], 'a new code is not bound yet' );
		$this->loopback();
		Sender::pair();
		$this->assertSame( $here, $this->inbound()['origin'] );
		Sender::pair();
		$this->assertSame( $here, $this->inbound()['origin'], 'the same staging confirms again' );

		$other = $this->signed( Receiver::PAIR, (string) wp_json_encode( array( 'site' => 'https://altro.test' ) ) );
		$this->assertSame( 409, $other->get_status(), 'another address cannot take the link over' );
		$this->assertSame( $here, $this->inbound()['origin'] );
		$this->assertSame( 409, $this->signed( Receiver::PAIR, '{"site":"javascript:alert(1)"}' )->get_status() );

		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'zq-origine', 'post_title' => 'Origine zq' ) );
		foreach ( array( Receiver::PLAN, Receiver::IMPORT ) as $route ) {
			$refused = $this->signed( $route, $this->package_from( 'https://altro.test/' ) );
			$this->assertSame( 400, $refused->get_status(), $route );
			$this->assertStringContainsString( 'https://altro.test', (string) $refused->get_data()['message'] );
		}
		$this->assertSame( '', get_post_meta( $page, SiteTransfer::ORIGIN_META, true ), 'nothing stamped with the other address' );
		$this->assertSame( 200, $this->signed( Receiver::PLAN, $this->package_from( $here . '/' ) )->get_status(), 'packages of the bound staging pass' );

		// Unbound (paired before the address was kept): the first package binds it, then the rule holds.
		$stored = get_option( Link::INBOUND );
		$this->assertIsArray( $stored );
		unset( $stored['origin'] );
		update_option( Link::INBOUND, $stored, false );
		$this->assertSame( '', $this->inbound()['origin'] );
		$this->assertSame( 200, $this->signed( Receiver::PLAN, $this->package_from( $here . '/' ) )->get_status() );
		$this->assertSame( $here, $this->inbound()['origin'] );
		$this->assertSame( 400, $this->signed( Receiver::PLAN, $this->package_from( 'https://altro.test/' ) )->get_status() );

		$this->code();
		$this->assertSame( '', $this->inbound()['origin'], 'a new code starts unbound' );
	}

	public function test_a_release_goes_to_production_dry_run_first_and_never_published_unless_allowed(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_name' => 'xb-remote-pagina', 'post_title' => 'Inviata' ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'Dallo staging' ) ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( array( Documents::for_post( $page ) ) )->get_status() );
		$this->assertSame( array( Documents::for_post( $page ) ), Sender::release_docs(), 'only what the latest release changed' );

		Link::save_outbound( $this->code( false ) );
		$this->loopback();
		$plan = Sender::send( Sender::SCOPE_RELEASE, false );
		$this->assertFalse( $plan['written'] );
		$this->assertFalse( $plan['canPublish'] );
		$this->assertContains( 'aggiorna', array_column( $plan['contents'], 'action' ) );
		$this->assertCount( 1, $plan['contents'], 'only the release, not the whole site' );

		$done = Sender::send( Sender::SCOPE_RELEASE, true );
		$this->assertTrue( $done['written'] );
		$this->assertSame( '', $done['published'], 'the link does not allow publishing' );
		$this->assertGreaterThan( 0, $this->inbound()['last'] );
		$actions = array_column( \XB\Plugin::instance()->versioning->store()->audit_log( 10 ), 'action' );
		$this->assertContains( 'remote_import', $actions );
		$this->assertContains( 'remote_send', $actions );

		// A dry run's report from production is reduced to plain, bounded text before it is printed here.
		$report = Sender::report( array( 'site' => array( 'x' ), 'media' => '3', 'contents' => array( array( 'label' => str_repeat( 'a', 999 ), 'action' => 1 ), 'x' ), 'issues' => array( 1, 'ok' ), 'written' => 'yes' ) );
		$this->assertSame( '', $report['site'] );
		$this->assertSame( 0, $report['media'] );
		$this->assertSame( 300, mb_strlen( $report['contents'][0]['label'] ) );
		$this->assertSame( array( '', 'ok' ), $report['issues'] );
		$this->assertFalse( $report['written'] );
	}
}
