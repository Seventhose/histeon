<?php
/**
 * Loads the dev WordPress (dev/compose.yml) for the integration suite.
 *
 * @package XB
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require __DIR__ . '/../../vendor/autoload.php';

// Histeon is written in Italian and the suite checks Italian texts: Italian here, whatever language the dev site
// has (every other language gets English, which I18nTest covers). Loaded again on first use, in Italian.
add_filter( 'locale', static fn (): string => 'it_IT' );
unload_textdomain( 'xb', true );

if ( ! defined( 'XB_VERSION' ) ) {
	fwrite( STDERR, "Il plugin XB non è attivo nel WordPress di sviluppo (esegui dev/up.sh).\n" );
	exit( 1 );
}
