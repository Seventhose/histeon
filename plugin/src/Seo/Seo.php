<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Seo;

use XB\Data\PageContextNode;
use XB\Plugin;
use XB\Render\Context;
use XB\Spec;
use XB\Versioning\Versioning;

/**
 * XB pages for SEO plugins (ADR 0011). XB keeps its text in block attributes, so whatever reads `post_content`
 * finds nothing: no automatic description, no images in the sitemap, empty content analysis. Here the page is
 * rendered once, as an anonymous visitor sees the live site, and its text and images are handed to:
 * - WordPress's own excerpt (used by Yoast's %%excerpt%%, Rank Math's %excerpt%, feeds);
 * - Yoast SEO and Rank Math sitemaps (image entries, dynamic sources resolved);
 * - Slim SEO (its content parser renders XB blocks; its sitemap gets the XB images);
 * - The SEO Framework (its description excerpt and its social image generator).
 * The analysis in the block editor (Yoast, Rank Math) runs in the browser: assets/editor/seo.js.
 */
final class Seo {

	/** Images handed over per page, and text kept for descriptions. */
	private const MAX_IMAGES = 100;
	private const MAX_TEXT   = 20000;

	/** @var array<int, array{text: string, images: list<array{src: string, alt: string, title: string}>}> */
	private static array $memo = array();
	private static bool $rendering = false;

