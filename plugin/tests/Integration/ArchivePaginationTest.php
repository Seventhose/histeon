<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/**
 * Archive pagination in `props.page` through real WordPress requests (docs/spec/templates.md §5): the same fields as
 * the static build of Histeon Publish, with addresses from WordPress and nothing from the request.
 */
final class ArchivePaginationTest extends IntegrationTestCase {

	private const TYPE = 'xba8_libro';
	private const TAX  = 'xba8_genere';

	private string $structure = '';

	private ?\WP $wp = null;

	protected function setUp(): void {
		parent::setUp();
		$this->wp = $GLOBALS['wp'] instanceof \WP ? $GLOBALS['wp'] : null;
		$this->structure = (string) get_option( 'permalink_structure' );
		register_post_type(
			self::TYPE,
			array(
				'public'      => true,
				'label'       => 'Libri A8',
				'has_archive' => 'libri-a8',
				'rewrite'     => array( 'slug' => 'libro-a8' ),
			)
		);
		register_taxonomy( self::TAX, self::TYPE, array( 'public' => true, 'rewrite' => array( 'slug' => 'genere-a8' ) ) );
		$this->permalinks( '/%postname%/' );
		$this->set_option( 'posts_per_page', 10 );
	}

	protected function tearDown(): void {
		parent::tearDown();
		$GLOBALS['wp'] = $this->wp;
		unregister_taxonomy( self::TAX );
		unregister_post_type( self::TYPE );
		$this->permalinks( $this->structure );
		unset( $_SERVER['QUERY_STRING'] );
		$_SERVER['REQUEST_URI'] = '/';
		$_GET                   = array();
	}

	private function permalinks( string $structure ): void {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( $structure );
		$wp_rewrite->flush_rules( false );
	}

