<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Versioning\Preview;

/** V3: the builder chooses how long a preview link lasts, among four durations; 48 hours without a choice. */
final class PreviewLinkTtlTest extends IntegrationTestCase {

	private function token( string $url ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return is_string( $query[ Preview::PARAM ] ?? null ) ? $query[ Preview::PARAM ] : '';
	}

	public function test_a_duration_outside_the_list_is_refused(): void {
		$id = $this->post( array( 'post_type' => 'page' ) );
		foreach ( array( '7200', '0', '-3600', '1209600', 'tanto' ) as $ttl ) {
			$response = $this->request( 'GET', '/preview-link', array( 'post' => $id, 'ttl' => $ttl ) );
			$this->assertSame( 400, $response->get_status(), 'ttl ' . $ttl );
			$this->assertArrayNotHasKey( 'url', (array) $response->get_data() );
		}
		foreach ( Preview::LINK_TTLS as $ttl ) {
			$response = $this->request( 'GET', '/preview-link', array( 'post' => $id, 'ttl' => (string) $ttl ) );
			$this->assertSame( 200, $response->get_status(), 'ttl ' . $ttl );
			$data = (array) $response->get_data();
			$this->assertEqualsWithDelta( time() + $ttl, $data['expires'], 5 );
			$this->assertSame( Preview::expires( (string) $data['url'] ), $data['expires'] );
		}
	}

	public function test_without_a_duration_the_link_lasts_48_hours(): void {
		$id   = $this->post( array( 'post_type' => 'page' ) );
		$data = (array) $this->request( 'GET', '/preview-link', array( 'post' => $id ) )->get_data();
		$this->assertEqualsWithDelta( time() + 2 * DAY_IN_SECONDS, $data['expires'], 5 );
		$this->assertEqualsWithDelta( time() + 2 * DAY_IN_SECONDS, Preview::expires( Preview::url( $id ) ), 5, 'the default of url(), the agents\' ability too' );
		$this->assertSame( $id, Preview::validate( $this->token( (string) $data['url'] ) ) );
	}

	public function test_a_one_hour_link_no_longer_works_after_61_minutes(): void {
		$id    = $this->post( array( 'post_type' => 'page' ) );
		$url   = (string) $this->request( 'GET', '/preview-link', array( 'post' => $id, 'ttl' => '3600' ) )->get_data()['url'];
		$token = $this->token( $url );
		$this->assertSame( $id, Preview::validate( $token ), 'valid now' );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, Preview::expires( $url ), 5 );
		// The same link 61 minutes later: the token, signed as it was, with its expiry seen from then (now − 60 s).
		$parts  = explode( '.', $token );
		$expiry = (int) $parts[0] - 61 * MINUTE_IN_SECONDS;
		$mac    = new \ReflectionMethod( Preview::class, 'mac' );
		$later  = $expiry . '.' . $parts[1] . '.' . $parts[2] . '.' . $mac->invoke( null, $expiry, (int) $parts[1], (int) $parts[2], '' );
		$this->assertNull( Preview::validate( $later ) );
		// The same arithmetic one minute earlier still opens the page: what closes it is the hour, not the forgery.
		$expiry += 2 * MINUTE_IN_SECONDS;
		$sooner  = $expiry . '.' . $parts[1] . '.' . $parts[2] . '.' . $mac->invoke( null, $expiry, (int) $parts[1], (int) $parts[2], '' );
		$this->assertSame( $id, Preview::validate( $sooner ) );
	}
}
