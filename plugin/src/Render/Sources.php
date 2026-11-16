<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * JSON data sources — docs/spec/data.md. Named data (a timetable, a list of offices…) kept in the `settings`
 * document, so it has versions, preview and releases like the queries. Pages read it with the `data` root
 * (`{data.orari[0].giorno}`) or loop over it (`{#loop data.orari as h}`). It is data, never markup: every value
 * leaves through the same escaping as any other expression.
 */
final class Sources {

	public const OPTION       = 'xb_sources';
	public const MAX_SOURCES  = 50;
	public const MAX_BYTES    = 262144;
	private const MAX_TOTAL   = 1048576;
	private const MAX_DEPTH   = 10;
	private const MAX_KEY     = 100;
	/** Names a source cannot take: the built-in queries. */
	public const RESERVED = array( 'posts', 'terms', 'main', 'users' );

	/**
	 * The sources a page sees: the dev version in a signed preview, the live one otherwise.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$dev    = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored = is_array( $dev ) && array_key_exists( 'sources', $dev ) ? $dev['sources'] : get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Checks the sources a builder saves; returns them normalized.
	 *
	 * @param mixed        $sources
	 * @param list<string> $taken Names already used by queries.
	 * @return array<string, array<string, mixed>>
	 * @throws \InvalidArgumentException With a message for the builder.
	 */
	public static function validate( mixed $sources, array $taken = array() ): array {
		if ( ! is_array( $sources ) || count( $sources ) > self::MAX_SOURCES ) {
			throw new \InvalidArgumentException( __( 'Elenco delle sorgenti dati non valido.', 'xb' ) );
		}
		$out   = array();
		$total = 0;
		foreach ( $sources as $name => $source ) {
			$name = (string) $name;
			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name ) || in_array( $name, self::RESERVED, true ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Nome di sorgente non valido o riservato: "%s".', 'xb' ), mb_substr( $name, 0, 40 ) ) );
			}
			if ( in_array( $name, $taken, true ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Esiste già una query "%s": una sorgente non può avere lo stesso nome.', 'xb' ), $name ) );
			}
			if ( is_array( $source ) && 'url' === ( $source['type'] ?? null ) ) {
				// Read from an address (doc 26 voce 214): only https, path with names and dots; hosts are checked when read.
				$url  = is_string( $source['url'] ?? null ) ? trim( $source['url'] ) : '';
				$path = is_string( $source['path'] ?? null ) ? trim( $source['path'] ) : '';
				if ( ! str_starts_with( $url, 'https://' ) || strlen( $url ) > 2000 || false === filter_var( $url, FILTER_VALIDATE_URL ) || null !== wp_parse_url( $url, PHP_URL_USER ) || ( '' !== $path && 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}(?:\.[A-Za-z0-9_-]{1,64}){0,9}$/', $path ) ) ) {
					throw new \InvalidArgumentException( sprintf( __( 'Sorgente "%s": serve un indirizzo https:// valido.', 'xb' ), $name ) );
				}
				$out[ $name ] = array(
					'type' => 'url',
					'url'  => $url,
					'path' => $path,
					'ttl'  => RemoteSource::ttl( $source['ttl'] ?? null ),
				);
				continue;
			}
			if ( ! is_array( $source ) || 'json' !== ( $source['type'] ?? 'json' ) || ! array_key_exists( 'data', $source ) ) {
				throw new \InvalidArgumentException( sprintf( __( 'Sorgente "%s" non valida.', 'xb' ), $name ) );
			}
			self::check( $source['data'], $name, 0 );
			$bytes = strlen( (string) wp_json_encode( $source['data'] ) );
			$total += $bytes;
			if ( $bytes > self::MAX_BYTES || $total > self::MAX_TOTAL ) {
				throw new \InvalidArgumentException( sprintf( __( 'La sorgente "%s" è troppo grande.', 'xb' ), $name ) );
			}
			$out[ $name ] = array(
				'type' => 'json',
				'data' => $source['data'],
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * What a source holds: the data written in it, or the data read from its address (RemoteSource).
	 *
	 * @param array<string, mixed> $source
	 */
	public static function data( array $source ): mixed {
		if ( 'url' === ( $source['type'] ?? null ) && is_string( $source['url'] ?? null ) ) {
			return RemoteSource::read( array( 'url' => $source['url'], 'path' => (string) ( $source['path'] ?? '' ), 'ttl' => (int) ( $source['ttl'] ?? 60 ) ) );
		}
		return $source['data'] ?? null;
	}

	/** JSON values only (no objects of other kinds), keys of reasonable size, limited depth. */
	private static function check( mixed $value, string $name, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \InvalidArgumentException( sprintf( __( 'La sorgente "%s" è troppo annidata.', 'xb' ), $name ) );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && ( mb_strlen( $key ) > self::MAX_KEY || in_array( $key, array( '__proto__', 'constructor', 'prototype' ), true ) ) ) {
					throw new \InvalidArgumentException( sprintf( __( 'Chiave non valida nella sorgente "%s".', 'xb' ), $name ) );
				}
				self::check( $item, $name, $depth + 1 );
			}
			return;
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			throw new \InvalidArgumentException( sprintf( __( 'Valore non valido nella sorgente "%s".', 'xb' ), $name ) );
		}
	}
}
