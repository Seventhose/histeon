<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Plugin;
use XB\Rest\Controller;
use XB\Versioning\Versioning;

/**
 * XB → Panoramica: what is waiting to go online, what is online, and the way into the builder.
 */
final class OverviewScreen {

	public const NEW_PAGE = 'xb_new_page';
	public const PRUNE    = 'xb_prune_history';
	/** How old a version must be before the cleanup may remove it, in months. */
	private const PRUNE_MONTHS = array( 6, 12, 24 );

	public static function register(): void {
		add_action( 'admin_post_' . self::NEW_PAGE, array( self::class, 'new_page' ) );
		add_action( 'admin_post_' . self::PRUNE, array( self::class, 'prune' ) );
	}

	/**
	 * Removes old versions nobody needs (lista della spesa 153): administrators only, by hand, after a confirmation.
	 * What is in development, what is online, the bases they come from and every release stay (Store::prune).
	 */
	public static function prune(): void {
		check_admin_referer( self::PRUNE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Solo un amministratore può pulire la storia delle versioni.', 'xb' ), 403 );
		}
		$months = isset( $_POST['months'] ) ? (int) $_POST['months'] : 0;
		if ( ! in_array( $months, self::PRUNE_MONTHS, true ) ) {
			Menu::back( Menu::url( Menu::SLUG ), 'error', __( 'Scegli quanto indietro tenere la storia.', 'xb' ) );
		}
		$store   = Plugin::instance()->versioning->store();
		$before  = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$months} months" ) );
		$deleted = $store->prune( $before );
		$store->audit( 'prune', 'history', array( 'before' => $before, 'deleted' => $deleted ) );
		/* translators: 1: number of versions, 2: months */
		Menu::back( Menu::url( Menu::SLUG ), 'done', sprintf( _n( 'Tolta %1$d versione più vecchia di %2$d mesi.', 'Tolte %1$d versioni più vecchie di %2$d mesi.', $deleted, 'xb' ), $deleted, $months ) );
	}

	/** A new draft page, opened straight in the builder. */
	public static function new_page(): void {
		check_admin_referer( self::NEW_PAGE );
		if ( ! current_user_can( 'xb_build' ) || ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Non puoi creare pagine con Histeon.', 'xb' ), 403 );
		}
		$id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => __( 'Nuova pagina', 'xb' ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			Menu::back( Menu::url( Menu::SLUG ), 'error', $id->get_error_message() );
		}
		wp_safe_redirect( BuilderScreen::url( (int) $id ) );
		exit;
	}

	/** @return array{page: int, post: int, wp_block: int} */
	private static function counts(): array {
		global $wpdb;
		$out = array(
			'page'     => 0,
			'post'     => 0,
			'wp_block' => 0,
		);
		foreach ( array_keys( $out ) as $type ) {
			$out[ $type ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') AND post_content LIKE %s", $type, '%' . $wpdb->esc_like( '<!-- wp:xb/' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count for the overview.
		}
		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( 'xb_build' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per usare Histeon.', 'xb' ), 403 );
		}
		$changes  = Controller::visible_changes();
		$store    = Plugin::instance()->versioning->store();
		$current  = $store->get_release( (int) get_option( Versioning::OPTION_CURRENT, 0 ) );
		$releases = $store->releases( 5 );
		$counts   = self::counts();
		$home     = (int) get_option( 'page_on_front' );
		$recent   = get_posts(
			array(
				'post_type'      => array( 'page', 'post', 'wp_block' ),
				'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'posts_per_page' => 40,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$recent   = array_slice( array_values( array_filter( $recent, static fn ( \WP_Post $p ): bool => \XB\Spec::has_xb_blocks( $p->post_content ) && current_user_can( 'edit_post', $p->ID ) ) ), 0, 6 );
		Menu::open(
			Menu::SLUG,
			__( 'Panoramica', 'xb' ),
			__( 'Il tuo sito costruito con Histeon: cosa è online, cosa aspetta di andarci, da dove ripartire.', 'xb' ),
			static function () use ( $home ): void {
				if ( $home > 0 && BuilderScreen::can_open( $home ) ) {
					printf( '<a class="xb-btn" href="%s">%s%s</a>', esc_url( BuilderScreen::url( $home ) ), Menu::icon( 'admin-home' ), esc_html__( 'Apri la home', 'xb' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is fixed.
				}
				if ( current_user_can( 'edit_pages' ) ) {
					printf( '<a class="xb-btn xb-btn--primary" href="%s">%s%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::NEW_PAGE ), self::NEW_PAGE ) ), Menu::icon( 'plus-alt2' ), esc_html__( 'Nuova pagina', 'xb' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		);
		?>
		<div class="xb-grid">
			<a class="xb-card xb-stat" href="<?php echo esc_url( Menu::url( ReleasesScreen::SLUG ) ); ?>">
				<span class="xb-stat-icon <?php echo array() === $changes ? 'is-ok' : 'is-dev'; ?>"><?php echo Menu::icon( array() === $changes ? 'yes-alt' : 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="xb-stat-value"><?php echo esc_html( (string) count( $changes ) ); ?></span>
				<span class="xb-stat-label"><?php echo esc_html( array() === $changes ? __( 'tutto è online', 'xb' ) : _n( 'documento da pubblicare', 'documenti da pubblicare', count( $changes ), 'xb' ) ); ?></span>
			</a>
			<a class="xb-card xb-stat" href="<?php echo esc_url( Menu::url( ReleasesScreen::SLUG ) ); ?>">
				<span class="xb-stat-icon is-ok"><?php echo Menu::icon( 'cloud-saved' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="xb-stat-value"><?php echo esc_html( null !== $current ? 'v' . $current['version'] : '—' ); ?></span>
				<span class="xb-stat-label"><?php echo esc_html( null !== $current ? sprintf( /* translators: %s: date */ __( 'online dal %s', 'xb' ), Menu::date( $current['created'] ) ) : __( 'nessuna release ancora', 'xb' ) ); ?></span>
			</a>
			<a class="xb-card xb-stat" href="<?php echo esc_url( Menu::url( ContentScreen::PAGES ) ); ?>">
				<span class="xb-stat-icon"><?php echo Menu::icon( 'admin-page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="xb-stat-value"><?php echo esc_html( (string) ( $counts['page'] + $counts['post'] ) ); ?></span>
				<span class="xb-stat-label"><?php echo esc_html( sprintf( /* translators: 1: pages, 2: posts */ __( '%1$d pagine · %2$d articoli', 'xb' ), $counts['page'], $counts['post'] ) ); ?></span>
			</a>
			<a class="xb-card xb-stat" href="<?php echo esc_url( Menu::url( ContentScreen::COMPONENTS ) ); ?>">
				<span class="xb-stat-icon"><?php echo Menu::icon( 'screenoptions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="xb-stat-value"><?php echo esc_html( (string) $counts['wp_block'] ); ?></span>
				<span class="xb-stat-label"><?php esc_html_e( 'componenti riutilizzabili', 'xb' ); ?></span>
			</a>
		</div>

		<?php SiteStatus::render(); ?>
		<?php self::history( $store ); ?>

		<h2 class="xb-section-title"><?php esc_html_e( 'Da pubblicare', 'xb' ); ?> <small><?php esc_html_e( 'salvato nel builder, non ancora online', 'xb' ); ?></small></h2>
		<div class="xb-panel">
			<?php if ( array() === $changes ) : ?>
				<div class="xb-empty"><?php echo Menu::icon( 'yes-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Niente in attesa', 'xb' ); ?></strong><span><?php esc_html_e( 'Il sito online corrisponde a quanto salvato nel builder.', 'xb' ); ?></span></div>
			<?php else : ?>
				<table class="xb-table">
					<tbody>
					<?php foreach ( $changes as $change ) : ?>
						<tr>
							<td><span class="xb-pill is-dev"><?php esc_html_e( 'in sviluppo', 'xb' ); ?></span></td>
							<td><strong><?php echo esc_html( Menu::describe( $change['doc'] ) ); ?></strong></td>
							<td><div class="xb-row-actions"><?php self::doc_links( $change['doc'] ); ?></div></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( current_user_can( 'xb_publish' ) ) : ?>
					<div class="xb-panel-body" style="border-top:1px solid var(--xb-line);text-align:right">
						<a class="xb-btn xb-btn--primary" href="<?php echo esc_url( Menu::url( ReleasesScreen::SLUG ) ); ?>"><?php echo Menu::icon( 'cloud-upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Rivedi e pubblica', 'xb' ); ?></a>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div class="xb-grid" style="grid-template-columns:repeat(auto-fit,minmax(380px,1fr));margin-top:8px">
			<div>
				<h2 class="xb-section-title"><?php esc_html_e( 'Modificati di recente', 'xb' ); ?></h2>
				<div class="xb-panel">
					<?php if ( array() === $recent ) : ?>
						<div class="xb-empty"><?php echo Menu::icon( 'admin-page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Ancora nessuna pagina Histeon', 'xb' ); ?></strong><span><?php esc_html_e( 'Crea una pagina o apri una pagina esistente nel builder.', 'xb' ); ?></span></div>
					<?php else : ?>
						<table class="xb-table"><tbody>
						<?php foreach ( $recent as $post ) : ?>
							<tr>
								<td><strong><?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(senza titolo)', 'xb' ) ); ?></strong><div class="xb-muted"><?php echo esc_html( Menu::date( $post->post_modified_gmt ) ); ?></div></td>
								<td><div class="xb-row-actions"><?php self::doc_links( \XB\Versioning\Documents::for_post( $post->ID ) ); ?></div></td>
							</tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</div>
			</div>
			<div>
				<h2 class="xb-section-title"><?php esc_html_e( 'Ultime release', 'xb' ); ?></h2>
				<div class="xb-panel">
					<?php if ( array() === $releases ) : ?>
						<div class="xb-empty"><?php echo Menu::icon( 'cloud-upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Nessuna release', 'xb' ); ?></strong><span><?php esc_html_e( 'La prima pubblicazione crea la versione 1.0.0.', 'xb' ); ?></span></div>
					<?php else : ?>
						<ol class="xb-timeline">
						<?php foreach ( $releases as $release ) : ?>
							<?php $is_current = null !== $current && $release['id'] === $current['id']; ?>
							<li class="<?php echo $is_current ? 'is-live is-current' : ''; ?>">
								<div class="xb-tl-title"><?php echo esc_html( 'v' . $release['version'] ); ?>
									<?php if ( $is_current ) : ?>
										<span class="xb-pill is-live"><?php esc_html_e( 'online', 'xb' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="xb-tl-meta"><?php echo esc_html( Menu::date( $release['created'] ) . ' · ' . Menu::user_name( $release['author'] ) . ( '' !== $release['notes'] ? ' · ' . wp_trim_words( $release['notes'], 10 ) : '' ) ); ?></div>
							</li>
						<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		Menu::close();
	}

	/** The size of the version history, and for administrators the cleanup (lista della spesa 153). */
	private static function history( \XB\Versioning\Store $store ): void {
		$size = $store->history_size();
		if ( 0 === $size['commits'] ) {
			return;
		}
		?>
		<details class="xb-panel xb-history-size">
			<summary class="xb-panel-body">
				<strong><?php esc_html_e( 'Storia delle versioni', 'xb' ); ?></strong>
				<span class="xb-muted"><?php echo esc_html( sprintf( /* translators: 1: number of versions, 2: size, 3: date */ _n( '%1$s versione salvata · %2$s · dal %3$s', '%1$s versioni salvate · %2$s · dal %3$s', $size['commits'], 'xb' ), number_format_i18n( $size['commits'] ), size_format( $size['bytes'], 1 ), null !== $size['oldest'] ? Menu::date( $size['oldest'] ) : '—' ) ); ?></span>
			</summary>
			<div class="xb-panel-body">
				<p><?php esc_html_e( 'Ogni salvataggio nel builder è una versione: si può riaprire e ripristinare dalla scheda Versioni. Con il tempo la storia cresce nel database.', 'xb' ); ?></p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Le versioni tolte non si possono più ripristinare. Procedere?', 'xb' ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::PRUNE ); ?>">
						<?php wp_nonce_field( self::PRUNE ); ?>
						<label><?php esc_html_e( 'Togli le versioni più vecchie di', 'xb' ); ?>
							<select name="months">
								<?php foreach ( self::PRUNE_MONTHS as $months ) : ?>
									<option value="<?php echo esc_attr( (string) $months ); ?>" <?php selected( 12, $months ); ?>><?php echo esc_html( sprintf( /* translators: %d: months */ __( '%d mesi', 'xb' ), $months ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<button class="xb-btn" type="submit"><?php esc_html_e( 'Pulisci la storia', 'xb' ); ?></button>
					</form>
					<p class="xb-muted"><?php esc_html_e( 'Restano sempre: ciò che è in sviluppo e online, le versioni da cui partono, e ogni release, che resta ripristinabile.', 'xb' ); ?></p>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/** Builder, Gutenberg and versions of a document, as far as the user may open them. */
	public static function doc_links( string $doc ): void {
		$id = \XB\Versioning\Documents::post_id( $doc );
		if ( null !== $id && BuilderScreen::can_open( $id ) ) {
			printf( '<a class="xb-btn xb-btn--small xb-btn--primary" href="%s">%s</a>', esc_url( BuilderScreen::url( $id ) ), esc_html__( 'Apri in Histeon', 'xb' ) );
		}
		if ( Menu::can_see( $doc ) ) {
			printf( '<a class="xb-btn xb-btn--small" href="%s">%s</a>', esc_url( Menu::url( HistoryScreen::SLUG, array( 'doc' => $doc ) ) ), esc_html__( 'Versioni', 'xb' ) );
		}
	}
}
