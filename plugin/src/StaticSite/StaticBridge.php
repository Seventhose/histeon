<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\StaticSite;

use XB\Render\ComponentRegistry;
use XB\Spec;

/**
 * XB's side of the contract with Static Bridge — docs/integration/static-bridge.md. Static Bridge keeps a static
 * copy of the site and regenerates it after changes; XB tells it:
 *   - which pages show a component (`static_bridge_dependents_for_post`), so changing one regenerates those pages
 *     instead of the whole site;
 *   - which pages list content (`static_bridge_post_has_query_loop`): Histeon and Etch loops and the core Query
 *     Loop, also inside components and reusable blocks, and the `data` root, so they are regenerated when a post
 *     changes.
 *   - once a day, which pages show lists that depend on the date (a query preset with "$today" or "$now"), through
 *     its `static_bridge_queue_paths` action, so "Prossimi eventi" does not stay frozen on the day of the export.
 * When in doubt (a template uses the component, too many pages) XB answers nothing and Static Bridge refreshes
 * everything, which is always correct. The filters only read; nothing here runs without Static Bridge.
 */
final class StaticBridge {

	/** As many pages as Static Bridge accepts for a targeted regeneration. */
	private const MAX_PAGES = 500;
	/** Components nested in components, followed this deep. */
	private const MAX_DEPTH = 10;

	/** The daily event that asks Static Bridge to regenerate the pages with lists that depend on the date (D2). */
	public const DAILY_HOOK = 'xb_static_dates_daily';

