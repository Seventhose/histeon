<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\AcssToCore;
use XB\Framework\Carry;
use XB\Framework\Frameworks;
use XB\Framework\Migration;
use XB\Plugin;
use XB\Versioning\Documents;

/** Carrying the previous framework over when the site switches — docs/spec/framework.md. Needs ACSS's files. */
final class CarryTest extends IntegrationTestCase {

	/** @var string|false */
	private string|false $compat_file = false;

	protected function setUp(): void {
		parent::setUp();
		$acss = Plugin::instance()->frameworks->all()['acss'];
		if ( array() === Carry::files( $acss ) ) {
			$this->markTestSkipped( 'Servono i fogli generati di Automatic.css nel WordPress di sviluppo.' );
		}
		$this->set_option( Frameworks::OPTION, get_option( Frameworks::OPTION ) );
		$this->set_option( Carry::COMPAT, get_option( Carry::COMPAT ) );
		$this->set_option( AcssToCore::COMPAT, get_option( AcssToCore::COMPAT ) );
		$this->set_option( 'xb_styles', get_option( 'xb_styles', array() ) );
		$this->set_option( 'xb_global_styles', get_option( 'xb_global_styles', array() ) );
		$compat                = Carry::compat();
		$this->compat_file     = null === $compat ? false : $compat['file'];
	}

	protected function tearDown(): void {
		$now = Carry::compat();
		if ( null !== $now && $now['file'] !== $this->compat_file ) {
			wp_delete_file( wp_upload_dir()['basedir'] . '/xb-css/' . $now['file'] );
		}
		parent::tearDown();
	}

	public function test_reads_the_framework_and_carries_it_into_histos_tokens(): void {
		$acss   = Plugin::instance()->frameworks->all()['acss'];
		$tokens = Plugin::instance()->frameworks->all()['xb-tokens'];
		$uploads = (string) realpath( wp_upload_dir()['basedir'] );
		foreach ( Carry::files( $acss ) as $file ) {
			$this->assertTrue( str_starts_with( $file, $uploads . '/' ), $file );
		}
		$vars = Carry::variables( $acss );
		$this->assertGreaterThan( 50, count( $vars ) );
		$this->assertArrayHasKey( '--space-m', $vars );
		$this->assertSame( 'sheet', Carry::route( $acss, $tokens ) );

		$plan = Carry::carry( $acss, $tokens );
		$this->assertGreaterThan( 0, $plan['tokens'] );
		$globals = Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload']['globals'];
		$this->assertSame( 'carry-acss', $globals[0]['id'], 'first: under the site\'s own sheets' );
		$this->assertStringContainsString( '--space-m:', $globals[0]['css'] );
		$this->assertStringContainsString( '--xb-space-m: var(--space-m);', $globals[0]['css'] );
		$this->assertStringNotContainsString( '</', $globals[0]['css'] );
		$this->assertSame( 'acss', Carry::compat()['source'] ?? null );

		// The compatibility sheet only while another framework is active.
		$this->needs_active_acss();
		$this->assertTrue( Plugin::instance()->frameworks->select( 'xb-tokens' ) );
		$this->assertNotNull( Carry::compat_url() );
		$this->assertTrue( Plugin::instance()->frameworks->select( 'acss' ) );
		$this->assertNull( Carry::compat_url() );
		// Carrying again replaces the sheet, never duplicates it.
		Carry::carry( $acss, $tokens );
		$ids = array_column( Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload']['globals'], 'id' );
		$this->assertSame( 1, count( array_keys( $ids, 'carry-acss', true ) ) );
	}

	public function test_switching_acss_to_histos_tokens_keeps_the_site_working(): void {
		$this->needs_active_acss();
		$this->assertTrue( Plugin::instance()->frameworks->select( 'acss' ) );
		Plugin::instance()->versioning->ensure( 'settings' );
		Plugin::instance()->versioning->record_live( 'settings' );
		$copy = Plugin::instance()->versioning->working_copy( 'settings' );
		if ( 'acss' !== ( $copy['payload']['framework'] ?? null ) ) {
			Plugin::instance()->versioning->save_dev( 'settings', array( 'framework' => 'acss' ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'] );
		}
		$id = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'div', array( 'style' => 'padding: var(--space-xxl); color: var(--base-ultra-light); background: var(--primary-ultra-light)' ), array( $this->text( 'ACSS' ) ), 'carry001' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $id, str_replace( '"ACSS"', '"ACSS v0"', $this->content( $id ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( null, 'baseline' )->get_status() );

		$plan = Migration::instance()->plan( 'xb-tokens' );
		$this->assertSame( 'sheet', $plan['carry']['route'] ?? null );
		Migration::instance()->prepare( 'xb-tokens', true );
		$this->assertSame( 200, $this->publish( null, 'ACSS → token Histeon' )->get_status() );

		$this->assertSame( 'xb-tokens', Plugin::instance()->frameworks->active()->id() );
		$style = parse_blocks( $this->content( $id ) )[0]['attrs']['attributes']['style'] ?? '';
		$this->assertStringContainsString( 'var(--xb-space-2xl)', $style, 'native names become Histeon CSS tokens' );
		$this->assertStringContainsString( 'var(--xb-primary-50)', $style, 'shades too (docs/spec/histos-css.md §4)' );
		$this->assertStringContainsString( 'var(--base-ultra-light)', $style, 'names without a token stay, defined by the carried sheet' );
		$live = (array) get_option( 'xb_global_styles', array() );
		$this->assertSame( 'carry-acss', $live[0]['id'] ?? null, 'the carried values went live with the switch' );
		$this->assertStringContainsString( '--primary-ultra-light:', (string) ( $live[0]['css'] ?? '' ) );
		$this->assertNotNull( Carry::compat_url() );
	}

	public function test_css_is_made_safe(): void {
		$this->assertSame( '\3C /style><b>', Carry::safe_css( '</style><b>' ) );
		$this->assertStringNotContainsString( 'expression(', Carry::safe_css( 'width: expression(alert(1))' ) );
	}
}
