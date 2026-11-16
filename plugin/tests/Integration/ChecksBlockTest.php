<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\ReleasesScreen;
use XB\Versioning\Documents;

/** Publishing blocked on page-check errors (docs/spec/checks.md): off by default, and then only from the builder. */
final class ChecksBlockTest extends IntegrationTestCase {

	protected function tearDown(): void {
		delete_option( ReleasesScreen::CHECKS_BLOCK );
		parent::tearDown();
	}

	public function test_off_by_default_and_the_releases_screen_publishes(): void {
		$this->assertFalse( ReleasesScreen::checks_block() );
		$page = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->text( 'uno' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'due' ) ) ) )->get_status() );
		$this->assertStringContainsString( 'Pubblica i documenti scelti', $this->screen() );
	}

	public function test_on_the_releases_screen_sends_to_the_builder(): void {
		update_option( ReleasesScreen::CHECKS_BLOCK, true, false );
		$this->assertTrue( ReleasesScreen::checks_block() );
		$page = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->text( 'uno' ) ) ) ) );
		$this->assertSame( 200, $this->save_dev( $page, $this->markup( array( $this->text( 'due' ) ) ) )->get_status() );
		$html = $this->screen();
		$this->assertStringNotContainsString( 'Pubblica i documenti scelti', $html );
		$this->assertStringContainsString( 'pubblica dal builder', $html );
		$this->assertContains( Documents::for_post( $page ), array_column( \XB\Rest\Controller::visible_changes(), 'doc' ), 'what waits is still listed' );
	}

	private function screen(): string {
		ob_start();
		ReleasesScreen::render();
		return (string) ob_get_clean();
	}
}
