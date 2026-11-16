<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\I18n;

/** Languages: Italian source, English for everything else (docs/spec/i18n.md). */
final class I18nTest extends IntegrationTestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = get_temp_dir() . 'xb-i18n-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
		file_put_contents( $this->dir . '/xb-en_US.mo', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->dir . '/xb-en_US-' . md5( 'assets/editor/launch.js' ) . '.json', '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		remove_all_filters( 'determine_locale' );
		parent::tearDown();
	}

	public function test_every_language_but_italian_falls_back_to_english(): void {
		$this->assertSame( $this->dir . '/xb-en_US.mo', I18n::fallback( $this->dir . '/xb-de_DE.mo', 'xb', 'de_DE' ) );
		$this->assertSame( $this->dir . '/xb-en_US.mo', I18n::fallback( $this->dir . '/xb-en_GB.mo', 'xb', 'en_GB' ) );
		$this->assertSame( $this->dir . '/xb-it_IT.mo', I18n::fallback( $this->dir . '/xb-it_IT.mo', 'xb', 'it_IT' ), 'Italian is the source' );
		$this->assertSame( $this->dir . '/xb-de_DE.mo', I18n::fallback( $this->dir . '/xb-de_DE.mo', 'other', 'de_DE' ), 'other plugins are left alone' );
		file_put_contents( $this->dir . '/xb-fr_FR.mo', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( $this->dir . '/xb-fr_FR.mo', I18n::fallback( $this->dir . '/xb-fr_FR.mo', 'xb', 'fr_FR' ), 'a language with its own file keeps it' );

		$json = '/xb-en_US-' . md5( 'assets/editor/launch.js' ) . '.json';
		$this->assertSame( $this->dir . $json, I18n::script_fallback( $this->dir . '/xb-de_DE-' . md5( 'assets/editor/launch.js' ) . '.json', 'xb-launch', 'xb' ) );
		$this->assertSame( $this->dir . '/xb-it_IT-' . md5( 'x' ) . '.json', I18n::script_fallback( $this->dir . '/xb-it_IT-' . md5( 'x' ) . '.json', 'xb-launch', 'xb' ) );
		$this->assertFalse( I18n::script_fallback( false, 'xb-launch', 'xb' ) );
	}

	public function test_the_builder_gets_its_catalog_only_in_english(): void {
		add_filter( 'determine_locale', static fn (): string => 'it_IT' );
		$this->assertFalse( I18n::english() );
		$this->assertSame( array(), I18n::editor_strings() );
		remove_all_filters( 'determine_locale' );
		add_filter( 'determine_locale', static fn (): string => 'de_DE' );
		$this->assertTrue( I18n::english() );
		$this->assertIsArray( I18n::editor_strings() );
	}

	public function test_english_files_ship_and_load(): void {
		$mo = dirname( XB_FILE ) . '/languages/xb-en_US.mo';
		if ( ! is_readable( $mo ) ) {
			$this->markTestSkipped( 'No compiled English file yet.' );
		}
		unload_textdomain( 'xb' );
		add_filter( 'determine_locale', static fn (): string => 'en_US' );
		load_textdomain( 'xb', $mo, 'en_US' );
		$this->assertSame( 'Settings', __( 'Impostazioni', 'xb' ) );
		unload_textdomain( 'xb' );
	}
}
