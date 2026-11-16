<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Fields\Fields;

/**
 * Options pages of Secure Custom Fields (free, on WordPress.org) and ACF read in pages as
 * `site.options.<page>.<field>` (D5): read-only, and only the fields an administrator makes public.
 */
final class OptionsPagesTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'acf_add_options_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'Secure Custom Fields (o ACF con le pagine di opzioni) non attivo nel WordPress di sviluppo.' );
		}
		$this->set_option( Fields::OPTION, array() );
		acf_add_options_page(
			array(
				'page_title' => 'Dati del sito',
				'menu_slug'  => 'xbt-dati-sito',
				'post_id'    => 'xbt_dati',
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_xbt_opzioni',
				'title'    => 'Contatti',
				'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'xbt-dati-sito' ) ) ),
				'fields'   => array(
					array( 'key' => 'field_xbt_orari', 'name' => 'orari', 'label' => 'Orari', 'type' => 'text' ),
					array( 'key' => 'field_xbt_telefono', 'name' => 'telefono', 'label' => 'Telefono', 'type' => 'text' ),
					array( 'key' => 'field_xbt_codice', 'name' => 'codice', 'label' => 'Codice', 'type' => 'password' ),
				),
			)
		);
		update_field( 'orari', 'Lun–Ven 9–18', 'xbt_dati' );
		update_field( 'telefono', '<b>06 123</b>', 'xbt_dati' );
		update_field( 'codice', 'segreto', 'xbt_dati' );
	}

	private function show(): string {
		return $this->render( array( $this->el( 'p', array(), array( $this->text( '[{site.options.xbt_dati_sito.orari}|{site.options.xbt_dati_sito.telefono}|{site.options.xbt_dati_sito.codice}|{site.options.nessuna.orari}]' ) ) ) ) );
	}

	public function test_fields_of_an_options_page_are_read_only_once_public(): void {
		wp_set_current_user( 0 );
		$this->assertStringContainsString( '[|||]', $this->show(), 'nothing until an administrator makes the fields public' );

		update_option( Fields::OPTION, array( 'acf:field_xbt_orari', 'acf:field_xbt_telefono', 'acf:field_xbt_codice' ) );
		$this->assertStringContainsString( '[Lun–Ven 9–18|&lt;b&gt;06 123&lt;/b&gt;||]', $this->show(), 'escaped, never a password, an unknown page is empty' );

		// The builder lists them with their options page, to be made public like the others.
		wp_set_current_user( 1 );
		$described = array_merge( ...array_column( Fields::describe(), 'fields' ) );
		$orari     = array_values( array_filter( $described, static fn ( array $f ): bool => 'acf:field_xbt_orari' === $f['id'] ) );
		$this->assertCount( 1, $orari );
		$this->assertSame( 'xbt-dati-sito', $orari[0]['optionsPage'] ?? null );
		$this->assertSame( array(), $orari[0]['postTypes'] );
	}
}
