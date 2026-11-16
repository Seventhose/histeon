<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\StaticSite;

/**
 * Pre-deploy scan — threat model T8. Looks in the text files of a bundle for things a public static copy
 * must never contain. Findings with severity `block` stop the deploy.
 */
final class Scanner {

	private const TEXT = array( 'html', 'css', 'js', 'mjs', 'json', 'txt', 'svg', 'map' );

	/**
	 * @return list<array{severity: string, file: string, rule: string, excerpt: string}>
	 */
	public function scan( string $dir ): array {
		$rules    = $this->rules();
		$nonce    = __( 'nonce WordPress', 'xb' );
		$findings = array();
		foreach ( Bundle::files( $dir ) as $relative => $file ) {
			if ( ! in_array( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ), self::TEXT, true ) ) {
				continue;
			}
			$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$shipped = self::shipped_code( (string) $relative );
			foreach ( $rules as $rule => [ $severity, $needle, $is_regex ] ) {
				if ( $shipped && $nonce === $rule ) {
					continue;
				}
				$position = $is_regex ? ( 1 === preg_match( $needle, $content, $m, PREG_OFFSET_CAPTURE ) ? (int) $m[0][1] : false ) : stripos( $content, $needle );
				if ( false !== $position ) {
					$findings[] = array(
						'severity' => $severity,
						'file'     => $relative,
						'rule'     => $rule,
						'excerpt'  => mb_substr( (string) preg_replace( '/\s+/', ' ', substr( $content, max( 0, $position - 40 ), 120 ) ), 0, 120 ),
					);
				}
			}
		}
		return $findings;
	}

	/**
	 * A script or style that WordPress, a plugin or a theme ships: the asset is copied from disk, so it is the same
	 * file for every visitor and cannot carry a session nonce. Libraries such as `api-fetch` name the `X-WP-Nonce`
	 * header in their code; the nonce rule would stop every site that loads them. Uploads and caches are still checked.
	 */
	private static function shipped_code( string $relative ): bool {
		$path = '/' . ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'js', 'mjs', 'css', 'map' ), true ) ) {
			return false;
		}
		$content = '/' . trim( (string) wp_make_link_relative( content_url() ), '/' );
		foreach ( array( '/' . WPINC . '/', $content . '/plugins/', $content . '/themes/', $content . '/mu-plugins/' ) as $root ) {
			if ( str_starts_with( $path, $root ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<mixed> $findings */
	public static function blocking( array $findings ): bool {
		foreach ( $findings as $finding ) {
			if ( is_array( $finding ) && 'block' === ( $finding['severity'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string, array{0: string, 1: string, 2: bool}> rule → [severity, needle, regex?] */
	private function rules(): array {
		$rules = array(
			__( 'percorso del server', 'xb' )     => array( 'block', rtrim( ABSPATH, '/' ), false ),
			// The real token and the real annotation, not the words: a post that merely mentions them must not stop
			// every deploy of the site.
			__( 'token di anteprima', 'xb' )      => array( 'block', '/xb_preview=\\d{9,11}\\.\\d+\\.\\d+\\.[0-9a-f]{64}/', true ),
			__( 'annotazioni del builder', 'xb' ) => array( 'block', '/\\sdata-xb-uid=["\']?[a-z0-9]{8,16}["\'\\s>]/', true ),
			__( 'nonce WordPress', 'xb' )         => array( 'block', '/(_wpnonce=|name=["\']_wpnonce["\']|["\']?(nonce|ajax_nonce|restNonce|security)["\']?\s*:\s*["\'][0-9a-f]{10}["\']|X-WP-Nonce)/i', true ),
			__( 'percorso dei contenuti', 'xb' )  => array( 'block', rtrim( WP_CONTENT_DIR, '/' ), false ),
			__( 'chiave API', 'xb' )              => array( 'block', '/(sk_live_[0-9a-zA-Z]{10,}|AKIA[0-9A-Z]{16}|ghp_[0-9A-Za-z]{30,}|xox[baprs]-[0-9A-Za-z-]{10,})/', true ),
			__( 'indirizzo IP privato', 'xb' )    => array( 'warn', '/\b(10\.\d{1,3}|192\.168|172\.(1[6-9]|2\d|3[01]))\.\d{1,3}\.\d{1,3}\b/', true ),
			__( 'link a wp-admin', 'xb' )         => array( 'warn', '/wp-admin/', false ),
			__( 'link a wp-login', 'xb' )         => array( 'warn', 'wp-login.php', false ),
			__( 'endpoint REST', 'xb' )           => array( 'warn', '/wp-json/', false ),
		);
		$origin = defined( 'XB_EXPORT_ORIGIN' ) && is_string( XB_EXPORT_ORIGIN ) ? (string) wp_parse_url( XB_EXPORT_ORIGIN, PHP_URL_HOST ) : '';
		if ( '' !== $origin && $origin !== (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$rules[ __( 'host interno di export', 'xb' ) ] = array( 'block', '//' . $origin, false );
		}
		$site = (string) wp_parse_url( site_url(), PHP_URL_HOST );
		if ( $site !== (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$rules[ __( 'host di WordPress', 'xb' ) ] = array( 'warn', '//' . $site, false );
		}
		$email = (string) get_option( 'admin_email' );
		if ( '' !== $email ) {
			$rules[ __( 'email amministratore', 'xb' ) ] = array( 'warn', $email, false );
		}
		return $rules;
	}
}
