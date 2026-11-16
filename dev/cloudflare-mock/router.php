<?php
/**
 * Local mock of the Cloudflare Workers Static Assets API, for XB integration tests only.
 * Runs with `php -S` in the cfmock container; state lives in /tmp/cfmock. Never use it outside dev.
 *
 * It enforces what the real API does and what XB must get right: bearer tokens, upload-session JWTs,
 * hashes that match the manifest, 404 for a script that does not exist yet, keep_bindings, deployments.
 */

declare(strict_types=1);

$state_dir = '/tmp/cfmock';
@mkdir( $state_dir . '/blobs', 0700, true );
$state_file = $state_dir . '/state.json';
$state      = is_file( $state_file ) ? json_decode( (string) file_get_contents( $state_file ), true ) : array();
$state     += array( 'scripts' => array(), 'sessions' => array(), 'versions' => array(), 'active' => array(), 'secrets' => array(), 'requests' => 0 );

function reply( int $code, array $body ): void {
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $body );
}

function ok( array $result, int $code = 200 ): void {
	reply( $code, array( 'success' => true, 'errors' => array(), 'result' => $result ) );
}

function fail( int $code, string $message ): void {
	reply( $code, array( 'success' => false, 'errors' => array( array( 'code' => $code, 'message' => $message ) ), 'result' => null ) );
}

