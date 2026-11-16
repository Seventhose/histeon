<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB;

use XB\Expr\Evaluator;
use XB\Expr\Modifiers;
use XB\Framework\Frameworks;
use XB\Render\ComponentRegistry;
use XB\Render\Context;
use XB\Render\LoopPresets;
use XB\Render\Renderer;
use XB\Scripts\Scripts;
use XB\Security\ContentSanitizer;
use XB\Security\MarkupPolicy;
use XB\Styles\StyleCollector;
use XB\Styles\StyleRegistry;
use XB\Versioning\Preview;
use XB\Versioning\Schema;
use XB\Versioning\Store;
use XB\Versioning\Versioning;

/** Wires XB into WordPress. Everything WordPress-specific about the runtime starts here. */
final class Plugin {

	private static ?self $instance = null;

	public readonly Renderer $renderer;
	public readonly StyleCollector $styles;
	public readonly ComponentRegistry $components;
	public readonly ContentSanitizer $sanitizer;
	public readonly Scripts $scripts;
	public readonly Frameworks $frameworks;
	public readonly Versioning $versioning;
	public readonly Modifiers $modifiers;

	private function __construct() {
		$this->modifiers  = new Modifiers( static fn (): array => (array) apply_filters( 'xb_modifiers', array() ) );
		$this->styles     = new StyleCollector( new StyleRegistry() );
		$this->components = new ComponentRegistry();
		$this->sanitizer  = new ContentSanitizer( new MarkupPolicy( array_values( wp_allowed_protocols() ) ) );
		$this->scripts    = new Scripts();
		$this->frameworks = new Frameworks();
		$this->versioning = new Versioning( new Store() );
		$this->renderer   = new Renderer(
			new Evaluator( $this->modifiers ),
			$this->components,
			new LoopPresets(),
			$this->styles,
			defined( 'WP_DEBUG' ) && WP_DEBUG,
			$this->scripts
		);
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function boot(): void {
		I18n::register();
		add_action( 'init', array( self::class, 'on_init' ) );
		add_action( 'init', array( Content\ContentTypes::class, 'maybe_flush' ), 99 );
		add_action( \XB\Versioning\Integrity::UPGRADE, array( \XB\Versioning\Integrity::class, 'upgrade_step' ) );
		add_filter( 'wp_insert_post_data', array( self::class, 'on_insert_post_data' ), 20, 2 );
		Styles\PageStyles::boot();
		Styles\StyleRegistry::register();
		add_action( 'wp_footer', static fn () => self::instance()->scripts->print_footer(), 20 );
		add_filter( 'block_editor_settings_all', array( self::class, 'on_block_editor_settings' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'maybe_upgrade' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'on_enqueue_block_assets' ) );
		add_action( 'rest_api_init', array( Rest\Controller::class, 'register' ) );
		add_action( 'rest_api_init', array( Rest\DeployController::class, 'register' ) );
		add_action( 'rest_api_init', array( Forms\Forms::class, 'routes' ) );
		add_action( 'rest_api_init', array( Remote\Receiver::class, 'register' ) );
		add_action( 'rest_api_init', array( Cms\Bridge::class, 'register' ) );
		add_action( 'wp_trash_post', array( Remote\PageProfileAdapter::class, 'record_profile_trash' ), 10, 1 );
		add_action( 'untrash_post', array( Remote\PageProfileAdapter::class, 'clear_profile_tombstone_on_restore' ), 10, 1 );
		add_filter( 'register_taxonomy_args', array( Remote\ContentProfileAdapter::class, 'native_taxonomy_labels' ), 10, 2 );
		Render\ComponentUsage::register();
		Templates\ThemeCarry::register();
		add_filter( 'pre_trash_post', array( Remote\PageProfileAdapter::class, 'protect_linked_content_before_trash' ), 10, 3 );
		add_filter( 'pre_delete_post', array( Remote\PageProfileAdapter::class, 'protect_linked_content_before_permanent_delete' ), 10, 3 );
		// Older Histeon CMS imports get the marker of transferred content once, outside any render (transfer-markup §5).
		add_action( 'admin_init', array( Remote\PageProfileAdapter::class, 'migrate_imported_markers' ), 20, 0 );
		add_action( 'rest_api_init', array( Library\Rest::class, 'register' ) );
		add_action( 'rest_api_init', array( Media\Usage::class, 'register' ) );
		add_action( 'rest_api_init', array( Fonts\Rest::class, 'register' ) );
		add_action( 'rest_api_init', array( Import\ChoicesRest::class, 'register' ) );
		add_action( 'rest_api_init', array( Import\AddPageRest::class, 'register' ) );
		add_action( 'rest_api_init', array( Media\Video::class, 'register' ) );
		add_action( 'rest_api_init', array( Integrations\FluentForms::class, 'register' ) );
		StaticSite\ExportMode::boot();
		Ai\Abilities::register();
		Ai\Scopes::register();
		Ai\Contents::register();
		Media\Compress::register();
		Render\RemoteSource::register();
		Cli::register();
		add_action( 'xb_release_published', array( self::class, 'on_release_published' ) );
		add_action( 'xb_release_published', array( Remote\PageProfileAdapter::class, 'apply_pending_fields' ), 15, 2 );
		add_action( 'xb_release_published', array( Remote\PageProfileAdapter::class, 'apply_pending_media' ), 11, 2 );
		add_filter( 'xb_release_waiting_media', array( Remote\PageProfileAdapter::class, 'pending_media_count' ) );
		add_filter( 'xb_release_waiting_contents', array( Remote\ContentProfileAdapter::class, 'queued_count' ) );
		add_action( 'xb_release_published', array( Remote\ContentProfileAdapter::class, 'apply_queue' ), 12, 2 );
		add_action( 'xb_release_published', array( Cms\Bridge::class, 'apply_waiting_language_links' ), 20, 0 );
		add_action( 'xb_static_deploy', array( self::class, 'run_scheduled_deploy' ) );
		\XB\Versioning\Scheduled::register();
		add_action( 'transition_post_status', array( Deploy\Deployer::class, 'note_withdrawal' ), 10, 3 );
		add_action( 'xb_forms_cleanup', array( Forms\Forms::class, 'cleanup' ) );
		// The plugin's daily cleanup: also the records of imports from Histeon Publish past their retention (153).
		add_action( 'xb_forms_cleanup', array( Remote\PageProfileAdapter::class, 'prune_operations' ), 20, 0 );
		add_filter( 'wp_privacy_personal_data_exporters', array( Forms\Forms::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Forms\Forms::class, 'register_eraser' ) );
		Security\ClientGuard::register();
		Security\StructureLock::register();
		// Voce 186: each client's pages and permissions, and a page's own look.
		Security\ClientAccess::register();
		Styles\PageLook::register();
		Admin\BuilderScreen::register();
		Admin\EditorLaunch::register();
		Content\EditorFields::register();
		Content\ObjectFieldForms::register();
		Admin\SettingsScreen::register();
		Admin\SetupScreen::register();
		Updates\Updater::register();
		Updates\Backup::register();
		Admin\BackupPanel::register();
		Admin\Menu::register();
		Seo\Seo::register();
		StaticSite\StaticBridge::register();
		Review\Comments::boot();
		Fonts\LocalFonts::register();
		Framework\AcssToCore::register();
		add_action( 'init', array( Modules\Registry::class, 'boot' ), 1 );
		Admin\ModulesScreen::register();
		Admin\ModuleMenus::register();
		add_action( 'admin_notices', array( Modules\FatalGuard::class, 'notice' ) );
		add_action( 'admin_notices', array( \XB\Versioning\Signing::class, 'notice' ) );
		Framework\AcssLegacy::register();
		Admin\LegacyReminder::register();
		Import\Etch\Runtime::register();
		Admin\TemplatesScreen::register();
		add_action( 'wp_enqueue_scripts', array( Framework\Reset::class, 'enqueue' ), 1 );
		add_action( 'wp_enqueue_scripts', static fn () => self::instance()->frameworks->enqueue(), 20 );
		Render\WpBaseStyles::register();
		add_action( 'wp_after_insert_post', array( self::class, 'on_after_insert_post' ), 20, 2 );
		Preview::boot();
	}

	/** Plugin version plus the file's date: an updated file is never served from a stale cache. */
	public static function asset_version( string $relative ): string {
		$file = dirname( XB_FILE ) . '/' . $relative;
		return XB_VERSION . ( is_readable( $file ) ? '-' . (string) filemtime( $file ) : '' );
	}

	public static function on_init(): void {
		Schema::maybe_install();
		\XB\Versioning\Integrity::maybe_schedule_upgrade();
		ComponentRegistry::register_meta();
		ComponentRegistry::watch();
		Scripts::register_meta();
		\XB\Scripts\SiteScripts::register();
		Content\ContentTypes::register();
		Content\ArticleBlocks::register();
		Remote\ContentProfileAdapter::boot();
		Cms\FrontPage::boot();
		Media\Collections::register();
		Forms\Forms::register();
		Forms\Forms::schedule_cleanup();
		$base = plugin_dir_url( XB_FILE );
		wp_register_script(
			'xb-blocks-editor',
			$base . 'assets/editor/blocks.js',
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
			self::asset_version( 'assets/editor/blocks.js' ),
			array( 'in_footer' => true )
		);
		I18n::script( 'xb-blocks-editor' );
		wp_register_style( 'xb-blocks-editor', $base . 'assets/editor/blocks.css', array(), self::asset_version( 'assets/editor/blocks.css' ) );

		foreach ( glob( dirname( XB_FILE ) . '/blocks/*/block.json' ) ?: array() as $metadata ) {
			register_block_type(
				dirname( $metadata ),
				array(
					'render_callback'   => array( self::class, 'render_callback' ),
					'skip_inner_blocks' => true,
				)
			);
		}
	}

	/** @param array<string, mixed> $attributes */
	public static function render_callback( array $attributes, string $content, \WP_Block $block ): string {
		// In a signed preview the elements carry their uid, so a reviewer's comment points to one (docs/spec/review.md).
		return self::instance()->renderer->render_block( $block->parsed_block, Context::from_globals( Preview::active() ) );
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $postarr
	 * @return array<string, mixed>
	 */
	public static function on_insert_post_data( array $data, array $postarr ): array {
		return self::instance()->sanitizer->filter_post_data( $data, $postarr );
	}

	/**
	 * In Gutenberg, pages built with XB are content-only for people who cannot use the builder:
	 * they edit texts, links and images; structure and styles stay protected.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function on_block_editor_settings( array $settings, \WP_Block_Editor_Context $context ): array {
		if ( $context->post instanceof \WP_Post && Spec::has_xb_blocks( $context->post->post_content ) && ! current_user_can( 'xb_build' ) ) {
			$settings['templateLock'] = 'contentOnly';
		}
		return $settings;
	}

	/**
	 * The site's own CSS inside the block editor canvas (an iframe: only block assets reach it), so XB content
	 * looks there as it does on the site: reset, framework (whole stylesheets: the editor cannot know which
	 * classes a page will use), aliases, global sheets and every style entry.
	 */
	public static function on_enqueue_block_assets(): void {
		if ( ! is_admin() ) {
			return;
		}
		Framework\Reset::enqueue();
		$frameworks = self::instance()->frameworks;
		$frameworks->enqueue();
		// Histeon CSS is already whole in the queue (Frameworks::enqueue: its tokens, the site's values right after them,
		// its classes). A second copy of its sheets came after the site's values and put the defaults back in the
		// canvas: system fonts instead of the site's, so Gutenberg did not look like the site.
		$stylesheets = 'xb-tokens' === $frameworks->active()->id() ? array() : $frameworks->active()->stylesheets();
		foreach ( $stylesheets as $n => $url ) {
			if ( in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
				wp_enqueue_style( 'xb-framework-editor-' . (int) $n, $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the provider versions its URLs.
			}
		}
		wp_register_style( 'xb-styles-editor', false, array(), XB_VERSION );
		wp_add_inline_style( 'xb-styles-editor', Fonts\LocalFonts::css() . self::instance()->styles->all_css() );
		wp_enqueue_style( 'xb-styles-editor' );
	}

	/** Content edited outside the builder (Gutenberg, REST, WP-CLI) becomes a prod commit. */
	public static function on_after_insert_post( int $post_id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, Rest\Controller::DOCUMENT_TYPES, true ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::instance()->versioning->record_live_edit( $post_id );
		Content\ArticleBlocks::store_used( $post->post_content );
	}

	/** With automatic deploy on, a release goes to Cloudflare in the background (never slowing the publish). */
	public static function on_release_published( int $release_id ): void {
		if ( get_option( Deploy\Deployer::AUTO, false ) && Deploy\Credentials::ready() ) {
			wp_schedule_single_event( time(), 'xb_static_deploy', array( $release_id ) );
		}
	}

	public static function run_scheduled_deploy( int $release_id ): void {
		// A newer release supersedes this event: its own event will deploy, never an older state.
		if ( (int) get_option( Versioning::OPTION_CURRENT, 0 ) !== $release_id ) {
			return;
		}
		try {
			( new Deploy\Deployer() )->deploy( 'Histeon release #' . $release_id, true );
		} catch ( \Throwable $e ) {
			self::instance()->versioning->store()->audit( 'deploy_failed', 'release:' . $release_id, array( 'error' => $e->getMessage() ) );
		}
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'xb_version' ) !== XB_VERSION ) {
			Capabilities::grant();
			update_option( 'xb_version', XB_VERSION, false );
		}
	}
}
