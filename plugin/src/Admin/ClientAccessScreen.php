<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Security\ClientAccess;
use XB\Styles\PageLook;

/**
 * Histeon → Permessi dei clienti (voce 186 of doc 23 of Publish; docs/spec/client-mode.md): for each client, what
 * he may change (texts and pictures always; component props; the page's own look, in the groups chosen) and on
 * which pages, with a level for each. For who builds and for administrators; a client never reaches it.
 *
 * The form posts to admin-post.php with a nonce bound to the client; ClientAccess::update() checks the capability
 * and the target again and validates every value against closed lists before storing anything.
 */
final class ClientAccessScreen {

	public const SLUG   = 'xb-clients';
	private const SAVE  = 'xb_client_access_save';
	/** Pages and posts listed for one client (ClientAccess::MAX_PAGES is the most a list may hold). */
	private const PAGES = 300;

	public static function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( self::class, 'save' ) );
		add_filter( 'user_row_actions', array( self::class, 'row_action' ), 10, 2 );
		add_action( 'edit_user_profile', array( self::class, 'profile' ) );
	}

	public static function menu(): void {
		add_submenu_page( Menu::SLUG, __( 'Permessi dei clienti', 'xb' ), __( 'Permessi dei clienti', 'xb' ), 'xb_build', self::SLUG, array( self::class, 'render' ) );
	}

	private static function can_see(): bool {
		return current_user_can( 'xb_build' ) || current_user_can( 'manage_options' );
	}

	/** @return array<string, string> Group of the Aspetto panel → its name. */
	public static function group_labels(): array {
		return array(
			'layout'   => __( 'Impaginazione', 'xb' ),
			'space'    => __( 'Spazi', 'xb' ),
			'size'     => __( 'Dimensioni', 'xb' ),
			'text'     => __( 'Testo', 'xb' ),
			'look'     => __( 'Colori e bordi', 'xb' ),
			'position' => __( 'Posizione', 'xb' ),
		);
	}

	/** @return array<string, string> Level → its name. */
	public static function level_labels(): array {
		return array(
			'none'    => __( 'Nessuno', 'xb' ),
			'view'    => __( 'Solo vedere nel builder', 'xb' ),
			'content' => __( 'Testi e immagini', 'xb' ),
			'look'    => __( 'Anche aspetto', 'xb' ),
		);
	}

	public static function url( int $user = 0 ): string {
		return Menu::url( self::SLUG, $user > 0 ? array( 'user' => (string) $user ) : array() );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function row_action( array $actions, \WP_User $user ): array {
		if ( ClientAccess::can_manage( $user->ID ) ) {
			$actions['xb_client_access'] = sprintf( '<a href="%s">%s</a>', esc_url( self::url( $user->ID ) ), esc_html__( 'Permessi Histeon', 'xb' ) );
		}
		return $actions;
	}

	public static function profile( \WP_User $user ): void {
		if ( ! ClientAccess::can_manage( $user->ID ) ) {
			return;
		}
		printf(
			'<h2>Histeon</h2><p>%s <a href="%s">%s</a></p>',
			esc_html__( 'Questo utente lavora in modalità cliente.', 'xb' ),
			esc_url( self::url( $user->ID ) ),
			esc_html__( 'Scegli che cosa può modificare e su quali pagine', 'xb' )
		);
	}

	/** @return list<\WP_User> The site's clients (client mode and nothing more). */
	private static function clients(): array {
		$users = get_users(
			array(
				'capability' => 'xb_edit_content',
				'number'     => 500,
				'orderby'    => 'display_name',
			)
		);
		return array_values( array_filter( $users, static fn ( \WP_User $u ): bool => ClientAccess::is_client( $u->ID ) ) );
	}

	/** In words, what a client may do now. */
	private static function summary( int $user_id ): string {
		$s     = ClientAccess::settings( $user_id );
		$parts = array( __( 'testi e immagini', 'xb' ) );
		if ( ClientAccess::props( $user_id ) ) {
			$parts[] = __( 'props dei componenti', 'xb' );
		}
		if ( $s['look'] && array() !== $s['groups'] ) {
			$parts[] = __( 'aspetto della pagina', 'xb' );
		}
		$where = 'all' === $s['pages']
			? __( 'tutte le pagine che può modificare', 'xb' )
			/* translators: %d: number of pages */
			: sprintf( _n( '%d pagina scelta', '%d pagine scelte', count( $s['levels'] ), 'xb' ), count( $s['levels'] ) );
		return implode( ', ', $parts ) . ' · ' . $where;
	}

	public static function render(): void {
		if ( ! self::can_see() ) {
			wp_die( esc_html__( 'Non hai i permessi per usare Histeon.', 'xb' ), 403 );
		}
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view; the save has its nonce.
		if ( $user_id > 0 ) {
			if ( ! ClientAccess::can_manage( $user_id ) ) {
				wp_die( esc_html__( 'Non puoi cambiare i permessi di questo utente.', 'xb' ), 403 );
			}
			self::form( $user_id );
			return;
		}
		Menu::open( self::SLUG, __( 'Permessi dei clienti', 'xb' ), __( 'Per ogni cliente: che cosa può modificare e su quali pagine. Di serie un cliente cambia solo testi e immagini delle pagine che può modificare.', 'xb' ) );
		$clients = self::clients();
		?>
		<div class="xb-panel">
			<?php if ( array() === $clients ) : ?>
				<div class="xb-empty"><?php echo Menu::icon( 'groups' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?><strong><?php esc_html_e( 'Nessun cliente', 'xb' ); ?></strong><span><?php esc_html_e( 'Un cliente è un utente con il ruolo «Cliente Histeon» (o con xb_edit_content senza xb_build).', 'xb' ); ?></span></div>
			<?php else : ?>
				<table class="xb-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Cliente', 'xb' ); ?></th><th scope="col"><?php esc_html_e( 'Che cosa può modificare', 'xb' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Azioni', 'xb' ); ?></span></th></tr></thead>
					<tbody>
					<?php foreach ( $clients as $client ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $client->display_name ); ?></strong> <span class="xb-muted"><?php echo esc_html( $client->user_login ); ?></span></td>
							<td class="xb-muted"><?php echo esc_html( self::summary( $client->ID ) ); ?></td>
							<td><?php if ( ClientAccess::can_manage( $client->ID ) ) : ?><a class="xb-btn" href="<?php echo esc_url( self::url( $client->ID ) ); ?>"><?php esc_html_e( 'Modifica i permessi', 'xb' ); ?></a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		Menu::close();
	}

	/** @return list<\WP_Post> Pages and posts the client may edit in WordPress, the most recent first. */
	private static function pages_of( int $user_id ): array {
		$posts = get_posts(
			array(
				'post_type'        => PageLook::TYPES,
				'post_status'      => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'numberposts'      => self::PAGES,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		return array_values( array_filter( $posts, static fn ( \WP_Post $p ): bool => ClientAccess::raw_can_edit( $user_id, $p->ID ) ) );
	}

	private static function form( int $user_id ): void {
		$user      = get_userdata( $user_id );
		$settings  = ClientAccess::settings( $user_id );
		$from_role = user_can( $user_id, \XB\Capabilities::EDIT_PROPS );
		$pages     = self::pages_of( $user_id );
		Menu::open(
			self::SLUG,
			/* translators: %s: name of the client */
			sprintf( __( 'Permessi di %s', 'xb' ), $user instanceof \WP_User ? $user->display_name : '#' . $user_id ),
			__( 'Il server applica queste scelte a ogni salvataggio, anteprima e modifica in WordPress. Il cliente non può cambiarle.', 'xb' ),
			static function (): void {
				printf( '<a class="xb-btn" href="%s">%s%s</a>', esc_url( self::url() ), Menu::icon( 'arrow-left-alt2' ), esc_html__( 'Tutti i clienti', 'xb' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is fixed.
			}
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-client-access">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
			<input type="hidden" name="user" value="<?php echo esc_attr( (string) $user_id ); ?>">
			<?php wp_nonce_field( self::SAVE . '_' . $user_id ); ?>
			<div class="xb-panel">
				<div class="xb-panel-head"><h2><?php esc_html_e( 'Che cosa può modificare', 'xb' ); ?></h2></div>
				<div class="xb-panel-body">
					<p><label><input type="checkbox" checked disabled> <?php esc_html_e( 'Testi e immagini (sempre, nelle pagine con almeno «Testi e immagini»)', 'xb' ); ?></label></p>
					<p>
						<label><input type="checkbox" name="props" value="1" <?php checked( $settings['props'] || $from_role ); ?> <?php disabled( $from_role ); ?>> <?php esc_html_e( 'Props dei componenti (i valori semplici delle istanze: testi, numeri, scelte, link, immagini)', 'xb' ); ?></label>
						<?php if ( $from_role ) : ?><br><span class="xb-muted"><?php esc_html_e( 'Lo dà già il suo ruolo (xb_edit_props): per toglierlo, toglilo dal ruolo.', 'xb' ); ?></span><?php endif; ?>
					</p>
					<p><label><input type="checkbox" name="look" value="1" <?php checked( $settings['look'] ); ?>> <?php esc_html_e( 'Aspetto della pagina (pannello Aspetto: vale solo per quella pagina, mai per le classi del sito)', 'xb' ); ?></label></p>
					<fieldset>
						<legend><?php esc_html_e( 'Gruppi dell\'Aspetto che può usare', 'xb' ); ?></legend>
						<?php foreach ( self::group_labels() as $group => $label ) : ?>
							<label style="margin-right:1em"><input type="checkbox" name="groups[]" value="<?php echo esc_attr( $group ); ?>" <?php checked( in_array( $group, $settings['groups'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				</div>
			</div>
			<div class="xb-panel">
				<div class="xb-panel-head"><h2><?php esc_html_e( 'Su quali pagine', 'xb' ); ?></h2></div>
				<div class="xb-panel-body">
					<p><label><input type="radio" name="pages" value="all" <?php checked( 'all', $settings['pages'] ); ?>> <?php esc_html_e( 'Tutte quelle che può modificare in WordPress', 'xb' ); ?></label></p>
					<p><label><input type="radio" name="pages" value="list" <?php checked( 'list', $settings['pages'] ); ?>> <?php esc_html_e( 'Solo quelle scelte qui sotto, ognuna con il suo livello (le altre: nessun accesso)', 'xb' ); ?></label></p>
					<?php if ( array() === $pages ) : ?>
						<p class="xb-muted"><?php esc_html_e( 'Nessuna pagina o articolo che questo utente può modificare.', 'xb' ); ?></p>
					<?php else : ?>
						<table class="xb-table">
							<thead><tr><th scope="col"><?php esc_html_e( 'Pagina', 'xb' ); ?></th><th scope="col"><?php esc_html_e( 'Tipo', 'xb' ); ?></th><th scope="col"><?php esc_html_e( 'Accesso', 'xb' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $pages as $page ) : ?>
								<?php
								// Listed level; before any list, the level the page has now (switching to a list takes nothing away).
								$level = 'list' === $settings['pages'] ? ( $settings['levels'][ $page->ID ] ?? 'none' ) : ( $settings['look'] && array() !== $settings['groups'] ? 'look' : 'content' );
								$field = 'xb-level-' . $page->ID;
								?>
								<tr>
									<td><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( '' !== $page->post_title ? $page->post_title : __( '(senza titolo)', 'xb' ) ); ?></label></td>
									<td class="xb-muted"><?php echo esc_html( 'page' === $page->post_type ? __( 'Pagina', 'xb' ) : __( 'Articolo', 'xb' ) ); ?></td>
									<td>
										<select id="<?php echo esc_attr( $field ); ?>" name="levels[<?php echo esc_attr( (string) $page->ID ); ?>]">
											<?php foreach ( self::level_labels() as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $level ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="xb-muted"><?php esc_html_e( 'I livelli valgono solo con «Solo quelle scelte». «Anche aspetto» vale se è acceso «Aspetto della pagina» con almeno un gruppo; altrimenti vale come «Testi e immagini».', 'xb' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<p><button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Salva i permessi', 'xb' ); ?></button></p>
		</form>
		<?php
		Menu::close();
	}

	/**
	 * The form's values as ClientAccess reads them: flags as booleans, lists of strings; anything else is refused
	 * there by the closed lists.
	 *
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	public static function from_form( array $post ): array {
		$levels = array();
		foreach ( is_array( $post['levels'] ?? null ) ? $post['levels'] : array() as $id => $level ) {
			$levels[ (string) $id ] = is_string( $level ) ? $level : '';
		}
		return array(
			'props'  => '1' === ( $post['props'] ?? null ),
			'look'   => '1' === ( $post['look'] ?? null ),
			'groups' => array_values( array_map( static fn ( mixed $g ): string => is_string( $g ) ? $g : '', is_array( $post['groups'] ?? null ) ? $post['groups'] : array() ) ),
			'pages'  => is_string( $post['pages'] ?? null ) ? $post['pages'] : 'all',
			'levels' => $levels,
		);
	}

	public static function save(): void {
		$user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
		check_admin_referer( self::SAVE . '_' . $user_id );
		if ( ! ClientAccess::can_manage( $user_id ) ) {
			wp_die( esc_html__( 'Non puoi cambiare i permessi di questo utente.', 'xb' ), 403 );
		}
		$form = self::from_form( wp_unslash( $_POST ) );
		// Pages the form did not list (past the most recent ones) keep their level.
		foreach ( ClientAccess::settings( $user_id )['levels'] as $id => $level ) {
			$form['levels'][ (string) $id ] ??= $level;
		}
		try {
			ClientAccess::update( $user_id, $form );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			Menu::back( self::url( $user_id ), 'error', $e->getMessage() );
		}
		Menu::back( self::url( $user_id ), 'done', __( 'Permessi salvati.', 'xb' ) );
	}
}
