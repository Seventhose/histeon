<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Fields\Fields;
use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * Facets: filters, sorting and pagination for a list of posts — docs/spec/facets.md.
 *
 * A facet is defined once in the site settings (versioned, released like queries): the posts query it filters
 * and the filters a visitor may use, each read from one query string parameter. Pages read its state anywhere
 * through `facets.<name>` (results, totals, options with counts, links), whatever the order of the markup.
 *
 * Visitors only choose among what the definition allows: parameters outside it are ignored, values are
 * checked (existing terms, short texts, known sort options, bounded page numbers) and the query keeps every
 * rule of a loop (published, readable content of listable types). Fields are filterable only when public.
 */
final class Facets {

	public const OPTION       = 'xb_facets';
	public const MAX          = 20;
	public const MAX_FILTERS  = 10;
	/** Posts scanned to count the options of a filter: counts are exact up to this many results. */
	public const MAX_SCAN     = 2000;
	private const MAX_VALUES  = 20;
	private const MAX_OPTIONS = 50;
	private const MAX_PAGE    = 1000;
	private const NAME        = '/^[a-z][a-z0-9_]{0,30}$/';
	private const KEY         = '/^[a-z][a-z0-9_-]{0,30}$/';
	private const KINDS       = array( 'search', 'taxonomy', 'field', 'range', 'sort' );
	/** `field`: by a public field (`field` names it), D4. */
	public const ORDERS       = array( 'date', 'title', 'modified', 'menu_order', 'comment_count', 'rand', 'field' );
	/** What a range compares: numbers or dates (`AAAA-MM-GG`). */
	private const RANGE_TYPES = array( 'number', 'date' );
	/** Field types whose value is one short scalar: the only ones a filter compares. */
	private const FIELD_TYPES = array( 'text', 'select', 'radio', 'number', 'toggle', 'true_false', 'date', 'date_picker', 'button_group', 'email', 'url', 'switcher', 'range' );
	/** Names never usable as parameters, besides WordPress's own query variables (see reserved()). */
	private const RESERVED = array( 'p', 'page', 'paged', 's', 'cat', 'tag', 'm', 'w', 'name', 'author', 'feed', 'preview', 'order', 'orderby', 'year', 'day', 'monthnum', 'static_bridge_bypass', 'xb', 'xb_preview', 'xb_token' );

	/** @var array<string, array<string, mixed>|null> */
	private static array $memo = array();

	/** Whether a facet was rendered in this request (its script then goes in the footer). */
	private static bool $used = false;

	/** In the static ZIP (P9): at most this many results on the page, filtered in the browser. */
	public const STATIC_MAX = 500;

	/**
	 * Facets shown in this static export, with the definition the browser needs (P9).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $exported = array();

	/** @var array<string, mixed>|null Parameters of a test run (Facets::test) in place of the query string. */
	private static ?array $params = null;

	/**
	 * The facets a page sees: the dev version in a signed preview, the live one otherwise.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$dev    = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored = is_array( $dev ) && array_key_exists( 'facets', $dev ) ? $dev['facets'] : get_option( self::OPTION, array() );
		try {
			return self::validate( $stored, null, false );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}

	/**
	 * The facets as data for Fondamenta, the theme that carries them out of the builder (doc 25 of Histeon Publish,
	 * D6; docs/spec/export-core.md §11.7): the base query as `WP_Query` arguments, and the meta key of every field
	 * resolved now (empty when the field is not public: the filter then does nothing, as here). Facets whose query
	 * no longer lists posts are left out.
	 *
	 * @return array<string, array{base: array<string, mixed>, perPage: int, pageKey: string, filters: list<array<string, mixed>>}>
	 */
	public static function portable(): array {
		$presets = new LoopPresets();
		$out     = array();
		foreach ( self::all() as $name => $facet ) {
			$type = (string) $facet['type'];
			$base = $presets->facet_base( (string) $facet['query'], $type );
			if ( null === $base ) {
				continue;
			}
			$filters = array();
			foreach ( $facet['filters'] as $filter ) {
				if ( in_array( $filter['kind'], array( 'field', 'range' ), true ) ) {
					$filter['meta'] = self::field( (string) $filter['field'], $type ) ?? '';
				}
				if ( 'sort' === $filter['kind'] ) {
					foreach ( $filter['options'] as $i => $option ) {
						if ( 'field' === ( $option['orderby'] ?? '' ) ) {
							$filter['options'][ $i ]['meta']    = self::field( (string) ( $option['field'] ?? '' ), $type ) ?? '';
							$filter['options'][ $i ]['numeric'] = self::numeric( (string) ( $option['field'] ?? '' ), $type );
						}
					}
				}
				$filters[] = $filter;
			}
			$out[ (string) $name ] = array(
				'base'    => $base,
				'perPage' => (int) $facet['perPage'],
				'pageKey' => (string) $facet['pageKey'],
				'filters' => $filters,
			);
		}
		return $out;
	}

	/** Test helper: forget per-request state. */
	public static function forget(): void {
		self::$memo     = array();
		self::$used     = false;
		self::$exported = array();
	}

