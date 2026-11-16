<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Updates;

/**
 * Minisign signatures (https://jedisct1.github.io/minisign/), checked with libsodium — docs/spec/updates.md.
 *
 * Public key: base64 of `Ed` + key id (8 bytes) + Ed25519 key (32). Signature file:
 *
 *     untrusted comment: …
 *     base64( `ED` + key id + Ed25519 signature of BLAKE2b-512(file) )   (`Ed`: of the file itself, legacy)
 *     trusted comment: …
 *     base64( Ed25519 signature of (signature + trusted comment) )
 *
 * Both signatures must verify with the configured key, and the key ids must match. Nothing else is trusted.
 */
final class Minisign {

	/** Signature files are a few hundred bytes. */
	private const MAX_SIGNATURE = 4096;

	/**
	 * The key id and the Ed25519 public key, or an exception.
	 *
	 * @return array{id: non-empty-string, key: non-empty-string}
	 */
	public static function public_key( string $text ): array {
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", trim( $text ) ) ), static fn ( string $l ): bool => '' !== $l && ! str_starts_with( $l, 'untrusted comment:' ) ) );
		$raw   = base64_decode( $lines[0] ?? '', true );
		if ( false === $raw || 42 !== strlen( $raw ) || 'Ed' !== substr( $raw, 0, 2 ) ) {
			throw new \InvalidArgumentException( __( 'Chiave pubblica minisign non valida.', 'xb' ) );
		}
		/** @var non-empty-string $id 8 bytes of a 42-byte key. */
		$id = substr( $raw, 2, 8 );
		/** @var non-empty-string $key 32 bytes. */
		$key = substr( $raw, 10, 32 );
		return array(
			'id'  => $id,
			'key' => $key,
		);
	}

	/**
	 * Whether `$file` (a path) is signed by `$public_key` (minisign text) with `$signature` (the .minisig text).
	 * Returns the trusted comment; throws with a reason when anything does not hold.
	 */
	public static function verify( string $file, string $signature, string $public_key ): string {
		$pk = self::public_key( $public_key );
		if ( strlen( $signature ) > self::MAX_SIGNATURE ) {
			throw new \InvalidArgumentException( __( 'Firma troppo lunga.', 'xb' ) );
		}
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $signature ) ) ), static fn ( string $l ): bool => '' !== $l ) );
		if ( 4 !== count( $lines ) || ! str_starts_with( $lines[0], 'untrusted comment:' ) || ! str_starts_with( $lines[2], 'trusted comment: ' ) ) {
			throw new \InvalidArgumentException( __( 'Firma minisign illeggibile.', 'xb' ) );
		}
		$sig    = base64_decode( $lines[1], true );
		$global = base64_decode( $lines[3], true );
		if ( false === $sig || 74 !== strlen( $sig ) || false === $global || 64 !== strlen( $global ) ) {
			throw new \InvalidArgumentException( __( 'Firma minisign illeggibile.', 'xb' ) );
		}
		$algorithm = substr( $sig, 0, 2 );
		if ( ! hash_equals( $pk['id'], substr( $sig, 2, 8 ) ) ) {
			throw new \InvalidArgumentException( __( 'Firmato con un\'altra chiave.', 'xb' ) );
		}
		if ( 'ED' === $algorithm ) {
			$message = self::blake2b( $file );
		} elseif ( 'Ed' === $algorithm ) {
			$message = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file just downloaded.
		} else {
			throw new \InvalidArgumentException( __( 'Algoritmo di firma sconosciuto.', 'xb' ) );
		}
		/** @var non-empty-string $ed25519 64 bytes of a 74-byte signature. */
		$ed25519 = substr( $sig, 10, 64 );
		if ( ! sodium_crypto_sign_verify_detached( $ed25519, $message, $pk['key'] ) ) {
			throw new \InvalidArgumentException( __( 'La firma non corrisponde al file.', 'xb' ) );
		}
		$comment = substr( $lines[2], strlen( 'trusted comment: ' ) );
		if ( ! sodium_crypto_sign_verify_detached( $global, $ed25519 . $comment, $pk['key'] ) ) {
			throw new \InvalidArgumentException( __( 'Il commento firmato è stato alterato.', 'xb' ) );
		}
		return $comment;
	}

	/** BLAKE2b-512 of a file, read in pieces. */
	public static function blake2b( string $file ): string {
		$state  = sodium_crypto_generichash_init( '', 64 );
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new \InvalidArgumentException( __( 'File da verificare non leggibile.', 'xb' ) );
		}
		try {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 1 << 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk ) {
					break;
				}
				sodium_crypto_generichash_update( $state, $chunk );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return sodium_crypto_generichash_final( $state, 64 );
	}
}
