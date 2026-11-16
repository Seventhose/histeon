<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Export\Core\Departure;
use XB\Export\Core\Fields\Adapters;
use XB\Export\Core\Prova;

/**
 * The exit written for real (docs/spec/export-core.md §17, doc 25 §5 F1 and F4): preview with a fingerprint, writing
 * only with it, every document converted in its own post with its dates, field values in the chosen plugin's form,
 * and the restore that puts everything back as it was.
 */
final class DepartureTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! Prova::ready() ) {
			$this->markTestSkipped( 'Serve Fondamenta 0.2 attiva nel WordPress di sviluppo.' );
		}
		$this->set_option( \XB\Content\ContentTypes::OPTION, \XB\Content\ContentTypes::validate( array( 'fields' => array( 'post' => array( array( 'name' => 'zdata', 'label' => 'Data', 'type' => 'date' ), array( 'name' => 'zanni', 'label' => 'Anni', 'type' => 'number', 'multiple' => true ) ) ) ) ) );
		\XB\Content\ContentTypes::forget();
		wp_set_current_user( $this->user( 'administrator' ) );
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$this->markTestSkipped( 'Serve un amministratore con unfiltered_html.' );
		}
	}

	protected function tearDown(): void {
		while ( array() !== Departure::backups() ) {
			Departure::restore();
		}
		\XB\Content\ContentTypes::forget();
		parent::tearDown();
	}

	public function test_preview_write_check_and_restore(): void {
		$article = $this->post( array( 'post_title' => 'Con i campi' ) );
		add_post_meta( $article, 'zdata', '2026-01-10', true );
		add_post_meta( $article, 'zanni', array( 2024, 2025 ), true );
		$page   = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Pagina in uscita',
				'post_content' => $this->markup(
					array(
						$this->el( 'section', array( 'class' => 'uscita' ), array( $this->el( 'h2', array(), array( $this->text( 'Uscita' ) ) ) ) ),
						$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'limit' => '1', 'include' => '[' . $article . ']' ) ), 'as' => 'x' ), array( $this->el( 'p', array( 'class' => 'data' ), array( $this->text( '{x.fields.zdata.date("j F Y")}' ) ) ) ) ),
					)
				),
			)
		);
		global $wpdb;
		$before   = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_modified FROM {$wpdb->posts} WHERE ID = %d", $page ), ARRAY_A );
		$meta     = get_post_meta( $article );
		$adapter  = Adapters::get( 'scf' );
		$exit     = new Departure( $adapter );
		$plan     = $exit->plan();
		$this->assertContains( $page, array_column( $plan['documents'], 'id' ) );
		$this->assertContains( $article, $plan['fields']['posts'] );
		$this->assertSame( 'scf', $plan['fields']['plugin'] );
		$this->assertSame( $plan['fingerprint'], ( new Departure( $adapter ) )->plan()['fingerprint'], 'the same site, the same fingerprint' );
		$this->assertNotSame( $plan['fingerprint'], ( new Departure() )->plan()['fingerprint'], 'another choice, another fingerprint' );

		// Only with the fingerprint of the preview.
		try {
			$exit->apply( 'sbagliata' );
			$this->fail( 'written with a wrong fingerprint' );
		} catch ( \RuntimeException ) {
			$this->assertSame( array(), Departure::backups() );
		}

		$done = $exit->apply( $plan['fingerprint'] );
		$this->assertGreaterThanOrEqual( 1, $done['documents'] );
		$after = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_modified FROM {$wpdb->posts} WHERE ID = %d", $page ), ARRAY_A );
		$this->assertStringNotContainsString( '<!-- wp:xb/', (string) $after['post_content'] );
		$this->assertStringContainsString( '<!-- wp:fondamenta/loop', (string) $after['post_content'] );
		$this->assertSame( $before['post_modified'], $after['post_modified'], 'its dates stay' );
		$this->assertSame( '20260110', get_post_meta( $article, 'zdata', true ) );
		$this->assertSame( '2', get_post_meta( $article, 'zanni', true ) );
		$this->assertNotContains( $page, array_column( Departure::check()['left'], 'id' ) );
		$this->assertCount( 1, Departure::backups() );
		// What Fondamenta draws from the converted page shows the field as before.
		$this->go_to( $page );
		$this->assertStringContainsString( '10 January 2026', do_blocks( (string) $after['post_content'] ) );

		// The restore puts everything back as it was.
		$restored = Departure::restore();
		$this->assertGreaterThanOrEqual( 1, $restored['posts'] );
		$this->assertSame( $before, $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_modified FROM {$wpdb->posts} WHERE ID = %d", $page ), ARRAY_A ) );
		$this->assertEquals( $meta, get_post_meta( $article ) );
		$this->assertSame( array(), Departure::backups() );
	}

	public function test_writing_needs_an_administrator_who_may_write_any_html(): void {
		wp_set_current_user( $this->user( 'editor' ) );
		$this->expectException( \RuntimeException::class );
		( new Departure() )->apply( 'x' );
	}

	public function test_finish_needs_a_written_exit_and_the_site_theme(): void {
		try {
			Departure::finish( Departure::theme_slug() );
			$this->fail( 'finished without an exit' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'anteprima', $e->getMessage() );
		}
		$this->assertMatchesRegularExpression( '/-tema$/', Departure::theme_slug() );
	}
}
