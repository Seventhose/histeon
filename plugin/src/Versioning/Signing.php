<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Plugin;

/**
 * Signed exports between sites (P8, docs/spec/signing.md): an Ed25519 key per site, made on the site and kept
 * encrypted at rest; the signature covers the whole file but itself; the site that imports trusts a key the first
 * time an administrator says so (its fingerprint compared with the source's), follows a rotation vouched for by a
 * trusted key, and refuses revoked keys and broken signatures. libsodium only.
 */
final class Signing {

	/** This site's key: {public, secret (encrypted), previous?: {from, sig}} (base64). */
	public const KEY_OPTION = 'xb_signing_key';
	/** Keys this site trusts: list of {key, fingerprint, site, added, by, revoked}. */
	public const TRUSTED_OPTION = 'xb_trusted_keys';
	/** Refuse unsigned files, even when someone accepts them in the preview. */
	public const REQUIRE_OPTION = 'xb_require_signature';

	private const SIGNED   = 'xb-signed-v1|';
	private const ROTATION = 'xb-key-rotation-v1|';
	private const MAX_KEYS = 200;

	/** Whether this site can sign: libsodium and the salts of wp-config.php to encrypt its private key. */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_sign_keypair' ) && \XB\Deploy\Credentials::salts_in_config();
	}

	/**
	 * Whether this site has a key it can no longer read (the salts of wp-config.php changed since): its exports leave
	 * without a signature until an administrator makes a new key.
	 */
	public static function unreadable(): bool {
		$stored = get_option( self::KEY_OPTION );
		return self::available() && is_array( $stored ) && is_string( $stored['public'] ?? null ) && is_string( $stored['secret'] ?? null ) && null === self::own();
	}

	/** The warning for administrators while the key cannot be read (note 98 of the 3 November 2026 audit). */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::unreadable() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Histeon: la chiave di firma di questo sito non si legge più.', 'xb' ),
			esc_html__( 'Le chiavi di wp-config.php sono cambiate: gli export escono senza firma.', 'xb' ),
			esc_url( \XB\Admin\SettingsScreen::url() . '#xb-signing' ),
			esc_html__( 'Genera una nuova chiave', 'xb' )
		);
	}

	/** This site's public key (raw bytes), made on first use; '' when the site cannot sign. */
	public static function public_key(): string {
		$own = self::own();
		return null === $own ? '' : $own['public'];
	}

	/** SHA-256 of a public key in 16 groups of 4 hexadecimal digits: what two people compare. */
	public static function fingerprint( string $public ): string {
		return implode( ' ', str_split( strtoupper( hash( 'sha256', $public ) ), 4 ) );
	}

	/** A new key for this site, vouched for by the old one; the old private key is gone. */
	public static function rotate(): void {
		if ( self::unreadable() ) {
			// The old key cannot vouch for a new one: a fresh key, which the sites that import compare again.
			delete_option( self::KEY_OPTION );
			self::own();
			return;
		}
		$old = self::own();
		if ( null === $old ) {
			return;
		}
		$pair = sodium_crypto_sign_keypair();
		$new  = sodium_crypto_sign_publickey( $pair );
		self::store( sodium_crypto_sign_secretkey( $pair ), $new, array( 'from' => self::b64( $old['public'] ), 'sig' => self::b64( sodium_crypto_sign_detached( self::ROTATION . $new, $old['secret'] ) ) ) );
		self::audit( 'signing_key_rotated', array( 'fingerprint' => self::fingerprint( $new ) ) );
	}

	/**
	 * A file signed with this site's key (and the proof of the last rotation); unchanged when the site cannot sign.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function sign( array $data ): array {
		$own = self::own();
		if ( null === $own ) {
			return $data;
		}
		return self::sign_with( $data, $own['secret'], home_url( '/' ), $own['previous'] );
	}

	/**
	 * A file signed with a given secret key. `$rotation`: the proof stored at rotation (`{from, sig}`, base64), or,
	 * in tests standing for another site, `{from, secret}` (raw) to make it.
	 *
	 * @param array<string, mixed>      $data
	 * @param non-empty-string          $secret
	 * @param array<string, mixed>|null $rotation
	 * @return array<string, mixed>
	 */
	public static function sign_with( array $data, string $secret, string $site, ?array $rotation = null ): array {
		unset( $data['signature'] );
		$rotation_secret = is_array( $rotation ) && is_string( $rotation['secret'] ?? null ) ? $rotation['secret'] : '';
		$public    = sodium_crypto_sign_publickey_from_secretkey( $secret );
		$signature = array(
			'alg'  => 'ed25519',
			'key'  => self::b64( $public ),
			'site' => $site,
			'sig'  => self::b64( sodium_crypto_sign_detached( self::message( $data ), $secret ) ),
		);
		if ( is_array( $rotation ) && is_string( $rotation['from'] ?? null ) ) {
			$signature['rotation'] = '' !== $rotation_secret
				? array(
					'from' => self::b64( $rotation['from'] ),
					'sig'  => self::b64( sodium_crypto_sign_detached( self::ROTATION . $public, $rotation_secret ) ),
				)
				: array(
					'from' => $rotation['from'],
					'sig'  => (string) ( $rotation['sig'] ?? '' ),
				);
		}
		$data['signature'] = $signature;
		return $data;
	}

	/**
	 * Who signed a file, as this site sees it. `status`: unsigned, invalid, own, trusted, rotated (a new key vouched
	 * for by a trusted one), new (a valid signature of an unknown key), revoked.
	 *
	 * @param array<string, mixed> $data
	 * @return array{status: string, fingerprint: string, site: string, key: string, from: string}
	 */
	public static function check( array $data ): array {
		$out = array(
			'status'      => 'unsigned',
			'fingerprint' => '',
			'site'        => '',
			'key'         => '',
			'from'        => '',
		);
		$sig = $data['signature'] ?? null;
		if ( null === $sig ) {
			return $out;
		}
		$out['status'] = 'invalid';
		$key           = is_array( $sig ) && is_string( $sig['key'] ?? null ) ? base64_decode( $sig['key'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$bytes         = is_array( $sig ) && is_string( $sig['sig'] ?? null ) ? base64_decode( $sig['sig'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $sig ) || 'ed25519' !== ( $sig['alg'] ?? null ) || ! is_string( $key ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) || ! is_string( $bytes ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $bytes ) ) {
			return $out;
		}
		$out['fingerprint'] = self::fingerprint( $key );
		$out['site']        = is_string( $sig['site'] ?? null ) ? mb_substr( sanitize_text_field( $sig['site'] ), 0, 200 ) : '';
		$out['key']         = $key;
		unset( $data['signature'] );
		try {
			$message = self::message( $data );
		} catch ( \JsonException ) {
			return $out;
		}
		if ( ! sodium_crypto_sign_verify_detached( $bytes, $message, $key ) ) {
			return $out;
		}
		// The site the signature covers: the one of the signature block is only declared (it is not signed).
		if ( is_string( $data['site'] ?? null ) ) {
			$out['site'] = mb_substr( sanitize_text_field( $data['site'] ), 0, 200 );
		}
		$own = get_option( self::KEY_OPTION );
		if ( is_array( $own ) && is_string( $own['public'] ?? null ) && hash_equals( $own['public'], self::b64( $key ) ) ) {
			$out['status'] = 'own';
			return $out;
		}
		$known = self::entry( $key );
		if ( null !== $known ) {
			$out['status'] = $known['revoked'] ? 'revoked' : 'trusted';
			return $out;
		}
		$rotation = is_array( $sig['rotation'] ?? null ) ? $sig['rotation'] : null;
		$from     = is_string( $rotation['from'] ?? null ) ? (string) base64_decode( $rotation['from'], true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$proof    = is_string( $rotation['sig'] ?? null ) ? (string) base64_decode( $rotation['sig'], true ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$previous = '' === $from ? null : self::entry( $from );
		if ( null !== $previous && ! $previous['revoked'] && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $from ) && SODIUM_CRYPTO_SIGN_BYTES === strlen( $proof ) && sodium_crypto_sign_verify_detached( $proof, self::ROTATION . $key, $from ) ) {
			$out['status'] = 'rotated';
			$out['from']   = $from;
			return $out;
		}
		$out['status'] = 'new';
		return $out;
	}

	/**
	 * Applies the import rules to a checked file (docs/spec/signing.md §4) and, when writing, records what the
	 * administrator chose. Throws with a message for the person importing.
	 *
	 * @param array{status: string, fingerprint: string, site: string, key: string, from: string} $check
	 * @param array<string, mixed>                                                   $options `trust`, `allowUnsigned`.
	 * @throws \InvalidArgumentException
	 */
	public static function accept( array $check, array $options ): void {
		switch ( $check['status'] ) {
			case 'invalid':
				throw new \InvalidArgumentException( __( 'La firma del file non corrisponde al contenuto: il file è stato cambiato dopo la firma, o la firma è rovinata.', 'xb' ) );
			case 'revoked':
				/* translators: %s: key fingerprint */
				throw new \InvalidArgumentException( sprintf( __( 'Il file è firmato con una chiave revocata (%s).', 'xb' ), $check['fingerprint'] ) );
			case 'new':
				if ( empty( $options['trust'] ) || ! current_user_can( 'manage_options' ) ) {
					/* translators: 1: declared site, 2: key fingerprint */
					throw new \InvalidArgumentException( sprintf( __( 'Il file è firmato da una chiave che questo sito non conosce (sito dichiarato: %1$s, impronta %2$s). Confronta l\'impronta con quella del sito di origine e scegli «Mi fido di questa chiave».', 'xb' ), $check['site'], $check['fingerprint'] ) );
				}
				self::trust( $check['key'], $check['site'] );
				return;
			case 'rotated':
				self::trust( $check['key'], $check['site'], $check['from'] );
				return;
			case 'unsigned':
				if ( get_option( self::REQUIRE_OPTION ) ) {
					throw new \InvalidArgumentException( __( 'Il file non è firmato, e questo sito accetta solo file firmati (Impostazioni → Firma degli export).', 'xb' ) );
				}
				if ( empty( $options['allowUnsigned'] ) ) {
					throw new \InvalidArgumentException( __( 'Il file non è firmato: non si sa da quale sito arriva. Scegli «Importa anche senza firma» se sai da dove viene.', 'xb' ) );
				}
				self::audit( 'import_unsigned', array() );
				return;
			default:
				return;
		}
	}

	/**
	 * Trusts a key. `$retire`: the key a rotation came from, which stops counting here.
	 */
	public static function trust( string $public, string $site, string $retire = '' ): void {
		$list = self::trusted();
		foreach ( $list as $entry ) {
			if ( hash_equals( $entry['key'], self::b64( $public ) ) ) {
				return;
			}
		}
		if ( '' !== $retire ) {
			$list = array_values( array_filter( $list, static fn ( array $entry ): bool => ! hash_equals( $entry['key'], self::b64( $retire ) ) ) );
		}
		$list[] = array(
			'key'         => self::b64( $public ),
			'fingerprint' => self::fingerprint( $public ),
			'site'        => mb_substr( sanitize_text_field( $site ), 0, 200 ),
			'added'       => gmdate( 'c' ),
			'by'          => get_current_user_id(),
			'revoked'     => false,
		);
		// Only trusted keys make room for new ones: a revoked key is never forgotten, or its files would be «new» again.
		$live = array_slice( array_keys( array_filter( $list, static fn ( array $entry ): bool => ! $entry['revoked'] ) ), -self::MAX_KEYS );
		$list = array_values( array_filter( $list, static fn ( array $entry, int $i ): bool => $entry['revoked'] || in_array( $i, $live, true ), ARRAY_FILTER_USE_BOTH ) );
		update_option( self::TRUSTED_OPTION, $list, false );
		self::audit( '' !== $retire ? 'signing_key_rotated' : 'signing_key_trusted', array( 'fingerprint' => self::fingerprint( $public ), 'site' => $site ) );
	}

	/** Revokes a trusted key by its fingerprint: its files are refused, its rotations worth nothing. */
	public static function revoke( string $fingerprint ): bool {
		$list  = self::trusted();
		$found = false;
		foreach ( $list as $i => $entry ) {
			if ( $entry['fingerprint'] === $fingerprint ) {
				$list[ $i ]['revoked'] = true;
				$found                 = true;
			}
		}
		if ( $found ) {
			update_option( self::TRUSTED_OPTION, $list, false );
			self::audit( 'signing_key_revoked', array( 'fingerprint' => $fingerprint ) );
		}
		return $found;
	}

	/**
	 * The keys this site trusts (and those it revoked).
	 *
	 * @return list<array{key: string, fingerprint: string, site: string, added: string, by: int, revoked: bool}>
	 */
	public static function trusted(): array {
		$stored = get_option( self::TRUSTED_OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['key'] ?? null ) && is_string( $entry['fingerprint'] ?? null ) ) {
				$out[] = array(
					'key'         => $entry['key'],
					'fingerprint' => $entry['fingerprint'],
					'site'        => (string) ( $entry['site'] ?? '' ),
					'added'       => (string) ( $entry['added'] ?? '' ),
					'by'          => (int) ( $entry['by'] ?? 0 ),
					'revoked'     => true === ( $entry['revoked'] ?? false ),
				);
			}
		}
		return $out;
	}

	/**
	 * A trusted or revoked key; one retired by a rotation is no longer in the list.
	 *
	 * @return array{key: string, fingerprint: string, site: string, added: string, by: int, revoked: bool}|null
	 */
	private static function entry( string $public ): ?array {
		foreach ( self::trusted() as $entry ) {
			if ( hash_equals( $entry['key'], self::b64( $public ) ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * What is signed: the format, then the file but its signature, in canonical JSON.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function message( array $data ): string {
		$json = wp_json_encode( self::canonical( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			// Never a signature over an empty body: it would hold for every file that fails to encode.
			throw new \JsonException( 'The file cannot be encoded for its signature.' );
		}
		return self::SIGNED . ( is_string( $data['format'] ?? null ) ? $data['format'] : '' ) . '|' . $json;
	}

	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $out ) ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}

	/**
	 * This site's key (raw), made the first time; null when the site cannot sign.
	 *
	 * @return array{public: string, secret: non-empty-string, previous: array<string, string>|null}|null
	 */
	private static function own(): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$stored = get_option( self::KEY_OPTION );
		if ( is_array( $stored ) && is_string( $stored['public'] ?? null ) && is_string( $stored['secret'] ?? null ) ) {
			$secret = \XB\Deploy\Credentials::decrypt_with( (string) $stored['secret'], 'xb-signing-key-v1' );
			$public = base64_decode( $stored['public'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( '' !== $secret && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $secret ) && is_string( $public ) && hash_equals( sodium_crypto_sign_publickey_from_secretkey( $secret ), $public ) ) {
				return array(
					'public'   => $public,
					'secret'   => $secret,
					'previous' => is_array( $stored['previous'] ?? null ) ? array_map( 'strval', $stored['previous'] ) : null,
				);
			}
			return null; // Salts changed since: the key cannot be read; an administrator makes a new one.
		}
		$pair   = sodium_crypto_sign_keypair();
		$public = sodium_crypto_sign_publickey( $pair );
		self::store( sodium_crypto_sign_secretkey( $pair ), $public, null );
		self::audit( 'signing_key_created', array( 'fingerprint' => self::fingerprint( $public ) ) );
		return array(
			'public'   => $public,
			'secret'   => sodium_crypto_sign_secretkey( $pair ),
			'previous' => null,
		);
	}

	/** @param array<string, string>|null $previous */
	private static function store( string $secret, string $public, ?array $previous ): void {
		update_option(
			self::KEY_OPTION,
			array(
				'public'   => self::b64( $public ),
				'secret'   => \XB\Deploy\Credentials::encrypt_with( $secret, 'xb-signing-key-v1' ),
				'previous' => $previous,
			),
			false
		);
	}

	/** @param array<string, mixed> $details */
	private static function audit( string $action, array $details ): void {
		Plugin::instance()->versioning->store()->audit( $action, 'signing', $details );
	}

	private static function b64( string $bytes ): string {
		return base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
