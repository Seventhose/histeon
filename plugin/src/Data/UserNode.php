<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Data;

/**
 * `user` (the visitor) and `post.author` (a public profile). Never exposes email or login; `role` only for the
 * visitor themselves.
 * In a static export the visitor is always anonymous.
 */
final class UserNode implements Node, \Stringable {

	private function __construct( private readonly ?\WP_User $user, private readonly bool $is_visitor, private readonly string $role = '' ) {}

	public static function visitor(): self {
		// A preview seen as a role (lista della spesa 161): a made-up visitor with that role, never an account.
		$as = \XB\Versioning\Preview::seen_as();
		if ( 'guest' === $as ) {
			return new self( null, true );
		}
		if ( '' !== $as ) {
			$user               = new \WP_User();
			$user->display_name = __( 'Visitatore di prova', 'xb' );
			return new self( $user, true, $as );
		}
		$user = wp_get_current_user();
		return new self( $user->exists() ? $user : null, true );
	}

	public static function public_profile( int $user_id ): ?self {
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User ? new self( $user, false ) : null;
	}

	/**
	 * The public profile of a user named by a field or a filter, only when they are an author of the site, as the
	 * users loop (LoopPresets::users): a user field must not print the name of a customer or a subscriber.
	 */
	public static function author_profile( int $user_id ): ?self {
		$types = array_values( array_filter( get_post_types( array( 'public' => true ) ), array( \XB\Render\LoopPresets::class, 'listable' ) ) );
		return array() !== $types && (int) count_user_posts( $user_id, $types, true ) > 0 ? self::public_profile( $user_id ) : null;
	}

	public function get( string $key ): mixed {
		if ( 'loggedIn' === $key ) {
			return null !== $this->user && $this->is_visitor;
		}
		if ( null === $this->user ) {
			return null;
		}
		if ( 'role' === $key ) {
			// The visitor's own role, for conditions such as `user.role == "customer"`; never another person's.
			return $this->is_visitor ? ( '' !== $this->role ? $this->role : ( $this->user->roles[0] ?? null ) ) : null;
		}
		return match ( $key ) {
			'name' => $this->user->display_name,
			'url' => $this->is_visitor ? null : get_author_posts_url( $this->user->ID ),
			'avatar' => get_avatar_url( $this->user->ID ) ?: null,
			'bio' => $this->is_visitor ? null : get_the_author_meta( 'description', $this->user->ID ),
			'id' => $this->is_visitor ? null : $this->user->ID,
			// Published, public content only (the count author pages imply).
			'postCount' => $this->is_visitor ? null : (int) count_user_posts( $this->user->ID, array_values( get_post_types( array( 'public' => true ) ) ), true ),
			// Fields of a public profile (an author); never of the visitor, whose page may be cached or static.
			'fields' => $this->is_visitor ? null : new FieldsNode( $this->user ),
			'acf', 'jetengine', 'metabox' => $this->is_visitor ? null : new FieldsNode( $this->user, $key ),
			default => null,
		};
	}

	public function __toString(): string {
		return null === $this->user ? '' : $this->user->display_name;
	}
}
