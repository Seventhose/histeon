<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\SetupScreen;
use XB\Framework\Frameworks;

/** Primo avvio (docs/spec/setup.md): asked until the choices are made, then out of the way. */
final class SetupScreenTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( SetupScreen::DONE, false );
		delete_option( SetupScreen::DONE );
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
	}

	public function test_needed_only_on_a_site_without_choices(): void {
		delete_option( Frameworks::OPTION );
		$this->assertTrue( SetupScreen::needed() );
		update_option( Frameworks::OPTION, 'xb-tokens' );
		$this->assertFalse( SetupScreen::needed(), 'a framework chosen in Impostazioni counts as configured' );
		delete_option( Frameworks::OPTION );
		update_option( SetupScreen::DONE, array( 'time' => time(), 'user' => 1 ) );
		$this->assertFalse( SetupScreen::needed(), 'skipped' );
	}

	public function test_the_page_offers_the_three_choices_to_administrators_only(): void {
		ob_start();
		SetupScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="framework" value="xb-tokens"', $html );
		$this->assertStringContainsString( 'consigliato', $html );
		$this->assertMatchesRegularExpression( '/name="modules\[\]"|non include moduli/', $html, 'the bundled modules, or why there are none' );
		$this->assertStringContainsString( 'Da dove arrivi', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertFalse( SetupScreen::can_manage() );
	}

	public function test_found_reports_what_can_be_imported(): void {
		$found = SetupScreen::found();
		$this->assertSame( \XB\Import\Etch\Migration::detected(), $found['etch'], 'Etch content, as the import sees it' );
		$this->assertSame( class_exists( '\\Automatic_CSS\\API' ) || \XB\Framework\AcssLegacy::needed(), $found['acss'], 'Automatic.css active, or its sheets left behind' );
	}
}
