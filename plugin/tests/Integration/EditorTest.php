<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

final class EditorTest extends IntegrationTestCase {

	public function test_xb_pages_are_content_only_for_people_without_xb_build(): void {
		$post = get_post( $this->post( array( 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'x' ) ) ) ) ) ) ) );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$context = new \WP_Block_Editor_Context( array( 'post' => $post ) );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 'contentOnly', self::lock( get_block_editor_settings( array(), $context ) ) );

		wp_set_current_user( 1 );
		$this->assertNotSame( 'contentOnly', self::lock( get_block_editor_settings( array(), $context ) ) );
	}

	public function test_other_posts_are_not_locked(): void {
		$post = get_post( $this->post( array( 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) ) );
		$this->assertInstanceOf( \WP_Post::class, $post );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertNotSame( 'contentOnly', self::lock( get_block_editor_settings( array(), new \WP_Block_Editor_Context( array( 'post' => $post ) ) ) ) );
	}

	/**
	 * The stubs declare templateLock as always present; at runtime it is set only when a lock applies.
	 *
	 * @param array<string, mixed> $settings
	 */
	private static function lock( array $settings ): mixed {
		return $settings['templateLock'] ?? null;
	}

	public function test_builder_page_policy_allows_only_nonced_scripts_and_no_framing(): void {
		$policy = \XB\Admin\BuilderScreen::policy( 'abc123' );
		$this->assertStringContainsString( "script-src 'nonce-abc123'", $policy );
		$this->assertStringContainsString( "frame-ancestors 'none'", $policy );
		$this->assertStringContainsString( "object-src 'none'", $policy );
		$this->assertStringContainsString( "base-uri 'none'", $policy );
		$this->assertStringNotContainsString( 'unsafe-eval', $policy );
		$this->assertDoesNotMatchRegularExpression( "/script-src[^;]*(\\*|'unsafe-inline'|https?:)/", $policy );
	}

	public function test_the_only_worker_allowed_is_the_image_encoder_by_its_path_and_never_any_eval(): void {
		$policy  = \XB\Admin\BuilderScreen::policy( 'abc123' );
		$encoder = \XB\Admin\BuilderScreen::encoder_url();
		$this->assertStringNotContainsString( 'wasm-unsafe-eval', $policy );
		if ( null === $encoder ) {
			$this->assertStringContainsString( "worker-src 'none'", $policy );
			return;
		}
		$this->assertStringEndsWith( '/assets/builder/media/encoder.js', $encoder );
		$this->assertStringContainsString( 'worker-src ' . $encoder . ';', $policy );
		// Another origin for the plugin's files (a CDN) leaves the worker out: a module worker must be same-origin.
		$cdn = static fn ( string $url ): string => str_replace( (string) wp_parse_url( $url, PHP_URL_HOST ), 'cdn.example', $url );
		add_filter( 'plugins_url', $cdn );
		try {
			$this->assertNull( \XB\Admin\BuilderScreen::encoder_url() );
			$this->assertStringContainsString( "worker-src 'none'", \XB\Admin\BuilderScreen::policy( 'abc123' ) );
		} finally {
			remove_filter( 'plugins_url', $cdn );
		}
	}

	public function test_only_the_overlay_over_gutenberg_may_frame_the_builder_and_only_from_this_origin(): void {
		$policy = \XB\Admin\BuilderScreen::policy( 'abc123', true );
		$this->assertStringContainsString( "frame-ancestors 'self'", $policy );
		$this->assertStringNotContainsString( "frame-ancestors 'none'", $policy );
		$this->assertStringContainsString( 'embed=1', \XB\Admin\BuilderScreen::url( 9, true ) );
		$this->assertStringNotContainsString( 'embed', \XB\Admin\BuilderScreen::url( 9 ) );
	}

	public function test_the_gutenberg_button_is_offered_only_to_who_can_open_the_builder(): void {
		$page = $this->post( array( 'post_type' => 'page' ) );
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'page' );
		$GLOBALS['post'] = get_post( $page );
		\XB\Admin\EditorLaunch::enqueue();
		$this->assertTrue( wp_script_is( 'xb-launch', 'enqueued' ) );
		$this->assertStringContainsString( 'embed=1', (string) wp_scripts()->get_inline_script_data( 'xb-launch', 'before' ) );

		wp_dequeue_script( 'xb-launch' );
		wp_set_current_user( $this->user( 'editor' ) );
		\XB\Admin\EditorLaunch::enqueue();
		$this->assertFalse( wp_script_is( 'xb-launch', 'enqueued' ), 'no xb_build, no button' );
		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );
	}

	public function test_the_framework_button_appears_only_for_an_installed_framework_whose_page_this_user_can_open(): void {
		global $menu, $submenu;
		$saved = array( $menu, $submenu );
		$menu    = array();
		$submenu = array();
		$this->assertNull( \XB\Admin\BuilderScreen::framework_panel(), 'no admin page in the menu (no plugin, or no capability)' );
		$menu = array( array( 'Automatic CSS', 'manage_options', 'automatic-css' ), array( 'Core Framework', 'manage_options', 'core-framework' ) );
		$panel = \XB\Admin\BuilderScreen::framework_panel();
		$installed = array_filter( \XB\Plugin::instance()->frameworks->all(), static fn ( $p ): bool => in_array( $p->id(), array( 'acss', 'core-framework' ), true ) && $p->available() );
		if ( array() === $installed ) {
			$this->assertNull( $panel, 'a menu entry alone is not enough: the plugin must be active' );
		} else {
			$this->assertNotNull( $panel );
			$this->assertStringContainsString( '/wp-admin/admin.php?page=', $panel['url'] );
		}
		[ $menu, $submenu ] = $saved;
	}

	/** V6: the builder page carries the first answers, with the same permissions as the REST routes. */
	public function test_the_builder_page_carries_the_first_answers(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->text( 'avvio in linea' ) ) ) ) );
		$boot = \XB\Admin\BuilderScreen::boot( $page );
		$this->assertStringContainsString( 'avvio in linea', (string) $boot['document']['content'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $boot['document']['head'] );
		$this->assertArrayHasKey( 'styles', (array) $boot['styles'] );
		$this->assertIsArray( $boot['components'] );
		$this->assertArrayNotHasKey( 'framework', $boot, 'revalidated with its ETag instead' );

		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertSame( array(), \XB\Admin\BuilderScreen::boot( $page ), 'nothing for who may not open it' );
	}

}
