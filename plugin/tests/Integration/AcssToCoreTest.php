<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\AcssToCore;

/** Automatic.css → Core Framework, completely — docs/spec/framework.md §6. */
final class AcssToCoreTest extends IntegrationTestCase {

	public function test_colours_convert_to_hsla(): void {
		$this->assertSame( 'hsla(0, 100%, 50%, 1)', AcssToCore::to_hsla( '#f00' ) );
		$this->assertSame( 'hsla(0, 0%, 100%, 0.5)', AcssToCore::to_hsla( 'rgba(255, 255, 255, 0.5)' ) );
		$this->assertSame( 'hsla(0, 0%, 0%, 1)', AcssToCore::to_hsla( 'oklch(0 0 0)' ) );
		$this->assertSame( 'hsla(0, 0%, 100%, 1)', AcssToCore::to_hsla( 'oklch(1 0 0)' ) );
		$red = (string) AcssToCore::to_hsla( 'oklch(0.628 0.2577 29.23)' ); // sRGB red.
		$this->assertMatchesRegularExpression( '/^hsla\((0|359|360|1), 100%, 50%, 1\)$/', $red );
		$this->assertNull( AcssToCore::to_hsla( 'var(--x)' ) );
	}

	public function test_recipes_are_cleaned(): void {
		$clean = AcssToCore::clean_recipes(
			array(
				'flex-center' => 'display: flex; align-items: center;',
				'Bad Name'    => 'x: 1;',
				'markup'      => '</style><script>alert(1)</script>',
				'unbalanced'  => 'a: b; }',
				'hover'       => '&:hover { color: red; }',
				'not-css'     => array( 'x' ),
			)
		);
		$this->assertSame( array( 'flex-center', 'hover' ), array_keys( $clean ) );
	}

	public function test_import_creates_a_core_framework_project_with_everything(): void {
		if ( ! AcssToCore::available() ) {
			$this->markTestSkipped( 'Servono Automatic.css e Core Framework nel WordPress di sviluppo.' );
		}
		global $wpdb;
		$this->set_option( 'core_framework_main', get_option( 'core_framework_main' ) );
		$this->set_option( AcssToCore::RECIPES, get_option( AcssToCore::RECIPES ) );
		$this->set_option( AcssToCore::COMPAT, get_option( AcssToCore::COMPAT ) );
		$this->set_option( \XB\Framework\Carry::COMPAT, get_option( \XB\Framework\Carry::COMPAT ) );
		$compat_before = \XB\Framework\Carry::compat()['file'] ?? null;
		$plan = AcssToCore::plan();
		$this->assertGreaterThan( 50, $plan['variables'] );

		$id = AcssToCore::import();
		try {
			$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $id );
			$main = get_option( 'core_framework_main' );
			$this->assertIsArray( $main );
			$this->assertSame( $id, $main['selected_id'] );
			$data = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}core_framework_presets WHERE id = %s", $id ) ), true );
			$this->assertIsArray( $data );
			$sheets = array_column( $data['modulesData']['STYLESHEETS']['groups'], 'css', 'name' );
			$this->assertArrayHasKey( 'Automatic.css — variabili', $sheets );
			$this->assertStringContainsString( '--space-m:', $sheets['Automatic.css — variabili'] );
			$this->assertStringNotContainsString( '</', implode( '', $sheets ) );
			// The ACSS rules the site uses load after the site's styles, from XB's compatibility sheet, while Core
			// Framework is the framework; back on Automatic.css its own sheet has them, and the copy stays off.
			$this->set_option( \XB\Framework\Frameworks::OPTION, get_option( \XB\Framework\Frameworks::OPTION ) );
			$this->assertTrue( \XB\Plugin::instance()->frameworks->select( 'core-framework' ) );
			$url = AcssToCore::compat_url();
			$this->assertNotNull( $url );
			$compat = (string) file_get_contents( wp_upload_dir()['basedir'] . '/xb-css/' . ( \XB\Framework\Carry::compat()['file'] ?? '' ) );
			$this->assertStringNotContainsString( '</', $compat );
			$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
			ob_start();
			do_action( 'wp_head' );
			$this->assertStringContainsString( 'id="xb-acss-compat-css"', (string) ob_get_clean() );
			foreach ( $plan['colors'] as $value ) {
				$this->assertStringContainsString( $value, (string) wp_json_encode( $data['modulesData']['COLOR_SYSTEM'] ) );
			}
			$this->assertSame( $plan['recipes'], count( (array) get_option( AcssToCore::RECIPES ) ) );
			$this->assertTrue( \XB\Plugin::instance()->frameworks->select( 'acss' ) );
			$this->assertNull( AcssToCore::compat_url(), 'back on ACSS: no duplicate rules' );
		} finally {
			$wpdb->delete( $wpdb->prefix . 'core_framework_presets', array( 'id' => $id ) );
			$now = \XB\Framework\Carry::compat()['file'] ?? null;
			if ( is_string( $now ) && $now !== $compat_before ) {
				wp_delete_file( wp_upload_dir()['basedir'] . '/xb-css/' . $now );
			}
		}
	}
}
