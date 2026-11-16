<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\StaticSite;

/**
 * Builds a static copy of the published site — docs/spec/static.md.
 *
 * 1. Lists the public URLs (home, viewable singular content, public archives), without the shop's dynamic pages.
 * 2. Renders each one through a loopback request as an anonymous visitor in export mode.
 * 3. Copies the assets the pages reference, straight from disk (never through PHP), and rewrites
 *    absolute site URLs to root-relative ones.
 * 4. Writes `404.html` and runs the Scanner: a bundle with findings marked `block` cannot be deployed.
 *
 * Origin for loopback requests: constant XB_EXPORT_ORIGIN (e.g. http://127.0.0.1 behind a proxy),
 * otherwise home_url(). The Host header always names the public host.
 */
final class Exporter {

	private const MAX_URLS  = 5000;
	/** Pages of one archive (page 1 included) that the export follows; filter `xb_static_archive_pages`. */
	private const MAX_ARCHIVE_PAGES = 50;
	private const MAX_BYTES = 10 * 1024 * 1024;

	/** @var list<string> */
	private array $log = array();

	/**
	 * @return array{dir: string, pages: int, assets: int, findings: list<array{severity: string, file: string, rule: string, excerpt: string}>, log: list<string>}
	 */
	public function build( string $label ): array {
		$dir = Bundle::create( $label );
		try {
			$assets = new Assets( $dir, fn ( string $path ): bool => $this->reachable( $path ) );
			$pages  = 0;
			foreach ( $this->urls() as $path ) {
				$html = $this->fetch( $path, 200 );
				if ( null === $html ) {
					continue;
				}
				$html = $assets->collect_and_rewrite( $this->relativize( $html ) );
				Bundle::write( $dir, Bundle::page_file( $path ), $html );
				++$pages;
			}
			$missing = $this->fetch( '/xb-static-404-' . bin2hex( random_bytes( 6 ) ) . '/', 404 );
			if ( null !== $missing ) {
				Bundle::write( $dir, '404.html', $assets->collect_and_rewrite( $this->relativize( $missing ) ) );
			}
			Bundle::write( $dir, SecurityHeaders::FILE, SecurityHeaders::file() );
			$findings = ( new Scanner() )->scan( $dir );
		} catch ( \Throwable $e ) {
			// A build that stops halfway leaves no pages of the site in the work area (note 60, audit of 3 November 2026).
			Bundle::delete( $dir );
			throw $e;
		}
		Bundle::write_meta(
			$dir,
			array(
				'label'    => $label,
				'pages'    => $pages,
				'assets'   => $assets->count(),
				'blocking' => Scanner::blocking( $findings ),
			)
		);
		return array(
			'dir'      => $dir,
			'pages'    => $pages,
			'assets'   => $assets->count(),
			'findings' => $findings,
			'log'      => $this->log,
		);
	}

