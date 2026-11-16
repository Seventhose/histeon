<?php
/**
 * Fondamenta's own blocks (docs/spec/fondamenta.md §5): they keep the markup of a page as it was built, so its CSS
 * still applies, and work with or without the builder.
 *
 * - fondamenta/elemento: any element, static. Its saved HTML is what the page shows. Values read from the content
 *   being shown (a title, a link, a field) are «legami»: a list of parts, written text or a named value with a few
 *   formats, filled in at render time. No expression language: only the names of fondamenta_valore().
 * - fondamenta/loop: repeats its blocks for each item of a query, of the main query, of the terms of the content
 *   shown, of the values of a field with more than one, or of the page links of an archive; fondamenta/altrimenti,
 *   its last block, is shown when there are none.
 * - fondamenta/se: shows its blocks when a named value of the content shown is (or is not) empty.
 * - fondamenta/contenuto: the content of the post shown, with no wrapper.
 *
 * @package Fondamenta
 */

declare(strict_types=1);

add_action(
	'init',
	static function (): void {
		$dir = get_template_directory();
		wp_register_script(
			'fondamenta-editor',
			get_template_directory_uri() . '/assets/editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
			(string) wp_get_theme( 'fondamenta' )->get( 'Version' ),
			true
		);
		wp_set_script_translations( 'fondamenta-editor', 'fondamenta', $dir . '/languages' );
		wp_register_style( 'fondamenta-editor', get_template_directory_uri() . '/assets/editor.css', array(), (string) wp_get_theme( 'fondamenta' )->get( 'Version' ) );
		register_block_type( $dir . '/blocks/elemento', array( 'render_callback' => 'fondamenta_render_elemento' ) );
		register_block_type( $dir . '/blocks/loop', array( 'render_callback' => 'fondamenta_render_loop' ) );
		register_block_type( $dir . '/blocks/altrimenti', array( 'render_callback' => '__return_empty_string' ) );
		register_block_type( $dir . '/blocks/se', array( 'render_callback' => 'fondamenta_render_se' ) );
		register_block_type( $dir . '/blocks/contenuto', array( 'render_callback' => 'fondamenta_render_contenuto' ) );
		register_block_type( $dir . '/blocks/testo', array( 'render_callback' => 'fondamenta_render_testo' ) );
	}
);

/** What a synced pattern may override in an element: its text and these attributes (WP 6.9). */
const FONDAMENTA_OVERRIDE = array( 'contenuto', 'href', 'src', 'alt', 'title' );

add_filter(
	'block_bindings_supported_attributes',
	static function ( mixed $supported, mixed $block_type ): mixed {
		// WordPress may ask with no block type: then the answer stays as it is.
		return 'fondamenta/elemento' === $block_type && is_array( $supported ) ? array_values( array_unique( array_merge( $supported, FONDAMENTA_OVERRIDE ) ) ) : $supported;
	},
	10,
	2
);

/** Elements without content (HTML void elements). */
const FONDAMENTA_VUOTI = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
/** Attributes whose value is an address. */
const FONDAMENTA_URL = array( 'href', 'src', 'action', 'formaction', 'poster', 'cite', 'data', 'background', 'xlink:href' );
/** Most items a loop shows, as the builder. */
const FONDAMENTA_MASSIMO = 100;

/**
 * An element with legami: the saved HTML with the bound attributes and text filled in for the content shown.
 *
 * @param array<string, mixed> $attributes
 */
function fondamenta_render_elemento( array $attributes, string $content, WP_Block $block ): string {
	if ( ! empty( $attributes['metadata']['bindings'] ) ) {
		$content = fondamenta_override( $attributes, $content );
	}
	$legami = is_array( $attributes['legami'] ?? null ) ? $attributes['legami'] : array();
	if ( array() === $legami ) {
		return $content;
	}
	$end = strpos( $content, '>' );
	if ( false === $end ) {
		return $content;
	}
	$open  = substr( $content, 0, $end + 1 );
	$rest  = substr( $content, $end + 1 );
	$tag   = new WP_HTML_Tag_Processor( $open );
	$image = null;
	if ( ! $tag->next_tag() ) {
		return $content;
	}
	$name = strtolower( (string) $tag->get_tag() );
	foreach ( $legami as $attribute => $parts ) {
		if ( '#testo' === $attribute || ! is_string( $attribute ) || ! is_array( $parts ) || 1 !== preg_match( '/^[a-zA-Z_:@][a-zA-Z0-9_:.@-]*$/', $attribute ) || str_starts_with( strtolower( $attribute ), 'on' ) ) {
			continue;
		}
		// A yes/no as the whole value (`checked={o.selected}`): the attribute is there, bare, or not at all.
		$whole = 1 === count( $parts ) && is_array( $parts[0] ) && is_string( $parts[0]['chiave'] ?? null ) && empty( $parts[0]['modi'] ) ? fondamenta_dato( $parts[0]['chiave'], $block ) : null;
		if ( is_bool( $whole ) ) {
			if ( $whole ) {
				$tag->set_attribute( $attribute, true );
			} else {
				$tag->remove_attribute( $attribute );
			}
			continue;
		}
		$value = fondamenta_testo( $parts, $block, false );
		if ( null === $value ) {
			$tag->remove_attribute( $attribute );
			continue;
		}
		// The builder's esc_attr() leaves entities already in a value as they are: the browser reads them once.
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$lower = strtolower( $attribute );
		if ( in_array( $lower, FONDAMENTA_URL, true ) ) {
			$value = esc_url( $value );
		} elseif ( 'style' === $lower ) {
			$value = safecss_filter_attr( $value );
		}
		$tag->set_attribute( $attribute, $value );
		if ( 'img' === $name && 'src' === $lower ) {
			$image = fondamenta_immagine_di( $parts, $block );
		}
	}
	if ( null !== $image ) {
		fondamenta_completa_immagine( $tag, $image );
	}
	$open = $tag->get_updated_html();
	if ( ! isset( $legami['#testo'] ) || ! is_array( $legami['#testo'] ) ) {
		return $open . $rest;
	}
	$close = in_array( $name, FONDAMENTA_VUOTI, true ) ? '' : '</' . $name . '>';
	return $open . (string) fondamenta_testo( $legami['#testo'], $block, true ) . $close;
}

