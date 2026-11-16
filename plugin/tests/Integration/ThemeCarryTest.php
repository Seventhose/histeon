<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Templates\ThemeCarry;

/** The site's templates follow it from XB Blank to Fondamenta and back (docs/spec/fondamenta.md §3). */
final class ThemeCarryTest extends IntegrationTestCase {

	private string $theme = '';

	protected function setUp(): void {
		parent::setUp();
		$this->theme = get_stylesheet();
	}

	protected function tearDown(): void {
		if ( get_stylesheet() !== $this->theme ) {
			switch_theme( $this->theme );
		}
		parent::tearDown();
	}

	private function saved( string $type, string $slug, string $theme ): int {
		$id = $this->post(
			array(
				'post_type'    => $type,
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( $slug . ' di ' . $theme ) ) ) ) ),
			)
		);
		wp_set_post_terms( $id, array( $theme ), 'wp_theme' );
		// WordPress made the name unique against the active theme at insert time: the test wants it exact.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		return $id;
	}

	private static function theme_of( int $id ): string {
		$terms = wp_get_post_terms( $id, 'wp_theme', array( 'fields' => 'names' ) );
		return is_array( $terms ) ? implode( ',', $terms ) : '';
	}

	public function test_switch_carries_templates_parts_and_styles_both_ways(): void {
		$this->assertTrue( wp_get_theme( 'xb-blank' )->exists() && wp_get_theme( 'fondamenta' )->exists(), 'i due temi montati nel WordPress di sviluppo' );
		switch_theme( 'xb-blank' );
		$template = $this->saved( 'wp_template', 'single-prova-tema', 'xb-blank' );
		$part     = $this->saved( 'wp_template_part', 'prova-testata', 'xb-blank' );
		$styles   = $this->saved( 'wp_global_styles', 'wp-global-styles-xb-blank', 'xb-blank' );
		wp_update_post(
			array(
				'ID'           => $styles,
				'post_content' => wp_slash( '{"version":3,"styles":{"color":{"text":"#123456"}},"isGlobalStylesUserThemeJSON":true}' ),
			)
		);
		// Fondamenta already has its own template with this name: that one wins, the old one stays behind.
		$clash_old = $this->saved( 'wp_template', 'prova-doppio', 'xb-blank' );
		$clash_new = $this->saved( 'wp_template', 'prova-doppio', 'fondamenta' );

		switch_theme( 'fondamenta' );
		$this->assertSame( 'fondamenta', self::theme_of( $template ) );
		$this->assertSame( 'fondamenta', self::theme_of( $part ) );
		$this->assertSame( 'fondamenta', self::theme_of( $styles ) );
		$this->assertSame( 'wp-global-styles-fondamenta', get_post_field( 'post_name', $styles ) );
		$this->assertSame( 'xb-blank', self::theme_of( $clash_old ) );
		$this->assertSame( 'fondamenta', self::theme_of( $clash_new ) );
		$found = get_block_template( 'fondamenta//single-prova-tema' );
		$this->assertInstanceOf( \WP_Block_Template::class, $found );
		$this->assertSame( $template, $found->wp_id );

		global $wpdb;
		$audit = $wpdb->get_row( 'SELECT action, object, details FROM ' . \XB\Versioning\Schema::table( 'audit' ) . " WHERE action = 'theme_carry' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertIsArray( $audit );
		$this->assertSame( 'theme:fondamenta', $audit['object'] );
		$this->assertStringContainsString( 'wp_template:prova-doppio', (string) $audit['details'] );

		// Global styles the Site Editor created empty for the new theme trade places with the old ones.
		switch_theme( 'xb-blank' );
		$empty = $this->post(
			array(
				'post_type'    => 'wp_global_styles',
				'post_name'    => 'wp-global-styles-fondamenta',
				'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true}',
			)
		);
		wp_set_post_terms( $empty, array( 'fondamenta' ), 'wp_theme' );
		switch_theme( 'fondamenta' );
		$this->assertSame( 'fondamenta', self::theme_of( $styles ) );
		$this->assertSame( 'xb-blank', self::theme_of( $empty ) );

		// And back: everything moved returns.
		switch_theme( 'xb-blank' );
		$this->assertSame( 'xb-blank', self::theme_of( $template ) );
		$this->assertSame( 'wp-global-styles-xb-blank', get_post_field( 'post_name', $styles ) );
	}

	public function test_family_and_other_themes(): void {
		$this->assertTrue( ThemeCarry::in_family( wp_get_theme( 'fondamenta' ) ) );
		$this->assertTrue( ThemeCarry::in_family( wp_get_theme( 'xb-blank' ) ) );
		$other = null;
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( ! ThemeCarry::in_family( $theme ) && $theme->is_block_theme() ) {
				$other = (string) $slug;
				break;
			}
		}
		if ( null === $other ) {
			$this->markTestSkipped( 'Nessun altro tema a blocchi installato.' );
		}
		switch_theme( 'fondamenta' );
		$template = $this->saved( 'wp_template', 'single-prova-altro', 'fondamenta' );
		switch_theme( $other );
		$this->assertSame( 'fondamenta', self::theme_of( $template ), 'un tema fuori dalla famiglia non prende i template' );
	}
}
