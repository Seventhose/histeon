<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Fonts\LocalFonts;

/** Fonts served by the site — docs/spec/fonts.md. */
final class LocalFontsTest extends IntegrationTestCase {

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

	private static function woff2( string $seed ): string {
		return 'wOF2' . str_repeat( $seed, 64 );
	}

	public function test_uploads_are_checked_by_content_and_printed_as_font_face(): void {
		LocalFonts::add( 'Xbt Sans', '400', 'normal', self::woff2( 'a' ) );
		LocalFonts::add( 'Xbt Sans', '700', 'italic', 'wOFF' . str_repeat( 'b', 64 ) );
		$faces = LocalFonts::faces();
		$this->assertCount( 2, $faces );
		$this->assertFileExists( LocalFonts::dir() . '/' . $faces[0]['file'] );
		$css = LocalFonts::css();
		$this->assertStringContainsString( '@font-face{font-family:"Xbt Sans";src:url("', $css );
		$this->assertStringContainsString( 'format("woff2");font-weight:400;font-style:normal;font-display:swap;', $css );
		$this->assertStringContainsString( 'format("woff");font-weight:700;font-style:italic;', $css );

		$cases = array(
			'php file'     => array( 'Xbt Sans', '400', 'normal', '<?php echo 1;' ),
			'svg'          => array( 'Xbt Sans', '400', 'normal', '<svg onload=alert(1)>' ),
			'bad family'   => array( 'x"}body{display:none', '400', 'normal', self::woff2( 'c' ) ),
			'bad weight'   => array( 'Xbt Sans', '450', 'normal', self::woff2( 'c' ) ),
			'bad style'    => array( 'Xbt Sans', '400', 'oblique', self::woff2( 'c' ) ),
			'too big'      => array( 'Xbt Sans', '400', 'normal', 'wOF2' . str_repeat( 'x', LocalFonts::MAX_BYTES ) ),
		);
		foreach ( $cases as $label => [ $family, $weight, $style, $bytes ] ) {
			try {
				LocalFonts::add( $family, $weight, $style, $bytes );
				$this->fail( "accepted: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}

		// A stored value that does not validate is never printed.
		update_option( LocalFonts::OPTION, array_merge( (array) get_option( LocalFonts::OPTION ), array( array( 'family' => 'X', 'weight' => '400', 'style' => 'normal', 'file' => '../wp-config.php', 'format' => 'woff2', 'range' => '' ) ) ) );
		$this->assertStringNotContainsString( 'wp-config', LocalFonts::css() );

		ob_start();
		LocalFonts::print_head();
		$this->assertStringContainsString( '<style id="xb-fonts">', (string) ob_get_clean() );

		LocalFonts::remove( 'Xbt Sans' );
		$this->assertFileDoesNotExist( LocalFonts::dir() . '/' . $faces[0]['file'] );
		$this->assertSame( '', LocalFonts::css() );
	}

	/** Note 81 of the 3 November audit: a face refused after its file was written leaves no file behind. */
	public function test_a_refused_face_leaves_no_file(): void {
		$dir = LocalFonts::dir() . '/xbt-orfano';
		try {
			LocalFonts::add( 'Xbt Orfano', '400', 'normal', self::woff2( 'z' ), 'non un intervallo' );
			$this->fail( 'an invalid range was saved' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertSame( array(), glob( $dir . '/*' ) ?: array() );
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}

	public function test_google_import_fetches_only_from_google_hosts(): void {
		$requested = array();
		add_filter(
			'pre_http_request',
			static function ( mixed $pre, array $args, string $url ) use ( &$requested ): array {
				$requested[] = $url;
				if ( str_starts_with( $url, 'https://fonts.googleapis.com/css2?family=Xbt%20Inter' ) ) {
					$css = "/* latin */\n@font-face {\n  font-family: 'Xbt Inter';\n  font-style: normal;\n  font-weight: 400;\n  font-display: swap;\n  src: url(https://fonts.gstatic.com/s/inter/v1/a.woff2) format('woff2');\n  unicode-range: U+0000-00FF, U+0131;\n}\n"
						. "@font-face {\n  font-family: 'Xbt Inter';\n  font-style: normal;\n  font-weight: 700;\n  src: url(https://evil.example/b.woff2) format('woff2');\n}\n";
					return array( 'headers' => array(), 'body' => $css, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
				}
				if ( 'https://fonts.gstatic.com/s/inter/v1/a.woff2' === $url ) {
					return array( 'headers' => array(), 'body' => 'wOF2' . str_repeat( 'i', 64 ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
				}
				return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
			},
			10,
			3
		);
		$this->assertSame( 1, LocalFonts::import_google( 'Xbt Inter', array( 400, 700 ), false ) );
		$this->assertNotContains( 'https://evil.example/b.woff2', $requested, 'files only from fonts.gstatic.com' );
		$this->assertStringContainsString( 'unicode-range:U+0000-00FF, U+0131;', LocalFonts::css() );
		$this->assertStringNotContainsString( 'gstatic', LocalFonts::css(), 'pages load the font from this site' );
	}
}
