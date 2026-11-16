<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Etch;

use XB\Expr\Parser;
use XB\Expr\SyntaxError;
use XB\Expr\Template;
use XB\Import\Etch\Expressions as ImportExpressions;

/**
 * XB expressions → the form Etch saves — the inverse of docs/spec/import-etch.md §3, see docs/spec/export-etch.md §3.
 *
 * What differs: the modifiers Etch names otherwise (`x.date('Y')` → `x.dateFormat('Y')`, `upper`, `lower` and
 * `slug` → `toUpperCase`, `toLowerCase`, `toSlug`) and the address of a post (`post.url` →
 * `post.permalink.relative`, only directly on a name that holds a post). The rewrite is token-based,
 * strings are never touched, and every result is read back with the importer: when it does not come back as the
 * same XB expression the report says so.
 */
final class Expressions {

	/** Literal braces in text: HTML entities, so Etch prints them and never reads them as an expression. */
	public const OPEN  = '&#123;';
	public const CLOSE = '&#125;';

	/**
	 * A literal brace inside a text. Etch prints the content of a text as plain text (observed on Etch 1.7.0:
	 * `&amp;` came out as `&amp;amp;`), so an entity would show as written; an expression that is the string
	 * "{" or "}" prints the brace. A closing brace left alone is dropped by Etch when the text has an expression.
	 */
	public const TEXT_OPEN  = '{"{"}';
	public const TEXT_CLOSE = '{"}"}';

	/** @var list<string> */
	public array $issues = array();
	/** @var list<string> Each expression the export rewrote, as Histeon wrote it and as Etch will read it. */
	public array $changes = array();

	private ImportExpressions $import;

	public function __construct() {
		$this->import = new ImportExpressions();
	}

	/**
	 * One expression (without braces). Null when XB itself cannot read it: nothing is guessed.
	 *
	 * @param list<string> $post_roots Names that hold a post here: `this` plus the post loop variables in scope.
	 */
	public function expression( string $source, array $post_roots = array( 'this' ) ): ?string {
		try {
			Parser::parse( $source );
		} catch ( SyntaxError $e ) {
			$this->issues[] = sprintf( /* translators: 1: expression, 2: error */ __( 'Espressione non valida in Histeon, omessa: {%1$s} (%2$s)', 'xb' ), self::short( $source ), $e->getMessage() );
			return null;
		}
		$out = $this->rewrite( $source, $post_roots );
		if ( $out !== $source ) {
			$this->changes[] = sprintf( '{%1$s} → {%2$s}', self::short( $source ), self::short( $out ) );
		}
		if ( $this->import->rewrite( $out ) !== $source ) {
			$this->issues[] = sprintf( /* translators: 1: XB expression, 2: Etch expression */ __( 'Espressione esportata ma non reversibile: {%1$s} diventa {%2$s}, che reimportata non torna uguale', 'xb' ), self::short( $source ), self::short( $out ) );
		}
		return $out;
	}

