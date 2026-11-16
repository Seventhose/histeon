<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

use XB\Deploy\Credentials;

/**
 * The link between a staging site and its production site (docs/spec/remote.md).
 *
 * Production creates a pairing code: its address and a random secret, shown once. The staging site stores it.
 * Both keep the secret encrypted (the key derives from wp-config.php). Every request from staging is signed with
 * HMAC-SHA256 over the method, the route, the time, a nonce and the body's hash; production refuses an old,
 * replayed or altered request.
 */
final class Link {

	/** Production side: the link that accepts requests. */
	public const INBOUND = 'xb_remote_inbound';
	/** Staging side: where to send. */
	public const OUTBOUND = 'xb_remote_outbound';
	public const CODE_PREFIX = 'histos-link-1:';
	/** How far a request's clock may be from ours. */
	public const SKEW = 300;
	/** The largest body production accepts (a site's media can be large; the server's own limits come first). */
	public const MAX_BODY = 512 * 1024 * 1024;

	// ------------------------------------------------------------------ production side

	/**
	 * A new link (any old one stops working). The code holds the secret: it is returned once, never stored in clear.
	 *
	 * @return string The pairing code to paste on the staging site.
	 */
	public static function create_inbound( bool $publish, string $label ): string {
		$secret = random_bytes( 32 );
		update_option(
			self::INBOUND,
			array(
				'secret'  => Credentials::encrypt( $secret ),
				'user'    => get_current_user_id(),
				'publish' => $publish,
				'label'   => mb_substr( sanitize_text_field( $label ), 0, 80 ),
				'created' => time(),
				'last'    => 0,
				// The staging site's address, bound when it confirms the pairing (pair_inbound).
				'origin'  => '',
			),
			false
		);
		return self::CODE_PREFIX . self::b64( (string) wp_json_encode( array( 'url' => untrailingslashit( home_url() ), 'secret' => self::b64( $secret ) ) ) );
	}

	/** @return array{secret: string, user: int, publish: bool, label: string, created: int, last: int, origin: string}|null */
	public static function inbound(): ?array {
		$link = get_option( self::INBOUND );
		if ( ! is_array( $link ) || ! is_string( $link['secret'] ?? null ) ) {
			return null;
		}
		$secret = Credentials::decrypt( $link['secret'] );
		if ( 32 !== strlen( $secret ) ) {
			return null;
		}
		return array(
			'secret'  => $secret,
			'user'    => (int) ( $link['user'] ?? 0 ),
			'publish' => (bool) ( $link['publish'] ?? false ),
			'label'   => (string) ( $link['label'] ?? '' ),
			'created' => (int) ( $link['created'] ?? 0 ),
			'last'    => (int) ( $link['last'] ?? 0 ),
			'origin'  => is_string( $link['origin'] ?? null ) ? $link['origin'] : '',
		);
	}

	public static function revoke_inbound(): void {
		delete_option( self::INBOUND );
	}

	/**
	 * Binds the staging site's address to the link (doc 23, voce 173): the first signed request that names it decides,
	 * and from then on every package must come from that address. The staging site sends it right after saving the
	 * code (Sender::pair); a link made before this check, or whose confirmation never arrived, is bound by its first
	 * preview or send. Only whoever holds the secret can sign, so the first contact is the staging site the code was
	 * given to; a new code starts unbound.
	 *
	 * @return string The bound address.
	 * @throws \InvalidArgumentException When the address is not one, or the link is bound to another.
	 */
	public static function pair_inbound( string $site ): string {
		$origin = self::origin( $site );
		if ( '' === $origin ) {
			throw new \InvalidArgumentException( __( 'Indirizzo dello staging non valido.', 'xb' ) );
		}
		$link = get_option( self::INBOUND );
		if ( ! is_array( $link ) ) {
			throw new \InvalidArgumentException( __( 'Richiesta non autorizzata.', 'xb' ) );
		}
		$bound = is_string( $link['origin'] ?? null ) ? $link['origin'] : '';
		if ( '' === $bound ) {
			$link['origin'] = $origin;
			update_option( self::INBOUND, $link, false );
			\XB\Plugin::instance()->versioning->store()->audit( 'remote_pair', 'remote', array( 'origin' => $origin ) );
			return $origin;
		}
		if ( $bound !== $origin ) {
			/* translators: %s: address of the staging site the code is paired with */
			throw new \InvalidArgumentException( sprintf( __( 'Questo collegamento è già abbinato a %s: per un altro staging crea un nuovo codice.', 'xb' ), $bound ) );
		}
		return $bound;
	}

