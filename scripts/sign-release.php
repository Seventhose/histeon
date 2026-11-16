#!/usr/bin/env php
<?php
/**
 * Release signatures in the minisign format (docs/spec/updates.md), with PHP's libsodium: no other tool needed,
 * and `minisign -Vm <zip> -P <public key>` verifies them too.
 *
 *   php scripts/sign-release.php keygen <secret-key-file>   writes the secret key (0600) and prints the public key
 *   php scripts/sign-release.php sign <secret-key-file> <file>…   writes <file>.minisig next to each file
 *
 * The secret key file never goes in the repository or in a package: keep it offline, and only its public key in
 * the sites (Impostazioni → Aggiornamenti) or in XB_UPDATE_PUBLIC_KEY.
 */

declare(strict_types=1);

function fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

function public_line( string $id, string $pk ): string {
	return base64_encode( 'Ed' . $id . $pk );
}

[ , $command, $keyfile ] = array_pad( $argv, 3, '' );
if ( 'keygen' === $command && '' !== $keyfile ) {
	if ( file_exists( $keyfile ) ) {
		fail( "Esiste già: $keyfile (non lo sovrascrivo)." );
	}
	$pair = sodium_crypto_sign_keypair();
	$id   = random_bytes( 8 );
	$old  = umask( 0077 );
	file_put_contents( $keyfile, json_encode( array( 'id' => base64_encode( $id ), 'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ) ) ) . "\n" );
	umask( $old );
	chmod( $keyfile, 0600 );
	echo 'untrusted comment: Histeon release public key ' . strtoupper( bin2hex( strrev( $id ) ) ) . "\n";
	echo public_line( $id, sodium_crypto_sign_publickey( $pair ) ) . "\n";
	exit( 0 );
}
if ( 'sign' === $command && '' !== $keyfile && $argc > 3 ) {
	$key = json_decode( (string) file_get_contents( $keyfile ), true );
	$id  = base64_decode( (string) ( $key['id'] ?? '' ), true );
	$sk  = base64_decode( (string) ( $key['secret'] ?? '' ), true );
	if ( false === $id || 8 !== strlen( $id ) || false === $sk || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $sk ) ) {
		fail( "Chiave segreta non valida: $keyfile" );
	}
	foreach ( array_slice( $argv, 3 ) as $file ) {
		if ( ! is_file( $file ) ) {
			fail( "File mancante: $file" );
		}
		$state = sodium_crypto_generichash_init( '', 64 );
		$h     = fopen( $file, 'rb' );
		while ( ! feof( $h ) ) {
			sodium_crypto_generichash_update( $state, (string) fread( $h, 1 << 16 ) );
		}
		fclose( $h );
		$signature = sodium_crypto_sign_detached( sodium_crypto_generichash_final( $state, 64 ), $sk );
		$comment   = 'timestamp:' . time() . "\tfile:" . basename( $file ) . "\thashed";
		$global    = sodium_crypto_sign_detached( $signature . $comment, $sk );
		file_put_contents(
			$file . '.minisig',
			"untrusted comment: signature from Histeon release key\n" . base64_encode( 'ED' . $id . $signature ) . "\ntrusted comment: $comment\n" . base64_encode( $global ) . "\n"
		);
		echo "firmato: $file.minisig\n";
	}
	exit( 0 );
}
fail( "Uso: php scripts/sign-release.php keygen <file-chiave> | sign <file-chiave> <file>…" );
