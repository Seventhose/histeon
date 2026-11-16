<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Fonts\LocalFonts;
use XB\Framework\HistosCss\Design;

/** Preloading the two fonts a page surely uses (lista della spesa 146). */
final class FontPreloadTest extends IntegrationTestCase {

	protected function tearDown(): void {
		delete_option( LocalFonts::OPTION );
		delete_option( Design::OPTION );
		parent::tearDown();
	}

	public function test_preloads_the_text_and_heading_faces_latin_and_nothing_else(): void {
		update_option(
			LocalFonts::OPTION,
			array(
				array( 'family' => 'Inter', 'weight' => '400', 'style' => 'normal', 'file' => 'inter/inter-400-cyr.woff2', 'format' => 'woff2', 'range' => 'U+0400-045F' ),
				array( 'family' => 'Inter', 'weight' => '400', 'style' => 'normal', 'file' => 'inter/inter-400-lat.woff2', 'format' => 'woff2', 'range' => 'U+0000-00FF, U+0131' ),
				array( 'family' => 'Inter', 'weight' => '400', 'style' => 'italic', 'file' => 'inter/inter-400-it.woff2', 'format' => 'woff2', 'range' => '' ),
				array( 'family' => 'Lora', 'weight' => '400 700', 'style' => 'normal', 'file' => 'lora/lora-var.woff2', 'format' => 'woff2', 'range' => '' ),
				array( 'family' => 'Lora', 'weight' => '300', 'style' => 'normal', 'file' => 'lora/lora-300.woff2', 'format' => 'woff2', 'range' => '' ),
			)
		);
		update_option( Design::OPTION, array_merge( Design::DEFAULTS, array( 'font_body' => 'Inter, sans-serif', 'font_heading' => '"Lora", serif', 'heading_weight' => 700 ) ) );
		$urls = LocalFonts::preloads();
		$this->assertCount( 2, $urls );
		$this->assertStringEndsWith( 'xb-fonts/inter/inter-400-lat.woff2', $urls[0], 'the latin subset of the text font' );
		$this->assertStringEndsWith( 'xb-fonts/lora/lora-var.woff2', $urls[1], 'the variable heading face covers 700' );

		ob_start();
		LocalFonts::print_head();
		$head = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $head, 'rel="preload"' ) );
		$this->assertStringContainsString( 'as="font" type="font/woff2" crossorigin', $head );
	}

	public function test_no_preload_for_system_fonts(): void {
		update_option( LocalFonts::OPTION, array( array( 'family' => 'Inter', 'weight' => '400', 'style' => 'normal', 'file' => 'inter/inter-400.woff2', 'format' => 'woff2', 'range' => '' ) ) );
		update_option( Design::OPTION, Design::DEFAULTS );
		$this->assertSame( array(), LocalFonts::preloads() );
	}
}
