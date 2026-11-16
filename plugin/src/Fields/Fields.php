<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Fields;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * Custom fields readable by pages — docs/spec/content.md §4, threat model T3.
 *
 * Fields defined in XB (Content Hub) are content meant for the site: always readable. Fields of other plugins
 * (ACF, SCF, JetEngine, Meta Box) may hold anything, internal notes included: a page reads one only when an administrator
 * made it public in the builder (the `publicFields` list of the `settings` document, with versions and
 * releases) or when the plugin itself already publishes it in the REST API. Password fields and protected keys
 * (`_name`) never are.
 */
final class Fields {

	public const OPTION     = 'xb_public_fields';
	public const MAX_PUBLIC = 1000;
	private const ID        = '/^[a-z][a-z0-9]{0,19}:[A-Za-z0-9_:-]{1,150}$/';

	/** @var list<Provider>|null */
	private static ?array $providers = null;

	/** @return list<Provider> */
	public static function providers(): array {
		if ( null === self::$providers ) {
			$list = array( new XbFields(), new AcfFields(), new JetEngineFields(), new MetaBoxFields() );
			/** @var mixed $extra Providers added by other plugins. */
			$extra = apply_filters( 'xb_field_providers', array() );
			foreach ( is_array( $extra ) ? $extra : array() as $provider ) {
				if ( $provider instanceof Provider && 1 === preg_match( '/^[a-z][a-z0-9]{0,19}$/', $provider->id() ) ) {
					$list[] = $provider;
				}
			}
			self::$providers = $list;
		}
		return self::$providers;
	}

	public static function provider( string $id ): ?Provider {
		foreach ( self::providers() as $provider ) {
			if ( $provider->id() === $id ) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Test helper: use these providers (null: the default ones).
	 *
	 * @param list<Provider>|null $providers
	 */
	public static function use( ?array $providers ): void {
		self::$providers = $providers;
	}

	/**
	 * Field ids an administrator made public: the dev list in a signed preview, the live one otherwise.
	 *
	 * @return list<string>
	 */
	public static function public_ids(): array {
		$dev    = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored = is_array( $dev ) && array_key_exists( 'publicFields', $dev ) ? $dev['publicFields'] : get_option( self::OPTION, array() );
		return array_values( array_filter( is_array( $stored ) ? $stored : array(), 'is_string' ) );
	}

	/** @param array<string, mixed> $field One of Provider::fields_for(). */
	public static function is_public( Provider $provider, array $field ): bool {
		$name = (string) ( $field['name'] ?? '' );
		if ( '' === $name || str_starts_with( $name, '_' ) || 'password' === ( $field['type'] ?? '' ) ) {
			return false;
		}
		if ( 'xb' === $provider->id() ) {
			return ! array_key_exists( 'public', $field ) || true === $field['public'];
		}
		if ( ! empty( $field['rest'] ) ) {
			return true;
		}
		return in_array( (string) ( $field['id'] ?? '' ), self::public_ids(), true );
	}

	/**
	 * Checks the list of public field ids a builder saves.
	 *
	 * @return list<string>
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	public static function validate( mixed $ids ): array {
		if ( ! is_array( $ids ) || count( $ids ) > self::MAX_PUBLIC ) {
			throw new \InvalidArgumentException( __( 'Elenco dei campi pubblici non valido.', 'xb' ) );
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::ID, $id ) ) {
				throw new \InvalidArgumentException( __( 'Identificativo di campo non valido.', 'xb' ) );
			}
			$out[] = $id;
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * Every field of every active plugin, with whether pages can read it.
	 *
	 * @param list<string>|null $public Ids to treat as public (the builder's dev list); the live ones otherwise.
	 * @return list<array{id: string, label: string, fields: list<array<string, mixed>>}>
	 */
	public static function describe( ?array $public = null ): array {
		$public ??= self::public_ids();
		$out      = array();
		foreach ( self::providers() as $provider ) {
			if ( 'xb' === $provider->id() || ! self::safe( static fn (): bool => $provider->available(), false ) ) {
				continue;
			}
			$fields = array();
			foreach ( self::safe( static fn (): array => $provider->describe(), array() ) as $field ) {
				$field['readable'] = 'password' !== $field['type'] && ! str_starts_with( $field['name'], '_' ) && ( $field['rest'] || in_array( $field['id'], $public, true ) );
				$fields[]          = $field;
			}
			$out[] = array(
				'id'     => $provider->id(),
				'label'  => $provider->label(),
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * Runs a call into another plugin: an error there leaves the field empty instead of breaking the page.
	 *
	 * @template T
	 * @param callable(): T $call
	 * @param T             $fallback
	 * @return T
	 */
	public static function safe( callable $call, mixed $fallback ): mixed {
		try {
			return $call();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
				error_log( 'Histeon fields: ' . $e->getMessage() );
			}
			return $fallback;
		}
	}
}
