<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

use XB\Fields\Fields;
use XB\Fields\OptionsProvider;

/**
 * `site.options`: the options pages of the field plugins (D5), by slug, dashes read as underscores
 * (`site.options.dati_sito.orari` for the page `dati-sito`). Each page gives only its public fields.
 */
final class OptionsNode implements Node {

	public function get( string $key ): mixed {
		foreach ( Fields::providers() as $provider ) {
			if ( ! $provider instanceof OptionsProvider || ! Fields::safe( static fn (): bool => $provider->available(), false ) ) {
				continue;
			}
			foreach ( Fields::safe( static fn (): array => $provider->options_pages(), array() ) as $slug => $unused ) {
				if ( $slug === $key || str_replace( '-', '_', $slug ) === $key ) {
					return new OptionsPageNode( $provider, $slug );
				}
			}
		}
		return null;
	}
}
