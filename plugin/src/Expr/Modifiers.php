<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

/**
 * Registry of modifiers (`value.upper()`), the only behaviour expressions can invoke.
 * Each modifier receives the subject and positional arguments and must be side-effect free.
 * WordPress functions are used when available (localized dates and numbers) with plain-PHP fallbacks.
 */
final class Modifiers {

	/** @var array<string, \Closure(mixed, list<mixed>): mixed>|null */
	private ?array $map = null;

	/**
	 * @param array<string, \Closure(mixed, list<mixed>): mixed>|\Closure(): array<mixed> $extra Additional modifiers, or
	 *        a function returning them (WordPress filter `xb_modifiers`), called on first use: a theme's
	 *        functions.php is loaded after plugins, and its modifiers count too.
	 */
	public function __construct( private readonly array|\Closure $extra = array() ) {}

	/** @return array<string, \Closure(mixed, list<mixed>): mixed> */
	private function map(): array {
		if ( null === $this->map ) {
			$extra = $this->extra instanceof \Closure ? ( $this->extra )() : $this->extra;
			$valid = array();
			foreach ( $extra as $name => $fn ) {
				if ( is_string( $name ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $name ) && $fn instanceof \Closure ) {
					$valid[ $name ] = $fn;
				}
			}
			$this->map = array_merge( self::defaults(), $valid );
		}
		return $this->map;
	}

	/** @return list<string> */
	public function names(): array {
		return array_keys( $this->map() );
	}

	/**
	 * @param list<mixed> $args
	 * @throws EvalError When the modifier does not exist.
	 */
	public function apply( string $name, mixed $subject, array $args ): mixed {
		$map = $this->map();
		if ( ! isset( $map[ $name ] ) ) {
			throw new EvalError( sprintf( /* translators: %s: modifier name */ __( 'Modificatore sconosciuto "%s"', 'xb' ), $name ) );
		}
		return ( $map[ $name ] )( $subject, $args );
	}

