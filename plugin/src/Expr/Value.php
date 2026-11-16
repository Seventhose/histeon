<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

use XB\Data\Node;

/** Value semantics shared by evaluator, modifiers and renderer — docs/spec/dsl.md §4.3. */
final class Value {

	public static function truthy( mixed $value ): bool {
		return ! ( null === $value || false === $value || 0 === $value || 0.0 === $value || '' === $value || array() === $value );
	}

	/** `==`: strict, except that int and float compare by numeric value. */
	public static function equals( mixed $a, mixed $b ): bool {
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return (float) $a === (float) $b;
		}
		return $a === $b;
	}

	/** Ordering is defined only between two numbers or two strings; anything else compares false. */
	public static function compare( string $op, mixed $a, mixed $b ): bool {
		$numbers = ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) );
		if ( ! $numbers && ! ( is_string( $a ) && is_string( $b ) ) ) {
			return false;
		}
		$cmp = $numbers ? ( $a <=> $b ) : strcmp( (string) $a, (string) $b );
		return match ( $op ) {
			'<' => $cmp < 0,
			'<=' => $cmp <= 0,
			'>' => $cmp > 0,
			'>=' => $cmp >= 0,
			default => false,
		};
	}

	/** Null-safe member/index access on arrays and nodes. */
	public static function member( mixed $object, string|int $key ): mixed {
		if ( is_array( $object ) ) {
			return $object[ $key ] ?? null;
		}
		if ( $object instanceof Node ) {
			return $object->get( (string) $key );
		}
		return null;
	}

	/** Text output of a value (before HTML escaping). */
	public static function to_string( mixed $value ): string {
		return match ( true ) {
			null === $value => '',
			is_bool( $value ) => $value ? 'true' : 'false',
			is_int( $value ), is_string( $value ) => (string) $value,
			is_float( $value ) => rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' ),
			is_array( $value ) => implode( ', ', array_map( self::to_string( ... ), array_filter( $value, static fn ( $v ): bool => is_scalar( $v ) || $v instanceof \Stringable ) ) ),
			$value instanceof \Stringable => (string) $value,
			default => '',
		};
	}
}
