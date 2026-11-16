<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Plugin;
use XB\Versioning\Integrity;

/**
 * «Versioni da riparare» (docs/spec/versioning.md): after a commit did not read, administrators see a notice on
 * every admin screen, the overview included even when it cannot load, with the site's old address to give. The
 * repair rewrites only the fingerprints of the commits whose content differs by that address alone.
 */
final class VersionsRepair {

	public const ACTION = 'xb_versions_repair';

	private const RESULT = 'xb_versions_repair_result_';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'run' ) );
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'xb_build' ) ) {
			return;
		}
		$result = get_transient( self::RESULT . get_current_user_id() );
		if ( is_string( $result ) && '' !== $result ) {
			delete_transient( self::RESULT . get_current_user_id() );
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $result ) );
		}
		if ( ! get_option( Integrity::UNREADABLE ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Histeon: alcune versioni non si leggono.', 'xb' ); ?></strong>
			<?php esc_html_e( 'Il loro contenuto non corrisponde più alla loro impronta, e nel builder le pagine possono apparire vuote. Succede di solito quando il sito viene spostato a un altro indirizzo (All-in-One WP Migration, Duplicator, search-replace): l\'indirizzo cambia anche dentro le versioni. Scrivi l\'indirizzo che il sito aveva prima dello spostamento: Histeon ripara solo le versioni che differiscono soltanto per quell\'indirizzo, senza cambiare il contenuto.', 'xb' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;margin:0 0 10px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label for="xb-from"><?php esc_html_e( 'Indirizzo di prima', 'xb' ); ?></label>
				<input type="url" id="xb-from" name="xb_from" class="regular-text" placeholder="https://vecchio-indirizzo.example">
				<button class="button button-primary"><?php esc_html_e( 'Ripara le versioni', 'xb' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;margin:0 0 10px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="xb_accept" value="1">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label><input type="checkbox" name="xb_confirm" value="1" required> <?php esc_html_e( 'Ho controllato le pagine: accetto le versioni che restano così come sono ora.', 'xb' ); ?></label>
				<button class="button"><?php esc_html_e( 'Accetta le versioni rimaste', 'xb' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Se lo spostamento ha portato tutti gli indirizzi a https, Histeon non può dimostrare che è cambiato solo l\'indirizzo. In quel caso, dopo la riparazione, puoi accettare le versioni rimaste: è registrato nel registro di Histeon.', 'xb' ); ?></p>
		</div>
		<?php
	}

	public static function run(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'xb_build' ) ) {
			wp_die( esc_html__( 'Solo un amministratore ripara le versioni.', 'xb' ), 403 );
		}
		$from   = isset( $_POST['xb_from'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['xb_from'] ) ) ) : '';
		$accept = isset( $_POST['xb_accept'], $_POST['xb_confirm'] ) && '1' === $_POST['xb_confirm'];
		if ( isset( $_POST['xb_accept'] ) && ! $accept ) {
			self::done( __( 'Per accettare le versioni rimaste serve la conferma.', 'xb' ) );
		}
		if ( '' !== $from && null === Integrity::origin( $from ) ) {
			self::done( __( 'L\'indirizzo di prima non è valido: scrivilo intero, per esempio http://sito.local.', 'xb' ) );
		}
		$result = Integrity::repair_all( '' === $from ? null : $from, $accept );
		Plugin::instance()->versioning->store()->audit(
			'versions_repair',
			'versions',
			array(
				'from'       => $from,
				'repaired'   => $result['repaired'],
				'upgraded'   => $result['upgraded'],
				'accepted'   => array_slice( $result['accepted'], 0, 200 ),
				'unreadable' => array_slice( $result['unreadable'], 0, 20 ),
			)
		);
		self::done( self::summary( $result ) );
	}

	/** @param array{checked: int, upgraded: int, repaired: int, accepted: list<string>, unreadable: list<string>} $result */
	public static function summary( array $result ): string {
		$text = sprintf(
			/* translators: 1: commits checked, 2: commits repaired */
			__( 'Histeon ha controllato %1$d versioni e ne ha riparate %2$d.', 'xb' ),
			$result['checked'],
			$result['repaired']
		);
		if ( array() !== $result['accepted'] ) {
			/* translators: %d: commits accepted as they are */
			$text .= ' ' . sprintf( _n( '%d accettata così com\'è.', '%d accettate così come sono.', count( $result['accepted'] ), 'xb' ), count( $result['accepted'] ) );
		}
		if ( array() !== $result['unreadable'] ) {
			$text .= ' ' . sprintf(
				/* translators: 1: commits still unreadable, 2: first of them */
				_n( '%1$d non si legge ancora (%2$s). Controlla l\'indirizzo di prima; se tutti gli indirizzi sono passati a https, controlla le pagine e accetta le versioni rimaste.', '%1$d non si leggono ancora (la prima è %2$s). Controlla l\'indirizzo di prima; se tutti gli indirizzi sono passati a https, controlla le pagine e accetta le versioni rimaste.', count( $result['unreadable'] ), 'xb' ),
				count( $result['unreadable'] ),
				$result['unreadable'][0]
			);
		}
		return $text;
	}

	private static function done( string $message ): never {
		set_transient( self::RESULT . get_current_user_id(), $message, 300 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
