<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Render\Facets;
use XB\Versioning\Documents;

/** Filters, sorting and pages of a list — docs/spec/facets.md. */
final class FacetsTest extends IntegrationTestCase {

	private const TYPE = 'xbt_fac';
	private const TAX  = 'xbt_tema';

	/** @var array<string, int> */
	private array $terms = array();

	protected function setUp(): void {
		parent::setUp();
		register_post_type( self::TYPE, array( 'public' => true ) );
		register_taxonomy( self::TAX, self::TYPE, array( 'public' => true ) );
		register_taxonomy( 'xbt_interna', self::TYPE, array( 'public' => false ) );
		foreach ( array( 'xbt-a' => 'Alfa', 'xbt-b' => 'Beta', 'xbt-c' => 'Gamma' ) as $slug => $name ) {
			$term                 = wp_insert_term( $name, self::TAX, array( 'slug' => $slug ) );
			$this->terms[ $slug ] = is_array( $term ) ? (int) $term['term_id'] : (int) get_term_by( 'slug', $slug, self::TAX )->term_id; // @phpstan-ignore property.nonObject
		}
		$make = function ( string $title, string $term, array $extra = array() ): void {
			$id = $this->post( array( 'post_type' => self::TYPE, 'post_title' => $title ) + $extra );
			wp_set_object_terms( $id, array( $this->terms[ $term ] ), self::TAX );
		};
		foreach ( array( 'Uno', 'Due', 'Tre', 'Quattro', 'Cinque' ) as $title ) {
			$make( "A $title", 'xbt-a' );
		}
		foreach ( array( 'Sei', 'Sette', 'Otto' ) as $title ) {
			$make( "B $title", 'xbt-b' );
		}
		// Never listed: a draft, a private post and one with a password, all in A.
		$make( 'A Bozza', 'xbt-a', array( 'post_status' => 'draft' ) );
		$make( 'A Privato', 'xbt-a', array( 'post_status' => 'private' ) );
		$make( 'A Password', 'xbt-a', array( 'post_password' => 'segreta' ) );
		$this->set_option( Facets::OPTION, array( 'temi' => $this->definition() ) );
		Facets::forget();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		Facets::forget();
		foreach ( $this->terms as $id ) {
			wp_delete_term( $id, self::TAX );
		}
		unregister_taxonomy( self::TAX );
		unregister_taxonomy( 'xbt_interna' );
		unregister_post_type( self::TYPE );
		parent::tearDown();
	}

