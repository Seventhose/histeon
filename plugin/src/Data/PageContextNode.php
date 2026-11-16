<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * Portable template page context (`props.page`) for WordPress singles and archives, with the names of the static
 * build of Histeon Publish (docs/spec/templates.md §5). The ordinary `this` root remains the current post, including
 * the first post in an archive; `props.page` on an archive describes the archive itself, never that first post.
 * On an archive (type, term, posts index, author, date) it also has the pagination of the main query:
 * `page totalPages totalRecords prevUrl nextUrl pageLinks` (ArchivePagination), and `url` is the page shown.
 */
final class PageContextNode implements Node {

	private const PAGINATION_KEYS = array( 'page', 'totalPages', 'totalRecords', 'prevUrl', 'nextUrl', 'pageLinks' );

	/** @var array<string, mixed>|false|null Computed on first read: null not yet, false not an archive. */
	private array|false|null $pagination = null;

	/**
	 * @param array<string, mixed> $archive    Identity of an archive with no post or term of its own (type, posts index
	 *                                         without a posts page, author, date): key → value; `url` is page 1.
	 * @param bool                 $posts_page The post is the posts page, shown as the posts index.
	 */
	private function __construct(
		private readonly ?\WP_Post $post = null,
		private readonly ?\WP_Term $term = null,
		private readonly array $archive = array(),
		private readonly ?\WP_Query $query = null,
		private readonly bool $posts_page = false
	) {}

	public static function from_globals(): ?self {
		$query = $GLOBALS['wp_query'] ?? null;
		$query = $query instanceof \WP_Query ? $query : null;
		if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$queried = get_queried_object();
			if ( '' === $type && $queried instanceof \WP_Post_Type ) {
				$type = $queried->name;
			}
			if ( is_array( $type ) ) {
				$type = 1 === count( $type ) ? reset( $type ) : '';
			}
			$type = is_string( $type ) ? $type : '';
			$object = '' !== $type ? get_post_type_object( $type ) : null;
			if ( $object instanceof \WP_Post_Type ) {
				$label = isset( $object->labels->name ) ? (string) $object->labels->name : (string) $object->label;
				$url   = get_post_type_archive_link( $type );
				return new self( archive: self::identity( $type, $label, $type, $type, is_string( $url ) ? $url : home_url( '/' ) ), query: $query );
			}
		}

		if ( function_exists( 'is_tax' ) && ( is_tax() || is_category() || is_tag() ) ) {
			$queried = get_queried_object();
			if ( $queried instanceof \WP_Term ) {
				return new self( term: $queried, query: $query );
			}
		}

		if ( null !== $query && ! $query->is_singular() && ! $query->is_search() && ! $query->is_404() ) {
			if ( $query->is_home() ) {
				$posts_page = 'page' === get_option( 'show_on_front' ) ? get_post( (int) get_option( 'page_for_posts' ) ) : null;
				if ( $posts_page instanceof \WP_Post && 'page' === $posts_page->post_type ) {
					return new self( post: $posts_page, query: $query, posts_page: true );
				}
				// Publish's /blog/: the list of posts with no page of its own.
				return new self( archive: self::identity( 'post', 'Blog', 'blog', 'post', home_url( '/' ) ), query: $query );
			}
			if ( $query->is_author() ) {
				$author = $query->get_queried_object();
				if ( $author instanceof \WP_User ) {
					return new self( archive: self::identity( $author->ID, (string) $author->display_name, (string) $author->user_nicename, null, get_author_posts_url( $author->ID, $author->user_nicename ) ), query: $query );
				}
			}
			if ( $query->is_date() ) {
				$period = ArchivePagination::date_period( $query );
				$url    = ArchivePagination::date_link( $query );
				if ( null !== $period && null !== $url ) {
					return new self( archive: self::identity( null, self::period_label( $period ), null, null, $url ), query: $query );
				}
			}
		}