	/** @return array<string, \Closure(mixed, list<mixed>): mixed> */
	private static function defaults(): array {
		$str = static fn ( mixed $v ): string => Value::to_string( $v );
		$int = static fn ( mixed $v, int $fallback ): int => is_numeric( $v ) ? (int) $v : $fallback;

		return array(
			'upper'         => static fn ( mixed $s ): string => mb_strtoupper( $str( $s ) ),
			'lower'         => static fn ( mixed $s ): string => mb_strtolower( $str( $s ) ),
			'capitalize'    => static function ( mixed $s ) use ( $str ): string {
				$text = $str( $s );
				return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
			},
			'trim'          => static fn ( mixed $s ): string => trim( $str( $s ) ),
			'inline'        => static fn ( mixed $s ): InlineHtml => InlineHtml::of( $str( $s ) ),
			'stripTags'     => static fn ( mixed $s ): string => trim( strip_tags( $str( $s ) ) ),
			'truncateWords' => static function ( mixed $s, array $a ) use ( $str, $int ): string {
				$words = preg_split( '/\s+/u', trim( strip_tags( $str( $s ) ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
				$limit = max( 0, $int( $a[0] ?? null, 55 ) );
				return count( $words ) > $limit ? implode( ' ', array_slice( $words, 0, $limit ) ) . $str( $a[1] ?? '…' ) : implode( ' ', $words );
			},
			'truncateChars' => static function ( mixed $s, array $a ) use ( $str, $int ): string {
				$text  = trim( strip_tags( $str( $s ) ) );
				$limit = max( 0, $int( $a[0] ?? null, 140 ) );
				return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit ) ) . $str( $a[1] ?? '…' ) : $text;
			},
			'date'          => static fn ( mixed $s, array $a ): string => self::format_date( $s, isset( $a[0] ) ? Value::to_string( $a[0] ) : null ),
			'number'        => static function ( mixed $s, array $a ) use ( $int ): string {
				$decimals = max( 0, $int( $a[0] ?? null, 0 ) );
				$value    = is_numeric( $s ) ? (float) $s : 0.0;
				return function_exists( 'number_format_i18n' ) ? number_format_i18n( $value, $decimals ) : number_format( $value, $decimals );
			},
			'length'        => static fn ( mixed $s ): int => is_array( $s ) ? count( $s ) : ( null === $s ? 0 : mb_strlen( $str( $s ) ) ),
			'first'         => static fn ( mixed $s ): mixed => is_array( $s ) && array() !== $s ? $s[ array_key_first( $s ) ] : null,
			'last'          => static fn ( mixed $s ): mixed => is_array( $s ) && array() !== $s ? $s[ array_key_last( $s ) ] : null,
			'join'          => static fn ( mixed $s, array $a ): string => is_array( $s ) ? implode( $str( $a[0] ?? ', ' ), array_map( $str, $s ) ) : $str( $s ),
			'slice'         => static function ( mixed $s, array $a ) use ( $str, $int ): mixed {
				$start  = $int( $a[0] ?? null, 0 );
				$length = isset( $a[1] ) ? $int( $a[1], 0 ) : null;
				return is_array( $s ) ? array_values( array_slice( $s, $start, $length ) ) : mb_substr( $str( $s ), $start, $length );
			},
			'includes'      => static fn ( mixed $s, array $a ): bool => is_array( $s )
				? in_array( $a[0] ?? null, $s, true )
				: str_contains( $str( $s ), $str( $a[0] ?? '' ) ),
			'pluck'         => static fn ( mixed $s, array $a ): array => is_array( $s )
				? array_values( array_map( static fn ( mixed $item ): mixed => Value::member( $item, $str( $a[0] ?? '' ) ), $s ) )
				: array(),
			// Doc 23 item 118: a list in groups, for a title per group. `groupBy("date", "Y")` by year, `groupBy("title",
			// "letter")` by first letter, `groupBy("fields.tipo")` by value; the groups keep the order the list has.
			'groupBy'       => static function ( mixed $s, array $a ) use ( $str ): array {
				if ( ! is_array( $s ) ) {
					return array();
				}
				$path   = array_filter( explode( '.', $str( $a[0] ?? '' ) ), static fn ( string $part ): bool => '' !== $part );
				$how    = $str( $a[1] ?? '' );
				$groups = array();
				foreach ( array_slice( array_values( $s ), 0, 2000 ) as $item ) {
					$value = $item;
					foreach ( $path as $part ) {
						$value = Value::member( $value, $part );
					}
					if ( 'letter' === $how ) {
						$first = mb_strtoupper( mb_substr( trim( $str( $value ) ), 0, 1 ) );
						$key   = 1 === preg_match( '/^\p{L}$/u', $first ) ? $first : '#';
					} elseif ( '' !== $how ) {
						$key = self::format_date( $value, $how );
					} else {
						$key = $str( $value );
					}
					$groups[ $key ] ??= array( 'key' => $key, 'items' => array() );
					$groups[ $key ]['items'][] = $item;
				}
				return array_values( $groups );
			},
			'default'       => static fn ( mixed $s, array $a ): mixed => Value::truthy( $s ) ? $s : ( $a[0] ?? null ),
			'json'          => static fn ( mixed $s ): string => (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $s ) : json_encode( $s ) ),
			'slug'          => static fn ( mixed $s ): string => function_exists( 'sanitize_title' )
				? sanitize_title( $str( $s ) )
				: trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $str( $s ) ) ), '-' ),
		);
	}


	/** A date as text: an ISO date or a timestamp, in the site's time zone; the site's format when none is given. */
	private static function format_date( mixed $s, ?string $format ): string {
		if ( is_int( $s ) ) {
			$timestamp = $s;
		} else {
			$value = Value::to_string( $s );
			if ( '' === $value ) {
				return '';
			}
			try {
				$timezone  = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( date_default_timezone_get() );
				$timestamp = ( new \DateTimeImmutable( $value, $timezone ) )->getTimestamp();
			} catch ( \Exception ) {
				return '';
			}
		}
		$format = $format ?? ( function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'Y-m-d' ) : 'Y-m-d' );
		$format = self::project_names( $format, $timestamp );
		return function_exists( 'wp_date' ) ? (string) wp_date( $format, $timestamp ) : gmdate( $format, $timestamp );
	}

	/**
	 * Doc 23 item 236: month and day names in the language of the Histeon project, as Publish writes them
	 * (static-renderer.ts, cmsDate: Intl with the project's locale), whatever language the site speaks and whether
	 * or not WordPress has that language installed. F, M, l and D become the project's names, escaped; the rest of
	 * the format stays WordPress's. Without a project language, or an ICU that knows it, the format is unchanged.
	 */
	/**
	 * Whether this PHP's ICU has the language of a locale (de_DE → de). A server with ICU's reduced data knows only
	 * a few: there the formatter falls back to English, and WordPress's own names are better.
	 */
	public static function intl_speaks( string $locale ): bool {
		if ( ! class_exists( \IntlDateFormatter::class ) ) {
			return false;
		}
		$tag = str_replace( '-', '_', $locale );
		if ( 'en' === strtolower( (string) strtok( $tag, '_' ) ) ) {
			return true;
		}
		// getLocale() says nothing for a formatter with a pattern on some builds: the names themselves tell. Seven
		// months in English and in the language: ICU without the language's data gives English for all of them.
		$month = static fn ( string $in ): string => implode( ' ', array_map( static fn ( int $m ): string => (string) ( new \IntlDateFormatter( $in, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL' ) )->format( new \DateTimeImmutable( sprintf( '2026-%02d-15T12:00:00Z', $m ) ) ), array( 1, 2, 3, 6, 7, 10, 12 ) ) );
		return $month( $tag ) !== $month( 'en_US' );
	}

	private static function project_names( string $format, int $timestamp ): string {
		$project = function_exists( 'get_option' ) ? (string) get_option( 'xb_page_profile_default_locale', '' ) : '';
		$tag     = str_replace( '-', '_', $project );
		if ( '' === $project || 1 !== preg_match( '/[FMlD]/', $format ) ) {
			return $format;
		}
		$zone  = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( date_default_timezone_get() );
		if ( ! self::intl_speaks( $tag ) ) {
			return $format;
		}
		$name  = static function ( string $pattern ) use ( $tag, $zone, $timestamp ): ?string {
			$formatter = new \IntlDateFormatter( $tag, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, \IntlDateFormatter::GREGORIAN, $pattern );
			$text      = $formatter->format( $timestamp );
			return is_string( $text ) && '' !== $text ? $text : null;
		};
		// Standalone forms, as Intl gives a month or a weekday asked alone.
		$names = array( 'F' => 'LLLL', 'M' => 'LLL', 'l' => 'cccc', 'D' => 'ccc' );
		$out   = '';
		$chars = mb_str_split( $format );
		$count = count( $chars );
		for ( $i = 0; $i < $count; $i++ ) {
			$char = $chars[ $i ];
			if ( '\\' === $char && $i + 1 < $count ) {
				$out .= $char . $chars[ ++$i ];
				continue;
			}
			$text = isset( $names[ $char ] ) ? $name( $names[ $char ] ) : null;
			$out .= null === $text ? $char : implode( '', array_map( static fn ( string $c ): string => '\\' . $c, mb_str_split( $text ) ) );
		}
		return $out;
	}
}