/**
 * Text with values that change and no element around it (` {o.label} ` between a box and its count): the text of
 * its legami, escaped.
 *
 * @param array<string, mixed> $attributes
 */
function fondamenta_render_testo( array $attributes, string $content, WP_Block $block ): string {
	unset( $content );
	$parts = $attributes['legami']['#testo'] ?? null;
	return is_array( $parts ) ? (string) fondamenta_testo( $parts, $block, true ) : '';
}

/**
 * An element of a synced pattern, with the values its instance overrides: the attributes on its tag, the text
 * inside it (filtered as WordPress filters overridden rich text).
 *
 * @param array<string, mixed> $attributes
 */
function fondamenta_override( array $attributes, string $content ): string {
	$end = strpos( $content, '>' );
	if ( false === $end ) {
		return $content;
	}
	$tag = new WP_HTML_Tag_Processor( substr( $content, 0, $end + 1 ) );
	if ( ! $tag->next_tag() ) {
		return $content;
	}
	foreach ( array( 'href', 'src', 'alt', 'title' ) as $name ) {
		if ( isset( $attributes[ $name ] ) && is_string( $attributes[ $name ] ) ) {
			$tag->set_attribute( $name, in_array( $name, array( 'href', 'src' ), true ) ? esc_url( $attributes[ $name ] ) : $attributes[ $name ] );
		}
	}
	$name = strtolower( (string) $tag->get_tag() );
	$open = $tag->get_updated_html();
	if ( 'testo' !== ( $attributes['modo'] ?? '' ) || ! isset( $attributes['contenuto'] ) || ! is_scalar( $attributes['contenuto'] ) ) {
		return $open . substr( $content, $end + 1 );
	}
	return $open . wp_kses_post( (string) $attributes['contenuto'] ) . '</' . $name . '>';
}

/**
 * Parts joined: written parts as they are (HTML in a text, plain text in an attribute), named values escaped for a
 * text. Null when the only part is a value that is empty, so an attribute bound to it is left out, as the builder
 * leaves out `src={x}` when x is empty.
 *
 * @param array<mixed> $parts
 */
function fondamenta_testo( array $parts, WP_Block $block, bool $html ): ?string {
	$out    = '';
	$values = 0;
	$empty  = 0;
	foreach ( $parts as $part ) {
		if ( is_string( $part ) ) {
			$out .= $part;
			continue;
		}
		if ( ! is_array( $part ) || ! is_string( $part['chiave'] ?? null ) ) {
			continue;
		}
		++$values;
		$value = fondamenta_valore( $part['chiave'], is_array( $part['modi'] ?? null ) ? $part['modi'] : array(), $block );
		if ( null === $value || '' === $value ) {
			++$empty;
			continue;
		}
		$out .= $html ? esc_html( $value ) : $value;
	}
	return 1 === count( $parts ) && 1 === $values && 1 === $empty ? null : $out;
}

/**
 * A named value of what is shown: the post of the loop or page, a term or a page link of a loop, a value of a
 * filtered list. The names are those of the builder (`title`, `date`, `fields.prezzo`, `term.name`,
 * `page.totalPages`, `facet.eventi.total`…); the formats are `date:<formato>`, `words:<n>`,
 * `slice:<inizio>[:<lunghezza>]`, `upper`, `lower`, `join:<separatore>`, and `se:<testo>` (the text when the value
 * is not empty, as `x ? "testo" : null` in the builder).
 *
 * @param array<mixed> $modi
 */
