<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Etch;

use XB\Admin\Menu;
use XB\Admin\SettingsScreen;

/**
 * Histeon → Impostazioni → «Esporta verso Etch» — docs/spec/export-etch.md §6.
 *
 * Preview (nothing written), download of the JSON file, write into this site only with the fingerprint of the
 * preview the user just read and an explicit confirmation, and restore of the last backup. One admin-post action,
 * a nonce, and the capabilities of Exporter (writing also needs unfiltered_html).
 */
final class Admin {

	public const ACTION = 'xb_etch_export';
	public const ANCHOR = 'xb-etch-export';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/** Shown where Etch is (its data or its plugin folder) or an export left a backup. */
	public static function visible(): bool {
		return Exporter::can_export() && ( \XB\Import\Etch\Migration::detected() || is_dir( dirname( XB_FILE, 2 ) . '/etch' ) || array() !== Exporter::backups() );
	}

	private static function transient(): string {
		return 'xb_etch_export_' . get_current_user_id();
	}

	public static function render(): void {
		if ( ! self::visible() ) {
			return;
		}
		$plan = get_transient( self::transient() );
		$plan = is_array( $plan ) ? $plan : null;
		$acss = Exporter::acss_available();
		?>
		<section class="xb-panel" id="<?php echo esc_attr( self::ANCHOR ); ?>">
		<div class="xb-panel-head"><div>
		<h2><?php esc_html_e( 'Esporta verso Etch', 'xb' ); ?></h2>
		<p><?php esc_html_e( 'Per un sito che deve restare su Etch: riscrive pagine, componenti, template, stili e loop pubblicati in Histeon nei dati di Etch di questo sito. Prima un\'anteprima, che non modifica nulla; la scrittura fa una copia di sicurezza di tutto ciò che sostituisce e si annulla con «Ripristina». Le versioni di Histeon restano.', 'xb' ); ?></p>
		</div></div>
		<div class="xb-panel-body">
		<p class="description"><?php esc_html_e( 'Gli stili portano con sé Histeon CSS (i token --xb-* e le classi), open source. Condizioni, slot, tipi di contenuto e record, template di Histeon CMS non sono supportati dall\'esportazione: non vengono esportati e l\'anteprima li elenca.', 'xb' ); ?></p>
		<?php if ( null !== $plan ) : ?>
			<?php self::render_plan( $plan ); ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<div class="xb-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Nomi dei token', 'xb' ); ?>">
				<label class="xb-choice"><input type="radio" name="xb_tokens" value="<?php echo esc_attr( Tokens::HISTOS ); ?>" checked> <strong><?php esc_html_e( 'Histeon CSS', 'xb' ); ?></strong><span><?php esc_html_e( 'Consigliato. Un foglio globale con i token --xb-* e uno con le classi di Histeon CSS: il sito resta modificabile dai token.', 'xb' ); ?></span></label>
				<?php if ( $acss ) : ?>
					<label class="xb-choice"><input type="radio" name="xb_tokens" value="<?php echo esc_attr( Tokens::ACSS ); ?>"> <strong><?php esc_html_e( 'Nomi di Automatic.css', 'xb' ); ?></strong><span><?php esc_html_e( 'Solo perché Automatic.css è già installato su questo sito: i token e le classi che hanno un nome ACSS lo prendono (--xb-space-m diventa --space-m). Histeon non usa né legge licenze.', 'xb' ); ?></span></label>
				<?php endif; ?>
			</div>
			<p>
				<button class="xb-btn" name="xb_mode" value="plan"><?php esc_html_e( 'Anteprima dell\'esportazione', 'xb' ); ?></button>
				<button class="xb-btn" name="xb_mode" value="download"><?php esc_html_e( 'Scarica il file JSON', 'xb' ); ?></button>
			</p>
		</form>
		<?php if ( array() !== Exporter::backups() && Exporter::can_write() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><label><input type="checkbox" name="xb_confirm" value="1" required> <?php esc_html_e( 'Rimetti contenuti, chiavi dei componenti e opzioni di Etch come erano prima dell\'ultima scrittura', 'xb' ); ?></label></p>
				<p><button class="xb-btn" name="xb_mode" value="restore"><?php esc_html_e( 'Ripristina', 'xb' ); ?></button></p>
			</form>
		<?php endif; ?>
		</div>
		</section>
		<?php
	}

	/** @param array<string, mixed> $plan */
	private static function render_plan( array $plan ): void {
		$diff    = is_array( $plan['diff'] ?? null ) ? $plan['diff'] : array();
		$changes = array_column( is_array( $diff['posts'] ?? null ) ? $diff['posts'] : array(), null, 'id' );
		$report  = is_array( $plan['report'] ?? null ) ? $plan['report'] : array();
		?>
		<div class="notice notice-info inline">
			<p><strong><?php echo esc_html( ! empty( $plan['written'] ) ? __( 'Esportazione scritta nei dati di Etch di questo sito.', 'xb' ) : __( 'Anteprima: nulla è stato modificato.', 'xb' ) ); ?></strong></p>
			<table class="widefat striped" style="max-width:960px">
				<thead><tr><th><?php esc_html_e( 'Documento', 'xb' ); ?></th><th><?php esc_html_e( 'Fiducia', 'xb' ); ?></th><th><?php esc_html_e( 'Blocchi', 'xb' ); ?></th><th><?php esc_html_e( 'Omessi', 'xb' ); ?></th><th><?php esc_html_e( 'Sostituisce contenuto Etch', 'xb' ); ?></th><th><?php esc_html_e( 'Trasformato', 'xb' ); ?></th><th><?php esc_html_e( 'Da sapere', 'xb' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $report['documents'] ?? array() ) as $row ) : ?>
					<?php if ( is_array( $row ) ) : ?>
					<tr>
						<td><?php echo esc_html( sprintf( '%s (%s #%d)', (string) ( $row['title'] ?? '' ), (string) ( $row['type'] ?? '' ), (int) ( $row['id'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( ! empty( $row['trusted'] ) ? 'trusted' : 'restricted' ); ?></td>
						<td><?php echo esc_html( (string) (int) ( $row['blocks'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( (string) (int) ( $row['omitted'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( ! empty( $changes[ (int) ( $row['id'] ?? 0 ) ]['etch'] ) ? __( 'sì', 'xb' ) : '' ); ?></td>
						<td><?php echo esc_html( implode( ' · ', array_map( 'strval', (array) ( $row['changes'] ?? array() ) ) ) ); ?></td>
						<td><?php echo esc_html( implode( ' · ', array_map( 'strval', (array) ( $row['issues'] ?? array() ) ) ) ); ?></td>
					</tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$replaced = array_filter( (array) ( $diff['options'] ?? array() ), static fn ( mixed $e ): bool => is_array( $e ) && 'replace' === ( $e['action'] ?? '' ) );
			$added    = array_filter( (array) ( $diff['options'] ?? array() ), static fn ( mixed $e ): bool => is_array( $e ) && 'add' === ( $e['action'] ?? '' ) );
			?>
			<p><?php echo esc_html( sprintf( /* translators: 1: added entries, 2: replaced entries */ __( 'Voci di Etch (query, fogli globali, stili): %1$d nuove, %2$d sostituite.', 'xb' ), count( $added ), count( $replaced ) ) ); ?></p>
			<?php foreach ( $replaced as $entry ) : ?>
				<p>⚠ <?php echo esc_html( sprintf( /* translators: 1: option, 2: key */ __( 'Sostituisce la voce esistente %1$s → %2$s', 'xb' ), (string) $entry['option'], (string) $entry['key'] ) ); ?></p>
			<?php endforeach; ?>
			<?php foreach ( array_merge( (array) ( $report['issues'] ?? array() ), (array) ( $report['skipped'] ?? array() ) ) as $issue ) : ?>
				<p>⚠ <?php echo esc_html( (string) $issue ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php if ( empty( $plan['written'] ) && Exporter::can_write() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="xb_tokens" value="<?php echo esc_attr( (string) ( $plan['tokens'] ?? Tokens::HISTOS ) ); ?>">
				<input type="hidden" name="xb_fingerprint" value="<?php echo esc_attr( (string) ( $plan['fingerprint'] ?? '' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><label><input type="checkbox" name="xb_confirm" value="1" required> <?php esc_html_e( 'Ho letto l\'anteprima: scrivi questa esportazione nei dati di Etch di questo sito (con copia di sicurezza)', 'xb' ); ?></label></p>
				<p><button class="xb-btn xb-btn--primary" name="xb_mode" value="apply"><?php esc_html_e( 'Scrivi in questo sito', 'xb' ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	public static function handle(): void {
		if ( ! Exporter::can_export() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$back   = SettingsScreen::url() . '#' . self::ANCHOR;
		$mode   = isset( $_POST['xb_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_mode'] ) ) : 'plan';
		$tokens = isset( $_POST['xb_tokens'] ) && Tokens::ACSS === $_POST['xb_tokens'] ? Tokens::ACSS : Tokens::HISTOS;
		$sure   = ! empty( $_POST['xb_confirm'] );
		try {
			if ( 'restore' === $mode ) {
				if ( ! $sure ) {
					Menu::back( $back, 'error', __( 'Conferma il ripristino con la casella.', 'xb' ) );
				}
				$done = Exporter::restore();
				delete_transient( self::transient() );
				Menu::back( $back, 'done', sprintf( /* translators: %s: date */ __( 'Ripristinato lo stato di prima della scrittura del %s.', 'xb' ), $done['at'] ) );
			}
			$exporter = new Exporter( $tokens );
			if ( 'download' === $mode ) {
				$file = Exporter::file( $exporter->plan() );
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="histos-etch-export-' . gmdate( 'Ymd-His' ) . '.json"' );
				header( 'X-Content-Type-Options: nosniff' );
				echo $file; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download, not HTML.
				exit;
			}
			if ( 'apply' === $mode ) {
				if ( ! $sure ) {
					Menu::back( $back, 'error', __( 'Conferma la scrittura con la casella.', 'xb' ) );
				}
				$fingerprint = isset( $_POST['xb_fingerprint'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['xb_fingerprint'] ) ) : '';
				$result      = $exporter->apply( $fingerprint );
			} else {
				$result = $exporter->plan();
			}
		} catch ( \Throwable $e ) {
			Menu::back( $back, 'error', $e->getMessage() );
		}
		// The page needs the report, not the content: the transient stays small.
		set_transient( self::transient(), array_diff_key( $result, array( 'posts' => true, 'options' => true ) ), 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( $back );
		exit;
	}
}
