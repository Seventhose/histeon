<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Data\PostNode;
use XB\Data\TermNode;
use XB\Data\UserNode;
use XB\Plugin;

/**
 * PHP for the builder, written in files — docs/spec/php.md. XB never runs PHP typed in the editor; a theme or
 * plugin (versioned, deployed like any code) gives pages:
 *   - data: `add_filter( 'xb_data_providers', fn ( $p ) => $p + array( 'meteo' => array( 'callback' => fn () => …,
 *     'description' => '…' ) ) )`, read as `data.meteo` (or `{#loop data.meteo as giorno}`);
 *   - modifiers: the `xb_modifiers` filter, used as `valore.nome()`;
 *   - dynamic blocks: `register_block_type( 'tema/blocco', array( 'render_callback' => … ) )`, inserted as
 *     `<!-- wp:tema/blocco /-->`.
 * The builder lists all three (GET /xb/v1/extensions). Values from data providers become plain data: posts,
 * terms and users go through the same visibility rules as anywhere else, other objects are dropped.
 */
final class Extensions {

	private const NAME      = '/^[A-Za-z_][A-Za-z0-9_]{0,40}$/';
	private const MAX_DEPTH = 8;
	private const MAX_ITEMS = 2000;

	/** @var array<string, array{callback: callable, description: string}>|null */
	private static ?array $providers = null;

	/** @var array<string, mixed> Values already computed in this request: each provider runs once per page and content. */
	private static array $values = array();

	/** @return array<string, array{callback: callable, description: string}> */
	public static function providers(): array {
		if ( null === self::$providers ) {
			$out = array();
			/** @var mixed $raw Data providers of themes and plugins. */
			$raw = apply_filters( 'xb_data_providers', array() );
			foreach ( is_array( $raw ) ? $raw : array() as $name => $provider ) {
				$callback    = is_array( $provider ) ? ( $provider['callback'] ?? null ) : $provider;
				$description = is_array( $provider ) && is_string( $provider['description'] ?? null ) ? $provider['description'] : '';
				// `posts`, `terms` and `users` are XB's own: a provider never takes their place.
				if ( is_string( $name ) && 1 === preg_match( self::NAME, $name ) && ! in_array( $name, array( 'posts', 'terms', 'users' ), true ) && is_callable( $callback ) ) {
					$out[ $name ] = array(
						'callback'    => $callback,
						'description' => mb_substr( sanitize_text_field( $description ), 0, 200 ),
					);
				}
			}
			self::$providers = $out;
		}
		return self::$providers;
	}

	/** Test helper: read the filter again. */
	public static function forget(): void {
		self::$providers = null;
		self::$values    = array();
	}

	/** The value of a data provider as page data; null when it fails (the page never breaks). */
	public static function data( string $name ): mixed {
		$provider = self::providers()[ $name ] ?? null;
		if ( null === $provider ) {
			return null;
		}
		// Per page and current content: a static export renders many pages in one request.
		$key = $name . '|' . get_queried_object_id() . '|' . (int) get_the_ID();
		if ( array_key_exists( $key, self::$values ) ) {
			return self::$values[ $key ];
		}
		self::$values[ $key ] = null;
		try {
			$budget               = self::MAX_ITEMS;
			self::$values[ $key ] = self::plain( ( $provider['callback'] )(), 0, $budget );
			return self::$values[ $key ];
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'Histeon: data.%s: %s', $name, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			}
			return null;
		}
	}

	/**
	 * Scalars, lists and maps; posts, terms and users as nodes, with their visibility rules. Anything else
	 * (other objects, resources, deeper than MAX_DEPTH, more than MAX_ITEMS values) is null.
	 */
	private static function plain( mixed $value, int $depth, int &$budget ): mixed {
		if ( --$budget < 0 ) {
			return null;
		}
		if ( null === $value || is_scalar( $value ) ) {
			return is_float( $value ) && ! is_finite( $value ) ? null : $value;
		}
		if ( $value instanceof \WP_Post ) {
			return PostNode::for_post( $value );
		}
		if ( $value instanceof \WP_Term ) {
			$taxonomy = get_taxonomy( $value->taxonomy );
			return $taxonomy && $taxonomy->public ? new TermNode( $value ) : null;
		}
		if ( $value instanceof \WP_User ) {
			return UserNode::author_profile( $value->ID );
		}
		if ( $value instanceof \JsonSerializable && $depth < self::MAX_DEPTH ) {
			return self::plain( $value->jsonSerialize(), $depth + 1, $budget );
		}
		if ( ! is_array( $value ) || $depth >= self::MAX_DEPTH ) {
			return null;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::plain( $item, $depth + 1, $budget );
			if ( $budget < 0 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * What the builder can offer: data providers, modifiers (XB's and the site's) and dynamic blocks of themes
	 * and plugins.
	 *
	 * @return array{data: list<array{name: string, description: string}>, modifiers: list<string>, blocks: list<array{name: string, title: string}>}
	 */
	public static function describe(): array {
		$data = array();
		foreach ( self::providers() as $name => $provider ) {
			$data[] = array(
				'name'        => $name,
				'description' => $provider['description'],
			);
		}
		$blocks = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $type ) {
			if ( ! $type->is_dynamic() || str_starts_with( $type->name, 'core/' ) || str_starts_with( $type->name, 'xb/' ) ) {
				continue;
			}
			$blocks[] = array(
				'name'  => $type->name,
				'title' => '' !== $type->title ? $type->title : $type->name,
			);
		}
		usort( $blocks, static fn ( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );
		return array(
			'data'      => $data,
			'modifiers' => Plugin::instance()->modifiers->names(),
			'blocks'    => $blocks,
		);
	}
}
