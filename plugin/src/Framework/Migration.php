<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

use XB\Blocks;
use XB\Plugin;
use XB\Rest\Controller;
use XB\Versioning\Documents;
use XB\Versioning\Store;
use XB\Versioning\Versioning;

/**
 * Assisted framework switch — docs/spec/framework.md §Cambio.
 *
 * Native names are rewritten (`var(--space-xxl)` → `var(--space-2xl)`, `.btn--primary` → `.btn-primary`) in
 * element classes, inline styles and style entries. The result is a set of **dev commits** plus the new
 * framework in the `settings` document: nothing goes live until a release is published, and that release can
 * be rolled back like any other. --xb-* tokens need no rewriting.
 */
final class Migration {

	private const MAX_DOCS = 2000;

	public function __construct(
		private readonly Frameworks $frameworks,
		private readonly Versioning $versioning,
	) {}

	/**
	 * Name maps between two providers: variables through the shared --xb-* aliases, classes through the
	 * `a--b` ↔ `a-b` convention, only towards names that exist in the target.
	 *
	 * @return array{variables: array<string, string>, classes: array<string, string>}
	 */
	public function map( Provider $from, Provider $to ): array {
		if ( 'acss' === $from->id() && 'xb-tokens' === $to->id() ) {
			// Into Histeon CSS: explicit tables (docs/spec/histos-css.md §4), only names that mean the same thing.
			return array(
				'variables' => HistosCss\AcssImport::variables(),
				'classes'   => HistosCss\AcssImport::classes(),
			);
		}
		$variables = array();
		$to_alias  = $to->aliases();
		foreach ( $from->aliases() as $token => $source ) {
			if ( isset( $to_alias[ $token ] ) && $to_alias[ $token ] !== $source ) {
				$variables[ $source ] = $to_alias[ $token ];
			}
		}
		if ( 'xb-tokens' === $to->id() ) {
			// Moving to XB Tokens: native names become the semantic tokens themselves.
			foreach ( $from->aliases() as $token => $source ) {
				$variables[ $source ] = $token;
			}
		}

		$target_classes = array();
		foreach ( $to->classes() as $names ) {
			foreach ( $names as $name ) {
				$target_classes[ $name ] = true;
			}
		}
		$classes = array();
		foreach ( $from->classes() as $names ) {
			foreach ( $names as $name ) {
				foreach ( array( str_replace( '--', '-', $name ), (string) preg_replace( '/^([a-z]+)-(?!-)/', '$1--', $name ) ) as $candidate ) {
					if ( $candidate !== $name && isset( $target_classes[ $candidate ] ) ) {
						$classes[ $name ] = $candidate;
						break;
					}
				}
			}
		}
		return array(
			'variables' => $variables,
			'classes'   => $classes,
		);
	}

	/**
	 * Dry run: what would change, document by document, and what has no counterpart.
	 *
	 * @return array{from: string, to: string, docs: list<array{doc: string, label: string, variables: int, classes: int}>, unmapped: list<string>, carry: array{from: string, to: string, route: string, variables: int, tokens: int}|null, design: list<string>}
	 */
	public function plan( string $to_id, ?string $from_id = null ): array {
		return $this->run( $to_id, false, false, $from_id );
	}

	/**
	 * Writes the migration as dev commits (and the framework choice in `settings`).
	 *
	 * @return array{from: string, to: string, docs: list<array{doc: string, label: string, variables: int, classes: int}>, unmapped: list<string>, carry: array{from: string, to: string, route: string, variables: int, tokens: int}|null, design: list<string>}
	 */
	public function prepare( string $to_id, bool $carry = true, ?string $from_id = null ): array {
		// All or nothing (S8): every document's commit and the framework choice in one transaction, so a failure
		// half way leaves no site half migrated. The old framework's values (Carry: files and options derived from
		// them) follow only once the migration is in.
		$store = $this->versioning->store();
		$store->assert_transactional();
		try {
			$result = $store->transaction( fn (): array => $this->run( $to_id, true, false, $from_id ) );
		} catch ( \Throwable $e ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( AcssToCore::RECIPES, 'options' );
			foreach ( $this->documents() as $doc ) {
				$post_id = Documents::post_id( $doc );
				if ( null !== $post_id ) {
					clean_post_cache( $post_id );
				}
			}
			throw $e;
		}
		if ( $carry ) {
			$from            = $this->frameworks->all()[ $result['from'] ];
			$result['carry'] = Carry::carry( $from, $this->frameworks->all()[ $result['to'] ] );
		}
		return $result;
	}