function fondamenta_valore( string $chiave, array $modi, WP_Block $block ): ?string {
	$value = fondamenta_dato( $chiave, $block );
	foreach ( $modi as $modo ) {
		if ( is_string( $modo ) && str_starts_with( $modo, 'se:' ) ) {
			$value = fondamenta_pieno( $value ) ? substr( $modo, 3 ) : null;
		}
	}
	if ( is_array( $value ) ) {
		$join = ', ';
		foreach ( $modi as $modo ) {
			if ( is_string( $modo ) && str_starts_with( $modo, 'join:' ) ) {
				$join = substr( $modo, 5 );
			}
		}
		$value = implode( $join, array_map( 'fondamenta_come_testo', array_filter( $value, 'is_scalar' ) ) );
	} elseif ( is_scalar( $value ) ) {
		$value = fondamenta_come_testo( $value );
	}
	if ( ! is_string( $value ) ) {
		return null;
	}
	foreach ( $modi as $modo ) {
		if ( ! is_string( $modo ) ) {
			continue;
		}
		if ( str_starts_with( $modo, 'date:' ) ) {
			$value = fondamenta_data( $value, substr( $modo, 5 ) );
		} elseif ( str_starts_with( $modo, 'words:' ) ) {
			$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( $value ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
			$limit = max( 0, (int) substr( $modo, 6 ) );
			$value = count( $words ) > $limit ? implode( ' ', array_slice( $words, 0, $limit ) ) . '…' : implode( ' ', $words );
		} elseif ( str_starts_with( $modo, 'slice:' ) ) {
			$cut   = explode( ':', substr( $modo, 6 ) );
			$value = mb_substr( $value, (int) $cut[0], isset( $cut[1] ) && '' !== $cut[1] ? (int) $cut[1] : null );
		} elseif ( 'upper' === $modo ) {
			$value = mb_strtoupper( $value );
		} elseif ( 'lower' === $modo ) {
			$value = mb_strtolower( $value );
		}
	}
	return $value;
}

/** A value as the builder prints it: yes/no as `true`/`false`, numbers without trailing zeros. */
function fondamenta_come_testo( mixed $value ): string {
	return match ( true ) {
		is_bool( $value ) => $value ? 'true' : 'false',
		is_float( $value ) => rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' ),
		is_scalar( $value ) => (string) $value,
		default => '',
	};
}

/**
 * A named value as it is: text, a list, a number or a yes/no (fondamenta_valore() names them).
 */
function fondamenta_dato( string $chiave, WP_Block $block ): mixed {
	$item  = is_array( $block->context['fondamenta/voce'] ?? null ) ? $block->context['fondamenta/voce'] : array();
	$value = null;
	if ( str_starts_with( $chiave, 'term.' ) ) {
		$term = isset( $item['termine'] ) ? get_term( (int) $item['termine'] ) : null;
		if ( $term instanceof WP_Term ) {
			$value = match ( substr( $chiave, 5 ) ) {
				'name' => $term->name,
				'slug' => $term->slug,
				'url' => get_term_link( $term ),
				'count' => (string) $term->count,
				'description' => $term->description,
				'id' => (string) $term->term_id,
				default => null,
			};
			$value = is_string( $value ) ? $value : null;
		}
	} elseif ( 'page.title' === $chiave ) {
		$value = fondamenta_titolo_pagina();
	} elseif ( str_starts_with( $chiave, 'page.' ) ) {
		$what  = substr( $chiave, 5 );
		$value = in_array( $what, array( 'page', 'totalPages', 'totalRecords', 'prevUrl', 'nextUrl' ), true ) ? fondamenta_valore_archivio( $what ) : fondamenta_valore_pagina( $what );
	} elseif ( 'url.path' === $chiave ) {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$path  = wp_parse_url( $uri, PHP_URL_PATH );
		$value = is_string( $path ) ? $path : '/';
	} elseif ( 'item' === $chiave ) {
		$value = isset( $item['valore'] ) && is_scalar( $item['valore'] ) ? $item['valore'] : null;
	} elseif ( str_starts_with( $chiave, 'link.' ) ) {
		$field = substr( $chiave, 5 );
		$value = isset( $item['pagina'][ $field ] ) && is_scalar( $item['pagina'][ $field ] ) ? $item['pagina'][ $field ] : null;
	} elseif ( str_starts_with( $chiave, 'riga.' ) ) {
		// A value of the row of a repeater; an image of the row by its parts (`riga.foto.url`).
		$path  = explode( '.', substr( $chiave, 5 ) );
		$value = $item['riga'][ $path[0] ] ?? null;
		if ( isset( $path[1] ) ) {
			$value = fondamenta_valore_immagine( is_numeric( $value ) ? (int) $value : 0, $path[1] );
		} elseif ( ! is_scalar( $value ) ) {
			$value = null;
		}
	} elseif ( str_starts_with( $chiave, 'voce.' ) ) {
		// An entry of a filtered list: an option, an active filter, a page link, a hidden field.
		$field = substr( $chiave, 5 );
		$value = isset( $item['dato'][ $field ] ) && ( is_scalar( $item['dato'][ $field ] ) || is_array( $item['dato'][ $field ] ) ) ? $item['dato'][ $field ] : null;
	} elseif ( str_starts_with( $chiave, 'facet.' ) ) {
		$path  = explode( '.', substr( $chiave, 6 ) );
		$value = fondamenta_filtro_valore( (string) array_shift( $path ), $path );
	} else {
		$id    = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
		$post  = $id > 0 ? get_post( $id ) : null;
		$value = $post instanceof WP_Post ? fondamenta_valore_post( $post, $chiave ) : null;
	}
	return $value;
}

/** Whether a value counts as there, as the builder's conditions read it. */
function fondamenta_pieno( mixed $value ): bool {
	if ( is_array( $value ) ) {
		return array() !== $value;
	}
	if ( is_bool( $value ) ) {
		return $value;
	}
	return null !== $value && '' !== (string) $value && '0' !== (string) $value && 'false' !== (string) $value;
}

/**
 * A value of a post, by the builder's name. Fields are read from post meta, as every field plugin stores simple
 * values; an image field holds an attachment ID.
 *
 * @return mixed Text, a number, a yes/no, a list, or null.
 */
function fondamenta_valore_post( WP_Post $post, string $chiave ): mixed {
	$guarded = in_array( strtok( $chiave, '.' ), array( 'fields', 'excerpt' ), true ) && post_password_required( $post );
	if ( $guarded ) {
		return '';
	}
	$parts = explode( '.', $chiave );
	switch ( $parts[0] ) {
		case 'id':
			return (string) $post->ID;
		case 'title':
			return get_the_title( $post );
		case 'url':
			return get_permalink( $post ) ?: null;
		case 'slug':
			return $post->post_name;
		case 'type':
			return $post->post_type;
		case 'excerpt':
			return '' !== $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( excerpt_remove_blocks( strip_shortcodes( $post->post_content ) ) ), 55 );
		case 'date':
			return (string) get_post_time( DATE_W3C, false, $post );
		case 'modified':
			return (string) get_post_modified_time( DATE_W3C, false, $post );
		case 'author':
			$user = get_userdata( (int) $post->post_author );
			return $user instanceof WP_User ? ( 'url' === ( $parts[1] ?? '' ) ? get_author_posts_url( $user->ID ) : $user->display_name ) : null;
		case 'image':
			return fondamenta_valore_immagine( (int) get_post_thumbnail_id( $post ), $parts[1] ?? 'url' );
		case 'categories':
			return fondamenta_nomi( $post, 'category' );
		case 'tags':
			return fondamenta_nomi( $post, 'post_tag' );
		case 'terms':
			return isset( $parts[1] ) ? fondamenta_nomi( $post, $parts[1] ) : null;
		case 'fields':
			$raw = isset( $parts[1] ) ? fondamenta_campo( $post, $parts[1] ) : null;
			if ( isset( $parts[2] ) ) {
				return fondamenta_valore_immagine( is_numeric( $raw ) ? (int) $raw : 0, $parts[2] );
			}
			return is_scalar( $raw ) ? $raw : ( is_array( $raw ) ? array_values( array_filter( $raw, 'is_scalar' ) ) : null );
	}
	return null;
}

