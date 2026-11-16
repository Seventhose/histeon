<?php
/**
 * The fields of a content as the builder shows them, whichever field plugin stores them — doc 25 of Histeon Publish,
 * §10 and §11 step 5. The fields the site declares (option `xb_content`, which the builder writes and keeps) say
 * each field's type; a value is read from post meta and brought back to the builder's form:
 * - a date `Ymd` (ACF, SCF) is `AAAA-MM-GG`; a date and time `AAAA-MM-GG hh:mm:ss` is ISO 8601 in the site's zone;
 * - a yes/no `0` is empty;
 * - a repeater (and a field with more values written as one) stored in rows `campo_0_sotto` (ACF, SCF) is a list of
 *   rows, as the builder and Meta Box keep it in one value;
 * - more choices, contents or images stored one meta row each (Meta Box, Pods), or as IDs separated by commas
 *   (JetEngine), are one list; a yes/no `true`/`false` (JetEngine) is yes or no.
 * A field the site does not declare is read as it is.
 *
 * @package Fondamenta
 */

declare(strict_types=1);

/**
 * The definition of a field of a content type, from the site's declaration; null when it does not declare it.
 *
 * @return array<string, mixed>|null
 */
function fondamenta_definizione_campo( string $type, string $name ): ?array {
	$declared = get_option( 'xb_content', array() );
	$by_type  = is_array( $declared ) && is_array( $declared['fields'] ?? null ) ? $declared['fields'] : array();
	foreach ( is_array( $by_type[ $type ] ?? null ) ? $by_type[ $type ] : array() as $field ) {
		if ( is_array( $field ) && ( $field['name'] ?? null ) === $name ) {
			return $field;
		}
	}
	return null;
}

/** A field of a post, as the builder shows it. */
function fondamenta_campo( WP_Post $post, string $name ): mixed {
	$raw = get_post_meta( $post->ID, $name, true );
	$def = fondamenta_definizione_campo( $post->post_type, $name );
	if ( null !== $def && ! empty( $def['multiple'] ) && in_array( $def['type'] ?? '', array( 'image', 'post' ), true ) && is_string( $raw ) && str_contains( $raw, ',' ) ) {
		// More images or contents as IDs separated by commas (a gallery of JetEngine).
		$raw = array_map( 'trim', explode( ',', $raw ) );
	}
	if ( null !== $def && ! empty( $def['multiple'] ) && 'repeater' !== ( $def['type'] ?? '' ) && ! is_array( $raw ) && '' !== $raw && ! metadata_exists( 'post', $post->ID, $name . '_0_valore' ) ) {
		// More values one meta row each (Meta Box, Pods); rows of ACF have `campo_0_valore`.
		$raw = get_post_meta( $post->ID, $name, false );
	}
	return null === $def ? $raw : fondamenta_campo_normalizza( $raw, $def, $post->ID, $name );
}

/**
 * A stored value read as the builder reads it (its XbFields and Values): numbers as numbers, yes/no as yes or no,
 * dates `AAAA-MM-GG` (also from `Ymd`) or `AAAA-MM-GGThh:mm:ss`, addresses http(s) only, rich text without its
 * markup, empty as nothing; the rows of a repeater each read the same way, also from rows stored one meta per value.
 *
 * @param array<string, mixed> $def
 */
function fondamenta_campo_normalizza( mixed $raw, array $def, int $id, string $key ): mixed {
	$type = (string) ( $def['type'] ?? 'text' );
	if ( 'repeater' === $type || ! empty( $def['multiple'] ) ) {
		if ( is_array( $raw ) ) {
			$items = array_slice( array_values( $raw ), 0, 1000 );
		} elseif ( is_numeric( $raw ) && ( 'repeater' === $type || ! in_array( $type, array( 'image', 'post', 'select' ), true ) ) ) {
			// Rows stored one meta per value (ACF, SCF); more plain values are rows of one field, `valore`.
			$items = fondamenta_righe_acf( (int) $raw, $def, $id, $key );
		} else {
			return array();
		}
		if ( 'repeater' !== $type ) {
			// More plain values kept as rows of one field, `valore` (ACF, JetEngine).
			$items = array_map( static fn ( mixed $row ): mixed => is_array( $row ) && array_key_exists( 'valore', $row ) ? $row['valore'] : $row, $items );
		}
		if ( 'repeater' === $type ) {
			$subs = is_array( $def['sub'] ?? null ) ? $def['sub'] : array();
			return array_map(
				static function ( mixed $row ) use ( $subs, $id, $key ): array {
					$out = array();
					foreach ( $subs as $sub ) {
						if ( is_array( $sub ) && is_string( $sub['name'] ?? null ) ) {
							$out[ $sub['name'] ] = fondamenta_campo_normalizza( is_array( $row ) ? ( $row[ $sub['name'] ] ?? null ) : null, $sub, $id, $key );
						}
					}
					return $out;
				},
				array_slice( $items, 0, 200 )
			);
		}
		return in_array( $type, array( 'image', 'post' ), true ) ? $items : array_map( static fn ( mixed $item ): mixed => fondamenta_campo_valore( $type, $item ), $items );
	}
	return fondamenta_campo_valore( $type, $raw );
}

