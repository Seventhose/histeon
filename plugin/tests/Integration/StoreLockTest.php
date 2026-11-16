<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Store;
use XB\Versioning\VersioningException;

/**
 * The lock row that keeps publish, restore and deploy apart where GET_LOCK does not exist (SQLite: the SQLite
 * Database Integration plugin answers it with a dummy «yes»). Plain SQL, so it is tested on any engine.
 */
final class StoreLockTest extends IntegrationTestCase {

	private const LOCK = 'wp_xb_test_lock';

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Store::lock_row( self::LOCK ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		parent::tearDown();
	}

	private function row(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Store::lock_row( self::LOCK ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_string( $value ) ? $value : null;
	}

	public function test_held_while_the_work_runs_and_released_after(): void {
		$store = Plugin::instance()->versioning->store();
		$seen  = $store->row_locked( self::LOCK, fn (): ?string => $this->row() );
		$this->assertNotNull( $seen, 'the row exists while the work runs' );
		$this->assertNull( $this->row(), 'and is gone after' );
		$this->assertSame( 'dentro', $store->row_locked( self::LOCK, static fn (): string => $store->row_locked( self::LOCK, static fn (): string => 'dentro' ) ), 're-entrant in the same request, like GET_LOCK' );
		$this->assertNull( $this->row() );

		try {
			$store->row_locked( self::LOCK, static fn () => throw new \RuntimeException( 'errore' ) );
		} catch ( \RuntimeException ) {
			$this->assertNull( $this->row(), 'released after an error too' );
		}
	}

	public function test_someone_else_s_lock_makes_us_wait_then_give_up(): void {
		global $wpdb;
		$theirs = ( time() + 600 ) . ':altro';
		$wpdb->insert( $wpdb->options, array( 'option_name' => Store::lock_row( self::LOCK ), 'option_value' => $theirs, 'autoload' => 'off' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ran = false;
		try {
			Plugin::instance()->versioning->store()->row_locked( self::LOCK, static function () use ( &$ran ): void { $ran = true; }, 1 );
			$this->fail( 'another request holds the lock' );
		} catch ( VersioningException $e ) {
			$this->assertSame( 409, $e->status );
		}
		$this->assertFalse( $ran );
		$this->assertSame( $theirs, $this->row(), 'someone else\'s lock is never removed' );
	}

	public function test_a_dead_request_s_lock_is_taken_over(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->options, array( 'option_name' => Store::lock_row( self::LOCK ), 'option_value' => ( time() - 1 ) . ':morto', 'autoload' => 'off' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'fatto', Plugin::instance()->versioning->store()->row_locked( self::LOCK, static fn (): string => 'fatto', 1 ) );
		$this->assertNull( $this->row() );
	}
}
