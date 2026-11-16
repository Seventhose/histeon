<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;

/** The framework's dashboard alone in the builder's side panel — docs/spec/framework.md. */
final class FramedDashboardTest extends IntegrationTestCase {

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	/** @param array<string, mixed> $get */
	private function styles_for( array $get ): string {
		$_GET = $get;
		ob_start();
		BuilderScreen::framed_dashboard();
		return (string) ob_get_clean();
	}

	public function test_only_the_framework_page_in_the_panel_loses_the_admin_chrome(): void {
		$css = $this->styles_for( array( 'page' => 'core-framework', 'xb_frame' => '1' ) );
		$this->assertStringContainsString( '#adminmenumain', $css );
		$this->assertStringContainsString( '#wpadminbar', $css );
		$this->assertSame( '', $this->styles_for( array( 'page' => 'core-framework' ) ), 'opened on its own: the normal admin' );
		$this->assertSame( '', $this->styles_for( array( 'page' => 'plugins', 'xb_frame' => '1' ) ), 'any other admin page: untouched' );
		$this->assertSame( '', $this->styles_for( array( 'page' => array( 'core-framework' ), 'xb_frame' => '1' ) ) );
		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertSame( '', $this->styles_for( array( 'page' => 'automatic-css', 'xb_frame' => '1' ) ), 'builders only' );
	}
}
