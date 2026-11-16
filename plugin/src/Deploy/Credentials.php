<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Deploy;

/**
 * Cloudflare settings and API token — threat model T7.
 *
 * Preferred: constants in wp-config.php (XB_CLOUDFLARE_ACCOUNT_ID, XB_CLOUDFLARE_SCRIPT, XB_CLOUDFLARE_API_TOKEN).
 * Otherwise the token is stored encrypted (sodium secretbox, key derived from the site's AUTH salt, which
 * lives in wp-config.php, not in the database): a database dump alone does not reveal it.
 * The token is never returned by the REST API, never logged, never sent to the browser.
 * Required token permission: Account › Workers Scripts › Edit (scoped to the one account).
 */
final class Credentials {

	public const OPTION = 'xb_cloudflare';

	/** @return array{account: string, script: string, token: string, from_config: bool} */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$token  = defined( 'XB_CLOUDFLARE_API_TOKEN' ) && is_string( XB_CLOUDFLARE_API_TOKEN ) ? XB_CLOUDFLARE_API_TOKEN : self::decrypt( (string) ( $stored['token'] ?? '' ) );
		return array(
			'account'     => defined( 'XB_CLOUDFLARE_ACCOUNT_ID' ) && is_string( XB_CLOUDFLARE_ACCOUNT_ID ) ? XB_CLOUDFLARE_ACCOUNT_ID : (string) ( $stored['account'] ?? '' ),
			'script'      => defined( 'XB_CLOUDFLARE_SCRIPT' ) && is_string( XB_CLOUDFLARE_SCRIPT ) ? XB_CLOUDFLARE_SCRIPT : (string) ( $stored['script'] ?? '' ),
			'token'       => $token,
			'from_config' => defined( 'XB_CLOUDFLARE_API_TOKEN' ),
		);
	}

	/**
	 * What the UI may see: never the token itself.
	 *
	 * @return array{account: string, script: string, tokenSet: bool, fromConfig: bool, ready: bool, unreadable: bool}
	 */
	public static function public_view(): array {
		$c = self::get();
		$stored = get_option( self::OPTION, array() );
		$cipher = is_array( $stored ) ? (string) ( $stored['token'] ?? '' ) : '';
		return array(
			'account'    => $c['account'],
			'script'     => $c['script'],
			'tokenSet'   => '' !== $c['token'],
			'fromConfig' => $c['from_config'],
			'ready'      => self::ready(),
			// Stored but not decryptable: the salts in wp-config.php changed since it was saved.
			'unreadable' => ! $c['from_config'] && '' !== $cipher && '' === $c['token'],
		);
	}

	public static function ready(): bool {
		$c = self::get();
		return 1 === preg_match( '/^[0-9a-f]{32}$/', $c['account'] ) && 1 === preg_match( '/^[a-z0-9-]{1,63}$/', $c['script'] ) && '' !== $c['token'];
	}

	/** Saves account and script; the token only when a new one is given ('' keeps the current one). */
	public static function save( string $account, string $script, string $token ): void {
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $account ) ) {
			throw new \InvalidArgumentException( __( 'Account ID non valido (32 caratteri esadecimali).', 'xb' ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9-]{1,63}$/', $script ) ) {
			throw new \InvalidArgumentException( __( 'Nome del Worker non valido (minuscole, cifre e trattini).', 'xb' ) );
		}
		if ( '' !== $token && 1 !== preg_match( '/^[A-Za-z0-9_-]{20,200}$/', $token ) ) {
			throw new \InvalidArgumentException( __( 'Token API non valido.', 'xb' ) );
		}
		if ( '' !== $token && ! self::salts_in_config() ) {
			throw new \InvalidArgumentException( __( 'Le chiavi segrete di wp-config.php (AUTH_KEY, AUTH_SALT) mancano o sono quelle di esempio: senza, il token cifrato sarebbe leggibile da chi ha il database. Definiscile, oppure metti il token in XB_CLOUDFLARE_API_TOKEN.', 'xb' ) );
		}
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		update_option(
			self::OPTION,
			array(
				'account' => $account,
				'script'  => $script,
				'token'   => '' === $token ? (string) ( $current['token'] ?? '' ) : self::encrypt( $token ),
			),
			false
		);
	}

	public static function forget_token(): void {
		$current = get_option( self::OPTION, array() );
		if ( is_array( $current ) ) {
			unset( $current['token'] );
			update_option( self::OPTION, $current, false );
		}
	}

	/** The encryption key derives from secrets that live in wp-config.php, never from the database. */
	public static function salts_in_config(): bool {
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT' ) as $name ) {
			$value = defined( $name ) ? constant( $name ) : '';
			if ( ! is_string( $value ) || strlen( $value ) < 32 || str_contains( $value, 'put your unique phrase here' ) ) {
				return false;
			}
		}
		return true;
	}

	/** A key for one purpose (`$info`), derived from the salts of wp-config.php. */
	private static function key( string $info = 'xb-cloudflare-token-v1' ): string {
		return hash_hkdf( 'sha256', (string) constant( 'AUTH_KEY' ) . (string) constant( 'AUTH_SALT' ), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $info );
	}

	public static function encrypt( string $plain ): string {
		return self::encrypt_with( $plain, 'xb-cloudflare-token-v1' );
	}

	public static function decrypt( string $stored ): string {
		return self::decrypt_with( $stored, 'xb-cloudflare-token-v1' );
	}

	/** Encrypted at rest with a key of its own purpose (the site's signing key uses `xb-signing-key-v1`, P8). */
	public static function encrypt_with( string $plain, string $info ): string {
		if ( ! self::salts_in_config() ) {
			throw new \InvalidArgumentException( __( 'Chiavi di wp-config.php mancanti.', 'xb' ) );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key( $info ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt_with( string $stored, string $info ): string {
		if ( ! str_starts_with( $stored, 'v1:' ) || ! self::salts_in_config() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key( $info ) );
		return false === $plain ? '' : $plain;
	}
}
