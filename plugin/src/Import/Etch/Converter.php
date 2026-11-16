<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import\Etch;

use XB\Ai\Uids;
use XB\Security\MarkupPolicy;

/**
 * Blocks as saved by Etch → XB blocks — docs/spec/import-etch.md §2.
 * One converter per document: it collects that document's scripts, issues and custom fields for the report.
 */
final class Converter {

	private const MAX_DEPTH = 100;
	private const RAW_TEXT  = array( 'script', 'style', 'textarea' );

	public Expressions $expressions;
	/** @var list<string> Decoded element scripts, in document order. */
	public array $scripts = array();
	/** @var list<string> */
	public array $issues = array();
	/** @var array<string, int> */
	public array $counts = array(
		'etch'        => 0,
		'converted'   => 0,
		'passthrough' => 0,
		'kept'        => 0,
	);

	/**
	 * @param array<int, string>                                   $components Etch component post ID → XB component key.
	 * @param array<string, array{kind: string, id?: string, expr?: string, item?: string}> $loops Etch loop id or key → XB loop source;
	 *                                                                    `item` says what the loop repeats: post (default), term or data.
	 * @param array<int, list<string>>                             $image_props Etch component post ID → its props that a dynamic
	 *                                                                    image uses as attachment ID (image_props()).
	 */
	public function __construct( private readonly array $components, private readonly array $loops, private readonly array $image_props = array() ) {
		$this->expressions = new Expressions();
	}