/**
 * The title of the page shown, as the builder names it: the post or page, the posts page (or «Blog»), the term or
 * the author of an archive.
 */
function fondamenta_titolo_pagina(): ?string {
	if ( is_home() ) {
		$page = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_for_posts' ) ) : null;
		return $page instanceof WP_Post ? get_the_title( $page ) : 'Blog';
	}
	$queried = get_queried_object();
	return match ( true ) {
		$queried instanceof WP_Post_Type => (string) ( $queried->labels->name ?? $queried->label ),
		$queried instanceof WP_Term => $queried->name,
		$queried instanceof WP_User => $queried->display_name,
		$queried instanceof WP_Post => get_the_title( $queried ),
		default => null,
	};
}

/**
 * A value of the archive shown, by the builder's name: its page, number of pages and of contents, and the
 * addresses of the previous and next page.
 */
function fondamenta_valore_archivio( string $what ): ?string {
	global $wp_query;
	if ( ! $wp_query instanceof WP_Query || $wp_query->is_singular() || $wp_query->is_404() ) {
		return null;
	}
	$page  = max( 1, (int) $wp_query->get( 'paged' ) );
	$pages = max( 1, (int) $wp_query->max_num_pages );
	return match ( $what ) {
		'page' => (string) $page,
		'totalPages' => (string) $pages,
		'totalRecords' => (string) max( 0, (int) $wp_query->found_posts ),
		'prevUrl' => $page > 1 ? fondamenta_pagina_n( $page - 1 ) : '',
		'nextUrl' => $page < $pages ? fondamenta_pagina_n( $page + 1 ) : '',
		default => null,
	};
}