/** One value as the builder reads it, by type. */
function fondamenta_campo_valore( string $type, mixed $raw ): mixed {
	$text = is_array( $raw ) || is_object( $raw ) || null === $raw || '' === $raw || false === $raw ? null : (string) $raw;
	switch ( $type ) {
		case 'number':
		case 'integer':
		case 'decimal':
			return is_numeric( $raw ) ? $raw + 0 : null;
		case 'toggle':
			return in_array( $raw, array( true, 1, '1', 'true', 'on', 'yes' ), true );
		case 'date':
		case 'datetime':
			if ( null === $text ) {
				return null;
			}
			if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $text, $m ) ) {
				return $m[1] . '-' . $m[2] . '-' . $m[3];
			}
			if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(:\d{2})?)$/', $text, $m ) ) {
				return $m[1] . 'T' . ( 5 === strlen( $m[2] ) ? $m[2] . ':00' : $m[2] );
			}
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $text ) ) {
				$date = date_create_immutable( $text );
				return false === $date ? null : $date->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:s' );
			}
			return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) || 1 === preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $text ) ? $text : null;
		case 'url':
			$url = null === $text ? '' : esc_url_raw( $text, array( 'http', 'https' ) );
			return '' === $url ? null : $url;
		case 'rich-text':
			return null === $text ? null : trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />' ), array( "</p>\n", "\n", "\n" ), $text ) ) );
		case 'image':
		case 'post':
			return is_numeric( $raw ) ? (int) $raw : ( is_array( $raw ) ? ( $raw['id'] ?? $raw['ID'] ?? null ) : null );
		default:
			return $text;
	}
}

/**
 * The rows of a repeater stored one meta per value (ACF, SCF): `campo` holds how many, `campo_0_sotto` each value;
 * a repeater in a row holds its own rows the same way (`campo_0_sotto_1_altro`).
 *
 * @param array<string, mixed> $def
 * @return list<mixed>
 */
function fondamenta_righe_acf( int $count, array $def, int $id, string $key ): array {
	$rows = array();
	$subs = is_array( $def['sub'] ?? null ) && array() !== $def['sub'] ? $def['sub'] : array( array( 'name' => 'valore', 'type' => (string) ( $def['type'] ?? 'text' ) ) );
	for ( $i = 0; $i < min( $count, 1000 ); $i++ ) {
		$row = array();
		foreach ( $subs as $sub ) {
			if ( ! is_array( $sub ) || ! is_string( $sub['name'] ?? null ) ) {
				continue;
			}
			$at                  = $key . '_' . $i . '_' . $sub['name'];
			$row[ $sub['name'] ] = fondamenta_campo_normalizza( get_post_meta( $id, $at, true ), $sub, $id, $at );
		}
		$rows[] = $row;
	}
	return $rows;
}

/**
 * The rows a loop repeats: of a repeater of the post shown (or of the page), or of a repeater inside the row of the
 * loop around it.
 *
 * @param array<string, mixed> $attributes
 * @return list<array<string, mixed>>
 */
function fondamenta_righe( array $attributes, WP_Block $block ): array {
	$campo = (string) ( $attributes['campo'] ?? '' );
	if ( ! empty( $attributes['dentro'] ) ) {
		$rows = $block->context['fondamenta/voce']['riga'][ $campo ] ?? array();
	} else {
		$id   = ! empty( $attributes['pagina'] ) ? (int) get_queried_object_id() : ( isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID() );
		$post = $id > 0 ? get_post( $id ) : null;
		$rows = $post instanceof WP_Post && ! post_password_required( $post ) ? fondamenta_campo( $post, $campo ) : array();
	}
	$rows = is_array( $rows ) ? array_slice( array_values( array_filter( $rows, 'is_array' ) ), 0, 1000 ) : array();
	return array_map( static fn ( array $row ): array => array( 'fondamenta/voce' => array( 'riga' => $row ) ), $rows );
}
