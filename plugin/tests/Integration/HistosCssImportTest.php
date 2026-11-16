<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\AcssToCore;
use XB\Framework\Carry;
use XB\Framework\Frameworks;
use XB\Framework\Migration;
use XB\Framework\HistosCss\AcssImport;
use XB\Framework\HistosCss\Color;
use XB\Framework\HistosCss\Design;
use XB\Framework\HistosCss\Tokens;
use XB\Framework\TokenOverrides;
use XB\Plugin;
use XB\Versioning\Documents;

/** From Automatic.css to Histeon CSS, with the stylesheets Automatic.css generated in the dev WordPress — docs/spec/histos-css.md §4. */
final class HistosCssImportTest extends IntegrationTestCase {

	/** @var string|false */
	private string|false $compat_file = false;

	protected function setUp(): void {
		parent::setUp();
		$acss = Plugin::instance()->frameworks->all()['acss'];
		if ( array() === Carry::files( $acss ) ) {
			$this->markTestSkipped( 'Servono i fogli generati di Automatic.css nel WordPress di sviluppo.' );
		}
		foreach ( array( Frameworks::OPTION, Carry::COMPAT, AcssToCore::COMPAT, Design::OPTION, TokenOverrides::OPTION ) as $option ) {
			$this->set_option( $option, get_option( $option ) );
		}
		$this->set_option( 'xb_styles', get_option( 'xb_styles', array() ) );
		$this->set_option( 'xb_global_styles', get_option( 'xb_global_styles', array() ) );
		$compat            = Carry::compat();
		$this->compat_file = null === $compat ? false : $compat['file'];
	}

	protected function tearDown(): void {
		$now = Carry::compat();
		if ( null !== $now && $now['file'] !== $this->compat_file ) {
			wp_delete_file( wp_upload_dir()['basedir'] . '/xb-css/' . $now['file'] );
		}
		parent::tearDown();
	}

	public function test_every_mapped_token_exists_in_histos_css(): void {
		$histos = Tokens::compute( Design::DEFAULTS );
		$names = array_flip( TokenOverrides::names() );
		foreach ( array_keys( AcssImport::tokens() ) as $token ) {
			$this->assertArrayHasKey( $token, $histos, $token );
			$this->assertArrayHasKey( $token, $names, $token . ' can be overridden' );
		}
		$classes = array_merge( ...array_values( Plugin::instance()->frameworks->all()['xb-tokens']->classes() ) );
		foreach ( AcssImport::classes() as $target ) {
			$this->assertContains( $target, $classes, $target );
		}
	}

	public function test_the_real_stylesheets_feed_the_import(): void {
		$vars = Carry::variables( Plugin::instance()->frameworks->all()['acss'] );
		$hits = 0;
		foreach ( AcssImport::tokens() as $variable ) {
			$hits += isset( $vars[ '--' . $variable ] ) ? 1 : 0;
		}
		$this->assertGreaterThan( 40, $hits, 'most of the table finds its variable' );
		$design = AcssImport::design( $vars );
		$this->assertArrayHasKey( 'primary', $design );
		$this->assertNotNull( Color::parse( (string) $design['primary'] ) );
		$this->assertSame( 'oklch(0 0 0)', AcssImport::resolve( '--x', array( '--x' => 'var(--y)', '--y' => 'oklch(0 0 0)' ) ) );
		$this->assertNull( AcssImport::resolve( '--a', array( '--a' => 'var(--a)' ) ), 'a loop ends nowhere' );
	}

