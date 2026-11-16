<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Security;

use XB\Blocks;
use XB\Spec;

/**
 * Applies MarkupPolicy to XB blocks on every post save — threat model §4.
 *
 * Runs on `wp_insert_post_data`, so it covers the block editor, REST, XML-RPC, WP-CLI and imports alike.
 * Authors with `unfiltered_html` are not filtered. For everyone else:
 * - an element whose tag is not allowed is removed with its subtree;
 * - attributes that are not allowed are removed;
 * - xb/text content is limited to inline formatting, xb/raw to wp_kses_post();
 * - nodes that already existed with identical attributes (same uid) are left untouched, so a client
 *   editing text in Gutenberg never strips markup a developer added with higher permissions.
 *
 * @phpstan-type Block array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}
 */
final class ContentSanitizer {

	/** @var array<string, true> uids already accepted as "known" in the current pass: each may be reused once. */
	private array $used = array();

	public function __construct( private readonly MarkupPolicy $policy ) {}

	/**
	 * The full restricted pipeline, for content that never goes through core's save filters (dev commits,
	 * previews, publishing a commit whose author lacked unfiltered_html): XB policy first, then the same
	 * kses core applies to restricted authors, which also covers freeform HTML and non-XB blocks.
	 *
	 * @param string ...$references Content already trusted (live, dev head): its unchanged nodes are kept.
	 */
	public function restricted_content( string $content, string ...$references ): string {
		$previous = array();
		foreach ( $references as $reference ) {
			$previous += $this->index( parse_blocks( $reference ) );
		}
		$clean = Blocks::serialize( $this->sanitize( parse_blocks( $content ), $previous ) );
		return wp_kses_post( $clean );
	}

