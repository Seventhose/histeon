<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/** Normalizes what third-party plugins return: only well-formed CSS identifiers survive. */
final class Lists {

	private const NAME = '/^-?[_a-zA-Z][_a-zA-Z0-9-]{0,99}$/';
	private const MAX  = 5000;

	/** @return list<string> */
	public static function names( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $name ) {
			if ( is_string( $name ) ) {
				$name = ltrim( $name, '.' );
				$name = str_starts_with( $name, '--' ) ? substr( $name, 2 ) : $name;
				if ( 1 === preg_match( self::NAME, $name ) ) {
					$out[ $name ] = true;
				}
			}
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/** @return array<string, list<string>> */
	public static function grouped( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $group => $names ) {
			$list = self::names( $names );
			if ( is_string( $group ) && array() !== $list ) {
				$out[ $group ] = $list;
			}
		}
		return $out;
	}
}
