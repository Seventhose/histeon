<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Scripts;

use XB\Versioning\Documents;
use XB\Versioning\Preview;

/**
 * The site's own scripts (lista della spesa 148): libraries and files every page, or every page of one content type,
 * loads — with where (head or footer) and how (defer, async, module). Part of the settings document, so they go
 * from development to the site with a release like everything else.
 *
 * Threat model T1, as for a document's script (Scripts.php): only `unfiltered_html` writes them (REST check, and the
 * settings save refuses them otherwise); the address is the site's own or `https`, and a file from another site must
 * carry its integrity hash, so a changed file there does not run here; the tag is printed by WordPress
 * (wp_print_script_tag), which escapes every attribute. Nothing runs in the builder canvas.
 */
final class SiteScripts {

	public const OPTION = 'xb_site_scripts';
	public const KEY    = 'siteScripts';
	private const MAX   = 30;

	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print_head' ), 20 );
		add_action( 'wp_footer', array( self::class, 'print_footer' ), 20 );
	}

	/**
	 * The scripts, checked; throws with a message for the person who wrote them.
	 *
	 * @param mixed $value
	 * @return list<array{id: string, label: string, src: string, scope: string, position: string, strategy: string, integrity: string}>
	 */
	public static function validate( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX ) {
			throw new \InvalidArgumentException( sprintf( /* translators: %d: number */ __( 'Gli script del sito sono un elenco di al massimo %d voci.', 'xb' ), self::MAX ) );
		}
		$out = array();
		$ids = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new \InvalidArgumentException( __( 'Voce degli script del sito non valida.', 'xb' ) );
			}
			$id        = (string) ( $entry['id'] ?? '' );
			$label     = trim( sanitize_text_field( (string) ( $entry['label'] ?? '' ) ) );
			$src       = trim( (string) ( $entry['src'] ?? '' ) );
			$scope     = (string) ( $entry['scope'] ?? 'site' );
			$position  = (string) ( $entry['position'] ?? 'footer' );
			$strategy  = (string) ( $entry['strategy'] ?? 'defer' );
			$integrity = trim( (string) ( $entry['integrity'] ?? '' ) );
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $id ) || isset( $ids[ $id ] ) ) {
				throw new \InvalidArgumentException( __( 'Ogni script del sito ha un id: minuscole, cifre e trattini, diverso dagli altri.', 'xb' ) );
			}
			$ids[ $id ] = true;
			if ( 1 !== preg_match( '/^(site|type:[a-z0-9_-]{1,20})$/', $scope ) || ! in_array( $position, array( 'head', 'footer' ), true ) || ! in_array( $strategy, array( 'defer', 'async', 'module', 'blocking' ), true ) ) {
				throw new \InvalidArgumentException( __( 'Ambito, posizione o modo di caricamento non validi.', 'xb' ) );
			}
			$own = self::own( $src );
			if ( ! $own && ! self::https( $src ) ) {
				throw new \InvalidArgumentException( sprintf( /* translators: %s: address */ __( 'Indirizzo non ammesso: %s. Serve un file di questo sito o un indirizzo https.', 'xb' ), $src ) );
			}
			if ( ! $own && 1 !== preg_match( '/^sha(384|512)-[A-Za-z0-9+\/]{40,120}={0,2}$/', $integrity ) ) {
				throw new \InvalidArgumentException( sprintf( /* translators: %s: address */ __( 'Un file di un altro sito (%s) va caricato con la sua impronta di integrità (sha384-… o sha512-…), così una sua modifica non gira qui.', 'xb' ), $src ) );
			}
			if ( '' !== $integrity && 1 !== preg_match( '/^sha(256|384|512)-[A-Za-z0-9+\/]{40,120}={0,2}$/', $integrity ) ) {
				throw new \InvalidArgumentException( __( 'Impronta di integrità non valida.', 'xb' ) );
			}
			$out[] = array(
				'id'        => $id,
				'label'     => mb_substr( '' === $label ? $id : $label, 0, 80 ),
				'src'       => $src,
				'scope'     => $scope,
				'position'  => $position,
				'strategy'  => $strategy,
				'integrity' => $integrity,
			);
		}
		return $out;
	}

	/** A file of this site: a path under it, or an address on its own host. */
	private static function own( string $src ): bool {
		if ( str_starts_with( $src, '/' ) && ! str_starts_with( $src, '//' ) ) {
			return 1 === preg_match( '#^/[A-Za-z0-9._~/%-]+\.m?js(\?[A-Za-z0-9=&._-]*)?$#', $src ) && ! str_contains( $src, '..' );
		}
		$home = wp_parse_url( home_url() );
		$url  = wp_parse_url( $src );
		return is_array( $url ) && is_array( $home ) && in_array( $url['scheme'] ?? '', array( 'http', 'https' ), true ) && strtolower( (string) ( $url['host'] ?? '' ) ) === strtolower( (string) ( $home['host'] ?? '' ) ) && empty( $url['user'] ) && empty( $url['pass'] ) && ! str_contains( (string) ( $url['path'] ?? '' ), '..' );
	}

	private static function https( string $src ): bool {
		$url = wp_parse_url( $src );
		return is_array( $url ) && 'https' === ( $url['scheme'] ?? '' ) && '' !== (string) ( $url['host'] ?? '' ) && empty( $url['user'] ) && empty( $url['pass'] ) && false === strpbrk( $src, " \t\r\n\"'<>" );
	}

	/**
	 * The scripts that apply now: those in development during a preview, the published ones otherwise.
	 *
	 * @return list<array{id: string, label: string, src: string, scope: string, position: string, strategy: string, integrity: string}>
	 */
	public static function current(): array {
		$payload = Preview::active() ? Preview::dev_payload( Documents::SETTINGS ) : null;
		$stored  = is_array( $payload ) && array_key_exists( self::KEY, $payload ) ? $payload[ self::KEY ] : get_option( self::OPTION, array() );
		try {
			return self::validate( $stored );
		} catch ( \InvalidArgumentException ) {
			return array(); // Stored values are checked again before they print: a bad one prints nothing.
		}
	}

	/**
	 * The published scripts as validate() returns them; a stored value that no longer validates counts as none.
	 *
	 * @return list<array{id: string, label: string, src: string, scope: string, position: string, strategy: string, integrity: string}>
	 */
	public static function validate_stored(): array {
		try {
			return self::validate( get_option( self::OPTION, array() ) );
		} catch ( \InvalidArgumentException ) {
			return array();
		}
	}

	/** Whether a script's scope covers the page being shown. */
	public static function applies( string $scope ): bool {
		if ( 'site' === $scope ) {
			return true;
		}
		return is_singular( substr( $scope, 5 ) ) || is_post_type_archive( substr( $scope, 5 ) );
	}

	public static function print_head(): void {
		self::print( 'head' );
	}

	public static function print_footer(): void {
		self::print( 'footer' );
	}

	private static function print( string $position ): void {
		if ( is_admin() || ! function_exists( 'wp_print_script_tag' ) ) {
			return;
		}
		foreach ( self::current() as $script ) {
			if ( $script['position'] !== $position || ! self::applies( $script['scope'] ) ) {
				continue;
			}
			wp_print_script_tag( self::attributes( $script ) );
		}
	}

	/**
	 * The tag's attributes: WordPress escapes them when it prints.
	 *
	 * @param array{id: string, src: string, strategy: string, integrity: string} $script
	 * @return array<string, string|bool>
	 */
	public static function attributes( array $script ): array {
		$attrs = array(
			'id'  => 'xb-site-script-' . $script['id'],
			'src' => $script['src'],
		);
		if ( 'module' === $script['strategy'] ) {
			$attrs['type'] = 'module';
		} elseif ( 'defer' === $script['strategy'] || 'async' === $script['strategy'] ) {
			$attrs[ $script['strategy'] ] = true;
		}
		if ( '' !== $script['integrity'] ) {
			$attrs['integrity']   = $script['integrity'];
			$attrs['crossorigin'] = 'anonymous';
		}
		return $attrs;
	}
}
