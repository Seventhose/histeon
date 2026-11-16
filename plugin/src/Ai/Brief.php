<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

/**
 * What a remote agent reads before working (docs/spec/ai.md): the agents' manual, the documentation by topic and
 * the site brief, the same the local connector gives (docs/spec/connector.md §5).
 *
 * Manual and documentation come from the connector file shipped with the plugin, where they are embedded as JSON
 * literals (scripts/sync-manual.mjs): one source for local and remote agents. The brief is built from the builder's
 * own REST routes, so it holds the development version and the user's own permissions apply.
 */
final class Brief {

	/** Names per framework group, and entries per data list, as in the builder (BRIEF_GROUP, BRIEF_LIST). */
	private const GROUP = 80;
	private const LIST  = 50;

	private static function connector(): string {
		return dirname( XB_FILE ) . '/connector/histos-connector.mjs';
	}

	/** A JSON literal embedded in the connector as `export const NAME = …;`, or null. */
	private static function embedded( string $name ): mixed {
		$js = is_readable( self::connector() ) ? (string) file_get_contents( self::connector() ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( 1 !== preg_match( '/^export const ' . $name . ' = (.+);$/m', $js, $m ) ) {
			return null;
		}
		return json_decode( $m[1], true );
	}

	public static function manual(): string {
		$manual = self::embedded( 'MANUAL' );
		return is_string( $manual ) ? $manual : '';
	}

	/** @return list<array{key: string, title: string, summary: string, text: string}> */
	public static function docs(): array {
		$out = array();
		foreach ( (array) self::embedded( 'DOCS' ) as $topic ) {
			if ( is_array( $topic ) && is_string( $topic['key'] ?? null ) && is_string( $topic['text'] ?? null ) ) {
				$out[] = array(
					'key'     => $topic['key'],
					'title'   => (string) ( $topic['title'] ?? '' ),
					'summary' => (string) ( $topic['summary'] ?? '' ),
					'text'    => $topic['text'],
				);
			}
		}
		return $out;
	}

	/**
	 * One topic, or the index (first topic) when none or an unknown one is asked, as `histos-connector docs`.
	 *
	 * @return array{key: string, title: string, summary: string, text: string}
	 */
	public static function doc( string $topic ): array {
		$docs = self::docs();
		foreach ( $docs as $doc ) {
			if ( $doc['key'] === $topic ) {
				return $doc;
			}
		}
		$index = $docs[0] ?? array( 'key' => 'indice', 'title' => '', 'summary' => '', 'text' => '' );
		if ( '' !== $topic ) {
			$index['text'] = sprintf( /* translators: 1: topic asked, 2: topics available */ __( 'Argomento sconosciuto: %1$s. Disponibili: %2$s', 'xb' ), $topic, implode( ', ', array_column( $docs, 'key' ) ) ) . "\n\n" . $index['text'];
		}
		return $index;
	}

	/** @return array<string, mixed> The data of a builder route, as the current user would get it; empty on refusal. */
	private static function route( string $path ): array {
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/xb/v1/' . $path ) );
		$data     = $response->is_error() ? null : $response->get_data();
		return is_array( $data ) ? $data : ( is_object( $data ) ? (array) $data : array() );
	}

	/**
	 * The site brief: the shape of the builder's getBrief() (editor/src/lib/publicApi.ts), without the open
	 * document (a remote agent has no tab).
	 *
	 * @return array<string, mixed>
	 */
	public static function site(): array {
		$framework = self::route( 'framework' );
		$groups    = array();
		$more      = array();
		$values    = array();
		$tokens    = array();
		foreach ( (array) ( $framework['variables'] ?? array() ) as $v ) {
			if ( ! is_array( $v ) || ! is_string( $v['name'] ?? null ) || ! is_string( $v['group'] ?? null ) ) {
				continue;
			}
			if ( 'xb' === $v['group'] ) {
				$tokens[] = $v['name'];
			} elseif ( count( $groups[ $v['group'] ] ?? array() ) < self::GROUP ) {
				$groups[ $v['group'] ][] = $v['name'];
			} else {
				$more[ $v['group'] ] = ( $more[ $v['group'] ] ?? 0 ) + 1;
				continue;
			}
			if ( is_string( $v['value'] ?? null ) ) {
				$values[ $v['name'] ] = $v['value'];
			}
		}
		$styles    = self::route( 'styles' );
		$selectors = array_values( array_filter( array_map( static fn ( mixed $s ): mixed => is_array( $s ) ? ( $s['selector'] ?? null ) : null, (array) ( $styles['styles'] ?? array() ) ), 'is_string' ) );
		$bem       = self::bem_blocks( $selectors );
		$queries   = self::route( 'queries' );
		$content   = self::route( 'content' );
		$facets    = self::route( 'facets' );
		$extend    = self::route( 'extensions' );
		$data_more = array();
		$cap       = static function ( string $key, array $list ) use ( &$data_more ): array {
			if ( count( $list ) > self::LIST ) {
				$data_more[ $key ] = count( $list ) - self::LIST;
			}
			return array_slice( $list, 0, self::LIST );
		};
		$loop_queries = array();
		foreach ( (array) ( $queries['presets'] ?? array() ) as $name => $preset ) {
			$loop_queries[] = array(
				'name'   => (string) $name,
				'type'   => is_array( $preset ) ? (string) ( $preset['type'] ?? '' ) : '',
				'params' => self::params( is_array( $preset ) ? ( $preset['args'] ?? array() ) : array() ),
			);
		}
		$sources = array();
		foreach ( array_keys( (array) ( $queries['sources'] ?? array() ) ) as $name ) {
			$sources[] = array( 'name' => (string) $name, 'kind' => 'json' );
		}
		foreach ( (array) ( $extend['data'] ?? array() ) as $d ) {
			if ( is_array( $d ) && is_string( $d['name'] ?? null ) ) {
				$sources[] = array( 'name' => $d['name'], 'kind' => 'php' ) + ( '' !== (string) ( $d['description'] ?? '' ) ? array( 'description' => (string) $d['description'] ) : array() );
			}
		}
		$facet_list = array();
		foreach ( (array) ( $facets['facets'] ?? array() ) as $name => $facet ) {
			$facet_list[] = array(
				'name'    => (string) $name,
				'query'   => is_array( $facet ) ? (string) ( $facet['query'] ?? '' ) : '',
				'filters' => is_array( $facet ) ? array_values( array_filter( array_map( static fn ( mixed $f ): mixed => is_array( $f ) ? ( $f['key'] ?? null ) : null, (array) ( $facet['filters'] ?? array() ) ), 'is_string' ) ) : array(),
			);
		}
		$listable   = array_column( (array) ( $queries['types'] ?? array() ), 'name' );
		$own_fields = (array) ( ( (array) ( $content['content'] ?? array() ) )['fields'] ?? array() );
		$types      = array();
		foreach ( (array) ( $content['postTypes'] ?? array() ) as $type ) {
			if ( ! is_array( $type ) || ! is_string( $type['name'] ?? null ) || ( array() !== $listable && ! in_array( $type['name'], $listable, true ) ) ) {
				continue;
			}
			$types[] = array(
				'name'   => $type['name'],
				'label'  => (string) ( $type['label'] ?? '' ),
				'fields' => array_values( array_filter( array_map( static fn ( mixed $f ): mixed => is_array( $f ) ? ( $f['name'] ?? null ) : null, (array) ( $own_fields[ $type['name'] ] ?? array() ) ), 'is_string' ) ),
			);
		}
		$readable = (array) ( $content['publicFields'] ?? array() );
		$public   = array();
		foreach ( (array) ( $content['providers'] ?? array() ) as $provider ) {
			foreach ( is_array( $provider ) ? (array) ( $provider['fields'] ?? array() ) : array() as $field ) {
				if ( is_array( $field ) && in_array( $field['id'] ?? null, $readable, true ) && is_string( $field['name'] ?? null ) ) {
					$public[] = $field['name'];
				}
			}
		}
		$components = array();
		foreach ( self::route( 'components' ) as $c ) {
			if ( is_array( $c ) && is_string( $c['ref'] ?? null ) ) {
				$components[] = array(
					'ref'   => $c['ref'],
					'title' => (string) ( $c['title'] ?? '' ),
					'props' => array_map( 'strval', array_keys( (array) ( $c['props'] ?? array() ) ) ),
				);
			}
		}
		$active = (array) ( $framework['active'] ?? array() );
		return array(
			'document'       => null,
			'framework'      => array(
				'name'       => (string) ( $active['label'] ?? '' ),
				'tokens'     => $tokens,
				'groups'     => (object) $groups,
				'more'       => (object) $more,
				'values'     => (object) $values,
				'classCount' => count( (array) ( $framework['classes'] ?? array() ) ),
			),
			'mediaQueries'   => (object) (array) ( $styles['queries'] ?? array() ),
			'data'           => array(
				'loopQueries'  => $cap( 'loopQueries', $loop_queries ),
				'sources'      => $cap( 'sources', $sources ),
				'facets'       => $cap( 'facets', $facet_list ),
				'types'        => $cap( 'types', $types ),
				'publicFields' => $cap( 'publicFields', $public ),
				'more'         => (object) $data_more,
			),
			'blocks'         => $bem['blocks'],
			'otherSelectors' => $bem['other'],
			'components'     => $components,
		);
	}

	/**
	 * `$name` placeholders of a loop query, wherever they sit in its arguments (queryParams in publicApi.ts).
	 *
	 * @return list<string>
	 */
	public static function params( mixed $args ): array {
		$found = array();
		$walk  = static function ( mixed $value ) use ( &$walk, &$found ): void {
			if ( is_string( $value ) && 1 === preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $value, $m ) && ! in_array( $m[1], array( 'today', 'now' ), true ) ) {
				$found[ $m[1] ] = true;
			} elseif ( is_array( $value ) ) {
				array_map( $walk, $value );
			}
		};
		$walk( $args );
		return array_map( 'strval', array_keys( $found ) );
	}

