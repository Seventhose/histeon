<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB;

/**
 * XB capabilities — threat model §5:
 * - xb_build:         use the builder, edit styles, components and loop presets;
 * - xb_publish:       publish and roll back releases;
 * - xb_manage_deploy: manage deploy targets and their credentials;
 * - xb_edit_content:  client mode, texts and pictures of the pages one may edit (docs/spec/client-mode.md);
 * - xb_edit_props:    in client mode, also the literal props of the component instances on those pages (list 135).
 *                     Off by default: no role receives it, the owner grants it with a role editor or WP-CLI.
 * Granted to administrators on activation; site owners can grant them to other roles with any role editor.
 * Each client's pages, levels and the page's own look are set per user in Histeon → Permessi dei clienti
 * (Security\ClientAccess, voce 186): with nothing set, a client has exactly the above.
 * The «Cliente Histeon» role has only client mode: it edits pages and posts (never publishes or deletes them), in
 * development, through the builder.
 */
final class Capabilities {

	public const ALL = array( 'xb_build', 'xb_publish', 'xb_manage_deploy', 'xb_edit_content' );
	public const CLIENT_ROLE = 'xb_client';
	/** What the client role may do: read, upload pictures, edit (not publish or delete) pages and posts. */
	public const CLIENT_CAPS = array( 'read', 'upload_files', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'xb_edit_content' );

	/** Client mode may also change component props: grant it to a role to allow it (list 135). */
	public const EDIT_PROPS = 'xb_edit_props';

	/** Client mode: may change texts and pictures in the builder, nothing else. */
	public static function content_only(): bool {
		return current_user_can( 'xb_edit_content' ) && ! current_user_can( 'xb_build' );
	}

	/**
	 * In client mode, whether the literal props of component instances may change too (list 135): from the role
	 * (`xb_edit_props`) or from the client's own permissions (voce 186, Security\ClientAccess).
	 */
	public static function content_props(): bool {
		return self::content_only() && Security\ClientAccess::props( get_current_user_id() );
	}

	public static function grant(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}
		foreach ( self::ALL as $capability ) {
			$role->add_cap( $capability );
		}
		if ( null === get_role( self::CLIENT_ROLE ) ) {
			add_role( self::CLIENT_ROLE, 'Cliente Histeon', array_fill_keys( self::CLIENT_CAPS, true ) );
		}
	}
}
