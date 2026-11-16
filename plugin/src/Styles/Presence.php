<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

/**
 * The classes and ids present on a page (plus the safelist): what CssRules::select() checks rules against.
 * Tokens are 'c:<class>' and 'i:<id>'.
 */
final class Presence {

	/** @var array<string, true> */
	private array $tokens = array();
	/** @var list<string> Token prefixes from safelist entries ending in `*`. */
	private array $prefixes = array();
	private bool $all = false;

	public static function everything(): self {
		$presence      = new self();
		$presence->all = true;
		return $presence;
	}

	/** Every class and id in an HTML document or fragment. */
	public static function from_html( string $html ): self {
		$presence = new self();
		$presence->add_html( $html );
		return $presence;
	}

	public function add_html( string $html ): void {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			$class = $tags->get_attribute( 'class' );
			if ( is_string( $class ) ) {
				$this->add_classes( $class );
			}
			$id = $tags->get_attribute( 'id' );
			if ( is_string( $id ) && '' !== $id ) {
				$this->tokens[ 'i:' . $id ] = true;
			}
		}
	}

	public function add_classes( string $value ): void {
		foreach ( preg_split( '/[ \t\n\f\r]+/', $value ) ?: array() as $class ) {
			if ( '' !== $class ) {
				$this->tokens[ 'c:' . $class ] = true;
			}
		}
	}

	/**
	 * Safelist entries: `.is-open`, `#modal`, a bare name (= class), or a prefix ending in `*` (`.is-*`).
	 * For classes and ids added by JavaScript after the page loads, which no scan of the HTML can see.
	 *
	 * @param list<string> $entries
	 */
	public function add_safelist( array $entries ): void {
		foreach ( $entries as $entry ) {
			$entry = (string) preg_replace( '/\\\\(.)/', '$1', trim( $entry ) ); // `.md\:hidden` as written in CSS.
			if ( '' === $entry ) {
				continue;
			}
			$token = str_starts_with( $entry, '#' ) ? 'i:' . substr( $entry, 1 ) : 'c:' . ltrim( $entry, '.' );
			if ( str_ends_with( $token, '*' ) ) {
				$this->prefixes[] = substr( $token, 0, -1 );
			} else {
				$this->tokens[ $token ] = true;
			}
		}
	}

	/** One token ('c:<class>' or 'i:<id>'), as written. */
	public function add_token( string $token ): void {
		$this->tokens[ $token ] = true;
	}

	/** Every token starting with $prefix counts as present (a class completed by an interpolation). */
	public function add_prefix( string $prefix ): void {
		$this->prefixes[] = $prefix;
	}

	/** @param array<mixed> $tokens */
	public function has_all( array $tokens ): bool {
		if ( $this->all ) {
			return true;
		}
		foreach ( $tokens as $token ) {
			if ( ! $this->has( (string) $token ) ) {
				return false;
			}
		}
		return true;
	}

	public function has( string $token ): bool {
		if ( $this->all || isset( $this->tokens[ $token ] ) ) {
			return true;
		}
		foreach ( $this->prefixes as $prefix ) {
			if ( str_starts_with( $token, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string> */
	public function tokens(): array {
		return array_keys( $this->tokens );
	}
}