	/** Visits a URL of the site as WordPress serves a request: rewrite rules, query vars, the main query. */
	private function visit( string $path ): void {
		$query = (string) wp_parse_url( $path, PHP_URL_QUERY );
		$_SERVER['REQUEST_URI']  = $path;
		$_SERVER['QUERY_STRING'] = $query;
		$_GET                    = array();
		parse_str( $query, $_GET );
		unset( $_SERVER['PATH_INFO'] );
		$GLOBALS['wp_the_query'] = new \WP_Query();
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
		// A fresh WP each time, as WordPress's own test case does: parse_request() keeps the previous match otherwise.
		$GLOBALS['wp'] = new \WP();
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( is_post_type_viewable( $type ) && is_string( $type->query_var ) && '' !== $type->query_var ) {
				$GLOBALS['wp']->add_query_var( $type->query_var );
			}
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( $taxonomy->public && is_string( $taxonomy->query_var ) && '' !== $taxonomy->query_var ) {
				$GLOBALS['wp']->add_query_var( $taxonomy->query_var );
			}
		}
		$GLOBALS['wp']->main( $query );
	}

	/** The fields as a template prints them. */
	private function fields(): string {
		return $this->render(
			array(
				$this->text( '{props.page.page}|{props.page.totalPages}|{props.page.totalRecords}|{props.page.prevUrl}|{props.page.nextUrl}|' ),
				$this->block(
					'xb/loop',
					array(
						'source' => array( 'kind' => 'expr', 'expr' => 'props.page.pageLinks' ),
						'as'     => 'l',
					),
					array( $this->text( '{l.number}:{l.current}:{l.url};' ) )
				),
			)
		);
	}

	/** The identity fields of props.page, and the post fields that must never come from the first listed post. */
	private function identity(): string {
		return $this->render( array( $this->text( '{props.page.id}|{props.page.title}|{props.page.name}|{props.page.slug}|{props.page.type}|{props.page.typeKey}|{props.page.url}|{props.page.excerpt}|{props.page.terms}' ) ) );
	}

	/** @return list<int> */
	private function books( int $count, string $prefix = 'Libro' ): array {
		$ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = $this->post(
				array(
					'post_type'  => self::TYPE,
					'post_title' => $prefix . ' ' . $i,
					'post_name'  => strtolower( $prefix ) . '-a8-' . $i,
					'post_date'  => gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 60 ),
				)
			);
		}
		return $ids;
	}

	public function test_type_archive_with_pretty_permalinks(): void {
		$this->books( 25 );
		$base = home_url( '/libri-a8/' );

		$this->visit( '/libri-a8/' );
		$this->assertTrue( is_post_type_archive( self::TYPE ) );
		$this->assertSame( $base, $this->render( array( $this->text( '{props.page.url}' ) ) ), 'page 1 is the archive link' );
		$this->assertSame(
			'1|3|25||' . home_url( '/libri-a8/page/2/' ) . '|'
			. '1:true:' . $base . ';2:false:' . home_url( '/libri-a8/page/2/' ) . ';3:false:' . home_url( '/libri-a8/page/3/' ) . ';',
			$this->fields()
		);

		$this->visit( '/libri-a8/page/2/' );
		$this->assertSame(
			'2|3|25|' . $base . '|' . home_url( '/libri-a8/page/3/' ) . '|'
			. '1:false:' . $base . ';2:true:' . home_url( '/libri-a8/page/2/' ) . ';3:false:' . home_url( '/libri-a8/page/3/' ) . ';',
			$this->fields()
		);
		$this->assertSame( self::TYPE . '|Libri A8|Libri A8|' . self::TYPE . '|' . self::TYPE . '|' . self::TYPE . '|' . home_url( '/libri-a8/page/2/' ) . '||', $this->identity(), 'url is the page shown' );
		$link = $this->render( array( $this->el( 'a', array( 'href' => '{props.page.nextUrl}' ), array( $this->text( 'Seguente' ) ) ) ) );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/libri-a8/page/3/' ) ) . '"', $link );

		$this->visit( '/libri-a8/page/3/' );
		$this->assertCount( 5, $GLOBALS['wp_query']->posts, 'the real last page' );
		$this->assertSame(
			'3|3|25|' . home_url( '/libri-a8/page/2/' ) . '||'
			. '1:false:' . $base . ';2:false:' . home_url( '/libri-a8/page/2/' ) . ';3:true:' . home_url( '/libri-a8/page/3/' ) . ';',
			$this->fields()
		);
	}

	public function test_addresses_never_carry_the_request_query_string(): void {
		$this->books( 25 );
		$this->visit( '/libri-a8/page/2/?foo=bar&q=%22%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E' );
		$this->assertTrue( is_post_type_archive( self::TYPE ) );
		$this->assertStringContainsString( 'foo=bar', get_pagenum_link( 3 ), "WordPress's own helper would keep the visitor's query string" );
		$out = $this->fields();
		$this->assertStringStartsWith( '2|3|25|' . home_url( '/libri-a8/' ) . '|' . home_url( '/libri-a8/page/3/' ) . '|', $out );
		$this->assertSame( home_url( '/libri-a8/page/2/' ), $this->render( array( $this->text( '{props.page.url}' ) ) ), 'nor does the page shown' );
		$this->assertStringNotContainsString( 'foo', $out );
		$this->assertStringNotContainsString( 'script', $out );
		$this->assertStringNotContainsString( '?', $out );
	}

	public function test_plain_permalinks(): void {
		$this->books( 25 );
		$this->permalinks( '' );
		$base = home_url( '/?post_type=' . self::TYPE );

		$this->visit( '/?post_type=' . self::TYPE );
		$this->assertTrue( is_post_type_archive( self::TYPE ) );
		$this->assertSame(
			'1|3|25||' . esc_html( $base . '&paged=2' ) . '|'
			. '1:true:' . $base . ';2:false:' . esc_html( $base . '&paged=2' ) . ';3:false:' . esc_html( $base . '&paged=3' ) . ';',
			$this->fields()
		);

		$this->visit( '/?post_type=' . self::TYPE . '&paged=3' );
		$this->assertCount( 5, $GLOBALS['wp_query']->posts );
		$out = $this->fields();
		$this->assertStringStartsWith( '3|3|25|' . esc_html( $base . '&paged=2' ) . '||', $out );
		$this->assertStringContainsString( '3:true:', $out );
		$this->assertSame( esc_html( $base . '&paged=3' ), $this->render( array( $this->text( '{props.page.url}' ) ) ) );
		$link = $this->render( array( $this->el( 'a', array( 'href' => '{props.page.prevUrl}' ) ) ) );
		$this->assertStringContainsString( 'href="' . esc_url( $base . '&paged=2' ) . '"', $link );
	}

	public function test_term_archive(): void {
		$books = $this->books( 25 );
		$term  = wp_insert_term( 'Giallo A8', self::TAX, array( 'slug' => 'giallo-a8' ) );
		$this->assertIsArray( $term );
		foreach ( array_slice( $books, 0, 12 ) as $id ) {
			wp_set_object_terms( $id, (int) $term['term_id'], self::TAX );
		}
		try {
			$this->visit( '/genere-a8/giallo-a8/' );
			$this->assertTrue( is_tax( self::TAX ) );
			$this->assertSame(
				'1|2|12||' . home_url( '/genere-a8/giallo-a8/page/2/' ) . '|'
				. '1:true:' . home_url( '/genere-a8/giallo-a8/' ) . ';2:false:' . home_url( '/genere-a8/giallo-a8/page/2/' ) . ';',
				$this->fields()
			);
			$this->assertSame( 'Giallo A8|' . self::TAX . '|' . self::TAX . '|' . home_url( '/genere-a8/giallo-a8/' ), $this->render( array( $this->text( '{props.page.title}|{props.page.taxonomyKey}|{props.page.taxonomy}|{props.page.url}' ) ) ), 'the term identity, with taxonomyKey as in Publish' );

			$this->visit( '/genere-a8/giallo-a8/page/2/' );
			$this->assertStringStartsWith( '2|2|12|' . home_url( '/genere-a8/giallo-a8/' ) . '||', $this->fields() );
			$this->assertSame( home_url( '/genere-a8/giallo-a8/page/2/' ), $this->render( array( $this->text( '{props.page.url}' ) ) ) );

			$this->permalinks( '' );
			$this->visit( '/?' . self::TAX . '=giallo-a8&paged=2' );
			$this->assertTrue( is_tax( self::TAX ) );
			$this->assertStringStartsWith( '2|2|12|' . esc_html( home_url( '/?' . self::TAX . '=giallo-a8' ) ) . '||', $this->fields() );
			$this->assertSame( esc_html( home_url( '/?' . self::TAX . '=giallo-a8&paged=2' ) ), $this->render( array( $this->text( '{props.page.url}' ) ) ) );
		} finally {
			wp_delete_term( (int) $term['term_id'], self::TAX );
		}
	}

	public function test_posts_page_author_and_date_archives(): void {
		$this->set_option( 'posts_per_page', 2 );
		$author = $this->user( 'author' );
		wp_update_user( array( 'ID' => $author, 'display_name' => 'Autrice A8' ) );
		$nicename = (string) get_the_author_meta( 'user_nicename', $author );
		foreach ( array( 1, 2, 3 ) as $i ) {
			$this->post(
				array(
					'post_title'  => 'Articolo A8 ' . $i,
					'post_name'   => 'articolo-a8-' . $i,
					'post_author' => $author,
					'post_date'   => '2001-03-0' . $i . ' 10:00:00',
				)
			);
		}

		// Author archive.
		$author_url = get_author_posts_url( $author );
		$this->visit( (string) wp_parse_url( $author_url, PHP_URL_PATH ) . 'page/2/' );
		$this->assertTrue( is_author() );
		$this->assertSame( '2|2|3|' . $author_url . '||1:false:' . $author_url . ';2:true:' . trailingslashit( $author_url ) . 'page/2/;', $this->fields() );
		$this->assertSame( $author . '|Autrice A8|Autrice A8|' . $nicename . '|||' . trailingslashit( $author_url ) . 'page/2/||', $this->identity(), "the author, never the first post's fields" );

		// Date archives.
		$this->visit( '/2001/03/' );
		$this->assertTrue( is_month() );
		$this->assertStringStartsWith( '1|2|3||' . home_url( '/2001/03/page/2/' ) . '|', $this->fields() );
		$month = mysql2date( _x( 'F Y', 'monthly archives date format' ), '2001-03-01 00:00:00' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		$this->assertIsString( $month );
		$this->assertStringContainsString( '2001', $month );
		$this->assertSame( '|' . $month . '|' . $month . '||||' . home_url( '/2001/03/' ) . '||', $this->identity(), 'the period, never the first post' );
		$this->visit( '/2001/03/02/' );
		$this->assertTrue( is_day() );
		$day = mysql2date( (string) get_option( 'date_format' ), '2001-03-02 00:00:00' );
		$this->assertIsString( $day );
		$this->assertSame( '|' . $day . '|' . $day . '||||' . home_url( '/2001/03/02/' ) . '||', $this->identity() );
		$this->visit( '/2001/page/2/' );
		$this->assertTrue( is_year() );
		$this->assertStringStartsWith( '2|2|3|' . home_url( '/2001/' ) . '||', $this->fields() );
		$this->assertSame( '|2001|2001||||' . home_url( '/2001/page/2/' ) . '||', $this->identity() );

		// The posts index on the front page, then on a posts page.
		$this->set_option( 'show_on_front', 'posts' );
		$this->visit( '/' );
		$this->assertTrue( is_home() );
		$total = (int) $GLOBALS['wp_query']->found_posts;
		$this->assertGreaterThanOrEqual( 3, $total );
		$this->assertStringStartsWith( '1|' . (int) ceil( $total / 2 ) . '|' . $total . '||' . home_url( '/page/2/' ) . '|', $this->fields() );
		$this->assertSame( 'post|Blog|Blog|blog|post|post|' . home_url( '/' ) . '||', $this->identity(), "Publish's /blog/, never the first post" );
		$this->visit( '/page/2/' );
		$this->assertSame( 'post|Blog|Blog|blog|post|post|' . home_url( '/page/2/' ) . '||', $this->identity() );

		$blog = $this->post( array( 'post_type' => 'page', 'post_title' => 'Blog A8', 'post_name' => 'blog-a8' ) );
		$this->set_option( 'show_on_front', 'page' );
		$this->set_option( 'page_for_posts', $blog );
		$this->set_option( 'page_on_front', $this->post( array( 'post_type' => 'page', 'post_title' => 'Home A8' ) ) );
		$this->visit( '/blog-a8/page/2/' );
		$this->assertTrue( is_home() );
		$this->assertStringStartsWith( '2|' . (int) ceil( $total / 2 ) . '|' . $total . '|' . get_permalink( $blog ) . '|', $this->fields() );
		$this->assertSame( $blog . '|Blog A8|Blog A8|blog-a8|post|post|' . home_url( '/blog-a8/page/2/' ) . '||', $this->identity(), 'the posts page names the index; the listed type is post' );
	}

	public function test_singles_and_searches_have_no_pagination(): void {
		$this->books( 3 );
		$this->visit( '/libro-a8/libro-a8-2/' );
		$this->assertTrue( is_singular( self::TYPE ) );
		$this->assertSame( 'Libro 2|||||', $this->render( array( $this->text( '{props.page.title}|{props.page.page}|{props.page.totalPages}|{props.page.prevUrl}|{props.page.nextUrl}|{props.page.pageLinks}' ) ) ) );

		$this->visit( '/?s=Libro' );
		$this->assertTrue( is_search() );
		$this->assertSame( '||', $this->render( array( $this->text( '{props.page.page}|{props.page.nextUrl}|{props.page.pageLinks}' ) ) ) );
	}

	public function test_an_empty_archive_is_page_one_of_one(): void {
		$this->visit( '/libri-a8/' );
		$this->assertTrue( is_post_type_archive( self::TYPE ) );
		$this->assertSame( '1|1|0|||1:true:' . home_url( '/libri-a8/' ) . ';', $this->fields() );

		$author = $this->user( 'author' );
		wp_update_user( array( 'ID' => $author, 'display_name' => 'Senza articoli A8' ) );
		$url = get_author_posts_url( $author );
		$this->visit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$this->assertTrue( is_author() );
		$this->assertSame( '1|1|0|||1:true:' . $url . ';', $this->fields() );
		$this->assertSame( 'Senza articoli A8', $this->render( array( $this->text( '{props.page.title}' ) ) ) );
	}
}
