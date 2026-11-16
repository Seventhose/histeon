<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

use XB\Data\ImageNode;
use XB\Data\PostNode;
use XB\Data\TermNode;
use XB\Data\UserNode;

/**
 * Raw field values (as the plugins store them) turned into XB data. Every provider goes through here, so a
 * related post is read only if the visitor could read it (PostNode::for_post), a term only from a public
 * taxonomy, a user only as a public profile, and a link only with a web address. Nothing here runs the plugins'
 * own formatting (shortcodes, oEmbed requests, filters): the values are data, escaped on output like any other.
 */
final class Values {

	public const MAX_ROWS  = 200;
	public const MAX_DEPTH = 3;

	public static function text( mixed $value ): ?string {
		if ( is_array( $value ) || is_object( $value ) || null === $value || '' === $value || false === $value ) {
			return null;
		}
		return (string) $value;
	}

	/** Rich text without its markup: expressions print text, never HTML. */
	public static function plain( mixed $value ): ?string {
		$text = self::text( $value );
		return null === $text ? null : trim( wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />' ), array( "</p>\n", "\n", "\n" ), $text ) ) );
	}

	public static function number( mixed $value ): int|float|null {
		return is_numeric( $value ) ? $value + 0 : null;
	}

	public static function boolean( mixed $value ): bool {
		return in_array( $value, array( true, 1, '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * An ISO 8601 date and time with its zone, exactly as written (`2026-11-15T09:30:00+01:00`), or null for any other
	 * text or an impossible date. Histeon's own date and time fields keep it so: Histeon Publish saves and shows them
	 * with their zone (docs/spec/templates.md §5), and `.date()` turns them into the site's time when a format is asked.
	 */
	public static function zoned( mixed $value ): ?string {
		$text = self::text( $value );
		if ( null === $text || 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $text, $m ) ) {
			return null;
		}
		try {
			$date = new \DateTimeImmutable( $text );
		} catch ( \Exception ) {
			return null;
		}
		// No overflow: 2026-02-30 is not 2 March.
		return $date->format( 'Y-m-d\TH:i:s' ) === $m[1] ? $text : null;
	}

	/** A date as `YYYY-MM-DD` (or with the time), from the formats plugins store. */
	public static function date( mixed $value, bool $timestamp = false ): ?string {
		$text = self::text( $value );
		if ( null === $text ) {
			return null;
		}
		if ( $timestamp && ctype_digit( $text ) ) {
			return wp_date( 'Y-m-d\TH:i:s', (int) $text ) ?: null;
		}
		if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $text, $m ) ) {
			return "{$m[1]}-{$m[2]}-{$m[3]}";
		}
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(:\d{2})?)$/', $text, $m ) ) {
			return $m[1] . 'T' . ( 5 === strlen( $m[2] ) ? $m[2] . ':00' : $m[2] );
		}
		// ISO 8601 with its zone (as Histeon's own date and time fields are saved): the site's local time.
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $text ) ) {
			try {
				return ( new \DateTimeImmutable( $text ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:s' );
			} catch ( \Exception ) {
				return null;
			}
		}
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) || 1 === preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $text ) ? $text : null;
	}

	/** An image by attachment ID (or by its address, if it is one of the media library). */
	public static function image( mixed $value ): ?ImageNode {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? $value['ID'] ?? $value['url'] ?? null;
		}
		if ( is_string( $value ) && ! ctype_digit( $value ) ) {
			$value = attachment_url_to_postid( $value );
		}
		$id = is_numeric( $value ) ? (int) $value : 0;
		return $id > 0 ? ImageNode::for_attachment( $id ) : null;
	}

	/**
	 * A file of the media library: address, title, name and type.
	 *
	 * @return array{id: int, url: string, title: string, filename: string, mime: string}|null
	 */
	public static function file( mixed $value ): ?array {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? $value['ID'] ?? null;
		}
		$id   = is_numeric( $value ) ? (int) $value : 0;
		$post = $id > 0 ? get_post( $id ) : null;
		$url  = $post instanceof \WP_Post && 'attachment' === $post->post_type ? wp_get_attachment_url( $id ) : false;
		if ( ! $post instanceof \WP_Post || ! is_string( $url ) ) {
			return null;
		}
		return array(
			'id'       => $id,
			'url'      => $url,
			'title'    => get_the_title( $post ),
			'filename' => wp_basename( $url ),
			'mime'     => (string) $post->post_mime_type,
		);
	}

	/** @return list<ImageNode> */
	public static function images( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : explode( ',', $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_ROWS ) : array() as $item ) {
			$image = self::image( is_string( $item ) ? trim( $item ) : $item );
			if ( null !== $image ) {
				$out[] = $image;
			}
		}
		return $out;
	}

	/** One or more related posts, each only if the visitor could read it. */
	public static function post( mixed $value ): ?PostNode {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		$id = is_numeric( $value ) ? (int) $value : ( $value instanceof \WP_Post ? $value->ID : 0 );
		return $id > 0 ? PostNode::for_post( $id ) : null;
	}

	/** @return list<PostNode> */
	public static function posts( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : explode( ',', $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_ROWS ) : array( $value ) as $item ) {
			$post = self::post( is_string( $item ) ? trim( $item ) : $item );
			if ( null !== $post ) {
				$out[] = $post;
			}
		}
		return $out;
	}

	/** @return list<TermNode> */
	public static function terms( mixed $value, string $taxonomy = '' ): array {
		$out = array();
		foreach ( is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_ROWS ) : array( $value ) as $item ) {
			$term = is_numeric( $item ) ? get_term( (int) $item, $taxonomy ) : null;
			if ( $term instanceof \WP_Term && ( get_taxonomy( $term->taxonomy )->public ?? false ) ) {
				$out[] = new TermNode( $term );
			}
		}
		return $out;
	}

	public static function user( mixed $value ): ?UserNode {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_numeric( $value ) && (int) $value > 0 ? UserNode::author_profile( (int) $value ) : null;
	}

	/**
	 * A link: an address of the web (or mail, telephone), its text and whether it opens a new tab.
	 *
	 * @return array{url: string, title: string, target: string}|null
	 */
	public static function link( mixed $value ): ?array {
		if ( is_string( $value ) ) {
			$value = array( 'url' => $value );
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$url = esc_url_raw( (string) ( $value['url'] ?? '' ), array( 'http', 'https', 'mailto', 'tel' ) );
		if ( '' === $url ) {
			return null;
		}
		return array(
			'url'    => $url,
			'title'  => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
			'target' => '_blank' === ( $value['target'] ?? '' ) ? '_blank' : '',
		);
	}

	/** A web address only (oEmbed, URL fields): anything else is empty. */
	public static function url( mixed $value ): ?string {
		$text = self::text( $value );
		$url  = null === $text ? '' : esc_url_raw( $text, array( 'http', 'https' ) );
		return '' === $url ? null : $url;
	}

	/**
	 * One choice or several: values as strings.
	 *
	 * @return string|list<string>|null
	 */
	public static function choice( mixed $value ): string|array|null {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, self::MAX_ROWS ) as $key => $item ) {
				if ( is_scalar( $item ) ) {
					// JetEngine checkboxes: [ value => 'true' | 'false' ].
					if ( is_string( $key ) && in_array( $item, array( 'true', 'false' ), true ) ) {
						if ( 'true' === $item ) {
							$out[] = $key;
						}
						continue;
					}
					$out[] = (string) $item;
				}
			}
			return $out;
		}
		return self::text( $value );
	}

	/**
	 * A map: coordinates and address only.
	 *
	 * @return array{lat: float|null, lng: float|null, address: string}|null
	 */
	public static function map( mixed $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		return array(
			'lat'     => is_numeric( $value['lat'] ?? null ) ? (float) $value['lat'] : null,
			'lng'     => is_numeric( $value['lng'] ?? null ) ? (float) $value['lng'] : null,
			'address' => sanitize_text_field( (string) ( $value['address'] ?? '' ) ),
		);
	}
}
