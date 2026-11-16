<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Etch;

use XB\Expr\SyntaxError;
use XB\Expr\Template;
use XB\Security\MarkupPolicy;
use XB\Spec;

/**
 * XB blocks → blocks in the form Etch saves them: the inverse of Import\Etch\Converter (docs/spec/export-etch.md §2).
 * One converter per document; it collects that document's issues for the report.
 *
 * What has no observed Etch equivalent (conditions, slots) is left out, never guessed. Everything written is
 * something the importer reads back: valid tag and attribute names, identifiers, expressions XB parses.
 */
final class Converter {

	private const MAX_DEPTH  = 100;
	/** A block comment inside raw HTML would become a real block once the post is parsed again. */
	private const DELIMITER  = '/<!--\s*\/?\s*wp:/i';
	private const IDENTIFIER = '/^[A-Za-z_$][A-Za-z0-9_$]*$/';
	/** The importer's header before each script it moved from an element to the document (Import\Etch\Converter). */
	private const SCRIPT_HEADER = '/(?:^|\n\n)\/\* Da Etch: script di <([a-zA-Z][a-zA-Z0-9-]*)>(?: «(.*?)»)? \*\/\n/u';

	public Expressions $expressions;
	/** @var list<string> */
	public array $issues = array();
	/** @var list<string> */
	private array $changes = array();
	/** @var array<string, int> */
	public array $counts = array(
		'blocks'  => 0,
		'omitted' => 0,
	);

	/**
	 * @param array<string, int> $components XB component key → the wp_block post that defines it.
	 */
	public function __construct( private readonly array $components, private readonly Queries $queries, private readonly Tokens $tokens, private readonly bool $trusted ) {
		$this->expressions = new Expressions();
	}

	/**
	 * @param array<array<string, mixed>> $blocks parse_blocks() output of an XB document.
	 * @return list<array<string, mixed>> Blocks for serialize_blocks().
	 */
	public function convert( array $blocks ): array {
		return $this->list( $blocks, '', array( 'this' ), 0, true );
	}

	/** @return list<string> */
	public function report(): array {
		return array_values( array_unique( array_merge( $this->issues, $this->expressions->issues ) ) );
	}

	/**
	 * What the export changed on the way to Etch (B2): loops that became Etch queries and rewritten expressions, each
	 * once, so the report says what to check in Etch besides what was left out.
	 *
	 * @return list<string>
	 */
	public function changes(): array {
		return array_values( array_unique( array_merge( $this->changes, $this->expressions->changes ) ) );
	}

