<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Export\Core\Fields\Adapters;
use XB\Export\Core\Prova;

/**
 * The site's fields leaving Histeon for a field plugin (docs/spec/export-core.md §15, doc 25 §11 step 5): the
 * plugin's field groups, the values in its form, and Fondamenta drawing the same page before and after.
 */
final class FieldsExitTest extends IntegrationTestCase {

	private const FIELDS = array(
		array( 'name' => 'zfdata', 'label' => 'Data', 'type' => 'date' ),
		array( 'name' => 'zfquando', 'label' => 'Quando', 'type' => 'datetime' ),
		array( 'name' => 'zfattivo', 'label' => 'Attivo', 'type' => 'toggle' ),
		array( 'name' => 'zfspento', 'label' => 'Spento', 'type' => 'toggle' ),
		array( 'name' => 'zfprezzo', 'label' => 'Prezzo', 'type' => 'number' ),
		array( 'name' => 'zfanni', 'label' => 'Anni', 'type' => 'number', 'multiple' => true ),
		array( 'name' => 'zftipo', 'label' => 'Tipo', 'type' => 'select', 'options' => array( array( 'value' => 'a', 'label' => 'A' ), array( 'value' => 'b', 'label' => 'B' ) ) ),
		array( 'name' => 'zftag', 'label' => 'Etichette', 'type' => 'select', 'multiple' => true, 'options' => array( array( 'value' => 'x', 'label' => 'X' ), array( 'value' => 'y', 'label' => 'Y' ), array( 'value' => 'z', 'label' => 'Z' ) ) ),
		array(
			'name'  => 'zforari',
			'label' => 'Orari',
			'type'  => 'repeater',
			'sub'   => array(
				array( 'name' => 'giorno', 'label' => 'Giorno', 'type' => 'text' ),
				array( 'name' => 'aperto', 'label' => 'Aperto', 'type' => 'toggle' ),
				array( 'name' => 'dal', 'label' => 'Dal', 'type' => 'date' ),
				array( 'name' => 'turni', 'label' => 'Turni', 'type' => 'repeater', 'sub' => array( array( 'name' => 'ora', 'label' => 'Ora', 'type' => 'text' ) ) ),
			),
		),
	);