function save( array $state, string $file ): void {
	file_put_contents( $file, json_encode( $state ) );
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$auth   = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
$token  = (string) getenv( 'XB_MOCK_TOKEN' );
++$state['requests'];

// Introspection and the deployed site, for assertions.
if ( '/__state' === $path ) {
	reply( 200, array( 'active' => $state['active'], 'versions' => array_map( static fn ( $v ) => array( 'id' => $v['id'], 'script' => $v['script'], 'annotations' => $v['annotations'], 'bindings' => $v['bindings'], 'headers' => $v['headers'] ?? '', 'files' => count( $v['manifest'] ) ), $state['versions'] ), 'scripts' => array_keys( $state['scripts'] ), 'secrets' => array_map( 'array_keys', $state['secrets'] ), 'blobs' => count( glob( $state_dir . '/blobs/*' ) ) ) );
	return;
}
if ( '/__reset' === $path ) {
	save( array(), $state_file );
	array_map( 'unlink', glob( $state_dir . '/blobs/*' ) );
	reply( 200, array( 'reset' => true ) );
	return;
}
if ( preg_match( '#^/__site/([a-z0-9-]+)(/.*)$#', $path, $m ) ) {
	$version  = $state['versions'][ $state['active'][ $m[1] ] ?? '' ] ?? null;
	$asset    = $m[2];
	$manifest = $version['manifest'] ?? array();
	$hash     = $manifest[ $asset ]['hash'] ?? $manifest[ rtrim( $asset, '/' ) . '/index.html' ]['hash'] ?? null;
	if ( null === $hash ) {
		$hash = $manifest['/404.html']['hash'] ?? null;
		http_response_code( 404 );
	}
	// The `/*` rules of the `_headers` config, as Workers static assets apply them.
	foreach ( preg_split( '/\R/', (string) ( $version['headers'] ?? '' ) ) ?: array() as $line ) {
		if ( preg_match( '/^\s+([A-Za-z-]+):\s*(.+)$/', $line, $h ) ) {
			header( $h[1] . ': ' . $h[2] );
		}
	}
	echo null === $hash ? 'not found' : base64_decode( (string) file_get_contents( $state_dir . '/blobs/' . $hash ) );
	return;
}

if ( ! preg_match( '#^/client/v4/accounts/([0-9a-f]{32})/workers/(.*)$#', $path, $route ) ) {
	fail( 404, 'Route not found' );
	return;
}
$rest = $route[2];

// Asset uploads authenticate with the upload-session JWT; everything else with the API token.
if ( 'assets/upload' === $rest && 'POST' === $method ) {
	$jwt     = substr( $auth, 7 );
	$session = $state['sessions'][ $jwt ] ?? null;
	if ( null === $session ) {
		fail( 401, 'Invalid upload JWT' );
		save( $state, $state_file );
		return;
	}
	$paths_by_hash = array();
	foreach ( $session['manifest'] as $asset_path => $entry ) {
		$paths_by_hash[ $entry['hash'] ] = $asset_path;
	}
	foreach ( $_FILES as $hash => $file ) {
		$base64 = (string) file_get_contents( $file['tmp_name'] );
		$asset  = $paths_by_hash[ $hash ] ?? null;
		if ( null === $asset || substr( hash( 'sha256', $base64 . pathinfo( $asset, PATHINFO_EXTENSION ) ), 0, 32 ) !== $hash ) {
			fail( 400, 'Hash mismatch for ' . $hash );
			save( $state, $state_file );
			return;
		}
		file_put_contents( $state_dir . '/blobs/' . $hash, $base64 );
		$state['sessions'][ $jwt ]['pending'] = array_values( array_diff( $state['sessions'][ $jwt ]['pending'], array( $hash ) ) );
	}
	if ( array() === $state['sessions'][ $jwt ]['pending'] ) {
		$complete                       = 'complete-' . bin2hex( random_bytes( 8 ) );
		$state['sessions'][ $complete ] = $state['sessions'][ $jwt ] + array( 'complete' => true );
		$state['sessions'][ $complete ]['complete'] = true;
		save( $state, $state_file );
		ok( array( 'jwt' => $complete ), 201 );
		return;
	}
	save( $state, $state_file );
	ok( array() );
	return;
}

if ( '' === $token || 'Bearer ' . $token !== $auth ) {
	fail( 401, 'Authentication error' );
	save( $state, $state_file );
	return;
}

if ( preg_match( '#^scripts/([a-z0-9-]+)/assets-upload-session$#', $rest, $m ) && 'POST' === $method ) {
	$body     = json_decode( (string) file_get_contents( 'php://input' ), true );
	$manifest = $body['manifest'] ?? null;
	if ( ! is_array( $manifest ) || array() === $manifest ) {
		fail( 400, 'manifest required' );
		return;
	}
	foreach ( $manifest as $asset => $entry ) {
		if ( ! str_starts_with( (string) $asset, '/' ) || ! preg_match( '/^[0-9a-f]{32}$/', (string) ( $entry['hash'] ?? '' ) ) || ! is_int( $entry['size'] ?? null ) ) {
			fail( 400, 'invalid manifest entry ' . $asset );
			return;
		}
	}
	$missing = array();
	foreach ( $manifest as $entry ) {
		if ( ! is_file( $state_dir . '/blobs/' . $entry['hash'] ) ) {
			$missing[ $entry['hash'] ] = true;
		}
	}
	$missing = array_keys( $missing );
	$jwt     = 'upload-' . bin2hex( random_bytes( 8 ) );
	$state['sessions'][ $jwt ] = array( 'script' => $m[1], 'manifest' => $manifest, 'pending' => $missing, 'complete' => array() === $missing );
	save( $state, $state_file );
	ok( array( 'jwt' => $jwt, 'buckets' => array_chunk( $missing, 3 ) ) );
	return;
}

// php -S parses multipart only for POST: parse PUT bodies by hand (Cloudflare accepts both).
$parts = array();
if ( 'PUT' === $method && preg_match( '/boundary=(\S+)/', (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ), $b ) ) {
	foreach ( explode( '--' . $b[1], (string) file_get_contents( 'php://input' ) ) as $chunk ) {
		if ( preg_match( '/name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*)\r\n$/s', $chunk, $p ) ) {
			$parts[ $p[1] ] = $p[2];
		}
	}
}
$metadata = null;
if ( isset( $parts['metadata'] ) ) {
	$metadata = json_decode( $parts['metadata'], true );
} elseif ( isset( $_POST['metadata'] ) ) {
	$metadata = json_decode( (string) $_POST['metadata'], true );
} elseif ( isset( $_FILES['metadata'] ) ) {
	$metadata = json_decode( (string) file_get_contents( $_FILES['metadata']['tmp_name'] ), true );
}

if ( preg_match( '#^scripts/([a-z0-9-]+)$#', $rest, $m ) && 'PUT' === $method ) {
	if ( ! is_array( $metadata ) || ! isset( $parts['worker.js'] ) ) {
		fail( 400, 'metadata and worker.js required' );
		return;
	}
	$state['scripts'][ $m[1] ] = true;
	save( $state, $state_file );
	ok( array( 'id' => $m[1] ) );
	return;
}

if ( preg_match( '#^scripts/([a-z0-9-]+)/versions$#', $rest, $m ) && 'POST' === $method ) {
	if ( ! isset( $state['scripts'][ $m[1] ] ) ) {
		fail( 404, 'This Worker does not exist on your account.' );
		save( $state, $state_file );
		return;
	}
	$jwt     = (string) ( $metadata['assets']['jwt'] ?? '' );
	$session = $state['sessions'][ $jwt ] ?? null;
	if ( null === $session || ! $session['complete'] || ! empty( $session['pending'] ) ) {
		fail( 400, 'assets jwt is not a completed upload' );
		return;
	}
	if ( ( $metadata['keep_bindings'] ?? null ) !== array( 'secret_text' ) || ( $metadata['main_module'] ?? '' ) !== 'worker.js' ) {
		fail( 400, 'unexpected metadata' );
		return;
	}
	$id                       = bin2hex( random_bytes( 16 ) );
	$state['versions'][ $id ] = array( 'id' => $id, 'script' => $m[1], 'manifest' => $session['manifest'], 'annotations' => $metadata['annotations'] ?? array(), 'bindings' => array_column( (array) ( $metadata['bindings'] ?? array() ), 'name' ), 'headers' => (string) ( $metadata['assets']['config']['_headers'] ?? '' ) );
	save( $state, $state_file );
	ok( array( 'id' => $id ) );
	return;
}

if ( preg_match( '#^scripts/([a-z0-9-]+)/deployments$#', $rest, $m ) && 'POST' === $method ) {
	$body    = json_decode( (string) file_get_contents( 'php://input' ), true );
	$version = $body['versions'][0]['version_id'] ?? null;
	if ( 'percentage' !== ( $body['strategy'] ?? null ) || 100 !== ( $body['versions'][0]['percentage'] ?? null ) || ! isset( $state['versions'][ $version ] ) ) {
		fail( 400, 'invalid deployment' );
		return;
	}
	$state['active'][ $m[1] ] = $version;
	save( $state, $state_file );
	ok( array( 'id' => bin2hex( random_bytes( 8 ) ) ) );
	return;
}

if ( preg_match( '#^scripts/([a-z0-9-]+)/secrets$#', $rest, $m ) && 'PUT' === $method ) {
	$body = json_decode( (string) file_get_contents( 'php://input' ), true );
	if ( 'secret_text' !== ( $body['type'] ?? null ) || ! is_string( $body['name'] ?? null ) || ! is_string( $body['text'] ?? null ) ) {
		fail( 400, 'invalid secret' );
		return;
	}
	$state['secrets'][ $m[1] ][ $body['name'] ] = hash( 'sha256', $body['text'] );
	save( $state, $state_file );
	ok( array( 'name' => $body['name'], 'type' => 'secret_text' ) );
	return;
}

fail( 404, 'Route not found: ' . $method . ' ' . $rest );
