<?php
/**
 * Writes Histeon CSS's two stylesheets from their PHP definition — docs/spec/histos-css.md:
 *   assets/tokens/xb-tokens.css     the --xb-* tokens of the default design (HistosCss\Tokens);
 *   assets/framework/histos.css      the classes (HistosCss\Utilities).
 * Usage: php bin/build-histos-css.php [--check]   (--check: exit 1 if a file is not up to date, write nothing)
 *
 * @package XB
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
require dirname( __DIR__ ) . '/vendor/autoload.php';

$root  = dirname( __DIR__ );
$files = array(
	$root . '/assets/tokens/xb-tokens.css'  => \XB\Framework\HistosCss\Tokens::stylesheet(),
	$root . '/assets/framework/histos.css'   => \XB\Framework\HistosCss\Utilities::stylesheet(),
);
$check = in_array( '--check', $argv, true );
$stale = 0;
foreach ( $files as $path => $css ) {
	$same = is_readable( $path ) && file_get_contents( $path ) === $css;
	if ( $check ) {
		if ( ! $same ) {
			fwrite( STDERR, 'Da rigenerare: ' . substr( $path, strlen( $root ) + 1 ) . "\n" );
			++$stale;
		}
		continue;
	}
	if ( ! $same ) {
		file_put_contents( $path, $css );
	}
	fwrite( STDOUT, substr( $path, strlen( $root ) + 1 ) . ': ' . strlen( $css ) . " byte\n" );
}
exit( $stale > 0 ? 1 : 0 );
