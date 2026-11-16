<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

use XB\Render\Facets;

/**
 * `facets`: the filters of a list, readable anywhere — docs/spec/facets.md.
 *   {#loop facets.eventi.items as item}       the results of this page
 *   facets.eventi.total, .pages, .nextUrl     counts and links
 *   facets.eventi.filters.categoria.options   options with counts, selection and links
 * An unknown name is null, like any unknown key.
 */
final class FacetsNode implements Node {

	public function get( string $key ): mixed {
		return Facets::state( $key );
	}
}