	public function test_a_site_moves_from_acss_to_histos_css_and_looks_the_same(): void {
		$this->needs_active_acss();
		$this->assertTrue( Plugin::instance()->frameworks->select( 'acss' ) );
		Plugin::instance()->versioning->ensure( 'settings' );
		Plugin::instance()->versioning->record_live( 'settings' );
		$copy = Plugin::instance()->versioning->working_copy( 'settings' );
		if ( 'acss' !== ( $copy['payload']['framework'] ?? null ) ) {
			Plugin::instance()->versioning->save_dev( 'settings', array( 'framework' => 'acss' ) + $copy['payload'], (string) $copy['head'], (string) $copy['base'] );
		}
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->el(
							'section',
							array(
								'class' => 'text--l text--primary-dark hidden-accessible bg--ultra-dark',
								'style' => 'gap: var(--space-m); border-color: var(--primary-dark); margin: var(--section-space-l)',
							),
							array( $this->text( 'Histeon CSS' ) ),
							'hcss001'
						),
					)
				),
			)
		);
		$this->assertSame( 200, $this->save_dev( $id, str_replace( '"Histeon CSS"', '"Histeon CSS v0"', $this->content( $id ) ) )->get_status() );
		$this->assertSame( 200, $this->publish( null, 'baseline' )->get_status() );

		$plan = Migration::instance()->plan( 'xb-tokens', 'acss' );
		$this->assertContains( 'primary', $plan['design'], 'the base colours are read' );
		$this->assertNotEmpty( array_filter( $plan['docs'], static fn ( array $d ): bool => $d['classes'] > 0 && $d['variables'] > 0 ) );

		Migration::instance()->prepare( 'xb-tokens', true, 'acss' );
		$settings = Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['payload'];
		$this->assertSame( 'xb-tokens', $settings['framework'] );
		$this->assertArrayHasKey( 'primary', $settings['design'], 'HistosCss\'s palette starts from the site\'s colours' );
		$carry = Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload']['globals'][0];
		$this->assertSame( 'carry-acss', $carry['id'] );
		$this->assertStringContainsString( '--xb-primary-800: var(--primary-dark);', $carry['css'], 'Histeon CSS tokens take the site\'s exact values' );
		$this->assertStringContainsString( '--xb-h1: var(--h1);', $carry['css'] );

		$this->assertSame( 200, $this->publish( null, 'ACSS → Histeon CSS' )->get_status() );
		$this->assertSame( 'xb-tokens', Plugin::instance()->frameworks->active()->id() );
		$attrs = parse_blocks( $this->content( $id ) )[0]['attrs']['attributes'] ?? array();
		$this->assertSame( 'xb-text-l xb-color-primary-800 xb-visually-hidden bg--ultra-dark', $attrs['class'] ?? '', 'same-meaning classes renamed, the others kept for the compatibility sheet' );
		$this->assertSame( 'gap: var(--xb-space-m); border-color: var(--xb-primary-800); margin: var(--xb-section-l)', $attrs['style'] ?? '' );
		$this->assertArrayHasKey( 'primary', (array) get_option( Design::OPTION ), 'the design went live with the release' );
		$this->assertNotNull( Carry::compat_url(), 'rules without a Histeon CSS class keep working' );
	}

	/**
	 * The usual case: Automatic.css turned off, its sheets still served by Histeon (AcssLegacy), then the import.
	 * Checked in the browser on the real sheets of a site (docs/spec/histos-css.md §4): same page, pixel for pixel.
	 */
	public function test_a_site_with_automatic_css_turned_off_moves_too(): void {
		if ( class_exists( '\\Automatic_CSS\\API' ) ) {
			$this->markTestSkipped( 'Automatic.css è attivo: qui si prova il sito che l\'ha spento.' );
		}
		$this->set_option( \XB\Framework\Frameworks::OPTION, 'acss' );
		$this->set_option( Carry::COMPAT, false ); // Nothing imported yet.
		$this->set_option( \XB\Framework\AcssToCore::COMPAT, false );
		Plugin::instance()->versioning->record_live( 'settings' );
		$this->assertTrue( \XB\Framework\AcssLegacy::needed(), 'its last sheets are served' );
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'p', array( 'class' => 'text--l text--dark-muted', 'style' => 'gap: var(--space-m)' ), array( $this->text( 'Spento' ) ), 'hcssoff1' ) ) ),
			)
		);
		$plan = Migration::instance()->plan( 'xb-tokens', 'acss' );
		$this->assertContains( 'primary', $plan['design'] );
		Migration::instance()->prepare( 'xb-tokens', true, 'acss' );
		$this->assertSame( 200, $this->publish( null, 'ACSS spento → Histeon CSS' )->get_status() );
		$attrs = parse_blocks( $this->content( $id ) )[0]['attrs']['attributes'] ?? array();
		$this->assertSame( 'xb-text-l text--dark-muted', $attrs['class'] ?? '' );
		$this->assertSame( 'gap: var(--xb-space-m)', $attrs['style'] ?? '' );
		$this->assertFalse( \XB\Framework\AcssLegacy::needed(), 'the import took its place' );
		$this->assertNotNull( Carry::compat_url(), 'what Histeon CSS has no class for keeps working' );
	}
}