	/**
	 * The document's JavaScript on its elements, where Etch keeps scripts. A part the importer moved from an element
	 * (its header names the tag and label) goes back to the first such element; the rest goes to the first element.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @return int How many scripts were written (0 when there was no element to hold them).
	 */
	public function place_script( array &$blocks, string $script ): int {
		if ( '' === trim( $script ) ) {
			return 0;
		}
		if ( ! $this->trusted ) {
			$this->issues[] = __( 'JavaScript non esportato: l\'autore non ha il permesso unfiltered_html (lo stesso limite dell\'importazione)', 'xb' );
			return 0;
		}
		$elements = array();
		self::elements( $blocks, array(), $elements );
		if ( array() === $elements ) {
			$this->issues[] = __( 'JavaScript non esportato: il documento non ha un elemento a cui legarlo', 'xb' );
			return 0;
		}
		$parts = array();
		preg_match_all( self::SCRIPT_HEADER, $script, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		$cursor = 0;
		$owner  = null;
		foreach ( $m as $match ) {
			$parts[] = array( $owner, substr( $script, $cursor, $match[0][1] - $cursor ) );
			$owner   = array( $match[1][0], isset( $match[2] ) && -1 !== $match[2][1] ? $match[2][0] : '' );
			$cursor  = $match[0][1] + strlen( $match[0][0] );
		}
		$parts[] = array( $owner, substr( $script, $cursor ) );

		$codes = array();
		$taken = array();
		foreach ( $parts as [ $wanted, $code ] ) {
			if ( '' === trim( $code ) ) {
				continue;
			}
			$target = 0;
			if ( null !== $wanted ) {
				foreach ( $elements as $n => $element ) {
					if ( ! isset( $taken[ $n ] ) && $element['tag'] === $wanted[0] && str_replace( '*/', '* /', $element['label'] ) === $wanted[1] ) {
						$target = $n;
						break;
					}
				}
			}
			$taken[ $target ]  = true;
			$codes[ $target ] = isset( $codes[ $target ] ) ? $codes[ $target ] . "\n\n" . $code : $code;
		}
		foreach ( $codes as $n => $code ) {
			if ( strlen( $code ) > \XB\Scripts\Scripts::MAX_BYTES ) {
				$this->issues[] = __( 'JavaScript oltre la dimensione massima: non esportato', 'xb' );
				return 0;
			}
			$node = &$blocks;
			foreach ( $elements[ $n ]['path'] as $depth => $index ) {
				$node = &$node[ $index ];
				if ( $depth < count( $elements[ $n ]['path'] ) - 1 ) {
					$node = &$node['innerBlocks'];
				}
			}
			$node['attrs']['script'] = array(
				'id'   => substr( md5( $code ), 0, 8 ),
				'code' => base64_encode( $code ),
			);
			unset( $node );
		}
		return count( $codes );
	}

	/**
	 * @param array<array<string, mixed>>                                 $blocks
	 * @param list<int>                                                   $path
	 * @param list<array{path: list<int>, tag: string, label: string}>    $out
	 */
	private static function elements( array $blocks, array $path, array &$out ): void {
		foreach ( $blocks as $i => $block ) {
			if ( 'etch/element' === ( $block['blockName'] ?? null ) ) {
				$out[] = array(
					'path'  => array_merge( $path, array( (int) $i ) ),
					'tag'   => (string) ( $block['attrs']['tag'] ?? '' ),
					'label' => (string) ( $block['attrs']['metadata']['name'] ?? '' ),
				);
				self::elements( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), array_merge( $path, array( (int) $i ) ), $out );
			}
		}
	}

