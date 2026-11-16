<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Templates\Templates;

/**
 * XB → Template: the active theme's templates and parts, each opened in the builder.
 * Templates that are still theme files are written to the database first (POST with nonce).
 */
final class TemplatesScreen {

	public const SLUG   = 'xb-templates';
	public const ACTION = 'xb_template_open';
	public const CREATE = 'xb_template_create';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'open' ) );
		add_action( 'admin_post_' . self::CREATE, array( self::class, 'create' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 81 );
	}

	public static function allowed(): bool {
		return current_user_can( 'edit_theme_options' ) && current_user_can( 'xb_build' );
	}

	/** XB → Template (registered by Menu, in the menu's order). */
	public static function menu(): void {
		if ( wp_is_block_theme() ) {
			add_submenu_page( Menu::SLUG, __( 'Template Histeon', 'xb' ), __( 'Template', 'xb' ), 'edit_theme_options', self::SLUG, array( self::class, 'render' ) );
		}
	}

	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Non hai i permessi per modificare i template con Histeon.', 'xb' ), 403 );
		}
		$all    = Templates::all();
		$groups = array();
		foreach ( $all as $template ) {
			$groups[ $template['group'] ]['label']   = $template['groupLabel'];
			$groups[ $template['group'] ]['items'][] = $template;
		}
		Menu::open(
			self::SLUG,
			__( 'Template', 'xb' ),
			/* translators: %s: theme name */
			sprintf( __( 'Template del tema attivo (%s), divisi per ciò che mostrano. Si modificano nel builder come le pagine, con un contenuto di esempio, e vanno online con le release.', 'xb' ), (string) wp_get_theme()->get( 'Name' ) )
		);
		self::render_create( $all );
		?>
		<nav class="xb-chips" aria-label="<?php esc_attr_e( 'Tipi di template', 'xb' ); ?>">
			<?php foreach ( $groups as $key => $group ) : ?>
				<a href="#xb-group-<?php echo esc_attr( sanitize_html_class( str_replace( ':', '-', $key ) ) ); ?>"><?php echo esc_html( $group['label'] ); ?> <span class="xb-count is-plain"><?php echo esc_html( (string) count( $group['items'] ) ); ?></span></a>
			<?php endforeach; ?>
		</nav>
		<?php
		foreach ( $groups as $key => $group ) :
			?>
			<h2 class="xb-section-title" id="xb-group-<?php echo esc_attr( sanitize_html_class( str_replace( ':', '-', $key ) ) ); ?>"><?php echo esc_html( $group['label'] ); ?> <small><?php echo esc_html( (string) count( $group['items'] ) ); ?></small></h2>
			<div class="xb-grid">
				<?php foreach ( $group['items'] as $template ) : ?>
					<div class="xb-card xb-template">
						<div class="xb-template-head">
							<span class="xb-stat-icon"><?php echo Menu::icon( 'wp_template_part' === $template['type'] ? 'align-wide' : 'layout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is fixed. ?></span>
							<div>
								<strong><?php echo esc_html( $template['title'] ); ?></strong>
								<div class="xb-muted"><code><?php echo esc_html( $template['slug'] ); ?></code></div>
							</div>
						</div>
						<?php if ( '' !== $template['description'] ) : ?>
							<p class="xb-muted xb-template-desc"><?php echo esc_html( $template['description'] ); ?></p>
						<?php endif; ?>
						<div class="xb-template-foot">
							<span class="xb-pill <?php echo $template['post'] ? 'is-live' : 'is-plain'; ?>"><?php echo esc_html( $template['post'] ? __( 'Personalizzato', 'xb' ) : __( 'File del tema', 'xb' ) ); ?></span>
							<?php if ( $template['post'] ) : ?>
								<a class="xb-btn xb-btn--small xb-btn--primary" href="<?php echo esc_url( BuilderScreen::url( $template['post'] ) ); ?>"><?php esc_html_e( 'Apri in Histeon', 'xb' ); ?></a>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
									<input type="hidden" name="type" value="<?php echo esc_attr( $template['type'] ); ?>">
									<input type="hidden" name="template" value="<?php echo esc_attr( $template['id'] ); ?>">
									<?php wp_nonce_field( self::ACTION ); ?>
									<button class="xb-btn xb-btn--small xb-btn--primary"><?php esc_html_e( 'Modifica con Histeon', 'xb' ); ?></button>
								</form>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		endforeach;
		if ( array() === $groups ) :
			?>
			<div class="xb-panel"><div class="xb-empty"><?php echo Menu::icon( 'layout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Il tema attivo non ha template', 'xb' ); ?></strong></div></div>
			<?php
		endif;
		Menu::close();
	}

	/**
	 * «Nuovo template»: type, technical name (suggested from the template hierarchy), title, area for parts, and
	 * where to start from.
	 *
	 * @param list<array{type: string, id: string, slug: string, title: string, source: string, post: int, group: string, groupLabel: string, groupOrder: int, description: string}> $all
	 */
	private static function render_create( array $all ): void {
		?>
		<details class="xb-panel xb-template-new">
			<summary class="xb-panel-head"><h2><?php echo Menu::icon( 'plus-alt2' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Nuovo template', 'xb' ); ?></h2></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-panel-body xb-form-grid">
				<?php wp_nonce_field( self::CREATE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE ); ?>">
				<label><?php esc_html_e( 'Tipo', 'xb' ); ?>
					<select name="type">
						<option value="wp_template"><?php esc_html_e( 'Template completo', 'xb' ); ?></option>
						<option value="wp_template_part"><?php esc_html_e( 'Parte di template', 'xb' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Nome tecnico', 'xb' ); ?>
					<input name="slug" required pattern="[a-z0-9][a-z0-9_\-]{0,99}" list="xb-template-slugs" placeholder="single-evento" aria-describedby="xb-template-slug-help">
				</label>
				<datalist id="xb-template-slugs">
					<?php foreach ( Templates::suggestions() as $slug ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( Templates::classify( 'wp_template', $slug )['hint'] ); ?></option>
					<?php endforeach; ?>
				</datalist>
				<label><?php esc_html_e( 'Titolo', 'xb' ); ?> <input name="title" maxlength="120" placeholder="<?php esc_attr_e( 'Evento singolo', 'xb' ); ?>"></label>
				<label><?php esc_html_e( 'Area (per le parti)', 'xb' ); ?>
					<select name="area">
						<option value="uncategorized"><?php esc_html_e( 'Generale', 'xb' ); ?></option>
						<option value="header"><?php esc_html_e( 'Intestazione', 'xb' ); ?></option>
						<option value="footer"><?php esc_html_e( 'Piè di pagina', 'xb' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Parti da', 'xb' ); ?>
					<select name="from">
						<option value=""><?php esc_html_e( 'Vuoto', 'xb' ); ?></option>
						<?php foreach ( $all as $template ) : ?>
							<option value="<?php echo esc_attr( $template['type'] . '|' . $template['id'] ); ?>"><?php echo esc_html( sprintf( /* translators: 1: template title, 2: slug */ __( 'Copia di %1$s (%2$s)', 'xb' ), $template['title'], $template['slug'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<p id="xb-template-slug-help" class="xb-muted xb-form-wide"><?php esc_html_e( 'Un nome della gerarchia di WordPress (page, single-evento, archive-evento, category, 404…) vale da solo dove previsto. Un altro nome crea un modello personalizzato, da assegnare a pagine e articoli dal loro editor.', 'xb' ); ?></p>
				<p class="xb-form-wide"><button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Crea e apri in Histeon', 'xb' ); ?></button></p>
			</form>
		</details>
		<?php
	}

	/** Creates a template (or part) in the database and opens it in the builder. */
	public static function create(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Non hai i permessi per modificare i template con Histeon.', 'xb' ), 403 );
		}
		check_admin_referer( self::CREATE );
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( (string) $_POST['type'] ) ) : '';
		$slug  = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		$area  = isset( $_POST['area'] ) ? sanitize_key( wp_unslash( (string) $_POST['area'] ) ) : 'uncategorized';
		$from  = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '';
		// «Parti da» names a template of the same type; another type starts empty.
		[ $from_type, $from_id ] = array_pad( explode( '|', $from, 2 ), 2, '' );
		try {
			$post_id = Templates::create( $type, $slug, $title, $area, $from_type === $type ? $from_id : '' );
		} catch ( \InvalidArgumentException $e ) {
			Menu::back( Menu::url( self::SLUG ), 'error', $e->getMessage() );
		}
		wp_safe_redirect( BuilderScreen::url( $post_id ) );
		exit;
	}

	/** Writes a theme-file template to the database (once) and opens it in the builder. */
	public static function open(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'Non hai i permessi per modificare i template con Histeon.', 'xb' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( (string) $_POST['type'] ) ) : '';
		$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['template'] ) ) : '';
		try {
			$post_id = Templates::materialize( $type, $template );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
		wp_safe_redirect( BuilderScreen::url( $post_id ) );
		exit;
	}

	/** On the front end: the template this page uses, when it is in the database. */
	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( is_admin() || ! self::allowed() ) {
			return;
		}
		$template = Templates::current();
		if ( null !== $template && current_user_can( 'edit_post', $template->ID ) ) {
			$bar->add_node(
				array(
					'id'    => 'xb-template',
					'title' => esc_html( sprintf( __( 'Template con Histeon: %s', 'xb' ), get_the_title( $template ) ) ),
					'href'  => BuilderScreen::url( $template->ID ),
				)
			);
		}
	}
}