		$queried = get_queried_object();
		if ( ! $queried instanceof \WP_Post ) {
			$queried = get_post();
		}
		return $queried instanceof \WP_Post ? new self( post: $queried, query: $query ) : null;
	}

	/**
	 * The context of one post shown as a single, whatever the current request is showing: a page rendered on its own
	 * (the SEO summary) reads its own title and fields, never those of the archive or page that asked for it.
	 */
	public static function for_post( \WP_Post $post ): self {
		return new self( post: $post );
	}

	/** @return array<string, mixed> */
	private static function identity( int|string|null $id, string $title, ?string $slug, ?string $type, string $url ): array {
		return array(
			'id'      => $id,
			'title'   => $title,
			'name'    => $title,
			'slug'    => $slug,
			'type'    => $type,
			'typeKey' => $type,
			'url'     => $url,
		);
	}

	/**
	 * The period of a date archive as WordPress names it in its archive titles, without the «Mese:» prefix:
	 * the year, the month (`F Y`), the day (the site's date format).
	 *
	 * @param array{year: int, month: int, day: int} $period
	 */
	private static function period_label( array $period ): string {
		if ( $period['day'] > 0 ) {
			return (string) mysql2date( (string) get_option( 'date_format' ), sprintf( '%04d-%02d-%02d 00:00:00', $period['year'], $period['month'], $period['day'] ) );
		}
		if ( $period['month'] > 0 ) {
			return (string) mysql2date( _x( 'F Y', 'monthly archives date format' ), sprintf( '%04d-%02d-01 00:00:00', $period['year'], $period['month'] ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own string.
		}
		return (string) $period['year'];
	}

	/** @return array<string, mixed>|null */
	private function pagination(): ?array {
		if ( null === $this->pagination ) {
			$this->pagination = ( null !== $this->query ? ArchivePagination::for_query( $this->query ) : null ) ?? false;
		}
		return false === $this->pagination ? null : $this->pagination;
	}

	/** On an archive, the address of the page shown; `$first` (the archive link) otherwise. */
	private function shown_url( mixed $first ): mixed {
		$pagination = $this->pagination();
		return null !== $pagination ? $pagination['url'] : $first;
	}

	public function get( string $key ): mixed {
		if ( in_array( $key, self::PAGINATION_KEYS, true ) ) {
			return $this->pagination()[ $key ] ?? null;
		}
		if ( $this->post instanceof \WP_Post ) {
			if ( $this->posts_page ) {
				// The posts page as the posts index: its own title, slug, excerpt and fields; the listed type is `post`.
				return match ( $key ) {
					'id' => $this->post->ID,
					'title', 'name' => get_the_title( $this->post ),
					'slug' => $this->post->post_name,
					'url' => $this->shown_url( get_permalink( $this->post ) ?: null ),
					'type', 'typeKey' => 'post',
					'excerpt' => PostNode::for_post( $this->post )?->get( 'excerpt' ),
					'meta', 'fields', 'values' => new PageMetaNode( $this->post ),
					default => null,
				};
			}
			return match ( $key ) {
				'id' => $this->post->ID,
				'title', 'name' => get_the_title( $this->post ),
				'slug' => $this->post->post_name,
				'url' => get_permalink( $this->post ) ?: null,
				'type', 'typeKey' => $this->post->post_type,
				'excerpt' => PostNode::for_post( $this->post )?->get( 'excerpt' ),
				'meta', 'fields', 'values' => new PageMetaNode( $this->post ),
				'terms' => new TermsNode( $this->post ),
				// The rest as the content itself has it: date and publishedAt, modified, author, image, parent…
				default => PostNode::for_post( $this->post )?->get( $key ),
			};
		}
		if ( $this->term instanceof \WP_Term ) {
			return match ( $key ) {
				'id' => $this->term->term_id,
				'title', 'name' => $this->term->name,
				'slug' => $this->term->slug,
				'url' => $this->shown_url( ( static fn ( mixed $url ): ?string => is_string( $url ) ? $url : null )( get_term_link( $this->term ) ) ),
				'type', 'typeKey', 'taxonomy', 'taxonomyKey' => $this->term->taxonomy,
				'meta', 'fields', 'values' => new PageMetaNode( $this->term ),
				default => null,
			};
		}
		if ( 'url' === $key ) {
			return $this->shown_url( $this->archive['url'] ?? null );
		}
		return $this->archive[ $key ] ?? null;
	}
}