	public static function register(): void {
		add_filter( 'static_bridge_dependents_for_post', array( self::class, 'dependents' ), 10, 2 );
		add_filter( 'static_bridge_post_has_query_loop', array( self::class, 'has_list' ), 10, 2 );
		add_action( self::DAILY_HOOK, array( self::class, 'refresh_dated' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/** Every day a little after midnight (site time), only while Static Bridge listens for paths to regenerate. */
	public static function schedule(): void {
		$next = wp_next_scheduled( self::DAILY_HOOK );
		if ( ! has_action( 'static_bridge_queue_paths' ) ) {
			if ( false !== $next ) {
				wp_clear_scheduled_hook( self::DAILY_HOOK );
			}
			return;
		}
		if ( false === $next ) {
			wp_schedule_event( ( new \DateTimeImmutable( 'tomorrow 00:05', wp_timezone() ) )->getTimestamp(), 'daily', self::DAILY_HOOK );
		}
	}

	/** Asks Static Bridge to regenerate the published pages whose lists depend on the date (D2). */
	public static function refresh_dated(): void {
		if ( ! has_action( 'static_bridge_queue_paths' ) ) {
			return;
		}
		$presets = self::dated_presets();
		if ( array() === $presets ) {
			return;
		}
		global $wpdb;
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- once a day, a search across contents.
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_content LIKE %s ORDER BY ID LIMIT %d',
				array_merge( $types, array( '%' . $wpdb->esc_like( '<!-- wp:xb/' ) . '%', 5000 ) )
			)
		);
		$urls = array();
		$memo = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post && self::shows_dated( $post->post_content, $presets, $memo, 0 ) ) {
				$urls[] = (string) get_permalink( $post );
			}
			if ( count( $urls ) >= self::MAX_PAGES ) {
				break;
			}
		}
		if ( array() !== $urls ) {
			do_action( 'static_bridge_queue_paths', $urls, 'histos-dates' );
		}
	}

	/**
	 * The query presets whose arguments read the date ("$today", "$now").
	 *
	 * @return array<string, true>
	 */
	private static function dated_presets(): array {
		$out = array();
		foreach ( \XB\Render\Queries::presets() as $id => $preset ) {
			$json = wp_json_encode( $preset['args'] );
			if ( is_string( $json ) && 1 === preg_match( '/"\$(today|now)\b/', $json ) ) {
				$out[ (string) $id ] = true;
			}
		}
		return $out;
	}

	/**
	 * Whether markup shows a loop over a dated preset, itself or through the components it uses.
	 *
	 * @param array<string, true>  $presets
	 * @param array<string, bool>  $memo    Components already looked at.
	 */
	private static function shows_dated( string $content, array $presets, array &$memo, int $depth ): bool {
		if ( $depth > self::MAX_DEPTH || ! str_contains( $content, '<!-- wp:xb/' ) ) {
			return false;
		}
		$stack = parse_blocks( $content );
		while ( array() !== $stack ) {
			$block = array_pop( $stack );
			if ( ! is_array( $block ) ) {
				continue;
			}
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'xb/loop' === ( $block['blockName'] ?? null ) && 'preset' === ( $attrs['source']['kind'] ?? null ) && isset( $presets[ (string) ( $attrs['source']['id'] ?? '' ) ] ) ) {
				return true;
			}
			if ( 'xb/component' === ( $block['blockName'] ?? null ) && is_string( $attrs['ref'] ?? null ) ) {
				$ref = $attrs['ref'];
				if ( ! array_key_exists( $ref, $memo ) ) {
					$memo[ $ref ] = false;
					$owner        = ComponentRegistry::owner( $ref );
					$definition   = null === $owner ? null : get_post( $owner );
					$memo[ $ref ] = $definition instanceof \WP_Post && self::shows_dated( $definition->post_content, $presets, $memo, $depth + 1 );
				}
				if ( $memo[ $ref ] ) {
					return true;
				}
			}
			foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $inner ) {
				$stack[] = $inner;
			}
		}
		return false;
	}

	/**
	 * Where Static Bridge's copy of the site stands (its `static_bridge_status` filter, Static Bridge 2.0.25+), or
	 * null when it is not active. Whatever answers the filter, only known fields of the expected types are kept,
	 * and the link must be this site's admin.
	 *
	 * @phpstan-impure
	 * @return array{version: string, serving: bool, incremental: bool, pages: int, published_at: int, pending: int, export: string, last: array{message: string, time: string, error: bool}|null, admin_url: string}|null
	 */
	public static function status(): ?array {
		if ( ! has_filter( 'static_bridge_status' ) ) {
			return null;
		}
		$raw = apply_filters( 'static_bridge_status', null );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$text   = static fn( mixed $v, int $max ): string => is_scalar( $v ) ? mb_substr( wp_strip_all_tags( (string) $v ), 0, $max ) : '';
		$number = static fn( mixed $v ): int => is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ? max( 0, (int) $v ) : 0;
		$export = $text( $raw['export'] ?? '', 20 );
		$link   = is_string( $raw['admin_url'] ?? null ) ? esc_url_raw( $raw['admin_url'] ) : '';
		$last   = is_array( $raw['last'] ?? null ) ? $raw['last'] : null;
		return array(
			'version'      => $text( $raw['version'] ?? '', 20 ),
			'serving'      => true === ( $raw['serving'] ?? null ),
			'incremental'  => true === ( $raw['incremental'] ?? null ),
			'pages'        => $number( $raw['pages'] ?? 0 ),
			'published_at' => $number( $raw['published_at'] ?? 0 ),
			'pending'      => $number( $raw['pending'] ?? 0 ),
			'export'       => in_array( $export, array( 'idle', 'running', 'paused', 'failed', 'complete', 'cancelled' ), true ) ? $export : 'idle',
			'last'         => null === $last ? null : array(
				'message' => $text( $last['message'] ?? '', 300 ),
				'time'    => $text( $last['time'] ?? '', 60 ),
				'error'   => true === ( $last['error'] ?? null ),
			),
			'admin_url'    => str_starts_with( $link, admin_url() ) ? $link : '',
		);
	}

	/**
	 * True when a release published now also reaches Static Bridge's copy on its own.
	 *
	 * @phpstan-impure
	 */
	public static function keeps_up(): bool {
		$status = self::status();
		return null !== $status && $status['serving'] && $status['incremental'];
	}

	/**
	 * The public URLs of the pages that show a component, or the value received (null: refresh everything).
	 */
	public static function dependents( mixed $current, mixed $post ): mixed {
		if ( null !== $current || ! $post instanceof \WP_Post || 'wp_block' !== $post->post_type ) {
			return $current;
		}
		$ref = (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true );
		if ( 1 !== preg_match( Spec::COMPONENT_REF_PATTERN, $ref ) || self::templates_render_posts() ) {
			return $current;
		}
		$urls  = array();
		$seen  = array( $ref => true );
		$queue = array( $ref );
		for ( $depth = 0; array() !== $queue && $depth <= self::MAX_DEPTH; $depth++ ) {
			$next = array();
			foreach ( $queue as $component ) {
				$users = self::users_of( $component );
				if ( null === $users ) {
					return $current; // Too many to name.
				}
				foreach ( $users as $user ) {
					if ( in_array( $user->post_type, array( 'wp_template', 'wp_template_part', 'wp_navigation' ), true ) ) {
						return $current; // On many routes: Static Bridge refreshes everything.
					}
					if ( 'wp_block' === $user->post_type ) {
						$parent = (string) get_post_meta( $user->ID, ComponentRegistry::META_KEY, true );
						if ( 1 !== preg_match( Spec::COMPONENT_REF_PATTERN, $parent ) ) {
							return $current; // A plain reusable block: where it appears is not known here.
						}
						if ( ! isset( $seen[ $parent ] ) ) {
							$seen[ $parent ] = true;
							$next[]          = $parent;
						}
						continue;
					}
					if ( 'publish' === $user->post_status && '' === $user->post_password && is_post_type_viewable( $user->post_type ) ) {
						$url = (string) get_permalink( $user );
						if ( '' !== $url ) {
							$urls[ $url ] = $url;
						}
					}
					if ( count( $urls ) > self::MAX_PAGES ) {
						return $current;
					}
				}
			}
			$queue = $next;
		}
		return array() !== $queue ? $current : array_values( $urls );
	}

	/** Whether a page lists content: a loop or the `data` root, in its own markup or in the components it uses. */
	public static function has_list( mixed $current, mixed $post ): mixed {
		if ( true === $current || ! $post instanceof \WP_Post ) {
			return $current;
		}
		$contents = array( $post->post_content );
		$seen     = array();
		for ( $depth = 0; array() !== $contents && $depth <= self::MAX_DEPTH; $depth++ ) {
			$next = array();
			foreach ( $contents as $content ) {
				if ( self::lists( $content ) ) {
					return true;
				}
				$blocks = parse_blocks( $content );
				foreach ( ComponentRegistry::refs_in( $blocks ) as $ref ) {
					if ( isset( $seen[ $ref ] ) ) {
						continue;
					}
					$seen[ $ref ] = true;
					$owner        = ComponentRegistry::owner( $ref );
					if ( null !== $owner ) {
						$definition = get_post( $owner );
						$next[]     = $definition instanceof \WP_Post ? $definition->post_content : '';
					}
				}
				// Reusable blocks and Etch components point at a wp_block by id.
				foreach ( self::block_ids( $blocks ) as $id ) {
					if ( isset( $seen[ '#' . $id ] ) ) {
						continue;
					}
					$seen[ '#' . $id ] = true;
					$definition        = get_post( $id );
					if ( $definition instanceof \WP_Post && 'wp_block' === $definition->post_type ) {
						$next[] = $definition->post_content;
					}
				}
			}
			$contents = $next;
		}
		return $current;
	}

	/**
	 * A list in the markup: a Histeon loop, an Etch loop (sites imported from Etch), the core Query Loop (not its
	 * parts such as `wp:query-title`), or the `data` and `facets` roots.
	 */
	private static function lists( string $content ): bool {
		return 1 === preg_match( '~<!--\s*wp:(?:xb/loop|etch/loop|query)(?=[\s/])~', $content ) || 1 === preg_match( '/[{(\s,:|](data|facets)\.[A-Za-z_]/', $content );
	}

	/**
	 * Ids of the wp_block posts that reusable blocks (`core/block`) and Etch components point at.
	 *
	 * @param array<mixed> $blocks
	 * @return list<int>
	 */
	private static function block_ids( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( in_array( $block['blockName'] ?? null, array( 'core/block', 'etch/component' ), true ) && is_numeric( $block['attrs']['ref'] ?? null ) && (int) $block['attrs']['ref'] > 0 ) {
				$out[] = (int) $block['attrs']['ref'];
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$out = array_merge( $out, self::block_ids( $block['innerBlocks'] ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Posts whose markup has an instance of the component (checked block by block, not by text alone); null when
	 * there are too many.
	 *
	 * @return list<\WP_Post>|null
	 */
	private static function users_of( string $ref ): ?array {
		global $wpdb;
		$needle = '%' . $wpdb->esc_like( '"ref":"' . $ref . '"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a read of post_content by pattern, no WP_Query equivalent.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') LIMIT %d", $needle, self::MAX_PAGES + 1 ) );
		if ( count( $ids ) > self::MAX_PAGES ) {
			return null;
		}
		$out = array();
		foreach ( $ids as $id ) {
			$user = get_post( (int) $id );
			if ( $user instanceof \WP_Post && in_array( $ref, ComponentRegistry::refs_in( parse_blocks( $user->post_content ) ), true ) ) {
				$out[] = $user;
			}
		}
		return $out;
	}

	/**
	 * A template that prints the content of listed posts (post content inside a loop or a query) may show any
	 * post, so a component change can reach archive pages: then everything is refreshed.
	 */
	private static function templates_render_posts(): bool {
		$templates = get_posts(
			array(
				'post_type'        => array( 'wp_template', 'wp_template_part' ),
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'suppress_filters' => true,
			)
		);
		foreach ( $templates as $template ) {
			$content = $template->post_content;
			$lists   = self::lists( $content );
			if ( $lists && ( str_contains( $content, 'wp:xb/post-content' ) || str_contains( $content, 'wp:post-content' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