/**
 * A value of the page shown (not of the item of a loop): a field, slug or address of the post or page, or of the
 * term of an archive.
 *
 * @return string|list<string>|null
 */
function fondamenta_valore_pagina( string $what ): string|array|null {
	if ( is_home() ) {
		// The list of posts: the posts page, or «Blog» at the home address when there is none (as the builder).
		$page = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_for_posts' ) ) : null;
		if ( $page instanceof WP_Post ) {
			return fondamenta_valore_post( $page, $what );
		}
		return match ( $what ) {
			'url' => home_url( '/' ),
			'slug' => 'blog',
			'type' => 'post',
			default => null,
		};
	}
	if ( is_author() ) {
		$author = get_queried_object();
		return $author instanceof WP_User ? match ( $what ) {
			'url' => get_author_posts_url( $author->ID ),
			'slug' => $author->user_nicename,
			'id' => (string) $author->ID,
			default => null,
		} : null;
	}
	$queried = get_queried_object();
	if ( $queried instanceof WP_Post ) {
		return fondamenta_valore_post( $queried, $what );
	}
	if ( $queried instanceof WP_Post_Type ) {
		$link = get_post_type_archive_link( $queried->name );
		return match ( $what ) {
			'slug', 'type' => $queried->name,
			'url' => is_string( $link ) ? $link : home_url( '/' ),
			default => null,
		};
	}
	if ( $queried instanceof WP_Term ) {
		$value = match ( $what ) {
			'slug' => $queried->slug,
			'url' => get_term_link( $queried ),
			'id' => (string) $queried->term_id,
			'type' => $queried->taxonomy,
			default => null,
		};
		return is_string( $value ) ? $value : null;
	}
	return null;
}

/**
 * Page N of the archive shown, as the builder writes it: from the archive's own link, not from the address that
 * was asked for.
 */
function fondamenta_pagina_n( int $n ): string {
	global $wp_query, $wp_rewrite;
	$base = null;
	if ( $wp_query instanceof WP_Query ) {
		if ( $wp_query->is_post_type_archive() ) {
			$type = $wp_query->get( 'post_type' );
			$type = is_array( $type ) ? ( 1 === count( $type ) ? reset( $type ) : '' ) : $type;
			$base = is_string( $type ) && '' !== $type ? get_post_type_archive_link( $type ) : null;
		} elseif ( $wp_query->is_category() || $wp_query->is_tag() || $wp_query->is_tax() ) {
			$term = $wp_query->get_queried_object();
			$base = $term instanceof WP_Term ? get_term_link( $term ) : null;
		} elseif ( $wp_query->is_author() ) {
			$base = get_author_posts_url( (int) $wp_query->get_queried_object_id() );
		} elseif ( $wp_query->is_home() ) {
			$posts_page = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
			$base       = $posts_page > 0 ? get_permalink( $posts_page ) : home_url( '/' );
		}
	}
	if ( ! is_string( $base ) || '' === $base ) {
		return get_pagenum_link( $n );
	}
	if ( $n <= 1 ) {
		return $base;
	}
	$query = '';
	$at    = strpos( $base, '?' );
	if ( false !== $at ) {
		$query = substr( $base, $at );
		$base  = substr( $base, 0, $at );
	}
	if ( ! $wp_rewrite instanceof WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
		return $base . ( '' === $query ? '?' : $query . '&' ) . 'paged=' . $n;
	}
	return user_trailingslashit( rtrim( $base, '/' ) . '/' . $wp_rewrite->pagination_base . '/' . $n, 'paged' ) . $query;
}

/** An image (attachment) value: url, alt, width, height, id or the address of a registered size. */
function fondamenta_valore_immagine( int $id, string $what ): ?string {
	if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
		return null;
	}
	if ( 'alt' === $what ) {
		return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	}
	if ( 'id' === $what ) {
		return (string) $id;
	}
	$size = in_array( $what, array( 'url', 'width', 'height' ), true ) ? 'full' : $what;
	$file = wp_get_attachment_image_src( $id, $size );
	if ( ! is_array( $file ) ) {
		return null;
	}
	return match ( $what ) {
		'width' => (string) $file[1],
		'height' => (string) $file[2],
		default => (string) $file[0],
	};
}

/**
 * The attachment an image source is bound to, and its size, when it is one.
 *
 * @param array<mixed> $parts
 * @return array{0: int, 1: string}|null
 */
function fondamenta_immagine_di( array $parts, WP_Block $block ): ?array {
	if ( 1 !== count( $parts ) || ! is_array( $parts[0] ) || ! is_string( $parts[0]['chiave'] ?? null ) ) {
		return null;
	}
	$keys = explode( '.', $parts[0]['chiave'] );
	$id   = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
	if ( 'page' === $keys[0] ) {
		array_shift( $keys );
		$id = (int) get_queried_object_id();
	}
	if ( 'image' === $keys[0] ) {
		$image = (int) get_post_thumbnail_id( $id );
		$size  = $keys[1] ?? 'url';
	} elseif ( 'fields' === $keys[0] && isset( $keys[1], $keys[2] ) ) {
		$raw   = get_post_meta( $id, $keys[1], true );
		$image = is_numeric( $raw ) ? (int) $raw : 0;
		$size  = $keys[2];
	} else {
		return null;
	}
	if ( $image <= 0 || ! in_array( $size, array( 'url', ...get_intermediate_image_sizes() ), true ) ) {
		return null;
	}
	return array( $image, 'url' === $size ? 'full' : $size );
}

