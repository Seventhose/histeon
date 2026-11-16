<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

use XB\Render\Extensions;
use XB\Render\LoopPresets;
use XB\Render\Queries;
use XB\Render\Sources;

/**
 * `data`: named data sources, readable anywhere without a loop — docs/spec/data.md.
 *   data.orari[0].giorno        a JSON source (Sources)
 *   data.ultimi_eventi[0].title a query (custom or built-in: posts, terms, users), with its defaults
 *   data.meteo.oggi             a data provider of the site's PHP (Extensions)
 * Queries run with the same rules as in a loop (published, readable content, same limits). Each name is
 * resolved at most once per render; an unknown name is null, like any unknown key.
 */
final class DataNode implements Node {

	/** @var array<string, mixed> */
	private array $memo = array();

	public function get( string $key ): mixed {
		if ( array_key_exists( $key, $this->memo ) ) {
			return $this->memo[ $key ];
		}
		$sources = Sources::all();
		if ( isset( $sources[ $key ] ) ) {
			return $this->memo[ $key ] = Sources::data( $sources[ $key ] );
		}
		$presets = new LoopPresets();
		$custom  = Queries::presets();
		if ( isset( $custom[ $key ] ) ) {
			return $this->memo[ $key ] = $presets->run_preset( $key, $custom[ $key ], array() );
		}
		if ( in_array( $key, array( 'posts', 'terms', 'users' ), true ) ) {
			return $this->memo[ $key ] = $presets->run( $key, array() );
		}
		// Data of the site's PHP (themes and plugins, docs/spec/php.md).
		return $this->memo[ $key ] = Extensions::data( $key );
	}
}
