<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Framework\AcssLegacy;
use XB\Import\Etch\Migration as EtchMigration;

/**
 * The reminder to import — docs/spec/histos-css.md §4: a site that still relies on Automatic.css's last sheets
 * (AcssLegacy) or still has Etch content is told, on the admin screens, that importing into Histeon and Histeon CSS
 * is recommended, with a link to do it. Never required. «Più tardi» hides it for this user for 14 days.
 */
final class LegacyReminder {

	public const ACTION = 'xb_legacy_reminder_later';
	public const META   = 'xb_legacy_reminder_until';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'later' ) );
	}

	/** @return list<string> What is still to import, in words. */
	public static function pending(): array {
		$out = array();
		if ( AcssLegacy::needed() ) {
			$out[] = __( 'Automatic.css è spento: Histeon serve i suoi ultimi fogli, così il sito resta com\'era.', 'xb' );
		}
		if ( EtchMigration::has_content() ) {
			$out[] = __( 'Ci sono contenuti salvati da Etch non ancora importati.', 'xb' );
		}
		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'xb_build' ) ) {
			return;
		}
		if ( (int) get_user_meta( get_current_user_id(), self::META, true ) > time() ) {
			return;
		}
		$pending = self::pending();
		if ( array() === $pending ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Histeon: importazione consigliata', 'xb' ) . '</strong></p><ul style="list-style:disc;margin-left:1.4em">';
		foreach ( $pending as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'Importare in Histeon e Histeon CSS non è obbligatorio, ma è consigliato: il sito non dipende più da plugin spenti, e i suoi stili diventano modificabili nel builder e nel pannello Histeon CSS. Tutto arriva come versione di sviluppo, da controllare e pubblicare.', 'xb' ) . '</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( SettingsScreen::url() . '#xb-etch' ) . '">' . esc_html__( 'Importa ora', 'xb' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ) . '">' . esc_html__( 'Più tardi', 'xb' ) . '</a>';
		echo '</p></div>';
	}

	public static function later(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'xb' ), 403 );
		}
		self::postpone( get_current_user_id() );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/** The reminder comes back for this user in 14 days. */
	public static function postpone( int $user_id ): void {
		update_user_meta( $user_id, self::META, time() + 14 * DAY_IN_SECONDS );
	}
}