	protected function setUp(): void {
		parent::setUp();
		if ( ! Prova::ready() ) {
			$this->markTestSkipped( 'Serve Fondamenta 0.2 attiva nel WordPress di sviluppo.' );
		}
		$this->set_option( \XB\Content\ContentTypes::OPTION, \XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => self::FIELDS ) ) ) );
		\XB\Content\ContentTypes::forget();
	}

	protected function tearDown(): void {
		\XB\Content\ContentTypes::forget();
		parent::tearDown();
	}

	/** @return array<string, array{string}> */
	public static function adapters(): array {
		return array_map( static fn ( string $id ): array => array( $id ), array_combine( array_keys( Adapters::all() ), array_keys( Adapters::all() ) ) );
	}

	/** Every field plugin: the values in its form, and Fondamenta draws the same page from them. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'adapters' )]
	public function test_fondamenta_draws_the_same_page_from_each_plugin( string $id ): void {
		[ $post, $page ] = $this->post_and_page();
		$before          = Prova::compare( $page );
		$this->assertContains( $before['esito'], array( 'uguale', 'classi del core' ), implode( "\n", $before['differenze'] ) );
		$adapter = Adapters::get( $id );
		$this->assertNotNull( $adapter );
		$target = get_post( $post );
		$this->assertInstanceOf( \WP_Post::class, $target );
		Adapters::write_post( $adapter, $target );
		$this->assertSame( $before['dopo'], Prova::compare( $page )['dopo'], $id );
	}

	public function test_meta_box_keeps_dates_and_lists_and_writes_choices_one_row_each(): void {
		[ $post ] = $this->post_and_page();
		$adapter  = Adapters::get( 'metabox' );
		$this->assertNotNull( $adapter );
		$target = get_post( $post );
		$this->assertInstanceOf( \WP_Post::class, $target );
		Adapters::write_post( $adapter, $target );
		$this->assertSame( '2026-01-10', get_post_meta( $post, 'zfdata', true ) );
		$this->assertSame( '2026-03-01 17:30:00', get_post_meta( $post, 'zfquando', true ) );
		$this->assertSame( '0', get_post_meta( $post, 'zfspento', true ) );
		$this->assertSame( array( 'x', 'z' ), get_post_meta( $post, 'zftag', false ) );
		$this->assertSame( array( 2024, 2025 ), get_post_meta( $post, 'zfanni', true ) );
		$rows = get_post_meta( $post, 'zforari', true );
		$this->assertIsArray( $rows );
		$this->assertSame( '1', $rows[0]['aperto'] );
		$this->assertSame( array( array( 'ora' => '9' ), array( 'ora' => '15' ) ), $rows[0]['turni'] );
		$files = $adapter->theme_files( \XB\Content\ContentTypes::live() );
		$boxes = json_decode( (string) $files['campi/meta-box.json'], true );
		$this->assertIsArray( $boxes );
		$fields = array_column( $boxes[0]['fields'], null, 'id' );
		$this->assertSame( array( 'post' ), $boxes[0]['post_types'] );
		$this->assertSame( 'group', $fields['zforari']['type'] );
		$this->assertTrue( $fields['zforari']['clone'] );
		$this->assertTrue( $fields['zfanni']['clone'] );
		$this->assertSame( 'Y-m-d', $fields['zfdata']['save_format'] );
		$this->assertStringContainsString( "'rwmb_meta_boxes'", $adapter->functions() );
		$this->assertStringContainsString( 'Meta Box Group', implode( ' ', $adapter->notes( \XB\Content\ContentTypes::live() ) ) );
	}

	/**
	 * A post with values in Histeon's form, and a page that shows them in a loop.
	 *
	 * @return array{int, \WP_Post}
	 */
	private function post_and_page(): array {
		$post = $this->post( array( 'post_title' => 'Con i campi' ) );
		$meta = array(
			'zfdata'   => '2026-01-10',
			'zfquando' => '2026-03-01T18:30:00+01:00',
			'zfattivo' => true,
			'zfspento' => false,
			'zfprezzo' => 12,
			'zfanni'   => array( 2024, 2025 ),
			'zftipo'   => 'b',
			'zftag'    => array( 'x', 'z' ),
			'zforari'  => array(
				array( 'giorno' => 'Lunedì', 'aperto' => true, 'dal' => '2026-02-02', 'turni' => array( array( 'ora' => '9' ), array( 'ora' => '15' ) ) ),
				array( 'giorno' => 'Martedì', 'aperto' => false, 'dal' => '', 'turni' => array() ),
			),
		);
		foreach ( $meta as $key => $value ) {
			delete_post_meta( $post, $key );
			add_post_meta( $post, $key, $value, true );
		}
		$loop = fn ( string $expr, string $as, array $inner ): array => $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => $expr ), 'as' => $as ), array_values( $inner ) );
		$if   = fn ( string $test, array $then, array $else ): array => $this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => $test ), array_values( $then ) ), $this->block( 'xb/branch', array(), array_values( $else ) ) ) );
		$page = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->block(
							'xb/loop',
							array(
								'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'limit' => '1', 'include' => '[' . $post . ']' ) ),
								'as'     => 'x',
							),
							array(
								$this->el(
									'article',
									array( 'class' => 'campi' ),
									array(
										$this->el( 'p', array( 'class' => 'data' ), array( $this->text( '{x.fields.zfdata} · {x.fields.zfdata.date("j F Y")}' ) ) ),
										$this->el( 'p', array( 'class' => 'quando' ), array( $this->text( '{x.fields.zfquando.date("j/n/Y H:i")}' ) ) ),
										$this->el( 'p', array( 'class' => 'prezzo' ), array( $this->text( '{x.fields.zfprezzo} €, tipo {x.fields.zftipo}' ) ) ),
										$if( 'x.fields.zfattivo', array( $this->el( 'span', array( 'class' => 'attivo' ), array( $this->text( 'attivo' ) ) ) ), array( $this->el( 'span', array(), array( $this->text( 'no' ) ) ) ) ),
										$if( 'x.fields.zfspento', array( $this->el( 'span', array( 'class' => 'spento' ), array( $this->text( 'spento' ) ) ) ), array( $this->el( 'span', array(), array( $this->text( 'acceso' ) ) ) ) ),
										$this->el( 'p', array( 'class' => 'tag' ), array( $this->text( '{x.fields.zftag}' ) ) ),
										$this->el( 'ul', array( 'class' => 'tag' ), array( $loop( 'x.fields.zftag', 'g', array( $this->el( 'li', array(), array( $this->text( '{g}' ) ) ) ) ) ) ),
										$this->el( 'ul', array( 'class' => 'anni' ), array( $loop( 'x.fields.zfanni', 'a', array( $this->el( 'li', array(), array( $this->text( '{a}' ) ) ) ) ) ) ),
										$this->el(
											'ul',
											array( 'class' => 'orari' ),
											array(
												$loop(
													'x.fields.zforari',
													'r',
													array(
														$this->el(
															'li',
															array( 'class' => 'orario' ),
															array(
																$this->el( 'b', array(), array( $this->text( '{r.giorno}' ) ) ),
																$if( 'r.aperto', array( $this->el( 'em', array(), array( $this->text( 'aperto dal {r.dal.date("j/n")}' ) ) ) ), array( $this->el( 'em', array(), array( $this->text( 'chiuso' ) ) ) ) ),
																$this->el( 'ol', array(), array( $loop( 'r.turni', 't', array( $this->el( 'li', array(), array( $this->text( 'ore {t.ora}' ) ) ) ) ) ) ),
															)
														),
													)
												),
											)
										),
									)
								),
							)
						),
					)
				),
			)
		);
		$page_post = get_post( $page );
		$this->assertInstanceOf( \WP_Post::class, $page_post );
		return array( $post, $page_post );
	}

	public function test_values_go_to_acf_and_fondamenta_draws_the_same(): void {
		[ $post, $page_post ] = $this->post_and_page();
		$converted = \XB\Blocks::serialize( Prova::convert( $page_post )['blocks'] );
		$this->assertStringContainsString( '"fonte":"righe","campo":"zforari"', $converted );
		$this->assertStringContainsString( '"fonte":"righe","campo":"turni","dentro":true', $converted );
		$this->assertStringContainsString( '{"chiave":"riga.giorno"}', $converted );
		$before = Prova::compare( $page_post );
		$this->assertContains( $before['esito'], array( 'uguale', 'classi del core' ), implode( "\n", $before['differenze'] ) . "\n" . $before['prima'] . "\n" . $before['dopo'] );
		$this->assertStringContainsString( 'ore 15', $before['dopo'] );
		$this->assertStringContainsString( '<span class="attivo">attivo</span>', $before['dopo'] );
		// A date and time Histeon saved with its zone, shown in the site's time (UTC in the dev WordPress).
		$this->assertStringContainsString( '<p class="quando">1/3/2026 17:30</p>', $before['dopo'] );

		// The values in the plugin's form: rows, references, dates, yes/no.
		$adapter = Adapters::get( 'scf' );
		$this->assertNotNull( $adapter );
		$target = get_post( $post );
		$this->assertInstanceOf( \WP_Post::class, $target );
		Adapters::write_post( $adapter, $target );
		$this->assertSame( '20260110', get_post_meta( $post, 'zfdata', true ) );
		$this->assertSame( '1', get_post_meta( $post, 'zfattivo', true ) );
		$this->assertSame( '0', get_post_meta( $post, 'zfspento', true ) );
		$this->assertSame( '2', get_post_meta( $post, 'zforari', true ) );
		$this->assertSame( 'Lunedì', get_post_meta( $post, 'zforari_0_giorno', true ) );
		$this->assertSame( '15', get_post_meta( $post, 'zforari_0_turni_1_ora', true ) );
		$this->assertSame( '', get_post_meta( $post, 'zforari_1_turni', true ) );
		$this->assertSame( '2025', get_post_meta( $post, 'zfanni_1_valore', true ) );
		$this->assertStringStartsWith( 'field_', (string) get_post_meta( $post, '_zforari_0_turni_1_ora', true ) );

		// Fondamenta reads the plugin's form back as Histeon's: the same page.
		$after = Prova::compare( $page_post );
		$this->assertSame( $before['dopo'], $after['dopo'] );

		// Written again with fewer rows: the rows left from before go.
		update_post_meta( $post, 'zforari', 'x' );
		delete_post_meta( $post, 'zforari' );
		add_post_meta( $post, 'zforari', array( array( 'giorno' => 'Solo', 'aperto' => true, 'dal' => '', 'turni' => array() ) ), true );
		$values = Adapters::post_values( $adapter, $target );
		$this->assertContains( 'zforari_1_giorno', $values['delete'] );
		$this->assertNotContains( 'zforari_0_giorno', $values['delete'] );
	}

	public function test_acf_field_groups_in_the_site_theme(): void {
		$adapter = Adapters::get( 'scf' );
		$this->assertNotNull( $adapter );
		$content = \XB\Content\ContentTypes::live();
		$files   = $adapter->theme_files( $content );
		$this->assertCount( 1, $files );
		$group = json_decode( (string) reset( $files ), true );
		$this->assertIsArray( $group );
		$this->assertSame( 'acf-json/' . $group['key'] . '.json', (string) key( $files ) );
		$this->assertSame( array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ), $group['location'] );
		$fields = array_column( $group['fields'], null, 'name' );
		$this->assertSame( 'date_picker', $fields['zfdata']['type'] );
		$this->assertSame( 'true_false', $fields['zfattivo']['type'] );
		$this->assertSame( 'repeater', $fields['zfanni']['type'] );
		$this->assertSame( array( 'a' => 'A', 'b' => 'B' ), $fields['zftipo']['choices'] );
		$this->assertSame( 'repeater', $fields['zforari']['sub_fields'][3]['type'] );
		$this->assertSame( 'ora', $fields['zforari']['sub_fields'][3]['sub_fields'][0]['name'] );
		// The keys of the groups are those of the values' references, and stay the same.
		$this->assertSame( $files, $adapter->theme_files( $content ) );
		$values = $adapter->values( 'post', $content['fields']['post'], static fn (): mixed => null, array() );
		$this->assertSame( $fields['zforari']['key'], $values['set']['_zforari'] );
		// ACF without Pro: the report says which fields ask for Pro.
		$acf = Adapters::get( 'acf' );
		$this->assertNotNull( $acf );
		$this->assertStringContainsString( 'zforari', implode( ' ', $acf->notes( $content ) ) );
		$this->assertSame( array(), $adapter->notes( $content ) );
	}
}
