<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Versioning\Integrity;
use XB\Versioning\Schema;
use XB\Versioning\Store;
use XB\Versioning\VersioningException;

/**
 * A site moved to another address (docs/spec/versioning.md): the move rewrites the site's URL inside the commits,
 * and the commits must still read, or be repaired with the old address.
 */
final class VersionsMoveTest extends IntegrationTestCase {

	private const OLD = 'http://vecchio.local';
	private const NEW = 'https://nuovo.example';

	/** @var list<callable> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		$this->set_option( Integrity::UNREADABLE, false );
		delete_option( Integrity::UNREADABLE );
		$this->at( self::OLD );
	}

	protected function tearDown(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'pre_option_home', $filter );
			remove_filter( 'pre_option_siteurl', $filter );
		}
		parent::tearDown();
	}

	/** The site answers at this address, as after a move. */
	private function at( string $url ): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( 'pre_option_home', $filter );
			remove_filter( 'pre_option_siteurl', $filter );
		}
		$filter        = static fn (): string => $url;
		$this->filters = array( $filter );
		add_filter( 'pre_option_home', $filter );
		add_filter( 'pre_option_siteurl', $filter );
	}

	/**
	 * A page whose content names the site's address in the ways a commit holds it.
	 *
	 * @return array{content: string, script: string}
	 */
	private function payload( string $base ): array {
		$escaped = str_replace( '/', '\\/', $base );
		return array(
			'content' => '<!-- wp:xb/element {"tag":"img","attributes":{"src":"' . $escaped . '\/wp-content\/uploads\/foto.webp"}} /-->'
				. '<p><a href="' . $base . '/contatti/">Contatti</a> · <a href="//' . (string) wp_parse_url( $base, PHP_URL_HOST ) . '/blog/">Blog</a> · '
				. '<a href="https://altro.example/?u=' . rawurlencode( $base . '/x' ) . '">condividi</a> · festival＠<wbr>letteratura.it</p>'
				. '<p>Il sito è ' . $base . '.</p>',
			'script'  => '',
		);
	}

	/**
	 * What a migration plugin does to the commits table: the old address becomes the new one in the forms it knows.
	 * A slash escaped twice (a block attribute inside the payload's JSON) is missed, as a tool may miss it.
	 */
	private function move(): void {
		global $wpdb;
		$table = Schema::table( 'commits' );
		$pairs = array(
			self::OLD                                   => self::NEW,
			str_replace( '/', '\\/', self::OLD )        => str_replace( '/', '\\/', self::NEW ),
			str_replace( '/', '\\\\\\/', self::OLD )    => str_replace( '/', '\\\\\\/', self::NEW ),
			rawurlencode( self::OLD )                   => rawurlencode( self::NEW ),
			'//vecchio.local'                           => '//nuovo.example',
		);
		foreach ( $wpdb->get_results( "SELECT id, payload FROM {$table}", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$moved = strtr( (string) $row['payload'], $pairs );
			if ( $moved !== $row['payload'] ) {
				$wpdb->update( $table, array( 'payload' => $moved ), array( 'id' => $row['id'] ) );
			}
		}
		$this->at( self::NEW );
	}

	private function legacy( string $id ): void {
		global $wpdb;
		$table = Schema::table( 'commits' );
		$json  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$table} WHERE id = %s", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( $table, array( 'hash' => hash( 'sha256', $json ) ), array( 'id' => $id ) );
	}

	private function readable( string $id ): bool {
		try {
			return null !== ( new Store() )->get( $id );
		} catch ( VersioningException ) {
			return false;
		}
	}

	public function test_a_commit_written_now_still_reads_after_a_move(): void {
		$commit = ( new Store() )->commit( 'post:9001', Store::DEV, $this->payload( self::OLD ), null, null, 'prima' );
		$this->move();
		$this->assertTrue( $this->readable( $commit['id'] ) );
		$read = ( new Store() )->get( $commit['id'] );
		$this->assertIsArray( $read );
		$this->assertStringContainsString( self::NEW . '/contatti/', (string) ( $read['payload']['content'] ?? '' ), 'the content keeps the new address' );
		$this->assertFalse( get_option( Integrity::UNREADABLE ) );
	}

	public function test_an_older_commit_is_repaired_with_the_old_address_and_only_then(): void {
		$store   = new Store();
		$old     = $store->commit( 'post:9002', Store::DEV, $this->payload( self::OLD ), null, null, 'prima' );
		$changed = $store->commit( 'post:9003', Store::DEV, $this->payload( self::OLD ), null, null, 'prima' );
		$this->legacy( $old['id'] );
		$this->legacy( $changed['id'] );
		$this->assertTrue( $this->readable( $old['id'] ), 'a plain SHA-256 still reads where nothing moved' );
		$this->move();
		// Someone also changed one of them by hand: the address does not explain that.
		global $wpdb;
		$table = Schema::table( 'commits' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET payload = REPLACE(payload, 'Contatti', 'Contatto') WHERE id = %s", $changed['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertFalse( $this->readable( $old['id'] ) );
		$this->assertNotFalse( get_option( Integrity::UNREADABLE ), 'the admin offers the repair' );

		$without = Integrity::repair_all( null );
		$this->assertContains( $old['id'], $without['unreadable'], 'without the old address nothing is guessed' );
		$this->assertFalse( $this->readable( $old['id'] ) );

		$wrong = Integrity::repair_all( 'http://altro.local' );
		$this->assertContains( $old['id'], $wrong['unreadable'] );

		$result = Integrity::repair_all( self::OLD . '/' );
		$this->assertGreaterThanOrEqual( 1, $result['repaired'] );
		$this->assertTrue( $this->readable( $old['id'] ) );
		$read = ( new Store() )->get( $old['id'] );
		$this->assertIsArray( $read );
		$this->assertStringContainsString( self::NEW . '/contatti/', (string) ( $read['payload']['content'] ?? '' ) );
		$this->assertContains( $changed['id'], $result['unreadable'], 'a change other than the address stays unreadable' );
		$this->assertFalse( $this->readable( $changed['id'] ) );
		$this->assertNotFalse( get_option( Integrity::UNREADABLE ) );

		// The changed commit gone (a restored database, say): the notice goes too.
		$wpdb->delete( $table, array( 'id' => $changed['id'] ) );
		Integrity::repair_all( null );
		$this->assertFalse( get_option( Integrity::UNREADABLE ) );
	}

	public function test_a_move_to_one_scheme_needs_the_administrator_to_accept(): void {
		$store   = new Store();
		$payload = $this->payload( self::OLD );
		$payload['content'] .= '<a href="https://vecchio.local/sicuro/">anche in https</a>';
		$mixed   = $store->commit( 'post:9005', Store::DEV, $payload, null, null, 'prima' );
		$broken  = $store->commit( 'post:9006', Store::DEV, $this->payload( self::OLD ), null, null, 'prima' );
		$this->legacy( $mixed['id'] );
		global $wpdb;
		$table = Schema::table( 'commits' );
		$wpdb->update( $table, array( 'payload' => '{"content":' ), array( 'id' => $broken['id'] ) );
		$this->move();
		// https://vecchio.local became https://nuovo.example too: http and https are now one scheme.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET payload = REPLACE(payload, 'https://vecchio.local', %s) WHERE id = %s", self::NEW, $mixed['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$proved = Integrity::repair_all( self::OLD );
		$this->assertContains( $mixed['id'], $proved['unreadable'], 'the old address cannot prove it' );
		$accepted = Integrity::repair_all( self::OLD, true );
		$this->assertSame( array( $mixed['id'] ), $accepted['accepted'] );
		$this->assertTrue( $this->readable( $mixed['id'] ) );
		$this->assertSame( array( $broken['id'] ), $accepted['unreadable'], 'a payload that is no document is never accepted' );
	}

	public function test_older_fingerprints_are_upgraded_in_the_background_without_an_address(): void {
		$commit = ( new Store() )->commit( 'post:9004', Store::DEV, $this->payload( self::OLD ), null, null, 'prima' );
		$this->legacy( $commit['id'] );
		$this->set_option( Integrity::UPGRADE, false );
		delete_option( Integrity::UPGRADE );
		do {
			Integrity::upgrade_step();
		} while ( 'done' !== get_option( Integrity::UPGRADE ) );
		$this->move();
		$this->assertTrue( $this->readable( $commit['id'] ), 'upgraded before the move, it reads after it' );
	}

	public function test_the_notice_is_for_administrators_and_only_when_a_version_did_not_read(): void {
		$notice = static function (): string {
			ob_start();
			\XB\Admin\VersionsRepair::notice();
			return (string) ob_get_clean();
		};
		$this->assertSame( '', $notice(), 'nothing to repair' );
		Integrity::flag();
		$this->assertStringContainsString( 'name="xb_from"', $notice() );
		$this->assertStringContainsString( 'name="xb_confirm"', $notice(), 'accepting needs a confirmation' );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( '', $notice(), 'not for an editor' );
		// Note 115 of the 3 November audit: an administrator who does not build with Histeon does not repair its versions.
		$admin = $this->user( 'administrator' );
		( new \WP_User( $admin ) )->add_cap( 'xb_build', false );
		wp_set_current_user( $admin );
		$this->assertSame( '', $notice(), 'not without xb_build' );
		$this->assertStringContainsString( '1 non si legge ancora (abc)', \XB\Admin\VersionsRepair::summary( array( 'checked' => 3, 'upgraded' => 0, 'repaired' => 2, 'accepted' => array(), 'unreadable' => array( 'abc' ) ) ) );
	}

	public function test_the_old_address_must_be_an_address(): void {
		$this->assertNull( Integrity::origin( 'vecchio.local' ) );
		$this->assertNull( Integrity::origin( 'ftp://vecchio.local' ) );
		$this->assertNull( Integrity::origin( 'http://vec chio.local' ) );
		$this->assertSame( array( 'scheme' => 'http', 'host' => 'vecchio.local:8080' ), Integrity::origin( ' http://Vecchio.local:8080/wp ' ) );
	}
}
