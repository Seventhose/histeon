<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Render\LoopPresets;
use XB\Render\Queries;
use XB\Versioning\Documents;
use XB\Versioning\Preview;

/** Query manager (docs/spec/queries.md). */
final class QueriesTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( LoopPresets::OPTION, get_option( LoopPresets::OPTION, array() ) );
		$this->set_option( LoopPresets::TYPES, get_option( LoopPresets::TYPES, array() ) );
	}

	public function test_validation(): void {
		$ok = Queries::validate( array( 'news' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'tax_query' => array( array( 'taxonomy' => 'category', 'terms' => array( 1 ) ) ) ) ) ) );
		$this->assertSame( 'posts', $ok['news']['type'] );
		$bad = array(
			array( 'posts' => array( 'type' => 'posts' ) ),
			array( '1x' => array( 'type' => 'posts' ) ),
			array( 'q' => array( 'type' => 'sql' ) ),
			array( 'q' => array( 'type' => 'posts', 'args' => array( 'post_status' => 'draft' ) ) ),
			array( 'q' => array( 'type' => 'posts', 'args' => array( 'suppress_filters' => true ) ) ),
			array( 'q' => array( 'type' => 'posts', 'args' => array( 'Bad Key' => 1 ) ) ),
			array( 'q' => array( 'type' => 'posts', 'args' => array( 'a' => array( array( array( array( array( array( 1 ) ) ) ) ) ) ) ) ),
			array( 'q' => array( 'type' => 'posts', 'args' => array( 'a' => new \stdClass() ) ) ),
		);
		foreach ( $bad as $i => $presets ) {
			try {
				Queries::validate( $presets );
				$this->fail( "case $i accepted" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_builders_filter_only_on_what_visitors_may_read(): void {
		register_taxonomy( 'zq_interna', 'post', array( 'public' => false ) );
		$preset = static fn ( array $args, string $type = 'posts' ): array => array( 'q' => array( 'type' => $type, 'args' => $args ) );
		$hidden = array(
			$preset( array( 'meta_query' => array( array( 'key' => '_codice_interno', 'value' => '^A', 'compare' => 'REGEXP' ) ) ) ),
			$preset( array( 'meta_query' => array( 'relation' => 'OR', array( array( 'key' => 'nota_non_pubblica', 'value' => 'x' ) ) ) ) ),
			$preset( array( 'meta_key' => '_prezzo_acquisto', 'orderby' => 'meta_value_num' ) ),
			$preset( array( 'tax_query' => array( array( 'taxonomy' => 'zq_interna', 'terms' => array( 1 ) ) ) ) ),
			$preset( array( 'taxonomy' => 'zq_interna' ), 'terms' ),
			$preset( array( 'taxonomy' => 'category', 'meta_query' => array( array( 'key' => 'x' ) ) ), 'terms' ),
			// A value with no key is compared with every field, private ones too.
			$preset( array( 'meta_query' => array( array( 'value' => 'segreto', 'compare' => 'LIKE' ) ) ) ),
			$preset( array( 'meta_query' => array( 'relation' => 'AND', array( array( 'compare' => 'EXISTS', 'value' => 'x' ) ) ) ) ),
			$preset( array( 'meta_value' => 'segreto' ) ),
			$preset( array( 'meta_compare' => 'LIKE', 'meta_value' => 'seg' ) ),
			$preset( array( 'meta_query' => array( array( 'key' => 'prezzo', 'compare_key' => 'LIKE', 'value' => 'x' ) ) ) ),
		);
		$builder = $this->user( 'author' );
		( new \WP_User( $builder ) )->add_cap( 'xb_build' );
		$admin = get_current_user_id();
		wp_set_current_user( $builder );
		try {
			foreach ( $hidden as $i => $presets ) {
				try {
					Queries::validate( $presets );
					$this->fail( "case $i accepted for a builder" );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( 'non può leggere', $e->getMessage() );
				}
			}
			$this->assertArrayHasKey( 'q', Queries::validate( $preset( array( 'tax_query' => array( array( 'taxonomy' => 'category', 'terms' => array( 1 ) ) ), 'orderby' => 'date' ) ) ) );
		} finally {
			wp_set_current_user( $admin );
			unregister_taxonomy( 'zq_interna' );
		}
		// An administrator decides what is public, and may query by any field.
		$this->assertArrayHasKey( 'q', Queries::validate( $hidden[0] ) );
	}

	public function test_saved_in_dev_previewed_then_published(): void {
		$this->post( array( 'post_title' => 'Visibile' ) );
		$get = $this->request( 'GET', '/queries' );
		$this->assertSame( 200, $get->get_status() );
		$data = $get->get_data();
		$save = $this->request(
			'POST',
			'/queries',
			array(
				'presets' => array( 'ultimi' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => '$count ?? 1' ) ) ),
				'head'    => $data['head'],
				'base'    => $data['base'],
			)
		);
		$this->assertSame( 200, $save->get_status(), (string) wp_json_encode( $save->get_data() ) );
		$this->assertArrayNotHasKey( 'ultimi', (array) get_option( LoopPresets::OPTION, array() ), 'not live before a release' );
		$this->assertSame( array(), ( new LoopPresets() )->run( 'ultimi', array() ) );

		Preview::use_dev();
		$this->assertCount( 1, ( new LoopPresets() )->run( 'ultimi', array() ), 'previews and the canvas use the dev queries' );
		Preview::reset();

		$this->assertSame( 200, $this->publish()->get_status() );
		$this->assertArrayHasKey( 'ultimi', (array) get_option( LoopPresets::OPTION ) );
	}

	/** D2: "$today" and "$now" in a query, for upcoming events; a loop parameter with the same name wins. */
	public function test_today_and_now_in_a_query(): void {
		$past   = $this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Evento passato zq' ) );
		$future = $this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Evento futuro zq' ) );
		update_post_meta( $past, 'zq_data', wp_date( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) );
		update_post_meta( $future, 'zq_data', wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) );
		update_option(
			LoopPresets::OPTION,
			Queries::validate(
				array(
					'zq_prossimi' => array(
						'type' => 'posts',
						'args' => array(
							'meta_query' => array( array( 'key' => 'zq_data', 'value' => '$today', 'compare' => '>=', 'type' => 'DATE' ) ),
							'orderby'    => 'meta_value',
							'meta_key'   => 'zq_data',
						),
					),
				)
			)
		);
		$loop = fn ( array $args = array() ): string => $this->render( array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'zq_prossimi' ) + ( array() === $args ? array() : array( 'args' => $args ) ), 'as' => 'e' ), array( $this->text( '[{e.title}]' ) ) ) ) );
		$html = $loop();
		$this->assertStringContainsString( '[Evento futuro zq]', $html );
		$this->assertStringNotContainsString( 'Evento passato zq', $html, 'only what is still to come' );
		$this->assertStringContainsString( '[Evento passato zq]', $loop( array( 'today' => '"2000-01-01"' ) ), 'the loop can pass its own date' );
	}

	public function test_forbidden_arguments_are_ignored_even_if_stored(): void {
		$draft = $this->post( array( 'post_title' => 'Bozza segreta', 'post_status' => 'draft' ) );
		update_option( LoopPresets::OPTION, array( 'tutto' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'post_status' => 'draft', 'suppress_filters' => true, 'posts_per_page' => 50 ) ) ) );
		wp_set_current_user( 0 );
		$ids = array_map( static fn ( $n ): int => $n->id(), ( new LoopPresets() )->run( 'tutto', array() ) );
		$this->assertNotContains( $draft, $ids );
	}

	/** Notes 5 and 9 of the 3 November audit: nothing in a stored preset lifts a list past its cap, and a static facet page has room for all. */
	public function test_no_stored_argument_lifts_the_limit_of_a_list(): void {
		$term = wp_insert_term( 'Xbt limite', 'category' );
		$this->assertIsArray( $term );
		for ( $i = 0; $i < 101; $i++ ) {
			$this->post( array( 'post_title' => 'Limite ' . $i, 'post_category' => array( (int) $term['term_id'] ) ) );
		}
		update_option(
			LoopPresets::OPTION,
			array(
				'senza-pagine' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => 2, 'nopaging' => true ) ),
				'archivio'     => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => 2, 'posts_per_archive_page' => -1, 'cat' => (int) $term['term_id'] ) ),
			)
		);
		wp_set_current_user( 0 );
		$this->assertCount( 2, ( new LoopPresets() )->run( 'senza-pagine', array() ), 'nopaging' );
		$this->assertCount( 2, ( new LoopPresets() )->run( 'archivio', array() ), 'posts_per_archive_page on an archive query' );
		$this->assertCount( 100, ( new LoopPresets() )->facet_page( array( 'post_type' => 'post', 'cat' => (int) $term['term_id'], 'posts_per_page' => 500 ) )['items'], 'a loop page stops at 100' );
		$static = ( new LoopPresets() )->facet_page( array( 'post_type' => 'post', 'cat' => (int) $term['term_id'], 'posts_per_page' => 500 ), \XB\Render\Facets::STATIC_MAX );
		$this->assertCount( 101, $static['items'], 'a static facet page holds every result' );
		$this->assertSame( 101, $static['total'] );
		wp_set_current_user( 1 );
		wp_delete_term( (int) $term['term_id'], 'category' );
	}

	public function test_test_endpoint_and_permissions(): void {
		$this->post( array( 'post_title' => 'Titolo con l’apostrofo' ) );
		$res = $this->request( 'POST', '/queries/test', array( 'id' => 'prova', 'preset' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => 1 ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Titolo con l’apostrofo', $res->get_data()['items'][0]['title'] );
		$this->assertSame( 400, $this->request( 'POST', '/queries/test', array( 'id' => 'prova', 'preset' => array( 'type' => 'posts', 'args' => array( 'post_status' => 'draft' ) ) ) )->get_status() );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'GET', '/queries' )->get_status() );
	}

	/** Doc 23 of Publish, item 205: the Query panel's preview of a loop in groups counts the groups of one query. */
	public function test_preview_of_a_grouped_loop_counts_its_groups(): void {
		$cat    = (int) self::factory_term( 'Zg gruppi ' . wp_generate_password( 6, false ) );
		$dates  = array( 'Alba' => '2024-05-02 10:00:00', 'Bosco' => '2024-03-01 10:00:00', 'Aria' => '2023-07-09 10:00:00' );
		foreach ( $dates as $title => $date ) {
			$this->post( array( 'post_title' => $title, 'post_date' => $date, 'post_category' => array( $cat ) ) );
		}
		$preset = array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'cat' => $cat, 'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC' ) );
		$res    = $this->request( 'POST', '/queries/test', array( 'id' => 'zg', 'preset' => $preset, 'groupBy' => array( 'field' => 'date', 'how' => 'Y' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 3, $res->get_data()['items'], 'the records stay the records of the query' );
		$this->assertSame( array( array( 'key' => '2024', 'count' => 2 ), array( 'key' => '2023', 'count' => 1 ) ), $res->get_data()['groups'] );
		$letters = $this->request( 'POST', '/queries/test', array( 'id' => 'zg', 'preset' => $preset, 'groupBy' => array( 'field' => 'title', 'how' => 'letter' ) ) );
		$this->assertSame( array( array( 'key' => 'A', 'count' => 2 ), array( 'key' => 'B', 'count' => 1 ) ), $letters->get_data()['groups'] );
		$this->assertArrayNotHasKey( 'groups', $this->request( 'POST', '/queries/test', array( 'id' => 'zg', 'preset' => $preset ) )->get_data(), 'no grouping, no groups' );
		foreach ( array( array( 'field' => '../x' ), array( 'field' => 'date', 'how' => '' ), array( 'field' => 'date', 'extra' => 'x' ), array( 'how' => 'Y' ) ) as $invalid ) {
			$this->assertSame( 400, $this->request( 'POST', '/queries/test', array( 'id' => 'zg', 'preset' => $preset, 'groupBy' => $invalid ) )->get_status(), (string) wp_json_encode( $invalid ) );
		}
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/queries/test', array( 'id' => 'zg', 'preset' => $preset, 'groupBy' => array( 'field' => 'date', 'how' => 'Y' ) ) )->get_status() );
		wp_set_current_user( 1 );
		wp_delete_term( $cat, 'category' );
	}

	public function test_only_administrators_widen_the_allowed_types(): void {
		update_option( LoopPresets::TYPES, array() );
		$builder = $this->user( 'editor' );
		$user    = get_user_by( 'id', $builder );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		$user->add_cap( 'xb_publish' );
		wp_set_current_user( $builder );
		try {
			Documents::apply( Documents::SETTINGS, array( 'loopTypes' => array( 'page' ) ) );
			$this->fail( 'a non-administrator widened the loop types' );
		} catch ( \XB\Versioning\VersioningException $e ) {
			$this->assertSame( 403, $e->status );
		}
		wp_set_current_user( 1 );
		Documents::apply( Documents::SETTINGS, array( 'loopTypes' => array( 'page', 'wp_block', 'nope' ) ) );
		$this->assertSame( array( 'page' ), get_option( LoopPresets::TYPES ), 'internal and unknown types are never stored' );
	}

	private static function factory_term( string $name ): int {
		$term = wp_insert_term( $name, 'category' );
		self::assertIsArray( $term );
		return (int) $term['term_id'];
	}

	public function test_preview_returns_records_with_their_keys(): void {
		$cat  = (int) self::factory_term( 'Zq categoria ' . wp_generate_password( 6, false ) );
		$post = $this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Zq record', 'post_category' => array( $cat ) ) );
		$res  = $this->request( 'POST', '/queries/test', array( 'id' => 'zq', 'preset' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'p' => $post ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$record = $res->get_data()['items'][0];
		$this->assertSame( 'Zq record', $record['title'] );
		$fields = array_column( $record['fields'], 'value', 'path' );
		$this->assertSame( 'Zq record', $fields['title'] );
		$this->assertSame( (string) $post, $fields['id'] );
		$this->assertStringContainsString( 'Zq categoria', (string) $fields['categories'] );
		$kinds = array_column( $record['fields'], 'kind', 'path' );
		$this->assertSame( 'date', $kinds['date'] );
		$this->assertSame( 'list', $kinds['categories'] );
	}

	public function test_builder_options_list_public_taxonomies_terms_and_authors(): void {
		$name = 'Zq opzione ' . wp_generate_password( 6, false );
		self::factory_term( $name );
		$this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => get_current_user_id() ) );
		$res = $this->request( 'GET', '/queries/options', array( 'type' => 'post' ) );
		$this->assertSame( 200, $res->get_status() );
		$data  = $res->get_data();
		$names = array_column( $data['taxonomies'], 'name' );
		$this->assertContains( 'category', $names );
		$this->assertNotContains( 'post_format', $names, 'not shown in the admin UI' );
		$category = $data['taxonomies'][ array_search( 'category', $names, true ) ];
		$this->assertContains( $name, array_column( $category['terms'], 'name' ) );
		$this->assertNotEmpty( $data['authors'] );
		$this->assertArrayNotHasKey( 'user_email', $data['authors'][0] );

		$this->assertSame( 400, $this->request( 'GET', '/queries/options', array( 'type' => '../x' ) )->get_status() );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'GET', '/queries/options', array( 'type' => 'post' ) )->get_status(), 'no xb_build' );
	}
}