	public static function register(): void {
		add_filter( 'get_the_excerpt', array( self::class, 'excerpt' ), 20, 2 );
		add_filter( 'wpseo_sitemap_urlimages', array( self::class, 'sitemap_images' ), 10, 2 );
		add_filter( 'rank_math/sitemap/urlimages', array( self::class, 'sitemap_images' ), 10, 2 );
		add_filter( 'slim_seo_allowed_blocks', array( self::class, 'slim_blocks' ) );
		add_action( 'slim_seo_sitemap_post', array( self::class, 'slim_sitemap' ) );
		add_filter( 'the_seo_framework_get_excerpt', array( self::class, 'tsf_excerpt' ), 10, 2 );
		add_filter( 'the_seo_framework_image_generation_params', array( self::class, 'tsf_images' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
	}

	// ------------------------------------------------------------------ the page as a visitor sees it

	/**
	 * Text and images of an XB page, rendered from the live site for an anonymous visitor (nothing personal or
	 * unpublished ever reaches a description or a sitemap). Cached until the page or the release changes.
	 *
	 * @return array{text: string, images: list<array{src: string, alt: string, title: string}>}
	 */
	public static function page( int $post_id ): array {
		$empty = array(
			'text'   => '',
			'images' => array(),
		);
		$post  = get_post( $post_id );
		// A preview or builder request renders development content: nothing from it may reach the shared copy.
		// A password-protected post gives nothing to descriptions or sitemaps, whoever asks: not post_password_required(),
		// which says false to whoever holds the password cookie or may edit the post (REST edit context).
		if ( ! $post instanceof \WP_Post || ! Spec::has_xb_blocks( $post->post_content ) || '' !== $post->post_password || self::$rendering || \XB\Versioning\Preview::active() ) {
			return $empty;
		}
		if ( isset( self::$memo[ $post_id ] ) ) {
			return self::$memo[ $post_id ];
		}
		$key    = 'xb_seo_' . $post_id . '_' . md5( $post->post_modified_gmt . '|' . (int) get_option( Versioning::OPTION_CURRENT, 0 ) . '|' . XB_VERSION );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && is_string( $cached['text'] ?? null ) && is_array( $cached['images'] ?? null ) ) {
			/** @var array{text: string, images: list<array{src: string, alt: string, title: string}>} $cached */
			return self::$memo[ $post_id ] = $cached;
		}
		$html = self::render( $post );
		$data = array(
			'text'   => self::text( $html ),
			'images' => self::images( $html ),
		);
		set_transient( $key, $data, DAY_IN_SECONDS );
		return self::$memo[ $post_id ] = $data;
	}

	private static function render( \WP_Post $target ): string {
		global $post;
		$previous_post = $post;
		$previous_user = get_current_user_id();
		$previous_get  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved to render without it, restored below.
		$previous_uri  = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- saved and restored as it is.
		self::$rendering = true;
		wp_set_current_user( 0 );
		$_GET = array(); // The shared copy is the page any visitor gets: no url.query or facet of this request.
		// … and its own address for url.path, not the one of the request that happened to build the copy.
		$_SERVER['REQUEST_URI'] = (string) ( wp_parse_url( (string) get_permalink( $target ), PHP_URL_PATH ) ?: '/' );
		$post = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
		setup_postdata( $target );
		try {
			$plugin = Plugin::instance();
			// `page` and `props.page` are the page summarised, not the archive or single of the request that happened
			// to compute the shared copy (an excerpt asked for while a category or another page is shown).
			$page    = PageContextNode::for_post( $target );
			$context = Context::from_globals()->with(
				array(
					'props' => array( 'page' => $page ),
					'page'  => $page,
				)
			);
			return (string) $plugin->styles->isolated( static fn (): string => $plugin->renderer->render_blocks( parse_blocks( $target->post_content ), $context ) );
		} catch ( \Throwable ) {
			return '';
		} finally {
			$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			wp_reset_postdata();
			wp_set_current_user( $previous_user );
			$_GET = $previous_get;
			if ( null === $previous_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_uri;
			}
			self::$rendering = false;
		}
	}

	/** Visible text: no scripts, styles or markup, whitespace collapsed. */
	public static function text( string $html ): string {
		$html = (string) preg_replace( '#<(script|style|noscript|template|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		// Elements that end a word for a reader also end it here ("01 / 07" and a caption are not one word).
		$html = (string) preg_replace( '#<(br|hr|img|/(?:p|h[1-6]|li|dt|dd|div|section|article|header|footer|nav|main|aside|figure|figcaption|blockquote|td|th|tr|span|a|button|label|time|small|strong|em))\b[^>]*>#i', '$0 ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $text ) ), 0, self::MAX_TEXT );
	}

	/**
	 * Images of this site in the rendered page, with their alt and title, without repeats.
	 *
	 * @return list<array{src: string, alt: string, title: string}>
	 */
	public static function images( string $html ): array {
		$out  = array();
		$seen = array();
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( count( $out ) < self::MAX_IMAGES && $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
			$src = $tags->get_attribute( 'src' );
			if ( ! is_string( $src ) || '' === $src || str_starts_with( $src, 'data:' ) ) {
				continue;
			}
			$url  = str_starts_with( $src, '/' ) && ! str_starts_with( $src, '//' ) ? home_url( $src ) : $src;
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) || $host !== $home || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$alt          = $tags->get_attribute( 'alt' );
			$title        = $tags->get_attribute( 'title' );
			$out[]        = array(
				'src'   => $url,
				'alt'   => is_string( $alt ) ? $alt : '',
				'title' => is_string( $title ) ? $title : '',
			);
		}
		return $out;
	}

	/** First words of the page, as WordPress shortens an excerpt. */
	private static function words( string $text ): string {
		/** This filter is documented in wp-includes/formatting.php */
		$length = (int) apply_filters( 'excerpt_length', 55 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core hook.
		return wp_trim_words( $text, $length, '…' );
	}

	// ------------------------------------------------------------------ WordPress and the plugins

	/**
	 * WordPress's automatic excerpt (after wp_trim_excerpt) is empty for XB pages: the page's own words instead.
	 * A written excerpt always wins.
	 */
	public static function excerpt( mixed $text, mixed $post = null ): string {
		$text = is_string( $text ) ? $text : '';
		$post = get_post( $post instanceof \WP_Post || is_int( $post ) ? $post : null );
		if ( '' !== trim( $text ) || ! $post instanceof \WP_Post || '' !== $post->post_excerpt ) {
			return $text;
		}
		return self::words( self::page( $post->ID )['text'] );
	}

	/**
	 * Yoast SEO and Rank Math: the images of the rendered page join theirs in the sitemap.
	 *
	 * @param mixed $images
	 * @return array<mixed>
	 */
	public static function sitemap_images( mixed $images, mixed $post_id = 0 ): array {
		$images = is_array( $images ) ? $images : array();
		$known  = array();
		foreach ( $images as $image ) {
			if ( is_array( $image ) && is_string( $image['src'] ?? null ) ) {
				$known[ $image['src'] ] = true;
			}
		}
		foreach ( self::page( (int) $post_id )['images'] as $image ) {
			if ( ! isset( $known[ $image['src'] ] ) ) {
				$images[] = $image;
			}
		}
		return $images;
	}

	/**
	 * Slim SEO renders only the blocks it calls static: XB blocks render on the server, so they are added.
	 *
	 * @param mixed $blocks
	 * @return array<mixed>
	 */
	public static function slim_blocks( mixed $blocks ): array {
		$blocks = is_array( $blocks ) ? $blocks : array();
		foreach ( Spec::BLOCK_NAMES as $name ) {
			if ( ! in_array( $name, $blocks, true ) ) {
				$blocks[] = $name;
			}
		}
		return $blocks;
	}

	/** Slim SEO's sitemap reads images from the raw content: the XB ones are added to the entry. */
	public static function slim_sitemap( mixed $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$thumbnail = (string) get_the_post_thumbnail_url( $post, 'full' );
		foreach ( self::page( $post->ID )['images'] as $image ) {
			if ( $image['src'] === $thumbnail ) {
				continue;
			}
			echo "\t\t<image:image>\n\t\t\t<image:loc>", esc_url( $image['src'] ), "</image:loc>\n\t\t</image:image>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup, URL escaped.
		}
	}

	/**
	 * The SEO Framework builds its descriptions from the content: for XB pages, the rendered text.
	 *
	 * @param mixed $args Null in the main query, else ['id' => …] (or a term, a user…).
	 */
	public static function tsf_excerpt( mixed $excerpt, mixed $args = null ): string {
		$excerpt = is_string( $excerpt ) ? $excerpt : '';
		if ( '' !== trim( $excerpt ) ) {
			return $excerpt;
		}
		$id = self::tsf_post( $args );
		return null === $id ? $excerpt : self::page( $id )['text'];
	}

	/**
	 * The SEO Framework's social images: after the featured image, the images of the rendered XB page.
	 *
	 * @param mixed $params
	 * @return array<mixed>
	 */
	public static function tsf_images( mixed $params, mixed $args = null, mixed $context = 'social' ): array {
		$params = is_array( $params ) ? $params : array();
		if ( 'social' !== $context || null === self::tsf_post( $args ) ) {
			return $params;
		}
		$params['cbs']       = is_array( $params['cbs'] ?? null ) ? $params['cbs'] : array();
		$params['cbs']['xb'] = array( self::class, 'tsf_generator' );
		return $params;
	}

	/**
	 * @param mixed $args
	 * @return \Generator<array{url: string}>
	 */
	public static function tsf_generator( mixed $args = null, mixed $size = 'full' ): \Generator {
		$id = self::tsf_post( $args );
		foreach ( null === $id ? array() : array_slice( self::page( $id )['images'], 0, 5 ) as $image ) {
			yield array( 'url' => $image['src'] );
		}
	}

	/** The post The SEO Framework asks about: its arguments, or the singular page being viewed. */
	private static function tsf_post( mixed $args ): ?int {
		if ( is_array( $args ) ) {
			return ! empty( $args['id'] ) && empty( $args['tax'] ) && empty( $args['pta'] ) && empty( $args['uid'] ) ? (int) $args['id'] : null;
		}
		return is_singular() ? (int) get_queried_object_id() : null;
	}

	/** Yoast SEO and Rank Math analyse the rendered XB content while you edit (assets/editor/seo.js). */
	public static function editor_assets(): void {
		if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}
		$deps = array( 'wp-api-fetch', 'wp-data', 'wp-hooks', 'wp-i18n' );
		if ( defined( 'WPSEO_VERSION' ) ) {
			$deps[] = 'jquery';
		}
		wp_enqueue_script( 'xb-seo', plugin_dir_url( XB_FILE ) . 'assets/editor/seo.js', $deps, Plugin::asset_version( 'assets/editor/seo.js' ), array( 'in_footer' => true ) );
		\XB\I18n::script( 'xb-seo' );
	}
}
