<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Fonts\LocalFonts;

/** Local fonts from the builder's design system (E2): list, Google import, removal — docs/spec/fonts.md. */
final class FontsRestTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( LocalFonts::OPTION, array() );
	}

	protected function tearDown(): void {
		foreach ( LocalFonts::families() as $family ) {
			LocalFonts::remove( $family );
		}
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private static function reply( int $code, string $body = '' ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Bad Request',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_lists_the_site_fonts_and_the_catalog(): void {
		LocalFonts::add( 'Xbt Sans', '400', 'normal', 'wOF2' . str_repeat( 'a', 64 ) );
		$data = $this->request( 'GET', '/fonts' )->get_data();
		$this->assertSame( array( array( 'family' => 'Xbt Sans', 'faces' => array( array( 'weight' => '400', 'style' => 'normal' ) ) ) ), $data['families'] );
		$this->assertStringContainsString( '@font-face{font-family:"Xbt Sans"', $data['css'] );
		$this->assertTrue( $data['canManage'] );
		$inter = array_values( array_filter( $data['catalog'], static fn ( array $f ): bool => 'Inter' === $f['family'] ) );
		$this->assertCount( 1, $inter );
		$this->assertSame( 'sans-serif', $inter[0]['category'] );
		$this->assertContains( 400, $inter[0]['weights'] );
		$this->assertGreaterThan( 80, count( $data['catalog'] ), 'a useful list, bundled with the plugin' );
	}

	public function test_imports_from_google_one_weight_at_a_time_when_the_whole_request_is_refused(): void {
		$requested = array();
		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ) use ( &$requested ): array {
				$requested[] = $url;
				if ( str_contains( $url, 'fonts.googleapis.com/css2?family=Xbt%20Inter:ital,wght@0,400&' ) ) {
					return self::reply( 200, "@font-face {\n  font-family: 'Xbt Inter';\n  font-style: normal;\n  font-weight: 400;\n  src: url(https://fonts.gstatic.com/s/xbt/v1/a.woff2) format('woff2');\n}\n" );
				}
				if ( str_starts_with( $url, 'https://fonts.gstatic.com/' ) ) {
					return self::reply( 200, 'wOF2' . str_repeat( 'z', 64 ) );
				}
				// The combined request, and the weight that does not exist.
				return self::reply( 400 );
			},
			10,
			3
		);
		$response = $this->request(
			'POST',
			'/fonts/google',
			array(
				'family'  => 'Xbt Inter',
				'weights' => array( 400, 900 ),
				'italic'  => false,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( array( 'weight' => '400', 'style' => 'normal' ) ), $response->get_data()['families'][0]['faces'] );
		$this->assertStringContainsString( 'wght@0,400;0,900', $requested[0] );
		$this->assertCount( 4, $requested, 'whole request, then 400 and 900 alone, then the one file' );
	}

	public function test_only_administrators_change_the_fonts(): void {
		LocalFonts::add( 'Xbt Sans', '400', 'normal', 'wOF2' . str_repeat( 'a', 64 ) );
		$editor = $this->user( 'editor' );
		$user   = get_userdata( $editor );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $editor );
		$this->assertFalse( $this->request( 'GET', '/fonts' )->get_data()['canManage'] );
		$this->assertSame( 403, $this->request( 'POST', '/fonts/google', array( 'family' => 'Inter' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', '/fonts', array( 'family' => 'Xbt Sans' ) )->get_status() );

		wp_set_current_user( 1 );
		$this->assertSame( 400, $this->request( 'POST', '/fonts/google', array( 'family' => 'x"}body{' ) )->get_status() );
		$this->assertSame( 200, $this->request( 'DELETE', '/fonts', array( 'family' => 'Xbt Sans' ) )->get_status() );
		$this->assertSame( array(), LocalFonts::families() );
	}
}