	/**
	 * @param string|null $from_id The framework the site is leaving (default: the active one). It may be turned off
	 *                             already (an Etch site whose Automatic.css is off): its files are still there.
	 * @return array{from: string, to: string, docs: list<array{doc: string, label: string, variables: int, classes: int}>, unmapped: list<string>, carry: array{from: string, to: string, route: string, variables: int, tokens: int}|null, design: list<string>}
	 */
	private function run( string $to_id, bool $write, bool $carry = false, ?string $from_id = null ): array {
		$from = null !== $from_id ? ( $this->frameworks->all()[ $from_id ] ?? null ) : $this->frameworks->active();
		if ( null === $from || ( ! $from->available() && array() === Carry::files( $from ) ) ) {
			throw new \InvalidArgumentException( __( 'Framework di partenza non valido.', 'xb' ) );
		}
		$to   = $this->frameworks->all()[ $to_id ] ?? null;
		if ( null === $to || ! $to->available() || $to->id() === $from->id() ) {
			throw new \InvalidArgumentException( __( 'Framework di destinazione non valido.', 'xb' ) );
		}
		$map      = $this->map( $from, $to );
		$known    = array();
		foreach ( $from->variables() as $names ) {
			foreach ( $names as $name ) {
				$known[ $name ] = true;
			}
		}
		$report   = array();
		$unmapped = array();

		foreach ( $this->documents() as $doc ) {
			$post_id = Documents::post_id( $doc );
			if ( null !== $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$copy    = $this->versioning->working_copy( $doc );
			$payload = $copy['payload'];
			$count   = array(
				'variables' => 0,
				'classes'   => 0,
			);
			if ( Documents::STYLES === $doc ) {
				foreach ( array( 'styles', 'globals' ) as $key ) {
					$list = is_array( $payload[ $key ] ?? null ) ? $payload[ $key ] : array();
					foreach ( $list as $i => $entry ) {
						if ( ! is_array( $entry ) ) {
							continue;
						}
						if ( is_string( $entry['css'] ?? null ) ) {
							$list[ $i ]['css'] = $this->css( $entry['css'], $map['variables'], $count, $known, $unmapped );
						}
						if ( is_string( $entry['selector'] ?? null ) ) {
							$list[ $i ]['selector'] = $this->selector( $entry['selector'], $map['classes'], $count );
						}
					}
					$payload[ $key ] = $list;
				}
			} elseif ( is_string( $payload['content'] ?? null ) ) {
				$payload['content'] = Blocks::serialize( $this->blocks( parse_blocks( $payload['content'] ), $map, $count, $known, $unmapped ) );
			}
			if ( $count['variables'] + $count['classes'] > 0 ) {
				$report[] = array(
					'doc'       => $doc,
					'label'     => Documents::label( $doc ),
					'variables' => $count['variables'],
					'classes'   => $count['classes'],
				);
				if ( $write ) {
					$this->versioning->save_dev( $doc, $payload, $copy['head'], $copy['base'], sprintf( /* translators: 1: previous CSS framework, 2: new CSS framework */ __( 'Migrazione %1$s → %2$s', 'xb' ), $from->label(), $to->label() ), true );
				}
			}
		}

		// The old framework's values and rules come along, so nothing it styled breaks (Carry).
		$carried = null;
		if ( $write && $carry ) {
			$carried = Carry::carry( $from, $to );
		} elseif ( ! $write ) {
			$carried = Carry::plan( $from, $to );
		}
		// Into Histeon CSS from Automatic.css: its base colours become the design's, so every shade Histeon CSS computes (and the
		// families it does not map) belongs to the same palette.
		$design = 'acss' === $from->id() && 'xb-tokens' === $to->id() ? HistosCss\AcssImport::design( Carry::variables( $from ) ) : array();
		if ( $write ) {
			$settings = $this->versioning->working_copy( Documents::SETTINGS );
			$extra    = array( 'framework' => $to->id() );
			if ( array() !== $design ) {
				$current          = is_array( $settings['payload']['design'] ?? null ) ? $settings['payload']['design'] : array();
				$extra['design']  = HistosCss\Design::changes( HistosCss\Design::validate( $design + $current ) );
			}
			$this->versioning->save_dev( Documents::SETTINGS, $extra + $settings['payload'], $settings['head'], $settings['base'], sprintf( /* translators: %s: CSS framework name */ __( 'Framework: %s', 'xb' ), $to->label() ), true );
			// The site's Automatic.css recipes stay in the CSS editor (`@name`), next to Histeon CSS's.
			if ( 'acss' === $from->id() && 'xb-tokens' === $to->id() && array() !== AcssToCore::recipes() ) {
				update_option( AcssToCore::RECIPES, AcssToCore::recipes(), false );
			}
			$this->versioning->store()->audit( 'migration', 'framework', array( 'from' => $from->id(), 'to' => $to->id(), 'docs' => array_column( $report, 'doc' ) ) );
		}
		ksort( $unmapped );
		return array(
			'from'     => $from->id(),
			'to'       => $to->id(),
			'docs'     => $report,
			'unmapped' => array_keys( $unmapped ),
			'carry'    => $carried,
			'design'   => array_keys( $design ),
		);
	}

	/** @return list<string> Tracked documents plus every page, post and component that contains XB blocks. */
	private function documents(): array {
		global $wpdb;
		$docs = array( Documents::STYLES => true );
		foreach ( $this->versioning->store()->docs( Store::DEV ) as $doc ) {
			if ( Documents::SETTINGS !== $doc ) {
				$docs[ $doc ] = true;
			}
		}
		$types = implode( ',', array_fill( 0, count( \XB\Versioning\Documents::POST_TYPES ), '%s' ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) AND post_status NOT IN ('auto-draft', 'trash', 'inherit') AND post_content LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
				array_merge( \XB\Versioning\Documents::POST_TYPES, array( '%' . $wpdb->esc_like( 'wp:xb/' ) . '%', self::MAX_DOCS ) )
			)
		);
		foreach ( $ids as $id ) {
			$docs[ Documents::for_post( (int) $id ) ] = true;
		}
		return array_keys( $docs );
	}

