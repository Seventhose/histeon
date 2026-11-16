<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Cms;

use XB\Deploy\Credentials;
use XB\Remote\Link as RemoteLink;

/**
 * The link with a Histeon CMS project (docs/spec/cms-bridge.md §1). Separate from the staging link: pairing the CMS
 * never disconnects a staging site, and the other way round. Requests are signed exactly as staging requests
 * (RemoteLink::signature), with their nonces remembered under a different key.
 */
final class Link {

	public const OPTION      = 'xb_cms_link';
	public const CODE_PREFIX = 'histos-cms-1:';

	/**
	 * A new link (any old one stops working). Every permission starts off: the administrator turns each one on.
	 *
	 * @return string The pairing code to paste in Histeon CMS, returned once.
	 */
	public static function create( string $label, bool $read, bool $write, bool $publish ): string {
		$secret  = random_bytes( 32 );
		$publish = $publish && $write;
		// Language links waiting from an earlier link belong to that project: a new link starts without them.
		delete_option( Bridge::WAITING_LANGUAGE_LINKS );
		update_option(
			self::OPTION,
			array(
				'secret'  => Credentials::encrypt( $secret ),
				'user'    => get_current_user_id(),
				'read'    => $read,
				'write'   => $write,
				'publish' => $publish,
				'label'   => mb_substr( sanitize_text_field( $label ), 0, 80 ),
				'created' => time(),
				'last'    => 0,
			),
			false
		);
		$code = array(
			'url'    => untrailingslashit( home_url() ),
			'secret' => self::b64( $secret ),
			'scopes' => array(
				'read'    => $read,
				'write'   => $write,
				'publish' => $publish,
			),
		);
		return self::CODE_PREFIX . self::b64( (string) wp_json_encode( $code ) );
	}

	/** @return array{secret: string, user: int, read: bool, write: bool, publish: bool, label: string, created: int, last: int}|null */
	public static function get(): ?array {
		$link = get_option( self::OPTION );
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
			'read'    => true === ( $link['read'] ?? false ),
			'write'   => true === ( $link['write'] ?? false ),
			'publish' => true === ( $link['publish'] ?? false ),
			'label'   => (string) ( $link['label'] ?? '' ),
			'created' => (int) ( $link['created'] ?? 0 ),
			'last'    => (int) ( $link['last'] ?? 0 ),
		);
	}

	public static function touch(): void {
		$link = get_option( self::OPTION );
		if ( is_array( $link ) ) {
			$link['last'] = time();
			update_option( self::OPTION, $link, false );
		}
	}

	public static function revoke(): void {
		delete_option( self::OPTION );
		delete_option( Bridge::WAITING_LANGUAGE_LINKS );
	}

	/**
	 * Checks a signed request (same rules as staging, docs/spec/remote.md). Returns '' when it is valid, otherwise
	 * a short reason for the log (never shown to the caller).
	 */
	public static function verify( string $method, string $route, string $time, string $nonce, string $signature, string $body ): string {
		$link = self::get();
		if ( null === $link ) {
			return 'no link';
		}
		if ( 1 !== preg_match( '/^\d{9,11}$/', $time ) || abs( time() - (int) $time ) > RemoteLink::SKEW ) {
			return 'time';
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $nonce ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $signature ) ) {
			return 'format';
		}
		if ( strlen( $body ) > RemoteLink::MAX_BODY ) {
			return 'size';
		}
		if ( ! hash_equals( RemoteLink::signature( $link['secret'], $method, $route, (int) $time, $nonce, $body ), $signature ) ) {
			return 'signature';
		}
		$seen = 'xb_cms_nonce_' . $nonce;
		if ( false !== get_transient( $seen ) ) {
			return 'replay';
		}
		set_transient( $seen, 1, 2 * RemoteLink::SKEW );
		return '';
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
