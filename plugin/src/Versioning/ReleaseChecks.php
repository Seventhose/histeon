<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

/**
 * The outcome of the page checks a release went out with (docs/spec/checks.md, «L'esito con la release»): how many
 * errors and warnings the Publish window of the builder found, rule by rule. It is a reminder written by the browser
 * of whoever published, not a proof: no permission and no block depend on it. Only whole non-negative numbers and
 * the known rule names are kept, at most MAX_RULES of them; anything else is dropped without failing the release.
 * The date is the server's.
 */
final class ReleaseChecks {

	/** The last outcome, with the version and date of its release (not autoloaded). */
	public const OPTION = 'xb_checks_last';

	/** Rule entries read from a request: the rest is dropped. */
	public const MAX_RULES = 40;

	/**
	 * The rules of the Publish window, the only names kept. The same list, in the same order, is CHECK_RULES in
	 * editor/src/lib/checks.ts (ReleaseChecksTest compares them).
	 */
	public const RULES = array(
		'h1-missing',
		'h1-many',
		'heading-skip',
		'heading-empty',
		'img-src',
		'img-alt',
		'img-size',
		'img-big',
		'link-name',
		'link-vague',
		'link-href',
		'link-noopener',
		'button-name',
		'field-label',
		'iframe-title',
		'untranslated',
		'contrast',
		'title-missing',
		'description-missing',
		'lang-missing',
		'canonical-missing',
		'noindex',
		'site-hidden',
		'page-heavy',
		'link-broken',
	);

	/**
	 * The outcome as the server keeps it, or null when it is not one: `errors` and `warnings` must be whole numbers
	 * from 0; in `rules` only known names with whole numbers from 1 stay, among the first MAX_RULES entries. `pages`
	 * (M-B1: how many pages the round checked of those of the release) stays only as two whole numbers with
	 * `checked <= total`; otherwise it is left out, as when the browser sent none. Any other key (the browser's `at`
	 * too) is dropped.
	 *
	 * @return array{errors: int, warnings: int, rules: array<string, int>, pages?: array{checked: int, total: int}}|null
	 */
	public static function clean( mixed $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$errors   = $raw['errors'] ?? null;
		$warnings = $raw['warnings'] ?? null;
		if ( ! self::count_of( $errors ) || ! self::count_of( $warnings ) ) {
			return null;
		}
		$rules = array();
		if ( is_array( $raw['rules'] ?? null ) ) {
			foreach ( array_slice( $raw['rules'], 0, self::MAX_RULES, true ) as $rule => $count ) {
				if ( is_string( $rule ) && in_array( $rule, self::RULES, true ) && self::count_of( $count ) && $count > 0 ) {
					$rules[ $rule ] = $count;
				}
			}
		}
		$clean = array(
			'errors'   => $errors,
			'warnings' => $warnings,
			'rules'    => $rules,
		);
		$pages = $raw['pages'] ?? null;
		if ( is_array( $pages ) ) {
			$checked = $pages['checked'] ?? null;
			$total   = $pages['total'] ?? null;
			if ( self::count_of( $checked ) && self::count_of( $total ) && $checked <= $total ) {
				$clean['pages'] = array(
					'checked' => $checked,
					'total'   => $total,
				);
			}
		}
		return $clean;
	}

	/**
	 * Keeps the outcome of release $version as the last one, dated now (UTC, ISO 8601).
	 *
	 * @param array{errors: int, warnings: int, rules: array<string, int>, pages?: array{checked: int, total: int}} $checks
	 */
	public static function remember( string $version, array $checks ): void {
		update_option(
			self::OPTION,
			array(
				'version' => $version,
				'at'      => gmdate( 'c' ),
			) + $checks,
			false
		);
	}

	/**
	 * The last outcome kept, checked again as it is read (the option may have been written by anything), or null.
	 *
	 * @return array{version: string, at: string, errors: int, warnings: int, rules: array<string, int>, pages?: array{checked: int, total: int}}|null
	 */
	public static function last(): ?array {
		$raw    = get_option( self::OPTION, null );
		$checks = self::clean( $raw );
		if ( null === $checks || ! is_array( $raw ) ) {
			return null;
		}
		$version = $raw['version'] ?? null;
		$at      = $raw['at'] ?? null;
		if ( ! is_string( $version ) || 1 !== preg_match( '/^\d{1,4}\.\d{1,4}\.\d{1,4}$/', $version ) || ! is_string( $at ) || false === \DateTimeImmutable::createFromFormat( \DateTimeInterface::ATOM, $at ) ) {
			return null;
		}
		return array(
			'version' => $version,
			'at'      => $at,
		) + $checks;
	}

	/**
	 * The outcome each release went out with, from the «publish» entries of the audit log; releases without one
	 * (published from wp-admin, from a file, by Histeon CMS, or before the checks were done) are missing.
	 *
	 * @param list<int> $ids
	 * @return array<int, array{errors: int, warnings: int, rules: array<string, int>, pages?: array{checked: int, total: int}}>
	 */
	public static function of_releases( Store $store, array $ids ): array {
		$out = array();
		foreach ( $store->audit_of( 'publish', array_map( static fn ( int $id ): string => 'release:' . $id, $ids ) ) as $object => $details ) {
			$decoded = json_decode( $details, true );
			$checks  = is_array( $decoded ) ? self::clean( $decoded['checks'] ?? null ) : null;
			if ( null !== $checks ) {
				$out[ (int) substr( $object, strlen( 'release:' ) ) ] = $checks;
			}
		}
		return $out;
	}

	/** @phpstan-assert-if-true int<0, max> $value */
	private static function count_of( mixed $value ): bool {
		return is_int( $value ) && $value >= 0;
	}
}