/**
 * Width, height, srcset and sizes of a bound image, unless the element has them: what the builder adds to an image
 * from the content.
 *
 * @param array{0: int, 1: string} $image
 */
function fondamenta_completa_immagine( WP_HTML_Tag_Processor $tag, array $image ): void {
	[ $id, $size ] = $image;
	$file          = wp_get_attachment_image_src( $id, $size );
	foreach ( array( 'width' => 1, 'height' => 2 ) as $dimension => $at ) {
		if ( null === $tag->get_attribute( $dimension ) && is_array( $file ) && (int) $file[ $at ] > 0 ) {
			$tag->set_attribute( $dimension, (string) (int) $file[ $at ] );
		}
	}
	if ( null !== $tag->get_attribute( 'srcset' ) ) {
		return;
	}
	$srcset = (string) wp_get_attachment_image_srcset( $id, $size );
	if ( '' === $srcset ) {
		return;
	}
	$tag->set_attribute( 'srcset', $srcset );
	$sizes = (string) wp_get_attachment_image_sizes( $id, $size );
	if ( null === $tag->get_attribute( 'sizes' ) && '' !== $sizes ) {
		$tag->set_attribute( 'sizes', ( 'lazy' === $tag->get_attribute( 'loading' ) ? 'auto, ' : '' ) . $sizes );
	}
}

/**
 * Names of the terms of a post in a taxonomy.
 *
 * @return list<string>
 */
function fondamenta_nomi( WP_Post $post, string $taxonomy ): array {
	$terms = get_the_terms( $post, $taxonomy );
	return is_array( $terms ) ? array_values( array_map( static fn ( WP_Term $t ): string => $t->name, $terms ) ) : array();
}

/** A date in a format, in the site's time zone. */
function fondamenta_data( string $value, string $format ): string {
	if ( '' === $value ) {
		return '';
	}
	try {
		$timestamp = ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
	} catch ( Exception ) {
		return '';
	}
	return (string) wp_date( '' === $format ? (string) get_option( 'date_format', 'Y-m-d' ) : $format, $timestamp );
}

/**
 * A loop: its blocks once for each item.
 *
 * @param array<string, mixed> $attributes
 */
function fondamenta_render_loop( array $attributes, string $content, WP_Block $block ): string {
	unset( $content );
	$items  = fondamenta_voci( $attributes, $block );
	$blocks = array();
	$empty  = array();
	foreach ( $block->parsed_block['innerBlocks'] as $inner ) {
		if ( 'fondamenta/altrimenti' === $inner['blockName'] ) {
			$empty = $inner['innerBlocks'];
		} else {
			$blocks[] = $inner;
		}
	}
	$html = '';
	foreach ( array() === $items ? array( array() ) : $items as $context ) {
		foreach ( array() === $items ? $empty : $blocks as $inner ) {
			$html .= ( new WP_Block( $inner, $context + $block->context ) )->render();
		}
	}
	return $html;
}

/**
 * The items of a loop, each as the block context its blocks read.
 *
 * @param array<string, mixed> $attributes
 * @return list<array<string, mixed>>
 */
