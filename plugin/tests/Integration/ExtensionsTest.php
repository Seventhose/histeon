<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Expr\Modifiers;
use XB\Render\Extensions;

/** PHP for the builder, written in files — docs/spec/php.md. */
final class ExtensionsTest extends IntegrationTestCase {

	/** @var \Closure(array<mixed>): array<mixed> */
	private \Closure $providers;

	protected function setUp(): void {
		parent::setUp();
		$draft           = $this->post( array( 'post_status' => 'draft', 'post_title' => 'Bozza segreta' ) );
		$public          = $this->post( array( 'post_title' => 'Articolo pubblico' ) );
		$this->providers = static fn ( array $p ): array => $p + array(
			'meteo'    => array(
				'callback'    => static fn (): array => array(
					'oggi'   => '<b>Sole</b>',
					'giorni' => array( array( 'nome' => 'lun', 'max' => 21.5 ), array( 'nome' => 'mar', 'max' => INF ) ),
					'altro'  => new \stdClass(),
				),
				'description' => 'Previsioni <i>locali</i>',
			),
			'articoli' => static fn (): array => array( get_post( $draft ), get_post( $public ) ),
			'rotto'    => static function (): never {
				throw new \RuntimeException( 'servizio non raggiungibile' );
			},
			'posts'    => static fn (): string => 'non prende il posto della query predefinita',
			'no-good'  => static fn (): int => 1,
			'nonfn'    => 'phpinfo_non_esiste',
		);
		add_filter( 'xb_data_providers', $this->providers );
		Extensions::forget();
	}

	protected function tearDown(): void {
		remove_filter( 'xb_data_providers', $this->providers );
		Extensions::forget();
		parent::tearDown();
	}

	public function test_data_providers_give_plain_data_with_visibility_rules(): void {
		wp_set_current_user( 0 );
		$html = $this->render(
			array(
				$this->el( 'p', array(), array( $this->text( '{data.meteo.oggi}|{data.meteo.giorni[0].max}|{data.meteo.giorni[1].max}|{data.meteo.altro}|{data.rotto}' ) ) ),
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'data.articoli' ), 'as' => 'a' ), array( $this->text( '[{a.title}]' ) ) ),
			)
		);
		$this->assertStringContainsString( '<p>&lt;b&gt;Sole&lt;/b&gt;|21.5|||</p>', $html, 'escaped like any value; INF, objects and failures are null' );
		$this->assertStringContainsString( '[Articolo pubblico]', $html );
		$this->assertStringNotContainsString( 'Bozza segreta', $html, 'a draft returned by PHP stays hidden from visitors' );
		$this->assertNotSame( 'non prende il posto della query predefinita', ( new \XB\Data\DataNode() )->get( 'posts' ) );
		$calls = 0;
		add_filter( 'xb_data_providers', $count = static function ( array $p ) use ( &$calls ): array {
			return $p + array( 'contatore' => static function () use ( &$calls ): int {
				return ++$calls;
			} );
		} );
		Extensions::forget();
		$this->render( array( $this->text( '{data.contatore}' ) ) );
		$this->render( array( $this->text( '{data.contatore}' ) ) );
		remove_filter( 'xb_data_providers', $count );
		$this->assertSame( 1, $calls, 'once per page, however many documents read it' );
	}

	/** Note 55 of the 3 November audit: a user given by PHP or by a field shows only when they are an author. */
	public function test_a_user_shows_only_when_they_are_an_author(): void {
		$customer = $this->user( 'subscriber' );
		wp_update_user( array( 'ID' => $customer, 'display_name' => 'Cliente Riservato' ) );
		$writer = $this->user( 'author' );
		wp_update_user( array( 'ID' => $writer, 'display_name' => 'Autrice Pubblica' ) );
		$this->post( array( 'post_author' => $writer, 'post_title' => 'Suo articolo' ) );
		$people = static fn ( array $p ): array => $p + array( 'persone' => static fn (): array => array( get_userdata( $customer ), get_userdata( $writer ) ) );
		add_filter( 'xb_data_providers', $people );
		Extensions::forget();
		try {
			wp_set_current_user( 0 );
			$html = $this->render( array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'data.persone' ), 'as' => 'u' ), array( $this->text( '[{u.name}]' ) ) ) ) );
		} finally {
			remove_filter( 'xb_data_providers', $people );
		}
		$this->assertStringContainsString( 'Autrice Pubblica', $html );
		$this->assertStringNotContainsString( 'Cliente Riservato', $html );
		$this->assertNull( \XB\Fields\Values::user( $customer ), 'a user field' );
		$this->assertNotNull( \XB\Fields\Values::user( $writer ) );
	}

	public function test_builder_lists_what_the_site_php_offers(): void {
		register_block_type( 'xbt/orari', array( 'title' => 'Orari', 'render_callback' => static fn (): string => '<p>9-18</p>' ) );
		try {
			$res = $this->request( 'GET', '/extensions' );
			$this->assertSame( 200, $res->get_status() );
			$data = $res->get_data();
			$this->assertSame( array( 'meteo', 'articoli', 'rotto' ), array_column( $data['data'], 'name' ), 'invalid names, non-callables and built-in names are left out' );
			$this->assertSame( 'Previsioni locali', array_column( $data['data'], 'description', 'name' )['meteo'] );
			$this->assertContains( 'upper', $data['modifiers'] );
			$this->assertContains( array( 'name' => 'xbt/orari', 'title' => 'Orari' ), $data['blocks'] );
			$this->assertNotContains( 'core/latest-posts', array_column( $data['blocks'], 'name' ) );
			wp_set_current_user( $this->user( 'author' ) );
			$this->assertSame( 403, $this->request( 'GET', '/extensions' )->get_status() );
		} finally {
			unregister_block_type( 'xbt/orari' );
		}
	}

	public function test_modifiers_of_a_theme_count_even_if_registered_late(): void {
		$modifiers = new Modifiers( static fn (): array => (array) apply_filters( 'xbt_late_modifiers', array() ) );
		add_filter( 'xbt_late_modifiers', $add = static fn ( array $m ): array => $m + array( 'euro' => static fn ( mixed $v ): string => number_format( (float) $v, 2, ',', '.' ) . ' €', 'bad name' => static fn (): int => 1 ) );
		try {
			$this->assertSame( '1.234,50 €', $modifiers->apply( 'euro', 1234.5, array() ) );
			$this->assertNotContains( 'bad name', $modifiers->names() );
		} finally {
			remove_filter( 'xbt_late_modifiers', $add );
		}
	}
}
