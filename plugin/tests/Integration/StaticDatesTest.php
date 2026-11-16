<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Render\LoopPresets;
use XB\StaticSite\StaticBridge;

/**
 * Lists that depend on the day ("$today", "$now" in a query preset) go stale in a static copy: once a day Histeon
 * asks Static Bridge to regenerate the pages that show them (D2).
 */
final class StaticDatesTest extends IntegrationTestCase {

	/** @var list<array{0: list<string>, 1: string}> */
	private array $queued = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_option(
			LoopPresets::OPTION,
			array(
				'xbt-oggi-in-poi'  => array(
					'type' => 'posts',
					'args' => array( 'post_type' => 'post', 'meta_query' => array( array( 'key' => 'data', 'value' => '$today', 'compare' => '>=', 'type' => 'DATE' ) ) ),
				),
				'xbt-ultimi'    => array(
					'type' => 'posts',
					'args' => array( 'post_type' => 'post', 'posts_per_page' => '$limit ?? 3' ),
				),
			)
		);
		$this->queued = array();
		add_action( 'static_bridge_queue_paths', array( $this, 'record' ), 10, 2 );
	}

	protected function tearDown(): void {
		remove_action( 'static_bridge_queue_paths', array( $this, 'record' ), 10 );
		wp_clear_scheduled_hook( StaticBridge::DAILY_HOOK );
		parent::tearDown();
	}

	/** @param list<string> $paths */
	public function record( array $paths, string $reason ): void {
		$this->queued[] = array( $paths, $reason );
	}

	/** @param array<string, mixed> $source */
	private function page_with_loop( array $source ): int {
		return $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->block( 'xb/loop', array( 'source' => $source, 'as' => 'item' ), array( $this->text( '{item.title}' ) ) ) ) ),
			)
		);
	}

	public function test_asks_once_a_day_for_the_pages_whose_lists_depend_on_the_date(): void {
		$dated    = $this->page_with_loop( array( 'kind' => 'preset', 'id' => 'xbt-oggi-in-poi' ) );
		$undated  = $this->page_with_loop( array( 'kind' => 'preset', 'id' => 'xbt-ultimi' ) );
		$ref      = 'date/elenco';
		$this->component( $ref, array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'xbt-oggi-in-poi' ), 'as' => 'item' ), array( $this->text( '{item.title}' ) ) ) ) );
		$through  = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->block( 'xb/component', array( 'uid' => 'datecmp1', 'ref' => $ref ) ) ) ),
			)
		);
		$draft    = $this->post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => $this->markup( array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'xbt-oggi-in-poi' ), 'as' => 'item' ) ) ) ) ) );

		StaticBridge::refresh_dated();
		$this->assertCount( 1, $this->queued, 'one request, whoever else listens' );
		[ $paths, $reason ] = $this->queued[0];
		$this->assertSame( 'histos-dates', $reason );
		$this->assertContains( (string) get_permalink( $dated ), $paths );
		$this->assertContains( (string) get_permalink( $through ), $paths, 'through a component' );
		$this->assertNotContains( (string) get_permalink( $undated ), $paths );
		$this->assertNotContains( (string) get_permalink( $draft ), $paths, 'no draft' );

		// Scheduled daily only where Static Bridge listens.
		StaticBridge::schedule();
		$this->assertNotFalse( wp_next_scheduled( StaticBridge::DAILY_HOOK ) );
		// Nobody listens (Static Bridge itself may be active in this site: its listener is set aside too).
		global $wp_filter;
		$listeners = $wp_filter['static_bridge_queue_paths'] ?? null;
		remove_all_actions( 'static_bridge_queue_paths' );
		StaticBridge::schedule();
		$this->assertFalse( wp_next_scheduled( StaticBridge::DAILY_HOOK ) );
		$this->queued = array();
		StaticBridge::refresh_dated();
		$this->assertSame( array(), $this->queued );
		if ( null !== $listeners ) {
			$wp_filter['static_bridge_queue_paths'] = $listeners; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored as it was.
		}
	}
}