function fondamenta_voci( array $attributes, WP_Block $block ): array {
	$fonte = (string) ( $attributes['fonte'] ?? 'query' );
	if ( 'filtri' === $fonte ) {
		return fondamenta_filtro_voci( $attributes );
	}
	if ( 'righe' === $fonte ) {
		return fondamenta_righe( $attributes, $block );
	}
	if ( 'campo' === $fonte ) {
		// The values of a field with more than one (a list of years, of names): each one is `item`.
		$id     = ! empty( $attributes['pagina'] ) ? (int) get_queried_object_id() : ( isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID() );
		$post   = $id > 0 ? get_post( $id ) : null;
		$values = $post instanceof WP_Post && ! post_password_required( $post ) ? fondamenta_campo( $post, (string) ( $attributes['campo'] ?? '' ) ) : array();
		$values = is_array( $values ) ? array_slice( array_values( array_filter( $values, 'is_scalar' ) ), 0, FONDAMENTA_MASSIMO ) : array();
		return array_map( static fn ( mixed $v ): array => array( 'fondamenta/voce' => array( 'valore' => $v ) ), $values );
	}
	if ( 'termini' === $fonte ) {
		$id    = ! empty( $attributes['pagina'] ) ? (int) get_queried_object_id() : ( isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID() );
		$terms = $id > 0 ? get_the_terms( $id, (string) ( $attributes['tassonomia'] ?? 'category' ) ) : false;
		return is_array( $terms ) ? array_map( static fn ( WP_Term $t ): array => array( 'fondamenta/voce' => array( 'termine' => $t->term_id ) ), array_values( $terms ) ) : array();
	}
	if ( 'pagine' === $fonte ) {
		global $wp_query;
		if ( ! $wp_query instanceof WP_Query || $wp_query->is_singular() || $wp_query->is_404() ) {
			return array();
		}
		$page  = max( 1, (int) $wp_query->get( 'paged' ) );
		$pages = max( 1, (int) $wp_query->max_num_pages );
		$out   = array();
		for ( $n = 1; $n <= min( $pages, FONDAMENTA_MASSIMO ); $n++ ) {
			$out[] = array(
				'fondamenta/voce' => array(
					'pagina' => array(
						'number'  => $n,
						'url'     => fondamenta_pagina_n( $n ),
						'current' => $n === $page,
					),
				),
			);
		}
		return $out;
	}
	$query = is_array( $attributes['query'] ?? null ) ? $attributes['query'] : array();
	if ( 'principale' === $fonte ) {
		global $wp_query;
		if ( ! $wp_query instanceof WP_Query ) {
			return array();
		}
		$posts = $wp_query->posts;
		$own   = array_intersect_key( $query, array_flip( array( 'posts_per_page', 'offset', 'order', 'orderby' ) ) );
		if ( array() !== $own && ! $wp_query->is_404() ) {
			$posts = ( new WP_Query( array_merge( $wp_query->query_vars, $own, array( 'no_found_rows' => true, 'perm' => 'readable' ) ) ) )->posts;
		}
	} else {
		$args = fondamenta_query( $query );
		if ( null === $args ) {
			return array();
		}
		$posts = ( new WP_Query( fondamenta_ordine_stabile( $args ) ) )->posts;
	}
	$out = array();
	foreach ( $posts as $post ) {
		$post  = get_post( $post );
		$out[] = $post instanceof WP_Post ? array(
			'postId'          => $post->ID,
			'postType'        => $post->post_type,
			'fondamenta/voce' => array( 'post' => $post->ID ),
		) : array();
	}
	return array_values( array_filter( $out ) );
}

/**
 * The same order with the ID breaking ties in the same direction, as Histeon's loops (LoopPresets::stable_order()):
 * posts with the same date would otherwise come out in whatever order the database plan gives. Orders given as a
 * list, random and the others stay as they are.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function fondamenta_ordine_stabile( array $args ): array {
	$orderby = $args['orderby'] ?? 'date';
	if ( ! is_string( $orderby ) || ! in_array( strtolower( trim( $orderby ) ), array( 'date', 'modified', 'title', 'menu_order', 'comment_count' ), true ) ) {
		return $args;
	}
	$order           = is_string( $args['order'] ?? null ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
	$args['orderby'] = array(
		strtolower( trim( $orderby ) ) => $order,
		'ID'                           => $order,
	);
	unset( $args['order'] );
	return $args;
}

/**
 * Query arguments as stored by the conversion, with the rules every list follows: published, readable, no password,
 * at most FONDAMENTA_MASSIMO, only types a visitor may list. `$today` and `$now` become the date and time of the site.
 *
 * @param array<mixed> $stored
 * @return array<string, mixed>|null
 */
function fondamenta_query( array $stored ): ?array {
	$allowed = array( 'post_type', 'posts_per_page', 'offset', 'order', 'orderby', 'cat', 'category_name', 'tag', 'author', 'post__in', 'post__not_in', 'post_parent', 'tax_query', 'meta_query', 'meta_key', 'meta_value', 'meta_compare', 'meta_type', 'date_query', 'ignore_sticky_posts', 's' );
	$args    = fondamenta_oggi( array_intersect_key( $stored, array_flip( $allowed ) ) );
	$types   = array_values(
		array_filter(
			(array) ( $args['post_type'] ?? 'post' ),
			static fn ( mixed $type ): bool => is_string( $type ) && post_type_exists( $type ) && ( is_post_type_viewable( $type ) || in_array( $type, fondamenta_tipi_elencabili(), true ) ) && ! in_array( $type, array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'attachment', 'xb_submission' ), true )
		)
	);
	if ( array() === $types ) {
		return null;
	}
	$per_page = (int) ( $args['posts_per_page'] ?? 10 );
	return array_merge(
		array( 'ignore_sticky_posts' => true ),
		$args,
		array(
			'post_type'      => 1 === count( $types ) ? $types[0] : $types,
			'post_status'    => 'publish',
			'has_password'   => false,
			'perm'           => 'readable',
			'no_found_rows'  => true,
			'posts_per_page' => $per_page <= 0 ? FONDAMENTA_MASSIMO : min( FONDAMENTA_MASSIMO, $per_page ),
			'nopaging'       => false,
		)
	);
}

