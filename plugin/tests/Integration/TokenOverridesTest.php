<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\SiteRecipes;
use XB\Framework\SiteTokens;
use XB\Framework\TokenOverrides;
use XB\Plugin;
use XB\Versioning\Documents;

/** The site's own Histeon Tokens values — docs/spec/design-system.md. */
final class TokenOverridesTest extends IntegrationTestCase {

	private ?string $framework = null;

	protected function setUp(): void {
		parent::setUp();
		$this->framework = Plugin::instance()->frameworks->active()->id();
		Plugin::instance()->frameworks->select( 'xb-tokens' );
	}

	protected function tearDown(): void {
		delete_option( TokenOverrides::OPTION );
		delete_option( SiteTokens::OPTION );
		delete_option( SiteRecipes::OPTION );
		if ( null !== $this->framework ) {
			Plugin::instance()->frameworks->select( $this->framework );
		}
		parent::tearDown();
	}

	public function test_the_gutenberg_canvas_keeps_the_site_values_after_the_defaults(): void {
		update_option( TokenOverrides::OPTION, array( 'xb-font-body' => 'Gentium, serif' ) );
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		$styles               = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_styles'] = new \WP_Styles();
		set_current_screen( 'page' );
		try {
			Plugin::on_enqueue_block_assets();
			ob_start();
			wp_styles()->do_items();
			$head = (string) ob_get_clean();
		} finally {
			set_current_screen( 'front' );
			$GLOBALS['wp_styles'] = $styles;
		}
		// The site's value comes after every sheet of defaults: the canvas shows the site's font, not the system's.
		$this->assertStringNotContainsString( 'xb-framework-editor-', $head );
		$this->assertMatchesRegularExpression( '/xb-tokens[^\'"]*\.css/', $head );
		$at = strpos( $head, 'Gentium, serif' );
		$this->assertIsInt( $at );
		$this->assertDoesNotMatchRegularExpression( '/xb-tokens[^\'"]*\.css/', substr( $head, $at ) );
	}

