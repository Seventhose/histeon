<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Store;

/** Commits are read once per request (V10): they never change, but a rolled-back one must not linger. */
final class StoreReadCacheTest extends IntegrationTestCase {

	public function test_a_commit_is_read_from_the_database_once(): void {
		global $wpdb;
		$store  = Plugin::instance()->versioning->store();
		$commit = $store->commit( 'post:999001', Store::DEV, array( 'content' => str_repeat( 'x', 1000 ) ), null, null, 'cache' );
		$fresh  = new Store();
		$before = $wpdb->num_queries;
		$first  = $fresh->get( $commit['id'] );
		$again  = $fresh->get( $commit['id'] );
		$this->assertSame( 1, $wpdb->num_queries - $before );
		$this->assertSame( $first, $again );
		$this->assertSame( $commit['hash'], $again['hash'] ?? null );
		$this->assertNull( $fresh->get( str_repeat( '0', 32 ) ) );
	}

	public function test_a_rollback_forgets_what_was_read_inside_it(): void {
		$store = new Store();
		$id    = '';
		try {
			$store->transaction(
				static function () use ( $store, &$id ): void {
					$id = $store->commit( 'post:999002', Store::DEV, array( 'content' => 'annullato' ), null, null, 'rollback' )['id'];
					$store->get( $id );
					throw new \RuntimeException( 'annulla' );
				}
			);
		} catch ( \RuntimeException ) {
			// Expected.
		}
		$this->assertNotSame( '', $id );
		$this->assertNull( $store->get( $id ), 'gone with the transaction, and from the memory' );
	}
}
