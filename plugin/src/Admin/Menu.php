<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Versioning\Documents;

/**
 * The XB menu in wp-admin: overview, content, components, templates, releases, versions and settings.
 * Every screen checks its own capability again when it renders and when it acts (forms with nonces).
 */
final class Menu {

	public const SLUG = 'xb';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 81 );
		OverviewScreen::register();
		VersionsRepair::register();
		ContentScreen::register();
		ReleasesScreen::register();
		ProposalsSection::register();
		HistoryScreen::register();
		AuditScreen::register();
		ClientAccessScreen::register();
	}

	/** The Histeon mark for WordPress's menu (monochrome: WordPress recolours it to the admin colour scheme). */
	private static function menu_icon(): string {
		$svg = (string) file_get_contents( dirname( XB_FILE ) . '/assets/brand/histos-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file of this plugin.
		return '' === $svg ? 'dashicons-layout' : 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress's own format for menu icons.
	}

	public static function menu(): void {
		$pending = current_user_can( 'xb_build' ) ? self::pending() : 0;
		$bubble  = $pending > 0 ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $pending ) : '';
		add_menu_page( 'Histeon', 'Histeon' . $bubble, 'xb_build', self::SLUG, array( OverviewScreen::class, 'render' ), self::menu_icon(), 3 );
		add_submenu_page( self::SLUG, __( 'Histeon — Panoramica', 'xb' ), __( 'Panoramica', 'xb' ), 'xb_build', self::SLUG, array( OverviewScreen::class, 'render' ) );
		ContentScreen::menu();
		TemplatesScreen::menu();
		ReleasesScreen::menu( $bubble );
		HistoryScreen::menu();
		AuditScreen::menu();
		ClientAccessScreen::menu();
		ModulesScreen::menu();
		SettingsScreen::menu();
		SetupScreen::menu();
	}

	private static ?int $pending = null;

	/** Documents with changes in development that this user may see (once per request). */
	public static function pending(): int {
		return self::$pending ??= count( \XB\Rest\Controller::visible_changes() );
	}

	/**
	 * Our screens only: the toplevel page and its submenus. WordPress names a submenu's hook after the menu's
	 * title (`histos_page_xb-pages`), so the page slug is what is compared, never the title.
	 */
	public static function is_own_screen( string $hook ): bool {
		return 'toplevel_page_' . self::SLUG === $hook || 1 === preg_match( '/_page_' . preg_quote( self::SLUG, '/' ) . '(-[a-z0-9-]+)?$/', $hook );
	}

	public static function assets( string $hook ): void {
		if ( ! self::is_own_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'xb-admin', plugin_dir_url( XB_FILE ) . 'assets/admin/admin.css', array( 'dashicons' ), \XB\Plugin::asset_version( 'assets/admin/admin.css' ) );
	}

	/** The XB menu in the toolbar: the builder for this page, its versions, what waits to be published. */
	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'xb_build' ) ) {
			return;
		}
		$pending = self::pending();
		$bar->add_node(
			array(
				'id'    => 'xb-menu',
				'title' => 'Histeon' . ( $pending > 0 ? ' <span class="ab-label" style="background:#f0b849;color:#1e1e1e;border-radius:9px;padding:0 6px;margin-left:4px;font-size:11px">' . (int) $pending . '</span>' : '' ),
				'href'  => self::url( self::SLUG ),
				'meta'  => array( 'title' => __( 'Histeon', 'xb' ) ),
			)
		);
		$post = is_admin() ? get_post() : ( is_singular() ? get_queried_object() : null );
		if ( $post instanceof \WP_Post && BuilderScreen::can_open( $post->ID ) ) {
			$bar->add_node( array( 'parent' => 'xb-menu', 'id' => 'xb-menu-builder', 'title' => esc_html__( 'Modifica questa pagina con Histeon', 'xb' ), 'href' => BuilderScreen::url( $post->ID ) ) );
			$bar->add_node( array( 'parent' => 'xb-menu', 'id' => 'xb-menu-versions', 'title' => esc_html__( 'Versioni di questa pagina', 'xb' ), 'href' => self::url( HistoryScreen::SLUG, array( 'doc' => Documents::for_post( $post->ID ) ) ) ) );
		}
		$bar->add_node( array( 'parent' => 'xb-menu', 'id' => 'xb-menu-overview', 'title' => esc_html__( 'Panoramica', 'xb' ), 'href' => self::url( self::SLUG ) ) );
		/* translators: %d: documents waiting to be published */
		$bar->add_node( array( 'parent' => 'xb-menu', 'id' => 'xb-menu-releases', 'title' => esc_html( $pending > 0 ? sprintf( _n( 'Release · %d in attesa', 'Release · %d in attesa', $pending, 'xb' ), $pending ) : __( 'Release', 'xb' ) ), 'href' => self::url( ReleasesScreen::SLUG ) ) );
		$bar->add_node( array( 'parent' => 'xb-menu', 'id' => 'xb-menu-content', 'title' => esc_html__( 'Pagine', 'xb' ), 'href' => self::url( ContentScreen::PAGES ) ) );
	}

	/**
	 * Opens an XB screen: the header with logo, title and actions, the section tabs, then the body.
	 *
	 * @param callable|null $actions Prints the header buttons.
	 */
	public static function open( string $current, string $title, string $subtitle = '', ?callable $actions = null ): void {
		$tabs = array(
			self::SLUG                  => array( 'dashicons-dashboard', __( 'Panoramica', 'xb' ), 'xb_build' ),
			ContentScreen::PAGES        => array( 'dashicons-admin-page', __( 'Pagine', 'xb' ), 'xb_build' ),
			ContentScreen::POSTS        => array( 'dashicons-admin-post', __( 'Articoli', 'xb' ), 'xb_build' ),
			ContentScreen::COMPONENTS   => array( 'dashicons-screenoptions', __( 'Componenti', 'xb' ), 'xb_build' ),
			TemplatesScreen::SLUG       => array( 'dashicons-layout', __( 'Template', 'xb' ), 'edit_theme_options' ),
			ReleasesScreen::SLUG        => array( 'dashicons-cloud-upload', __( 'Release', 'xb' ), 'xb_build' ),
			HistoryScreen::SLUG         => array( 'dashicons-backup', __( 'Versioni', 'xb' ), 'xb_build' ),
			AuditScreen::SLUG           => array( 'dashicons-list-view', __( 'Registro', 'xb' ), 'manage_options' ),
			ClientAccessScreen::SLUG    => array( 'dashicons-groups', __( 'Clienti', 'xb' ), 'xb_build' ),
			ModulesScreen::SLUG         => array( 'dashicons-admin-plugins', __( 'Moduli', 'xb' ), 'manage_options' ),
			SettingsScreen::SLUG        => array( 'dashicons-admin-settings', __( 'Impostazioni', 'xb' ), 'manage_options' ),
		);
		if ( ! wp_is_block_theme() ) {
			unset( $tabs[ TemplatesScreen::SLUG ] );
		}
		$pending = self::pending();
		?>
		<div class="wrap xb-admin">
			<div class="xb-head">
				<div class="xb-head-row">
					<img class="xb-logo" src="<?php echo esc_url( plugins_url( 'assets/brand/histos-mark.svg', XB_FILE ) ); ?>" alt="" width="38" height="38">
					<div>
						<h1><?php echo esc_html( $title ); ?></h1>
						<?php if ( '' !== $subtitle ) : ?>
							<p class="xb-sub"><?php echo esc_html( $subtitle ); ?></p>
						<?php endif; ?>
					</div>
					<?php if ( null !== $actions ) : ?>
						<div class="xb-head-actions"><?php $actions(); ?></div>
					<?php endif; ?>
				</div>
				<nav class="xb-tabs" aria-label="<?php esc_attr_e( 'Sezioni di Histeon', 'xb' ); ?>">
					<?php foreach ( $tabs as $slug => [ $icon, $label, $cap ] ) : ?>
						<?php if ( current_user_can( $cap ) && current_user_can( 'xb_build' ) ) : ?>
							<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="<?php echo $slug === $current ? 'is-active' : ''; ?>" <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>>
								<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
								<?php echo esc_html( $label ); ?>
								<?php if ( ReleasesScreen::SLUG === $slug && $pending > 0 ) : ?>
									<span class="xb-count" title="<?php esc_attr_e( 'Documenti in attesa di pubblicazione', 'xb' ); ?>"><?php echo esc_html( (string) $pending ); ?></span>
								<?php endif; ?>
							</a>
						<?php endif; ?>
					<?php endforeach; ?>
				</nav>
			</div>
			<div class="xb-body">
				<hr class="wp-header-end" style="display:none">
				<?php self::notice(); ?>
		<?php
	}

	public static function close(): void {
		echo '</div></div>';
	}

	/** A dashicon, decorative. */
	public static function icon( string $name ): string {
		return '<span class="dashicons dashicons-' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}

	/** @param array<string, string> $args */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/** Who may see a document's versions: the same rules as the builder and the REST API. */
	public static function can_see( string $doc ): bool {
		if ( ! current_user_can( 'xb_build' ) || ! Documents::valid( $doc ) ) {
			return false;
		}
		if ( Documents::SETTINGS === $doc ) {
			return current_user_can( 'manage_options' );
		}
		$id = Documents::post_id( $doc );
		return null === $id || current_user_can( 'edit_post', $id );
	}

	/** A short label for a document, with its kind. */
	public static function describe( string $doc ): string {
		$id = Documents::post_id( $doc );
		if ( null === $id ) {
			return Documents::label( $doc );
		}
		$type  = get_post_type( $id );
		$kinds = array(
			'page'             => __( 'Pagina', 'xb' ),
			'post'             => __( 'Articolo', 'xb' ),
			'wp_block'         => __( 'Componente', 'xb' ),
			'wp_template'      => __( 'Template', 'xb' ),
			'wp_template_part' => __( 'Parte di template', 'xb' ),
		);
		$title = Documents::label( $doc );
		return ( $kinds[ (string) $type ] ?? (string) $type ) . ': ' . ( '' !== $title ? $title : __( '(senza titolo)', 'xb' ) );
	}

	public static function user_name( int $id ): string {
		$user = get_userdata( $id );
		return $user instanceof \WP_User ? $user->display_name : ( 0 === $id ? __( 'sistema', 'xb' ) : '#' . $id );
	}

	public static function date( string $gmt ): string {
		return get_date_from_gmt( $gmt, (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
	}

	/** Result of the last action, carried in the redirect as a short code (never as free text). */
	public static function notice(): void {
		$codes = array(
			'published' => array( 'success', __( 'Release pubblicata.', 'xb' ) ),
			'restored'  => array( 'success', __( 'Release ripristinata: è online come nuova release.', 'xb' ) ),
			'reverted'  => array( 'success', __( 'Versione ripristinata in sviluppo: va online con la prossima release.', 'xb' ) ),
			'imported'  => array( 'success', __( 'Release importata in sviluppo: controllala nel builder e pubblicala.', 'xb' ) ),
			'error'     => array( 'error', __( 'Operazione non riuscita.', 'xb' ) ),
			// A success described by the action itself (the message travels like an error's detail).
			'done'      => array( 'success', '' ),
		);
		$code = isset( $_GET['xb-done'] ) ? sanitize_key( wp_unslash( $_GET['xb-done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $codes[ $code ] ) ) {
			$message = $codes[ $code ][1];
			$detail  = get_transient( 'xb_admin_error_' . get_current_user_id() );
			if ( in_array( $code, array( 'error', 'done' ), true ) && is_string( $detail ) && '' !== $detail ) {
				$message = trim( $message . ' ' . $detail );
				delete_transient( 'xb_admin_error_' . get_current_user_id() );
			}
			printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $codes[ $code ][0] ), esc_html( $message ) );
		}
	}

	/** Redirects back after an action; a message (an error's detail, or a 'done' text) is kept server side for the next page view. */
	public static function back( string $url, string $code, string $error = '' ): never {
		if ( '' !== $error ) {
			set_transient( 'xb_admin_error_' . get_current_user_id(), mb_substr( $error, 0, 500 ), 120 );
		}
		wp_safe_redirect( add_query_arg( 'xb-done', $code, $url ) );
		exit;
	}
}
