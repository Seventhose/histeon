<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB;

/** Constants from docs/spec/blocks.md shared by renderer, sanitizer and registries. */
final class Spec {

	/** Every XB block (the folders of blocks/). */
	public const BLOCK_NAMES = array( 'xb/element', 'xb/text', 'xb/if', 'xb/branch', 'xb/loop', 'xb/component', 'xb/slot', 'xb/slot-fill', 'xb/raw', 'xb/passthrough', 'xb/post-content' );


	public const UID_PATTERN           = '/^[a-z0-9]{8,16}$/';
	public const IDENTIFIER_PATTERN    = '/^[A-Za-z_$][A-Za-z0-9_$]*$/';
	/** The field and the mode of a grouped query loop (doc 23 of Publish, item 187), as packages/dsl/src/names.ts. */
	public const GROUP_BY_FIELD_PATTERN = '/^[A-Za-z0-9_$-]{1,64}(?:\.[A-Za-z0-9_$-]{1,64}){0,7}$/D';
	public const GROUP_BY_HOW_PATTERN   = '/^[^\p{Cc}\p{Cs}]{1,32}$/uD';
	public const COMPONENT_REF_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*(?:\/[a-z][a-z0-9]*(?:-[a-z][a-z0-9]*)*)?$/';
	public const SLOT_NAME_PATTERN     = '/^[a-z][a-zA-Z0-9_-]*$/';
	public const DEFAULT_SLOT          = 'default';

	public const VOID_ELEMENTS     = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
	public const RAW_TEXT_ELEMENTS = array( 'script', 'style', 'textarea' );

	/** Inline formatting allowed inside xb/text content (docs/spec/dsl.md §3.4), as a wp_kses() allowlist. */
	public const TEXT_INLINE_HTML = array(
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
		'abbr'   => array( 'title' => true ),
		'b'      => array(),
		'br'     => array(),
		'code'   => array(),
		'em'     => array(),
		'i'      => array(),
		'kbd'    => array(),
		'mark'   => array(),
		'q'      => array(),
		's'      => array(),
		'small'  => array(),
		'span'   => array(),
		'strong' => array(),
		'sub'    => array(),
		'sup'    => array(),
		'time'   => array( 'datetime' => true ),
		'u'      => array(),
		'wbr'    => array(),
	);

	/**
	 * Whether content contains XB blocks, matching the block parser's grammar (any whitespace after `<!--`).
	 * Never test for the literal '<!-- wp:xb/': a tab or a newline there still parses as a block.
	 */
	public static function has_xb_blocks( string $content ): bool {
		return 1 === preg_match( '/<!--\s+\/?wp:xb\//', $content );
	}

	/** Block names, in the order of docs/spec/blocks.md §3. */
	public const BLOCKS = array(
		'xb/element',
		'xb/text',
		'xb/if',
		'xb/branch',
		'xb/loop',
		'xb/component',
		'xb/slot',
		'xb/slot-fill',
		'xb/raw',
		'xb/passthrough',
		'xb/post-content',
	);
}
