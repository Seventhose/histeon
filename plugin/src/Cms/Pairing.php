<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Cms;

use XB\Admin\Menu;
use XB\Admin\ReleasesScreen;
use XB\Deploy\Credentials;
use XB\Plugin;
use XB\Remote\Link as RemoteLink;

/**
 * Pairing with Histeon Publish in one click (doc 23 item 237 of Histeon Publish), as OAuth's PKCE:
 *
 * 1. Publish opens Release → «Collega Histeon Publish» with `xb_pair` (the SHA-256 of a verifier only Publish knows,
 *    base64url) and `xb_pair_state` (its identifier of the pairing).
 * 2. An administrator sees what Publish asks, chooses the permissions and confirms: the pairing code is made as for
 *    «Crea il codice di abbinamento», and kept for five minutes under a one-time claim tied to the challenge.
 * 3. The button back to Publish (`histos-publish://collega?state=…&claim=…`) carries only the claim, worthless
 *    without the verifier: no secret goes through the browser, the system's link handler or a command line.
 * 4. Publish claims the code (POST `/cms/v1/pair` with the claim and the verifier): the site answers once, and
 *    forgets the claim whatever the answer.
 *
 * The manual code stays as it was, for a site Publish cannot reach from this computer.
 */
final class Pairing {

	private const ACTION  = 'xb_admin_cms_pair';
	public const ROUTE    = '/cms/v1/pair';
	private const TTL     = 5 * MINUTE_IN_SECONDS;
	private const SCHEME  = 'histos-publish';
	/** Refused claims counted across the site, at most this many in ten minutes. */
	private const MAX_FAILURES = 30;

