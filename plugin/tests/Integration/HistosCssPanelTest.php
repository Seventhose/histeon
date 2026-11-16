<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\Frameworks;
use XB\Framework\HistosCss\Panel;
use XB\Plugin;

/** The Histeon CSS panel: its live preview and who gets it — docs/spec/histos-css.md §6. */
final class HistosCssPanelTest extends IntegrationTestCase {

	public function test_the_preview_answers_css_built_from_validated_values(): void {
		$res = $this->request( 'POST', '/design/preview', array( 'design' => array( 'primary' => '#e5533d', 'radius' => 4 ) ) );
		$this->assertSame( 200, $res->get_status() );
		$css = (string) $res->get_data()['css'];
		$this->assertStringStartsWith( ':root{', $css );
		$this->assertStringContainsString( '--xb-radius-m:0.25rem;', $css );
		$this->assertSame( 1, preg_match( '/^:root\{[^{}<>]*\}/', $css ), 'nothing can close the rule' );
		$this->assertStringNotContainsString( '<', $css, 'nor the style element' );

		foreach ( array( array( 'font_body' => 'x;}body{display:none' ), array( 'primary' => '</style>' ), array( 'radius' => 999 ), array( 'evil' => 1 ) ) as $bad ) {
			$this->assertSame( 400, $this->request( 'POST', '/design/preview', array( 'design' => $bad ) )->get_status(), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_the_preview_gives_every_token_only_when_asked(): void {
		$this->assertArrayNotHasKey( 'tokens', $this->request( 'POST', '/design/preview', array( 'design' => array() ) )->get_data() );
		$tokens = $this->request( 'POST', '/design/preview', array( 'design' => array( 'dark_on' => 1 ), 'tokens' => true ) )->get_data()['tokens'];
		$this->assertSame( 'oklch(55% 0.2 265)', $tokens['light']['xb-primary'] );
		$this->assertNotSame( $tokens['light']['xb-bg'], $tokens['dark']['xb-bg'] );
	}

	public function test_the_preview_says_which_classes_are_off(): void {
		$this->assertSame( array(), $this->request( 'POST', '/design/preview', array( 'design' => array() ) )->get_data()['classesOff'] );
		$this->assertContains( '^xb-(?:enter|exit|hover)-', $this->request( 'POST', '/design/preview', array( 'design' => array( 'classes_motion' => 0 ) ) )->get_data()['classesOff'] );
	}

	public function test_only_builders_get_the_preview_and_the_panel(): void {
		wp_set_current_user( $this->user( 'author' ) );
		$this->assertContains( $this->request( 'POST', '/design/preview', array( 'design' => array() ) )->get_status(), array( 401, 403 ) );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new \WP_Admin_Bar();
		Panel::admin_bar( $bar );
		$this->assertNull( $bar->get_node( 'xb-histos-css' ), 'no panel for who cannot build' );
		wp_set_current_user( 1 );
		add_filter( 'show_admin_bar', '__return_true' );
		Panel::admin_bar( $bar );
		remove_filter( 'show_admin_bar', '__return_true' );
		$this->assertNotNull( $bar->get_node( 'xb-histos-css' ) );
	}

	public function test_the_block_editor_offers_the_palette_and_the_sizes(): void {
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		$this->assertTrue( Plugin::instance()->frameworks->select( 'xb-tokens' ) );
		wp_clean_theme_json_cache();
		$palette = array_column( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ), 'color', 'slug' );
		$this->assertSame( 'var(--xb-primary)', $palette['xb-primary'] ?? null );
		$this->assertSame( 'var(--xb-accent-700)', $palette['xb-accent-700'] ?? null );
		$sizes = array_column( (array) wp_get_global_settings( array( 'typography', 'fontSizes', 'theme' ) ), 'size', 'slug' );
		$this->assertSame( 'var(--xb-text-l)', $sizes['xb-l'] ?? null );
		wp_clean_theme_json_cache();
	}

	public function test_the_drawer_is_the_panel_built_with_the_editor(): void {
		wp_set_current_user( 1 );
		add_filter( 'show_admin_bar', '__return_true' );
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		$this->assertTrue( Plugin::instance()->frameworks->select( 'xb-tokens' ) );
		Panel::enqueue();
		remove_filter( 'show_admin_bar', '__return_true' );
		$script = wp_scripts()->registered[ Panel::HANDLE ] ?? null;
		$this->assertNotNull( $script );
		$this->assertStringEndsWith( '/' . Panel::SCRIPT, (string) $script->src );
		$data = (string) wp_scripts()->get_data( Panel::HANDLE, 'data' );
		$this->assertStringContainsString( '"english":', $data );
		$this->assertStringNotContainsString( '"i18n":', $data, 'the texts come with the panel, from the editor catalog' );
		$this->assertStringContainsString( '"tokensCss":', $data );
		wp_dequeue_script( Panel::HANDLE );
		wp_deregister_script( Panel::HANDLE );
	}

	public function test_a_site_with_groups_off_gets_its_own_tokens_sheet(): void {
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		$this->assertTrue( Plugin::instance()->frameworks->select( 'xb-tokens' ) );
		$this->assertSame( \XB\Framework\XbTokensProvider::shipped_file(), \XB\Framework\XbTokensProvider::file() );
		$this->set_option( \XB\Framework\HistosCss\Design::OPTION, array( 'print_alphas' => 0 ) );
		$file = \XB\Framework\XbTokensProvider::file();
		$this->assertNotSame( \XB\Framework\XbTokensProvider::shipped_file(), $file );
		$this->assertMatchesRegularExpression( '#/xb-histos-css/xb-tokens-[0-9a-f]{20}\.css$#', $file );
		$css = (string) file_get_contents( $file );
		$this->assertStringContainsString( '--xb-primary-700:', $css );
		$this->assertStringNotContainsString( '--xb-primary-a10:', $css );
		$this->assertStringContainsString( '/xb-histos-css/', \XB\Framework\XbTokensProvider::url() );
		$variables = ( new \XB\Framework\XbTokensProvider() )->variables()['xb'];
		$this->assertNotContains( 'xb-primary-a10', $variables, 'the CSS panel flags it as unknown' );
		$this->assertContains( 'xb-primary-700', $variables );
		wp_delete_file( $file );
	}

	public function test_tokens_can_print_in_the_page(): void {
		$this->set_option( \XB\Framework\HistosCss\Design::OPTION, array( 'tokens_inline' => 1 ) );
		wp_deregister_style( 'xb-tokens' );
		Plugin::instance()->frameworks->enqueue();
		$this->assertTrue( wp_style_is( 'xb-tokens', 'enqueued' ) );
		$this->assertFalse( wp_styles()->registered['xb-tokens']->src );
		$this->assertStringContainsString( '--xb-primary-700:', implode( '', (array) wp_styles()->get_data( 'xb-tokens', 'after' ) ) );
	}

	public function test_the_canvas_takes_the_sheets_of_the_design_in_development(): void {
		$this->set_option( \XB\Framework\HistosCss\Design::OPTION, array() );
		wp_set_current_user( 1 );
		$copy = Plugin::instance()->versioning->working_copy( \XB\Versioning\Documents::SETTINGS );
		Plugin::instance()->versioning->save_dev( \XB\Versioning\Documents::SETTINGS, array_merge( $copy['payload'], array( 'design' => array( 'print_alphas' => 0 ) ) ), (string) $copy['head'], (string) $copy['base'] );
		$live = \XB\Framework\XbTokensProvider::url();
		$this->assertStringContainsString( 'assets/tokens/xb-tokens.css', $live );
		$this->assertStringContainsString( '/xb-histos-css/xb-tokens-', \XB\Framework\XbTokensProvider::canvas_stylesheets()[0] );
	}

	public function test_the_block_palette_can_stay_the_themes(): void {
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		$this->assertTrue( Plugin::instance()->frameworks->select( 'xb-tokens' ) );
		$this->set_option( \XB\Framework\HistosCss\Design::OPTION, array( 'block_palette' => 0 ) );
		wp_clean_theme_json_cache();
		$palette = array_column( (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) ), 'color', 'slug' );
		$this->assertArrayNotHasKey( 'xb-primary', $palette );
		wp_clean_theme_json_cache();
	}

	public function test_groups_of_classes_switched_off_are_not_printed_nor_offered(): void {
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		$this->assertTrue( Plugin::instance()->frameworks->select( 'xb-tokens' ) );
		$shipped = \XB\Framework\XbTokensProvider::classes_file();
		$this->assertStringEndsWith( '/assets/framework/histos.css', $shipped );
		$this->set_option( \XB\Framework\HistosCss\Design::OPTION, array( 'classes_buttons' => 0, 'classes_motion' => 0 ) );
		$file = \XB\Framework\XbTokensProvider::classes_file();
		$this->assertMatchesRegularExpression( '#/xb-histos-css/histos-css-[0-9a-f]{20}\.css$#', $file );
		$css = (string) file_get_contents( $file );
		$this->assertStringNotContainsString( "\n.xb-btn-danger {", $css );
		$this->assertStringNotContainsString( '@keyframes xb-enter-up', $css );
		$this->assertStringContainsString( "\n.xb-container {", $css );
		$classes = array_merge( ...array_values( ( new \XB\Framework\XbTokensProvider() )->classes() ) );
		$this->assertNotContains( 'xb-btn-primary', $classes );
		$this->assertContains( 'xb-card', $classes );
		wp_delete_file( $file );
	}
}
