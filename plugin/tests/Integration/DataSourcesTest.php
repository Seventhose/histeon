<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Render\LoopPresets;
use XB\Render\Sources;

/** Data sources: JSON sources, the `data` root and the users loop — docs/spec/data.md. */
final class DataSourcesTest extends IntegrationTestCase {

	protected function tearDown(): void {
		delete_option( Sources::OPTION );
		delete_option( LoopPresets::OPTION );
		parent::tearDown();
	}

	public function test_sources_are_validated(): void {
		$ok = Sources::validate( array( 'orari' => array( 'data' => array( array( 'giorno' => 'Lunedì', 'ore' => '9-13' ) ) ) ) );
		$this->assertSame( 'json', $ok['orari']['type'] );
		$cases = array(
			'reserved name'   => array( array( 'posts' => array( 'data' => 1 ) ), array() ),
			'bad name'        => array( array( '1x' => array( 'data' => 1 ) ), array() ),
			'taken by query'  => array( array( 'eventi' => array( 'data' => 1 ) ), array( 'eventi' ) ),
			'no data'         => array( array( 'x' => array( 'type' => 'json' ) ), array() ),
			'object injected' => array( array( 'x' => array( 'data' => array( '__proto__' => 1 ) ) ), array() ),
			'too deep'        => array( array( 'x' => array( 'data' => json_decode( str_repeat( '[', 12 ) . str_repeat( ']', 12 ) ) ) ), array() ),
			'too big'         => array( array( 'x' => array( 'data' => str_repeat( 'a', Sources::MAX_BYTES + 1 ) ) ), array() ),
		);
		foreach ( $cases as $label => [ $sources, $taken ] ) {
			try {
				Sources::validate( $sources, $taken );
				$this->fail( "accepted: $label" );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_data_root_reads_json_and_queries_without_a_loop_and_loops_over_json(): void {
		update_option( Sources::OPTION, Sources::validate( array( 'orari' => array( 'data' => array( array( 'giorno' => 'Lunedì <b>', 'ore' => '9-13' ), array( 'giorno' => 'Martedì', 'ore' => '14-18' ) ) ) ) ) );
		$this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Zq ultimo articolo' ) );
		$html = $this->render(
			array(
				$this->el( 'p', array( 'class' => 'one' ), array( $this->text( '{data.orari[0].giorno} {data.orari[1].ore}' ) ) ),
				$this->el( 'p', array( 'class' => 'two' ), array( $this->text( '{data.posts[0].title}' ) ) ),
				$this->el( 'p', array( 'class' => 'three' ), array( $this->text( '[{data.nessuna[0].x}]' ) ) ),
				$this->block(
					'xb/loop',
					array(
						'source' => array( 'kind' => 'expr', 'expr' => 'data.orari' ),
						'as'     => 'h',
					),
					array( $this->el( 'li', array(), array( $this->text( '{h.giorno}: {h.ore}' ) ) ) )
				),
			)
		);
		$this->assertStringContainsString( 'Lunedì &lt;b&gt; 14-18', $html, 'JSON values are data, escaped' );
		$this->assertStringContainsString( 'Zq ultimo articolo', $html, 'a query read without a loop' );
		$this->assertStringContainsString( '[]', $html, 'an unknown source is empty' );
		$this->assertStringContainsString( '<li>Martedì: 14-18</li>', $html );
	}

	public function test_users_loop_lists_only_public_authors(): void {
		$author = $this->user( 'author' );
		$this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => $author ) );
		$silent = $this->user( 'subscriber' );
		$names  = array_map( static fn ( $u ): string => (string) $u, ( new LoopPresets() )->run( 'users', array( 'limit' => 100 ) ) );
		$a      = get_userdata( $author );
		$b      = get_userdata( $silent );
		$this->assertInstanceOf( \WP_User::class, $a );
		$this->assertInstanceOf( \WP_User::class, $b );
		$this->assertContains( $a->display_name, $names );
		$this->assertNotContains( $b->display_name, $names, 'nobody without published content' );

		$profile = ( new LoopPresets() )->run( 'users', array( 'limit' => 100 ) )[0];
		$this->assertNull( $profile->get( 'email' ) );
		$this->assertNull( $profile->get( 'login' ) );
		$this->assertIsInt( $profile->get( 'postCount' ) );

		$this->expectException( \InvalidArgumentException::class );
		\XB\Render\Queries::validate( array( 'team' => array( 'type' => 'users', 'args' => array( 'search' => '*@example.test' ) ) ) );
	}

	public function test_sources_saved_in_dev_and_published_with_a_release(): void {
		$data = $this->request( 'GET', '/queries' )->get_data();
		$res  = $this->request(
			'POST',
			'/queries',
			array(
				'presets' => (object) array(),
				'sources' => array( 'sedi' => array( 'data' => array( array( 'citta' => 'Milano' ) ) ) ),
				'head'    => $data['head'],
				'base'    => $data['base'],
			)
		);
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( array(), (array) get_option( Sources::OPTION, array() ), 'nothing live before a release' );
		$this->assertSame( 'Milano', $this->request( 'GET', '/queries' )->get_data()['sources']->sedi['data'][0]['citta'] );

		$this->assertSame( 200, $this->publish( array( \XB\Versioning\Documents::SETTINGS ) )->get_status() );
		$this->assertSame( 'Milano', get_option( Sources::OPTION )['sedi']['data'][0]['citta'] );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->request( 'POST', '/queries', array( 'presets' => (object) array(), 'head' => 'x', 'base' => 'y' ) )->get_status() );
	}
}