	/** A site address as the link keeps it: http(s), a host, no credentials, query or fragment, no trailing slash; '' if not one. */
	public static function origin( string $url ): string {
		$url   = untrailingslashit( trim( $url ) );
		$parts = wp_parse_url( $url );
		$ok    = strlen( $url ) <= 300 && is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && '' !== ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] );
		return $ok ? $url : '';
	}

	public static function touch_inbound(): void {
		$link = get_option( self::INBOUND );
		if ( is_array( $link ) ) {
			$link['last'] = time();
			update_option( self::INBOUND, $link, false );
		}
	}

	// ------------------------------------------------------------------ staging side

	/**
	 * Stores a pairing code from production.
	 *
	 * @throws \InvalidArgumentException When the code is not one.
	 */
	public static function save_outbound( string $code ): string {
		$code = trim( $code );
		$data = str_starts_with( $code, self::CODE_PREFIX ) ? json_decode( self::unb64( substr( $code, strlen( self::CODE_PREFIX ) ) ), true ) : null;
		$url  = is_array( $data ) && is_string( $data['url'] ?? null ) ? $data['url'] : '';
		$key  = is_array( $data ) && is_string( $data['secret'] ?? null ) ? self::unb64( $data['secret'] ) : '';
		// The shape only: the address is checked again (public, no private network) on every request.
		$parts = wp_parse_url( $url );
		$ok    = is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && '' !== ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] );
		if ( 32 !== strlen( $key ) || ! $ok ) {
			throw new \InvalidArgumentException( __( 'Codice di abbinamento non valido: copialo di nuovo dal sito di produzione.', 'xb' ) );
		}
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && ! self::local() ) {
			throw new \InvalidArgumentException( __( 'Il sito di produzione deve usare HTTPS.', 'xb' ) );
		}
		if ( untrailingslashit( $url ) === untrailingslashit( home_url() ) ) {
			throw new \InvalidArgumentException( __( 'Questo è il codice di questo stesso sito: incollalo sul sito di staging.', 'xb' ) );
		}
		update_option(
			self::OUTBOUND,
			array(
				'url'    => untrailingslashit( $url ),
				'secret' => Credentials::encrypt( $key ),
				'saved'  => time(),
			),
			false
		);
		return untrailingslashit( $url );
	}

	/** @return array{url: string, secret: string, saved: int}|null */
	public static function outbound(): ?array {
		$link = get_option( self::OUTBOUND );
		if ( ! is_array( $link ) || ! is_string( $link['url'] ?? null ) || ! is_string( $link['secret'] ?? null ) ) {
			return null;
		}
		$secret = Credentials::decrypt( $link['secret'] );
		return 32 === strlen( $secret ) ? array( 'url' => $link['url'], 'secret' => $secret, 'saved' => (int) ( $link['saved'] ?? 0 ) ) : null;
	}

	public static function forget_outbound(): void {
		delete_option( self::OUTBOUND );
	}

	// ------------------------------------------------------------------ signatures

	/** What is signed: method, route, time, nonce and the body's hash, one per line. */
	public static function signature( string $secret, string $method, string $route, int $time, string $nonce, string $body ): string {
		return hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $route . "\n" . $time . "\n" . $nonce . "\n" . hash( 'sha256', $body ), $secret );
	}

	/**
	 * The headers of a signed request.
	 *
	 * @return array<string, string>
	 */
	public static function headers( string $secret, string $method, string $route, string $body ): array {
		$time  = time();
		$nonce = bin2hex( random_bytes( 16 ) );
		return array(
			'X-Histos-Time'      => (string) $time,
			'X-Histos-Nonce'     => $nonce,
			'X-Histos-Signature' => self::signature( $secret, $method, $route, $time, $nonce, $body ),
		);
	}

	/**
	 * Checks a request against the inbound link. A nonce is accepted once.
	 *
	 * @return string '' when valid, otherwise why not (for the log, never the secret).
	 */
	public static function verify( string $method, string $route, string $time, string $nonce, string $signature, string $body ): string {
		$link = self::inbound();
		if ( null === $link ) {
			return 'no link';
		}
		if ( 1 !== preg_match( '/^\d{9,11}$/', $time ) || abs( time() - (int) $time ) > self::SKEW ) {
			return 'time';
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $nonce ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return 'format';
		}
		if ( strlen( $body ) > self::MAX_BODY ) {
			return 'size';
		}
		if ( ! hash_equals( self::signature( $link['secret'], $method, $route, (int) $time, $nonce, $body ), $signature ) ) {
			return 'signature';
		}
		// Replay: the nonce is remembered for longer than a request may be late.
		$seen = 'xb_remote_nonce_' . $nonce;
		if ( false !== get_transient( $seen ) ) {
			return 'replay';
		}
		set_transient( $seen, 1, 2 * self::SKEW );
		return '';
	}

	/**
	 * A development site may link over plain HTTP (local stacks); a live one needs HTTPS. Both the environment type
	 * and the site's own address must say so: a live site left on «development» still has a public name.
	 */
	public static function local(): bool {
		$host  = strtolower( trim( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '[]' ) );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || 1 === preg_match( '/\.(local|test|localhost)$/D', $host );
		/** Tests only, or a development stack with another name: the same answer for every environment. */
		return (bool) apply_filters( 'xb_remote_plain_http', $local && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function unb64( string $text ): string {
		$raw = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $raw ? '' : $raw;
	}
}
