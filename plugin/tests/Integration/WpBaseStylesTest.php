<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Render\WpBaseStyles;

/** Doc 23 of Publish, list 192: WP's base block rules only where WP's blocks are. */
final class WpBaseStylesTest extends IntegrationTestCase {

	private mixed $template = null;

	protected function setUp(): void {
		parent::setUp();
		global $_wp_current_template_content;
		$this->template = $_wp_current_template_content;
		WpBaseStyles::reset();
		wp_register_style( 'wp-block-library', '/wp-includes/css/dist/block-library/common.min.css' );
		wp_styles()->registered['wp-block-library']->src = '/wp-includes/css/dist/block-library/common.min.css';
	}

	protected function tearDown(): void {
		global $_wp_current_template_content;
		$_wp_current_template_content = $this->template;
		WpBaseStyles::reset();
		parent::tearDown();
	}

	private static function template( string $content ): void {
		global $_wp_current_template_content;
		$_wp_current_template_content = $content;
	}

	private static function src(): mixed {
		return wp_styles()->registered['wp-block-library']->src;
	}

	public function test_a_histeon_page_with_only_histeon_blocks_has_no_base_rules(): void {
		self::template( '<!-- wp:xb/passthrough {"uid":"a"} --><!-- wp:template-part {"slug":"header"} /--><!-- /wp:xb/passthrough --><!-- wp:xb/post-content {"uid":"b"} /-->' );
		render_block( array( 'blockName' => 'core/template-part', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
		$this->assertTrue( WpBaseStyles::histeon_only() );
		WpBaseStyles::apply();
		$this->assertFalse( self::src() );
		$this->assertArrayHasKey( 'wp-block-library', wp_styles()->registered, 'the handle stays, for styles that depend on it' );
	}

	public function test_a_page_with_a_block_of_wp_keeps_them(): void {
		self::template( '<!-- wp:xb/post-content {"uid":"b"} /-->' );
		render_block( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>x</p>', 'innerContent' => array( '<p>x</p>' ) ) );
		WpBaseStyles::apply();
		$this->assertSame( '/wp-includes/css/dist/block-library/common.min.css', self::src() );
	}

	public function test_a_page_without_a_histeon_template_keeps_them(): void {
		self::template( '<!-- wp:post-content /-->' );
		WpBaseStyles::apply();
		$this->assertNotFalse( self::src() );
		self::template( '' );
		WpBaseStyles::apply();
		$this->assertNotFalse( self::src() );
	}

	public function test_the_filter_keeps_them(): void {
		self::template( '<!-- wp:xb/post-content {"uid":"b"} /-->' );
		add_filter( 'xb_drop_wp_base_styles', '__return_false' );
		WpBaseStyles::apply();
		remove_filter( 'xb_drop_wp_base_styles', '__return_false' );
		$this->assertNotFalse( self::src() );
	}
}
