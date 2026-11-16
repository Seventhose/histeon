<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Deploy;

/**
 * Minimal client for Workers Static Assets — docs/spec/static.md §Cloudflare.
 *
 *   POST /accounts/{a}/workers/scripts/{s}/assets-upload-session   manifest → jwt + buckets of missing hashes
 *   POST /accounts/{a}/workers/assets/upload?base64=true              one multipart request per bucket
 *   POST /accounts/{a}/workers/scripts/{s}/versions                   the Worker + assets jwt → version id
 *   POST /accounts/{a}/workers/scripts/{s}/deployments                100% to a version (also rollback)
 *
 * The API base can be changed only through the constant XB_CLOUDFLARE_API_BASE (wp-config.php), used
 * by the local mock in tests: nothing stored in the database can redirect the token elsewhere.
 */
final class CloudflareClient {

	private const BASE = 'https://api.cloudflare.com/client/v4';

	public function __construct(
		private readonly string $account,
		private readonly string $script,
		private readonly string $token,
	) {}

	public static function from_settings(): self {
		$c = Credentials::get();
		if ( ! Credentials::ready() ) {
			throw new DeployException( __( 'Cloudflare non è configurato.', 'xb' ), 400 );
		}
		return new self( $c['account'], $c['script'], $c['token'] );
	}

	private static function base(): string {
		if ( ! defined( 'XB_CLOUDFLARE_API_BASE' ) || ! is_string( XB_CLOUDFLARE_API_BASE ) ) {
			return self::BASE;
		}
		$base = rtrim( XB_CLOUDFLARE_API_BASE, '/' );
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		// The token travels in the Authorization header: plain http only towards a local test double, i.e.
		// localhost, a loopback IP, or a single-label name starting with a letter (a container on a private network).
		// Numeric hosts such as http://134744072/ are IPs in disguise and are not internal.
		$internal = 'localhost' === $host
			|| 1 === preg_match( '/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host )
			|| '[::1]' === $host
			|| 1 === preg_match( '/^[a-z][a-z0-9-]{0,62}$/i', $host );
		if ( ! str_starts_with( $base, 'https://' ) && ! ( $internal && str_starts_with( $base, 'http://' ) ) ) {
			throw new DeployException( __( 'XB_CLOUDFLARE_API_BASE deve usare https.', 'xb' ), 500 );
		}
		return $base;
	}

	/**
	 * @param array<string, array{hash: string, size: int}> $manifest
	 * @return array{jwt: string, buckets: list<list<string>>}
	 */
	public function upload_session( array $manifest ): array {
		$result  = $this->json( 'POST', "/accounts/{$this->account}/workers/scripts/{$this->script}/assets-upload-session", array( 'manifest' => $manifest ) );
		$buckets = array();
		foreach ( (array) ( $result['buckets'] ?? array() ) as $bucket ) {
			$buckets[] = array_values( array_filter( (array) $bucket, static fn ( mixed $h ): bool => is_string( $h ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $h ) ) );
		}
		$jwt = $result['jwt'] ?? null;
		if ( ! is_string( $jwt ) || '' === $jwt ) {
			throw new DeployException( __( 'Risposta di Cloudflare senza jwt di upload.', 'xb' ) );
		}
		return array(
			'jwt'     => $jwt,
			'buckets' => $buckets,
		);
	}

	/**
	 * @param array<string, string> $files hash → base64 content
	 * @return string|null Completion jwt when Cloudflare returns it (last bucket).
	 */
	public function upload_bucket( string $jwt, array $files ): ?string {
		$boundary = 'xb' . bin2hex( random_bytes( 12 ) );
		$body     = '';
		foreach ( $files as $hash => $base64 ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$hash}\"; filename=\"{$hash}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$base64}\r\n";
		}
		$body    .= "--{$boundary}--\r\n";
		$response = $this->send( 'POST', "/accounts/{$this->account}/workers/assets/upload?base64=true", $body, 'multipart/form-data; boundary=' . $boundary, $jwt );
		$jwt      = $response['result']['jwt'] ?? null;
		return is_string( $jwt ) && '' !== $jwt ? $jwt : null;
	}

	/**
	 * Uploads a Worker version bound to the uploaded assets.
	 *
	 * @param array<string, string> $annotations workers/message, workers/tag
	 * @param array<string, string> $vars        Plain-text bindings (e.g. the form endpoint).
	 * @param string                $headers     `_headers` file applied to the asset responses.
	 * @return string Version id.
	 */
	public function upload_version( string $assets_jwt, string $worker_js, array $annotations, array $vars = array(), string $headers = '' ): string {
		$path = "/accounts/{$this->account}/workers/scripts/{$this->script}/versions";
		try {
			$response = $this->multipart( 'POST', $path, $this->metadata( $assets_jwt, $vars, $annotations, $headers ), $worker_js );
		} catch ( DeployException $e ) {
			if ( 404 !== $e->status ) {
				throw $e;
			}
			// First deploy: the script does not exist yet. Creating it also deploys it; then add the version.
			$this->multipart( 'PUT', "/accounts/{$this->account}/workers/scripts/{$this->script}", $this->metadata( $assets_jwt, $vars, null, $headers ), $worker_js );
			$response = $this->multipart( 'POST', $path, $this->metadata( $assets_jwt, $vars, $annotations, $headers ), $worker_js );
		}
		$id = $response['result']['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw new DeployException( __( 'Risposta di Cloudflare senza id di versione.', 'xb' ) );
		}
		return $id;
	}