	public function test_only_known_tokens_and_plain_values(): void {
		$this->assertContains( 'xb-primary', TokenOverrides::names() );
		$ok = TokenOverrides::validate( array( 'xb-primary' => 'oklch(60% 0.2 30)', '--xb-space-m' => 'clamp(1rem, 0.9rem + 0.5vw, 1.5rem)', 'xb-radius-m' => '0.75rem' ) );
		$this->assertSame( array( 'xb-primary', 'xb-radius-m', 'xb-space-m' ), array_keys( $ok ) );
		foreach (
			array(
				'unknown token'   => array( 'xb-nuovo' => '1rem' ),
				'rule break'      => array( 'xb-primary' => 'red;} body{display:none' ),
				'url'             => array( 'xb-primary' => 'url(https://evil.example/x)' ),
				'at-rule'         => array( 'xb-primary' => '@import x' ),
				'important'       => array( 'xb-primary' => 'red !important' ),
				'markup'          => array( 'xb-primary' => '</style><script>' ),
				'comment'         => array( 'xb-primary' => 'red /* x */' ),
				'quote'           => array( 'xb-primary' => '"x"' ),
				'unbalanced'      => array( 'xb-space-m' => 'calc(1rem + (2px)' ),
				'not text'        => array( 'xb-space-m' => array( '1rem' ) ),
				'empty'           => array( 'xb-space-m' => ' ' ),
			) as $label => $case
		) {
			try {
				TokenOverrides::validate( $case );
				$this->fail( "accepted: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_saved_in_dev_seen_by_the_canvas_published_with_a_release(): void {
		$doc = $this->request( 'GET', '/tokens' )->get_data();
		$this->assertTrue( $doc['editable'] );
		$res = $this->request( 'POST', '/tokens', array( 'tokens' => array( 'xb-primary' => 'oklch(60% 0.2 30)' ), 'head' => (string) $doc['head'], 'base' => (string) $doc['base'] ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( array( 'xb-primary' => 'oklch(60% 0.2 30)' ), Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['payload']['tokens'] );
		$this->assertSame( '', TokenOverrides::css(), 'the site waits for a release' );
		$this->assertSame( ':root{--xb-primary:oklch(60% 0.2 30);}', TokenOverrides::css( true ), 'the canvas sees dev' );

		$bad = $this->request( 'POST', '/tokens', array( 'tokens' => array( 'xb-primary' => 'red;}' ), 'head' => (string) $res->get_data()['head'], 'base' => (string) $doc['base'] ) );
		$this->assertSame( 400, $bad->get_status() );

		$published = $this->publish( array( Documents::SETTINGS ) );
		$this->assertContains( $published->get_status(), array( 200, 201 ), (string) wp_json_encode( $published->get_data() ) );
		$this->assertSame( ':root{--xb-primary:oklch(60% 0.2 30);}', TokenOverrides::css() );
		// The settings release also carries the framework saved in dev: Histeon Tokens again for this check.
		Plugin::instance()->frameworks->select( 'xb-tokens' );
		wp_dequeue_style( 'xb-tokens' );
		Plugin::instance()->frameworks->enqueue();
		$this->assertStringContainsString( '--xb-primary:oklch(60% 0.2 30)', implode( '', (array) wp_styles()->get_data( 'xb-tokens', 'after' ) ) );

		wp_set_current_user( $this->user( 'author' ) );
		$this->assertSame( 403, $this->request( 'GET', '/tokens' )->get_status() );
	}

	public function test_site_variables_and_recipes_only_safe_values(): void {
		$this->assertSame( array( 'bagliore' => 'var(--xb-primary-300)', 'card-gap' => '1.25rem' ), SiteTokens::validate( array( 'card-gap' => ' 1.25rem ', 'bagliore' => 'var(--xb-primary-300)' ) ) );
		foreach (
			array(
				'upper case'      => array( 'Maiuscolo' => '1rem' ),
				'empty name'      => array( '' => '1rem' ),
				'double dash'     => array( 'a--b' => '1rem' ),
				'trailing dash'   => array( 'a-' => '1rem' ),
				'rule break'      => array( 'a' => 'red;} body{display:none' ),
				'url'             => array( 'a' => 'url(https://evil.example/x)' ),
				'not text'        => array( 'a' => array( '1rem' ) ),
			) as $label => $case
		) {
			try {
				SiteTokens::validate( $case );
				$this->fail( "accepted variable: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->assertSame( array( 'riquadro' => "padding: var(--xb-space-m);\n&:hover { opacity: 0.9; }" ), SiteRecipes::validate( array( 'riquadro' => "padding: var(--xb-space-m);\n&:hover { opacity: 0.9; }" ) ) );
		foreach (
			array(
				'markup'      => '</style><script>alert(1)</script>',
				'url'         => 'background: url(https://evil.example/x.png);',
				'import'      => '@import "x.css";',
				'expression'  => 'width: expression(alert(1));',
				'binding'     => '-moz-binding: x;',
				'unbalanced'  => 'a { b',
				'closing'     => '} body { display: none',
				'empty'       => '  ',
				'too long'    => str_repeat( 'a', SiteRecipes::MAX_BYTES + 1 ),
			) as $label => $css
		) {
			try {
				SiteRecipes::validate( array( 'ricetta' => $css ) );
				$this->fail( "accepted recipe: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_site_variables_and_recipes_saved_in_dev_and_published(): void {
		$doc = $this->request( 'GET', '/tokens' )->get_data();
		$this->assertArrayHasKey( 'centra', (array) $doc['recipes']['histos'], 'the Histeon recipes come with the document' );
		$res = $this->request(
			'POST',
			'/tokens',
			array(
				'tokens'     => array(),
				'siteTokens' => array( 'bagliore' => 'oklch(80% 0.1 30)' ),
				'recipes'    => array( 'centra' => 'display: grid; place-items: start;', 'mia' => 'gap: var(--xb-site-bagliore);' ),
				'head'       => (string) $doc['head'],
				'base'       => (string) $doc['base'],
			)
		);
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( '', TokenOverrides::css(), 'the site waits for a release' );
		$this->assertSame( ':root{--xb-site-bagliore:oklch(80% 0.1 30);}', TokenOverrides::css( true ), 'the canvas sees dev' );
		$framework = $this->request( 'GET', '/framework' )->get_data();
		$this->assertSame( 'display: grid; place-items: start;', ( (array) $framework['recipes'] )['centra'], 'the site recipe wins over Histeon' );
		$this->assertContains( 'xb-site-bagliore', array_column( $framework['variables'], 'name' ) );

		$bad = $this->request( 'POST', '/tokens', array( 'tokens' => array(), 'recipes' => array( 'x' => 'a{' ), 'head' => (string) $res->get_data()['head'], 'base' => (string) $doc['base'] ) );
		$this->assertSame( 400, $bad->get_status() );
		$kept = $this->request( 'POST', '/tokens', array( 'tokens' => array( 'xb-primary' => 'red' ), 'head' => (string) $res->get_data()['head'], 'base' => (string) $doc['base'] ) );
		$this->assertSame( 200, $kept->get_status() );
		$this->assertArrayHasKey( 'mia', Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['payload']['recipes'], 'without recipes in the request they stay' );

		$published = $this->publish( array( Documents::SETTINGS ) );
		$this->assertContains( $published->get_status(), array( 200, 201 ), (string) wp_json_encode( $published->get_data() ) );
		$this->assertSame( ':root{--xb-primary:red;--xb-site-bagliore:oklch(80% 0.1 30);}', TokenOverrides::css() );
	}
}