	/**
	 * @param array<array<string, mixed>> $blocks
	 * @param list<string>                $roots Names that hold a post here.
	 * @return list<array<string, mixed>>
	 */
	private function list( array $blocks, string $parent, array $roots, int $depth, bool $top = false ): array {
		if ( $depth > self::MAX_DEPTH ) {
			$this->issues[] = sprintf( /* translators: %d: maximum nesting depth */ __( 'Annidamento oltre %d livelli: contenuto più profondo omesso', 'xb' ), self::MAX_DEPTH );
			return array();
		}
		$out = array();
		foreach ( $blocks as $block ) {
			foreach ( $this->inline( $block, $parent ) as $part ) {
				$converted = $this->block( $part, $parent, $roots, $depth, $top );
				if ( null !== $converted ) {
					$out[] = $converted;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 * @param list<string>         $roots
	 * @return array<string, mixed>|null
	 */
	private function block( array $block, string $parent, array $roots, int $depth, bool $top ): ?array {
		$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : null;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$inner = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();

		if ( null === $name ) {
			$html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			return '' === trim( $html ) ? null : $this->raw( $html, $top );
		}
		++$this->counts['blocks'];
		$converted = match ( $name ) {
			'xb/element'      => $this->element( $attrs, $inner, $roots, $depth ),
			'xb/text'         => $this->text( $attrs, $parent, $roots ),
			'xb/loop'         => $this->loop( $attrs, $inner, $roots, $depth ),
			'xb/component'    => $this->component( $attrs, $inner, $roots ),
			'xb/raw'          => $this->raw( is_string( $attrs['content'] ?? null ) ? $attrs['content'] : '', $top ),
			'xb/passthrough'  => array() === $inner ? null : $this->foreign( $inner[0], $roots, $depth + 1 ),
			'xb/post-content' => self::etch( 'core/post-content', array() ),
			'xb/if'           => $this->omit( __( 'Condizione (xb/if) non esportata con il suo contenuto: nei dati di Etch osservati le condizioni non comparivano', 'xb' ) ),
			'xb/slot'         => $this->omit( __( 'Slot (xb/slot) non esportato: nei dati di Etch osservati gli slot non comparivano', 'xb' ) ),
			default           => str_starts_with( $name, 'xb/' )
				? $this->omit( sprintf( /* translators: %s: block name */ __( 'Blocco %s fuori posto o sconosciuto: non esportato', 'xb' ), $name ) )
				: $this->foreign( $block, $roots, $depth + 1 ),
		};
		if ( null === $converted ) {
			++$this->counts['omitted'];
		}
		return $converted;
	}

	/** Attributes that take a URL: Histeon passes their dynamic values through esc_url when it renders them. */
	private const URL_ATTRIBUTES = array( 'href', 'src', 'srcset', 'imagesrcset', 'action', 'formaction', 'poster', 'xlink:href', 'cite', 'background', 'data', 'codebase', 'ping', 'manifest', 'longdesc', 'usemap', 'archive', 'classid', 'icon' );

	/**
	 * Why an attribute of a document by an author without unfiltered_html cannot go to Etch, or null. The markup
	 * policy accepts a URL or a style whose dangerous part is computed (`{'javascript:…'}`, `javascript{':'}…`)
	 * because the Histeon renderer escapes dynamic URLs and styles; Etch renders the exported value itself, so only
	 * what is safe as written goes: a fixed safe scheme or a relative path before the first expression, or one
	 * plain path (`{this.url}`), never a string literal inside an expression, never a computed style.
	 */
	private static function unsafe_for_etch( string $name, string $value ): ?string {
		if ( ! str_contains( $value, '{' ) ) {
			return null;
		}
		if ( 'style' === $name ) {
			return __( 'stile calcolato', 'xb' );
		}
		if ( ! in_array( $name, self::URL_ATTRIBUTES, true ) && ! str_starts_with( $name, 'on' ) ) {
			return null;
		}
		return self::unsafe_url_template( $value );
	}

	private static function unsafe_url_template( string $value ): ?string {
		if ( 1 === preg_match( '/\{[^}]*[\'"`]/', $value ) ) {
			return __( 'testo letterale dentro un\'espressione', 'xb' );
		}
		$prefix = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( substr( $value, 0, (int) strpos( $value, '{' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( '' === $prefix ) {
			return 1 === preg_match( '/^\{\s*[A-Za-z_$][\w$]*(?:\.[\w$]+)*\s*\}$/', $value ) ? null : __( 'indirizzo calcolato', 'xb' );
		}
		return 1 === preg_match( '#^(?:https?://|/|\#|\?|\.{1,2}/|mailto:|tel:)#', $prefix ) ? null : __( 'indirizzo calcolato', 'xb' );
	}

	/** A prop of a restricted document that could become a dangerous URL in a component's attribute, or null. */
	private static function unsafe_prop( mixed $value ): ?string {
		if ( is_string( $value ) ) {
			$plain = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			return 1 === preg_match( '/^(?:javascript|vbscript|data):/', $plain ) ? __( 'indirizzo con uno schema pericoloso', 'xb' ) : null;
		}
		if ( is_array( $value ) && is_string( $value['expr'] ?? null ) ) {
			return 1 === preg_match( '/[\'"`]/', $value['expr'] ) ? __( 'testo letterale dentro un\'espressione', 'xb' ) : null;
		}
		if ( is_array( $value ) && is_string( $value['tpl'] ?? null ) && str_contains( $value['tpl'], '{' ) ) {
			return self::unsafe_url_template( $value['tpl'] );
		}
		return null;
	}

	private function omit( string $issue ): null {
		$this->issues[] = $issue;
		return null;
	}

	/**
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>|null
	 */
	private function element( array $attrs, array $inner, array $roots, int $depth ): ?array {
		$tag = is_string( $attrs['tag'] ?? null ) ? $attrs['tag'] : '';
		if ( 1 !== preg_match( MarkupPolicy::TAG_PATTERN, $tag ) || in_array( strtolower( $tag ), MarkupPolicy::FORBIDDEN_TAGS, true ) ) {
			return $this->omit( sprintf( /* translators: %s: HTML tag */ __( 'Elemento con tag non valido "%s": non esportato con il suo contenuto', 'xb' ), mb_substr( $tag, 0, 40 ) ) );
		}
		$out        = array( 'tag' => $tag );
		$attributes = array();
		foreach ( is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array() as $name => $value ) {
			$name = (string) $name;
			if ( 1 !== preg_match( MarkupPolicy::ATTRIBUTE_PATTERN, $name ) || str_starts_with( $name, 'xb:' ) ) {
				$this->issues[] = sprintf( /* translators: %s: attribute name */ __( 'Attributo con nome non valido omesso: %s', 'xb' ), mb_substr( $name, 0, 40 ) );
				continue;
			}
			if ( true === $value ) {
				$attributes[ $name ] = '';
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$lower = strtolower( $name );
			if ( ! $this->trusted && null !== self::unsafe_for_etch( $lower, $value ) ) {
				$this->issues[] = sprintf( /* translators: 1: attribute name, 2: reason */ __( 'Attributo «%1$s» di un autore senza HTML libero omesso: %2$s', 'xb' ), mb_substr( $name, 0, 40 ), self::unsafe_for_etch( $lower, $value ) );
				continue;
			}
			$value = $this->expressions->template( $value, $roots );
			if ( 'class' === $lower ) {
				$value = $this->tokens->classes( $value );
			} elseif ( 'style' === $lower ) {
				$value = $this->tokens->css( $value );
			}
			$attributes[ $name ] = $value;
		}
		$out['attributes'] = $attributes;
		if ( is_string( $attrs['label'] ?? null ) && '' !== $attrs['label'] ) {
			$out['metadata'] = array( 'name' => $attrs['label'] );
		}
		if ( in_array( $tag, Spec::VOID_ELEMENTS, true ) ) {
			return self::etch( 'etch/element', $out );
		}
		if ( in_array( $tag, Spec::RAW_TEXT_ELEMENTS, true ) ) {
			$children = array();
			foreach ( $inner as $child ) {
				$content = $child['attrs']['content'] ?? null;
				if ( 'xb/text' === ( $child['blockName'] ?? null ) && true === ( $child['attrs']['verbatim'] ?? false ) && is_string( $content ) ) {
					// What Histeon prints: the content can never close its own element.
					$content    = 'textarea' === $tag ? str_ireplace( '</textarea', '&lt;/textarea', $content ) : (string) preg_replace( '#</(' . $tag . ')#i', '<\\/$1', $content );
					$children[] = self::etch( 'etch/text', array( 'content' => $content ) );
				}
			}
			return self::etch( 'etch/element', $out, $children );
		}
		return self::etch( 'etch/element', $out, $this->list( $inner, $tag, $roots, $depth + 1 ) );
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @param list<string>         $roots
	 * @return array<string, mixed>
	 */
	private function text( array $attrs, string $parent, array $roots ): array {
		$content = is_string( $attrs['content'] ?? null ) ? $attrs['content'] : '';
		if ( true === ( $attrs['verbatim'] ?? false ) ) {
			// Outside script, style and textarea, Histeon prints verbatim text escaped. So does Etch with every text.
			return self::etch( 'etch/text', array( 'content' => in_array( $parent, Spec::RAW_TEXT_ELEMENTS, true ) ? $content : Expressions::literal_text( $content ) ) );
		}
		// An XB text is HTML and Etch prints its texts escaped: entities are decoded, or `&amp;` would show as `&amp;amp;`.
		return self::etch( 'etch/text', array( 'content' => $this->expressions->template( $content, $roots, true ) ) );
	}

	/**
	 * Etch prints a text as plain text (observed on Etch 1.7.0: a tag written in a text shows as written). The
	 * inline formatting of an XB text (docs/spec/dsl.md §3.4) becomes elements of its own, with the text between.
	 *
	 * @param array<string, mixed> $block
	 * @return list<array<string, mixed>> XB blocks: the block itself when its text has no inline tag.
	 */
	private function inline( array $block, string $parent ): array {
		$content = $block['attrs']['content'] ?? null;
		if ( 'xb/text' !== ( $block['blockName'] ?? null ) || true === ( $block['attrs']['verbatim'] ?? false ) || ! is_string( $content ) || ! str_contains( $content, '<' ) || in_array( $parent, Spec::RAW_TEXT_ELEMENTS, true ) ) {
			return array( $block );
		}
		$at    = 0;
		$parts = self::inline_children( $content, $at, '', 0 );
		if ( null === $parts ) {
			$this->issues[] = sprintf( /* translators: %s: text */ __( 'Formattazione non chiusa nel testo "%s": i tag restano scritti nel testo', 'xb' ), mb_substr( $content, 0, 80 ) );
			return array( $block );
		}
		return $parts;
	}

	/**
	 * The XB blocks of a text from `$at` up to the closing tag `$until` (or to the end when `$until` is empty), with
	 * `$at` left on the last character read. Null when a tag closes another one, or is never closed.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private static function inline_children( string $content, int &$at, string $until, int $depth ): ?array {
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}
		$out    = array();
		$text   = '';
		$length = strlen( $content );
		for ( ; $at < $length; ++$at ) {
			$c = $content[ $at ];
			if ( '\\' === $c && isset( $content[ $at + 1 ] ) && ( '{' === $content[ $at + 1 ] || '}' === $content[ $at + 1 ] ) ) {
				$text .= $c . $content[ $at + 1 ];
				++$at;
				continue;
			}
			if ( '{' === $c ) {
				// An expression is skipped whole: a comparison such as {a<b} is not a tag.
				try {
					$close = Template::closing_brace( $content, $at );
				} catch ( SyntaxError ) {
					$close = $at;
				}
				$text .= substr( $content, $at, $close - $at + 1 );
				$at    = $close;
				continue;
			}
			$found = '<' === $c ? self::inline_tag( $content, $at ) : null;
			if ( null === $found ) {
				$text .= $c;
				continue;
			}
			if ( '' !== $text ) {
				$out[] = array( 'blockName' => 'xb/text', 'attrs' => array( 'content' => $text ), 'innerBlocks' => array() );
				$text  = '';
			}
			$at = $found['end'];
			if ( $found['close'] ) {
				return $found['tag'] === $until ? $out : null;
			}
			$children = array();
			if ( ! $found['void'] ) {
				++$at;
				$children = self::inline_children( $content, $at, $found['tag'], $depth + 1 );
				if ( null === $children ) {
					return null;
				}
			}
			$out[] = array( 'blockName' => 'xb/element', 'attrs' => array( 'tag' => $found['tag'], 'attributes' => $found['attributes'] ), 'innerBlocks' => $children );
		}
		if ( '' !== $until ) {
			return null;
		}
		if ( '' !== $text ) {
			$out[] = array( 'blockName' => 'xb/text', 'attrs' => array( 'content' => $text ), 'innerBlocks' => array() );
		}
		return $out;
	}

	/**
	 * The inline tag of an XB text that starts at `$at` (Spec::TEXT_INLINE_HTML), or null: anything else stays text.
	 *
	 * @return array{tag: string, close: bool, void: bool, attributes: array<string, string>, end: int}|null
	 */
	private static function inline_tag( string $content, int $at ): ?array {
		if ( 1 !== preg_match( '#\\G<(/?)([A-Za-z][A-Za-z0-9]*)((?:\s+[^<>"\']*(?:"[^"]*"|\'[^\']*\')?)*)\s*(/?)>#', $content, $m, 0, $at ) ) {
			return null;
		}
		$tag     = strtolower( $m[2] );
		$allowed = Spec::TEXT_INLINE_HTML[ $tag ] ?? null;
		if ( null === $allowed ) {
			return null;
		}
		$attributes = array();
		if ( '' === $m[1] && preg_match_all( '/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/', $m[3], $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				$name = strtolower( $pair[1] );
				if ( isset( $allowed[ $name ] ) ) {
					// Inside a tag braces are text (§3.4): written escaped, so that no expression is read in the attribute.
					$value               = html_entity_decode( ( $pair[2] ?? '' ) . ( $pair[3] ?? '' ) . ( $pair[4] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$attributes[ $name ] = str_replace( array( '\\{', '\\}', '{', '}' ), array( '{', '}', '\\{', '\\}' ), $value );
				}
			}
		}
		return array(
			'tag'        => $tag,
			'close'      => '/' === $m[1],
			'void'       => '/' === $m[4] || in_array( $tag, Spec::VOID_ELEMENTS, true ),
			'attributes' => $attributes,
			'end'        => $at + strlen( $m[0] ) - 1,
		);
	}

	/**
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>|null
	 */
	private function loop( array $attrs, array $inner, array $roots, int $depth ): ?array {
		$source = is_array( $attrs['source'] ?? null ) ? $attrs['source'] : array();
		if ( array_key_exists( 'groupBy', $source ) ) {
			// A query's result in groups (doc 23 of Publish, item 187): Etch's loops have nothing like it.
			$this->issues[] = sprintf( /* translators: %s: query name */ __( 'Loop a gruppi sulla query «%s» non esportato: Etch non raggruppa il risultato di una query', 'xb' ), (string) ( $source['id'] ?? '' ) );
			return null;
		}
		$query  = $this->queries->resolve( $source, $this->issues );
		if ( null === $query ) {
			return null;
		}
		$as = is_string( $attrs['as'] ?? null ) && 1 === preg_match( self::IDENTIFIER, $attrs['as'] ) ? $attrs['as'] : 'item';
		if ( isset( $attrs['index'] ) ) {
			$this->issues[] = sprintf( /* translators: %s: variable name */ __( 'Indice del loop «%s» non esportato: nei dati di Etch osservati non c\'era', 'xb' ), (string) $attrs['index'] );
		}
		$out = array(
			'loopId' => $query['id'],
			'itemId' => $as,
		);
		$this->changes[] = sprintf( /* translators: 1: Histeon loop source, 2: Etch query id */ __( 'Loop su «%1$s» → query di Etch «%2$s»', 'xb' ), 'expr' === ( $source['kind'] ?? null ) ? __( 'un elenco scritto', 'xb' ) : (string) ( $source['id'] ?? '' ), $query['id'] );
		if ( 'preset' === ( $source['kind'] ?? null ) && is_array( $source['args'] ?? null ) && 'posts' !== ( $source['id'] ?? null ) ) {
			$params = Queries::params( $source['args'], $this->expressions, $roots );
			if ( array() !== $params ) {
				$out['loopParams'] = $params;
			}
		}
		$scope = $query['posts'] ? array_values( array_unique( array_merge( $roots, array( $as ) ) ) ) : array_values( array_diff( $roots, array( $as ) ) );
		return self::etch( 'etch/loop', $out, $this->list( $inner, '', $scope, $depth + 1 ) );
	}

	/**
	 * @param array<string, mixed>        $attrs
	 * @param array<array<string, mixed>> $inner
	 * @param list<string>                $roots
	 * @return array<string, mixed>|null
	 */
	private function component( array $attrs, array $inner, array $roots ): ?array {
		$ref = is_string( $attrs['ref'] ?? null ) ? $attrs['ref'] : '';
		$id  = $this->components[ $ref ] ?? null;
		if ( null === $id ) {
			return $this->omit( sprintf( /* translators: %s: component key */ __( 'Componente «%s» non esportato: non è un componente (wp_block) esportato insieme', 'xb' ), mb_substr( $ref, 0, 80 ) ) );
		}
		$props = array();
		foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $name => $value ) {
			$name = (string) $name;
			if ( 1 !== preg_match( self::IDENTIFIER, $name ) || in_array( $name, array( '__proto__', 'constructor', 'prototype' ), true ) ) {
				$this->issues[] = sprintf( /* translators: %s: prop name */ __( 'Prop con nome non valido omessa: %s', 'xb' ), mb_substr( $name, 0, 40 ) );
				continue;
			}
			if ( ! $this->trusted && null !== self::unsafe_prop( $value ) ) {
				$this->issues[] = sprintf( /* translators: 1: prop name, 2: reason */ __( 'Prop «%1$s» di un autore senza HTML libero omessa: %2$s', 'xb' ), $name, (string) self::unsafe_prop( $value ) );
				continue;
			}
			if ( is_array( $value ) && is_string( $value['expr'] ?? null ) ) {
				$expr = $this->expressions->expression( $value['expr'], $roots );
				if ( null !== $expr ) {
					$props[ $name ] = '{' . $expr . '}';
				}
			} elseif ( is_array( $value ) && is_string( $value['tpl'] ?? null ) ) {
				$props[ $name ] = $this->expressions->template( $value['tpl'], $roots );
			} elseif ( is_string( $value ) ) {
				$props[ $name ] = Expressions::literal( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$props[ $name ] = $value;
			} else {
				$this->issues[] = sprintf( /* translators: %s: prop name */ __( 'Prop «%s» con una lista o un oggetto: non esportata (nei dati di Etch osservati le props sono testi e numeri)', 'xb' ), $name );
			}
		}
		if ( array() !== $inner ) {
			$this->issues[] = sprintf( /* translators: %s: component key */ __( 'Contenuto negli slot del componente «%s» non esportato: nei dati di Etch osservati gli slot non comparivano', 'xb' ), $ref );
		}
		return self::etch(
			'etch/component',
			array(
				'ref'        => $id,
				'attributes' => $props,
			)
		);
	}

	/** @return array<string, mixed>|null */
	private function raw( string $html, bool $top ): ?array {
		if ( 1 === preg_match( self::DELIMITER, $html ) ) {
			return $this->omit( __( 'HTML grezzo con un commento di blocco (<!-- wp:…): non esportato, diventerebbe un blocco vero', 'xb' ) );
		}
		if ( $top ) {
			// HTML outside blocks, where the importer found it.
			return array(
				'blockName'    => null,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $html,
				'innerContent' => array( $html ),
			);
		}
		return array(
			'blockName'    => 'core/html',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * A core or third-party block, as stored; XB blocks inside it (the importer converted the Etch blocks there) go
	 * back to Etch. Etch blocks the importer kept unconverted stay as they are, only in trusted documents: Etch does
	 * not check who may write scripts (docs/spec/import-etch.md §4).
	 *
	 * @param array<string, mixed> $block
	 * @param list<string>         $roots
	 * @return array<string, mixed>|null
	 */
	private function foreign( array $block, array $roots, int $depth ): ?array {
		$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		if ( '' === $name ) {
			return null;
		}
		if ( str_starts_with( $name, 'etch/' ) && ! $this->trusted ) {
			return $this->omit( sprintf( /* translators: %s: block name */ __( 'Blocco %s conservato dall\'importazione, in un contenuto di un autore senza unfiltered_html: non esportato', 'xb' ), $name ) );
		}
		if ( $depth > self::MAX_DEPTH ) {
			return $this->omit( sprintf( /* translators: %d: maximum nesting depth */ __( 'Annidamento oltre %d livelli: contenuto più profondo omesso', 'xb' ), self::MAX_DEPTH ) );
		}
		$children = array();
		$content  = array();
		$slot     = 0;
		$inner    = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
		foreach ( is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array() as $piece ) {
			if ( null !== $piece ) {
				$content[] = $piece;
				continue;
			}
			$child = $inner[ $slot++ ] ?? null;
			if ( ! is_array( $child ) ) {
				continue;
			}
			$child_name = is_string( $child['blockName'] ?? null ) ? $child['blockName'] : '';
			$converted  = str_starts_with( $child_name, 'xb/' ) ? $this->block( $child, '', $roots, $depth + 1, false ) : $this->foreign( $child, $roots, $depth + 1 );
			if ( null !== $converted && null !== $converted['blockName'] ) {
				$children[] = $converted;
				$content[]  = null;
			}
		}
		return array(
			'blockName'    => $name,
			'attrs'        => is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
			'innerBlocks'  => $children,
			'innerHTML'    => is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '',
			'innerContent' => $content,
		);
	}

	/**
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	private static function etch( string $name, array $attrs, array $children = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $children ), null ),
		);
	}
}
