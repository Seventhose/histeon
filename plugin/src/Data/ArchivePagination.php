<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * Pagination of the archive a template shows (`props.page.page`, `.totalPages`, `.totalRecords`, `.prevUrl`,
 * `.nextUrl`, `.pageLinks`), the same fields as the static build of Histeon Publish (docs/spec/templates.md §5).
 *
 * The addresses start from the archive's own link (type, term, posts page, author, date), never from the request:
 * WordPress's get_pagenum_link() keeps the request's query string, so a visitor's `?x=…` would end up in every link.
 * Page N is that link plus `page/N/` as WordPress writes it (pagination base, trailing slash of the permalink
 * structure), or `paged=N` with plain permalinks.
 */
final class ArchivePagination {

	/** pageLinks lists at most this many pages, from the first. */
	public const MAX_PAGE_LINKS = 100;

	/**
	 * The fields for a WordPress query, or null when it is not a paginated archive (a single, a search, a 404).
	 *
	 * @return array{page: int, totalPages: int, totalRecords: int, prevUrl: string, nextUrl: string, pageLinks: list<array{number: int, url: string, current: bool}>, url: string}|null
	 */
	public static function for_query( \WP_Query $query ): ?array {
		$base = self::base( $query );
		if ( null === $base ) {
			return null;
		}
		global $wp_rewrite;
		$pretty = $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks() ? (string) $wp_rewrite->pagination_base : null;
		$paged  = $query->get( 'paged' );
		return self::fields(
			is_numeric( $paged ) ? (int) $paged : 1,
			(int) $query->max_num_pages,
			(int) $query->found_posts,
			static fn ( int $n ): string => self::page_url( $base, $n, $pretty, static fn ( string $path ): string => user_trailingslashit( $path, 'paged' ) )
		);
	}

	/**
	 * The fields, from the numbers WordPress gives: page 0 (not paged) is page 1, and an archive always has at least
	 * one page, also when it is empty (WordPress then says 0 pages), as in Publish.
	 *
	 * @param callable(int): string $link Address of page N.
	 * @return array{page: int, totalPages: int, totalRecords: int, prevUrl: string, nextUrl: string, pageLinks: list<array{number: int, url: string, current: bool}>, url: string}
	 */
	public static function fields( int $page, int $max_pages, int $total, callable $link ): array {
		$page  = max( 1, $page );
		$pages = max( 1, $max_pages );
		$links = array();
		for ( $n = 1; $n <= min( $pages, self::MAX_PAGE_LINKS ); $n++ ) {
			$links[] = array(
				'number'  => $n,
				'url'     => $link( $n ),
				'current' => $n === $page,
			);
		}
		return array(
			'page'         => $page,
			'totalPages'   => $pages,
			'totalRecords' => max( 0, $total ),
			'prevUrl'      => $page > 1 ? $link( $page - 1 ) : '',
			'nextUrl'      => $page < $pages ? $link( $page + 1 ) : '',
			'pageLinks'    => $links,
			// The page shown, for `props.page.url` (PageContextNode); page 1 is the archive link.
			'url'          => $link( $page ),
		);
	}

	/**
	 * Page N of an archive whose first page is `$base`, as get_pagenum_link() writes it.
	 *
	 * @param string|null              $pagination_base WordPress's pagination base (`page`) with pretty permalinks;
	 *                                                  null for plain permalinks (`?paged=N`).
	 * @param callable(string): string $trail           The permalink structure's trailing slash (user_trailingslashit).
	 */
	public static function page_url( string $base, int $n, ?string $pagination_base, callable $trail ): string {
		if ( $n <= 1 ) {
			return $base;
		}
		$query = '';
		$at    = strpos( $base, '?' );
		if ( false !== $at ) {
			$query = substr( $base, $at );
			$base  = substr( $base, 0, $at );
		}
		if ( null === $pagination_base ) {
			return $base . ( '' === $query ? '?' : $query . '&' ) . 'paged=' . $n;
		}
		return $trail( rtrim( $base, '/' ) . '/' . $pagination_base . '/' . $n ) . $query;
	}

	/** The first page of the archive the query shows: its own link, or null when it is not a paginated archive. */
	private static function base( \WP_Query $query ): ?string {
		if ( $query->is_singular() || $query->is_search() || $query->is_404() || $query->is_feed() || $query->is_comment_feed() ) {
			return null;
		}
		$url = null;
		if ( $query->is_post_type_archive() ) {
			$type = $query->get( 'post_type' );
			if ( is_array( $type ) ) {
				$type = 1 === count( $type ) ? reset( $type ) : '';
			}
			$queried = $query->get_queried_object();
			if ( ( ! is_string( $type ) || '' === $type ) && $queried instanceof \WP_Post_Type ) {
				$type = $queried->name;
			}
			$url = is_string( $type ) && '' !== $type ? get_post_type_archive_link( $type ) : null;
		} elseif ( $query->is_category() || $query->is_tag() || $query->is_tax() ) {
			$term = $query->get_queried_object();
			$url  = $term instanceof \WP_Term ? get_term_link( $term ) : null;
		} elseif ( $query->is_author() ) {
			$author = (int) $query->get_queried_object_id();
			$url    = $author > 0 ? get_author_posts_url( $author ) : null;
		} elseif ( $query->is_date() ) {
			$url = self::date_link( $query );
		} elseif ( $query->is_home() ) {
			$posts_page = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
			$url        = $posts_page > 0 ? get_permalink( $posts_page ) : home_url( '/' );
		}
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * The period a date archive shows, from the query vars (`m` or `year`/`monthnum`/`day`); null for a time archive
	 * or without a year. A month or day of 0 means the archive is wider (a year, a month).
	 *
	 * @return array{year: int, month: int, day: int}|null
	 */
	public static function date_period( \WP_Query $query ): ?array {
		if ( ! $query->is_date() || $query->is_time() ) {
			return null;
		}
		$m     = preg_replace( '/\D/', '', (string) $query->get( 'm' ) );
		$m     = is_string( $m ) ? $m : '';
		$year  = '' !== $m ? (int) substr( $m, 0, 4 ) : (int) $query->get( 'year' );
		$month = strlen( $m ) >= 6 ? (int) substr( $m, 4, 2 ) : (int) $query->get( 'monthnum' );
		$day   = strlen( $m ) >= 8 ? (int) substr( $m, 6, 2 ) : (int) $query->get( 'day' );
		if ( $year <= 0 ) {
			return null;
		}
		if ( $query->is_day() && $month > 0 && $day > 0 ) {
			return array( 'year' => $year, 'month' => $month, 'day' => $day );
		}
		if ( $query->is_month() && $month > 0 ) {
			return array( 'year' => $year, 'month' => $month, 'day' => 0 );
		}
		return $query->is_year() ? array( 'year' => $year, 'month' => 0, 'day' => 0 ) : null;
	}

	/** The first page of a date archive: WordPress's year, month or day link. */
	public static function date_link( \WP_Query $query ): ?string {
		$period = self::date_period( $query );
		if ( null === $period ) {
			return null;
		}
		if ( $period['day'] > 0 ) {
			return get_day_link( $period['year'], $period['month'], $period['day'] );
		}
		return $period['month'] > 0 ? get_month_link( $period['year'], $period['month'] ) : get_year_link( $period['year'] );
	}
}
