<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Import\Etch\Runtime;
use XB\Plugin;
use XB\Styles\Presence;

/**
 * Etch turned off, the site unchanged (docs/spec/import-etch.md §7). The Etch-shaped content below is written for
 * these tests from the format described in the spec; no third-party data or code is used. Etch is not installed in
 * the dev WordPress, so its blocks are not registered: exactly the situation of a site that turned it off.
 */
final class EtchRuntimeTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! Runtime::active() ) {
			$this->markTestSkipped( 'Etch è attivo nel WordPress di sviluppo.' );
		}
		foreach ( array( 'etch_loops', 'etch_global_stylesheets', 'etch_styles', 'xb_global_styles' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		update_option( 'xb_global_styles', array_values( array_filter( (array) get_option( 'xb_global_styles', array() ), static fn ( mixed $g ): bool => is_array( $g ) && ! str_starts_with( (string) ( $g['id'] ?? '' ), 'etch-' ) ) ) );
		update_option(
			'etch_loops',
			array(
				'l1' => array(
					'key'    => 'nomi',
					'config' => array(
						'type' => 'json',
						'data' => array( array( 'name' => 'Uno' ), array( 'name' => 'Due' ) ),
					),
				),
			)
		);
		update_option(
			'etch_styles',
			array(
				's1' => array( 'selector' => '.rt-hero', 'css' => 'color: rgb(1, 2, 3);', 'readonly' => false ),
				's3' => array( 'selector' => '.rt-js-open', 'css' => 'display: block;', 'readonly' => false ),
				's2' => array( 'selector' => '.rt-default', 'css' => 'color: rgb(9, 9, 9);', 'readonly' => true ),
			)
		);
		update_option( 'etch_global_stylesheets', array( 'g1' => array( 'name' => 'Base', 'css' => '.rt-global { margin: 7px; }', 'type' => 'custom' ) ) );
	}

	/** @param array<string, mixed> $attrs */
	private static function etch( string $name, array $attrs, string $inner = '' ): string {
		$json = (string) wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return '' === $inner ? "<!-- wp:etch/$name $json /-->" : "<!-- wp:etch/$name $json -->$inner<!-- /wp:etch/$name -->";
	}

	private function render_page( int $id ): string {
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$html = (string) apply_filters( 'the_content', get_post_field( 'post_content', $id, 'raw' ) );
		wp_reset_postdata();
		return $html;
	}

	public function test_etch_content_renders_without_etch(): void {
		wp_set_current_user( 1 );
		$component = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Card RT',
				'post_author'  => 1,
				'post_content' => self::etch( 'element', array( 'tag' => 'aside', 'attributes' => array( 'class' => 'rt-card' ) ), self::etch( 'text', array( 'content' => 'Dal componente' ) ) ),
			)
		);
		$code = 'window.rtEtch = "</script><b>no</b>"; document.body.classList.add("rt-js-open");';
		$id   = $this->post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Pagina RT',
				'post_author'  => 1,
				'post_content' => self::etch(
					'element',
					array( 'tag' => 'section', 'attributes' => array( 'class' => 'rt-hero rt-global' ), 'script' => array( 'id' => 's', 'code' => base64_encode( $code ) ) ),
					self::etch( 'text', array( 'content' => 'Ciao {this.title}' ) )
						. self::etch( 'loop', array( 'loopId' => 'l1', 'itemId' => 'n' ), self::etch( 'element', array( 'tag' => 'span', 'attributes' => array( 'class' => 'rt-item' ) ), self::etch( 'text', array( 'content' => '{n.name}' ) ) ) )
						. self::etch( 'component', array( 'ref' => $component ) )
				),
			)
		);
		update_post_meta( $id, '_edit_last', 1 );
		update_post_meta( $component, '_edit_last', 1 );

		$html = $this->render_page( $id );
		$this->assertStringContainsString( '<section class="rt-hero rt-global">', $html );
		$this->assertStringContainsString( 'Ciao Pagina RT', $html, 'expressions work' );
		$this->assertStringContainsString( '<span class="rt-item">Uno</span>', $html, 'loops work (Etch query as a preset for the request)' );
		$this->assertStringContainsString( '<span class="rt-item">Due</span>', $html );
		$this->assertStringContainsString( '<aside class="rt-card">Dal componente</aside>', $html, 'components work' );
		$this->assertStringNotContainsString( 'wp:etch', $html );
		$this->assertStringNotContainsString( 'data-xb-uid', $html, 'no runtime uid on the public page' );

		ob_start();
		Runtime::print_scripts();
		$scripts = (string) ob_get_clean();
		$this->assertStringContainsString( 'window.rtEtch', $scripts, 'a trusted author\'s element script runs, as with Etch' );
		$this->assertStringNotContainsString( '</script><b>', $scripts, 'and cannot close its element' );

		$this->assertContains( '.rt-js-open', \XB\Styles\PageStyles::safelist(), 'a class the Etch script adds later is kept by the on-demand CSS, as the import does' );
		Runtime::forget();
		$this->assertTrue( \XB\Framework\Reset::enabled(), 'Histeon\'s reset is on while Etch content is rendered, as the import turns it on' );

		$css = Plugin::instance()->styles->css_for( Presence::from_html( $html ) );
		$this->assertMatchesRegularExpression( '/\.rt-hero\s*\{[^}]*rgb\(1, ?2, ?3\)/', $css, 'Etch selector styles, on demand' );
		$this->assertStringContainsString( '.rt-global', $css, 'Etch global sheets' );
		$this->assertStringNotContainsString( 'rt-default', $css, 'not Etch\'s read-only defaults' );
		$this->assertSame( $html, $this->render_page( $id ), 'the same page every time' );
	}

	public function test_types_the_etch_queries_list_are_allowed_like_the_import_does(): void {
		register_post_type( 'rt_hidden', array( 'public' => false, 'publicly_queryable' => false, 'show_ui' => true ) );
		update_option( 'etch_loops', array( 'h1' => array( 'key' => 'nascosti', 'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'rt_hidden' ) ) ) ) );
		$reset = \Closure::bind( static function (): void { self::$loops = null; }, null, Runtime::class );
		$reset();
		$this->assertTrue( \XB\Render\LoopPresets::listable( 'rt_hidden' ), 'named by a query of the site' );
		$this->assertFalse( \XB\Render\LoopPresets::listable( 'wp_navigation' ), 'never WordPress internals' );
		unregister_post_type( 'rt_hidden' );
		$reset();
	}

	public function test_untrusted_etch_content_is_sanitized(): void {
		$author = $this->user( 'author' );
		$id     = $this->post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_content' => self::etch(
					'element',
					array( 'tag' => 'div', 'attributes' => array( 'class' => 'rt-untrusted', 'onclick' => 'alert(1)' ), 'script' => array( 'id' => 's', 'code' => base64_encode( 'window.rtBad = 1;' ) ) ),
					self::etch( 'text', array( 'content' => 'Testo' ) )
				),
			)
		);
		update_post_meta( $id, '_edit_last', $author );
		$html = $this->render_page( $id );
		$this->assertStringContainsString( 'rt-untrusted', $html );
		$this->assertStringContainsString( 'Testo', $html );
		$this->assertStringNotContainsString( 'onclick', $html, 'restricted pipeline, as in the import' );
		ob_start();
		Runtime::print_scripts();
		$this->assertStringNotContainsString( 'rtBad', (string) ob_get_clean(), 'no script from an author without unfiltered_html' );
	}

	/** A synced pattern keeps its own author's trust inside a trusted page (threat model T21). */
	public function test_an_untrusted_synced_pattern_stays_untrusted_inside_a_trusted_page(): void {
		$author  = $this->user( 'author' );
		$pattern = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_content' => self::etch(
					'element',
					array( 'tag' => 'div', 'attributes' => array( 'class' => 'rt-pattern', 'onclick' => 'alert(1)' ), 'script' => array( 'id' => 'p', 'code' => base64_encode( 'window.rtPattern = 1;' ) ) ),
					self::etch( 'text', array( 'content' => 'Dal pattern' ) )
				),
			)
		);
		update_post_meta( $pattern, '_edit_last', $author );
		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:block {"ref":' . $pattern . '} /-->' ) );
		$html = $this->render_page( $page );
		$this->assertStringContainsString( 'Dal pattern', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		ob_start();
		Runtime::print_scripts();
		$this->assertStringNotContainsString( 'rtPattern', (string) ob_get_clean(), 'the pattern author has no unfiltered_html' );
	}

	/**
	 * Core renders a synced pattern twice through render_block: a trusted pattern nested in an untrusted one must
	 * not make the rest of the untrusted pattern trusted when it ends.
	 */
	public function test_a_nested_synced_pattern_keeps_the_untrusted_pattern_around_it(): void {
		$author = $this->user( 'author' );
		$inner  = $this->post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => self::etch( 'element', array( 'tag' => 'p' ), self::etch( 'text', array( 'content' => 'Interno' ) ) ) ) );
		$outer  = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_content' => '<!-- wp:block {"ref":' . $inner . '} /-->' . self::etch(
					'element',
					array( 'tag' => 'div', 'attributes' => array( 'class' => 'rt-after', 'onclick' => 'alert(1)' ), 'script' => array( 'id' => 'o', 'code' => base64_encode( 'window.rtOuter = 1;' ) ) ),
					self::etch( 'text', array( 'content' => 'Dopo' ) )
				),
			)
		);
		update_post_meta( $outer, '_edit_last', $author );
		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:block {"ref":' . $outer . '} /-->' ) );
		$html = $this->render_page( $page );
		$this->assertStringContainsString( 'Interno', $html );
		$this->assertStringContainsString( 'Dopo', $html );
		$this->assertStringNotContainsString( 'onclick', $html, 'still the untrusted pattern after the nested one' );
		ob_start();
		Runtime::print_scripts();
		$this->assertStringNotContainsString( 'rtOuter', (string) ob_get_clean() );
	}

	/**
	 * Someone who may edit an administrator's page but has no unfiltered_html (a client, or an administrator of a
	 * multisite sub-site) saves it through core: a new Etch script or handler is dropped, the existing one is kept.
	 */
	public function test_a_restricted_editor_cannot_add_an_etch_script_to_a_trusted_page(): void {
		$kept = self::etch( 'element', array( 'tag' => 'div', 'attributes' => array( 'class' => 'rt-kept' ), 'script' => array( 'id' => 'k', 'code' => base64_encode( 'window.rtKept = 1;' ) ) ), self::etch( 'text', array( 'content' => 'Tenuto' ) ) );
		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $kept ) );
		$editor = $this->user( 'editor' );
		( new \WP_User( $editor ) )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( $editor );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$added = self::etch( 'element', array( 'tag' => 'div', 'attributes' => array( 'class' => 'rt-added', 'onclick' => 'alert(1)' ), 'script' => array( 'id' => 'a', 'code' => base64_encode( 'window.rtAdded = 1;' ) ) ), self::etch( 'text', array( 'content' => 'Aggiunto' ) ) );
		wp_update_post( wp_slash( array( 'ID' => $page, 'post_content' => $kept . $added ) ) );
		$stored = get_post_field( 'post_content', $page, 'raw' );
		$this->assertIsString( $stored );
		$this->assertStringContainsString( base64_encode( 'window.rtKept = 1;' ), $stored, 'the stored script is kept as it was' );
		$this->assertStringNotContainsString( base64_encode( 'window.rtAdded = 1;' ), $stored );
		$this->assertStringNotContainsString( 'onclick', $stored );
		$this->assertStringContainsString( 'rt-added', $stored, 'the element stays, without script and handler' );
	}

	public function test_conversions_are_cached_by_what_they_depend_on(): void {
		$code    = 'window.rtCached = 1;';
		$content = self::etch(
			'element',
			array( 'tag' => 'div', 'attributes' => array( 'class' => 'rt-cached', 'onclick' => 'alert(1)' ), 'script' => array( 'id' => 's', 'code' => base64_encode( $code ) ) ),
			self::etch( 'text', array( 'content' => 'In cache' ) )
		);
		$blocks  = parse_blocks( $content );
		$trusted = Runtime::cache_key( $blocks, true, array() );
		$this->assertNotSame( $trusted, Runtime::cache_key( $blocks, false, array() ), 'trusted and restricted content never share an entry' );
		$this->assertNotSame( $trusted, Runtime::cache_key( parse_blocks( str_replace( 'In cache', 'Cambiato', $content ) ), true, array() ), 'other content, other key' );
		$this->assertNotSame( $trusted, Runtime::cache_key( $blocks, true, array( 9 => 'card' ) ), 'other component keys, other key' );

		// A clean start: an entry from an earlier run stays a day in its transient.
		delete_transient( $trusted );
		wp_cache_delete( $trusted, 'xb_etch_runtime' );
		Runtime::queries_changed();
		$first = Runtime::convert( $blocks, true );
		$this->assertIsArray( wp_cache_get( $trusted, 'xb_etch_runtime' ), 'stored after the first conversion' );
		if ( ! wp_using_ext_object_cache() ) {
			$this->assertIsArray( get_transient( $trusted ), 'and kept between requests without an object cache' );
		}
		$this->assertSame( $first, Runtime::convert( $blocks, true ), 'the same blocks from the cache' );
		ob_start();
		Runtime::print_scripts();
		$this->assertStringContainsString( 'window.rtCached', (string) ob_get_clean(), 'a cached conversion still brings its trusted script' );

		$restricted = \XB\Blocks::serialize( Runtime::convert( $blocks, false ) );
		$this->assertStringNotContainsString( 'onclick', $restricted, 'restricted content is sanitized, even with a trusted entry in the cache' );
		ob_start();
		Runtime::print_scripts();
		$this->assertStringNotContainsString( 'rtCached', (string) ob_get_clean(), 'and has no script' );

		$image = $this->post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ) );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Nuovo testo alternativo' );
		$this->assertNotSame( $trusted, Runtime::cache_key( $blocks, true, array() ), 'media changed: every entry is stale' );
		delete_option( Runtime::GENERATION );
		wp_delete_attachment( $image, true );
	}

	public function test_a_block_it_cannot_convert_draws_nothing_instead_of_itself_forever(): void {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_content' => self::etch( 'element', array( 'tag' => 'div', 'attributes' => array( 'class' => 'rt-orphan' ) ), self::etch( 'loop', array( 'loopId' => 'cancellata', 'itemId' => 'x' ), self::etch( 'text', array( 'content' => '{x.name}' ) ) ) . self::etch( 'text', array( 'content' => 'Resto della pagina' ) ) ),
			)
		);
		update_post_meta( $id, '_edit_last', 1 );
		$html = $this->render_page( $id );
		$this->assertStringContainsString( '<div class="rt-orphan">', $html );
		$this->assertStringContainsString( 'Resto della pagina', $html, 'the rest of the page renders' );
		$this->assertStringNotContainsString( 'wp:etch', $html );
	}

	public function test_changed_etch_queries_are_read_again(): void {
		update_option( 'etch_loops', array( 'l9' => array( 'key' => 'nove', 'config' => array( 'type' => 'json', 'data' => array( array( 'name' => 'Nove' ) ) ) ) ) );
		$blocks = parse_blocks( self::etch( 'loop', array( 'loopId' => 'l9', 'itemId' => 'n' ), self::etch( 'text', array( 'content' => '{n.name}' ) ) ) );
		$this->assertStringNotContainsString( 'etch/loop', \XB\Blocks::serialize( Runtime::convert( $blocks, true ) ), 'a query added a moment ago is known' );
	}

	/**
	 * With Etch off its styles reach the page as the import brings them: custom media queries resolved, in the order
	 * Etch printed them, with the read-only defaults the elements use.
	 */
	public function test_styles_are_served_as_the_import_brings_them(): void {
		update_option(
			'etch_global_stylesheets',
			array(
				'cm' => array( 'name' => 'Custom media', 'type' => 'custom-media', 'css' => '@custom-media --rt-narrow (width < 50em);' ),
				'g1' => array( 'name' => 'Base', 'css' => '@media (--rt-narrow) { .rt-global { margin: 7px; } }' ),
			)
		);
		update_option(
			'etch_styles',
			array(
				'b'  => array( 'selector' => '.rt-burger', 'css' => "display: none;\n  @media (--rt-narrow) { display: block; }" ),
				'i'  => array( 'selector' => '.rt-icon', 'css' => 'display: inline-flex;' ),
				'ro' => array( 'selector' => ':where([data-etch-element="section"])', 'css' => 'flex-direction: column;', 'readonly' => true ),
				'r2' => array( 'selector' => ':where([data-etch-element="iframe"])', 'css' => 'aspect-ratio: 16 / 9;', 'readonly' => true ),
			)
		);
		$this->post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_content' => self::etch( 'element', array( 'tag' => 'section', 'attributes' => array( 'data-etch-element' => 'section' ), 'styles' => array( 'ro' ) ), self::etch( 'element', array( 'tag' => 'a', 'attributes' => array( 'class' => 'rt-icon' ), 'styles' => array( 'i' ) ) ) . self::etch( 'element', array( 'tag' => 'button', 'attributes' => array( 'class' => 'rt-burger rt-icon' ), 'styles' => array( 'b', 'i' ) ) ) ),
			)
		);
		Runtime::forget();
		$css = Runtime::css( '' );
		$this->assertStringContainsString( '@media (width < 50em)', $css );
		$this->assertStringNotContainsString( '--rt-narrow', $css, 'no custom media left, definitions included' );
		$this->assertLessThan( strpos( $css, '.rt-burger' ), (int) strpos( $css, '.rt-icon' ), 'the order Etch printed' );
		$this->assertStringContainsString( ':where([data-etch-element="section"])', $css, 'a default an element uses' );
		$this->assertStringNotContainsString( 'iframe', $css, 'not one no element uses' );
		Runtime::forget();
	}
}