	/** Stores a Worker secret (Turnstile key, form signing key): sent to Cloudflare, not kept by XB in clear. */
	public function put_secret( string $name, string $value ): void {
		$this->json(
			'PUT',
			"/accounts/{$this->account}/workers/scripts/{$this->script}/secrets",
			array(
				'name' => $name,
				'text' => $value,
				'type' => 'secret_text',
			)
		);
	}

	/**
	 * @param array<string, string>      $vars        Plain-text bindings.
	 * @param array<string, string>|null $annotations
	 * @param string                     $headers     A `_headers` file for the asset responses (SecurityHeaders).
	 * @return array<string, mixed>
	 */
	private function metadata( string $assets_jwt, array $vars, ?array $annotations, string $headers = '' ): array {
		$bindings = array(
			array(
				'type' => 'assets',
				'name' => 'ASSETS',
			),
		);
		foreach ( $vars as $name => $text ) {
			$bindings[] = array(
				'type' => 'plain_text',
				'name' => $name,
				'text' => $text,
			);
		}
		$metadata = array(
			'main_module'        => 'worker.js',
			'compatibility_date' => '2026-01-01',
			'assets'             => array(
				'jwt'    => $assets_jwt,
				'config' => array(
					'html_handling'      => 'auto-trailing-slash',
					'not_found_handling' => '404-page',
					'run_worker_first'   => array( '/xb-form' ),
				) + ( '' === $headers ? array() : array( '_headers' => $headers ) ),
			),
			'bindings'           => $bindings,
			// Secrets (Turnstile, form signing key) are set once and carried over to every version.
			'keep_bindings'      => array( 'secret_text' ),
		);
		if ( null !== $annotations ) {
			$metadata['annotations'] = $annotations;
		}
		return $metadata;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function multipart( string $method, string $path, array $metadata, string $worker_js ): array {
		$boundary = 'xb' . bin2hex( random_bytes( 12 ) );
		$body     = "--{$boundary}\r\nContent-Disposition: form-data; name=\"metadata\"\r\nContent-Type: application/json\r\n\r\n" . wp_json_encode( $metadata ) . "\r\n"
			. "--{$boundary}\r\nContent-Disposition: form-data; name=\"worker.js\"; filename=\"worker.js\"\r\nContent-Type: application/javascript+module\r\n\r\n{$worker_js}\r\n"
			. "--{$boundary}--\r\n";
		return $this->send( $method, $path, $body, 'multipart/form-data; boundary=' . $boundary );
	}

	public function deploy( string $version_id, string $message ): void {
		$this->json(
			'POST',
			"/accounts/{$this->account}/workers/scripts/{$this->script}/deployments",
			array(
				'strategy'    => 'percentage',
				'versions'    => array(
					array(
						'version_id' => $version_id,
						'percentage' => 100,
					),
				),
				'annotations' => array( 'workers/message' => mb_substr( $message, 0, 100 ) ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed> The `result` member.
	 */
	private function json( string $method, string $path, array $payload ): array {
		$response = $this->send( $method, $path, (string) wp_json_encode( $payload ), 'application/json' );
		return is_array( $response['result'] ?? null ) ? $response['result'] : array();
	}

	/** @return array<string, mixed> */
	private function send( string $method, string $path, string $body, string $type, ?string $bearer = null ): array {
		$res = wp_remote_request(
			self::base() . $path,
			array(
				'method'      => $method,
				'timeout'     => 120,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . ( $bearer ?? $this->token ),
					'Content-Type'  => $type,
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new DeployException( sprintf( /* translators: %s: connection error */ __( 'Cloudflare non raggiungibile: %s', 'xb' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $code >= 300 || false === ( $data['success'] ?? false ) ) {
			$errors  = array_map( static fn ( mixed $e ): string => is_array( $e ) ? (string) ( $e['message'] ?? '' ) : '', (array) ( $data['errors'] ?? array() ) );
			$message = trim( implode( '; ', array_filter( $errors ) ) );
			throw new DeployException( sprintf( /* translators: 1: HTTP status code, 2: ": " and the error message, or nothing */ __( 'Cloudflare ha risposto %1$d%2$s', 'xb' ), $code, '' === $message ? '' : ': ' . $message ), $code );
		}
		return $data;
	}
}
