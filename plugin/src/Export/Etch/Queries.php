<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Etch;

use XB\Content\ContentTypes;
use XB\Render\LoopPresets;
use XB\Render\Queries as RenderQueries;

/**
 * Loop sources → entries of Etch's query option — docs/spec/export-etch.md §2.3.
 *
 * A stored preset of type `posts` becomes a `wp-query` entry with the same arguments, `main` a `main-query`
 * entry, a list written as literals a `json` entry; the built-in `posts` preset becomes a `wp-query` entry with
 * its literal arguments written out, the built-in `main` an argument-less `main-query`. An entry that already
 * stands for the same query keeps its id, key and the fields Histeon does not know. Anything else (terms, users,
 * records of Histeon content types, lists computed from props) is not exported and the report says why.
 */
final class Queries {

	private const OPTION = 'etch_loops';

	/** Built-in `posts` arguments → WP_Query, as LoopPresets reads them (only literal values are exported). */
	private const ORDERBY = array( 'date', 'title', 'modified', 'menu_order', 'rand', 'comment_count' );

	/** @var array<string, array<string, mixed>> Entries to write, by Etch id. */
	public array $entries = array();

	/** @var array<mixed> */
	private array $existing;
	/** @var array<mixed> */
	private array $presets;

	public function __construct() {
		$existing       = get_option( self::OPTION, array() );
		$presets        = get_option( LoopPresets::OPTION, array() );
		$this->existing = is_array( $existing ) ? $existing : array();
		$this->presets  = is_array( $presets ) ? $presets : array();
	}

	/**
	 * @param array<string, mixed> $source XB loop source.
	 * @param list<string>         $issues
	 * @return array{id: string, posts: bool}|null The Etch query id, and whether the items are posts.
	 */
	public function resolve( array $source, array &$issues ): ?array {
		if ( 'expr' === ( $source['kind'] ?? null ) ) {
			return $this->json( (string) ( $source['expr'] ?? '' ), $issues );
		}
		$id = is_string( $source['id'] ?? null ) ? $source['id'] : '';
		if ( 'main' === $id ) {
			return $this->entry( 'histos_main', array( 'type' => 'main-query' ), true );
		}
		if ( 'posts' === $id ) {
			return $this->builtin_posts( is_array( $source['args'] ?? null ) ? $source['args'] : array(), $issues );
		}
		if ( in_array( $id, array( 'terms', 'users' ), true ) ) {
			$issues[] = sprintf( /* translators: %s: preset name */ __( 'Loop sul preset «%s» non esportato: nei dati di Etch osservati non c\'è una query equivalente', 'xb' ), $id );
			return null;
		}
		$preset = $this->presets[ $id ] ?? null;
		if ( ! is_array( $preset ) || ! is_array( $preset['args'] ?? null ) ) {
			$issues[] = sprintf( /* translators: %s: preset name */ __( 'Loop sul preset sconosciuto «%s»: non esportato', 'xb' ), self::short( $id ) );
			return null;
		}
		$type = (string) ( $preset['type'] ?? 'posts' );
		if ( 'posts' === $type ) {
			if ( ! $this->types_allowed( $preset['args']['post_type'] ?? null, $id, $issues ) ) {
				return null;
			}
			return $this->entry( $id, array( 'type' => 'wp-query', 'args' => self::published( $preset['args'] ) ), true );
		}
		if ( 'main' === $type ) {
			$config = array( 'type' => 'main-query' );
			// Histeon reads only these from a main preset (LoopPresets::main_query); anything else in it (a post
			// type, an author, a search) is inert on the Histeon site and must not start working in Etch.
			$args = array_intersect_key( $preset['args'], array_flip( array( 'posts_per_page', 'offset', 'order', 'orderby' ) ) );
			if ( array() !== $args ) {
				$config['args'] = $args;
			}
			return $this->entry( $id, $config, true );
		}
		$issues[] = sprintf( /* translators: 1: preset name, 2: query type */ __( 'Loop sul preset «%1$s» di tipo %2$s non esportato: nei dati di Etch osservati non c\'è una query equivalente', 'xb' ), self::short( $id ), $type );
		return null;
	}