	/** @return list<string> Root-relative paths of the public pages. */
	public function urls(): array {
		$paths = array();
		$paths[ $this->path( home_url( '/' ) ) ?? '/' ] = true;
		$types = array_values( array_filter( get_post_types( array( 'public' => true ) ), static fn ( string $t ): bool => 'attachment' !== $t && is_post_type_viewable( $t ) ) );
		$ids   = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => self::MAX_URLS,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$dynamic = self::dynamic_pages();
		foreach ( $ids as $id ) {
			if ( in_array( (int) $id, $dynamic, true ) ) {
				continue;
			}
			$path = $this->path( get_permalink( (int) $id ) );
			if ( null !== $path ) {
				$paths[ $path ] = true;
			}
		}
		$per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		foreach ( $types as $type ) {
			$this->add_archive( $paths, get_post_type_archive_link( $type ), (int) ( wp_count_posts( $type )->publish ?? 0 ), $per_page );
		}
		// The blog index, on the home page or on its own page, and its following pages.
		$blog = 'page' === get_option( 'show_on_front' ) ? ( (int) get_option( 'page_for_posts' ) > 0 ? get_permalink( (int) get_option( 'page_for_posts' ) ) : false ) : home_url( '/' );
		$this->add_archive( $paths, $blog, (int) ( wp_count_posts( 'post' )->publish ?? 0 ), $per_page );
		foreach ( $this->authors() as $author => $count ) {
			$this->add_archive( $paths, get_author_posts_url( $author ), $count, $per_page );
		}
		foreach ( $this->months() as $month ) {
			$this->add_archive( $paths, get_month_link( $month['year'], $month['month'] ), $month['count'], $per_page );
			$year = get_year_link( $month['year'] );
			$path = $this->path( $year );
			if ( null !== $path ) {
				$paths[ $path ] = true;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 1000,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term );
				$this->add_archive( $paths, is_wp_error( $link ) ? false : $link, $term->count, $per_page );
			}
		}
		$list = array_slice( array_keys( (array) apply_filters( 'xb_static_paths', $paths ) ), 0, self::MAX_URLS );
		return array_values( array_filter( $list, static fn ( mixed $p ): bool => is_string( $p ) && Bundle::safe_path( $p ) ) );
	}

	/**
	 * The shop's own pages, which stay dynamic in WordPress (docs/spec/ecommerce.md): WooCommerce's cart, checkout and
	 * account pages hold session nonces or redirect an empty cart, so a static copy of them could only be broken.
	 *
	 * @return list<int>
	 */
	private static function dynamic_pages(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		$ids = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) get_option( 'woocommerce_' . $page . '_page_id', 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * An archive and its following pages (`/page/2/`…), up to MAX_ARCHIVE_PAGES: WordPress lists `posts_per_page`
	 * items per page. With plain permalinks the pages are query strings, which the export leaves out.
	 *
	 * @param array<string, true> $paths
	 */
	private function add_archive( array &$paths, string|false $url, int $items, int $per_page ): void {
		$path = $this->path( $url );
		if ( null === $path ) {
			return;
		}
		$paths[ $path ] = true;
		global $wp_rewrite;
		if ( ! is_string( $url ) || ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
			return;
		}
		$cap   = max( 1, (int) apply_filters( 'xb_static_archive_pages', self::MAX_ARCHIVE_PAGES ) );
		$pages = min( $cap, (int) ceil( $items / $per_page ) );
		for ( $n = 2; $n <= $pages; $n++ ) {
			$next = $this->path( user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $n, 'paged' ) );
			if ( null !== $next ) {
				$paths[ $next ] = true;
			}
		}
	}

	/** @return array<int, int> Authors with published posts → how many. */
	private function authors(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_author AS id, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_password = '' GROUP BY post_author LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( (int) $row['id'] > 0 ) {
				$out[ (int) $row['id'] ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/** @return list<array{year: int, month: int, count: int}> Months with published posts, newest first. */
	private function months(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT YEAR(post_date) AS y, MONTH(post_date) AS m, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_password = '' GROUP BY y, m ORDER BY y DESC, m DESC LIMIT 600", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'year'  => (int) $row['y'],
				'month' => (int) $row['m'],
				'count' => (int) $row['n'],
			);
		}
		return $out;
	}

	/** Root-relative path of a URL on this site, or null (other hosts and query strings stay out). */
	private function path( string|false $url ): ?string {
		if ( ! is_string( $url ) ) {
			return null;
		}
		$home = wp_parse_url( home_url( '/' ) );
		$part = wp_parse_url( $url );
		if ( ! is_array( $home ) || ! is_array( $part ) || ( $part['host'] ?? '' ) !== ( $home['host'] ?? '' ) || isset( $part['query'] ) ) {
			return null;
		}
		$path = $part['path'] ?? '/';
		return Bundle::safe_path( $path ) ? $path : null;
	}

	private static function origin(): string {
		return defined( 'XB_EXPORT_ORIGIN' ) && is_string( XB_EXPORT_ORIGIN ) ? rtrim( XB_EXPORT_ORIGIN, '/' ) : rtrim( home_url(), '/' );
	}

	/**
	 * TLS verification is skipped only for an XB_EXPORT_ORIGIN that is localhost, a single-label internal name,
	 * or a private/loopback IP literal (certificates there are usually self-signed). Any public DNS name or
	 * public IP is verified: the exported HTML is what gets deployed, and the request carries the export token.
	 */
	private static function verify_tls(): bool {
		$host = trim( (string) wp_parse_url( self::origin(), PHP_URL_HOST ), '[]' );
		if ( ! defined( 'XB_EXPORT_ORIGIN' ) || '' === $host ) {
			return true;
		}
		if ( 'localhost' === $host || 1 === preg_match( '/^[a-z][a-z0-9-]{0,62}$/i', $host ) ) {
			return false;
		}
		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true; // A DNS name with dots: always verified.
		}
		return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/** @return array<string, mixed> */
	private static function request_args( string $method, string $token ): array {
		$home = wp_parse_url( home_url() );
		$host = is_array( $home ) ? (string) ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) : '';
		return array(
			'method'              => $method,
			'headers'             => array(
				'Host'             => $host,
				ExportMode::HEADER => $token,
			),
			'cookies'             => array(),
			'redirection'         => 0,
			'timeout'             => 30,
			'limit_response_size' => self::MAX_BYTES + 1,
			'sslverify'           => self::verify_tls(),
			'user-agent'          => 'XB-Static-Exporter',
		);
	}

	/** Whether the web server gives this file to an anonymous visitor. */
	public function reachable( string $path ): bool {
		$res = wp_remote_request( self::origin() . $path, array_merge( self::request_args( 'HEAD', ExportMode::token() ), array( 'limit_response_size' => 1 ) ) );
		return ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
	}

	/** Renders a path anonymously; null (logged) when the answer is not the expected fresh render. */
	public function fetch( string $path, int $expected ): ?string {
		$token = ExportMode::token();
		$res   = wp_remote_request( self::origin() . $path, self::request_args( 'GET', $token ) );
		if ( is_wp_error( $res ) ) {
			$this->log[] = sprintf( '%s: %s', $path, $res->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code !== $expected ) {
			$this->log[] = sprintf( /* translators: 1: URL path, 2: HTTP status received, 3: HTTP status expected */ __( '%1$s: risposta %2$d (attesa %3$d)', 'xb' ), $path, $code, $expected );
			return null;
		}
		$echo = wp_remote_retrieve_header( $res, strtolower( ExportMode::ECHO ) );
		$echo = is_array( $echo ) ? (string) reset( $echo ) : $echo;
		if ( ! hash_equals( $token, $echo ) ) {
			$this->log[] = sprintf( /* translators: %s: URL path */ __( '%s: risposta non generata ora (cache?), scartata', 'xb' ), $path );
			return null;
		}
		$body = wp_remote_retrieve_body( $res );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			$this->log[] = sprintf( /* translators: 1: URL path, 2: size limit in MB */ __( '%1$s: pagina oltre %2$d MB, scartata', 'xb' ), $path, (int) ( self::MAX_BYTES / 1048576 ) );
			return null;
		}
		return $body;
	}

	/** Absolute links to this site become root-relative, so the copy works on any domain. */
	private function relativize( string $html ): string {
		foreach ( array_unique( array( untrailingslashit( home_url() ), untrailingslashit( site_url() ) ) ) as $base ) {
			$escaped = str_replace( '/', '\\/', $base );
			$html    = str_replace( array( $base . '/', $escaped . '\\/' ), array( '/', '\\/' ), $html );
			$html    = str_replace( array( '"' . $base . '"', "'" . $base . "'" ), array( '"/"', "'/'" ), $html );
		}
		return $html;
	}
}
