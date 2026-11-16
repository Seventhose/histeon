<?php
/**
 * Doc 23 item 236: dates in the Histeon project's language, as in Publish, when the site speaks another one.
 *
 * @package XB
 */

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Expr\Modifiers;

final class ProjectDateNamesTest extends IntegrationTestCase {

	private function date( string $format ): string {
		return (string) ( new Modifiers() )->apply( 'date', '2026-07-12', array( $format ) );
	}

	public function test_month_and_day_names_follow_the_project_language_when_the_site_speaks_another(): void {
		if ( ! Modifiers::intl_speaks( 'de-DE' ) ) {
			// ICU with reduced data (the CLI image): the names stay WordPress's, as a server like that shows them.
			$this->assertSame( (string) wp_date( 'j F Y', ( new \DateTimeImmutable( '2026-07-12', wp_timezone() ) )->getTimestamp() ), $this->date( 'j F Y' ) );
			$this->markTestSkipped( 'Questo PHP non ha i dati ICU del tedesco: i nomi restano quelli di WordPress.' );
		}
		$this->set_option( 'xb_page_profile_default_locale', get_option( 'xb_page_profile_default_locale', false ) );
		$site      = static fn (): string => 'it_IT';
		$wordpress = (string) wp_date( 'j F Y', ( new \DateTimeImmutable( '2026-07-12', wp_timezone() ) )->getTimestamp() );
		add_filter( 'determine_locale', $site );
		try {
			update_option( 'xb_page_profile_default_locale', 'de-DE' );
			$this->assertSame( '12 Juli 2026', $this->date( 'j F Y' ) );
			$this->assertSame( 'Sonntag 12 Juli', $this->date( 'l j F' ) );
			// Escaped letters stay letters, and the other tokens stay WordPress's.
			$this->assertSame( 'F: Juli, 07', $this->date( '\\F: F, m' ) );
			// The site in the project's language too: the project's names, even without WordPress's language files.
			update_option( 'xb_page_profile_default_locale', 'it-IT' );
			$this->assertSame( '12 luglio 2026', $this->date( 'j F Y' ) );
			// No project language: WordPress's own names.
			delete_option( 'xb_page_profile_default_locale' );
			$this->assertSame( $wordpress, $this->date( 'j F Y' ) );
		} finally {
			remove_filter( 'determine_locale', $site );
		}
	}
}