	/**
	 * @param array<string, mixed> $data    Slashed post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function filter_post_data( array $data, array $postarr ): array {
		$content = isset( $data['post_content'] ) && is_string( $data['post_content'] ) ? wp_unslash( $data['post_content'] ) : '';
		if ( current_user_can( 'unfiltered_html' ) || ( ! Spec::has_xb_blocks( $content ) && 1 !== preg_match( '/<!--\s+wp:etch\//', $content ) ) ) {
			return $data;
		}
		$previous = array();
		$id       = isset( $postarr['ID'] ) && is_numeric( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $id > 0 ) {
			$stored   = get_post_field( 'post_content', $id, 'raw' );
			$previous = $this->index( parse_blocks( is_string( $stored ) ? $stored : '' ) );
		}
		$data['post_content'] = wp_slash( serialize_blocks( $this->sanitize( parse_blocks( $content ), $previous ) ) );
		return $data;
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param array<string, string>       $previous uid → signature of nodes already stored.
	 * @return list<Block>
	 */
	public function sanitize( array $blocks, array $previous, ?string $parent_tag = null ): array {
		if ( null === $parent_tag ) {
			$this->used = array();
		}
		return $this->sanitize_list( $blocks, $previous, $parent_tag );
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param array<string, string>       $previous
	 * @return list<Block>
	 */
	private function sanitize_list( array $blocks, array $previous, ?string $parent_tag ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$clean = $this->sanitize_block( $block, $previous, $parent_tag );
			if ( null !== $clean ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>  $block
	 * @param array<string, string> $previous
	 * @return Block|null Null when the block must be removed.
	 */
	private function sanitize_block( array $block, array $previous, ?string $parent_tag ): ?array {
		$name  = $block['blockName'] ?? null;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$uid   = is_string( $attrs['uid'] ?? null ) ? $attrs['uid'] : '';
		// Text is judged together with its parent: verbatim text is harmless inside <textarea> only.
		// A known node may appear once: it cannot be duplicated or reused elsewhere.
		$known = '' !== $uid && ! isset( $this->used[ $uid ] ) && isset( $previous[ $uid ] ) && $previous[ $uid ] === self::signature( (string) $name, $attrs, 'xb/text' === $name ? $parent_tag : null );
		if ( $known ) {
			$this->used[ $uid ] = true;
		}
		$tag = $parent_tag;

		if ( 'xb/element' === $name ) {
			$tag = is_string( $attrs['tag'] ?? null ) ? $attrs['tag'] : '';
			if ( ! $known ) {
				if ( null !== $this->policy->check_tag( $tag, MarkupPolicy::TIER_RESTRICTED ) ) {
					return null;
				}
				$attributes = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
				foreach ( $attributes as $attribute => $value ) {
					$valid = is_string( $value ) || true === $value;
					if ( ! $valid || null !== $this->policy->check_attribute( (string) $attribute, $value, MarkupPolicy::TIER_RESTRICTED ) ) {
						unset( $attributes[ $attribute ] );
					} elseif ( 'style' === strtolower( (string) $attribute ) && is_string( $value ) ) {
						// Same CSS filtering core applies to restricted authors (no positioning tricks, no url()).
						$attributes[ $attribute ] = safecss_filter_attr( $value );
						if ( '' === $attributes[ $attribute ] ) {
							unset( $attributes[ $attribute ] );
						}
					}
				}
				if ( array() === $attributes ) {
					unset( $attrs['attributes'] );
				} else {
					$attrs['attributes'] = $attributes;
				}
			}
		} elseif ( 'xb/text' === $name && ! $known ) {
			$parent = strtolower( (string) $parent_tag );
			if ( in_array( $parent, array( 'script', 'style' ), true ) ) {
				return null; // New code inside a trusted <script>/<style> is code: not for restricted authors.
			}
			if ( ( $attrs['verbatim'] ?? false ) === true ) {
				if ( 'textarea' !== $parent || str_contains( strtolower( is_string( $attrs['content'] ?? null ) ? $attrs['content'] : '' ), '</textarea' ) ) {
					return null;
				}
			} else {
				$attrs['content'] = wp_kses( is_string( $attrs['content'] ?? null ) ? $attrs['content'] : '', Spec::TEXT_INLINE_HTML );
			}
		} elseif ( 'xb/raw' === $name && ! $known ) {
			$attrs['content'] = wp_kses_post( is_string( $attrs['content'] ?? null ) ? $attrs['content'] : '' );
		} elseif ( is_string( $name ) && str_starts_with( $name, 'etch/' ) && ! isset( $previous[ self::etch_key( $name, $attrs ) ] ) ) {
			// Etch blocks keep their script (base64, which kses leaves alone) and attributes in block attributes:
			// Histeon prints them when it renders Etch content. A new or changed one from a restricted author
			// loses its script and every attribute the restricted tier refuses.
			unset( $attrs['script'] );
			$attributes = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
			foreach ( $attributes as $attribute => $value ) {
				if ( false === $value ) {
					continue;
				}
				if ( ! ( is_string( $value ) || true === $value ) || null !== $this->policy->check_attribute( (string) $attribute, $value, MarkupPolicy::TIER_RESTRICTED ) ) {
					unset( $attributes[ $attribute ] );
				}
			}
			if ( isset( $attrs['attributes'] ) ) {
				$attrs['attributes'] = $attributes;
			}
		}

		return $this->with_children(
			array(
				'blockName'    => is_string( $name ) ? $name : null,
				'attrs'        => $attrs,
				'innerBlocks'  => is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(),
				'innerHTML'    => is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '',
				'innerContent' => is_array( $block['innerContent'] ?? null ) ? array_values( array_filter( $block['innerContent'], static fn ( mixed $c ): bool => null === $c || is_string( $c ) ) ) : array(),
			),
			$previous,
			$tag
		);
	}

	/**
	 * Sanitizes inner blocks and keeps innerContent consistent (one null placeholder per inner block).
	 *
	 * @param Block                 $block
	 * @param array<string, string> $previous
	 * @return Block
	 */
	private function with_children( array $block, array $previous, ?string $parent_tag ): array {
		$inner = array_values( $block['innerBlocks'] );
		if ( array() === $inner ) {
			return $block;
		}
		$content  = $block['innerContent'];
		$children = array();
		$chunks   = array();
		$index    = 0;
		foreach ( $content as $chunk ) {
			if ( null !== $chunk ) {
				$chunks[] = $chunk;
				continue;
			}
			$child = isset( $inner[ $index ] ) ? $this->sanitize_block( $inner[ $index ], $previous, $parent_tag ) : null;
			++$index;
			if ( null !== $child ) {
				$children[] = $child;
				$chunks[]   = null;
			}
		}
		$block['innerBlocks']  = $children;
		$block['innerContent'] = $chunks;
		return $block;
	}

	/**
	 * uid → signature of every stored element, text and raw node (text includes its parent tag).
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return array<string, string>
	 */
	public function index( array $blocks, ?string $parent_tag = null ): array {
		$index = array();
		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? null;
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( in_array( $name, array( 'xb/element', 'xb/text', 'xb/raw' ), true ) && is_string( $attrs['uid'] ?? null ) && ! isset( $index[ $attrs['uid'] ] ) ) {
				$index[ $attrs['uid'] ] = self::signature( (string) $name, $attrs, 'xb/text' === $name ? $parent_tag : null );
			}
			if ( is_string( $name ) && str_starts_with( $name, 'etch/' ) ) {
				$index[ self::etch_key( $name, $attrs ) ] = 'etch';
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$tag    = 'xb/element' === $name && is_string( $attrs['tag'] ?? null ) ? $attrs['tag'] : $parent_tag;
				$index += $this->index( $block['innerBlocks'], $tag );
			}
		}
		return $index;
	}

	/**
	 * An Etch block already stored, as it is: kept with its script and attributes when a restricted author saves
	 * the page again.
	 *
	 * @param array<string, mixed> $attrs
	 */
	private static function etch_key( string $name, array $attrs ): string {
		return 'etch:' . self::signature( $name, $attrs, null );
	}

	/** @param array<string, mixed> $attrs */
	private static function signature( string $name, array $attrs, ?string $parent_tag ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $name, $attrs, $parent_tag ) ) );
	}
}
