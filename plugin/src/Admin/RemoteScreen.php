<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Remote\Link;
use XB\Remote\Sender;

/**
 * Staging → production in Histeon → Release (docs/spec/remote.md), for administrators: on production, the pairing
 * code; on staging, the link, the dry run and the sending. Every action is a nonce-checked POST.
 */
final class RemoteScreen {

	private const CREATE = 'xb_admin_remote_create';
	private const REVOKE = 'xb_admin_remote_revoke';
	private const LINK   = 'xb_admin_remote_link';
	private const UNLINK = 'xb_admin_remote_unlink';
	private const SEND   = 'xb_admin_remote_send';

	public static function register(): void {
		foreach ( array( self::CREATE => 'create', self::REVOKE => 'revoke', self::LINK => 'link', self::UNLINK => 'unlink', self::SEND => 'send' ) as $action => $method ) {
			add_action( 'admin_post_' . $action, array( self::class, $method ) );
		}
	}

	private static function can(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Solo un amministratore collega i siti.', 'xb' ), 403 );
		}
	}

	private static function here(): string {
		return Menu::url( ReleasesScreen::SLUG ) . '#xb-remote';
	}

	private static function key( string $what ): string {
		return 'xb_remote_' . $what . '_' . get_current_user_id();
	}

	// ------------------------------------------------------------------ production side

	public static function create(): void {
		self::guard( self::CREATE );
		$publish = isset( $_POST['publish'] ) && current_user_can( 'xb_publish' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		try {
			$code = Link::create_inbound( $publish, $label );
		} catch ( \InvalidArgumentException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		// Shown once, to this user, for a few minutes: the code holds the secret.
		set_transient( self::key( 'code' ), $code, 10 * MINUTE_IN_SECONDS );
		\XB\Plugin::instance()->versioning->store()->audit( 'remote_link', 'remote', array( 'publish' => $publish, 'label' => $label ) );
		Menu::back( self::here(), 'done', __( 'Codice creato: copialo sul sito di staging. Non verrà più mostrato.', 'xb' ) );
	}

	public static function revoke(): void {
		self::guard( self::REVOKE );
		Link::revoke_inbound();
		delete_transient( self::key( 'code' ) );
		\XB\Plugin::instance()->versioning->store()->audit( 'remote_revoke', 'remote' );
		Menu::back( self::here(), 'done', __( 'Collegamento revocato: lo staging non può più inviare.', 'xb' ) );
	}

	// ------------------------------------------------------------------ staging side

	public static function link(): void {
		self::guard( self::LINK );
		$code = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in guard(); parsed and validated by Link.
		try {
			$url = Link::save_outbound( mb_substr( $code, 0, 2000 ) );
		} catch ( \InvalidArgumentException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		// Production keeps this site's address with the link (doc 23, voce 173).
		try {
			Sender::pair();
		} catch ( \RuntimeException $e ) {
			if ( 409 === $e->getCode() ) {
				Link::forget_outbound();
				Menu::back( self::here(), 'error', $e->getMessage() );
			}
			/* translators: 1: production site address, 2: error message */
			Menu::back( self::here(), 'done', sprintf( __( 'Collegato a %1$s, ma la produzione non ha confermato l\'abbinamento (%2$s): lo confermerà la prima anteprima.', 'xb' ), $url, $e->getMessage() ) );
		}
		/* translators: %s: production site address */
		Menu::back( self::here(), 'done', sprintf( __( 'Collegato a %s.', 'xb' ), $url ) );
	}

	public static function unlink(): void {
		self::guard( self::UNLINK );
		Link::forget_outbound();
		delete_transient( self::key( 'plan' ) );
		Menu::back( self::here(), 'done', __( 'Sito di produzione scollegato.', 'xb' ) );
	}

	/** A dry run (`write` 0) shows the plan; a send (`write` 1) imports on production. */
	public static function send(): void {
		self::guard( self::SEND );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$scope = isset( $_POST['scope'] ) && Sender::SCOPE_SITE === $_POST['scope'] ? Sender::SCOPE_SITE : Sender::SCOPE_RELEASE;
		$write = isset( $_POST['write'] ) && '1' === $_POST['write'];
		$trash = isset( $_POST['trash'] ) && '1' === $_POST['trash'];
		// phpcs:enable
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- packs and sends the media too.
		}
		try {
			$report = Sender::send( $scope, $write, $trash );
		} catch ( \RuntimeException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		if ( ! $write ) {
			set_transient( self::key( 'plan' ), array( 'scope' => $scope, 'report' => $report ), 30 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'remote', 'plan', Menu::url( ReleasesScreen::SLUG ) ) . '#xb-remote' );
			exit;
		}
		delete_transient( self::key( 'plan' ) );
		$message = sprintf(
			/* translators: 1: contents, 2: media files */
			__( 'Inviato alla produzione: %1$d contenuti, %2$d media.', 'xb' ),
			count( $report['contents'] ),
			$report['media']
		);
		/* translators: %s: version */
		$message .= '' !== $report['published'] ? ' ' . sprintf( __( 'Pubblicato là come release %s.', 'xb' ), $report['published'] ) : ' ' . __( 'Là è in sviluppo: va pubblicato dalla produzione.', 'xb' );
		if ( $report['deleted'] > 0 ) {
			/* translators: %d: contents */
			$message .= ' ' . sprintf( _n( '%d contenuto tolto dallo staging è nel Cestino della produzione.', '%d contenuti tolti dallo staging sono nel Cestino della produzione.', $report['deleted'], 'xb' ), $report['deleted'] );
		}
		Menu::back( self::here(), 'done', $message . ( array() !== $report['issues'] ? ' ' . implode( ' · ', $report['issues'] ) : '' ) );
	}

	// ------------------------------------------------------------------ screen

	public static function render(): void {
		if ( ! self::can() ) {
			return;
		}
		$inbound  = Link::inbound();
		$outbound = Link::outbound();
		$code     = get_transient( self::key( 'code' ) );
		if ( is_string( $code ) ) {
			delete_transient( self::key( 'code' ) );
		}
		$post = admin_url( 'admin-post.php' );
		?>
		<h2 class="xb-section-title" id="xb-remote"><?php esc_html_e( 'Staging e produzione', 'xb' ); ?> <small><?php esc_html_e( 'manda dallo staging alla produzione ciò che è online, con i media', 'xb' ); ?></small></h2>
		<?php self::render_plan(); ?>
		<div class="xb-grid-2 xb-remote">
			<div class="xb-panel">
				<div class="xb-panel-head"><h2><?php esc_html_e( 'Invia a produzione', 'xb' ); ?></h2><span class="xb-muted"><?php esc_html_e( 'su questo sito di staging', 'xb' ); ?></span></div>
				<div class="xb-panel-body">
					<?php if ( null === $outbound ) : ?>
						<form method="post" action="<?php echo esc_url( $post ); ?>">
							<?php wp_nonce_field( self::LINK ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::LINK ); ?>">
							<p><label for="xb-remote-code"><?php esc_html_e( 'Codice di abbinamento del sito di produzione', 'xb' ); ?></label></p>
							<textarea id="xb-remote-code" name="code" rows="3" class="large-text code" required autocomplete="off" spellcheck="false"></textarea>
							<p><button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Collega', 'xb' ); ?></button></p>
						</form>
					<?php else : ?>
						<p><?php echo esc_html( sprintf( /* translators: %s: production address */ __( 'Collegato a %s.', 'xb' ), $outbound['url'] ) ); ?></p>
						<form method="post" action="<?php echo esc_url( $post ); ?>">
							<?php wp_nonce_field( self::SEND ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::SEND ); ?>">
							<input type="hidden" name="write" value="0">
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Cosa inviare', 'xb' ); ?></legend>
								<label><input type="radio" name="scope" value="<?php echo esc_attr( Sender::SCOPE_RELEASE ); ?>" checked> <?php echo esc_html( sprintf( /* translators: %d: documents */ _n( 'Ciò che ha cambiato l\'ultima release (%d documento)', 'Ciò che ha cambiato l\'ultima release (%d documenti)', count( Sender::release_docs() ), 'xb' ), count( Sender::release_docs() ) ) ); ?></label><br>
								<label><input type="radio" name="scope" value="<?php echo esc_attr( Sender::SCOPE_SITE ); ?>"> <?php esc_html_e( 'Tutto il sito', 'xb' ); ?></label>
							</fieldset>
							<p><button class="xb-btn xb-btn--primary"><?php echo Menu::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Anteprima sulla produzione', 'xb' ); ?></button></p>
						</form>
						<form method="post" action="<?php echo esc_url( $post ); ?>">
							<?php wp_nonce_field( self::UNLINK ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::UNLINK ); ?>">
							<button class="xb-btn xb-btn--small"><?php esc_html_e( 'Scollega', 'xb' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>
			<div class="xb-panel">
				<div class="xb-panel-head"><h2><?php esc_html_e( 'Ricevi da uno staging', 'xb' ); ?></h2><span class="xb-muted"><?php esc_html_e( 'su questo sito di produzione', 'xb' ); ?></span></div>
				<div class="xb-panel-body">
					<?php if ( is_string( $code ) ) : ?>
						<p><label for="xb-remote-new"><strong><?php esc_html_e( 'Il codice di abbinamento (solo adesso: non verrà più mostrato)', 'xb' ); ?></strong></label></p>
						<textarea id="xb-remote-new" rows="3" class="large-text code" readonly><?php echo esc_textarea( $code ); ?></textarea>
						<p class="xb-muted"><?php esc_html_e( 'Incollalo nello staging, in Histeon → Release → Invia a produzione. Contiene il segreto del collegamento: non mandarlo per email in chiaro.', 'xb' ); ?></p>
					<?php endif; ?>
					<?php if ( null === $inbound ) : ?>
						<form method="post" action="<?php echo esc_url( $post ); ?>">
							<?php wp_nonce_field( self::CREATE ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE ); ?>">
							<p><label><?php esc_html_e( 'Nome dello staging', 'xb' ); ?> <input name="label" maxlength="80" placeholder="<?php esc_attr_e( 'staging.esempio.it', 'xb' ); ?>"></label></p>
							<?php if ( current_user_can( 'xb_publish' ) ) : ?>
								<p><label><input type="checkbox" name="publish" value="1"> <?php esc_html_e( 'Lo staging può anche pubblicare (altrimenti tutto arriva in sviluppo)', 'xb' ); ?></label></p>
							<?php endif; ?>
							<p><button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Crea il codice di abbinamento', 'xb' ); ?></button></p>
						</form>
					<?php else : ?>
						<ul class="xb-facts">
							<li><?php echo esc_html( '' !== $inbound['label'] ? $inbound['label'] : __( 'Uno staging', 'xb' ) ); ?> · <?php echo esc_html( sprintf( /* translators: %s: user name */ __( 'collegato da %s', 'xb' ), Menu::user_name( $inbound['user'] ) ) ); ?></li>
							<li><?php echo $inbound['publish'] ? esc_html__( 'può importare e pubblicare', 'xb' ) : esc_html__( 'può importare in sviluppo, non pubblicare', 'xb' ); ?></li>
							<li><?php echo '' !== $inbound['origin'] ? esc_html( sprintf( /* translators: %s: staging site address */ __( 'accetta pacchetti solo da %s', 'xb' ), $inbound['origin'] ) ) : esc_html__( 'abbinamento non ancora confermato dallo staging', 'xb' ); ?></li>
							<li><?php echo $inbound['last'] > 0 ? esc_html( sprintf( /* translators: %s: date */ __( 'ultimo invio: %s', 'xb' ), (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $inbound['last'] ) ) ) : esc_html__( 'nessun invio finora', 'xb' ); ?></li>
						</ul>
						<form method="post" action="<?php echo esc_url( $post ); ?>">
							<?php wp_nonce_field( self::REVOKE ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE ); ?>">
							<button class="xb-btn"><?php esc_html_e( 'Revoca il collegamento', 'xb' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/** The dry run's answer from production, and the button that sends for real. */
	private static function render_plan(): void {
		$stored = isset( $_GET['remote'] ) ? get_transient( self::key( 'plan' ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of the user's own pending plan.
		if ( ! is_array( $stored ) || ! is_array( $stored['report'] ?? null ) ) {
			return;
		}
		$report = Sender::report( $stored['report'] );
		$rows   = array_merge( $report['documents'], $report['contents'] );
		?>
		<div class="xb-panel" style="border-color:var(--xb-accent)">
			<div class="xb-panel-head">
				<div>
					<h2><?php echo esc_html( sprintf( /* translators: %s: production address */ __( 'Cosa cambierebbe su %s', 'xb' ), $report['site'] ) ); ?></h2>
					<p class="xb-muted"><?php echo esc_html( sprintf( /* translators: %d: media files */ __( 'Con %d media. Nulla è ancora stato scritto.', 'xb' ), $report['media'] ) ); ?> <?php echo $report['canPublish'] ? esc_html__( 'La produzione pubblicherà subito.', 'xb' ) : esc_html__( 'La produzione riceverà tutto in sviluppo.', 'xb' ); ?></p>
				</div>
			</div>
			<table class="xb-table"><tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr><td><strong><?php echo esc_html( $row['label'] ); ?></strong></td><td><span class="xb-pill is-dev"><?php echo esc_html( $row['action'] ); ?></span></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<div class="xb-panel-body" style="border-top:1px solid var(--xb-line)">
				<?php foreach ( $report['issues'] as $issue ) : ?>
					<p><span class="xb-pill is-warn"><?php echo esc_html( $issue ); ?></span></p>
				<?php endforeach; ?>
				<?php if ( array() !== $report['deletions'] ) : ?>
					<h3><?php echo esc_html( sprintf( /* translators: %d: contents */ _n( '%d contenuto non c\'è più sullo staging', '%d contenuti non ci sono più sullo staging', count( $report['deletions'] ), 'xb' ), count( $report['deletions'] ) ) ); ?></h3>
					<p class="xb-muted"><?php esc_html_e( 'Erano arrivati in produzione da questo staging. Solo se lo scegli qui sotto finiscono nel Cestino della produzione, da cui si recuperano; i contenuti scritti in produzione non sono mai toccati.', 'xb' ); ?></p>
					<ul>
						<?php foreach ( $report['deletions'] as $gone ) : ?>
							<li><?php echo esc_html( $gone ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::SEND ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SEND ); ?>">
					<input type="hidden" name="write" value="1">
					<?php if ( array() !== $report['deletions'] ) : ?>
						<p><label><input type="checkbox" name="trash" value="1"> <?php esc_html_e( 'Sposta anche questi contenuti nel Cestino della produzione', 'xb' ); ?></label></p>
					<?php endif; ?>
					<input type="hidden" name="scope" value="<?php echo esc_attr( Sender::SCOPE_SITE === ( $stored['scope'] ?? '' ) ? Sender::SCOPE_SITE : Sender::SCOPE_RELEASE ); ?>">
					<button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Invia alla produzione', 'xb' ); ?></button>
					<a class="xb-btn" href="<?php echo esc_url( Menu::url( ReleasesScreen::SLUG ) . '#xb-remote' ); ?>"><?php esc_html_e( 'Annulla', 'xb' ); ?></a>
				</form>
			</div>
		</div>
		<?php
	}
}