	/**
	 * @param array<array<string, mixed>>                                         $blocks
	 * @param array{variables: array<string, string>, classes: array<string, string>} $map
	 * @param array{variables: int, classes: int}                                 $count
	 * @param array<string, true>                                                 $known
	 * @param array<string, true>                                                 $unmapped
	 * @return array<array<string, mixed>>
	 */
	private function blocks( array $blocks, array $map, array &$count, array $known, array &$unmapped ): array {
		foreach ( $blocks as $i => $block ) {
			$attributes = $block['attrs']['attributes'] ?? null;
			if ( 'xb/element' === ( $block['blockName'] ?? null ) && is_array( $attributes ) ) {
				if ( is_string( $attributes['class'] ?? null ) ) {
					$attributes['class'] = $this->class_attribute( $attributes['class'], $map['classes'], $count );
				}
				if ( is_string( $attributes['style'] ?? null ) ) {
					$attributes['style'] = $this->css( $attributes['style'], $map['variables'], $count, $known, $unmapped );
				}
				$blocks[ $i ]['attrs']['attributes'] = $attributes;
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$blocks[ $i ]['innerBlocks'] = $this->blocks( $block['innerBlocks'], $map, $count, $known, $unmapped );
			}
		}
		return $blocks;
	}

	/**
	 * @param array<string, string>              $map
	 * @param array{variables: int, classes: int} $count
	 * @param array<string, true>                $known
	 * @param array<string, true>                $unmapped
	 */
	private function css( string $css, array $map, array &$count, array $known, array &$unmapped ): string {
		return (string) preg_replace_callback(
			'/var\(\s*--([A-Za-z0-9_-]+)/',
			static function ( array $m ) use ( $map, &$count, $known, &$unmapped ): string {
				if ( isset( $map[ $m[1] ] ) ) {
					++$count['variables'];
					return 'var(--' . $map[ $m[1] ];
				}
				if ( isset( $known[ $m[1] ] ) ) {
					$unmapped[ '--' . $m[1] ] = true;
				}
				return $m[0];
			},
			$css
		);
	}

	/**
	 * Rewrites static class tokens; interpolations (`{…}`) are left untouched.
	 *
	 * @param array<string, string>              $map
	 * @param array{variables: int, classes: int} $count
	 */
	private function class_attribute( string $value, array $map, array &$count ): string {
		$parts = preg_split( '/(\{[^}]*\})/', $value, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $value );
		foreach ( $parts as $i => $part ) {
			if ( str_starts_with( $part, '{' ) ) {
				continue;
			}
			$parts[ $i ] = (string) preg_replace_callback(
				'/(?<![\w-])([A-Za-z_-][\w-]*)(?![\w-])/',
				static function ( array $m ) use ( $map, &$count ): string {
					if ( isset( $map[ $m[1] ] ) ) {
						++$count['classes'];
						return $map[ $m[1] ];
					}
					return $m[1];
				},
				$part
			);
		}
		return implode( '', $parts );
	}

	/**
	 * @param array<string, string>              $map
	 * @param array{variables: int, classes: int} $count
	 */
	private function selector( string $selector, array $map, array &$count ): string {
		return (string) preg_replace_callback(
			'/\.([A-Za-z_-][\w-]*)/',
			static function ( array $m ) use ( $map, &$count ): string {
				if ( isset( $map[ $m[1] ] ) ) {
					++$count['classes'];
					return '.' . $map[ $m[1] ];
				}
				return $m[0];
			},
			$selector
		);
	}

	public static function instance(): self {
		$plugin = Plugin::instance();
		return new self( $plugin->frameworks, $plugin->versioning );
	}
}
