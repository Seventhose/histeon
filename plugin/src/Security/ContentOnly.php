<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

/**
 * Client mode (docs/spec/client-mode.md): what someone with `xb_edit_content` but not `xb_build` may change in a
 * document. The two versions must have the same blocks, in the same order, with the same attributes; only the
 * texts and an image's picture may differ:
 * - xb/text `content`, keeping exactly the same `{…}` expressions;
 * - on an `<img>` element: `src`, `srcset`, `sizes`, `width`, `height`, `alt`, unless the old value was an
 *   expression (a dynamic image stays dynamic).
 * - with `$props` (Capabilities::content_props(), list 135): the literal props of a component instance, each one
 *   declared by the component as string, number, boolean, url, image or enum, with a value of that type; never an
 *   expression, rich text, a list or an object, and never a prop that was dynamic.
 * Anything else — a block added, removed or moved, a tag, a class, a style, a link target, a script — is refused.
 * The server decides: the builder's content mode only avoids offering what would be refused.
 */
final class ContentOnly {

	public const IMAGE_ATTRIBUTES = array( 'src', 'srcset', 'sizes', 'width', 'height', 'alt' );

	/** Prop types a client may set with `xb_edit_props`: their values are printed escaped. */
	public const CLIENT_PROP_TYPES = array( 'string', 'number', 'boolean', 'url', 'image', 'enum' );

	private static bool $props = false;

	/** '' when `$after` only changes texts and pictures of `$before` (and literal props with `$props`); otherwise where it does more. */
	public static function check( string $before, string $after, bool $props = false ): string {
		self::$props = $props;
		try {
			return self::compare( self::blocks( $before ), self::blocks( $after ), __( 'documento', 'xb' ) );
		} finally {
			self::$props = false;
		}
	}

	/** @return list<array<string, mixed>> Blocks without the whitespace between them. */
	private static function blocks( string $content ): array {
		return array_values( array_filter( parse_blocks( $content ), static fn ( array $b ): bool => null !== $b['blockName'] || '' !== trim( (string) $b['innerHTML'] ) ) );
	}

