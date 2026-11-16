<?php

declare(strict_types=1);

$plugin_dir = dirname( __DIR__, 2 );
define( 'XB_FILE', $plugin_dir . '/xb.php' );
spl_autoload_register(
	static function ( string $class_name ) use ( $plugin_dir ): void {
		if ( ! str_starts_with( $class_name, 'XB\\' ) ) {
			return;
		}
		$path = $plugin_dir . '/src/' . str_replace( '\\', '/', substr( $class_name, 3 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

require $plugin_dir . '/src/Remote/PageProfile.php';

use XB\Remote\PageProfile;

$fixture_path = isset( $argv[3] ) && is_file( $argv[3] ) ? $argv[3] : __DIR__ . '/histos-static-page-v1.json';
$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true, 128, JSON_THROW_ON_ERROR );
$mode         = $argv[1] ?? '';

if ( 'canonical' === $mode ) {
	$input_path = $argv[2] ?? '';
	$input      = is_file( $input_path ) ? file_get_contents( $input_path ) : false;
	if ( ! is_string( $input ) ) {
		fwrite( STDERR, "Could not read the requested JSON value.\n" );
		exit( 2 );
	}
	fwrite( STDOUT, PageProfile::canonical( json_decode( $input, false, 128, JSON_THROW_ON_ERROR ) ) . "\n" );
	exit( 0 );
}

if ( 'encode' === $mode ) {
	$files = array();
	foreach ( $fixture['profile']['manifest']['files'] as $file ) {
		$path = $file['path'];
		if ( ! is_string( $fixture['files'][ $path ] ?? null ) ) {
			fwrite( STDERR, "Fixture is missing $path\n" );
		exit( 2 );
		}
		$bytes = base64_decode( $fixture['files'][ $path ], true );
		if ( false === $bytes ) {
			fwrite( STDERR, "Fixture contains invalid base64 for $path\n" );
			exit( 2 );
		}
		$files[ $path ] = $bytes;
	}
	$archive = PageProfile::archive( $fixture['profile']['manifest'], $fixture['profile']['entities'], $fixture['profile']['references'], $files );
	if ( ! isset( $argv[2] ) || false === file_put_contents( $argv[2], $archive ) ) {
		fwrite( STDERR, "Could not write the requested archive.\n" );
		exit( 2 );
	}
	exit( 0 );
}

if ( 'decode' === $mode ) {
	$archive = isset( $argv[2] ) ? file_get_contents( $argv[2] ) : false;
	if ( ! is_string( $archive ) ) {
		fwrite( STDERR, "Could not read the requested archive.\n" );
		exit( 2 );
	}
	$decoded = PageProfile::decode( $archive );
	$files   = array(
		'entities.ndjson'   => array() === $decoded['entities'] ? '' : implode( "\n", array_map( PageProfile::canonical(...), $decoded['entities'] ) ) . "\n",
		'references.ndjson' => array() === $decoded['references'] ? '' : implode( "\n", array_map( PageProfile::canonical(...), $decoded['references'] ) ) . "\n",
	);
	foreach ( $decoded['files'] as $path => $bytes ) {
		$files[ $path ] = $bytes;
	}
	$encoded_files = array();
	foreach ( $decoded['manifest']['files'] as $file ) {
		$path = $file['path'];
		if ( ! is_string( $files[ $path ] ?? null ) ) {
			fwrite( STDERR, "Decoded profile is missing $path\n" );
			exit( 3 );
		}
		$encoded_files[ $path ] = base64_encode( $files[ $path ] );
	}
	$profile_entities = $decoded['entities'];
	foreach ( $profile_entities as &$entity ) {
		if ( in_array( $entity['kind'] ?? null, array( 'component', 'template-part', 'template' ), true ) && is_array( $entity['payload']['schema']['props'] ?? null ) && array() === $entity['payload']['schema']['props'] ) {
			$entity['payload']['schema']['props'] = new \stdClass();
		}
		if ( 'document' === ( $entity['kind'] ?? null ) && 'post' === ( $entity['payload']['documentType'] ?? null ) && array() === ( $entity['payload']['terms'] ?? null ) ) {
			$entity['payload']['terms'] = new \stdClass();
		}
		if ( 'content-record' === ( $entity['kind'] ?? null ) ) {
			foreach ( array( 'values', 'terms' ) as $key ) {
				if ( array() === ( $entity['payload'][ $key ] ?? null ) ) {
					$entity['payload'][ $key ] = new \stdClass();
				}
			}
		}
		if ( 'styles' !== ( $entity['kind'] ?? null ) || ! is_array( $entity['payload'] ?? null ) ) {
			continue;
		}
		foreach ( array( 'design', 'tokenOverrides', 'queries' ) as $key ) {
			if ( array_key_exists( $key, $entity['payload'] ) && array() === $entity['payload'][ $key ] ) {
				$entity['payload'][ $key ] = new \stdClass();
			}
		}
	}
	unset( $entity );
	$output = array(
		'profile' => array( 'manifest' => $decoded['manifest'], 'entities' => $profile_entities, 'references' => $decoded['references'] ),
		'files'   => $encoded_files,
	);
	fwrite( STDOUT, json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n" );
	exit( 0 );
}

fwrite( STDERR, "Usage: php page-profile-interop.php encode OUTPUT.zip | decode INPUT.zip\n" );
exit( 2 );
