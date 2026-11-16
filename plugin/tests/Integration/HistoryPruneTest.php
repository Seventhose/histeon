<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Remote\PageProfileAdapter;
use XB\Versioning\Documents;
use XB\Versioning\Schema;

/** Lista della spesa 151-153: changes in one query, the history's size, its cleanup, and old import records. */
final class HistoryPruneTest extends IntegrationTestCase {

	public function test_old_versions_go_and_what_is_needed_stays(): void {
		global $wpdb;
		$versioning = Plugin::instance()->versioning;
		$store      = $versioning->store();
		$id         = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Uno' ) ) ) ) ) ) );
		$doc        = Documents::for_post( $id );
		$save       = function ( string $text ) use ( $versioning, $doc ): string {
			$copy               = $versioning->working_copy( $doc );
			$payload            = $copy['payload'];
			$payload['content'] = $this->markup( array( $this->el( 'p', array(), array( $this->text( $text ) ) ) ) );
			return (string) $versioning->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], $text )['id'];
		};
		$first    = $save( 'Due' );
		$released = $save( 'Tre' );
		$this->assertSame( 200, $this->publish( array( $doc ) )->get_status() );
		$release        = $store->get_release( (int) get_option( \XB\Versioning\Versioning::OPTION_CURRENT ) );
		$this->assertNotNull( $release );
		$release_commit = (string) $release['items'][ $doc ];
		$middle = $save( 'Quattro' );
		$head   = $save( 'Cinque' );
		$this->assertContains( $doc, array_column( $versioning->changes(), 'doc' ), 'one query still sees the change' );

		$ids = array( $first, $released, $release_commit, $middle, $head );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'commits' ) . " SET created = %s WHERE id IN ($in)", array_merge( array( '2020-01-01 00:00:00' ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$before = $store->history_size()['commits'];
		$this->assertGreaterThan( 0, $store->prune( '2021-01-01 00:00:00' ) );
		$this->assertLessThan( $before, $store->history_size()['commits'] );
		$this->assertNull( $store->get( $first ), 'an old version nobody needs' );
		$this->assertNull( $store->get( $middle ) );
		$this->assertNotNull( $store->get( $head ), 'the dev head' );
		$this->assertNotNull( $store->get( $release_commit ), 'the commit of a release' );
		$this->assertNotNull( $store->get( (string) $store->head( $doc, \XB\Versioning\Store::PROD ) ), 'the prod head' );
		$this->assertStringContainsString( 'Cinque', (string) $versioning->working_copy( $doc )['payload']['content'] );
		$this->assertContains( $doc, array_column( $versioning->changes(), 'doc' ) );
		$this->assertSame( 200, $this->publish( array( $doc ) )->get_status(), 'publishing still works' );
	}

	public function test_finished_import_records_are_forgotten_after_their_retention(): void {
		$this->set_option( 'xb_transfer_imported_markers', '1' );
		$old    = wp_generate_uuid4();
		$recent = wp_generate_uuid4();
		$busy   = wp_generate_uuid4();
		$long   = gmdate( 'c', time() - 40 * DAY_IN_SECONDS );
		foreach ( array( $old => array( 'complete', $long ), $recent => array( 'complete', gmdate( 'c' ) ), $busy => array( 'applying', $long ) ) as $uuid => [ $state, $at ] ) {
			$this->set_option( 'xb_remote_operation_' . $uuid, array( 'state' => $state, 'updatedAt' => $at ) );
		}
		$this->set_option( 'xb_remote_operation_lock_' . $old, array( 'createdAt' => time() - 2 * DAY_IN_SECONDS ) );
		$this->assertSame( 2, PageProfileAdapter::prune_operations() );
		$this->assertFalse( get_option( 'xb_remote_operation_' . $old ) );
		$this->assertFalse( get_option( 'xb_remote_operation_lock_' . $old ) );
		$this->assertIsArray( get_option( 'xb_remote_operation_' . $recent ) );
		$this->assertIsArray( get_option( 'xb_remote_operation_' . $busy ), 'an operation still running stays' );
	}

	public function test_the_overview_shows_the_history_and_only_administrators_get_the_cleanup(): void {
		$id = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Storia' ) ) ) ) ) ) );
		Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) );
		ob_start();
		\XB\Admin\OverviewScreen::render();
		$admin = (string) ob_get_clean();
		$this->assertStringContainsString( 'Storia delle versioni', $admin );
		$this->assertStringContainsString( 'value="xb_prune_history"', $admin );
		$builder = $this->user( 'editor' );
		( new \WP_User( $builder ) )->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		ob_start();
		\XB\Admin\OverviewScreen::render();
		$this->assertStringNotContainsString( 'xb_prune_history', (string) ob_get_clean() );
	}
}
