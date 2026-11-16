<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Updates\Backup;

/**
 * Histeon → Impostazioni → Copie di sicurezza (doc 23 of Histeon Publish, item 178): the copies made before an update,
 * «Fai una copia adesso», and «Torna alla versione di prima» with a confirmation. Only for who may manage the site and
 * update plugins, on a single site (Backup::can_manage()).
 */
final class BackupPanel {

	public const CREATE  = 'xb_update_backup_create';
	public const RESTORE = 'xb_update_backup_restore';

	public static function register(): void {
		add_action( 'admin_post_' . self::CREATE, array( self::class, 'create' ) );
		add_action( 'admin_post_' . self::RESTORE, array( self::class, 'restore' ) );
	}

	private static function back_url(): string {
		return Menu::url( SettingsScreen::SLUG ) . '#xb-backups';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$copies = Backup::available() ? Backup::all() : array();
		?>
		<section class="xb-panel" id="xb-backups">
		<div class="xb-panel-head"><div>
			<h2><?php esc_html_e( 'Copie di sicurezza di Histeon', 'xb' ); ?></h2>
			<p><?php esc_html_e( 'Prima che WordPress installi una nuova versione di Histeon (dalla pagina dei plugin, con gli aggiornamenti automatici, con il suo aggiornatore o caricando uno ZIP che sostituisce quello installato), Histeon copia la sua cartella e le sue tabelle (versioni, release, registro, pubblicazioni). Si tengono le ultime due copie, in una cartella degli upload chiusa al web.', 'xb' ); ?></p>
			<p class="xb-muted"><?php esc_html_e( 'Non sono nella copia: i contenuti del sito (pagine, articoli, media, menu), le impostazioni di Histeon, i dati dei moduli (Lingua Bridge, Static Bridge: il loro codice sì, è nella cartella), gli altri plugin e il tema. Per quelli serve un backup del sito.', 'xb' ); ?></p>
		</div></div>
		<div class="xb-panel-body">
			<?php if ( ! Backup::available() ) : ?>
				<p class="xb-muted"><?php esc_html_e( 'Su un multisito Histeon non fa copie di sicurezza: la cartella del plugin è di tutti i siti, le tabelle di ognuno.', 'xb' ); ?></p>
			<?php else : ?>
				<?php if ( ! Backup::enabled() ) : ?>
					<p class="xb-muted"><?php esc_html_e( 'La copia automatica prima degli aggiornamenti è spenta in wp-config.php (XB_UPDATE_BACKUP).', 'xb' ); ?></p>
				<?php endif; ?>
				<?php if ( array() === $copies ) : ?>
					<p><?php esc_html_e( 'Nessuna copia ancora: la prima nasce al prossimo aggiornamento, oppure adesso.', 'xb' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Copia', 'xb' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Contenuto', 'xb' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ritorno', 'xb' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $copies as $index => $copy ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Histeon %s', 'xb' ), $copy['version'] ) ); ?></strong><br>
									<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $copy['created'] ) ?: '' ); ?><br>
									<span class="xb-muted"><?php echo esc_html( self::reason( $copy['reason'], $copy['to'] ) ); ?></span>
								</td>
								<td>
									<?php echo esc_html( sprintf( /* translators: 1: files, 2: rows, 3: size */ __( '%1$d file della cartella, %2$d righe nelle tabelle, %3$s', 'xb' ), $copy['files'], array_sum( $copy['tables'] ), size_format( $copy['bytes'] ) ?: '0 B' ) ); ?>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( self::RESTORE ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( self::RESTORE ); ?>">
										<input type="hidden" name="backup" value="<?php echo esc_attr( $copy['id'] ); ?>">
										<label class="xb-choice"><input type="checkbox" name="confirm" value="1" required>
											<?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Rimetti Histeon %s: la cartella del plugin e le tabelle tornano come nella copia; le versioni salvate dopo la copia spariscono dalla cronologia (le pagine restano come sono).', 'xb' ), $copy['version'] ) ); ?>
										</label>
										<p><button class="xb-btn"><?php echo 0 === $index ? esc_html__( 'Torna alla versione di prima', 'xb' ) : esc_html__( 'Torna a questa copia', 'xb' ); ?></button></p>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::CREATE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE ); ?>">
					<p><button class="xb-btn"><?php esc_html_e( 'Fai una copia adesso', 'xb' ); ?></button>
					<?php if ( count( $copies ) >= Backup::KEEP ) : ?>
						<span class="xb-muted"><?php esc_html_e( 'La copia più vecchia lascia il posto alla nuova.', 'xb' ); ?></span>
					<?php endif; ?></p>
				</form>
			<?php endif; ?>
		</div>
		</section>
		<?php
	}

	private static function reason( string $reason, string $to ): string {
		return match ( $reason ) {
			/* translators: %s: version */
			'update' => '' !== $to ? sprintf( __( 'prima dell’aggiornamento a %s', 'xb' ), $to ) : __( 'prima di un aggiornamento', 'xb' ),
			/* translators: %s: version */
			'upload' => '' !== $to ? sprintf( __( 'prima di caricare lo ZIP di %s', 'xb' ), $to ) : __( 'prima di caricare uno ZIP', 'xb' ),
			default  => __( 'fatta a mano', 'xb' ),
		};
	}

	public static function create(): void {
		check_admin_referer( self::CREATE );
		if ( ! Backup::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per le copie di sicurezza di Histeon.', 'xb' ), 403 );
		}
		try {
			Backup::create( 'manual' );
		} catch ( \RuntimeException $e ) {
			Menu::back( self::back_url(), 'error', $e->getMessage() );
		}
		Menu::back( self::back_url(), 'done', __( 'Copia di sicurezza fatta.', 'xb' ) );
	}

	public static function restore(): void {
		check_admin_referer( self::RESTORE );
		if ( ! Backup::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per le copie di sicurezza di Histeon.', 'xb' ), 403 );
		}
		// Everything the answer needs is ready before the folder changes under this request.
		$url = self::back_url();
		$id  = sanitize_text_field( wp_unslash( (string) ( $_POST['backup'] ?? '' ) ) );
		if ( empty( $_POST['confirm'] ) ) {
			Menu::back( $url, 'error', __( 'Conferma il ritorno alla copia con la casella accanto al pulsante.', 'xb' ) );
		}
		try {
			$entry = Backup::restore( $id );
		} catch ( \RuntimeException $e ) {
			Menu::back( $url, 'error', $e->getMessage() );
		}
		/* translators: %s: version */
		Menu::back( $url, 'done', sprintf( __( 'Histeon %s è tornato com’era nella copia, con le sue tabelle.', 'xb' ), $entry['version'] ) );
	}
}