	public static function register_admin(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'confirm' ) );
	}

	public static function register_rest(): void {
		register_rest_route(
			\XB\Rest\Controller::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'claim' ),
				// The claim and the verifier are the authentication: checked in claim(), once.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The pairing Publish asks for, from the page's address: the challenge and Publish's identifier, or null.
	 *
	 * @return array{challenge: string, state: string}|null
	 */
	public static function requested(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read to show a form; the confirmation has its nonce.
		$challenge = isset( $_GET['xb_pair'] ) ? sanitize_text_field( wp_unslash( $_GET['xb_pair'] ) ) : '';
		$state     = isset( $_GET['xb_pair_state'] ) ? sanitize_text_field( wp_unslash( $_GET['xb_pair_state'] ) ) : '';
		// phpcs:enable
		return self::valid_challenge( $challenge ) && self::valid_state( $state ) ? array( 'challenge' => $challenge, 'state' => $state ) : null;
	}

	/** Base64url of 32 bytes: 43 characters. */
	private static function valid_challenge( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $value );
	}

	private static function valid_state( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value );
	}

	private static function return_key(): string {
		return 'xb_cms_pair_return_' . get_current_user_id();
	}

	/**
	 * The confirmation, at the top of «Collega Histeon Publish»: what Publish asks, the permissions, the yes. When a
	 * pairing was just confirmed, the button back to Publish instead.
	 */
	public static function render(): void {
		$back = get_transient( self::return_key() );
		if ( is_string( $back ) ) {
			delete_transient( self::return_key() );
			?>
			<div class="xb-panel" style="border-color:var(--xb-accent)">
				<div class="xb-panel-body">
					<p><strong><?php esc_html_e( 'Collegamento pronto: torna a Histeon Publish per finirlo.', 'xb' ); ?></strong></p>
					<p><a class="xb-btn xb-btn--primary" href="<?php echo esc_url( $back, array( self::SCHEME ) ); ?>"><?php esc_html_e( 'Apri Histeon Publish', 'xb' ); ?></a></p>
					<p class="xb-muted"><?php esc_html_e( 'Histeon Publish ti chiede ancora un sì, poi ritira il collegamento da qui. Vale cinque minuti e una volta sola; il segreto non passa dal browser.', 'xb' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}
		$request = self::requested();
		if ( null === $request ) {
			return;
		}
		$replaces = null !== Link::get();
		?>
		<div class="xb-panel" id="xb-cms-pair" style="border-color:var(--xb-accent)">
			<div class="xb-panel-body">
				<h3><?php esc_html_e( 'Histeon Publish chiede di collegarsi a questo sito', 'xb' ); ?></h3>
				<p><?php esc_html_e( 'Hai avviato il collegamento da Histeon Publish su questo computer? Se non l’hai avviato tu, chiudi questa pagina.', 'xb' ); ?></p>
				<?php if ( $replaces ) : ?>
					<p><span class="xb-pill is-warn"><?php esc_html_e( 'Il collegamento attuale smette di funzionare: ce n’è uno solo per sito.', 'xb' ); ?></span></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="challenge" value="<?php echo esc_attr( $request['challenge'] ); ?>">
					<input type="hidden" name="state" value="<?php echo esc_attr( $request['state'] ); ?>">
					<p><label><?php esc_html_e( 'Nome del progetto', 'xb' ); ?> <input name="label" maxlength="80" placeholder="<?php esc_attr_e( 'Sito principale', 'xb' ); ?>"></label></p>
					<fieldset>
						<legend><?php esc_html_e( 'Cosa può fare Histeon Publish', 'xb' ); ?></legend>
						<p><label><input type="checkbox" name="read" value="1" checked> <?php esc_html_e( 'Scaricare il sito (pagine, componenti, stili, contenuti, media)', 'xb' ); ?></label></p>
						<p><label><input type="checkbox" name="write" value="1"> <?php esc_html_e( 'Inviare modifiche: arrivano in sviluppo, il sito online non cambia', 'xb' ); ?></label></p>
						<?php if ( current_user_can( 'xb_publish' ) ) : ?>
							<p><label><input type="checkbox" name="publish" value="1"> <?php esc_html_e( 'Anche pubblicare ciò che invia (richiede «Inviare modifiche»)', 'xb' ); ?></label></p>
						<?php endif; ?>
					</fieldset>
					<p><button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Collega a Histeon Publish', 'xb' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}

	/** The administrator's yes: the code made and kept under a one-time claim, and the way back to Publish. */
	public static function confirm(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'xb_build' ) ) {
			wp_die( esc_html__( 'Solo un amministratore collega Histeon CMS.', 'xb' ), 403 );
		}
		$here = Menu::url( ReleasesScreen::SLUG ) . '#xb-cms';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$challenge = isset( $_POST['challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['challenge'] ) ) : '';
		$state     = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		$read      = isset( $_POST['read'] );
		$write     = isset( $_POST['write'] );
		$publish   = $write && isset( $_POST['publish'] ) && current_user_can( 'xb_publish' );
		$label     = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		// phpcs:enable
		if ( ! self::valid_challenge( $challenge ) || ! self::valid_state( $state ) ) {
			Menu::back( $here, 'error', __( 'Richiesta di collegamento non valida: ricomincia da Histeon Publish.', 'xb' ) );
		}
		if ( ! $read ) {
			Menu::back( $here, 'error', __( 'Histeon Publish deve poter scaricare il sito: lascia attivo «Scaricare il sito».', 'xb' ) );
		}
		$code  = Link::create( $label, $read, $write, $publish );
		$claim = bin2hex( random_bytes( 16 ) );
		set_transient(
			'xb_cms_pair_' . $claim,
			array(
				'code'      => Credentials::encrypt_with( $code, 'xb-cms-pairing-v1' ),
				'challenge' => $challenge,
			),
			self::TTL
		);
		set_transient( self::return_key(), self::SCHEME . '://collega?state=' . rawurlencode( $state ) . '&claim=' . $claim, self::TTL );
		Plugin::instance()->versioning->store()->audit(
			'cms_link',
			'cms',
			array(
				'read'    => $read,
				'write'   => $write,
				'publish' => $publish,
				'label'   => $label,
				'pairing' => true,
			)
		);
		Menu::back( $here, 'done', __( 'Collegamento preparato: apri Histeon Publish per finirlo.', 'xb' ) );
	}

	/** POST `/cms/v1/pair`: the code, once, to whoever has the claim and the verifier of its challenge. */
	public static function claim( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! is_ssl() && ! RemoteLink::local() ) {
			return new \WP_Error( 'xb_cms_https', __( 'Serve HTTPS.', 'xb' ), array( 'status' => 403 ) );
		}
		$failures = (int) get_transient( 'xb_cms_pair_failures' );
		if ( $failures >= self::MAX_FAILURES ) {
			return new \WP_Error( 'xb_cms_pair_busy', __( 'Troppi tentativi di collegamento: riprova fra qualche minuto.', 'xb' ), array( 'status' => 429 ) );
		}
		$body     = json_decode( (string) $request->get_body(), true );
		$claim    = is_array( $body ) && is_string( $body['claim'] ?? null ) ? $body['claim'] : '';
		$verifier = is_array( $body ) && is_string( $body['verifier'] ?? null ) ? $body['verifier'] : '';
		$stored   = 1 === preg_match( '/^[0-9a-f]{32}$/D', $claim ) ? get_transient( 'xb_cms_pair_' . $claim ) : false;
		if ( 1 === preg_match( '/^[0-9a-f]{32}$/D', $claim ) ) {
			// One try per claim, whatever the answer.
			delete_transient( 'xb_cms_pair_' . $claim );
		}
		$expected = is_array( $stored ) && is_string( $stored['challenge'] ?? null ) ? $stored['challenge'] : '';
		$given    = 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $verifier ) ? rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) : '';
		$code     = '' !== $expected && '' !== $given && hash_equals( $expected, $given ) ? Credentials::decrypt_with( (string) $stored['code'], 'xb-cms-pairing-v1' ) : '';
		if ( '' === $code || ! str_starts_with( $code, Link::CODE_PREFIX ) ) {
			set_transient( 'xb_cms_pair_failures', $failures + 1, 10 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'xb_cms_pair_refused', __( 'Collegamento non riconosciuto o scaduto: ricomincia da Histeon Publish.', 'xb' ), array( 'status' => 403 ) );
		}
		return new \WP_REST_Response( array( 'code' => $code ), 200, array( 'Cache-Control' => 'no-store' ) );
	}
}
