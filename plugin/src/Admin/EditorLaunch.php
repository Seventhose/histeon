<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Plugin;
use XB\Versioning\Documents;

/**
 * The way from Gutenberg to the builder: a button in the editor header that opens XB full screen over the
 * editor (assets/editor/launch.js). Closing it returns to Gutenberg, reloaded when a release changed the page.
 */
final class EditorLaunch {

	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/** The version online now, for the document panel ("v1.2.0"), or null before the first release. */
	private static function current_release(): ?string {
		$release = Plugin::instance()->versioning->store()->get_release( (int) get_option( \XB\Versioning\Versioning::OPTION_CURRENT, 0 ) );
		return null === $release ? null : $release['version'];
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		// A locked structure (list 138): whoever does not build edits only texts and pictures here, as the server holds.
		if ( $screen && 'post' === $screen->base && $post instanceof \WP_Post && \XB\Security\StructureLock::applies( $post->ID ) ) {
			wp_enqueue_script( 'wp-data' );
			wp_add_inline_script(
				'wp-data',
				'window.addEventListener("load",function(){var d=window.wp&&wp.data&&wp.data.dispatch("core/block-editor");if(d){d.updateSettings({templateLock:"contentOnly"});}});'
			);
		}
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || ! BuilderScreen::can_open( $post->ID ) ) {
			return;
		}
		$base = plugin_dir_url( XB_FILE ) . 'assets/editor/';
		wp_enqueue_style( 'xb-launch', $base . 'launch.css', array(), Plugin::asset_version( 'assets/editor/launch.css' ) );
		wp_enqueue_script( 'xb-launch', $base . 'launch.js', array( 'wp-api-fetch', 'wp-data', 'wp-dom-ready', 'wp-i18n', 'wp-notices' ), Plugin::asset_version( 'assets/editor/launch.js' ), array( 'in_footer' => true ) );
		\XB\I18n::script( 'xb-launch' );
		// The XB tabs of the block sidebar (Classi, HTML, CSS, JS), built from editor/ (npm run build -w editor).
		$inspector = dirname( XB_FILE ) . '/assets/gutenberg/inspector.js';
		if ( is_readable( $inspector ) ) {
			wp_enqueue_script(
				'xb-inspector',
				plugin_dir_url( XB_FILE ) . 'assets/gutenberg/inspector.js',
				array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-plugins', 'xb-blocks-editor' ),
				Plugin::asset_version( 'assets/gutenberg/inspector.js' ),
				array( 'in_footer' => true )
			);
			wp_add_inline_script(
				'xb-inspector',
				'window.xbInspector = ' . wp_json_encode(
					array(
						'restUrl'    => esc_url_raw( rest_url( \XB\Rest\Controller::NAMESPACE . '/' ) ),
						'nonce'      => wp_create_nonce( 'wp_rest' ),
						'postId'     => $post->ID,
						'builderUrl' => BuilderScreen::url( $post->ID ),
						'historyUrl' => Menu::url( HistoryScreen::SLUG, array( 'doc' => Documents::for_post( $post->ID ) ) ),
						'releasesUrl' => Menu::url( ReleasesScreen::SLUG ),
						'pending'    => in_array( Documents::for_post( $post->ID ), array_column( Plugin::instance()->versioning->changes(), 'doc' ), true ),
						'release'    => self::current_release(),
						// Client mode: the Histeon panels show, read-only (the server refuses the client's writes anyway).
						'client'     => \XB\Capabilities::content_only(),
						// Voce 186: the groups of the Aspetto panel that may write this page's own look ([]: none).
						'pageLook'   => \XB\Security\ClientAccess::groups( $post->ID ),
						'strings'    => (object) \XB\I18n::editor_strings(),
					),
					JSON_HEX_TAG | JSON_HEX_AMP
				) . ';',
				'before'
			);
		}
		$doc = Documents::for_post( $post->ID );
		wp_add_inline_script(
			'xb-launch',
			'window.xbLaunch = ' . wp_json_encode(
				array(
					'url'     => BuilderScreen::url( $post->ID, true ),
					'route'   => rest_get_route_for_post( $post ),
					'doc'     => $doc,
					'pending' => in_array( $doc, array_column( Plugin::instance()->versioning->changes(), 'doc' ), true ),
				),
				JSON_HEX_TAG | JSON_HEX_AMP
			) . ';',
			'before'
		);
	}
}
