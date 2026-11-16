<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

/**
 * Keyboard shortcuts of the builder, per user — docs/spec/shortcuts.md. Only the user's own changes to the
 * defaults are stored (user meta): command id => key combinations, an empty list turning a default off.
 * A preference, not a permission: what a command does is still checked by the server when it runs.
 */
final class Shortcuts {

	public const META       = 'xb_shortcuts';
	public const MAX        = 300;
	public const MAX_COMBOS = 3;
	private const ID        = '/^[\p{L}\p{N}][\p{L}\p{N} :._\/-]{0,99}$/u';
	public const COMBO      = '/^(Mod\+)?(Alt\+)?(Shift\+)?([A-Z0-9]|F[1-9]|F1[0-2]|Arrow(Up|Down|Left|Right)|Delete|Backspace|Home|End|PageUp|PageDown|[,.\/;\[\]=-])$/';
	/** Kept by the browser or the system, or needed to type and move around: never a builder shortcut. */
	public const RESERVED = array( 'Mod+C', 'Mod+V', 'Mod+X', 'Mod+A', 'Mod+W', 'Mod+T', 'Mod+N', 'Mod+Q', 'Mod+R', 'Mod+L', 'Mod+F', 'Mod+P', 'Mod+Shift+T', 'Mod+Shift+N', 'Mod+Shift+W', 'Backspace' );

	/** @return array<string, list<string>> */
	public static function get( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		try {
			return self::validate( is_array( $stored ) ? $stored : array() );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}

	/**
	 * @return array<string, list<string>>
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	public static function validate( mixed $map ): array {
		if ( ! is_array( $map ) || count( $map ) > self::MAX ) {
			throw new \InvalidArgumentException( __( 'Elenco delle scorciatoie non valido.', 'xb' ) );
		}
		$out  = array();
		$seen = array();
		foreach ( $map as $id => $combos ) {
			$id = (string) $id;
			if ( 1 !== preg_match( self::ID, $id ) || ! is_array( $combos ) || count( $combos ) > self::MAX_COMBOS ) {
				throw new \InvalidArgumentException( __( 'Scorciatoia non valida.', 'xb' ) );
			}
			$list = array();
			foreach ( $combos as $combo ) {
				if ( ! is_string( $combo ) || 1 !== preg_match( self::COMBO, $combo ) || in_array( $combo, self::RESERVED, true ) ) {
					/* translators: %s: key combination */
					throw new \InvalidArgumentException( sprintf( __( 'Combinazione non ammessa: %s.', 'xb' ), is_string( $combo ) ? mb_substr( $combo, 0, 30 ) : '?' ) );
				}
				if ( isset( $seen[ $combo ] ) ) {
					/* translators: %s: key combination */
					throw new \InvalidArgumentException( sprintf( __( '%s è assegnata a due comandi.', 'xb' ), $combo ) );
				}
				$seen[ $combo ] = true;
				$list[]         = $combo;
			}
			$out[ $id ] = $list;
		}
		ksort( $out );
		return $out;
	}

	/** @param array<string, list<string>> $map One of validate(). */
	public static function save( int $user_id, array $map ): void {
		if ( array() === $map ) {
			delete_user_meta( $user_id, self::META );
			return;
		}
		update_user_meta( $user_id, self::META, $map );
	}
}
