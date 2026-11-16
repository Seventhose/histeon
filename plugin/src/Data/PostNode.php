<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * A post as seen by expressions (`this`, loop items). Exposes only public, display-oriented fields.
 * Construct through ::for_post(), which refuses posts the current visitor cannot read.
 */
final class PostNode implements Node, \Stringable {

	/** @var array<string, mixed> */
	private array $resolved = array();
	/** @var array<string, mixed>|null Fields added by plugins through `xb_post_data`. */
	private ?array $extra = null;

	/** @param PostBatch|null $batch The list this post was loaded with, if any (images and authors load together). */
	private function __construct( private readonly \WP_Post $post, private readonly ?PostBatch $batch = null ) {}

	public static function for_post( mixed $post, ?PostBatch $batch = null ): ?self {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( ! is_post_publicly_viewable( $post ) && ! self::allowed_listing( $post ) && ! current_user_can( 'read_post', $post->ID ) ) {
			return null;
		}
		return new self( $post, $batch );
	}

	/**
	 * Nodes for the posts a loop lists, sharing one PostBatch; posts the visitor cannot read are left out.
	 *
	 * @param array<mixed> $posts
	 * @return list<self>
	 */
	public static function for_posts( array $posts ): array {
		$posts = array_values( array_filter( $posts, static fn ( mixed $post ): bool => $post instanceof \WP_Post ) );
		$batch = new PostBatch( $posts );
		$nodes = array();
		foreach ( $posts as $post ) {
			$node = self::for_post( $post, $batch );
			if ( null !== $node ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	/** Published, unprotected posts of a type an administrator allowed in loops (LoopPresets::listable). */
	private static function allowed_listing( \WP_Post $post ): bool {
		return 'publish' === get_post_status( $post ) && '' === $post->post_password && \XB\Render\LoopPresets::listable( $post->post_type );
	}

	public function id(): int {
		return $this->post->ID;
	}

	public function get( string $key ): mixed {
		if ( ! array_key_exists( $key, $this->resolved ) ) {
			$this->resolved[ $key ] = $this->resolve( $key );
		}
		return $this->resolved[ $key ];
	}

	public function __toString(): string {
		return (string) $this->get( 'title' );
	}

	private function resolve( string $key ): mixed {
		$post = $this->post;
		switch ( $key ) {
			case 'id':
				return $post->ID;
			case 'title':
				return get_the_title( $post );
			case 'url':
				return get_permalink( $post ) ?: null;
			case 'slug':
				return $post->post_name;
			case 'type':
				return $post->post_type;
			case 'excerpt':
				// As WordPress does: a password-protected post shows nothing of its text until the password is given.
				if ( post_password_required( $post ) ) {
					return '';
				}
				if ( '' !== $post->post_excerpt ) {
					return $post->post_excerpt;
				}
				// Never runs the_content here: a post may be rendering itself.
				return wp_trim_words( wp_strip_all_tags( excerpt_remove_blocks( strip_shortcodes( $post->post_content ) ) ), 55 );
			case 'date':
			case 'publishedAt': // Histeon Publish's name.
				return get_post_time( DATE_W3C, false, $post );
			case 'modified':
			case 'updatedAt':
				return get_post_modified_time( DATE_W3C, false, $post );
			case 'author':
				$this->batch?->prime_authors();
				return UserNode::public_profile( (int) $post->post_author );
			case 'image':
				$this->batch?->prime_images();
				$thumbnail = get_post_thumbnail_id( $post );
				return $thumbnail ? ImageNode::for_attachment( $thumbnail ) : null;
			case 'categories':
				return TermNode::list_for_post( $post, 'category' );
			case 'tags':
				return TermNode::list_for_post( $post, 'post_tag' );
			case 'terms':
				return new TermsNode( $post );
			case 'meta':
				// Custom fields are part of what a password protects, as core's post-meta binding treats them.
				return post_password_required( $post ) ? null : new MetaNode( $post );
			case 'product':
				// WooCommerce or FluentCart, whichever owns this post type (docs/spec/ecommerce.md).
				return ProductNode::for_post( $post ) ?? FluentCartProductNode::for_post( $post );
			case 'fields':
				return post_password_required( $post ) ? null : new FieldsNode( $post );
			case 'acf':
			case 'jetengine':
			case 'metabox':
				return post_password_required( $post ) ? null : new FieldsNode( $post, $key );
			case 'parent':
				return $post->post_parent ? self::for_post( $post->post_parent ) : null;
			case 'commentCount':
				return (int) $post->comment_count;
			case 'readingTime':
				// Minutes to read, as imported pages expect them: words of the text at 200 a minute, rounded up. Part of the
				// text, so a password hides it like the excerpt.
				return post_password_required( $post ) ? 0 : (int) ceil( str_word_count( wp_strip_all_tags( $post->post_content ) ) / 200 );
			default:
				// Plugins add their own fields (docs/spec/import-etch.md §5): they never replace the built-in ones.
				$this->extra ??= (array) apply_filters( 'xb_post_data', array(), $post->ID );
				return $this->extra[ $key ] ?? null;
		}
	}
}