	/**
	 * Checks facets written by a builder; returns them normalized.
	 *
	 * @param list<string>|null $presets Custom query names the facets may use (null: the stored ones).
	 * @param bool              $strict  Also check names against the site (taxonomies, fields, reserved parameters): on save.
	 * @return array<string, array<string, mixed>>
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	public static function validate( mixed $facets, ?array $presets = null, bool $strict = true ): array {
		if ( ! is_array( $facets ) || count( $facets ) > self::MAX ) {
			throw new \InvalidArgumentException( __( 'Elenco dei filtri non valido.', 'xb' ) );
		}
		$presets  = $presets ?? array_map( 'strval', array_keys( Queries::presets() ) );
		$reserved = $strict ? self::reserved() : self::RESERVED;
		$out      = array();
		foreach ( $facets as $name => $facet ) {
			$name = (string) $name;
			if ( 1 !== preg_match( self::NAME, $name ) ) {
				/* translators: %s: facet name */
				throw new \InvalidArgumentException( sprintf( __( 'Nome di filtri non valido: "%s" (minuscole, cifre e trattino basso, come una variabile).', 'xb' ), mb_substr( $name, 0, 40 ) ) );
			}
			if ( ! is_array( $facet ) ) {
				throw new \InvalidArgumentException( __( 'Filtri non validi.', 'xb' ) );
			}
			$query = is_string( $facet['query'] ?? null ) ? $facet['query'] : 'posts';
			if ( 'posts' !== $query && ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,60}$/', $query ) || ( $strict && ! in_array( $query, $presets, true ) ) ) ) {
				/* translators: 1: facet name, 2: query name */
				throw new \InvalidArgumentException( sprintf( __( '"%1$s": la query "%2$s" non esiste.', 'xb' ), $name, mb_substr( $query, 0, 40 ) ) );
			}
			$type = is_string( $facet['type'] ?? null ) ? $facet['type'] : 'post';
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,20}$/', $type ) ) {
				/* translators: %s: facet name */
				throw new \InvalidArgumentException( sprintf( __( '"%s": tipo di contenuto non valido.', 'xb' ), $name ) );
			}
			$per_page = is_numeric( $facet['perPage'] ?? null ) ? (int) $facet['perPage'] : 12;
			$page_key = is_string( $facet['pageKey'] ?? null ) ? $facet['pageKey'] : 'pagina';
			$keys     = array( $page_key );
			$filters  = array();
			$list     = is_array( $facet['filters'] ?? null ) ? array_values( $facet['filters'] ) : array();
			if ( count( $list ) > self::MAX_FILTERS ) {
				/* translators: 1: facet name, 2: maximum */
				throw new \InvalidArgumentException( sprintf( __( '"%1$s": al massimo %2$d filtri.', 'xb' ), $name, self::MAX_FILTERS ) );
			}
			foreach ( $list as $filter ) {
				$filters[] = self::filter( $name, $filter, $type, $strict );
				$keys[]    = $filters[ count( $filters ) - 1 ]['key'];
				// A range also reads `<key>_min` and `<key>_max`, the two fields of the form.
				if ( 'range' === $filters[ count( $filters ) - 1 ]['kind'] ) {
					$keys[] = $filters[ count( $filters ) - 1 ]['key'] . '_min';
					$keys[] = $filters[ count( $filters ) - 1 ]['key'] . '_max';
				}
			}
			foreach ( $keys as $key ) {
				if ( 1 !== preg_match( self::KEY, $key ) || in_array( $key, $reserved, true ) ) {
					/* translators: 1: facet name, 2: parameter */
					throw new \InvalidArgumentException( sprintf( __( '"%1$s": "%2$s" non si può usare come parametro dell\'indirizzo (minuscole, cifre e trattini; non un nome che WordPress usa già).', 'xb' ), $name, mb_substr( $key, 0, 40 ) ) );
				}
			}
			if ( count( array_unique( $keys ) ) !== count( $keys ) ) {
				/* translators: %s: facet name */
				throw new \InvalidArgumentException( sprintf( __( '"%s": due filtri usano lo stesso parametro.', 'xb' ), $name ) );
			}
			$out[ $name ] = array(
				'query'   => $query,
				'type'    => $type,
				'perPage' => max( 1, min( 100, $per_page ) ),
				'pageKey' => $page_key,
				'filters' => $filters,
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	private static function filter( string $facet, mixed $filter, string $type, bool $strict ): array {
		if ( ! is_array( $filter ) || ! in_array( $filter['kind'] ?? null, self::KINDS, true ) || ! is_string( $filter['key'] ?? null ) ) {
			/* translators: %s: facet name */
			throw new \InvalidArgumentException( sprintf( __( '"%s": filtro non valido.', 'xb' ), $facet ) );
		}
		$out = array(
			'kind'  => (string) $filter['kind'],
			'key'   => (string) $filter['key'],
			'label' => mb_substr( sanitize_text_field( is_string( $filter['label'] ?? null ) ? $filter['label'] : '' ), 0, 80 ),
		);
		switch ( $out['kind'] ) {
			case 'taxonomy':
				$taxonomy = is_string( $filter['taxonomy'] ?? null ) ? $filter['taxonomy'] : '';
				$object   = get_taxonomy( $taxonomy );
				if ( 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $taxonomy ) || ( $strict && ( ! $object || ! $object->public ) ) ) {
					/* translators: 1: facet name, 2: taxonomy */
					throw new \InvalidArgumentException( sprintf( __( '"%1$s": la tassonomia "%2$s" non esiste o non è pubblica.', 'xb' ), $facet, mb_substr( $taxonomy, 0, 40 ) ) );
				}
				$out['taxonomy'] = $taxonomy;
				$out['multiple'] = (bool) ( $filter['multiple'] ?? true );
				$out['match']    = 'all' === ( $filter['match'] ?? 'any' ) ? 'all' : 'any';
				break;
			case 'field':
			case 'range':
				$id = is_string( $filter['field'] ?? null ) ? $filter['field'] : '';
				if ( $strict && null === self::field( $id, $type ) ) {
					/* translators: 1: facet name, 2: field id */
					throw new \InvalidArgumentException( sprintf( __( '"%1$s": il campo "%2$s" non è pubblico o non ha un valore semplice da confrontare.', 'xb' ), $facet, mb_substr( $id, 0, 80 ) ) );
				}
				$out['field'] = $id;
				if ( 'range' === $out['kind'] ) {
					$out['type'] = in_array( $filter['type'] ?? null, self::RANGE_TYPES, true ) ? (string) $filter['type'] : 'number';
				}
				break;
			case 'sort':
				$options = array();
				foreach ( is_array( $filter['options'] ?? null ) ? array_slice( array_values( $filter['options'] ), 0, 10 ) : array() as $option ) {
					$value = is_array( $option ) && is_string( $option['value'] ?? null ) ? $option['value'] : '';
					if ( 1 !== preg_match( '/^[a-z0-9_-]{1,30}$/', $value ) || ! in_array( $option['orderby'] ?? null, self::ORDERS, true ) ) {
						/* translators: %s: facet name */
						throw new \InvalidArgumentException( sprintf( __( '"%s": opzione di ordinamento non valida.', 'xb' ), $facet ) );
					}
					$by    = is_string( $option['field'] ?? null ) ? $option['field'] : '';
					if ( 'field' === $option['orderby'] && $strict && null === self::field( $by, $type ) ) {
						/* translators: 1: facet name, 2: field id */
						throw new \InvalidArgumentException( sprintf( __( '"%1$s": il campo "%2$s" non è pubblico o non ha un valore semplice da confrontare.', 'xb' ), $facet, mb_substr( $by, 0, 80 ) ) );
					}
					$options[] = array(
						'value'   => $value,
						'label'   => mb_substr( sanitize_text_field( is_string( $option['label'] ?? null ) ? $option['label'] : $value ), 0, 80 ),
						'orderby' => (string) $option['orderby'],
						'order'   => 'asc' === ( $option['order'] ?? 'desc' ) ? 'asc' : 'desc',
					) + ( 'field' === $option['orderby'] ? array( 'field' => $by ) : array() );
				}
				if ( array() === $options ) {
					/* translators: %s: facet name */
					throw new \InvalidArgumentException( sprintf( __( '"%s": un ordinamento ha bisogno di almeno un\'opzione.', 'xb' ), $facet ) );
				}
				$out['options'] = $options;
				break;
		}
		return $out;
	}

	/**
	 * The meta key of a field a filter may compare: public (Fields::is_public), with a short scalar value, defined
	 * for the facet's type. Null otherwise.
	 */
	public static function field( string $id, string $type ): ?string {
		$provider = Fields::provider( (string) strtok( $id, ':' ) );
		if ( null === $provider || ! $provider->available() ) {
			return null;
		}
		foreach ( $provider->describe() as $field ) {
			if ( $field['id'] !== $id ) {
				continue;
			}
			$types = $field['postTypes'];
			if ( ( array() !== $types && ! in_array( $type, $types, true ) ) || ! in_array( $field['type'], self::FIELD_TYPES, true ) || ! Fields::is_public( $provider, $field ) ) {
				return null;
			}
			return $field['name'];
		}
		return null;
	}

	/** Whether a public field holds numbers (sorted as numbers, not as text). */
	private static function numeric( string $id, string $type ): bool {
		$provider = Fields::provider( (string) strtok( $id, ':' ) );
		foreach ( null === $provider ? array() : $provider->describe() as $field ) {
			if ( $field['id'] === $id ) {
				return in_array( $field['type'], array( 'number', 'range' ), true );
			}
		}
		unset( $type );
		return false;
	}

	/**
	 * Names a parameter may not take: WordPress's query variables (its own, and those of types and taxonomies)
	 * would change the page's main query.
	 *
	 * @return list<string>
	 */
	private static function reserved(): array {
		global $wp;
		$names = self::RESERVED;
		if ( $wp instanceof \WP ) {
			$names = array_merge( $names, $wp->public_query_vars, $wp->private_query_vars );
		}
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			$names[] = $taxonomy->name;
			if ( is_string( $taxonomy->query_var ) ) {
				$names[] = $taxonomy->query_var;
			}
		}
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			$names[] = $type->name;
			if ( is_string( $type->query_var ) ) {
				$names[] = $type->query_var;
			}
		}
		return array_values( array_unique( array_map( 'strval', $names ) ) );
	}

	/**
	 * The state of a facet for this request, as data for expressions; null when it does not exist.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state( string $name ): ?array {
		$memo = ( Preview::active() ? 'dev:' : 'live:' ) . $name;
		if ( ! array_key_exists( $memo, self::$memo ) ) {
			$facet               = self::all()[ $name ] ?? null;
			self::$memo[ $memo ] = null === $facet ? null : self::compute( $name, $facet );
			if ( null !== self::$memo[ $memo ] && ! self::$used ) {
				self::$used = true;
				self::enqueue();
			}
		}
		return self::$memo[ $memo ];
	}

	/**
	 * A facet being edited in the builder, run with test parameters: totals, the first titles and the options.
	 *
	 * @param array<string, mixed> $facet  One of validate().
	 * @param array<mixed>         $params Parameter => text or list of texts.
	 * @return array<string, mixed>
	 */
	public static function test( array $facet, array $params ): array {
		self::$params = $params;
		try {
			$state = self::compute( 'prova', $facet );
		} finally {
			self::$params = null;
		}
		if ( null === $state ) {
			return array( 'error' => __( 'La query dei filtri non esiste o non elenca contenuti.', 'xb' ) );
		}
		return array(
			'total'   => $state['total'],
			'pages'   => $state['pages'],
			'titles'  => array_map( static fn ( \XB\Data\PostNode $p ): string => (string) $p->get( 'title' ), array_slice( $state['items'], 0, 10 ) ),
			'filters' => array_map(
				static fn ( array $f ): array => array(
					'key'     => $f['key'],
					'label'   => $f['label'],
					'options' => array_map( static fn ( array $o ): array => array_intersect_key( $o, array_flip( array( 'value', 'label', 'count', 'selected' ) ) ), $f['options'] ),
				),
				$state['filterList']
			),
		);
	}

	/**
	 * @param array<string, mixed> $facet One of all().
	 * @return array<string, mixed>|null
	 */
	private static function compute( string $name, array $facet ): ?array {
		$presets = new LoopPresets();
		$base    = $presets->facet_base( (string) $facet['query'], (string) $facet['type'] );
		if ( null === $base ) {
			return null;
		}
		/** @var list<array<string, mixed>> $filters */
		$filters = $facet['filters'];
		$type    = (string) $facet['type'];
		$values  = array();
		foreach ( $filters as $filter ) {
			$values[ $filter['key'] ] = self::read( $filter, $type );
		}
		$per_page = (int) $facet['perPage'];
		$page_key = (string) $facet['pageKey'];
		$page     = self::param( $page_key );
		$page     = is_string( $page ) && ctype_digit( $page ) ? max( 1, min( self::MAX_PAGE, (int) $page ) ) : 1;
		// The static ZIP has no WordPress to ask (P9): every result goes on the page, the browser filters them.
		$static = \XB\StaticSite\ExportMode::active() && null === self::$params;
		if ( $static ) {
			$per_page = self::STATIC_MAX;
			$page     = 1;
		}

		$query                   = self::apply( $base, $filters, $values, $type );
		$query['posts_per_page'] = $per_page;
		$query['paged']          = $page;
		unset( $query['offset'], $query['nopaging'] );
		$result = $presets->facet_page( $query, $per_page );
		if ( array() === $result['items'] && $page > 1 ) {
			// Past the last page (WordPress then counts nothing): the last page with results.
			$query['paged'] = 1;
			$total          = $presets->facet_page( $query, $per_page )['total'];
			$page           = max( 1, (int) ceil( $total / $per_page ) );
			$query['paged'] = $page;
			$result         = $presets->facet_page( $query, $per_page );
		}
		$total = $result['total'];
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		$link  = static fn ( array $next, int $to = 1 ): string => self::url( $filters, $next, $page_key, $to );
		$state = array(
			'name'       => $name,
			'items'      => $result['items'],
			'total'      => $total,
			'page'       => $page,
			'pages'      => $pages,
			'perPage'    => $per_page,
			'pageKey'    => $page_key,
			'action'     => self::path(),
			// Hidden fields for the form (see kept()).
			'hidden'     => self::kept(),
			'values'     => array(),
			'filters'    => array(),
			'filterList' => array(),
			'active'     => array(),
			'hasFilters' => false,
			'resetUrl'   => $link( array() ),
			'prevUrl'    => $page > 1 ? $link( $values, $page - 1 ) : null,
			'nextUrl'    => $page < $pages ? $link( $values, $page + 1 ) : null,
			'pageLinks'  => self::page_links( $page, $pages, static fn ( int $to ): string => $link( $values, $to ) ),
		);
		foreach ( $filters as $filter ) {
			$key     = (string) $filter['key'];
			$value   = $values[ $key ];
			$options = self::options( $presets, $base, $filters, $values, $filter, $type, $link );
			$entry   = array(
				'kind'     => $filter['kind'],
				'key'      => $key,
				'label'    => $filter['label'],
				// The form field name: a list for checkboxes, so it works without JavaScript too.
				'name'     => 'taxonomy' === $filter['kind'] && ! empty( $filter['multiple'] ) ? $key . '[]' : $key,
				'multiple' => 'taxonomy' === $filter['kind'] && ! empty( $filter['multiple'] ),
				'value'    => $value,
				'options'  => $options,
			);
			if ( 'range' === $filter['kind'] ) {
				// The two sides, to fill the form's fields.
				[ $entry['min'], $entry['max'] ] = is_string( $value ) ? array_pad( explode( '..', $value, 2 ), 2, '' ) : array( '', '' );
				$entry['type']                       = $filter['type'];
			}
			$state['values'][ $key ]  = $value;
			$state['filters'][ $key ] = $entry;
			$state['filterList'][]    = $entry;
			foreach ( $options as $option ) {
				if ( $option['selected'] && 'sort' !== $filter['kind'] ) {
					$state['active'][] = array(
						'key'   => $key,
						'label' => $filter['label'],
						'value' => $option['value'],
						'text'  => $option['label'],
						'url'   => $option['url'],
					);
				}
			}
			if ( ( 'search' === $filter['kind'] || 'range' === $filter['kind'] ) && null !== $value ) {
				$state['active'][] = array(
					'key'   => $key,
					'label' => $filter['label'],
					'value' => $value,
					'text'  => $value,
					'url'   => $link( array( $key => null ) + $values ),
				);
			}
		}
		$state['hasFilters'] = array() !== $state['active'];
		if ( $static ) {
			self::$exported[ $name ] = array(
				'facet' => $facet,
				'items' => $result['items'],
			);
		}
		return $state;
	}

	/** Whether a loop over this facet's items should mark them for the static script (P9). */
	public static function exported( string $name ): bool {
		return isset( self::$exported[ $name ] );
	}

	/**
	 * The indexes of the facets of this static page (P9), as JSON for the browser: each result with its values for
	 * every filter, its sort keys and its words. Only what the page already shows publicly.
	 */
	public static function print_static_indexes(): void {
		foreach ( self::$exported as $name => $entry ) {
			$facet   = $entry['facet'];
			$type    = (string) $facet['type'];
			$filters = array();
			$sorts   = array();
			foreach ( $facet['filters'] as $filter ) {
				$filters[] = array_intersect_key( $filter, array_flip( array( 'kind', 'key', 'multiple', 'match', 'type' ) ) );
				if ( 'sort' === $filter['kind'] ) {
					foreach ( $filter['options'] as $option ) {
						$sorts[ (string) $option['value'] ] = 'desc' === ( $option['order'] ?? 'asc' ) ? 'desc' : 'asc';
					}
				}
			}
			$items = array();
			foreach ( $entry['items'] as $item ) {
				$id   = (int) $item->id();
				$post = get_post( $id );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				$values = array();
				$keys   = array();
				foreach ( $facet['filters'] as $filter ) {
					$key = (string) $filter['key'];
					if ( 'taxonomy' === $filter['kind'] ) {
						$slugs          = wp_get_post_terms( $id, (string) $filter['taxonomy'], array( 'fields' => 'slugs' ) );
						$values[ $key ] = is_array( $slugs ) ? array_values( array_map( 'strval', $slugs ) ) : array();
					} elseif ( 'field' === $filter['kind'] || 'range' === $filter['kind'] ) {
						$meta           = self::field( (string) $filter['field'], $type );
						$value          = null === $meta ? '' : get_post_meta( $id, $meta, true );
						$values[ $key ] = is_scalar( $value ) ? (string) $value : '';
					} elseif ( 'sort' === $filter['kind'] ) {
						foreach ( $filter['options'] as $option ) {
							$keys[ (string) $option['value'] ] = self::sort_key( $post, $option, $type );
						}
					}
				}
				$items[] = array(
					'id' => $id,
					'v'  => (object) $values,
					's'  => (object) $keys,
					't'  => mb_strtolower( mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $post->post_title . ' ' . $post->post_excerpt . ' ' . strip_shortcodes( $post->post_content ) ) ) ), 0, 2000 ) ),
				);
			}
			$json = wp_json_encode(
				array(
					'name'    => $name,
					'perPage' => (int) $facet['perPage'],
					'filters' => $filters,
					'sorts'   => (object) $sorts,
					'items'   => $items,
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
			);
			printf( '<script type="application/json" id="xb-facets-index-%s">%s</script>' . "\n", esc_attr( $name ), (string) $json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, > and & escaped.
		}
	}

	/**
	 * What a sort option orders a post by, as text or number for the browser.
	 *
	 * @param array<string, mixed> $option
	 */
	private static function sort_key( \WP_Post $post, array $option, string $type ): string|int {
		return match ( $option['orderby'] ?? 'date' ) {
			'title' => mb_strtolower( $post->post_title ),
			'modified' => (string) $post->post_modified_gmt,
			'menu_order' => (int) $post->menu_order,
			'comment_count' => (int) $post->comment_count,
			'rand' => wp_rand( 0, PHP_INT_MAX ),
			'field' => (static function () use ( $post, $option, $type ): string|int {
				$meta  = self::field( (string) ( $option['field'] ?? '' ), $type );
				$value = null === $meta ? '' : get_post_meta( $post->ID, $meta, true );
				return is_numeric( $value ) ? 0 + (int) $value : ( is_scalar( $value ) ? (string) $value : '' );
			} )(),
			default => (string) $post->post_date_gmt,
		};
	}

	/**
	 * The value a visitor chose for a filter, checked: null when absent or not allowed.
	 *
	 * @param array<string, mixed> $filter
	 * @return string|list<string>|null
	 */
	private static function read( array $filter, string $type ): string|array|null {
		$raw = self::param( (string) $filter['key'] );
		switch ( $filter['kind'] ) {
			case 'search':
			case 'field':
				$text = is_string( $raw ) ? trim( mb_substr( $raw, 0, 100 ) ) : '';
				if ( 'field' === $filter['kind'] && null === self::field( (string) $filter['field'], $type ) ) {
					return null; // The field is no longer public: the filter does nothing.
				}
				return '' === $text ? null : $text;
			case 'range':
				if ( null === self::field( (string) $filter['field'], $type ) ) {
					return null;
				}
				// `prezzo=10..50` (links), or the form's `prezzo_min` and `prezzo_max`; either side may be empty.
				$text  = is_string( $raw ) && str_contains( $raw, '..' ) ? $raw : self::text( self::param( $filter['key'] . '_min' ) ) . '..' . self::text( self::param( $filter['key'] . '_max' ) );
				$sides = array_map( 'trim', explode( '..', $text, 2 ) );
				$valid = static fn ( string $v ): bool => '' === $v || ( 'date' === $filter['type'] ? 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) : is_numeric( $v ) && strlen( $v ) <= 20 );
				if ( 2 !== count( $sides ) || ! $valid( $sides[0] ) || ! $valid( $sides[1] ) || ( '' === $sides[0] && '' === $sides[1] ) ) {
					return null;
				}
				return $sides[0] . '..' . $sides[1];
			case 'sort':
				foreach ( $filter['options'] as $option ) {
					if ( $raw === $option['value'] ) {
						return $raw;
					}
				}
				return null;
			case 'taxonomy':
				$slugs = is_array( $raw ) ? $raw : ( is_string( $raw ) ? explode( ',', $raw ) : array() );
				$out   = array();
				foreach ( array_slice( $slugs, 0, self::MAX_VALUES ) as $slug ) {
					$slug = sanitize_title( $slug );
					if ( '' !== $slug && ! in_array( $slug, $out, true ) && get_term_by( 'slug', $slug, (string) $filter['taxonomy'] ) instanceof \WP_Term ) {
						$out[] = $slug;
					}
				}
				if ( empty( $filter['multiple'] ) ) {
					$out = array_slice( $out, 0, 1 );
				}
				return array() === $out ? null : $out;
		}
		return null;
	}

	/**
	 * A parameter as one short text ('' for none or a list).
	 *
	 * @param string|list<string>|null $raw
	 */
	private static function text( string|array|null $raw ): string {
		return is_string( $raw ) ? trim( mb_substr( $raw, 0, 30 ) ) : '';
	}

	/**
	 * A query string parameter, sanitized text (or a list of texts); null when absent.
	 *
	 * @return string|list<string>|null
	 */
	private static function param( string $key ): string|array|null {
		if ( null !== self::$params ) {
			$raw = self::$params[ $key ] ?? null;
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filtering of public content.
			$raw = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : null;
		}
		if ( is_string( $raw ) ) {
			return sanitize_text_field( $raw );
		}
		if ( is_array( $raw ) ) {
			$out = array();
			foreach ( array_slice( $raw, 0, self::MAX_VALUES ) as $v ) {
				if ( is_string( $v ) ) {
					$out[] = sanitize_text_field( $v );
				}
			}
			return $out;
		}
		return null;
	}

	/**
	 * The base query with the chosen values (all of them, or all but one filter's to count its options).
	 *
	 * @param array<string, mixed>                   $base
	 * @param list<array<string, mixed>>             $filters
	 * @param array<string, string|list<string>|null> $values
	 * @return array<string, mixed>
	 */
	private static function apply( array $base, array $filters, array $values, string $type, ?string $except = null ): array {
		$query = $base;
		$tax   = array();
		$meta  = array();
		foreach ( $filters as $filter ) {
			$value = $values[ $filter['key'] ] ?? null;
			if ( null === $value || $filter['key'] === $except ) {
				continue;
			}
			switch ( $filter['kind'] ) {
				case 'search':
					$query['s'] = $value;
					break;
				case 'taxonomy':
					$tax[] = array(
						'taxonomy' => $filter['taxonomy'],
						'field'    => 'slug',
						'terms'    => (array) $value,
						'operator' => 'all' === $filter['match'] ? 'AND' : 'IN',
					);
					break;
				case 'field':
					$key = self::field( (string) $filter['field'], $type );
					if ( null !== $key ) {
						$meta[] = array(
							'key'     => $key,
							'value'   => $value,
							'compare' => '=',
						);
					}
					break;
				case 'range':
					$key = self::field( (string) $filter['field'], $type );
					if ( null !== $key && is_string( $value ) ) {
						[ $min, $max ]       = array_pad( explode( '..', $value, 2 ), 2, '' );
						$cast               = 'date' === $filter['type'] ? 'DATE' : 'NUMERIC';
						$meta[]             = '' !== $min && '' !== $max
							? array( 'key' => $key, 'value' => array( $min, $max ), 'compare' => 'BETWEEN', 'type' => $cast )
							: array( 'key' => $key, 'value' => '' !== $min ? $min : $max, 'compare' => '' !== $min ? '>=' : '<=', 'type' => $cast );
					}
					break;
				case 'sort':
					foreach ( $filter['options'] as $option ) {
						if ( $option['value'] !== $value ) {
							continue;
						}
						$query['order'] = strtoupper( $option['order'] );
						if ( 'field' !== $option['orderby'] ) {
							$query['orderby'] = $option['orderby'];
							continue;
						}
						// By a public field: contents without it are left out, as WordPress does with meta_key.
						$key = self::field( (string) ( $option['field'] ?? '' ), $type );
						if ( null !== $key ) {
							$query['meta_key'] = $key; // phpcs:ignore WordPress.DB.SlowDBQuery
							$query['orderby']  = self::numeric( (string) $option['field'], $type ) ? 'meta_value_num' : 'meta_value';
						}
					}
					break;
			}
		}
		if ( array() !== $tax ) {
			$own                = is_array( $base['tax_query'] ?? null ) && array() !== $base['tax_query'] ? array( $base['tax_query'] ) : array();
			$query['tax_query'] = array_merge( array( 'relation' => 'AND' ), $own, $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( array() !== $meta ) {
			$own                 = is_array( $base['meta_query'] ?? null ) && array() !== $base['meta_query'] ? array( $base['meta_query'] ) : array();
			$query['meta_query'] = array_merge( array( 'relation' => 'AND' ), $own, $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return $query;
	}

	/**
	 * The options of a filter, each with how many results it would give (the other filters applied), whether it is
	 * chosen and the link that chooses or removes it.
	 *
	 * @param array<string, mixed>                    $base
	 * @param list<array<string, mixed>>              $filters
	 * @param array<string, string|list<string>|null> $values
	 * @param array<string, mixed>                    $filter
	 * @param callable(array<string, mixed>): string  $link
	 * @return list<array{value: string, label: string, count: int|null, selected: bool, url: string}>
	 */
	private static function options( LoopPresets $presets, array $base, array $filters, array $values, array $filter, string $type, callable $link ): array {
		$key   = (string) $filter['key'];
		$value = $values[ $key ];
		$out   = array();
		if ( 'sort' === $filter['kind'] ) {
			foreach ( $filter['options'] as $option ) {
				$out[] = array(
					'value'    => $option['value'],
					'label'    => $option['label'],
					'count'    => null,
					'selected' => $option['value'] === $value,
					'url'      => $link( array( $key => $option['value'] ) + $values ),
				);
			}
			return $out;
		}
		if ( 'search' === $filter['kind'] || 'range' === $filter['kind'] ) {
			return array();
		}
		$ids    = $presets->facet_ids( self::apply( $base, $filters, $values, $type, $key ), self::MAX_SCAN );
		$counts = array();
		$labels = array();
		if ( 'taxonomy' === $filter['kind'] ) {
			$terms = array() === $ids ? array() : wp_get_object_terms( $ids, (string) $filter['taxonomy'], array( 'fields' => 'all_with_object_id' ) );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$counts[ $term->slug ] = ( $counts[ $term->slug ] ?? 0 ) + 1;
				$labels[ $term->slug ] = $term->name;
			}
			foreach ( (array) $value as $slug ) {
				$term = get_term_by( 'slug', (string) $slug, (string) $filter['taxonomy'] );
				if ( $term instanceof \WP_Term ) {
					$counts[ $term->slug ] = $counts[ $term->slug ] ?? 0;
					$labels[ $term->slug ] = $term->name;
				}
			}
		} else {
			$meta_key = self::field( (string) $filter['field'], $type );
			if ( null !== $meta_key && array() !== $ids ) {
				update_meta_cache( 'post', $ids );
				foreach ( $ids as $id ) {
					$v = get_post_meta( $id, $meta_key, true );
					if ( is_scalar( $v ) && '' !== (string) $v && mb_strlen( (string) $v ) <= 100 ) {
						$counts[ (string) $v ] = ( $counts[ (string) $v ] ?? 0 ) + 1;
						$labels[ (string) $v ] = (string) $v;
					}
				}
			}
			if ( is_string( $value ) ) {
				$counts[ $value ] = $counts[ $value ] ?? 0;
				$labels[ $value ] = $value;
			}
		}
		uksort( $counts, static fn ( int|string $a, int|string $b ): int => strnatcasecmp( $labels[ (string) $a ], $labels[ (string) $b ] ) );
		foreach ( array_slice( $counts, 0, self::MAX_OPTIONS, true ) as $option => $count ) {
			$option   = (string) $option;
			$chosen   = (array) $value;
			$selected = in_array( $option, $chosen, true );
			if ( ! empty( $filter['multiple'] ) ) {
				$next = $selected ? array_values( array_diff( $chosen, array( $option ) ) ) : array_merge( $chosen, array( $option ) );
			} else {
				$next = $selected ? null : ( 'taxonomy' === $filter['kind'] ? array( $option ) : $option );
			}
			$out[] = array(
				'value'    => $option,
				'label'    => $labels[ $option ],
				'count'    => $count,
				'selected' => $selected,
				'url'      => $link( array( $key => array() === $next ? null : $next ) + $values ),
			);
		}
		return $out;
	}

	/**
	 * A link to this page with these values (only the facet's own parameters; lists as a, b, c) and page.
	 *
	 * @param list<array<string, mixed>>              $filters
	 * @param array<string, string|list<string>|null> $values
	 */
	private static function url( array $filters, array $values, string $page_key, int $page ): string {
		$parts = array();
		foreach ( $filters as $filter ) {
			$value = $values[ $filter['key'] ] ?? null;
			if ( null === $value || array() === $value ) {
				continue;
			}
			$parts[] = rawurlencode( (string) $filter['key'] ) . '=' . implode( ',', array_map( 'rawurlencode', array_map( 'strval', (array) $value ) ) );
		}
		if ( $page > 1 ) {
			$parts[] = rawurlencode( $page_key ) . '=' . $page;
		}
		foreach ( self::kept() as $param ) {
			$parts[] = rawurlencode( $param['name'] ) . '=' . rawurlencode( $param['value'] );
		}
		return self::path() . ( array() === $parts ? '' : '?' . implode( '&', $parts ) );
	}

	/**
	 * Parameters every link and form of a facet carries on: in a signed preview, its token (the filtered page is
	 * still the preview). Nothing else of the query string is ever repeated.
	 *
	 * @return list<array{name: string, value: string}>
	 */
	private static function kept(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the HMAC token is the credential, checked by Preview.
		$token = isset( $_GET[ Preview::PARAM ] ) && is_string( $_GET[ Preview::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ Preview::PARAM ] ) ) : '';
		return Preview::active() && null === self::$params && '' !== $token && Preview::valid( $token ) ? array(
			array(
				'name'  => Preview::PARAM,
				'value' => $token,
			),
		) : array();
	}

	/** The path of the current page, without its query string. */
	private static function path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) && str_starts_with( $path, '/' ) && ! str_starts_with( $path, '//' ) ? $path : '/';
	}

	/**
	 * Links to the pages around the current one, the first and the last; a gap is an entry without number.
	 *
	 * @param callable(int): string $link
	 * @return list<array{number: int|null, url: string|null, current: bool}>
	 */
	private static function page_links( int $page, int $pages, callable $link ): array {
		if ( $pages <= 1 ) {
			return array();
		}
		$out  = array();
		$last = 0;
		for ( $n = 1; $n <= $pages; $n++ ) {
			if ( 1 !== $n && $pages !== $n && abs( $n - $page ) > 2 ) {
				continue;
			}
			if ( $n - $last > 1 ) {
				$out[] = array(
					'number'  => null,
					'url'     => null,
					'current' => false,
				);
			}
			$out[] = array(
				'number'  => $n,
				'url'     => $link( $n ),
				'current' => $n === $page,
			);
			$last  = $n;
		}
		return $out;
	}

	/** The script that updates the results without reloading the page; the forms and links work without it. */
	private static function enqueue(): void {
		if ( did_action( 'wp_footer' ) || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( \XB\StaticSite\ExportMode::active() ) {
			// The static ZIP: the browser filters the results already on the page, with the index in the footer (P9).
			wp_enqueue_script( 'xb-facets-static', plugin_dir_url( XB_FILE ) . 'assets/front/facets-static.js', array(), Plugin::asset_version( 'assets/front/facets-static.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
			if ( false === has_action( 'wp_footer', array( self::class, 'print_static_indexes' ) ) ) {
				add_action( 'wp_footer', array( self::class, 'print_static_indexes' ), 5 );
			}
			return;
		}
		wp_enqueue_script( 'xb-facets', plugin_dir_url( XB_FILE ) . 'assets/front/facets.js', array(), Plugin::asset_version( 'assets/front/facets.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
}
