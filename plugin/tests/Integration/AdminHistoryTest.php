<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\Menu;
use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Store;
use XB\Versioning\VersioningException;

/** XB → Versioni: a document's history and bringing an earlier version back into development (ADR 0010, U15). */
final class AdminHistoryTest extends IntegrationTestCase {

	public function test_history_lists_versions_and_their_releases(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->text( 'uno' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'due' ) ) ) )->get_status() );
		$release = $this->publish( array( Documents::for_post( $page ) ) )->get_data();
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'tre' ) ) ) )->get_status() );

		$store   = Plugin::instance()->versioning->store();
		$doc     = Documents::for_post( $page );
		$history = $store->history( $doc );
		$this->assertGreaterThanOrEqual( 3, count( $history ) );
		$this->assertSame( $store->count_versions( $doc ), count( $history ) );
		$this->assertSame( (string) $store->head( $doc, Store::DEV ), $history[0]['id'], 'newest first' );
		$this->assertContains( $release['version'], array_merge( ...array_values( $store->releases_of( $doc ) ) ) );
	}

	public function test_revert_brings_an_old_version_back_into_development_only(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->text( 'originale' ) ) ) ) );
		$doc  = Documents::for_post( $page );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'prima modifica' ) ) ) )->get_status() );
		$store = Plugin::instance()->versioning->store();
		$old   = (string) $store->head( $doc, Store::DEV );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'seconda modifica' ) ) ) )->get_status() );

		$commit = Plugin::instance()->versioning->revert( $doc, $old );
		$this->assertSame( (string) $store->head( $doc, Store::DEV ), $commit['id'] );
		$this->assertStringContainsString( 'prima modifica', (string) $commit['payload']['content'] );
		$this->assertStringContainsString( 'originale', $this->content( $page ), 'nothing goes live before a release' );

		$this->expectException( VersioningException::class );
		Plugin::instance()->versioning->revert( Documents::for_post( $this->post( array( 'post_type' => 'page' ) ) ), $old );
	}

	public function test_an_untrusted_version_stays_untrusted_when_an_administrator_restores_it(): void {
		$page    = $this->post( array( 'post_type' => 'page' ) );
		$doc     = Documents::for_post( $page );
		$hostile = $this->markup( array( $this->el( 'div', array( 'onclick' => 'alert(1)' ), array( $this->text( 'x' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'pulito' ) ) ) )->get_status() );
		$copy   = Plugin::instance()->versioning->working_copy( $doc );
		$commit = Plugin::instance()->versioning->save_dev( $doc, array( 'content' => $hostile, 'script' => '' ), $copy['head'], $copy['base'], 'import', false, true );
		$this->assertFalse( $commit['trusted'] );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'dopo' ) ) ) )->get_status() );

		$restored = Plugin::instance()->versioning->revert( $doc, $commit['id'] );
		$this->assertFalse( $restored['trusted'] );
		$this->assertStringNotContainsString( 'onclick', (string) $restored['payload']['content'] );
	}

	public function test_access_follows_the_builder_rules(): void {
		$page = $this->post( array( 'post_type' => 'page' ) );
		$this->assertTrue( Menu::can_see( Documents::for_post( $page ) ) );
		$this->assertTrue( Menu::can_see( Documents::SETTINGS ) );
		$this->assertFalse( Menu::can_see( 'post:999999' ) );
		$this->assertFalse( Menu::can_see( 'nonsense' ) );

		$author = $this->user( 'author' );
		$user   = get_userdata( $author );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		wp_set_current_user( $author );
		$this->assertFalse( Menu::can_see( Documents::for_post( $page ) ), 'someone else\'s page' );
		$this->assertFalse( Menu::can_see( Documents::SETTINGS ), 'settings are for administrators' );
		$this->assertTrue( Menu::can_see( Documents::STYLES ) );
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertFalse( Menu::can_see( Documents::STYLES ), 'no xb_build' );
	}
}