	/**
	 * Selectors grouped as BEM blocks (`.card`, `.card__title`, `.card--dark`), the rest apart (bemBlocks in
	 * publicApi.ts).
	 *
	 * @param list<string> $selectors
	 * @return array{blocks: list<array{block: string, parts: list<string>}>, other: list<string>}
	 */
	public static function bem_blocks( array $selectors ): array {
		$blocks = array();
		$other  = array();
		foreach ( $selectors as $selector ) {
			if ( 1 !== preg_match( '/^\.([a-z][a-z0-9]*(?:-[a-z0-9]+)*)((?:__[a-z0-9]+(?:-[a-z0-9]+)*)?(?:--[a-z0-9]+(?:-[a-z0-9]+)*)?)$/', trim( $selector ), $m ) ) {
				$other[] = $selector;
				continue;
			}
			$blocks[ $m[1] ] ??= array();
			if ( '' !== $m[2] ) {
				$blocks[ $m[1] ][ $m[2] ] = true;
			}
		}
		ksort( $blocks );
		$out = array();
		foreach ( $blocks as $block => $parts ) {
			$names = array_map( 'strval', array_keys( $parts ) );
			sort( $names );
			$out[] = array(
				'block' => (string) $block,
				'parts' => $names,
			);
		}
		return array(
			'blocks' => $out,
			'other'  => array_slice( $other, 0, 100 ),
		);
	}
}
