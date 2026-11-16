<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Render\ComponentRegistry;

/** Who owns a component key comes from one map (V14), which follows every change of keys and states. */
final class ComponentRefsTest extends IntegrationTestCase {

	public function test_keys_are_looked_up_without_a_query_each(): void {
		global $wpdb;
		$card = $this->component( 'zq-ref-card', array( $this->text( 'scheda' ) ) );
		$this->component( 'zq-ref-hero', array( $this->text( 'testata' ) ) );
		ComponentRegistry::forget_refs();
		$this->assertSame( $card, ComponentRegistry::owner( 'zq-ref-card' ) );
		ComponentRegistry::reset_memory();
		$before = $wpdb->num_queries;
		foreach ( array( 'zq-ref-card', 'zq-ref-hero', 'zq-ref-none', 'zq-ref-card' ) as $ref ) {
			ComponentRegistry::owner( $ref );
		}
		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before, 'one read of the map at most, whatever the number of keys' );
		$this->assertNull( ComponentRegistry::owner( 'zq-ref-none' ) );
	}

	public function test_the_map_follows_keys_states_and_rollbacks(): void {
		$card = $this->component( 'zq-ref-moved', array( $this->text( 'x' ) ) );
		$this->assertSame( $card, ComponentRegistry::owner( 'zq-ref-moved' ) );

		update_post_meta( $card, ComponentRegistry::META_KEY, 'zq-ref-renamed' );
		$this->assertNull( ComponentRegistry::owner( 'zq-ref-moved' ), 'a new key' );
		$this->assertSame( $card, ComponentRegistry::owner( 'zq-ref-renamed' ) );

		wp_trash_post( $card );
		$this->assertNull( ComponentRegistry::owner( 'zq-ref-renamed' ), 'a trashed component owns nothing' );
		wp_untrash_post( $card );
		wp_publish_post( $card );
		$this->assertSame( $card, ComponentRegistry::owner( 'zq-ref-renamed' ) );

		// A component created in a transaction that rolls back does not linger in this request's memory.
		$store = \XB\Plugin::instance()->versioning->store();
		try {
			$store->transaction(
				function (): void {
					$this->component( 'zq-ref-rolled', array( $this->text( 'y' ) ) );
					$this->assertNotNull( ComponentRegistry::owner( 'zq-ref-rolled' ) );
					throw new \RuntimeException( 'annulla' );
				}
			);
		} catch ( \RuntimeException ) {
			// Expected.
		}
		$this->assertNull( ComponentRegistry::owner( 'zq-ref-rolled' ) );
	}
}
