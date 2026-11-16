<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Integrity;
use XB\Versioning\Schema;
use XB\Versioning\Store;

/**
 * doc 23, voce 179: the list of changes compares a document's two heads by their fingerprints and, when those match,
 * by their bytes in the database: no document is read into PHP. On a real site WP-CLI once stopped at 128 MB there.
 */
final class ChangesMemoryTest extends IntegrationTestCase {

	private const DOCS = 150;

	/** A commit written as-is (its JSON chosen here), and the ref of `$branch` moved to it. */
	private function head( string $doc, string $branch, string $json ): void {
		global $wpdb;
		$id = bin2hex( random_bytes( 16 ) );
		$wpdb->insert(
			Schema::table( 'commits' ),
			array(
				'id'      => $id,
				'doc'     => $doc,
				'branch'  => $branch,
				'payload' => $json,
				'hash'    => Integrity::digest( $json ),
				'author'  => 1,
				'trusted' => 1,
				'message' => 'zq',
				'created' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		Plugin::instance()->versioning->store()->move( $doc, $branch, $id );
	}

	/** A large document: many small nodes, as a page of blocks is. */
	private static function document( int $n, string $salt = '' ): string {
		$nodes = array();
		for ( $i = 0; $i < 1500; $i++ ) {
			$nodes[] = array( 'tag' => 'p', 'text' => "Paragrafo $i della pagina $n $salt" );
		}
		return Store::encode( array( 'nodes' => $nodes ) );
	}

	public function test_changes_compare_heads_without_reading_the_documents(): void {
		$base = 9100000 + random_int( 0, 99999 ) * 1000;
		$docs = array();
		// Undone on the live site: two commits, the same document (an export to Etch and its restore). Not a change.
		for ( $n = 0; $n < self::DOCS; $n++ ) {
			$doc    = 'post:' . ( $base + $n );
			$json   = self::document( $n );
			$docs[] = $doc;
			$this->head( $doc, Store::DEV, $json );
			$this->head( $doc, Store::PROD, $json );
		}
		// The same fingerprint, other bytes, the same document (the address part of a URL escaped differently).
		$escaped = 'post:' . ( $base + self::DOCS );
		$this->head( $escaped, Store::DEV, '{"u":"https://zq.test/a"}' );
		$this->head( $escaped, Store::PROD, '{"u":"https:\/\/zq.test/a"}' );
		// The same fingerprint, a different document (another host): a change.
		$host = 'post:' . ( $base + self::DOCS + 1 );
		$this->head( $host, Store::DEV, '{"u":"https://uno.test/a"}' );
		$this->head( $host, Store::PROD, '{"u":"https://due.test/a"}' );
		// A real change.
		$changed = 'post:' . ( $base + self::DOCS + 2 );
		$this->head( $changed, Store::DEV, self::document( 1, 'nuovo' ) );
		$this->head( $changed, Store::PROD, self::document( 1 ) );

		$versioning = new \XB\Versioning\Versioning( new Store() );
		gc_collect_cycles();
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$list = array_column( $versioning->changes(), 'doc' );
		$peak = memory_get_peak_usage() - $before;
		fwrite( STDERR, sprintf( "\nvoce 179: changes() su %d documenti doppi: picco +%.1f MB\n", self::DOCS, $peak / 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		foreach ( $docs as $doc ) {
			$this->assertNotContains( $doc, $list, 'undone online: nothing to publish' );
		}
		$this->assertNotContains( $escaped, $list, 'the same document written with other bytes' );
		$this->assertContains( $host, $list, 'same fingerprint, another document' );
		$this->assertContains( $changed, $list );
		$this->assertLessThan( 4 * 1048576, $peak, 'the documents are not read into PHP' );

		// The comparison SQLite uses, one pair at a time: the same answers as the database's.
		$store = new Store();
		$pair  = static fn ( string $doc ): array => array( (string) $store->head( $doc, Store::DEV ), (string) $store->head( $doc, Store::PROD ) );
		$this->assertTrue( $store->same_bytes( ...$pair( $docs[0] ) ) );
		$this->assertFalse( $store->same_bytes( ...$pair( $escaped ) ) );
		$this->assertNull( $store->same_bytes( $pair( $docs[0] )[0], str_repeat( '0', 32 ) ) );
		$heads = array_column( $store->heads(), null, 'doc' );
		$this->assertTrue( $heads[ $docs[0] ]['same_bytes'] );
		$this->assertFalse( $heads[ $escaped ]['same_bytes'] );
		$this->assertNull( $heads[ $changed ]['same_bytes'], 'other fingerprints: not compared' );
	}
}