	/**
	 * Arguments of a loop on an Etch query: literals as values, anything else as `{expression}`.
	 *
	 * @param array<mixed> $args XB argument expressions.
	 * @param list<string> $roots
	 * @return array<string, mixed>
	 */
	public static function params( array $args, Expressions $expressions, array $roots ): array {
		$out = array();
		foreach ( $args as $name => $expr ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) || ! is_string( $expr ) ) {
				$expressions->issues[] = sprintf( /* translators: %s: argument name */ __( 'Argomento del loop «%s» non esportato', 'xb' ), self::short( (string) $name ) );
				continue;
			}
			$constant = Expressions::constant( $expr );
			if ( null !== $constant && ( is_scalar( $constant[0] ) || null === $constant[0] ) ) {
				if ( is_string( $constant[0] ) && 1 === preg_match( '/[{}]/', $constant[0] ) ) {
					// Etch would read the braces as an expression, and an argument is not HTML (no entity).
					$expressions->issues[] = sprintf( /* translators: %s: argument name */ __( 'Argomento del loop «%s» con graffe nel testo: non esportato', 'xb' ), $name );
					continue;
				}
				$out[ '$' . $name ] = $constant[0];
				continue;
			}
			$etch = $expressions->expression( $expr, $roots );
			if ( null !== $etch ) {
				$out[ '$' . $name ] = '{' . $etch . '}';
			}
		}
		return $out;
	}

	/**
	 * @param list<string> $issues
	 * @return array{id: string, posts: bool}|null
	 */
	private function json( string $expr, array &$issues ): ?array {
		$constant = Expressions::constant( $expr );
		if ( null === $constant || ! is_array( $constant[0] ) || ! array_is_list( $constant[0] ) ) {
			$issues[] = sprintf( /* translators: %s: expression */ __( 'Loop su {%s} non esportato: in Etch solo una lista scritta per intero diventa una query (json)', 'xb' ), self::short( $expr ) );
			return null;
		}
		$data = $constant[0];
		foreach ( $this->existing as $id => $entry ) {
			if ( is_array( $entry ) && 'json' === ( $entry['config']['type'] ?? null ) && wp_json_encode( $entry['config']['data'] ?? null ) === wp_json_encode( $data ) ) {
				return $this->keep( (string) $id, $entry, $entry['config'], false );
			}
		}
		return $this->entry( 'histos_json_' . substr( md5( (string) wp_json_encode( $data ) ), 0, 8 ), array( 'type' => 'json', 'data' => $data ), false );
	}

	/**
	 * The built-in `posts` preset with literal arguments, written out as WP_Query arguments.
	 *
	 * @param array<mixed> $args
	 * @param list<string> $issues
	 * @return array{id: string, posts: bool}|null
	 */
	private function builtin_posts( array $args, array &$issues ): ?array {
		$values = array();
		foreach ( $args as $name => $expr ) {
			$constant = is_string( $expr ) ? Expressions::constant( $expr ) : null;
			if ( null === $constant ) {
				$issues[] = sprintf( /* translators: %s: argument name */ __( 'Loop sugli articoli con l\'argomento «%s» calcolato: non esportato (in Etch la query deve essere scritta per intero)', 'xb' ), self::short( (string) $name ) );
				return null;
			}
			$values[ (string) $name ] = $constant[0];
		}
		$query = array(
			'post_type'      => $values['type'] ?? 'post',
			'posts_per_page' => is_numeric( $values['limit'] ?? null ) ? (int) $values['limit'] : 10,
		);
		if ( is_numeric( $values['offset'] ?? null ) ) {
			$query['offset'] = max( 0, (int) $values['offset'] );
		}
		if ( is_string( $values['order'] ?? null ) ) {
			$query['order'] = 'asc' === strtolower( $values['order'] ) ? 'ASC' : 'DESC';
		}
		if ( in_array( $values['orderby'] ?? null, self::ORDERBY, true ) ) {
			$query['orderby'] = $values['orderby'];
		}
		if ( isset( $values['category'] ) && is_scalar( $values['category'] ) ) {
			$query[ is_numeric( $values['category'] ) ? 'cat' : 'category_name' ] = $values['category'];
		}
		if ( is_string( $values['tag'] ?? null ) ) {
			$query['tag'] = $values['tag'];
		}
		if ( is_numeric( $values['author'] ?? null ) ) {
			$query['author'] = (int) $values['author'];
		}
		foreach ( array( 'include' => 'post__in', 'exclude' => 'post__not_in' ) as $from => $to ) {
			if ( isset( $values[ $from ] ) ) {
				$query[ $to ] = array_values( array_map( 'intval', array_filter( (array) $values[ $from ], 'is_numeric' ) ) );
			}
		}
		if ( is_string( $values['taxonomy'] ?? null ) && isset( $values['term'] ) && is_scalar( $values['term'] ) ) {
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => $values['taxonomy'],
					'field'    => is_numeric( $values['term'] ) ? 'term_id' : 'slug',
					'terms'    => $values['term'],
				),
			);
		}
		if ( ! $this->types_allowed( $query['post_type'], 'posts', $issues ) ) {
			return null;
		}
		$query = self::published( $query );
		ksort( $query );
		return $this->entry( 'histos_posts_' . substr( md5( (string) wp_json_encode( $query ) ), 0, 8 ), array( 'type' => 'wp-query', 'args' => $query ), true );
	}

	/**
	 * The arguments of an Etch wp-query, published content only. The import strips the visibility arguments
	 * (Queries::FORBIDDEN_ARGS) because Histeon adds its own when it renders a loop (LoopPresets::visible); Etch runs the
	 * query as written, so without `post_status` a logged-in user with the right capabilities would see private and
	 * draft items. Whatever visibility arguments a preset holds are dropped first, then the ones Histeon adds when it
	 * renders (LoopPresets::visible): `publish`, no password-protected posts, readable only, no hidden products.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private static function published( array $args ): array {
		$args                 = array_diff_key( $args, array_flip( RenderQueries::FORBIDDEN_ARGS ) );
		$args['post_status']  = 'publish';
		$args['has_password'] = false;
		$args['perm']         = 'readable';
		$types                = (array) ( $args['post_type'] ?? 'post' );
		if ( in_array( 'product', $types, true ) && taxonomy_exists( 'product_visibility' ) ) {
			// As LoopPresets::visible: products hidden from the catalogue never appear in a list.
			$args['tax_query']   = is_array( $args['tax_query'] ?? null ) ? $args['tax_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => array( 'exclude-from-catalog' ),
				'operator' => 'NOT IN',
			);
		}
		return $args;
	}

	/**
	 * Only types WordPress lets visitors list, and none of Histeon's own content types. Etch runs the query as it is
	 * written: the checks Histeon makes when it renders a loop (LoopPresets::listable) are made here, once.
	 *
	 * @param list<string> $issues
	 */
	private function types_allowed( mixed $types, string $preset, array &$issues ): bool {
		$types = null === $types ? array( 'post' ) : (array) $types;
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) || str_contains( $type, '$' ) ) {
				$issues[] = sprintf( /* translators: %s: preset name */ __( 'Loop sul preset «%s» non esportato: il tipo di contenuto dipende da un argomento, e in Etch nessuno lo controllerebbe', 'xb' ), self::short( $preset ) );
				return false;
			}
			if ( ContentTypes::owns( $type ) ) {
				$issues[] = sprintf( /* translators: 1: preset name, 2: content type */ __( 'Loop sul preset «%1$s» non esportato: elenca i record del tipo di contenuto Histeon «%2$s», che in un sito Etch non esiste', 'xb' ), self::short( $preset ), $type );
				return false;
			}
			if ( ! LoopPresets::listable( $type ) ) {
				$issues[] = sprintf( /* translators: 1: preset name, 2: content type */ __( 'Loop sul preset «%1$s» non esportato: il tipo «%2$s» non è elencabile dai visitatori', 'xb' ), self::short( $preset ), self::short( $type ) );
				return false;
			}
		}
		return true;
	}

	/**
	 * The entry for an XB preset (or a built-in query): the existing Etch entry that the import turned into this
	 * preset (`etch_<key>`), or one with the same configuration, keeps its id; otherwise a new one.
	 *
	 * @param array<string, mixed> $config
	 * @return array{id: string, posts: bool}
	 */
	private function entry( string $preset, array $config, bool $posts ): array {
		foreach ( $this->existing as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key = is_string( $entry['key'] ?? null ) ? $entry['key'] : (string) $id;
			if ( 'etch_' . preg_replace( '/[^A-Za-z0-9_]/', '_', $key ) === $preset ) {
				return $this->keep( (string) $id, $entry, $config, $posts );
			}
		}
		foreach ( $this->existing as $id => $entry ) {
			if ( is_array( $entry ) && is_array( $entry['config'] ?? null ) && self::same( $entry['config'], $config ) ) {
				return $this->keep( (string) $id, $entry, $config, $posts );
			}
		}
		$key = str_starts_with( $preset, 'etch_' ) ? substr( $preset, 5 ) : $preset;
		$key = '' === $key ? 'histos' : $key;
		return $this->keep(
			$key,
			array(
				'name' => $key,
				'key'  => $key,
			),
			$config,
			$posts
		);
	}

	/**
	 * @param array<mixed>         $entry
	 * @param array<string, mixed> $config
	 * @return array{id: string, posts: bool}
	 */
	private function keep( string $id, array $entry, array $config, bool $posts ): array {
		$entry['config']      = $config;
		$this->entries[ $id ] = $entry;
		return array(
			'id'    => $id,
			'posts' => $posts,
		);
	}

	/**
	 * @param array<mixed> $a
	 * @param array<mixed> $b
	 */
	private static function same( array $a, array $b ): bool {
		return wp_json_encode( self::sorted( $a ) ) === wp_json_encode( self::sorted( $b ) );
	}

	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return array_map( array( self::class, 'sorted' ), $value );
	}

	private static function short( string $s ): string {
		return Expressions::short( $s );
	}
}