	/**
	 * A text or attribute value with interpolations. `\{` becomes an entity (Etch has no observed escape for a
	 * literal brace), `\}` a plain brace (a closing brace alone never starts an expression).
	 *
	 * With `$text` the value is the HTML of an XB text and the result the plain text Etch prints: the entities of
	 * the literal parts are decoded and a literal brace is written as TEXT_OPEN or TEXT_CLOSE.
	 *
	 * @param list<string> $post_roots
	 */
	public function template( string $value, array $post_roots = array( 'this' ), bool $text = false ): string {
		if ( ! str_contains( $value, '{' ) && ! str_contains( $value, '\\' ) ) {
			return $text ? self::plain_text( $value ) : $value;
		}
		$out     = '';
		$literal = '';
		$len     = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $value[ $i ];
			if ( '\\' === $c && isset( $value[ $i + 1 ] ) && ( '{' === $value[ $i + 1 ] || '}' === $value[ $i + 1 ] ) ) {
				$literal .= '{' === $value[ $i + 1 ] ? ( $text ? '{' : self::OPEN ) : '}';
				++$i;
				continue;
			}
			if ( '{' !== $c ) {
				$literal .= $c;
				continue;
			}
			try {
				$close = Template::closing_brace( $value, $i );
			} catch ( SyntaxError ) {
				$this->issues[] = sprintf( /* translators: %s: text */ __( 'Graffa non chiusa in "%s": esportata come testo', 'xb' ), self::short( $value ) );
				$literal       .= $text ? '{' : self::OPEN;
				continue;
			}
			$etch    = $this->expression( substr( $value, $i + 1, $close - $i - 1 ), $post_roots );
			$out    .= ( $text ? self::plain_text( $literal ) : $literal ) . ( null === $etch ? '' : '{' . $etch . '}' );
			$literal = '';
			$i       = $close;
		}
		return $out . ( $text ? self::plain_text( $literal ) : $literal );
	}

	/** The literal part of an XB text (HTML: entities as written) as the plain text of an Etch text. */
	private static function plain_text( string $html ): string {
		return self::literal_text( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/** Plain text for an Etch text: nothing in it is read as an expression, nothing is escaped (Etch does). */
	public static function literal_text( string $value ): string {
		return strtr( $value, array( '{' => self::TEXT_OPEN, '}' => self::TEXT_CLOSE ) );
	}

	/**
	 * Plain text that must not be read as an expression by Etch (a literal prop, verbatim text shown as text).
	 */
	public static function literal( string $value ): string {
		return str_replace( array( '{', '}' ), array( self::OPEN, self::CLOSE ), $value );
	}

	/**
	 * The value of an expression made only of literals (`3`, `"futuri"`, `[{label: "Home"}]`), or null when it
	 * reads anything: the inverse of Migration::literal().
	 *
	 * @return array{0: mixed}|null The value, wrapped (null is a valid value).
	 */
	public static function constant( string $source ): ?array {
		try {
			$node = Parser::parse( $source );
		} catch ( SyntaxError ) {
			return null;
		}
		return self::node_value( $node );
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array{0: mixed}|null
	 */
	private static function node_value( array $node ): ?array {
		switch ( $node['type'] ?? '' ) {
			case 'Literal':
				return array( $node['value'] ?? null );
			case 'Array':
				$list = array();
				foreach ( is_array( $node['elements'] ?? null ) ? $node['elements'] : array() as $element ) {
					$value = is_array( $element ) ? self::node_value( $element ) : null;
					if ( null === $value ) {
						return null;
					}
					$list[] = $value[0];
				}
				return array( $list );
			case 'Object':
				$map = array();
				foreach ( is_array( $node['properties'] ?? null ) ? $node['properties'] : array() as $property ) {
					$value = is_array( $property['value'] ?? null ) ? self::node_value( $property['value'] ) : null;
					if ( null === $value ) {
						return null;
					}
					$map[ (string) $property['key'] ] = $value[0];
				}
				return array( $map );
			default:
				return null;
		}
	}

	/**
	 * Token rewrite outside string literals.
	 *
	 * @param list<string> $post_roots
	 */
	public function rewrite( string $source, array $post_roots = array( 'this' ) ): string {
		$out         = '';
		$len         = strlen( $source );
		$i           = 0;
		$last_word   = '';
		$last_member = false;
		$last_end    = -1;
		while ( $i < $len ) {
			$c = $source[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$end = $i + 1;
				while ( $end < $len && $source[ $end ] !== $c ) {
					$end += '\\' === $source[ $end ] ? 2 : 1;
				}
				$out      .= substr( $source, $i, $end - $i + 1 );
				$i         = $end + 1;
				$last_word = '';
				continue;
			}
			if ( 1 === preg_match( '/\G[A-Za-z_$][A-Za-z0-9_$]*/', $source, $m, 0, $i ) ) {
				$word   = $m[0];
				$member = '.' === self::previous( $source, $i );
				$rest   = substr( $source, $i + strlen( $word ) );
				$emit   = $word;
				$etch   = array_search( $word, array_diff_key( ImportExpressions::RENAMED, array( 'format' => true ) ), true );
				if ( $member && is_string( $etch ) && 1 === preg_match( '/^\s*\(/', $rest ) ) {
					$emit = $etch;
				} elseif (
					$member && 'url' === $word && ! $last_member && in_array( $last_word, $post_roots, true )
					&& 1 === preg_match( '/^\s*\.\s*$/', substr( $source, $last_end, $i - $last_end ) )
					&& 1 !== preg_match( '/^\s*\(/', $rest )
				) {
					$emit = 'permalink.relative';
				}
				$out        .= $emit;
				$i          += strlen( $word );
				$last_word   = $word;
				$last_member = $member;
				$last_end    = $i;
				continue;
			}
			$out .= $c;
			if ( '.' !== $c && ! ctype_space( $c ) ) {
				$last_word = '';
			}
			++$i;
		}
		return $out;
	}

	private static function previous( string $s, int $i ): string {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( ! ctype_space( $s[ $j ] ) ) {
				return $s[ $j ];
			}
		}
		return '';
	}

	public static function short( string $s ): string {
		return mb_strlen( $s ) > 80 ? mb_substr( $s, 0, 77 ) . '…' : $s;
	}
}
