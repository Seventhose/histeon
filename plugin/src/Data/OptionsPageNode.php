<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

use XB\Fields\Fields;
use XB\Fields\OptionsProvider;
use XB\Fields\Provider;

/** One options page (D5): its fields that an administrator made public, read-only. */
final class OptionsPageNode implements Node {

	public function __construct(
		private readonly OptionsProvider&Provider $provider,
		private readonly string $page,
	) {}

	public function get( string $key ): mixed {
		$provider = $this->provider;
		$page     = $this->page;
		$field    = Fields::safe( static fn (): array => $provider->options_fields( $page ), array() )[ $key ] ?? null;
		// The REST flag of a group says nothing about options: only an administrator's choice makes them public.
		if ( ! is_array( $field ) || ! Fields::is_public( $provider, array( 'rest' => false ) + $field ) ) {
			return null;
		}
		return Fields::safe( static fn (): mixed => $provider->options_value( $page, $field ), null );
	}
}
