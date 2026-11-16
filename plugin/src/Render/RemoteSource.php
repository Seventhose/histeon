<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Render;

use XB\Rest\Controller;

/**
 * A data source read from an address (doc 26 voce 214, docs/spec/data.md «Sorgente da un indirizzo»): JSON from
 * another service (timetables, events, a price list kept elsewhere), read like a JSON source written in the builder.
 *
 * - Only `https`, and only from the hosts an administrator allowed (option `xb_remote_hosts`): a builder cannot
 *   make the site call an address of its choosing. WordPress's safe request refuses private and local addresses.
 * - Same limits as a written source (Sources): weight, depth, keys. A timeout of 5 seconds.
 * - A copy is kept for the time the source says (5 minutes to a day). When it runs out, visitors get the last good
 *   copy while a background task reads it again; only the very first read waits for the service. A service that
 *   stops answering leaves the last good copy in place.
 * - Static Bridge and Histeon Publish see the data as it was when they built the page.
 */
final class RemoteSource {

	public const HOSTS   = 'xb_remote_hosts';
	public const HOOK    = 'xb_refresh_remote_source';
	public const TIMEOUT = 5;
	/** Minutes a copy lasts: the source's choice, within these bounds. */
	public const MIN_TTL = 5;
	public const MAX_TTL = 1440;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'refresh' ), 10, 3 );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Controller::NAMESPACE,
			'/sources/hosts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn (): \WP_REST_Response => new \WP_REST_Response(
						array(
							'hosts'     => self::hosts(),
							'canChange' => current_user_can( 'manage_options' ),
						)
					),
					'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response {
						$hosts = array();
						foreach ( (array) $request->get_param( 'hosts' ) as $host ) {
							$host = strtolower( trim( (string) $host ) );
							if ( 1 === preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
								$hosts[] = $host;
							}
						}
						$hosts = array_values( array_unique( array_slice( $hosts, 0, 50 ) ) );
						update_option( self::HOSTS, $hosts, false );
						\XB\Plugin::instance()->versioning->store()->audit( 'remote-hosts', 'site', array( 'hosts' => $hosts ) );
						return new \WP_REST_Response(
							array(
								'hosts'     => $hosts,
								'canChange' => true,
							)
						);
					},
					'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
				),
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/sources/test',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
					$data = self::fetch( (string) $request->get_param( 'url' ), (string) $request->get_param( 'path' ) );
					return is_wp_error( $data ) ? $data : new \WP_REST_Response( array( 'data' => $data ) );
				},
				'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ),
			)
		);
	}

	/** @return list<string> */
	public static function hosts(): array {
		$hosts = get_option( self::HOSTS, array() );
		return is_array( $hosts ) ? array_values( array_filter( array_map( 'strval', $hosts ) ) ) : array();
	}

	/** Why an address cannot be a source, or null when it can. */
	public static function problem( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || '' === ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return __( 'Serve un indirizzo https://, senza nome utente né password.', 'xb' );
		}
		if ( ! in_array( strtolower( (string) $parts['host'] ), self::hosts(), true ) ) {
			/* translators: %s: host name */
			return sprintf( __( 'L’host %s non è fra quelli consentiti: lo aggiunge un amministratore, accanto alla sorgente.', 'xb' ), (string) $parts['host'] );
		}
		return null;
	}

	/**
	 * The data of a source by address, from its copy when there is one.
	 *
	 * @param array{url: string, path?: string, ttl?: int} $source
	 */
	public static function read( array $source ): mixed {
		$url  = (string) $source['url'];
		$path = (string) ( $source['path'] ?? '' );
		$ttl  = self::ttl( $source['ttl'] ?? null );
		$key  = self::key( $url, $path );
		$copy = get_transient( $key );
		if ( is_array( $copy ) && array_key_exists( 'data', $copy ) ) {
			return $copy['data'];
		}
		$last = get_option( $key . '_last', null );
		if ( is_array( $last ) && array_key_exists( 'data', $last ) ) {
			if ( ! wp_next_scheduled( self::HOOK, array( $url, $path, $ttl ) ) ) {
				wp_schedule_single_event( time(), self::HOOK, array( $url, $path, $ttl ) );
			}
			return $last['data'];
		}
		return self::refresh( $url, $path, $ttl );
	}

	/** Reads the address again and keeps the copy (also the background task); on failure the last good copy stays. */
	public static function refresh( string $url, string $path, int $ttl ): mixed {
		$key  = self::key( $url, $path );
		$data = self::fetch( $url, $path );
		if ( is_wp_error( $data ) ) {
			// Tried again in a while, not at every visit.
			set_transient( $key, array( 'data' => self::last( $key ) ), min( 300, self::ttl( $ttl ) * MINUTE_IN_SECONDS ) );
			return self::last( $key );
		}
		set_transient( $key, array( 'data' => $data ), self::ttl( $ttl ) * MINUTE_IN_SECONDS );
		update_option( $key . '_last', array( 'data' => $data ), false );
		return $data;
	}

	/** The JSON at an address, checked like a written source; the part named by `path` (`items`, `data.eventi`). */
	public static function fetch( string $url, string $path = '' ): mixed {
		$problem = self::problem( $url );
		if ( null !== $problem ) {
			return new \WP_Error( 'xb_remote_source', $problem, array( 'status' => 400 ) );
		}
		if ( '' !== $path && 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}(?:\.[A-Za-z0-9_-]{1,64}){0,9}$/', $path ) ) {
			return new \WP_Error( 'xb_remote_source', __( 'Il percorso dentro il JSON si scrive con nomi e punti, come items o data.eventi.', 'xb' ), array( 'status' => 400 ) );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 2,
				'limit_response_size' => Sources::MAX_BYTES + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error from the remote service */
			return new \WP_Error( 'xb_remote_source', sprintf( __( 'Il servizio non risponde: %s', 'xb' ), $response->get_error_message() ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status */
			return new \WP_Error( 'xb_remote_source', sprintf( __( 'Il servizio ha risposto %d.', 'xb' ), $code ), array( 'status' => 502 ) );
		}
		if ( strlen( $body ) > Sources::MAX_BYTES ) {
			return new \WP_Error( 'xb_remote_source', __( 'Il JSON del servizio è troppo grande.', 'xb' ), array( 'status' => 502 ) );
		}
		$data = json_decode( $body, true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'xb_remote_source', __( 'Il servizio non ha risposto con un JSON valido.', 'xb' ), array( 'status' => 502 ) );
		}
		foreach ( '' === $path ? array() : explode( '.', $path ) as $part ) {
			$data = is_array( $data ) && array_key_exists( $part, $data ) ? $data[ $part ] : null;
		}
		try {
			Sources::validate( array( 'x' => array( 'type' => 'json', 'data' => $data ) ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_remote_source', $e->getMessage(), array( 'status' => 502 ) );
		}
		return $data;
	}

	public static function ttl( mixed $minutes ): int {
		return max( self::MIN_TTL, min( self::MAX_TTL, is_numeric( $minutes ) ? (int) $minutes : 60 ) );
	}

	private static function key( string $url, string $path ): string {
		return 'xb_src_' . md5( $url . '|' . $path );
	}

	private static function last( string $key ): mixed {
		$last = get_option( $key . '_last', null );
		return is_array( $last ) ? ( $last['data'] ?? null ) : null;
	}
}
