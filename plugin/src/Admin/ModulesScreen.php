<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Modules\Module;
use XB\Modules\Registry;

/**
 * Histeon → Moduli — ADR 0015 (M6): one card per module with its state, version and requirements, the switch and
 * the way to its settings. Administrators who can build only; every switch is a form with its own nonce.
 */
final class ModulesScreen {

	public const SLUG = 'xb-modules';

	public static function register(): void {
		add_action( 'admin_post_' . Registry::ACTION, array( self::class, 'toggle' ) );
	}

	public static function menu(): void {
		add_submenu_page( Menu::SLUG, __( 'Moduli di Histeon', 'xb' ), __( 'Moduli', 'xb' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	public static function render(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per gestire i moduli di Histeon.', 'xb' ), 403 );
		}
		Menu::open( self::SLUG, __( 'Moduli', 'xb' ), __( 'Le parti di Histeon che puoi accendere e spegnere. Spegnere un modulo non cancella mai i suoi dati.', 'xb' ) );
		if ( array() !== Registry::forced_off() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( in_array( '*', Registry::forced_off(), true ) ? __( 'Modalità sicura: wp-config.php definisce XB_SAFE_MODE, nessun modulo viene caricato.', 'xb' ) : sprintf( /* translators: %s: module ids */ __( 'wp-config.php tiene spenti questi moduli (XB_DISABLE_MODULES): %s.', 'xb' ), implode( ', ', Registry::forced_off() ) ) ) . '</p></div>';
		}
		echo '<div class="xb-modules">';
		foreach ( Registry::all() as $module ) {
			self::card( $module );
		}
		echo '</div>';
		Menu::close();
	}

	private static function card( Module $module ): void {
		$status = Registry::status( $module );
		$labels = array(
			Registry::ON         => array( 'is-on', __( 'Attivo', 'xb' ) ),
			Registry::OFF        => array( 'is-off', __( 'Spento', 'xb' ) ),
			Registry::STANDALONE => array( 'is-standalone', __( 'Attivo come plugin separato', 'xb' ) ),
			/* translators: %s: PHP version */
			Registry::PHP        => array( 'is-blocked', sprintf( __( 'Richiede PHP %s', 'xb' ), $module->php ) ),
			Registry::PLANNED    => array( 'is-planned', __( 'In arrivo come modulo', 'xb' ) ),
			Registry::PAUSED     => array( 'is-blocked', __( 'Spento dopo un errore fatale', 'xb' ) ),
			Registry::FORCED     => array( 'is-blocked', __( 'Spento da wp-config.php', 'xb' ) ),
		);
		[ $class, $label ] = $labels[ $status ];
		?>
		<section class="xb-card xb-module <?php echo esc_attr( $class ); ?>" aria-labelledby="xb-module-<?php echo esc_attr( $module->id ); ?>">
			<?php if ( '' !== $module->logo ) : ?>
				<img class="xb-module-logo" src="<?php echo esc_url( $module->logo ); ?>" alt="" width="52" height="52">
			<?php else : ?>
				<div class="xb-module-icon" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr( $module->icon ); ?>"></span></div>
			<?php endif; ?>
			<div class="xb-module-main">
				<div class="xb-module-title">
					<h2 id="xb-module-<?php echo esc_attr( $module->id ); ?>"><?php echo esc_html( $module->name ); ?></h2>
					<span class="xb-module-state"><?php echo esc_html( $label ); ?></span>
				</div>
				<p><?php echo esc_html( $module->description ); ?></p>
				<?php $error = Registry::PAUSED === $status ? \XB\Modules\FatalGuard::paused( $module->id ) : null; ?>
				<?php if ( null !== $error ) : ?>
					<p class="xb-module-error"><?php echo esc_html( sprintf( /* translators: 1: date, 2: file, 3: line, 4: message */ __( 'Il %1$s: %2$s, riga %3$d — %4$s. Correggi o aggiorna il modulo, poi riaccendilo.', 'xb' ), wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $error['time'] ), $error['file'], $error['line'], $error['message'] ) ); ?></p>
				<?php endif; ?>
				<p class="xb-module-meta">
					<?php
					$meta = array();
					if ( '' !== $module->version ) {
						/* translators: %s: version */
						$meta[] = sprintf( __( 'Versione %s', 'xb' ), $module->version );
					}
					/* translators: %s: PHP version */
					$meta[] = sprintf( __( 'PHP %s+', 'xb' ), $module->php );
					if ( Registry::STANDALONE === $status ) {
						$meta[] = __( 'Gestito dalla pagina Plugin finché resta attivo da solo', 'xb' );
					} elseif ( Registry::PLANNED === $status ) {
						$meta[] = __( 'Oggi si usa come plugin separato', 'xb' );
					}
					echo esc_html( implode( ' · ', $meta ) );
					?>
				</p>
			</div>
			<div class="xb-module-actions">
				<?php if ( in_array( $status, array( Registry::ON, Registry::OFF, Registry::PAUSED ), true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Registry::ACTION ); ?>">
						<input type="hidden" name="module" value="<?php echo esc_attr( $module->id ); ?>">
						<input type="hidden" name="on" value="<?php echo Registry::ON === $status ? '0' : '1'; ?>">
						<?php wp_nonce_field( Registry::ACTION . '_' . $module->id ); ?>
						<button class="xb-switch" role="switch" aria-checked="<?php echo Registry::ON === $status ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: module */ Registry::ON === $status ? __( 'Spegni %s', 'xb' ) : __( 'Accendi %s', 'xb' ), $module->name ) ); ?>"><span class="xb-switch-knob" aria-hidden="true"></span></button>
					</form>
					<?php if ( Registry::ON === $status && '' !== $module->settings ) : ?>
						<a class="xb-btn xb-btn--small xb-btn--ghost" href="<?php echo esc_url( $module->settings ); ?>"><?php esc_html_e( 'Impostazioni', 'xb' ); ?></a>
					<?php endif; ?>
				<?php elseif ( Registry::STANDALONE === $status ) : ?>
					<a class="xb-btn xb-btn--small xb-btn--ghost" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Plugin', 'xb' ); ?></a>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	public static function toggle(): void {
		$id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		check_admin_referer( Registry::ACTION . '_' . $id );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per gestire i moduli di Histeon.', 'xb' ), 403 );
		}
		$on = isset( $_POST['on'] ) && '1' === $_POST['on'];
		try {
			Registry::set( $id, $on );
			$module = Registry::get( $id );
			set_transient( 'xb_admin_error_' . get_current_user_id(), sprintf( /* translators: %s: module */ $on ? __( '%s acceso.', 'xb' ) : __( '%s spento: i suoi dati restano.', 'xb' ), null === $module ? $id : $module->name ), MINUTE_IN_SECONDS );
			$code = 'done';
		} catch ( \InvalidArgumentException $e ) {
			set_transient( 'xb_admin_error_' . get_current_user_id(), $e->getMessage(), MINUTE_IN_SECONDS );
			$code = 'error';
		}
		wp_safe_redirect( Menu::url( self::SLUG, array( 'xb-done' => $code ) ) );
		exit;
	}
}