	/**
	 * @param list<array<string, mixed>> $old
	 * @param list<array<string, mixed>> $new
	 */
	private static function compare( array $old, array $new, string $where ): string {
		if ( count( $old ) !== count( $new ) ) {
			return sprintf( /* translators: %s: where in the document */ __( '%s: elementi aggiunti o tolti', 'xb' ), $where );
		}
		foreach ( $old as $i => $a ) {
			$b    = $new[ $i ];
			$here = $where . ' › ' . ( is_string( $a['attrs']['uid'] ?? null ) ? $a['attrs']['uid'] : (string) $i );
			if ( $a['blockName'] !== $b['blockName'] ) {
				return sprintf( /* translators: %s: where in the document */ __( '%s: tipo di blocco cambiato', 'xb' ), $here );
			}
			$attrs = self::attributes( (string) $a['blockName'], (array) $a['attrs'], (array) $b['attrs'], $here );
			if ( '' !== $attrs ) {
				return $attrs;
			}
			// The markup between and around inner blocks (raw HTML, core blocks): untouched.
			if ( $a['innerHTML'] !== $b['innerHTML'] ) {
				return sprintf( /* translators: %s: where in the document */ __( '%s: codice HTML cambiato', 'xb' ), $here );
			}
			$inner = self::compare( self::inner( $a ), self::inner( $b ), $here );
			if ( '' !== $inner ) {
				return $inner;
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $block
	 * @return list<array<string, mixed>>
	 */
	private static function inner( array $block ): array {
		return array_values( array_filter( (array) $block['innerBlocks'], static fn ( array $b ): bool => null !== $b['blockName'] || '' !== trim( (string) $b['innerHTML'] ) ) );
	}

	/**
	 * @param array<string, mixed> $old
	 * @param array<string, mixed> $new
	 */
	private static function attributes( string $name, array $old, array $new, string $here ): string {
		if ( 'xb/text' === $name ) {
			$text_old = is_string( $old['content'] ?? null ) ? $old['content'] : '';
			$text_new = is_string( $new['content'] ?? null ) ? $new['content'] : '';
			if ( self::expressions( $text_old ) !== self::expressions( $text_new ) ) {
				return sprintf( /* translators: %s: where in the document */ __( '%s: espressioni {…} cambiate', 'xb' ), $here );
			}
			unset( $old['content'], $new['content'] );
		} elseif ( 'xb/element' === $name && 'img' === ( $old['tag'] ?? null ) && 'img' === ( $new['tag'] ?? null ) ) {
			$img_old = is_array( $old['attributes'] ?? null ) ? $old['attributes'] : array();
			$img_new = is_array( $new['attributes'] ?? null ) ? $new['attributes'] : array();
			foreach ( self::IMAGE_ATTRIBUTES as $attribute ) {
				$value = $img_old[ $attribute ] ?? null;
				if ( is_string( $value ) && str_contains( $value, '{' ) && ( $img_new[ $attribute ] ?? null ) !== $value ) {
					return sprintf( /* translators: %s: where in the document */ __( '%s: immagine dinamica cambiata', 'xb' ), $here );
				}
				$changed = $img_new[ $attribute ] ?? null;
				if ( is_string( $changed ) && str_contains( $changed, '{' ) && $changed !== $value ) {
					return sprintf( /* translators: %s: where in the document */ __( '%s: espressione {…} aggiunta all\'immagine', 'xb' ), $here );
				}
				unset( $img_old[ $attribute ], $img_new[ $attribute ] );
			}
			$old['attributes'] = $img_old;
			$new['attributes'] = $img_new;
		} elseif ( self::$props && 'xb/component' === $name && ( $old['ref'] ?? null ) === ( $new['ref'] ?? null ) && is_string( $old['ref'] ?? null ) ) {
			$props_old = is_array( $old['props'] ?? null ) ? $old['props'] : array();
			$props_new = is_array( $new['props'] ?? null ) ? $new['props'] : array();
			$problem   = self::props( $old['ref'], $props_old, $props_new, $here );
			if ( '' !== $problem ) {
				return $problem;
			}
			unset( $old['props'], $new['props'] );
		}
		return self::same( $old, $new ) ? '' : sprintf( /* translators: %s: where in the document */ __( '%s: attributi cambiati', 'xb' ), $here );
	}

	/**
	 * Literal props a client changed: each declared by the component with a type of CLIENT_PROP_TYPES, the old value
	 * literal too (a dynamic prop stays dynamic), the new one a value of that type.
	 *
	 * @param array<string, mixed> $old
	 * @param array<string, mixed> $new
	 */
	private static function props( string $ref, array $old, array $new, string $here ): string {
		$definition = null;
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $prop ) {
			$before = $old[ $prop ] ?? null;
			$after  = $new[ $prop ] ?? null;
			if ( self::same( $before, $after ) ) {
				continue;
			}
			$definition ??= \XB\Plugin::instance()->components->find( $ref );
			$type         = $definition['props'][ $prop ]['type'] ?? null;
			if ( ! is_string( $type ) || ! in_array( $type, self::CLIENT_PROP_TYPES, true ) || is_array( $before ) || ! self::prop_value( $type, $after, (array) ( $definition['props'][ $prop ] ?? array() ) ) ) {
				return sprintf( /* translators: 1: where in the document, 2: prop name */ __( '%1$s: la prop «%2$s» non si può cambiare così', 'xb' ), $here, (string) $prop );
			}
		}
		return '';
	}

	/** @param array<string, mixed> $definition */
	private static function prop_value( string $type, mixed $value, array $definition ): bool {
		if ( null === $value ) {
			return true; // Back to the component's default.
		}
		switch ( $type ) {
			case 'boolean':
				return is_bool( $value );
			case 'number':
				return is_int( $value ) || is_float( $value ) && is_finite( $value );
			case 'enum':
				return is_string( $value ) && in_array( $value, array_map( 'strval', (array) ( $definition['options'] ?? array() ) ), true );
		}
		if ( ! is_string( $value ) || strlen( $value ) > 2000 || 1 === preg_match( '/[{}\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ) ) {
			return false;
		}
		if ( 'url' === $type ) {
			return '' === $value || 1 === preg_match( '~^(?:https?://|mailto:|tel:|/(?!/)|#)~', strtolower( $value ) ) && '' !== esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
		}
		if ( 'image' === $type ) {
			$uploads = (string) wp_upload_dir( null, false )['baseurl'];
			return '' === $value || '' !== $uploads && str_starts_with( $value, trailingslashit( $uploads ) ) && 1 !== preg_match( '/[\s"\'<>]/', $value );
		}
		return true;
	}

	/**
	 * The `{…}` expressions of a text, in order.
	 *
	 * @return list<string>
	 */
	private static function expressions( string $text ): array {
		preg_match_all( '/\{[^{}]*\}/', $text, $m );
		return $m[0];
	}

	/** Deep equality, key order aside. */
	private static function same( mixed $a, mixed $b ): bool {
		if ( is_array( $a ) && is_array( $b ) ) {
			if ( count( $a ) !== count( $b ) || array_is_list( $a ) !== array_is_list( $b ) ) {
				return false;
			}
			foreach ( $a as $key => $value ) {
				if ( ! array_key_exists( $key, $b ) || ! self::same( $value, $b[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $a === $b;
	}
}
