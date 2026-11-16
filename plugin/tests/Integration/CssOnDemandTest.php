<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Styles\CssRules;
use XB\Styles\FrameworkCss;
use XB\Styles\PageStyles;
use XB\Styles\Presence;
use XB\Styles\StyleCollector;
use XB\Styles\StyleRegistry;

final class CssOnDemandTest extends IntegrationTestCase {

	private const MARKER = '<!--xb-styles-test-->';

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = wp_upload_dir()['basedir'] . '/xb-test-fw';
		wp_mkdir_p( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		delete_option( PageStyles::SAFELIST );
		delete_option( PageStyles::MODE );
		delete_option( PageStyles::GLOBAL_FILE );
		parent::tearDown();
	}

	private static function page( string $body, string $head = '' ): string {
		return '<!doctype html><html><head>' . $head . self::MARKER . '</head><body>' . $body . '</body></html>';
	}

	private static function xb_css( string $html ): string {
		return preg_match( '#<style id="xb-styles">(.*?)</style>#s', $html, $m ) ? $m[1] : '';
	}

	public function test_xb_styles_follow_the_final_html_of_the_page(): void {
		$this->set_option(
			'xb_styles',
			array(
				array( 'id' => 'a', 'selector' => '.card', 'css' => 'padding:1rem; &--dark { color:#fff }' ),
				array( 'id' => 'b', 'selector' => '#hero', 'css' => 'margin:0' ),
				array( 'id' => 'c', 'selector' => '.x, .from-raw', 'css' => 'color:red' ),
				array( 'id' => 'd', 'selector' => '.js-only', 'css' => 'display:none' ),
			)
		);
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => 'body{margin:0} .unused-global{x:1} .card{gap:1rem}' ) ) );

		// Classes from raw HTML and core blocks count as much as XB elements: what matters is the page.
		$css = self::xb_css( PageStyles::apply( self::page( '<div class="card"><p class="from-raw">x</p></div><section id="hero"></section>' ), self::MARKER ) );
		$this->assertStringContainsString( '.card{padding:1rem;}', $css );
		$this->assertStringContainsString( '#hero{margin:0;}', $css );
		$this->assertStringContainsString( '.x, .from-raw{color:red;}', $css );
		$this->assertStringContainsString( 'body{margin:0}', $css );
		$this->assertStringContainsString( '.card{gap:1rem}', $css );
		$this->assertStringNotContainsString( '.card--dark', $css );
		$this->assertStringNotContainsString( '.unused-global', $css );
		$this->assertStringNotContainsString( '.js-only', $css );

		update_option( PageStyles::SAFELIST, '.js-*' );
		$this->assertStringContainsString( '.js-only{display:none;}', self::xb_css( PageStyles::apply( self::page( '' ), self::MARKER ) ) );
	}

	public function test_named_queries_are_resolved_on_the_site(): void {
		$this->set_option( \XB\Styles\NamedQueries::OPTION, array( 'tablet' => '(width < 50em)' ) );
		$this->set_option( 'xb_styles', array( array( 'id' => 'a', 'selector' => '.card', 'css' => 'padding:2rem; @media (--tablet) { padding:1rem }' ) ) );
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => '@media (--tablet){.card{gap:0}} @media (--nope){.card{x:1}}' ) ) );
		$css = self::xb_css( PageStyles::apply( self::page( '<div class="card"></div>' ), self::MARKER ) );
		$this->assertStringContainsString( '(width < 50em)', $css );
		$this->assertStringNotContainsString( '(--tablet)', $css );
		$this->assertStringContainsString( '@media (--nope)', $css, 'an unknown name stays as written: the browser ignores that rule' );
	}

	public function test_linked_css_media_resolves_to_the_wordpress_attachment_url(): void {
		$asset_id = '550e8400-e29b-41d4-a716-446655440101';
		$reference = '/media/hero.png?v=2';
		$attachment = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
				'post_title'     => 'Hero CSS',
				'guid'           => wp_upload_dir()['baseurl'] . '/xb-test-css-media/hero.png',
			),
			true
		);
		$this->assertIsInt( $attachment );
		$this->track( $attachment );
		update_post_meta( $attachment, '_wp_attached_file', 'xb-test-css-media/hero.png' );
		update_post_meta( $attachment, '_xb_page_profile_id', $asset_id );
		$this->set_option(
			'xb_styles',
			array( array( 'id' => 'hero', 'selector' => '.hero', 'css' => 'background-image:url(/media/hero.png?v=2)', 'assets' => array( array( 'reference' => $reference, 'assetId' => $asset_id ) ) ) )
		);
		$this->set_option( 'xb_global_styles', array() );

		$css = \XB\Plugin::instance()->styles->all_css();
		$this->assertStringContainsString( 'url("' . wp_upload_dir()['baseurl'] . '/xb-test-css-media/hero.png?v=2")', $css );
		$this->assertSame( $reference, get_option( 'xb_styles' )[0]['assets'][0]['reference'], 'the bundle-root transfer reference remains portable' );
	}

	/** The live rules are parsed once and reused by later requests; any change to what they come from makes a new key. */
	public function test_site_rules_are_cached_between_requests(): void {
		$this->set_option( 'xb_styles', array( array( 'id' => 'a', 'selector' => '.card', 'css' => 'padding:1rem' ) ) );
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => '.wide{gap:1rem}' ) ) );
		$fresh = static fn (): StyleCollector => new StyleCollector( new StyleRegistry() );
		$page  = Presence::from_html( '<div class="card wide"></div>' );
		$files = static fn (): array => glob( wp_upload_dir()['basedir'] . '/xb-cache/' . StyleCollector::CACHE . '-*.json' ) ?: array();

		$this->assertStringContainsString( '.card{padding:1rem;}', $fresh()->css_for( $page ) );
		$this->assertCount( 1, $files() );
		// A later request reads the cache instead of parsing: an edited cache file shows through.
		$file = $files()[0];
		file_put_contents( $file, (string) wp_json_encode( CssRules::parse( '.card{color:cached}' ) ) );
		$this->assertStringContainsString( 'color:cached', $fresh()->css_for( $page ) );

		// A release (new stored styles) changes the key and replaces the old file.
		$this->set_option( 'xb_styles', array( array( 'id' => 'a', 'selector' => '.card', 'css' => 'padding:2rem' ) ) );
		$this->assertStringContainsString( '.card{padding:2rem;}', $fresh()->css_for( $page ) );
		$this->assertCount( 1, $files() );
		$this->assertNotSame( $file, $files()[0] );

		// So does a change to the attachments CSS points at.
		$file = $files()[0];
		file_put_contents( $file, (string) wp_json_encode( CssRules::parse( '.card{color:cached}' ) ) );
		$attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'post_title' => 'x' ), true );
		$this->assertIsInt( $attachment );
		$this->track( $attachment );
		update_post_meta( $attachment, '_xb_page_profile_id', '550e8400-e29b-41d4-a716-446655440102' );
		$this->assertStringNotContainsString( 'color:cached', $fresh()->css_for( $page ) );

		// Styles of a preview are never written to the shared cache.
		$before = $files();
		StyleRegistry::override( array( 'styles' => array( array( 'id' => 'p', 'selector' => '.card', 'css' => 'color:preview' ) ), 'globals' => array() ) );
		try {
			$this->assertStringContainsString( 'color:preview', $fresh()->css_for( $page ) );
		} finally {
			StyleRegistry::override( null );
		}
		$this->assertSame( $before, $files() );
		// Note 54 of the 3 November audit: nor is what a preview compiles with the published styles.
		$this->set_option( 'xb_styles', array( array( 'id' => 'a', 'selector' => '.card', 'css' => 'padding:3rem' ) ) );
		\XB\Versioning\Preview::use_dev( false );
		try {
			$this->assertStringContainsString( '.card{padding:3rem;}', $fresh()->css_for( $page ) );
			$this->assertNull( $fresh()->global_file_url() );
		} finally {
			\XB\Versioning\Preview::reset();
		}
		$this->assertSame( $before, $files() );
	}

	/**
	 * Threat model T31, V2 of the fifth review. On the public page (rules(), css_for()) each sheet and each style is read
	 * on its own and only statements read closed are printed: CSS that the filter leaves as written never comes out cut
	 * in the middle of a string or an address, and never takes from the page the CSS printed after it.
	 */
	public function test_css_in_doubt_is_not_printed_and_takes_nothing_else_from_the_page(): void {
		// One rule for CssCompiler; read with a new line ending the string it was cut after `k=` and printed so.
		$doubt  = "a:\"\n\";content:\"a;b}c\";src:url(\"media/a.png?k=}{\")";
		$stored = array(
			'styles'  => array(
				array( 'id' => 'a', 'selector' => '.card', 'css' => $doubt ),
				array( 'id' => 'b', 'selector' => '.brace', 'css' => 'color:red}' ),
				array( 'id' => 'c', 'selector' => '.open', 'css' => 'color:red;&:hover{color:blue' ),
				array( 'id' => 'd', 'selector' => '.after', 'css' => 'color:green' ),
			),
			'globals' => array(
				array( 'id' => 'g1', 'css' => ".x{color:red}}\n.x2{gap:1rem}" ),
				array( 'id' => 'g2', 'css' => ".y{color:blue}\n.y2{content:\"a\nb\"}\n.y3{gap:2rem}" ),
				array( 'id' => 'g3', 'css' => '.z{color:teal}' ),
			),
		);
		$this->assertSame( $stored, \XB\Versioning\Documents::restricted_styles( $stored ), 'the filter leaves all of it as written' );
		$this->set_option( 'xb_styles', $stored['styles'] );
		$this->set_option( 'xb_global_styles', $stored['globals'] );

		// Everything on the page: the extra `}` of g1 ends nothing; g2 stops at its string with a new line and g3 is read
		// all the same; `.card` is in doubt and not printed; the styles after it are.
		$all = '<div class="card brace open after x x2 y y2 y3 z"></div>';
		$css = self::xb_css( PageStyles::apply( self::page( $all ), self::MARKER ) );
		$this->assertSame( "\n.x{color:red}.x2{gap:1rem}.y{color:blue}.z{color:teal}.brace{color:red}.open{color:red;&:hover{color:blue}}.after{color:green;}\n", $css );
		$this->assertStringNotContainsString( 'k=', $css );

		// Whatever the page keeps, it is a list of closed statements.
		$css = self::xb_css( PageStyles::apply( self::page( '<div class="after x2 card"></div>' ), self::MARKER ) );
		$this->assertSame( "\n.x2{gap:1rem}.after{color:green;}\n", $css );
		// The fallback path (no buffer) prints from the same rules.
		$collector = new StyleCollector( new StyleRegistry() );
		$collector->add_class_attribute( 'card open z' );
		$this->assertSame( ".z{color:teal}\n.open{color:red;&:hover{color:blue}}\n", $collector->pending_css() );
		// With the global sheets served as a file, the styles are still read one by one.
		$this->assertSame( '.brace{color:red}.after{color:green;}', $collector->css_for( Presence::from_html( '<i class="brace after card"></i>' ), false ) );
	}

	/**
	 * W1, W2 and W3 of the seventh review of T31: three sheets the filter leaves as written were printed not closed,
	 * and took from the page the CSS printed after them.
	 */
	public function test_css_that_was_printed_not_closed_takes_nothing_else_from_the_page(): void {
		$stored = array(
			'styles'  => array(
				array( 'id' => 'a', 'selector' => '.card', 'css' => '--x:xurl(a[b)' ),
				array( 'id' => 'b', 'selector' => '.after', 'css' => 'color:green' ),
			),
			'globals' => array(
				array( 'id' => 'g1', 'css' => ".x{color:red}\n.x1{--x:xurl(a[b)}\n.x2{gap:1rem}" ),
				array( 'id' => 'g2', 'css' => ".y\\ {color:blue}\n.y2{gap:2rem}" ),
				array( 'id' => 'g3', 'css' => "@;\n.z{color:teal}\n@ layer a;\n.z2{gap:3rem}" ),
			),
		);
		$this->assertSame( $stored, \XB\Versioning\Documents::restricted_styles( $stored ), 'the filter leaves all of it as written' );
		$this->set_option( 'xb_styles', $stored['styles'] );
		$this->set_option( 'xb_global_styles', $stored['globals'] );

		// g1 stops at the `[` a browser would read as a block the `)` does not close; in g2 the space written with a
		// backslash stays in the selector; in g3 a `@` no name follows is loose text; `.card` is in doubt and not printed.
		$all = '<div class="card after x x1 x2 y y2 z z2"></div>';
		$css = self::xb_css( PageStyles::apply( self::page( $all ), self::MARKER ) );
		$this->assertSame( "\n.x{color:red}.y\\ {color:blue}.y2{gap:2rem}.z{color:teal}.z2{gap:3rem}.after{color:green;}\n", $css );

		// Whatever the page keeps, the rules after them are there.
		$css = self::xb_css( PageStyles::apply( self::page( '<div class="after y2 z2 card"></div>' ), self::MARKER ) );
		$this->assertSame( "\n.y2{gap:2rem}.z2{gap:3rem}.after{color:green;}\n", $css );
		$collector = new StyleCollector( new StyleRegistry() );
		$this->assertSame( '.after{color:green;}', $collector->css_for( Presence::from_html( '<i class="after card"></i>' ), false ) );
	}

	/** all_css() is printed raw in the block editor: queries substituted into it cannot close the <style>. */
	public function test_editor_css_cannot_close_its_style_element(): void {
		$this->set_option( 'xb_styles', array( array( 'id' => 'probe', 'selector' => '.probe', 'css' => '@media (--narrow){color:red} content:"</style>"' ) ) );
		$this->set_option( 'xb_global_styles', array() );
		$this->set_option( 'xb_named_queries', array( 'narrow' => '(width<1px)</style><script>alert(1)</script>' ) );
		$css = \XB\Plugin::instance()->styles->all_css();
		$this->assertStringNotContainsString( '</', $css );
		$this->assertStringNotContainsString( '<script', $css );
	}

	public function test_named_queries_are_validated_saved_in_dev_and_previewed(): void {
		$cases = array( array( 'Tablet' => '(width < 1em)' ), array( 'x' => '(width < 1em)) {} body{display:none' ), array( 'x' => 'url(a)' ), array( 'x' => '' ), array( 'x' => '(a' ), array( 'x' => '(width<1px)</style><script>alert(1)</script>' ), array( 'x' => '(width < 1px) </ style>' ), array( 'x' => '(width<1px)<!--' ) );
		foreach ( $cases as $case ) {
			try {
				\XB\Styles\NamedQueries::validate( $case );
				$this->fail( 'accepted: ' . (string) wp_json_encode( $case ) );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->set_option( \XB\Styles\NamedQueries::OPTION, false );
		delete_option( \XB\Styles\NamedQueries::OPTION );
		$this->assertSame( \XB\Styles\NamedQueries::DEFAULTS, \XB\Styles\NamedQueries::all() );

		$get = $this->request( 'GET', '/named-queries' )->get_data();
		$res = $this->request( 'POST', '/named-queries', array( 'queries' => array( 'stretto' => '(width < 30em)' ), 'head' => $get['head'], 'base' => $get['base'] ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 400, $this->request( 'POST', '/named-queries', array( 'queries' => array( 'x' => '{}' ), 'head' => 'a', 'base' => 'b' ) )->get_status() );
		$this->assertSame( \XB\Styles\NamedQueries::DEFAULTS, \XB\Styles\NamedQueries::all(), 'not live before a release' );
		$this->assertSame( array( 'stretto' => '(width < 30em)' ), (array) $this->request( 'GET', '/styles' )->get_data()['queries'], 'the builder previews the dev ones' );
		\XB\Versioning\Preview::use_dev();
		$this->assertSame( array( 'stretto' => '(width < 30em)' ), \XB\Styles\NamedQueries::all() );
		\XB\Versioning\Preview::reset();
		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertSame( 403, $this->request( 'GET', '/named-queries' )->get_status() );
	}

	public function test_styles_cannot_close_the_style_element(): void {
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => 'body::after{content:"</style><script>alert(1)</script>"}' ) ) );
		$html = PageStyles::apply( self::page( '' ), self::MARKER );
		// Only `</` ends a <style> (raw text): with it escaped the element closes once, where XB closes it, and
		// whatever looks like markup inside stays CSS text. A real HTML parser agrees.
		$this->assertSame( 1, substr_count( strtolower( $html ), '</style' ) );
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( $html );
		libxml_clear_errors();
		$this->assertSame( 0, $doc->getElementsByTagName( 'script' )->length, 'no script element comes out of the CSS' );
	}

	public function test_framework_link_becomes_the_rules_the_page_uses(): void {
		file_put_contents( $this->dir . '/fw.css', ":root{--space-m:1rem}@font-face{font-family:F;src:url(fonts/f.woff2)}.btn{padding:var(--space-m)}.unused{x:1}@media (width>40em){.btn{y:2}.unused{z:3}}.bg{background:url('../img/a.png')}" );
		$url  = wp_upload_dir()['baseurl'] . '/xb-test-fw/fw.css?ver=1';
		$html = self::page( '<a class="btn">x</a><div class="bg"></div>', "<link rel='stylesheet' id='fw-css' href='" . esc_url( $url ) . "' media='all' />" );
		$out  = FrameworkCss::inline( $html, array( $url ), Presence::from_html( $html ) );

		$this->assertStringNotContainsString( "id='fw-css'", $out );
		$this->assertMatchesRegularExpression( '#<style id="xb-framework-0">.*\.btn\{padding:var\(--space-m\)\}#s', $out );
		$this->assertStringContainsString( ':root{--space-m:1rem}', $out );
		$this->assertStringContainsString( '@media (width>40em){.btn{y:2}}', $out );
		$this->assertStringNotContainsString( '.unused', $out );
		$base = wp_upload_dir()['baseurl'];
		$this->assertStringContainsString( 'url(' . $base . '/xb-test-fw/fonts/f.woff2)', $out, 'relative urls keep pointing at the stylesheet folder' );
		$this->assertStringContainsString( "url('" . $base . "/img/a.png')", $out );

		$cache = glob( wp_upload_dir()['basedir'] . '/xb-cache/fw-*.json' ) ?: array();
		$this->assertNotEmpty( $cache, 'parsed once, then cached as data' );
	}

	public function test_only_local_css_files_are_inlined(): void {
		file_put_contents( $this->dir . '/x.txt', '.a{}' );
		$base = wp_upload_dir()['baseurl'];
		$this->assertNull( FrameworkCss::file_for( $base . '/xb-test-fw/x.txt' ) );
		$this->assertNull( FrameworkCss::file_for( $base . '/xb-test-fw/../../../wp-config.php' ) );
		$this->assertNull( FrameworkCss::file_for( 'https://cdn.example.com/wp-content/uploads/a.css' ) );
		$this->assertNull( FrameworkCss::file_for( site_url( '/wp-includes/css/dashicons.css' ) ) );
		$html = self::page( '', "<link rel='stylesheet' href='https://cdn.example.com/x.css' />" );
		$this->assertSame( $html, FrameworkCss::inline( $html, array( 'https://cdn.example.com/x.css' ), new Presence() ) );
	}

	public function test_global_sheets_can_be_a_cached_file_while_the_page_css_stays_inline(): void {
		$this->set_option( 'xb_styles', array( array( 'id' => 'a', 'selector' => '.card', 'css' => 'padding:1rem' ) ) );
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => 'body{margin:0} .unused-global{x:1} @media (--tablet) { body { gap: 1px } }' ) ) );
		update_option( PageStyles::GLOBAL_FILE, true );

		$html = PageStyles::apply( self::page( '<div class="card"></div>' ), self::MARKER );
		if ( 1 !== preg_match( '#<link rel="stylesheet" id="xb-global-css" href="([^"]+/xb-cache/global-css-([0-9a-f]{32})\.css)">\s*<style id="xb-styles">#', $html, $m ) ) {
			$this->fail( 'A link to the cached global sheets, before the inline styles: ' . $html );
		}
		$file = wp_upload_dir()['basedir'] . '/xb-cache/global-css-' . $m[2] . '.css';
		$sheet = (string) file_get_contents( $file );
		// The whole sheet, queries resolved, in the file; the page keeps only its selectors, on demand.
		$this->assertStringContainsString( '.unused-global{x:1}', $sheet );
		$this->assertStringNotContainsString( '--tablet', $sheet );
		$this->assertSame( md5( $sheet ), $m[2] );
		$css = self::xb_css( $html );
		$this->assertStringContainsString( '.card{padding:1rem;}', $css );
		$this->assertStringNotContainsString( 'body{margin:0}', $css );

		// Another sheet, another file; the old one goes.
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => 'body{margin:1px}' ) ) );
		Plugin::instance()->styles->reset();
		$again = PageStyles::apply( self::page( '' ), self::MARKER );
		$this->assertStringNotContainsString( $m[2], $again );
		$this->assertFileDoesNotExist( $file );

		// A relative url() would change meaning from uploads/xb-cache/: that sheet stays inline.
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => '.hero{background:url(img/a.png)}' ) ) );
		Plugin::instance()->styles->reset();
		$inline = PageStyles::apply( self::page( '<div class="hero"></div>' ), self::MARKER );
		$this->assertStringNotContainsString( 'xb-global-css', $inline );
		$this->assertStringContainsString( 'url(img/a.png)', self::xb_css( $inline ) );

		// A static copy keeps the sheets inline: the exporter copies from uploads only published media.
		$this->set_option( 'xb_global_styles', array( array( 'id' => 'g', 'css' => 'body{margin:2px}' ) ) );
		Plugin::instance()->styles->reset();
		$this->assertStringContainsString( 'xb-global-css', PageStyles::apply( self::page( '' ), self::MARKER ) );
		$export = new \ReflectionProperty( \XB\StaticSite\ExportMode::class, 'active' );
		$export->setValue( null, true );
		try {
			$static = PageStyles::apply( self::page( '' ), self::MARKER );
		} finally {
			$export->setValue( null, false );
		}
		$this->assertStringNotContainsString( 'xb-global-css', $static );
		$this->assertStringContainsString( 'body{margin:2px}', self::xb_css( $static ) );

		// Off (the default): as before, everything inline.
		update_option( PageStyles::GLOBAL_FILE, false );
		$this->assertStringNotContainsString( 'xb-global-css', PageStyles::apply( self::page( '' ), self::MARKER ) );
	}

	public function test_full_mode_and_unknown_markup_leave_the_page_alone(): void {
		update_option( PageStyles::MODE, 'full' );
		$this->assertSame( 'full', PageStyles::mode() );
		$html = '<html><head></head><body>no marker</body></html>';
		$this->assertSame( $html, PageStyles::apply( $html, self::MARKER ) );
	}
}
