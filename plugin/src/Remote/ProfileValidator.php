<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

use XB\Versioning\Documents;

/**
 * What a transfer profile may carry, checked before anything is written (docs/spec/cms-bridge.md §5): the blocks,
 * tags, expressions, component bindings and CSS of every document. Pure checks: they throw, they never write.
 * The markup itself (tags, attributes, content model, text) is histos-transfer-markup-v1, checked by TransferMarkup
 * with the same rules and reason codes as Histeon Publish (docs/spec/transfer-markup.md).
 *
 * @phpstan-type ParsedBlock array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}
 * @phpstan-import-type TransferContext from TransferMarkup
 */
final class ProfileValidator {

	private const TRANSFER_MODIFIERS = array( 'upper', 'lower', 'capitalize', 'trim', 'inline', 'stripTags', 'truncateWords', 'truncateChars', 'date', 'number', 'length', 'first', 'last', 'join', 'slice', 'includes', 'pluck', 'groupBy', 'default', 'json', 'slug' );
	private const TRANSFER_BUILTIN_QUERY_PRESETS = array( 'posts', 'terms', 'main', 'users' );

	/**
	 * @param array<ParsedBlock>    $blocks
	 * @param array<string, true>   $asset_ids
	 * @param array<string, string> $component_ids_by_ref
	 * @param array<string, array<string, true>|null> $transfer_query_presets
	 */
	public static function assert_page_blocks( array $blocks, array $asset_ids, array $component_ids_by_ref, bool $allow_slot = false, string $owner_id = '', bool $validate_tree = true, array $transfer_query_presets = array() ): void {
		if ( $validate_tree ) {
			// Media too, block by block in document order, as Publish checks them: the first violation is the same.
			self::assert_transfer_block_tree( $blocks, $allow_slot ? 'component' : 'document', $owner_id, $transfer_query_presets, $asset_ids );
		}
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( ! $allow_slot && 'xb/passthrough' === $name ) {
				continue; // Foreign: it comes back as it left (ArticleBlocks::assert_foreign_known), and nothing in it is ours.
			}
			if ( ! in_array( $name, array( 'xb/element', 'xb/text', 'xb/component', 'xb/if', 'xb/branch', 'xb/loop', 'xb/slot-fill' ), true ) && ( ! $allow_slot || 'xb/slot' !== $name ) ) {
				throw new \DomainException( 'The incoming page contains a block outside the static page profile.' );
			}
			$attrs = $block['attrs'];
			if ( isset( $attrs['attributes']['style'] ) && is_string( $attrs['attributes']['style'] ) ) {
				self::assert_css_profile( $attrs['attributes']['style'] );
			}
			if ( 'xb/element' === $name ) {
				self::assert_media( $attrs, $asset_ids );
			}
			if ( 'xb/component' === $name ) {
				$ref = $attrs['ref'] ?? null;
				$uid = $attrs['uid'] ?? null;
				if ( ! is_string( $ref ) || ! isset( $component_ids_by_ref[ $ref ] ) || ! is_string( $uid ) || 1 !== preg_match( '/^[a-z0-9]{8,16}$/', $uid ) ) {
					throw new \DomainException( 'The incoming document references a Histeon component outside the transfer profile.' );
				}
			}
			if ( array() !== $block['innerBlocks'] ) {
				/** @var array<ParsedBlock> $inner_blocks */
				$inner_blocks = $block['innerBlocks'];
				self::assert_page_blocks( $inner_blocks, $asset_ids, $component_ids_by_ref, $allow_slot, $owner_id, false, $transfer_query_presets );
			}
		}
	}

	/**
	 * Media of an element are project images (docs/spec/transfer-markup.md, Media): a non-empty `src` is a whole
	 * component prop (`{props.name}`, filled with project media) or `histos-media://<uuid>` of an image asset of the
	 * profile; every `srcset` candidate of `img` and `source` is such an identity (referenced with its index).
	 *
	 * @param array<string, mixed> $attrs
	 * @param array<string, true>  $asset_ids
	 */
	private static function assert_media( array $attrs, array $asset_ids ): void {
		$attributes = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
		$tag        = $attrs['tag'] ?? null;
		$source     = $attributes['src'] ?? null;
		if ( is_string( $source ) && TransferMarkup::is_whole_interpolation( $source ) && ! TransferMarkup::is_transfer_media_prop( $source ) ) {
			throw new TransferMarkupViolation( 'media', 'The incoming page has a dynamic image source other than a whole component prop ({props.name}).' );
		}
		if ( null !== $source && '' !== $source && ! ( is_string( $source ) && TransferMarkup::is_transfer_media_prop( $source ) ) ) {
			$asset_id = is_string( $source ) ? TransferMarkup::media_id( $source ) : null;
			if ( null === $asset_id ) {
				throw new TransferMarkupViolation( 'media', 'The incoming page contains external or unresolved media: upload the image to the project media to transfer it.' );
			}
			if ( ! isset( $asset_ids[ $asset_id ] ) ) {
				throw new TransferMarkupViolation( 'media', 'The incoming page references media outside the transfer profile or media that is not an image.' );
			}
		}
		// Doc 23 item 121: a link to a media file names an image of the profile, like a src.
		$href = $attributes['href'] ?? null;
		if ( 'a' === $tag && is_string( $href ) ) {
			$asset_id = TransferMarkup::media_id( $href );
			if ( null === $asset_id && str_starts_with( $href, 'histos-media://' ) ) {
				throw new TransferMarkupViolation( 'media', 'The incoming page links an invalid media identity.' );
			}
			if ( null !== $asset_id && ! isset( $asset_ids[ $asset_id ] ) ) {
				throw new TransferMarkupViolation( 'media', 'The incoming page links media outside the transfer profile or media that is not an image.' );
			}
		}
		$srcset = $attributes['srcset'] ?? null;
		if ( ( 'img' === $tag || 'source' === $tag ) && is_string( $srcset ) ) {
			$candidates = TransferMarkup::parse_srcset( $srcset );
			if ( null === $candidates ) {
				throw new TransferMarkupViolation( 'media', 'The srcset of <' . $tag . '> is not a list of project media.' );
			}
			foreach ( $candidates as $candidate ) {
				$asset_id = TransferMarkup::media_id( $candidate['url'] );
				if ( null === $asset_id ) {
					throw new TransferMarkupViolation( 'media', 'A srcset candidate of <' . $tag . '> is external or unresolved media: upload the image to the project media to transfer it.' );
				}
				if ( ! isset( $asset_ids[ $asset_id ] ) ) {
					throw new TransferMarkupViolation( 'media', 'A srcset candidate of <' . $tag . '> references media outside the transfer profile or media that is not an image.' );
				}
			}
		}
	}

	/**
	 * Validate component props and slots before any WordPress entity is written.
	 *
	 * @param array<ParsedBlock>                  $blocks
	 * @param array<string, array<string, mixed>> $entities_by_ref
	 */
	public static function assert_component_bindings( array $blocks, array $entities_by_ref, string $owner_id ): void {
		$visit = static function ( array $items ) use ( &$visit, $entities_by_ref, $owner_id ): void {
			foreach ( $items as $block ) {
				if ( 'xb/component' === ( $block['blockName'] ?? null ) ) {
					$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
					$ref = $attrs['ref'] ?? '';
					$definition = is_string( $ref ) ? ( $entities_by_ref[ $ref ] ?? null ) : null;
					if ( ! is_array( $definition ) ) {
							throw new \DomainException( 'The Histeon component definition is missing while validating ' . $owner_id . '.' );
					}
					$schema = $definition['payload']['schema'] ?? array();
					$declared_props = is_array( $schema ) && is_array( $schema['props'] ?? null ) ? $schema['props'] : null;
					if ( null === $declared_props ) {
						throw new \DomainException( 'The Histeon component schema is invalid for ' . $ref . '.' );
					}
					$props = is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array();
					foreach ( $props as $name => $_value ) {
						if ( ! is_string( $name ) || ! array_key_exists( $name, $declared_props ) ) {
							throw new \DomainException( 'The Histeon component ' . $ref . ' does not declare prop ' . (string) $name . '.' );
						}
					}
					/** @var list<ParsedBlock> $definition_blocks */
					$definition_blocks = array_values( parse_blocks( (string) ( $definition['payload']['content'] ?? '' ) ) );
					$declared_slots = self::declared_component_slots( $definition_blocks );
					$filled_slots = array();
					foreach ( $block['innerBlocks'] as $fill ) {
						$fill_attrs = is_array( $fill['attrs'] ?? null ) ? $fill['attrs'] : array();
						$slot = $fill_attrs['name'] ?? 'default';
						if ( ! is_string( $slot ) || ! isset( $declared_slots[ $slot ] ) ) {
							throw new \DomainException( 'The Histeon component ' . $ref . ' does not declare slot ' . (string) $slot . '.' );
						}
						if ( isset( $filled_slots[ $slot ] ) ) {
							throw new \DomainException( 'The Histeon component ' . $ref . ' receives slot ' . $slot . ' more than once.' );
						}
						$filled_slots[ $slot ] = true;
					}
				}
				/** @var array<ParsedBlock> $children */
				$children = $block['innerBlocks'];
				if ( array() !== $children ) {
					$visit( $children );
				}
			}
		};
		$visit( $blocks );
	}

	/**
	 * @param array<ParsedBlock> $blocks
	 * @return array<string, true>
	 */
	private static function declared_component_slots( array $blocks ): array {
		$slots = array();
		$visit = static function ( array $items ) use ( &$visit, &$slots ): void {
			foreach ( $items as $block ) {
				if ( 'xb/slot' === ( $block['blockName'] ?? null ) ) {
					$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
					$name = $attrs['name'] ?? 'default';
					if ( is_string( $name ) ) {
						$slots[ $name ] = true;
					}
				}
				if ( 'xb/component' !== $block['blockName'] && array() !== $block['innerBlocks'] ) {
					$visit( $block['innerBlocks'] );
				}
			}
		};
		$visit( $blocks );
		return $slots;
	}

	/**
	 * Query presets that a WordPress snapshot can represent. Built-ins accept their native arguments; relational
	 * CMS presets accept only the aliases understood by the M3 query resolver and their configured parameters.
	 *
	 * @return array<string, array<string, true>|null>
	 */
	public static function transfer_query_presets_from_wordpress(): array {
		$presets = array_fill_keys( self::TRANSFER_BUILTIN_QUERY_PRESETS, null );
		$stored = PageProfileAdapter::dev_payload( Documents::SETTINGS )['presets'] ?? array();
		$left_out = ContentProfileAdapter::presets_left_out();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $preset ) {
			$key = (string) $key;
			if ( isset( $left_out[ $key ] ) ) {
				continue;
			}
			$args = is_array( $preset ) && is_array( $preset['args'] ?? null ) ? $preset['args'] : null;
			$type_key = is_array( $args ) ? ( $args['xb_type_key'] ?? $args['post_type'] ?? null ) : null;
			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $key ) || in_array( $key, self::TRANSFER_BUILTIN_QUERY_PRESETS, true ) || ! is_array( $preset ) || 'posts' !== ( $preset['type'] ?? null ) || ! is_array( $args ) || ! is_string( $type_key ) ) {
				continue;
			}
			$allowed = array_fill_keys( array( 'limit', 'posts_per_page', 'count', 'locale', 'offset', 'page', 'paged' ), true );
			foreach ( array( $args['xb_limit'] ?? $args['posts_per_page'] ?? null, $args['offset'] ?? null ) as $value ) {
				if ( is_string( $value ) && 1 === preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]{0,63})(?:\s*\?\?\s*.+)?$/s', trim( $value ), $match ) ) {
					$allowed[ $match[1] ] = true;
				}
			}
			$presets[ $key ] = $allowed;
		}
		return $presets;
	}

	/**
	 * @param list<array<string, mixed>> $entities
	 * @return array<string, array<string, true>|null>
	 */
	public static function transfer_query_presets_from_entities( array $entities ): array {
		$presets = array_fill_keys( self::TRANSFER_BUILTIN_QUERY_PRESETS, null );
		$base_arguments = array( 'limit', 'posts_per_page', 'count', 'locale', 'offset', 'page', 'paged' );
		foreach ( $entities as $entity ) {
			if ( 'query-preset' !== ( $entity['kind'] ?? null ) ) {
				continue;
			}
			$key = $entity['payload']['key'] ?? null;
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $key ) || in_array( $key, self::TRANSFER_BUILTIN_QUERY_PRESETS, true ) || array_key_exists( $key, $presets ) ) {
				throw new \DomainException( 'The transfer profile contains a conflicting Histeon query preset.' );
			}
			$allowed = array_fill_keys( $base_arguments, true );
			foreach ( array( 'limitParam', 'offsetParam' ) as $parameter ) {
				$name = $entity['payload'][ $parameter ] ?? null;
				if ( is_string( $name ) ) {
					$allowed[ $name ] = true;
				}
			}
			$presets[ $key ] = $allowed;
		}
		return $presets;
	}

	/**
	 * Validate the portable, interpreter-only subset of the native Histeon tree.
	 *
	 * @param array<ParsedBlock> $blocks
	 * @param array<string, array<string, true>|null> $transfer_query_presets
	 * @param array<string, true>|null $asset_ids With the asset ids of the profile, media are checked too, in order.
	 */
	public static function assert_transfer_block_tree( array $blocks, string $owner_kind, string $owner_id, array $transfer_query_presets = array(), ?array $asset_ids = null ): void {
		$uids = array();
		$roots = 'document' === $owner_kind ? array( 'page' => true, 'this' => true ) : array( 'props' => true, 'slots' => true, 'page' => true, 'this' => true );
		// Where branches stand, on the blocks as written and before anything else, as Publish (checkTransferBranches).
		self::with_owner( $owner_id, static fn () => TransferMarkup::check_branches( $blocks ) );
		self::visit_transfer_blocks( $blocks, $owner_kind, $owner_id, null, $roots, $uids, 0, $transfer_query_presets, TransferMarkup::ROOT_CONTEXT, $asset_ids );
	}

	/**
	 * @param array<ParsedBlock>  $blocks
	 * @param array<string, true> $roots
	 * @param array<string, true>                         $uids
	 * @param array<string, array<string, true>|null>     $transfer_query_presets
	 * @param TransferContext                             $ctx Where the blocks sit: the nearest element and the enclosing svg, form and button.
	 * @param array<string, true>|null                  $asset_ids
	 */
	private static function visit_transfer_blocks( array $blocks, string $owner_kind, string $owner_id, ?string $parent, array $roots, array &$uids, int $loop_depth, array $transfer_query_presets, array $ctx, ?array $asset_ids = null ): void {
		$allowed = array( 'xb/element', 'xb/text', 'xb/component', 'xb/if', 'xb/branch', 'xb/loop', 'xb/slot', 'xb/slot-fill' );
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( 'document' === $owner_kind && 'xb/passthrough' === $name ) {
				$uid = $block['attrs']['uid'] ?? null;
				if ( ! is_string( $uid ) || 1 !== preg_match( '/^[a-z0-9]{8,16}$/', $uid ) || isset( $uids[ $uid ] ) || 1 !== count( $block['innerBlocks'] ) ) {
					throw new \DomainException( 'The Histeon transfer profile requires unique, valid block UIDs in ' . $owner_id . '.' );
				}
				$uids[ $uid ] = true;
				// Not inside svg, select, option, textarea or picture, where the parser would read its HTML otherwise (audit note 88).
				self::with_owner( $owner_id, static fn () => TransferMarkup::check_block( 'xb/passthrough', $ctx ) );
				continue;
			}
			if ( ! is_string( $name ) || ! in_array( $name, $allowed, true ) ) {
				throw new \DomainException( 'The Histeon transfer profile cannot preserve this block in ' . $owner_id . ': ' . ( is_string( $name ) ? $name : 'freeform content' ) . '.' );
			}
			$attrs = $block['attrs'];
			$uid = $attrs['uid'] ?? null;
			if ( ! is_string( $uid ) || 1 !== preg_match( '/^[a-z0-9]{8,16}$/', $uid ) || isset( $uids[ $uid ] ) ) {
				throw new \DomainException( 'The Histeon transfer profile requires unique, valid block UIDs in ' . $owner_id . '.' );
			}
			$uids[ $uid ] = true;
			if ( 'xb/branch' === $name && 'xb/if' !== $parent && 'xb/loop' !== $parent ) {
				throw new TransferMarkupViolation( 'context', 'A Histeon branch is outside xb/if and outside a loop in ' . $owner_id . '.' );
			}
			if ( 'xb/slot-fill' === $name && 'xb/component' !== $parent ) {
				throw new \DomainException( 'A Histeon slot fill is outside its owning block in ' . $owner_id . '.' );
			}
			if ( 'xb/if' === $parent && 'xb/branch' !== $name || 'xb/component' === $parent && 'xb/slot-fill' !== $name ) {
				throw new \DomainException( 'The Histeon block has a child type that its parent does not allow in ' . $owner_id . '.' );
			}
			if ( 'xb/slot' === $name && 'document' === $owner_kind ) {
				throw new \DomainException( 'Page documents cannot declare reusable-component slots.' );
			}
			if ( in_array( $name, array( 'xb/slot', 'xb/slot-fill' ), true ) ) {
				$slot = $attrs['name'] ?? 'default';
				if ( ! is_string( $slot ) || 1 !== preg_match( \XB\Spec::SLOT_NAME_PATTERN, $slot ) ) {
					throw new \DomainException( 'A Histeon slot name is invalid in ' . $owner_id . '.' );
				}
			}
			self::with_owner( $owner_id, static fn () => TransferMarkup::check_block( $name, $ctx ) );
			if ( 'xb/element' === $name ) {
				$tag = $attrs['tag'] ?? null;
				if ( ! is_string( $tag ) ) {
					throw new TransferMarkupViolation( 'tag', 'A Histeon element without a tag name is not transferable in ' . $owner_id . '.' );
				}
				if ( array_key_exists( 'attributes', $attrs ) && ! is_array( $attrs['attributes'] ) ) {
					throw new TransferMarkupViolation( 'attribute', 'A Histeon element has non-portable attributes in ' . $owner_id . '.' );
				}
				$element_attributes = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
				// Markup from Histeon CMS is held to histos-transfer-markup-v1 whoever owns the link (threat model T26),
				// which stays within the restricted tier of MarkupPolicy (TransferMarkupVectorsTest pins that): only the
				// form endpoint goes beyond it, with a static same-site or HTTPS action. Media identities are checked in
				// assert_page_blocks().
				$element_children = array_values( $block['innerBlocks'] );
				self::with_owner(
					$owner_id,
					static function () use ( $tag, $element_attributes, $ctx, $element_children ): void {
						TransferMarkup::check_element( $tag, $element_attributes, $ctx );
						TransferMarkup::check_children( $tag, array_map( TransferMarkup::child_kind( ... ), $element_children ) );
					}
				);
				// SVG values are static by the element check: only HTML values can hold expressions.
				if ( ! in_array( $tag, TransferMarkup::SVG_TAGS, true ) ) {
					foreach ( $element_attributes as $value ) {
						if ( is_string( $value ) ) {
							self::assert_transfer_interpolations( $value, $roots, $owner_id );
						}
					}
				}
				if ( null !== $asset_ids ) {
					self::assert_media( $attrs, $asset_ids );
				}
			} elseif ( 'xb/text' === $name ) {
				$content  = $attrs['content'] ?? null;
				$verbatim = $attrs['verbatim'] ?? false;
				if ( ! is_string( $content ) || ! is_bool( $verbatim ) ) {
					throw new \DomainException( 'A Histeon text block is not portable in ' . $owner_id . '.' );
				}
				self::with_owner( $owner_id, static fn () => TransferMarkup::check_text( $content, $verbatim, $ctx ) );
				// Verbatim text (a textarea's initial value) is printed as is: no expressions, no inline markup.
				if ( ! $verbatim ) {
					self::assert_transfer_interpolations( $content, $roots, $owner_id, true );
					self::assert_inline_markup( $content, $owner_id );
				}
			} elseif ( 'xb/if' === $name ) {
				$branches = array_values( $block['innerBlocks'] );
				if ( array() === $branches ) {
					throw new \DomainException( 'A Histeon conditional must contain at least one branch.' );
				}
				foreach ( $branches as $index => $branch ) {
					$branch_attrs = is_array( $branch['attrs'] ?? null ) ? $branch['attrs'] : array();
					$test = $branch_attrs['test'] ?? null;
					if ( 0 === $index && ! is_string( $test ) || null !== $test && ! is_string( $test ) || null === $test && $index !== count( $branches ) - 1 ) {
						throw new \DomainException( 'Histeon condition branches require a tested first branch and an optional final else.' );
					}
					if ( is_string( $test ) ) {
						self::assert_transfer_expression( $test, $roots, $owner_id );
					}
				}
			} elseif ( 'xb/loop' === $name ) {
				if ( $loop_depth >= 6 ) {
					throw new \DomainException( 'Nested Histeon loops exceed the portable limit of six.' );
				}
				$source = $attrs['source'] ?? null;
				if ( ! is_array( $source ) ) {
					throw new \DomainException( 'A Histeon loop source is invalid in ' . $owner_id . '.' );
				}
				if ( 'expr' === ( $source['kind'] ?? null ) ) {
					if ( array_diff( array_keys( $source ), array( 'kind', 'expr' ) ) || ! is_string( $source['expr'] ?? null ) ) {
						throw new \DomainException( 'A Histeon expression loop source is invalid in ' . $owner_id . '.' );
					}
					self::assert_transfer_expression( $source['expr'], $roots, $owner_id );
				} elseif ( 'preset' === ( $source['kind'] ?? null ) ) {
					$preset_id = $source['id'] ?? null;
					if ( array_diff( array_keys( $source ), array( 'kind', 'id', 'args', 'groupBy' ) ) || ! is_string( $preset_id ) || ! array_key_exists( $preset_id, $transfer_query_presets ) ) {
						throw new \DomainException( 'The Histeon loop references a query outside the transfer profile in ' . $owner_id . '.' );
					}
					// A query's result in groups (doc 23 of Publish, item 187): {field, how?}, literal strings only.
					if ( array_key_exists( 'groupBy', $source ) ) {
						$group_by = $source['groupBy'];
						if ( ! is_array( $group_by ) || array_diff( array_keys( $group_by ), array( 'field', 'how' ) ) || ! is_string( $group_by['field'] ?? null ) || 1 !== preg_match( \XB\Spec::GROUP_BY_FIELD_PATTERN, $group_by['field'] ) || array_key_exists( 'how', $group_by ) && ( ! is_string( $group_by['how'] ) || 1 !== preg_match( \XB\Spec::GROUP_BY_HOW_PATTERN, $group_by['how'] ) ) ) {
							throw new \DomainException( 'A Histeon query loop groupBy is invalid in ' . $owner_id . ': it is {field, how?} with a field path and a mode as text.' );
						}
					}
					$arguments = $source['args'] ?? array();
					if ( ! is_array( $arguments ) || array() !== $arguments && array_is_list( $arguments ) ) {
						throw new \DomainException( 'A Histeon query loop argument map is invalid in ' . $owner_id . '.' );
					}
					$allowed_arguments = $transfer_query_presets[ $preset_id ];
					foreach ( $arguments as $argument => $expression ) {
						if ( ! is_string( $argument ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $argument ) || ! is_string( $expression ) || is_array( $allowed_arguments ) && ! isset( $allowed_arguments[ $argument ] ) ) {
							throw new \DomainException( 'A Histeon query loop uses an unsupported argument in ' . $owner_id . ': ' . (string) $argument . '.' );
						}
						self::assert_transfer_expression( $expression, $roots, $owner_id );
					}
				} else {
					throw new \DomainException( 'A Histeon loop source kind is not portable in ' . $owner_id . '.' );
				}
				$variable = $attrs['as'] ?? 'item';
				$index_name = $attrs['index'] ?? null;
				if ( ! is_string( $variable ) || 1 !== preg_match( \XB\Spec::IDENTIFIER_PATTERN, $variable ) || null !== $index_name && ( ! is_string( $index_name ) || 1 !== preg_match( \XB\Spec::IDENTIFIER_PATTERN, $index_name ) ) ) {
					throw new \DomainException( 'A Histeon loop variable name is invalid.' );
				}
			} elseif ( 'xb/component' === $name ) {
				$props = $attrs['props'] ?? array();
				if ( ! is_array( $props ) ) {
					throw new \DomainException( 'A Histeon component props value is invalid.' );
				}
				foreach ( $props as $prop => $value ) {
					if ( ! is_string( $prop ) || 1 !== preg_match( \XB\Spec::IDENTIFIER_PATTERN, $prop ) ) {
						throw new \DomainException( 'A Histeon component prop name is invalid.' );
					}
					if ( is_array( $value ) && 1 === count( $value ) && is_string( $value['expr'] ?? null ) ) {
						self::assert_transfer_expression( $value['expr'], $roots, $owner_id );
					} elseif ( is_array( $value ) && 1 === count( $value ) && is_string( $value['tpl'] ?? null ) ) {
						self::assert_transfer_interpolations( $value['tpl'], $roots, $owner_id );
					} elseif ( ! is_null( $value ) && ! is_scalar( $value ) ) {
						throw new \DomainException( 'A Histeon component prop must be a JSON scalar, expression or template.' );
					}
				}
				$fill_names = array();
				foreach ( $block['innerBlocks'] as $fill ) {
					$fill_name = (string) ( $fill['attrs']['name'] ?? 'default' );
					if ( isset( $fill_names[ $fill_name ] ) ) {
						throw new \DomainException( 'A Histeon component slot is filled more than once.' );
					}
					$fill_names[ $fill_name ] = true;
				}
			}
			/** @var list<ParsedBlock> $children */
			$children = array_values( $block['innerBlocks'] );
			$child_roots = $roots;
			if ( 'xb/loop' === $name ) {
				$child_roots[(string) ( $attrs['as'] ?? 'item' )] = true;
				if ( is_string( $attrs['index'] ?? null ) ) {
					$child_roots[$attrs['index']] = true;
				}
			}
			$child_ctx = 'xb/element' === $name ? TransferMarkup::child_context( $ctx, (string) $attrs['tag'] ) : $ctx;
			$depth     = 'xb/loop' === $name ? $loop_depth + 1 : $loop_depth;
			// A loop's {:else} (its last block, a branch: check_branches allows no other) shows when there are no items:
			// the loop variable and index do not exist in it, so it keeps the loop's own scope.
			$last = end( $children );
			if ( 'xb/loop' === $name && is_array( $last ) && 'xb/branch' === ( $last['blockName'] ?? null ) ) {
				self::visit_transfer_blocks( array_slice( $children, 0, -1 ), $owner_kind, $owner_id, $name, $child_roots, $uids, $depth, $transfer_query_presets, $child_ctx, $asset_ids );
				self::visit_transfer_blocks( array( $last ), $owner_kind, $owner_id, $name, $roots, $uids, $depth, $transfer_query_presets, $child_ctx, $asset_ids );
			} else {
				self::visit_transfer_blocks( $children, $owner_kind, $owner_id, $name, $child_roots, $uids, $depth, $transfer_query_presets, $child_ctx, $asset_ids );
			}
		}
	}

	/** @param array<string, true> $roots */
	private static function assert_transfer_expression( string $source, array $roots, string $owner_id ): void {
		$expression = \XB\Expr\Parser::parse( $source );
		$visit = static function ( array $node ) use ( &$visit, $roots, $owner_id ): void {
			switch ( $node['type'] ?? null ) {
				case 'Literal':
					return;
				case 'Identifier':
					if ( ! isset( $roots[(string) ( $node['name'] ?? '' )] ) ) {
						throw new \DomainException( 'The expression scope is not portable in ' . $owner_id . ': ' . (string) ( $node['name'] ?? '' ) . '.' );
					}
					return;
				case 'Member':
					// `url.path` travels (doc 23 of Publish, item 189): on WP the path of the request, in Publish the path of the
					// page being built, the same on a static site. `url.query` does not: a static page has no query.
					if ( 'path' === ( $node['property'] ?? null ) && 'Identifier' === ( $node['object']['type'] ?? null ) && 'url' === ( $node['object']['name'] ?? null ) && ! isset( $roots['url'] ) ) {
						return;
					}
					$visit( $node['object'] );
					return;
				case 'Index':
					$visit( $node['object'] );
					$visit( $node['index'] );
					return;
				case 'Unary':
					$visit( $node['argument'] );
					return;
				case 'Conditional':
					$visit( $node['test'] );
					$visit( $node['consequent'] );
					$visit( $node['alternate'] );
					return;
				case 'Binary':
					$visit( $node['left'] );
					$visit( $node['right'] );
					return;
				case 'Array':
					foreach ( $node['elements'] as $child ) {
						$visit( $child );
					}
					return;
				case 'Object':
					foreach ( $node['properties'] as $property ) {
						$visit( $property['value'] );
					}
					return;
				case 'Call':
					$callee = $node['callee'] ?? array();
					if ( 'Member' !== ( $callee['type'] ?? null ) || ! in_array( $callee['property'] ?? null, self::TRANSFER_MODIFIERS, true ) ) {
						throw new \DomainException( 'The expression modifier is unavailable in Histeon CMS for ' . $owner_id . '.' );
					}
					$visit( $callee['object'] );
					foreach ( $node['args'] as $argument ) {
						$visit( $argument['value'] );
					}
					return;
				default:
					throw new \DomainException( 'The expression contains an unsupported node in ' . $owner_id . '.' );
			}
		};
		$visit( $expression );
	}

	/** @param array<string, true> $roots */
	private static function assert_transfer_interpolations( string $value, array $roots, string $owner_id, bool $inline_text = false ): void {
		$length = strlen( $value );
		for ( $i = 0; $i < $length; ++$i ) {
			if ( '\\' === $value[$i] && isset( $value[$i + 1] ) && ( '{' === $value[$i + 1] || '}' === $value[$i + 1] ) ) {
				++$i;
				continue;
			}
			if ( $inline_text && '<' === $value[$i] && isset( $value[$i + 1] ) && ( ctype_alpha( $value[$i + 1] ) || '/' === $value[$i + 1] ) ) {
				$quote = '';
				$end = $i + 1;
				for ( ; $end < $length; ++$end ) {
					$char = $value[$end];
					if ( '' !== $quote ) {
						if ( $char === $quote ) {
						$quote = '';
						}
					continue;
				}
				if ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( '>' === $char ) {
					break;
				}
				}
				if ( $end >= $length || 1 === preg_match( '/[{}]/', substr( $value, $i, $end - $i + 1 ) ) ) {
					throw new \DomainException( 'Inline markup has an invalid or dynamic attribute in ' . $owner_id . '.' );
				}
				$i = $end;
				continue;
			}
			if ( '{' === $value[$i] ) {
				$end = \XB\Expr\Template::closing_brace( $value, $i );
				self::assert_transfer_expression( substr( $value, $i + 1, $end - $i - 1 ), $roots, $owner_id );
				$i = $end;
			} elseif ( '}' === $value[$i] ) {
				throw new \DomainException( 'An unescaped closing brace is not portable in ' . $owner_id . '.' );
			}
		}
	}

	/**
	 * Second gate after TransferMarkup::check_text(): the inline markup of a text run read by WordPress's own tokenizer.
	 * Only the inline formatting a restricted author can write (Spec::TEXT_INLINE_HTML), with links limited to the
	 * transfer URL schemes (TransferMarkup::url_is_safe(); the processor returns decoded values). The first gate is
	 * stricter, so a refusal here means the two tokenizers read the text differently.
	 */
	private static function assert_inline_markup( string $content, string $owner_id ): void {
		if ( ! str_contains( $content, '<' ) ) {
			return;
		}
		$processor = new \WP_HTML_Tag_Processor( $content );
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if ( '#comment' === $type || '#funky-comment' === $type || '#cdata-section' === $type || '#processing-instruction' === $type || '#doctype' === $type || '#presumptuous-tag' === $type ) {
				throw new TransferMarkupViolation( 'text', 'Inline text from Histeon CMS contains markup that is not inline formatting in ' . $owner_id . '.' );
			}
			if ( '#tag' !== $type ) {
				continue;
			}
			$tag     = strtolower( (string) $processor->get_tag() );
			$allowed = \XB\Spec::TEXT_INLINE_HTML[ $tag ] ?? null;
			if ( null === $allowed ) {
				throw new TransferMarkupViolation( 'text', 'The inline element ' . $tag . ' is not allowed in text from Histeon CMS in ' . $owner_id . '.' );
			}
			foreach ( $processor->get_attribute_names_with_prefix( '' ) ?? array() as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( ! isset( $allowed[ $attribute ] ) || ! ( true === $value || is_string( $value ) ) ) {
					throw new TransferMarkupViolation( 'attribute', 'The inline attribute ' . $attribute . ' is not allowed in text from Histeon CMS in ' . $owner_id . '.' );
				}
				if ( 'href' === $attribute && ( ! is_string( $value ) || ! TransferMarkup::url_is_safe( $value ) ) ) {
					throw new TransferMarkupViolation( 'url', 'A link in text from Histeon CMS uses a URL scheme that is not allowed in ' . $owner_id . '.' );
				}
			}
		}
		if ( $processor->paused_at_incomplete_token() ) {
			throw new TransferMarkupViolation( 'text', 'Inline text from Histeon CMS ends inside a tag in ' . $owner_id . '.' );
		}
	}

	/**
	 * Runs a markup check and names the document in its message; the reason code is kept.
	 *
	 * @param callable(): void $check
	 */
	private static function with_owner( string $owner_id, callable $check ): void {
		try {
			$check();
		} catch ( TransferMarkupViolation $violation ) {
			throw new TransferMarkupViolation( $violation->reason, $violation->getMessage() . ' Document: ' . $owner_id . '.', $violation );
		}
	}

	/**
	 * The profile stores images as media entities, never as URLs hidden in CSS: an inline style must not load a resource
	 * or an active behaviour (G7, after the numeric-reference check G3). Kept by name for PageProfileAdapter.
	 */
	public static function assert_css_profile( string $css ): void {
		if ( TransferMarkup::style_loads_resources( TransferMarkup::decode_attribute( $css, 'The inline style' ) ) ) {
			throw new TransferMarkupViolation( 'style', 'The static page profile cannot transfer inline CSS that loads a resource (url(), image-set(), @import…) or an active behaviour.' );
		}
	}
}
