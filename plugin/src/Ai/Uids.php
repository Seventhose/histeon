<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

/** Fresh node identities (docs/spec/blocks.md §2: 10 lowercase base36 characters). */
final class Uids {

	public static function fresh(): string {
		$alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
		$out      = '';
		foreach ( str_split( random_bytes( 10 ) ) as $byte ) {
			$out .= $alphabet[ ord( $byte ) % 36 ];
		}
		return $out;
	}
}
