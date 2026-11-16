<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\SiteStatus;
use XB\Modules\FatalGuard;

/** Panoramica → Stato del sito (docs/spec/overview.md). */
final class SiteStatusTest extends IntegrationTestCase {

	protected function tearDown(): void {
		remove_all_filters( 'xb_site_status' );
		parent::tearDown();
	}

	public function test_every_module_has_a_row_and_a_stopped_one_says_where(): void {
		$labels = array_column( SiteStatus::rows(), 'label' );
		$this->assertContains( 'Histeon CSS', $labels );
		$this->assertContains( 'Lingua Bridge', $labels );
		$this->assertContains( 'Static Bridge', $labels );

		$this->set_option( FatalGuard::PAUSED, array( 'lingua-bridge' => array( 'time' => time(), 'file' => 'modules/lingua-bridge/x.php', 'line' => 12, 'message' => 'boom' ) ) );
		$row = array_values( array_filter( SiteStatus::rows(), static fn ( array $r ): bool => 'Lingua Bridge' === $r['label'] ) )[0];
		$this->assertSame( 'warn', $row['state'] );
		$this->assertStringContainsString( 'modules/lingua-bridge/x.php, riga 12', $row['value'] );
	}

	public function test_rows_from_modules_are_plain_text_and_stay_in_this_admin(): void {
		add_filter(
			'xb_site_status',
			static fn ( array $rows ): array => array_merge(
				$rows,
				array(
					array( 'label' => 'Traduzioni', 'value' => '<b>3</b> da tradurre', 'state' => 'warn', 'url' => admin_url( 'admin.php?page=lingua-bridge' ) ),
					array( 'label' => 'Altrove', 'value' => 'x', 'state' => 'strano', 'url' => 'https://evil.example/' ),
					array( 'value' => 'senza etichetta' ),
					'non una riga',
				)
			)
		);
		$rows = array_column( SiteStatus::rows(), null, 'label' );
		$this->assertSame( '3 da tradurre', $rows['Traduzioni']['value'] );
		$this->assertSame( 'warn', $rows['Traduzioni']['state'] );
		$this->assertSame( admin_url( 'admin.php?page=lingua-bridge' ), $rows['Traduzioni']['url'] );
		$this->assertSame( '', $rows['Altrove']['url'], 'no link out of the admin' );
		$this->assertSame( 'info', $rows['Altrove']['state'] );
		$this->assertArrayNotHasKey( '', $rows );
	}

	public function test_the_overview_shows_the_card(): void {
		ob_start();
		\XB\Admin\OverviewScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Stato del sito', $html );
		$this->assertStringContainsString( 'Histeon CSS', $html );
	}
}
