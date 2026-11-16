<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Modules\Registry;
use XB\Plugin;

/**
 * Histeon → Primo avvio — docs/spec/setup.md. One page for the choices that were spread over three screens: the CSS
 * framework (Histeon CSS recommended), the modules to turn on, and the imports Histeon found (Etch content, Automatic.css).
 * Imports are never run from here: the page links to where they are prepared, in development, with their preview.
 * Hidden from the menu once done; Impostazioni keeps a link to open it again.
 */
final class SetupScreen {

	public const SLUG   = 'xb-setup';
	public const ACTION = 'xb_setup_save';
	/** Option: when the first run was completed or skipped (time), and by whom. */
	public const DONE = 'xb_setup_done';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	public static function done(): bool {
		return false !== get_option( self::DONE, false );
	}

	/** A site that has not made its choices yet (a framework chosen elsewhere counts as configured). */
	public static function needed(): bool {
		return ! self::done() && ! Plugin::instance()->frameworks->chosen();
	}

	/** Listed under Histeon only while needed; the URL keeps working afterwards (Impostazioni links to it). */
	public static function menu(): void {
		add_submenu_page( self::needed() ? Menu::SLUG : '', __( 'Histeon — Primo avvio', 'xb' ), __( 'Primo avvio', 'xb' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * What Histeon found on the site that is worth importing.
	 *
	 * @return array{etch: bool, acss: bool}
	 */
	public static function found(): array {
		$acss = Plugin::instance()->frameworks->all()['acss'] ?? null;
		return array(
			'etch' => \XB\Import\Etch\Migration::detected(),
			'acss' => null !== $acss && ( $acss->available() || \XB\Framework\AcssLegacy::needed() ),
		);
	}

	public static function render(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		$frameworks = Plugin::instance()->frameworks;
		$active     = $frameworks->active()->id();
		$found      = self::found();
		$modules    = array_filter( Registry::all(), static fn ( \XB\Modules\Module $m ): bool => $m->bundled && \XB\Framework\HistosCss\Module::ID !== $m->id );
		Menu::open( self::SLUG, __( 'Primo avvio', 'xb' ), __( 'Tre scelte e Histeon è pronto. Si possono cambiare in ogni momento da Impostazioni e Moduli.', 'xb' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-setup">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

			<section class="xb-panel">
				<div class="xb-panel-head"><span class="xb-step" aria-hidden="true">1</span><div>
					<h2><?php esc_html_e( 'Il framework CSS', 'xb' ); ?></h2>
					<p><?php esc_html_e( 'Le variabili e le classi che il builder propone. Histeon CSS è libero e incluso: token, generatore, recipes.', 'xb' ); ?></p>
				</div></div>
				<div class="xb-panel-body">
					<?php foreach ( $frameworks->all() as $provider ) : ?>
						<label class="xb-choice">
							<input type="radio" name="framework" value="<?php echo esc_attr( $provider->id() ); ?>" <?php checked( $active, $provider->id() ); ?> <?php disabled( ! $provider->available() ); ?>>
							<strong><?php echo esc_html( $provider->label() ); ?></strong>
							<?php if ( 'xb-tokens' === $provider->id() ) : ?><span class="xb-pill is-dev"><?php esc_html_e( 'consigliato', 'xb' ); ?></span><?php endif; ?>
							<?php if ( ! $provider->available() ) : ?><span class="description"><?php esc_html_e( 'non installato', 'xb' ); ?></span><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="xb-panel">
				<div class="xb-panel-head"><span class="xb-step" aria-hidden="true">2</span><div>
					<h2><?php esc_html_e( 'I moduli', 'xb' ); ?></h2>
					<p><?php esc_html_e( 'Funzioni incluse in Histeon, da accendere solo se servono. Spegnerle non cancella i loro dati.', 'xb' ); ?></p>
				</div></div>
				<div class="xb-panel-body">
					<?php if ( array() === $modules ) : ?>
						<p class="xb-muted"><?php esc_html_e( 'Questa copia di Histeon non include moduli: Lingua Bridge e Static Bridge si possono installare come plugin separati.', 'xb' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $modules as $module ) : ?>
						<?php $status = Registry::status( $module ); ?>
						<label class="xb-choice">
							<input type="checkbox" name="modules[]" value="<?php echo esc_attr( $module->id ); ?>" <?php checked( Registry::ON === $status ); ?> <?php disabled( ! in_array( $status, array( Registry::ON, Registry::OFF, Registry::PAUSED ), true ) ); ?>>
							<strong><?php echo esc_html( $module->name ); ?></strong>
							<span class="description"><?php echo esc_html( $module->description ); ?></span>
							<?php if ( Registry::STANDALONE === $status ) : ?><span class="xb-pill"><?php esc_html_e( 'attivo come plugin separato', 'xb' ); ?></span><?php endif; ?>
							<?php if ( Registry::PHP === $status ) : ?><span class="xb-pill is-warn"><?php echo esc_html( sprintf( /* translators: %s: PHP version */ __( 'serve PHP %s', 'xb' ), $module->php ) ); ?></span><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="xb-panel">
				<div class="xb-panel-head"><span class="xb-step" aria-hidden="true">3</span><div>
					<h2><?php esc_html_e( 'Da dove arrivi', 'xb' ); ?></h2>
					<p><?php esc_html_e( 'Il sito continua a funzionare anche senza importare. Importare è consigliato: tutto viene preparato in sviluppo, con l\'anteprima, e va online solo con una release.', 'xb' ); ?></p>
				</div></div>
				<div class="xb-panel-body">
					<?php if ( ! $found['etch'] && ! $found['acss'] ) : ?>
						<p class="xb-muted"><?php esc_html_e( 'Niente da importare: né contenuti di Etch né Automatic.css su questo sito.', 'xb' ); ?></p>
					<?php endif; ?>
					<?php if ( $found['etch'] ) : ?>
						<p><strong><?php esc_html_e( 'Contenuti di Etch', 'xb' ); ?></strong> — <?php esc_html_e( 'pagine, componenti, stili e query diventano di Histeon.', 'xb' ); ?>
						<a class="xb-btn xb-btn--small" href="<?php echo esc_url( SettingsScreen::url() . '#xb-etch' ); ?>"><?php esc_html_e( 'Prepara l\'importazione', 'xb' ); ?></a></p>
					<?php endif; ?>
					<?php if ( $found['acss'] ) : ?>
						<p><strong><?php esc_html_e( 'Automatic.css', 'xb' ); ?></strong> — <?php esc_html_e( 'variabili, classi e recipes passano a Histeon CSS, con gli stessi valori.', 'xb' ); ?>
						<a class="xb-btn xb-btn--small" href="<?php echo esc_url( SettingsScreen::url() . '#xb-migration' ); ?>"><?php esc_html_e( 'Prepara la migrazione', 'xb' ); ?></a></p>
					<?php endif; ?>
				</div>
			</section>

			<p class="xb-setup-actions">
				<button class="xb-btn xb-btn--primary" name="finish" value="1"><?php esc_html_e( 'Salva e inizia', 'xb' ); ?></button>
				<button class="xb-btn xb-btn--ghost" name="skip" value="1"><?php esc_html_e( 'Salta per ora', 'xb' ); ?></button>
			</p>
		</form>
		<?php
		Menu::close();
	}

	public static function save(): void {
		check_admin_referer( self::ACTION );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		$back = Menu::url( Menu::SLUG );
		if ( empty( $_POST['skip'] ) ) {
			$framework = isset( $_POST['framework'] ) ? sanitize_key( wp_unslash( (string) $_POST['framework'] ) ) : '';
			if ( '' !== $framework && $framework !== Plugin::instance()->frameworks->active()->id() ) {
				if ( ! Plugin::instance()->frameworks->select( $framework ) ) {
					Menu::back( self::url(), 'error', __( 'Framework non disponibile.', 'xb' ) );
				}
				Plugin::instance()->versioning->record_live( \XB\Versioning\Documents::SETTINGS );
				Plugin::instance()->versioning->store()->audit( 'framework', 'settings', array( 'framework' => $framework ) );
			} elseif ( '' !== $framework ) {
				Plugin::instance()->frameworks->select( $framework ); // The default made explicit: the choice is done.
			}
			$wanted   = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['modules'] ) ) : array();
			$problems = array();
			foreach ( Registry::all() as $module ) {
				if ( ! $module->bundled || \XB\Framework\HistosCss\Module::ID === $module->id ) {
					continue;
				}
				$status = Registry::status( $module );
				if ( ! in_array( $status, array( Registry::ON, Registry::OFF, Registry::PAUSED ), true ) ) {
					continue;
				}
				try {
					Registry::set( $module->id, in_array( $module->id, $wanted, true ) );
				} catch ( \InvalidArgumentException $e ) {
					$problems[] = $module->name . ': ' . $e->getMessage();
				}
			}
			if ( array() !== $problems ) {
				update_option( self::DONE, array( 'time' => time(), 'user' => get_current_user_id() ), false );
				Menu::back( $back, 'error', implode( ' ', $problems ) );
			}
		}
		update_option( self::DONE, array( 'time' => time(), 'user' => get_current_user_id() ), false );
		Menu::back( $back, 'done', empty( $_POST['skip'] ) ? __( 'Histeon è pronto.', 'xb' ) : __( 'Primo avvio saltato: lo ritrovi in Impostazioni.', 'xb' ) );
	}
}
