<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Remote\PageProfileAdapter;

/**
 * The marker of content an import from Histeon CMS wrote (`_xb_transfer_imported`, docs/spec/transfer-markup.md §5):
 * older imports get it once from the import's operation records, on `admin_init`, in batches, never in a render.
 */
final class TransferImportedMarkersTest extends IntegrationTestCase {

	/** @var list<string> */
	private array $records = array();

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'xb_transfer_imported_markers', 'xb_transfer_imported_markers_cursor' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
			delete_option( $option );
		}
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( array_chunk( $this->records, 500 ) as $chunk ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', $chunk ) ); // phpcs:ignore WordPress.DB
		}
		wp_cache_flush();
		$this->records = array();
		parent::tearDown();
	}

	/** @param array<string, mixed> $status */
	private function record( array $status ): void {
		$name            = 'xb_remote_operation_' . wp_generate_uuid4();
		$this->records[] = $name;
		add_option( $name, $status, '', false );
	}

	private function component_with_identity( string $ref, string $identity ): int {
		$id = $this->component( $ref, array( $this->el( 'img', array( 'src' => '{props.i}', 'alt' => '' ) ) ), array( 'i' => array( 'type' => 'image', 'default' => '' ) ) );
		update_post_meta( $id, '_xb_page_profile_id', $identity );
		return $id;
	}

	private function render_with( string $ref, string $value ): string {
		\XB\Plugin::instance()->components->forget();
		\XB\Plugin::instance()->renderer->reset_budget();
		return $this->render( array( $this->block( 'xb/component', array( 'ref' => $ref, 'props' => array( 'i' => $value ) ) ) ) );
	}

	public function test_the_migration_runs_on_admin_init(): void {
		$this->assertSame( 20, has_action( 'admin_init', array( PageProfileAdapter::class, 'migrate_imported_markers' ) ) );
	}

	public function test_older_imports_get_the_marker_from_their_records_and_downloads_do_not(): void {
		$changed    = $this->component_with_identity( 'test/cambiato', $changed_id = wp_generate_uuid4() );
		$reported   = $this->component_with_identity( 'test/riportato', wp_generate_uuid4() );
		$downloaded = $this->component_with_identity( 'test/scaricato', $downloaded_id = wp_generate_uuid4() );
		$asset      = $this->post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ) );
		update_post_meta( $asset, '_xb_page_profile_id', $asset_id = wp_generate_uuid4() );
		$this->record( array( 'action' => 'import', 'state' => 'complete', 'changed' => array( $changed_id, $asset_id ), 'report' => array( 'components' => array( array( 'postId' => $reported, 'action' => 'updated' ), array( 'postId' => $downloaded, 'action' => 'unchanged' ) ) ) ) );
		$this->record( array( 'action' => 'export', 'changed' => array( $downloaded_id ) ) );

		// Before the migration, rendering reads the marker only: older imports render as native content.
		$this->assertStringContainsString( ' src="https://cdn.example.com/p.gif"', $this->render_with( 'test/cambiato', 'https://cdn.example.com/p.gif' ) );
		$this->assertFalse( get_option( 'xb_transfer_imported_markers' ), 'A render never runs the migration.' );

		$this->assertTrue( PageProfileAdapter::migrate_imported_markers() );
		$this->assertTrue( PageProfileAdapter::is_transfer_imported( $changed ) );
		$this->assertTrue( PageProfileAdapter::is_transfer_imported( $reported ) );
		$this->assertFalse( PageProfileAdapter::is_transfer_imported( $downloaded ), 'An unchanged entry or an export record never marks.' );
		$this->assertFalse( PageProfileAdapter::is_transfer_imported( $asset ), 'Only document post types are marked.' );
		$this->assertSame( '1', get_option( 'xb_transfer_imported_markers' ) );
		$this->assertFalse( get_option( 'xb_transfer_imported_markers_cursor' ) );
		$this->assertStringNotContainsString( ' src=', $this->render_with( 'test/cambiato', 'https://tracker.example.com/p.gif' ) );
		$this->assertStringContainsString( ' src="https://cdn.example.com/p.gif"', $this->render_with( 'test/scaricato', 'https://cdn.example.com/p.gif' ) );
	}

	public function test_an_interrupted_migration_resumes_from_its_last_batch(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->component_with_identity( 'test/ripresa-' . $i, $identity = wp_generate_uuid4() );
			$this->record( array( 'action' => 'publish', 'changed' => array( $identity ) ) );
		}
		// Records other tests left behind count as already done: the first batch holds this test's first two.
		global $wpdb;
		$first = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(option_id) FROM {$wpdb->options} WHERE option_name IN (" . implode( ',', array_fill( 0, count( $this->records ), '%s' ) ) . ')', $this->records ) ); // phpcs:ignore WordPress.DB
		update_option( 'xb_transfer_imported_markers_cursor', $first - 1, false );
		// A zero budget does one batch of two records, then stops with its progress saved.
		$this->assertFalse( PageProfileAdapter::migrate_imported_markers( 0.0, 2 ) );
		$cursor = (int) get_option( 'xb_transfer_imported_markers_cursor' );
		$this->assertGreaterThan( 0, $cursor );
		$this->assertFalse( get_option( 'xb_transfer_imported_markers' ) );
		$this->assertTrue( PageProfileAdapter::is_transfer_imported( $ids[0] ) );
		$this->assertFalse( PageProfileAdapter::is_transfer_imported( $ids[4] ) );
		$this->assertFalse( PageProfileAdapter::migrate_imported_markers( 0.0, 2 ) );
		$this->assertGreaterThan( $cursor, (int) get_option( 'xb_transfer_imported_markers_cursor' ), 'The next run starts after the last batch.' );
		$this->assertTrue( PageProfileAdapter::migrate_imported_markers( 5.0, 2 ) );
		foreach ( $ids as $id ) {
			$this->assertTrue( PageProfileAdapter::is_transfer_imported( $id ) );
		}
	}

	/** The review measured 1,500 records × 100 ids running out of 128 MB in a render: now bounded, from the admin hook. */
	public function test_fifteen_hundred_records_of_a_hundred_ids_stay_within_time_and_memory(): void {
		global $wpdb;
		$marked = $this->component_with_identity( 'test/molti', $marked_id = wp_generate_uuid4() );
		$rows   = array();
		for ( $i = 0; $i < 1500; $i++ ) {
			$changed = array();
			for ( $j = 0; $j < 100; $j++ ) {
				$changed[] = wp_generate_uuid4();
			}
			if ( 1499 === $i ) {
				$changed[] = $marked_id;
			}
			$name            = 'xb_remote_operation_' . wp_generate_uuid4();
			$this->records[] = $name;
			$rows[]          = $wpdb->prepare( '(%s, %s, %s)', $name, maybe_serialize( array( 'action' => 'import', 'state' => 'complete', 'changed' => $changed ) ), 'off' );
			if ( count( $rows ) === 250 ) {
				$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
				$rows = array();
			}
		}
		$memory  = memory_get_usage();
		$queries = $wpdb->num_queries;
		$started = microtime( true );
		$runs    = 0;
		do {
			++$runs;
			$done = PageProfileAdapter::migrate_imported_markers( 5.0 );
			$this->assertLessThan( 8.0, microtime( true ) - $started, 'Each admin request stays within its budget.' );
			$started = $done ? $started : microtime( true );
		} while ( ! $done && $runs < 20 );
		$this->assertTrue( $done );
		$this->assertTrue( PageProfileAdapter::is_transfer_imported( $marked ) );
		$this->assertLessThan( 32 * 1024 * 1024, memory_get_peak_usage() - $memory, 'Batches, not the whole log in memory.' );
		$this->assertLessThan( 600, $wpdb->num_queries - $queries, 'One identity query per batch, not one per id.' );
	}
}