/**
 * Content types a loop may list although they have no pages of their own: those the site declares (its content
 * types, and the ones an administrator listed), as the builder allows them.
 *
 * @return list<string>
 */
function fondamenta_tipi_elencabili(): array {
	$declared = get_option( 'xb_content', array() );
	$types    = is_array( $declared ) && is_array( $declared['types'] ?? null ) ? array_keys( $declared['types'] ) : array();
	$listed   = get_option( 'xb_loop_types', array() );
	return array_values( array_filter( array_merge( $types, is_array( $listed ) ? $listed : array() ), 'is_string' ) );
}

/**
 * `$today` (Y-m-d) and `$now` (Y-m-d H:i:s) in stored arguments, at the time of the request.
 *
 * @param array<mixed> $args
 * @return array<mixed>
 */
function fondamenta_oggi( array $args ): array {
	foreach ( $args as $key => $value ) {
		if ( is_array( $value ) ) {
			$args[ $key ] = fondamenta_oggi( $value );
		} elseif ( '$today' === $value ) {
			$args[ $key ] = wp_date( 'Y-m-d' );
		} elseif ( '$now' === $value ) {
			$args[ $key ] = wp_date( 'Y-m-d H:i:s' );
		}
	}
	return $args;
}

/**
 * A condition: its blocks when a named value is not empty, or when one comparison with a written value holds, or
 * when any group of comparisons in `oppure` holds whole (the menu item of the section shown). `vuoto` turns the
 * answer round.
 *
 * @param array<string, mixed> $attributes
 */
function fondamenta_render_se( array $attributes, string $content, WP_Block $block ): string {
	if ( is_array( $attributes['oppure'] ?? null ) ) {
		$full = false;
		foreach ( $attributes['oppure'] as $group ) {
			$all = is_array( $group ) && array() !== $group;
			foreach ( is_array( $group ) ? $group : array() as $test ) {
				$all = $all && is_array( $test ) && fondamenta_prova( $test, $block );
			}
			$full = $full || $all;
		}
	} else {
		$legame = is_array( $attributes['legame'] ?? null ) ? $attributes['legame'] : array();
		$full   = fondamenta_prova(
			array(
				'chiave'    => $legame['chiave'] ?? '',
				'confronto' => is_string( $attributes['confronto'] ?? null ) && '' !== $attributes['confronto'] ? $attributes['confronto'] : 'pieno',
				'valore'    => $attributes['valore'] ?? '',
			),
			$block
		);
	}
	return $full === empty( $attributes['vuoto'] ) ? $content : '';
}

/**
 * One test on a named value: `pieno` (not empty), a comparison with a written value (numbers as numbers, anything
 * else as text), or `contiene`; `nega` turns it round.
 *
 * @param array<mixed> $test
 */
function fondamenta_prova( array $test, WP_Block $block ): bool {
	$value = is_string( $test['chiave'] ?? null ) && '' !== $test['chiave'] ? fondamenta_valore( $test['chiave'], array(), $block ) : null;
	$other = is_scalar( $test['valore'] ?? null ) ? (string) $test['valore'] : '';
	$num   = is_numeric( $value ) && is_numeric( $other );
	$a     = $num ? (float) $value : (string) $value;
	$b     = $num ? (float) $other : $other;
	$held  = match ( $test['confronto'] ?? 'pieno' ) {
		'==' => $a == $b, // phpcs:ignore Universal.Operators.StrictComparisons -- numbers or text, as the builder compares.
		'!=' => $a != $b, // phpcs:ignore Universal.Operators.StrictComparisons
		'>' => $a > $b,
		'>=' => $a >= $b,
		'<' => $a < $b,
		'<=' => $a <= $b,
		'contiene' => '' !== $other && str_contains( (string) $value, $other ),
		default => null !== $value && '' !== $value && '0' !== $value && 'false' !== $value,
	};
	return empty( $test['nega'] ) ? $held : ! $held;
}

/**
 * The content of the post shown, with no wrapper; empty on a list, as the builder's slot of a template.
 *
 * @param array<string, mixed> $attributes
 */
function fondamenta_render_contenuto( array $attributes, string $content, WP_Block $block ): string {
	unset( $attributes, $content );
	static $seen = array();
	// WordPress gives every top-level block the global post as postId: only an item of a loop is a post of its own.
	$item = isset( $block->context['fondamenta/voce']['post'] );
	if ( ! $item && ! is_singular() && ( is_home() || is_archive() || is_search() ) ) {
		return '';
	}
	$id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();
	if ( $id <= 0 || isset( $seen[ $id ] ) ) {
		return '';
	}
	$seen[ $id ] = true;
	try {
		/** This filter is documented in wp-includes/post-template.php */
		$html = (string) apply_filters( 'the_content', str_replace( ']]>', ']]&gt;', get_the_content( null, false, $id ) ) );
	} finally {
		unset( $seen[ $id ] );
	}
	return $html;
}
