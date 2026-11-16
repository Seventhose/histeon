<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Etch;

use XB\Framework\HistosCss\AcssImport;
use XB\Framework\HistosCss\Module;
use XB\Framework\HistosCss\SiteDesign;
use XB\Framework\HistosCss\Tokens as HistosTokens;
use XB\Framework\TokenOverrides;
use XB\Framework\XbTokensProvider;
use XB\Plugin;

/**
 * Histeon CSS in the export — docs/spec/export-etch.md §4 (decision of 28 October 2026).
 *
 * By default the export carries Histeon CSS itself, open source and without licences: the --xb-* tokens with this
 * site's values and the Histeon CSS classes, as two global sheets, so the Etch site stays editable by tokens.
 * Only on request, for a site whose owner already has Automatic.css, the tokens and classes that have an ACSS
 * name (the C1 table, AcssImport) are written with that name; the tokens without one keep their definition.
 */
final class Tokens {

	public const HISTOS = 'histos-css';
	public const ACSS   = 'acss';

	public const SHEET_TOKENS  = 'histos-css-tokens';
	public const SHEET_CLASSES = 'histos-css-classes';

	/** @var array<string, string> Histeon token (without `--`) → ACSS variable. */
	private array $variables = array();
	/** @var array<string, string> Histeon CSS class → ACSS class. */
	private array $class_names = array();
	/** @var array{variables: int, classes: int} */
	public array $counts = array(
		'variables' => 0,
		'classes'   => 0,
	);

	public function __construct( public readonly string $mode = self::HISTOS ) {
		if ( self::ACSS === $mode ) {
			$this->variables = AcssImport::tokens();
			foreach ( AcssImport::classes() as $acss => $histos ) {
				$this->class_names[ $histos ] ??= $acss;
			}
		}
	}

	/** Usages of Histeon tokens with an ACSS name (definitions stay as written). Unchanged in the default mode. */
	public function css( string $css ): string {
		if ( self::ACSS !== $this->mode || ! str_contains( $css, '--xb-' ) ) {
			return $css;
		}
		return (string) preg_replace_callback(
			'/--(xb-[a-z0-9-]+)(?![a-z0-9-])(?!\s*:)/',
			function ( array $m ): string {
				if ( ! isset( $this->variables[ $m[1] ] ) ) {
					return $m[0];
				}
				++$this->counts['variables'];
				return '--' . $this->variables[ $m[1] ];
			},
			$css
		);
	}

	/** Histeon CSS classes with an ACSS equivalent, in a class attribute (whole names only). */
	public function classes( string $value ): string {
		if ( self::ACSS !== $this->mode || ! str_contains( $value, 'xb-' ) ) {
			return $value;
		}
		return (string) preg_replace_callback(
			'/(?<![^\s])xb-[a-z0-9-]+(?![^\s])/',
			function ( array $m ): string {
				if ( ! isset( $this->class_names[ $m[0] ] ) ) {
					return $m[0];
				}
				++$this->counts['classes'];
				return $this->class_names[ $m[0] ];
			},
			$value
		);
	}

	/**
	 * The global sheets that carry Histeon CSS: key → CSS. Empty when the site does not use Histeon CSS.
	 *
	 * @param string       $exported Everything else the export writes (content and CSS), to find the tokens still used.
	 * @param list<string> $issues
	 * @return array<string, string>
	 */
	public function sheets( string $exported, array &$issues ): array {
		$framework = Plugin::instance()->frameworks->active()->id();
		if ( 'xb-tokens' !== $framework ) {
			$issues[] = sprintf( /* translators: %s: CSS framework */ __( 'Il sito usa il framework «%s», non Histeon CSS: nessun foglio di token esportato (il CSS resta com\'è)', 'xb' ), $framework );
			return array();
		}
		$tokens  = self::without_header( (string) file_get_contents( XbTokensProvider::file() ) )
			. ( Module::active() ? HistosTokens::css_changes( SiteDesign::current() ) : '' )
			. TokenOverrides::css();
		$classes = Module::active() ? self::without_header( (string) file_get_contents( XbTokensProvider::classes_file() ) ) : '';
		$out     = array();
		if ( self::HISTOS === $this->mode ) {
			$out[ self::SHEET_TOKENS ] = "/* Histeon CSS: token --xb-* (GPL-3.0-or-later) */\n" . $tokens;
			if ( '' !== $classes ) {
				$out[ self::SHEET_CLASSES ] = "/* Histeon CSS: classi (GPL-3.0-or-later) */\n" . $classes;
			}
			return $out;
		}
		// ACSS names: the classes sheet with renamed usages, and only the tokens nothing in ACSS replaces.
		$classes = $this->css( $classes );
		preg_match_all( '/--(xb-[a-z0-9-]+)\s*:\s*([^;{}]+);/', $tokens, $m, PREG_SET_ORDER );
		$values = array();
		foreach ( $m as $declaration ) {
			$values[ $declaration[1] ] = trim( $declaration[2] );
		}
		$used  = array();
		$queue = array( $exported, $classes );
		while ( array() !== $queue ) {
			preg_match_all( '/--(xb-[a-z0-9-]+)(?![a-z0-9-])(?!\s*:)/', (string) array_pop( $queue ), $refs );
			foreach ( $refs[1] as $name ) {
				// A token with an ACSS name is written with that name everywhere: its definition is not needed.
				if ( ! isset( $used[ $name ] ) && isset( $values[ $name ] ) && ! isset( $this->variables[ $name ] ) ) {
					$used[ $name ] = true;
					$queue[]       = $values[ $name ];
				}
			}
		}
		$rules = '';
		foreach ( array_keys( $values ) as $name ) {
			if ( isset( $used[ $name ] ) ) {
				$rules .= "\t--" . $name . ': ' . $this->css( $values[ $name ] ) . ";\n";
			}
		}
		if ( '' !== $rules ) {
			$out[ self::SHEET_TOKENS ] = "/* Histeon CSS: token --xb-* senza un nome in Automatic.css (GPL-3.0-or-later) */\n:root {\n" . $rules . "}\n";
		}
		if ( '' !== $classes ) {
			$out[ self::SHEET_CLASSES ] = "/* Histeon CSS: classi (GPL-3.0-or-later) */\n" . $classes;
		}
		return $out;
	}

	/** The generated file's own comment names repository paths: the sheet gets a short header instead. */
	private static function without_header( string $css ): string {
		return ltrim( (string) preg_replace( '#^\s*/\*.*?\*/#s', '', $css, 1 ) );
	}
}
