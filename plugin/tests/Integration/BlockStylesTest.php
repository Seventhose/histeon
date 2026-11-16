<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\HistosCss\ThemeJson;

/** WordPress's own blocks with Histeon CSS's tokens (lista della spesa 139): pickers and block styles. */
final class BlockStylesTest extends IntegrationTestCase {

	public function test_spaces_and_shadows_go_to_the_pickers_after_the_themes(): void {
		$data = new \WP_Theme_JSON_Data(
			array(
				'version'  => 3,
				'settings' => array( 'spacing' => array( 'spacingSizes' => array( array( 'slug' => 'theme-s', 'name' => 'S', 'size' => '1rem' ) ) ) ),
			),
			'theme'
		);
		$settings = ThemeJson::apply( $data )->get_data()['settings'];
		$spaces   = array_column( $settings['spacing']['spacingSizes']['theme'] ?? $settings['spacing']['spacingSizes'], 'size', 'slug' );
		$this->assertSame( '1rem', $spaces['theme-s'], 'the theme’s own stay' );
		$this->assertSame( 'var(--xb-space-m)', $spaces['xb-m'] );
		$shadows = array_column( $settings['shadow']['presets']['theme'] ?? $settings['shadow']['presets'], 'shadow', 'slug' );
		$this->assertSame( 'var(--xb-shadow-m)', $shadows['xb-m'] );
	}

	public function test_block_styles_are_registered_with_token_css_only(): void {
		ThemeJson::register_block_styles();
		$registry = \WP_Block_Styles_Registry::get_instance();
		foreach ( ThemeJson::block_styles() as $style ) {
			$this->assertTrue( $registry->is_registered( $style['block'], $style['name'] ), $style['block'] . ' ' . $style['name'] );
			// Values come from the tokens: the site's design restyles them; nothing loads from elsewhere.
			$this->assertStringContainsString( 'var(--xb-', $style['css'] );
			$this->assertStringNotContainsString( 'url(', $style['css'] );
			$this->assertStringStartsWith( '.wp-block-', $style['css'] );
		}
	}
}
