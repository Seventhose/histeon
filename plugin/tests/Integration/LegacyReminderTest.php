<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\LegacyReminder;
use XB\Framework\AcssLegacy;
use XB\Import\Etch\Migration;

/** Etch and Automatic.css turned off, the site unchanged; the import recommended, never forced — docs/spec/histos-css.md §4. */
final class LegacyReminderTest extends IntegrationTestCase {

	public function test_etch_content_left_is_reminded_until_imported(): void {
		$had = Migration::has_content();
		$id  = $this->post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:etch/text {"content":"Da Etch"} /-->',
			)
		);
		$this->assertTrue( Migration::has_content() );
		$this->assertNotEmpty( array_filter( LegacyReminder::pending(), static fn ( string $l ): bool => str_contains( $l, 'Etch' ) ) );
		wp_trash_post( $id );
		$this->assertSame( $had, Migration::has_content(), 'trashed content is not counted' );
	}

	public function test_with_automatic_css_active_nothing_is_served_twice(): void {
		if ( ! class_exists( '\\Automatic_CSS\\API' ) ) {
			$this->markTestSkipped( 'Automatic.css non è attivo nel WordPress di sviluppo.' );
		}
		$this->assertFalse( AcssLegacy::needed(), 'its own plugin serves its sheets' );
	}

	public function test_later_hides_the_reminder_for_fourteen_days(): void {
		LegacyReminder::postpone( 1 );
		$until = (int) get_user_meta( 1, LegacyReminder::META, true );
		$this->assertGreaterThan( time() + 13 * DAY_IN_SECONDS, $until );
		wp_set_current_user( 1 );
		ob_start();
		LegacyReminder::render();
		$this->assertSame( '', (string) ob_get_clean(), 'hidden while postponed' );
		delete_user_meta( 1, LegacyReminder::META );
	}
}