	/**
	 * @param list<array<string, mixed>> $filters
	 * @return array<string, mixed>
	 */
	private function definition( array $filters = array() ): array {
		return array(
			'query'   => 'posts',
			'type'    => self::TYPE,
			'perPage' => 3,
			'filters' => array() !== $filters ? $filters : array(
				array( 'kind' => 'search', 'key' => 'q', 'label' => 'Cerca' ),
				array( 'kind' => 'taxonomy', 'key' => 'tema', 'label' => 'Tema', 'taxonomy' => self::TAX, 'multiple' => true ),
				array(
					'kind'    => 'sort',
					'key'     => 'ordine',
					'label'   => 'Ordina',
					'options' => array(
						array( 'value' => 'titolo', 'label' => 'Titolo', 'orderby' => 'title', 'order' => 'asc' ),
						array( 'value' => 'recenti', 'label' => 'Recenti', 'orderby' => 'date', 'order' => 'desc' ),
					),
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $get
	 * @return array<string, mixed>
	 */
	private function state( array $get ): array {
		$_GET = $get;
		Facets::forget();
		$state = Facets::state( 'temi' );
		$this->assertIsArray( $state );
		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @return list<string>
	 */
	private static function titles( array $state ): array {
		return array_values( array_map( static fn ( $p ): string => (string) $p->get( 'title' ), $state['items'] ) );
	}

	public function test_results_counts_and_links_follow_the_query_string(): void {
		$all = $this->state( array() );
		$this->assertSame( 8, $all['total'], 'drafts, private and password-protected posts never count' );
		$this->assertSame( 3, $all['pages'] );
		$this->assertCount( 3, $all['items'] );
		$options = array_column( $all['filters']['tema']['options'], null, 'value' );
		$this->assertSame( 5, $options['xbt-a']['count'] );
		$this->assertSame( 3, $options['xbt-b']['count'] );
		$this->assertArrayNotHasKey( 'xbt-c', $options, 'an option without results is not offered' );
		$this->assertStringEndsWith( '?tema=xbt-a', $options['xbt-a']['url'] );

		$a = $this->state( array( 'tema' => 'xbt-a' ) );
		$this->assertSame( 5, $a['total'] );
		$options = array_column( $a['filters']['tema']['options'], null, 'value' );
		$this->assertTrue( $options['xbt-a']['selected'] );
		$this->assertSame( 3, $options['xbt-b']['count'], 'counts of a filter ignore its own choice' );
		$this->assertStringEndsWith( '?tema=xbt-a,xbt-b', $options['xbt-b']['url'] );
		$this->assertStringEndsWith( '/', $options['xbt-a']['url'], 'the link of a chosen option removes it' );
		$this->assertSame( array( 'xbt-a' ), $a['values']['tema'] );
		$this->assertSame( 'Alfa', $a['active'][0]['text'] );

		// Lists both ways: name[]=… (form without JavaScript) and a,b (links).
		$this->assertSame( 8, $this->state( array( 'tema' => array( 'xbt-a', 'xbt-b' ) ) )['total'] );
		$this->assertSame( 8, $this->state( array( 'tema' => 'xbt-a,xbt-b' ) )['total'] );

		$sorted = $this->state( array( 'tema' => 'xbt-b', 'ordine' => 'titolo' ) );
		$this->assertSame( array( 'B Otto', 'B Sei', 'B Sette' ), self::titles( $sorted ) );
		$this->assertTrue( $sorted['filters']['ordine']['options'][0]['selected'] );

		$page = $this->state( array( 'ordine' => 'titolo', 'pagina' => '3' ) );
		$this->assertSame( array( 'B Sei', 'B Sette' ), self::titles( $page ) );
		$this->assertSame( 3, $page['page'] );
		$this->assertNull( $page['nextUrl'] );
		$this->assertStringEndsWith( '?ordine=titolo&pagina=2', (string) $page['prevUrl'] );
		$this->assertSame( array( 1, 2, 3 ), array_column( $page['pageLinks'], 'number' ) );

		$search = $this->state( array( 'q' => 'Quattro' ) );
		$this->assertSame( array( 'A Quattro' ), self::titles( $search ) );
		$this->assertTrue( $search['hasFilters'] );
	}

	/** D4: ranges of numbers and dates on a public field, and sorting by a field. */
	public function test_ranges_and_sorting_by_a_field(): void {
		$this->set_option( \XB\Content\ContentTypes::OPTION, get_option( \XB\Content\ContentTypes::OPTION ) );
		update_option( \XB\Content\ContentTypes::OPTION, \XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => array( array( 'name' => 'zqprezzo', 'label' => 'Prezzo', 'type' => 'number' ), array( 'name' => 'zqdata', 'label' => 'Data', 'type' => 'date' ) ) ) ) ) );
		\XB\Content\ContentTypes::forget();
		try {
			foreach ( array( 'Economico' => array( 5, '2026-01-10' ), 'Medio' => array( 40, '2026-03-01' ), 'Caro' => array( 120, '2026-06-30' ) ) as $title => $values ) {
				$id = $this->post( array( 'post_type' => 'post', 'post_title' => "R $title" ) );
				update_post_meta( $id, 'zqprezzo', (string) $values[0] );
				update_post_meta( $id, 'zqdata', $values[1] );
			}
			$definition = $this->definition(
				array(
					array( 'kind' => 'range', 'key' => 'prezzo', 'label' => 'Prezzo', 'field' => 'xb:post:zqprezzo', 'type' => 'number' ),
					array( 'kind' => 'range', 'key' => 'quando', 'label' => 'Quando', 'field' => 'xb:post:zqdata', 'type' => 'date' ),
					array( 'kind' => 'sort', 'key' => 'ordine', 'label' => 'Ordina', 'options' => array( array( 'value' => 'prezzo', 'label' => 'Prezzo', 'orderby' => 'field', 'field' => 'xb:post:zqprezzo', 'order' => 'asc' ) ) ),
				)
			);
			$definition['type'] = 'post';
			$this->set_option( Facets::OPTION, array( 'temi' => Facets::validate( array( 'temi' => $definition ) )['temi'] ) );

			$this->assertSame( array( 'R Medio' ), self::titles( $this->state( array( 'prezzo_min' => '10', 'prezzo_max' => '100' ) ) ), 'the two fields of the form' );
			$this->assertSame( array( 'R Medio', 'R Caro' ), self::titles( $this->state( array( 'prezzo' => '10..', 'ordine' => 'prezzo' ) ) ), 'a link with only the lower end' );
			$sorted = $this->state( array( 'ordine' => 'prezzo' ) );
			$this->assertSame( array( 'R Economico', 'R Medio', 'R Caro' ), self::titles( $sorted ), 'by price as numbers (5 before 40 before 120)' );
			$state = $this->state( array( 'quando' => '..2026-03-31' ) );
			$this->assertSame( array( 'R Economico', 'R Medio' ), self::titles( $this->state( array( 'quando' => '..2026-03-31', 'ordine' => 'prezzo' ) ) ), 'dates up to the end of March' );
			$this->assertSame( array( '', '2026-03-31' ), array( $state['filters']['quando']['min'], $state['filters']['quando']['max'] ) );
			$this->assertSame( '..2026-03-31', $state['values']['quando'] );
			$this->assertSame( $this->state( array() )['total'], $this->state( array( 'prezzo' => 'molto..', 'quando' => '2026-13' ) )['total'], 'unreadable values filter nothing' );

			// The form fields are parameters too: they cannot clash with others.
			$clash = $definition;
			$clash['filters'][] = array( 'kind' => 'search', 'key' => 'prezzo_min' );
			try {
				Facets::validate( array( 'temi' => $clash ) );
				$this->fail( 'a key used by a range' );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		} finally {
			\XB\Content\ContentTypes::forget();
		}
	}

	public function test_visitor_input_is_checked_and_never_reflected_raw(): void {
		$state = $this->state(
			array(
				'tema'      => '"><script>alert(1)</script>,xbt-zzz',
				'ordine'    => 'meta_value',
				'pagina'    => '-4',
				'q'         => str_repeat( 'x', 500 ),
				'post_type' => 'page',
				'altro'     => 'x',
			)
		);
		$this->assertSame( 0, $state['total'], 'the long search text is cut, not dropped' );
		$this->assertNull( $state['values']['tema'], 'unknown terms are ignored' );
		$this->assertNull( $state['values']['ordine'], 'only the defined sort options' );
		$this->assertSame( 1, $state['page'] );
		$this->assertSame( 100, mb_strlen( (string) $state['values']['q'] ) );
		$this->assertStringNotContainsString( 'altro', $state['resetUrl'], 'links carry only the facet\'s own parameters' );
		$this->assertSame( 8, $this->state( array( 'pagina' => '99999' ) )['total'] );
		$this->assertSame( 3, $this->state( array( 'pagina' => '99999' ) )['page'], 'past the end: the last page' );

		// In the page: text escaped, links through esc_url.
		$_GET = array( 'q' => '<b>x</b>' );
		Facets::forget();
		$html = $this->render(
			array(
				$this->el( 'p', array(), array( $this->text( '{facets.temi.values.q}' ) ) ),
				$this->el( 'a', array( 'href' => '{facets.temi.resetUrl}' ), array( $this->text( 'Azzera' ) ) ),
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'facets.temi.items' ), 'as' => 'item' ), array( $this->text( '{item.title}|' ) ) ),
			)
		);
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '<p>x</p>', $html, 'the query string is sanitized text' );
		$this->assertStringContainsString( 'href="/', $html );
	}

	public function test_list_renders_and_script_is_enqueued(): void {
		$_GET = array( 'tema' => 'xbt-b', 'ordine' => 'titolo' );
		Facets::forget();
		wp_dequeue_script( 'xb-facets' );
		$html = $this->render(
			array(
				$this->el(
					'div',
					array( 'data-xb-facet' => 'temi' ),
					array(
						$this->el( 'p', array(), array( $this->text( '{facets.temi.total} risultati' ) ) ),
						$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'facets.temi.items' ), 'as' => 'item' ), array( $this->text( '{item.title}|' ) ) ),
					)
				),
			)
		);
		$this->assertStringContainsString( '<p>3 risultati</p>', $html );
		$this->assertStringContainsString( 'B Otto|B Sei|B Sette|', $html );
		$this->assertTrue( wp_script_is( 'xb-facets', 'enqueued' ) );
	}

	/** P9: in the static ZIP there is no WordPress to ask: every result is on the page, marked, with an index. */
	public function test_static_export_lists_everything_with_an_index_for_the_browser(): void {
		$export = new \ReflectionProperty( \XB\StaticSite\ExportMode::class, 'active' );
		$export->setValue( null, true );
		wp_dequeue_script( 'xb-facets' );
		wp_dequeue_script( 'xb-facets-static' );
		try {
			$state = $this->state( array() );
			$this->assertSame( 8, $state['total'] );
			$this->assertCount( 8, $state['items'], 'all results, not the first page' );
			$this->assertSame( 1, $state['pages'] );
			$html = $this->render(
				array(
					$this->el(
						'div',
						array( 'data-xb-facet' => 'temi' ),
						array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'facets.temi.items' ), 'as' => 'item' ), array( $this->el( 'article', array(), array( $this->text( '{item.title}' ) ) ) ) ) )
					),
				)
			);
			$this->assertSame( 8, preg_match_all( '/<article data-xb-facet-item="temi:\d+">/', $html ) );
			$this->assertTrue( wp_script_is( 'xb-facets-static', 'enqueued' ) );
			$this->assertFalse( wp_script_is( 'xb-facets', 'enqueued' ) );

			ob_start();
			Facets::print_static_indexes();
			$printed = (string) ob_get_clean();
			$this->assertSame( 1, preg_match( '#<script type="application/json" id="xb-facets-index-temi">(.+?)</script>#s', $printed, $m ) );
			$json  = $m[1] ?? '';
			$index = json_decode( $json, true );
			$this->assertIsArray( $index );
			$this->assertSame( array( 'search', 'taxonomy', 'sort' ), array_column( $index['filters'], 'kind' ) );
			$this->assertCount( 8, $index['items'] );
			$first = $index['items'][0];
			$this->assertSame( array( 'id', 'v', 's', 't' ), array_keys( $first ) );
			$this->assertContains( $first['v']['tema'][0], array( 'xbt-a', 'xbt-b' ) );
			$this->assertArrayHasKey( 'titolo', $first['s'] );
			$this->assertStringContainsString( strtolower( (string) get_the_title( $first['id'] ) ), $first['t'] );
			// Only what is public: no draft, private or protected post in the index.
			$this->assertStringNotContainsString( 'bozza', $json );
			$this->assertStringNotContainsString( 'password', strtolower( $json ) );
		} finally {
			$export->setValue( null, false );
		}
	}

	public function test_links_keep_only_a_valid_preview_token(): void {
		$page = $this->post( array( 'post_type' => 'page' ) );
		\XB\Versioning\Preview::use_dev();
		$this->set_option( Facets::OPTION, array( 'temi' => $this->definition() ) );
		$token = \XB\Versioning\Preview::token( $page );
		$state = $this->state( array( 'tema' => 'xbt-a', \XB\Versioning\Preview::PARAM => $token ) );
		$this->assertStringContainsString( 'xb_preview=' . rawurlencode( $token ), $state['resetUrl'] );
		$this->assertSame( array( array( 'name' => 'xb_preview', 'value' => $token ) ), $state['hidden'] );
		$forged = $this->state( array( 'tema' => 'xbt-a', \XB\Versioning\Preview::PARAM => 'x' . $token ) );
		$this->assertSame( array(), $forged['hidden'] );
		$this->assertStringNotContainsString( 'xb_preview', $forged['resetUrl'] );
	}

	public function test_definitions_are_validated(): void {
		$bad = array(
			'reserved WordPress parameter' => array( array( 'kind' => 'search', 'key' => 's' ) ),
			'taxonomy query var'           => array( array( 'kind' => 'search', 'key' => self::TAX ) ),
			'post type name'               => array( array( 'kind' => 'search', 'key' => 'post' ) ),
			'duplicate key'                => array( array( 'kind' => 'search', 'key' => 'q' ), array( 'kind' => 'search', 'key' => 'q' ) ),
			'private taxonomy'             => array( array( 'kind' => 'taxonomy', 'key' => 'x', 'taxonomy' => 'xbt_interna' ) ),
			'field not public'             => array( array( 'kind' => 'field', 'key' => 'x', 'field' => 'acf:segreto' ) ),
			'free sort'                    => array( array( 'kind' => 'sort', 'key' => 'x', 'options' => array( array( 'value' => 'm', 'orderby' => 'meta_value' ) ) ) ),
			'unknown kind'                 => array( array( 'kind' => 'sql', 'key' => 'x' ) ),
		);
		foreach ( $bad as $case => $filters ) {
			try {
				Facets::validate( array( 'temi' => $this->definition( $filters ) ) );
				$this->fail( $case );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $case );
			}
		}
		foreach ( array( 'Temi', 'te-mi', '1temi' ) as $name ) {
			try {
				Facets::validate( array( $name => $this->definition() ) );
				$this->fail( $name );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		Facets::validate( array( 'temi' => array( 'query' => 'inesistente' ) + $this->definition() ) );
	}

	public function test_internal_types_are_never_listed(): void {
		Facets::validate( array( 'blocchi' => array( 'query' => 'posts', 'type' => 'wp_block' ) ) );
		$this->set_option( Facets::OPTION, array( 'blocchi' => array( 'query' => 'posts', 'type' => 'wp_block', 'filters' => array() ) ) );
		Facets::forget();
		$this->assertSame( 0, Facets::state( 'blocchi' )['total'] ?? 0 );
	}

	public function test_rest_saves_in_dev_and_tests_with_parameters(): void {
		$doc = $this->request( 'GET', '/facets' )->get_data();
		$res = $this->request(
			'POST',
			'/facets',
			array(
				'facets' => array( 'temi2' => $this->definition() ),
				'head'   => (string) $doc['head'],
				'base'   => (string) $doc['base'],
			)
		);
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$dev = Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['payload'];
		$this->assertArrayHasKey( 'temi2', $dev['facets'] );
		$this->assertArrayNotHasKey( 'temi2', (array) get_option( Facets::OPTION ), 'live waits for a release' );

		$bad = $this->request( 'POST', '/facets', array( 'facets' => array( 'x' => $this->definition( array( array( 'kind' => 'search', 'key' => 'p' ) ) ) ), 'head' => (string) $res->get_data()['head'], 'base' => (string) $doc['base'] ) );
		$this->assertSame( 400, $bad->get_status() );

		$test = $this->request( 'POST', '/facets/test', array( 'facet' => $this->definition(), 'params' => array( 'tema' => 'xbt-b', 'ordine' => 'titolo' ) ) )->get_data();
		$this->assertSame( 3, $test['total'] );
		$this->assertSame( 'B Otto', $test['titles'][0] );
		$this->assertSame( 'xbt-a', $test['filters'][1]['options'][0]['value'] );
		$this->assertArrayNotHasKey( 'url', $test['filters'][1]['options'][0] );

		wp_set_current_user( $this->user( 'author' ) );
		$this->assertSame( 403, $this->request( 'GET', '/facets' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/facets/test', array( 'facet' => $this->definition() ) )->get_status() );
	}
}
