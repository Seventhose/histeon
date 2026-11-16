<?php

declare(strict_types=1);

namespace XB\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XB\Versioning\ReleaseChecks;

/** The outcome of the page checks kept with a release (V1.5): what the server accepts of it. */
final class ReleaseChecksTest extends TestCase {

	private static function checks_source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/editor/src/lib/checks.ts' );
	}

	public function test_the_rules_are_the_same_list_as_check_rules_in_the_editor(): void {
		$source = self::checks_source();
		$start  = (int) strpos( $source, 'export const CHECK_RULES' );
		$list   = substr( $source, $start, (int) strpos( $source, '] as const', $start ) - $start );
		preg_match_all( "/'([a-z0-9-]+)'/", $list, $names );
		$this->assertSame( ReleaseChecks::RULES, $names[1], 'ReleaseChecks::RULES and CHECK_RULES (editor/src/lib/checks.ts) list the same rules in the same order' );
		$this->assertLessThanOrEqual( ReleaseChecks::MAX_RULES, count( ReleaseChecks::RULES ) );
	}

	public function test_every_rule_the_publish_window_reports_is_known(): void {
		// The rules written in checks.ts: `rule: 'x'` in the findings and `add('x', …)` in the head of the page.
		preg_match_all( "/(?:rule: '|add\\(')([a-z0-9-]+)'/", self::checks_source(), $found );
		$this->assertNotEmpty( $found[1] );
		foreach ( array_unique( $found[1] ) as $rule ) {
			$this->assertContains( $rule, ReleaseChecks::RULES, "the rule $rule of checks.ts is kept with a release" );
		}
	}

	public function test_a_clean_outcome_keeps_only_counts_and_known_rules(): void {
		$this->assertSame(
			array(
				'errors'   => 2,
				'warnings' => 3,
				'rules'    => array(
					'img-alt' => 2,
					'noindex' => 1,
				),
			),
			ReleaseChecks::clean(
				array(
					'errors'   => 2,
					'warnings' => 3,
					'rules'    => array(
						'img-alt'       => 2,
						'noindex'       => 1,
						'made-up'       => 4,
						'contrast'      => 'tre',
						'h1-many'       => -1,
						'link-name'     => 1.5,
						'heading-skip'  => 0,
						'<script>'      => 1,
					),
					'at'       => '2020-01-01',
					'verdict'  => 'pronto',
				)
			)
		);
		$this->assertSame(
			array(
				'errors'   => 0,
				'warnings' => 0,
				'rules'    => array(),
			),
			ReleaseChecks::clean(
				array(
					'errors'   => 0,
					'warnings' => 0,
				)
			),
			'a clean round is zeros'
		);
	}

	/** M-B1: how many pages the round checked of those of the release, kept only as two whole numbers, checked <= total. */
	public function test_pages_stay_only_as_whole_numbers_with_checked_not_above_total(): void {
		$base  = array(
			'errors'   => 0,
			'warnings' => 0,
		);
		$clean = ReleaseChecks::clean( $base + array( 'pages' => array( 'checked' => 4, 'total' => 10, 'extra' => 'x' ) ) );
		$this->assertNotNull( $clean );
		$this->assertSame(
			array(
				'checked' => 4,
				'total'   => 10,
			),
			$clean['pages'] ?? null
		);
		$this->assertSame(
			array(
				'checked' => 0,
				'total'   => 0,
			),
			ReleaseChecks::clean( $base + array( 'pages' => array( 'checked' => 0, 'total' => 0 ) ) )['pages'] ?? null
		);
		foreach ( array( 'tutte', 6, array(), array( 'checked' => 7, 'total' => 6 ), array( 'checked' => '4', 'total' => 10 ), array( 'checked' => 4, 'total' => 10.0 ), array( 'checked' => -1, 'total' => 3 ), array( 'checked' => true, 'total' => 3 ), array( 'total' => 3 ) ) as $pages ) {
			$clean = ReleaseChecks::clean( $base + array( 'pages' => $pages ) );
			$this->assertNotNull( $clean, (string) json_encode( $pages ) );
			$this->assertArrayNotHasKey( 'pages', $clean, (string) json_encode( $pages ) );
		}
	}

	public function test_text_instead_of_numbers_is_no_outcome(): void {
		foreach ( array( null, 'tutto bene', 3, array(), array( 'errors' => '0', 'warnings' => 0 ), array( 'errors' => 0, 'warnings' => 'nessuno' ), array( 'errors' => -1, 'warnings' => 0 ), array( 'errors' => 0.0, 'warnings' => 0 ), array( 'errors' => true, 'warnings' => 0 ) ) as $raw ) {
			$this->assertNull( ReleaseChecks::clean( $raw ), (string) json_encode( $raw ) );
		}
	}

	public function test_at_most_forty_rule_entries_are_read(): void {
		$rules = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$rules[ 'nope-' . $i ] = 1;
		}
		$rules['img-alt'] = 5;
		$clean            = ReleaseChecks::clean(
			array(
				'errors'   => 5,
				'warnings' => 0,
				'rules'    => $rules,
			)
		);
		$this->assertNotNull( $clean );
		$this->assertSame( array(), $clean['rules'], 'a known rule past the first 40 entries is dropped' );
	}
}
