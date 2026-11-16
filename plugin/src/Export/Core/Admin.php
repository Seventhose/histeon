<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

use XB\Admin\Menu;
use XB\Admin\SettingsScreen;

/**
 * Histeon → Impostazioni → «Uscita verso Gutenberg» — doc 25 of Histeon Publish, D7; docs/spec/export-core.md §17.
 *
 * The preview of the exit with the field and form plugins chosen (nothing written), the site theme to download, the
 * write only with the fingerprint of the preview just read and an explicit confirmation, the check of what is left,
 * the restore of the last backup, and the finish: the site theme active and Histeon turned off. One admin-post action,
 * a nonce, and the capabilities of Departure (writing also needs unfiltered_html).
 */
final class Admin {

	public const ACTION = 'xb_core_export';
	public const ANCHOR = 'xb-core-export';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/** Shown to who may read the report, with Fondamenta active (its blocks are where the pages go) or a backup left. */
	public static function visible(): bool {
		return Report::can_read() && ( Prova::ready() || array() !== Departure::backups() );
	}

	private static function transient(): string {
		return 'xb_core_export_' . get_current_user_id();
	}

	public static function render(): void {
		if ( ! self::visible() ) {
			return;
		}
		$state = get_transient( self::transient() );
		$state = is_array( $state ) ? $state : null;
		?>
		<section class="xb-panel" id="<?php echo esc_attr( self::ANCHOR ); ?>">
		<div class="xb-panel-head"><div>
		<h2><?php esc_html_e( 'Uscita verso Gutenberg', 'xb' ); ?></h2>
		<p><?php esc_html_e( 'Per lasciare Histeon: le pagine diventano blocchi di Fondamenta e del core, i campi passano al plugin di campi che scegli, i form al plugin dei form. Prima un\'anteprima, che non modifica nulla; la scrittura fa una copia di sicurezza di tutto ciò che sostituisce e si annulla con «Ripristina». Histeon resta installato e spento finché non decidi di cancellarlo.', 'xb' ); ?></p>
		</div></div>
		<div class="xb-panel-body">
		<?php if ( null !== $state ) : ?>
			<?php self::render_state( $state ); ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<p>
				<label><?php esc_html_e( 'Plugin dei campi', 'xb' ); ?>
				<select name="xb_campi">
					<option value=""><?php esc_html_e( 'Nessuno (i valori restano come sono)', 'xb' ); ?></option>
					<?php foreach ( Fields\Adapters::all() as $id => $adapter ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, (string) ( $state['campi'] ?? '' ) ); ?>><?php echo esc_html( $adapter->name() ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<label><?php esc_html_e( 'Plugin dei form', 'xb' ); ?>
				<select name="xb_form">
					<option value=""><?php esc_html_e( 'Nessuno', 'xb' ); ?></option>
					<?php foreach ( Forms\Adapters::all() as $id => $adapter ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, (string) ( $state['form'] ?? '' ) ); ?>><?php echo esc_html( $adapter->name() ); ?></option>
					<?php endforeach; ?>
				</select></label>
			</p>
			<p>
				<button class="xb-btn" name="xb_mode" value="plan"><?php esc_html_e( 'Anteprima dell\'uscita', 'xb' ); ?></button>
				<button class="xb-btn" name="xb_mode" value="theme"><?php esc_html_e( 'Scarica il tema del sito', 'xb' ); ?></button>
				<button class="xb-btn" name="xb_mode" value="check"><?php esc_html_e( 'Controlla cosa resta', 'xb' ); ?></button>
			</p>
		</form>
		<?php if ( array() !== Departure::backups() && Departure::can_write() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><label><input type="checkbox" name="xb_confirm" value="1" required> <?php esc_html_e( 'Rimetti contenuti, template delle pagine, valori dei campi e tema come erano prima dell\'ultima scrittura', 'xb' ); ?></label></p>
				<p><button class="xb-btn" name="xb_mode" value="restore"><?php esc_html_e( 'Ripristina', 'xb' ); ?></button></p>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php /* translators: %s: theme slug */ ?>
				<p><label><input type="checkbox" name="xb_confirm" value="1" required> <?php echo esc_html( sprintf( __( 'Ho installato il tema «%s» e controllato le pagine: attivalo e spegni Histeon (non lo cancella)', 'xb' ), Departure::theme_slug() ) ); ?></label></p>
				<p><button class="xb-btn" name="xb_mode" value="finish"><?php esc_html_e( 'Concludi l\'uscita', 'xb' ); ?></button></p>
			</form>
		<?php endif; ?>
		</div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $state */
	private static function render_state( array $state ): void {
		$plan = is_array( $state['plan'] ?? null ) ? $state['plan'] : null;
		?>
		<div class="notice notice-info inline">
		<?php if ( isset( $state['left'] ) ) : ?>
			<?php /* translators: %d: documents */ ?>
			<p><strong><?php echo esc_html( sprintf( __( 'Documenti con blocchi di Histeon: %d.', 'xb' ), count( (array) $state['left'] ) ) ); ?></strong></p>
			<?php foreach ( (array) $state['left'] as $doc ) : ?>
				<p><?php echo esc_html( sprintf( '#%d %s «%s»', (int) $doc['id'], (string) $doc['type'], (string) $doc['title'] ) ); ?></p>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( isset( $state['written'] ) ) : ?>
			<?php /* translators: 1: documents, 2: page templates, 3: contents with fields, 4: forms, 5: messages */ ?>
			<p><strong><?php echo esc_html( sprintf( __( 'Uscita scritta: %1$d documenti convertiti, %2$d template di pagina, %3$d contenuti con i campi nel nuovo formato, %4$d form creati con %5$d messaggi. Non pubblicare una release di Histeon.', 'xb' ), ...array_map( 'intval', array_values( (array) $state['written'] ) ) ) ); ?></strong></p>
		<?php endif; ?>
		<?php if ( null !== $plan ) : ?>
			<p><strong><?php esc_html_e( 'Anteprima: nulla è stato modificato.', 'xb' ); ?></strong></p>
			<?php /* translators: 1: documents, 2: pages, 3: contents with fields, 4: forms */ ?>
			<p><?php echo esc_html( sprintf( __( 'Documenti da convertire: %1$d. Pagine con un template proprio: %2$d. Contenuti con i campi: %3$d. Form di Histeon: %4$d.', 'xb' ), count( (array) $plan['documents'] ), count( (array) $plan['pages'] ), count( (array) $plan['fields']['posts'] ), count( (array) $plan['forms']['names'] ) ) ); ?></p>
			<?php foreach ( (array) $plan['notes'] as $note ) : ?>
				<p>⚠ <?php echo esc_html( (string) $note ); ?></p>
			<?php endforeach; ?>
		<?php endif; ?>
		</div>
		<?php if ( null !== $plan && Departure::can_write() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="xb_campi" value="<?php echo esc_attr( (string) ( $state['campi'] ?? '' ) ); ?>">
				<input type="hidden" name="xb_form" value="<?php echo esc_attr( (string) ( $state['form'] ?? '' ) ); ?>">
				<input type="hidden" name="xb_fingerprint" value="<?php echo esc_attr( (string) $plan['fingerprint'] ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><label><input type="checkbox" name="xb_confirm" value="1" required> <?php esc_html_e( 'Ho letto l\'anteprima e ho una copia completa del sito: scrivi l\'uscita (con copia di sicurezza)', 'xb' ); ?></label></p>
				<p><button class="xb-btn xb-btn--primary" name="xb_mode" value="apply"><?php esc_html_e( 'Scrivi l\'uscita', 'xb' ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	public static function handle(): void {
		if ( ! Report::can_read() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$back   = SettingsScreen::url() . '#' . self::ANCHOR;
		$mode   = isset( $_POST['xb_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_mode'] ) ) : 'plan';
		$campi  = isset( $_POST['xb_campi'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_campi'] ) ) : '';
		$form   = isset( $_POST['xb_form'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_form'] ) ) : '';
		$sure   = ! empty( $_POST['xb_confirm'] );
		$fields = '' !== $campi ? Fields\Adapters::get( $campi ) : null;
		$forms  = '' !== $form ? Forms\Adapters::get( $form ) : null;
		$state  = array(
			'campi' => null !== $fields ? $campi : '',
			'form'  => null !== $forms ? $form : '',
		);
		try {
			switch ( $mode ) {
				case 'restore':
					if ( ! $sure ) {
						Menu::back( $back, 'error', __( 'Conferma il ripristino con la casella.', 'xb' ) );
					}
					$done = Departure::restore();
					delete_transient( self::transient() );
					/* translators: %s: date */
					Menu::back( $back, 'done', sprintf( __( 'Ripristinato lo stato di prima della scrittura del %s.', 'xb' ), $done['at'] ) );
					// Menu::back() ends the request.
				case 'finish':
					if ( ! $sure ) {
						Menu::back( $back, 'error', __( 'Conferma la conclusione con la casella.', 'xb' ) );
					}
					Departure::finish( Departure::theme_slug() );
					// Histeon is off: its screens are gone.
					wp_safe_redirect( admin_url( 'themes.php' ) );
					exit;
				case 'theme':
					$path  = (string) wp_tempnam( 'tema-sito.zip' );
					$theme = ( new SiteTheme( $fields ) )->zip( $path );
					nocache_headers();
					header( 'Content-Type: application/zip' );
					header( 'Content-Disposition: attachment; filename="' . $theme['slug'] . '.zip"' );
					header( 'X-Content-Type-Options: nosniff' );
					readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- the ZIP just written.
					wp_delete_file( $path );
					exit;
				case 'check':
					$state['left'] = Departure::check()['left'];
					break;
				case 'apply':
					if ( ! $sure ) {
						Menu::back( $back, 'error', __( 'Conferma la scrittura con la casella.', 'xb' ) );
					}
					$fingerprint      = isset( $_POST['xb_fingerprint'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['xb_fingerprint'] ) ) : '';
					$done             = ( new Departure( $fields, $forms ) )->apply( $fingerprint );
					$state['written'] = array( $done['documents'], $done['pages'], $done['fields'], $done['forms'], $done['messages'] );
					break;
				default:
					$state['plan'] = ( new Departure( $fields, $forms ) )->plan();
			}
		} catch ( \Throwable $e ) {
			Menu::back( $back, 'error', $e->getMessage() );
		}
		set_transient( self::transient(), $state, 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( $back );
		exit;
	}
}