	/**
	 * The props of a component that its dynamic images use as attachment ID (`mediaId: "{props.image}"`). XB has no
	 * image from an ID inside an expression: such a prop receives the image itself (`{item.image}` where Etch passed
	 * `{item.image.id}`), and the image reads its file from it.
	 *
	 * @param array<array<string, mixed>> $blocks The component's blocks, parse_blocks() output.
	 * @return list<string>
	 */
	public static function image_props( array $blocks ): array {
		$out  = array();
		$walk = static function ( array $list, int $depth ) use ( &$walk, &$out ): void {
			foreach ( $list as $block ) {
				if ( ! is_array( $block ) || $depth > self::MAX_DEPTH ) {
					continue;
				}
				$media = $block['attrs']['attributes']['mediaId'] ?? null;
				if ( 'etch/dynamic-image' === ( $block['blockName'] ?? null ) && is_string( $media ) && 1 === preg_match( '/^\{\s*props\.([A-Za-z_$][A-Za-z0-9_$]*)\s*\}$/', $media, $m ) ) {
					$out[ $m[1] ] = true;
				}
				$walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $depth + 1 );
			}
		};
		$walk( $blocks, 0 );
		return array_keys( $out );
	}

	/**
	 * @param array<array<string, mixed>> $blocks parse_blocks() output.
	 * @return list<array<string, mixed>> XB blocks for Blocks::serialize().
	 */
	public function convert( array $blocks ): array {
		return $this->list( $blocks, '', array( 'this' ), 0 );
	}

	/**
	 * All issues of this document (blocks and expressions).
	 *
	 * @return list<string>
	 */
	public function report(): array {
		return array_values( array_unique( array_merge( $this->issues, $this->expressions->issues ) ) );
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param list<string>                $roots Names holding a post in this scope.
	 * @return list<array<string, mixed>>
	 */
	private function list( array $blocks, string $parent, array $roots, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			$this->issues[] = sprintf( /* translators: %d: maximum nesting depth */ __( 'Annidamento oltre %d livelli: contenuto più profondo omesso', 'xb' ), self::MAX_DEPTH );
			return array();
		}
		$out = array();
		foreach ( $blocks as $block ) {
			$converted = $this->block( $block, $parent, $roots, $depth );
			if ( null !== $converted ) {
				$out[] = $converted;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 * @param list<string>         $roots
	 * @return array<string, mixed>|null
	 */
	private function block( array $block, string $parent, array $roots, int $depth ): ?array {
		$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : null;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();

		if ( null === $name ) {
			$html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			if ( '' === trim( $html ) ) {
				return null;
			}
			if ( self::only_comments( $html ) ) {
				// Comments print nothing, but plugins look for them in the saved content (a cookie policy marked by
				// its plugin): left outside the blocks, word for word, where a block attribute would encode them.
				return array(
					'blockName'    => null,
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $html,
					'innerContent' => array( $html ),
				);
			}
			$this->issues[] = __( 'HTML fuori dai blocchi importato come blocco HTML', 'xb' );
			return self::xb( 'xb/raw', array( 'content' => $html ) );
		}
		if ( 'core/post-content' === $name ) {
			// The post's content without the layout wrapper core adds: what Etch printed, and what XB prints.
			++$this->counts['converted'];
			return self::xb( 'xb/post-content', array() );
		}
		if ( ! str_starts_with( $name, 'etch/' ) ) {
			// Core and third-party blocks render as they are; Etch blocks nested inside them are converted too.
			++$this->counts['passthrough'];
			$block['innerBlocks'] = array_map( fn ( array $b ): array => $this->nested( $b, $roots, $depth + 1 ), $inner );
			return array(
				'blockName'   => 'xb/passthrough',
				'attrs'       => array( 'uid' => Uids::fresh() ),
				'innerBlocks' => array( $block ),
			);
		}

		++$this->counts['etch'];
		$converted = match ( $name ) {
			'etch/element'       => $this->element( $attrs, $inner, $roots, $depth ),
			'etch/text'          => $this->text( $attrs, $parent, $roots ),
			'etch/loop'          => $this->loop( $attrs, $inner, $roots, $depth ),
			'etch/component'     => $this->component( $attrs, $inner, $roots ),
			'etch/dynamic-image' => $this->image( $attrs, $roots ),
			'etch/condition'     => $this->condition( $attrs, $inner, $parent, $roots, $depth ),
			'etch/raw-html'      => $this->raw_html( $attrs, $roots ),
			default              => null,
		};
		if ( null === $converted ) {
			++$this->counts['kept'];
			$this->issues[] = sprintf( /* translators: %s: block name */ __( 'Blocco %s non convertito: lasciato com\'è (funziona solo finché Etch è attivo)', 'xb' ), $name );
			return array(
				'blockName'   => 'xb/passthrough',
				'attrs'       => array( 'uid' => Uids::fresh() ),
				'innerBlocks' => array( $block ),
			);
		}
		++$this->counts['converted'];
		return $converted;
	}

	/**
	 * A block inside a foreign block: converted in place (the parent's innerContent keeps one slot per child).
	 *
	 * @param array<string, mixed> $block
	 * @param list<string>         $roots
	 * @return array<string, mixed>
	 */
	private function nested( array $block, array $roots, int $depth ): array {
		$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		if ( str_starts_with( $name, 'etch/' ) ) {
			return $this->block( $block, '', $roots, $depth ) ?? $block;
		}
		$block['innerBlocks'] = array_map( fn ( array $b ): array => $this->nested( $b, $roots, $depth + 1 ), is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
		return $block;
	}

	/**
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>
	 */
	private function element( array $attrs, array $inner, array $roots, int $depth ): array {
		$tag = is_string( $attrs['tag'] ?? null ) ? $attrs['tag'] : 'div';
		if ( 1 !== preg_match( MarkupPolicy::TAG_PATTERN, $tag ) ) {
			$this->issues[] = sprintf( /* translators: %s: HTML tag */ __( 'Tag non valido "%s": uso div', 'xb' ), mb_substr( $tag, 0, 40 ) );
			$tag = 'div';
		}
		$out = array( 'tag' => $tag );
		if ( is_string( $attrs['metadata']['name'] ?? null ) && '' !== $attrs['metadata']['name'] ) {
			$out['label'] = mb_substr( $attrs['metadata']['name'], 0, 200 );
		}
		$attributes = $this->attributes( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array(), $roots );
		if ( array() !== $attributes ) {
			$out['attributes'] = $attributes;
		}
		$script = $attrs['script'] ?? null;
		if ( is_array( $script ) && is_string( $script['code'] ?? null ) && '' !== $script['code'] ) {
			$code = base64_decode( $script['code'], true );
			if ( false === $code || ! mb_check_encoding( $code, 'UTF-8' ) ) {
				$this->issues[] = __( 'Script di un elemento non leggibile: non importato', 'xb' );
			} else {
				$this->scripts[] = sprintf( "/* Da Etch: script di <%s>%s */\n", $tag, isset( $out['label'] ) ? ' «' . str_replace( '*/', '* /', $out['label'] ) . '»' : '' ) . $code;
			}
		}
		return self::xb( 'xb/element', $out, $this->list( $inner, $tag, $roots, $depth + 1 ) );
	}

	/**
	 * @param array<mixed> $source
	 * @param list<string> $roots
	 * @return array<string, string>
	 */
	private function attributes( array $source, array $roots ): array {
		$out = array();
		foreach ( $source as $name => $value ) {
			if ( ! is_string( $name ) || 1 !== preg_match( MarkupPolicy::ATTRIBUTE_PATTERN, $name ) ) {
				$this->issues[] = sprintf( /* translators: %s: attribute name */ __( 'Attributo con nome non valido omesso: %s', 'xb' ), mb_substr( (string) $name, 0, 40 ) );
				continue;
			}
			if ( is_bool( $value ) ) {
				if ( $value ) {
					$out[ $name ] = '';
				}
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				$this->issues[] = sprintf( /* translators: %s: attribute name */ __( 'Attributo %s con valore non testuale omesso', 'xb' ), $name );
				continue;
			}
			$out[ $name ] = $this->expressions->template( (string) $value, $roots );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @param list<string>         $roots
	 * @return array<string, mixed>
	 */
	private function text( array $attrs, string $parent, array $roots ): array {
		$content = is_string( $attrs['content'] ?? null ) ? $attrs['content'] : '';
		if ( in_array( $parent, self::RAW_TEXT, true ) ) {
			return self::xb( 'xb/text', array( 'content' => $content, 'verbatim' => true ) );
		}
		return self::xb( 'xb/text', array( 'content' => $this->expressions->template( $content, $roots ) ) );
	}

	/**
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>|null
	 */
	private function loop( array $attrs, array $inner, array $roots, int $depth ): ?array {
		$id     = is_string( $attrs['loopId'] ?? null ) ? $attrs['loopId'] : '';
		$target = is_string( $attrs['target'] ?? null ) ? trim( $attrs['target'] ) : '';
		if ( '' === $id && '' !== $target ) {
			return $this->target_loop( $target, $attrs, $inner, $roots, $depth );
		}
		$source = $this->loops[ $id ] ?? null;
		if ( null === $source ) {
			$this->issues[] = sprintf( /* translators: %s: query ID */ __( 'Loop con query sconosciuta "%s": non convertito', 'xb' ), mb_substr( $id, 0, 40 ) );
			return null;
		}
		$as = is_string( $attrs['itemId'] ?? null ) && 1 === preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $attrs['itemId'] ) ? $attrs['itemId'] : 'item';
		if ( 'expr' === $source['kind'] ) {
			$loop = array( 'kind' => 'expr', 'expr' => (string) ( $source['expr'] ?? '[]' ) );
		} else {
			$loop = array( 'kind' => 'preset', 'id' => (string) ( $source['id'] ?? '' ) );
			$args = array();
			foreach ( is_array( $attrs['loopParams'] ?? null ) ? $attrs['loopParams'] : array() as $key => $value ) {
				$arg = ltrim( (string) $key, '$' );
				if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $arg ) ) {
					continue;
				}
				$expr = is_string( $value ) && str_contains( $value, '{' ) && \XB\Expr\Template::is_single( $value )
					? $this->expressions->expression( substr( $value, 1, -1 ), $roots )
					: (string) wp_json_encode( $value );
				if ( null !== $expr ) {
					$args[ $arg ] = $expr;
				}
			}
			if ( array() !== $args ) {
				$loop['args'] = $args;
			}
		}
		$out = array(
			'source' => $loop,
			'as'     => $as,
		);
		// Only a loop over posts adds a post root: the keys of static data (an FAQ's question and answer) and the
		// fields of a term are not post fields, and must not be reported as fields added by plugins.
		$item  = $source['item'] ?? 'post';
		$scope = 'post' === $item ? array_values( array_unique( array_merge( $roots, array( $as ) ) ) ) : array_values( array_diff( $roots, array( $as ) ) );
		return self::xb( 'xb/loop', $out, $this->list( $inner, '', $scope, $depth + 1 ) );
	}

	/**
	 * A loop over a value instead of a query (`target: "this.tags"`): an expression source. A target that reads the
	 * results of a query by its key (`posts.length()…`) has no XB form: kept and reported.
	 *
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>|null
	 */
	private function target_loop( string $target, array $attrs, array $inner, array $roots, int $depth ): ?array {
		$root = 1 === preg_match( '/^\s*([A-Za-z_$][A-Za-z0-9_$]*)/', $target, $m ) ? $m[1] : '';
		if ( '' === $root || ( ! in_array( $root, $roots, true ) && isset( $this->loops[ $root ] ) ) ) {
			$this->issues[] = sprintf( /* translators: %s: Etch expression */ __( 'Loop sul valore %s, che legge i risultati di una query: non convertito', 'xb' ), mb_substr( $target, 0, 80 ) );
			return null;
		}
		$expr = $this->expressions->expression( $target, $roots );
		if ( null === $expr ) {
			return null;
		}
		$as = is_string( $attrs['itemId'] ?? null ) && 1 === preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $attrs['itemId'] ) ? $attrs['itemId'] : 'item';
		// What a value holds is not known (terms, data, posts): its fields are not reported as fields added by plugins.
		$scope = array_values( array_diff( $roots, array( $as ) ) );
		return self::xb(
			'xb/loop',
			array(
				'source' => array(
					'kind' => 'expr',
					'expr' => $expr,
				),
				'as'     => $as,
			),
			$this->list( $inner, '', $scope, $depth + 1 )
		);
	}

	/**
	 * `etch/condition` → `xb/if` with one `xb/branch`: Etch saves the test as a tree of {leftHand, operator,
	 * rightHand}, whose leaves are expressions in Etch's syntax (paths, numbers, quoted strings).
	 *
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>|null Null when the test has no XB form (the block is kept and reported).
	 */
	private function condition( array $attrs, array $inner, string $parent, array $roots, int $depth ): ?array {
		$source = self::test( $attrs['condition'] ?? null, 0 );
		if ( null === $source ) {
			$this->issues[] = __( 'Condizione di Etch con una forma sconosciuta', 'xb' );
			return null;
		}
		$test = $this->expressions->expression( $source, $roots );
		if ( null === $test ) {
			return null;
		}
		return self::xb( 'xb/if', array(), array( self::xb( 'xb/branch', array( 'test' => $test ), $this->list( $inner, $parent, $roots, $depth + 1 ) ) ) );
	}

	/** Etch's comparison operators and XB's: XB's == and != are already strict (docs/spec/dsl.md §4.2). */
	private const OPERATORS = array(
		'=='  => '==',
		'===' => '==',
		'!='  => '!=',
		'!==' => '!=',
		'<'   => '<',
		'<='  => '<=',
		'>'   => '>',
		'>='  => '>=',
		'&&'  => '&&',
		'||'  => '||',
	);

	/** A condition tree as one expression in Etch's syntax, or null when a node is not one Etch writes. */
	private static function test( mixed $node, int $depth ): ?string {
		if ( $depth > 20 ) {
			return null;
		}
		if ( is_int( $node ) || is_float( $node ) ) {
			return (string) $node;
		}
		if ( is_bool( $node ) ) {
			return $node ? 'true' : 'false';
		}
		if ( is_string( $node ) ) {
			return '' === trim( $node ) ? null : trim( $node );
		}
		if ( ! is_array( $node ) || ! is_string( $node['operator'] ?? null ) ) {
			return null;
		}
		$left = self::test( $node['leftHand'] ?? null, $depth + 1 );
		if ( null === $left ) {
			return null;
		}
		$left = self::group( $left );
		if ( 'isTruthy' === $node['operator'] ) {
			return $left;
		}
		if ( 'isFalsy' === $node['operator'] ) {
			return '!' . $left;
		}
		$op    = self::OPERATORS[ $node['operator'] ] ?? null;
		$right = self::test( $node['rightHand'] ?? null, $depth + 1 );
		return null === $op || null === $right ? null : $left . ' ' . $op . ' ' . self::group( $right );
	}

	/** Parentheses around an operand that is more than a path, a number or a quoted string. */
	private static function group( string $operand ): string {
		return 1 === preg_match( '/^(?:[A-Za-z_$][\w$]*(?:\s*\.\s*[A-Za-z_$][\w$]*)*|-?\d+(?:\.\d+)?|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')$/', $operand ) ? $operand : '(' . $operand . ')';
	}

	/**
	 * `etch/raw-html`: without expressions it is `xb/raw`, the same HTML. With one expression and nothing else it
	 * becomes text: XB prints values escaped, so HTML inside the value shows as text (reported, to check).
	 *
	 * @param array<string, mixed> $attrs
	 * @param list<string>         $roots
	 * @return array<string, mixed>|null
	 */
	private function raw_html( array $attrs, array $roots ): ?array {
		$content = is_string( $attrs['content'] ?? null ) ? $attrs['content'] : '';
		if ( ! str_contains( $content, '{' ) ) {
			return self::xb( 'xb/raw', array( 'content' => $content ) );
		}
		if ( ! \XB\Expr\Template::is_single( trim( $content ) ) ) {
			$this->issues[] = __( 'HTML grezzo con espressioni e markup insieme: Histeon non ha un equivalente', 'xb' );
			return null;
		}
		$this->issues[] = sprintf( /* translators: %s: expression */ __( 'HTML grezzo %s convertito in testo: se il valore contiene HTML, si vede come testo', 'xb' ), trim( $content ) );
		return self::xb( 'xb/text', array( 'content' => $this->expressions->template( trim( $content ), $roots ) ) );
	}

	/**
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>|null
	 */
	private function component( array $attrs, array $inner, array $roots ): ?array {
		$ref = (int) ( $attrs['ref'] ?? 0 );
		$key = $this->components[ $ref ] ?? null;
		if ( null === $key ) {
			$this->issues[] = sprintf( /* translators: %d: component ID */ __( 'Componente #%d sconosciuto: non convertito', 'xb' ), $ref );
			return null;
		}
		$out   = array( 'ref' => $key );
		$props = array();
		foreach ( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array() as $name => $value ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name ) || in_array( $name, array( '__proto__', 'constructor', 'prototype' ), true ) ) {
				continue;
			}
			if ( in_array( $name, $this->image_props[ $ref ] ?? array(), true ) ) {
				$image = $this->image_prop( $value, $key, $name, $roots );
				if ( null !== $image ) {
					$props[ $name ] = $image;
				}
			} elseif ( is_string( $value ) && \XB\Expr\Template::is_single( $value ) ) {
				$expr = $this->expressions->expression( substr( $value, 1, -1 ), $roots );
				if ( null !== $expr ) {
					$props[ $name ] = array( 'expr' => $expr );
				}
			} elseif ( is_string( $value ) && str_contains( $value, '{' ) ) {
				$props[ $name ] = array( 'tpl' => $this->expressions->template( $value, $roots ) );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$props[ $name ] = $value;
			}
		}
		if ( array() !== $props ) {
			$out['props'] = $props;
		}
		if ( array() !== $inner ) {
			$this->issues[] = sprintf( /* translators: %s: component name */ __( 'Contenuto dentro il componente «%s»: importato nello slot predefinito, da verificare', 'xb' ), $key );
			return self::xb( 'xb/component', $out, array( self::xb( 'xb/slot-fill', array( 'name' => 'default' ), $this->list( $inner, '', $roots, 1 ) ) ) );
		}
		return self::xb( 'xb/component', $out );
	}

	/**
	 * The value given to a component prop that its dynamic images use as attachment ID: the image itself.
	 * `{item.image.id}` → `item.image`; anything else is reported (an ID written by hand has no image in XB).
	 *
	 * @param list<string> $roots
	 * @return array{expr: string}|null
	 */
	private function image_prop( mixed $value, string $component, string $name, array $roots ): ?array {
		$expr = is_string( $value ) && \XB\Expr\Template::is_single( $value ) ? $this->expressions->expression( substr( $value, 1, -1 ), $roots ) : null;
		if ( null !== $expr && 1 === preg_match( '/^(.+)\.id$/s', $expr, $m ) ) {
			return array( 'expr' => $m[1] );
		}
		if ( null !== $expr || ( is_scalar( $value ) && '' !== (string) $value ) ) {
			$this->issues[] = sprintf( /* translators: 1: component key, 2: prop name */ __( 'Immagine passata per ID alla prop %2$s del componente «%1$s»: da scegliere di nuovo nel builder', 'xb' ), $component, $name );
		}
		return null;
	}

	/**
	 * Images by attachment ID: the URLs are read once, at import time (they do not change). An ID that is an
	 * expression (`{this.image.id}`, `{props.image}`) becomes `src` on the image itself, at the size Etch printed
	 * (`maximumSize`): the renderer adds width, height, srcset and sizes from the attachment, as Etch did.
	 *
	 * @param array<string, mixed> $attrs
	 * @param list<string>         $roots
	 * @return array<string, mixed>|null
	 */
	private function image( array $attrs, array $roots ): ?array {
		$source = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
		$media  = $source['mediaId'] ?? null;
		$size   = is_string( $source['maximumSize'] ?? null ) && in_array( $source['maximumSize'], get_intermediate_image_sizes(), true ) ? $source['maximumSize'] : 'full';
		// Etch's own settings, not HTML attributes.
		unset( $source['mediaId'], $source['maximumSize'], $source['useSrcSet'] );
		$tag = is_string( $attrs['tag'] ?? null ) ? $attrs['tag'] : 'img';
		if ( 'img' === $tag && null === $media && is_string( $source['src'] ?? null ) && '' !== $source['src'] ) {
			// No attachment: the image is its src (`{this.author.avatar}`), like an element.
			return $this->image_element( $this->attributes( $source, $roots ) + array( 'alt' => '' ), $attrs );
		}
		if ( 'img' === $tag && is_string( $media ) && \XB\Expr\Template::is_single( trim( $media ) ) ) {
			$expr = $this->expressions->expression( substr( trim( $media ), 1, -1 ), $roots );
			if ( null === $expr ) {
				return null;
			}
			// `x.image.id` → the image `x.image`; `props.image` already holds the image (image_prop()).
			$image = 1 === preg_match( '/^(.+)\.id$/s', $expr, $m ) ? $m[1] : ( 1 === preg_match( '/^props\.[A-Za-z_$][A-Za-z0-9_$]*$/', $expr ) ? $expr : null );
			if ( null !== $image ) {
				$attributes = $this->attributes( $source, $roots );
				return $this->image_element( array( 'src' => '{' . $image . '.' . ( 'full' === $size ? 'url' : $size ) . '}' ) + $attributes + array( 'alt' => '{' . $image . '.alt}' ), $attrs );
			}
		}
		if ( 'img' !== $tag || ! is_numeric( $media ) || ! wp_attachment_is_image( (int) $media ) ) {
			$this->issues[] = sprintf( /* translators: 1: HTML tag, 2: media reference */ __( 'Immagine dinamica non convertita (tag %1$s, media %2$s)', 'xb' ), $tag, is_scalar( $media ) ? (string) $media : '?' );
			return null;
		}
		$id         = (int) $media;
		$attributes = $this->attributes( $source, $roots );
		/** @var mixed $file SVGs and some uploads have no width or height. */
		$file       = wp_get_attachment_image_src( $id, $size );
		$attributes = array_merge(
			array_filter(
				array(
					'src'    => (string) wp_get_attachment_image_url( $id, $size ),
					'srcset' => 'full' === $size || ! is_array( $file ) ? (string) wp_get_attachment_image_srcset( $id, $size ) : \XB\Render\Renderer::srcset_up_to( (string) wp_get_attachment_image_srcset( $id, $size ), (int) $file[1] ),
					'sizes'  => (string) wp_get_attachment_image_sizes( $id, $size ),
					'width'  => is_array( $file ) && (int) $file[1] > 0 ? (string) $file[1] : '',
					'height' => is_array( $file ) && (int) $file[2] > 0 ? (string) $file[2] : '',
					'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				),
				static fn ( string $v ): bool => '' !== $v
			),
			$attributes
		);
		return $this->image_element( $attributes + array( 'alt' => '' ), $attrs );
	}

	/**
	 * @param array<string, string> $attributes
	 * @param array<string, mixed>  $attrs
	 * @return array<string, mixed>
	 */
	private function image_element( array $attributes, array $attrs ): array {
		$out = array(
			'tag'        => 'img',
			'attributes' => $attributes,
		);
		if ( is_string( $attrs['metadata']['name'] ?? null ) && '' !== $attrs['metadata']['name'] ) {
			$out['label'] = mb_substr( $attrs['metadata']['name'], 0, 200 );
		}
		return self::xb( 'xb/element', $out );
	}

	/** HTML made of comments and white space only (no `-->` inside a comment, nothing between them). */
	private static function only_comments( string $html ): bool {
		return 1 === preg_match( '/^(?:\s*<!--(?!-?>)(?:(?!-->).)*-->)+\s*$/s', $html ) && 1 !== preg_match( '/<!--\s*\/?wp:/', $html );
	}

	/**
	 * @param array<string, mixed>        $attrs
	 * @param list<array<string, mixed>>  $children
	 * @return array<string, mixed>
	 */
	private static function xb( string $name, array $attrs, array $children = array() ): array {
		return array(
			'blockName'   => $name,
			'attrs'       => array( 'uid' => Uids::fresh() ) + $attrs,
			'innerBlocks' => $children,
		);
	}
}
