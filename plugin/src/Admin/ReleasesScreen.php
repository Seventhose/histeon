<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Plugin;
use XB\Rest\Controller;
use XB\Versioning\Scheduled;
use XB\Versioning\SiteTransfer;
use XB\Versioning\Transfer;
use XB\Versioning\Versioning;
use XB\Versioning\VersioningException;

/**
 * XB → Release: what is waiting in development and the published releases, with the same operations and the
 * same permissions as the builder's Versions panel — publish (xb_publish), restore (xb_publish), export and
 * import (administrators). Every action is a POST (or a nonce-signed download) checked again on arrival.
 */
final class ReleasesScreen {

	public const SLUG     = 'xb-releases';
	/** Option: publish only from the builder, after the page checks found no error (docs/spec/checks.md). */
	public const CHECKS_BLOCK = 'xb_checks_block';
	private const PUBLISH = 'xb_admin_publish';
	private const RESTORE = 'xb_admin_restore';
	private const EXPORT  = 'xb_admin_export';
	private const IMPORT  = 'xb_admin_import';
	private const CONFIRM = 'xb_admin_import_confirm';
	private const ZIP     = 'xb_admin_static_zip';
	private const SITE_EXPORT  = 'xb_admin_site_export';
	private const SITE_IMPORT  = 'xb_admin_site_import';
	private const SITE_CONFIRM = 'xb_admin_site_confirm';
	/** One upload for every file the site can import: the screen recognises it and opens the right check. */
	private const IMPORT_FILE  = 'xb_admin_import_file';
	/** The scheduled release (docs/spec/versioning.md §11): cancel it, or close the line with its outcome. */
	private const SCHEDULE_CANCEL  = 'xb_admin_schedule_cancel';
	private const SCHEDULE_DISMISS = 'xb_admin_schedule_dismiss';
	/** A whole site with its media; the server's own upload limit usually comes first. */
	private const SITE_MAX = 1024 * 1024 * 1024;
	/** Kept between the check and the confirmation; larger files are imported from the builder. */
	private const IMPORT_MAX = 8 * 1024 * 1024;

	public static function register(): void {
		add_action( 'admin_post_' . self::PUBLISH, array( self::class, 'publish' ) );
		add_action( 'admin_post_' . self::RESTORE, array( self::class, 'restore' ) );
		add_action( 'admin_post_' . self::EXPORT, array( self::class, 'export' ) );
		add_action( 'admin_post_' . self::IMPORT, array( self::class, 'import' ) );
		add_action( 'admin_post_' . self::CONFIRM, array( self::class, 'confirm_import' ) );
		add_action( 'admin_post_' . self::ZIP, array( self::class, 'static_zip' ) );
		add_action( 'admin_post_' . self::SITE_EXPORT, array( self::class, 'site_export' ) );
		add_action( 'admin_post_' . self::SITE_IMPORT, array( self::class, 'site_import' ) );
		add_action( 'admin_post_' . self::SITE_CONFIRM, array( self::class, 'site_confirm' ) );
		add_action( 'admin_post_' . self::IMPORT_FILE, array( self::class, 'import_file' ) );
		add_action( 'admin_post_' . self::SCHEDULE_CANCEL, array( self::class, 'cancel_schedule' ) );
		add_action( 'admin_post_' . self::SCHEDULE_DISMISS, array( self::class, 'dismiss_schedule' ) );
		RemoteScreen::register();
		CmsScreen::register();
	}

	/** @param string $bubble Count of documents waiting, as the admin menu shows counts. */
	public static function menu( string $bubble = '' ): void {
		add_submenu_page( Menu::SLUG, __( 'Release Histeon', 'xb' ), __( 'Release', 'xb' ) . $bubble, 'xb_build', self::SLUG, array( self::class, 'render' ) );
	}

	public static function checks_block(): bool {
		return (bool) get_option( self::CHECKS_BLOCK, false );
	}

	private static function can_publish(): bool {
		return current_user_can( 'xb_build' ) && current_user_can( 'xb_publish' );
	}

	private static function can_manage(): bool {
		return current_user_can( 'xb_build' ) && current_user_can( 'manage_options' );
	}

	private static function here(): string {
		return Menu::url( self::SLUG );
	}

	// ------------------------------------------------------------------ actions

	public static function publish(): void {
		check_admin_referer( self::PUBLISH );
		if ( ! self::can_publish() ) {
			wp_die( esc_html__( 'Serve il permesso di pubblicare.', 'xb' ), 403 );
		}
		if ( self::checks_block() ) {
			Menu::back( self::here(), 'error', __( 'Con i controlli prima di pubblicare attivi, si pubblica dal builder (Pubblica): lì le pagine passano dai controlli.', 'xb' ) );
		}
		$visible = array_column( Controller::visible_changes(), 'doc' );
		$chosen  = isset( $_POST['docs'] ) && is_array( $_POST['docs'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['docs'] ) ) : array();
		$docs    = array_values( array_intersect( $visible, $chosen ) );
		if ( array() === $docs ) {
			Menu::back( self::here(), 'error', __( 'Nessun documento scelto.', 'xb' ) );
		}
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$notes   = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		try {
			Plugin::instance()->versioning->publish( '' === $version ? null : $version, $notes, $docs );
		} catch ( VersioningException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		Menu::back( self::here(), 'published' );
	}

	public static function restore(): void {
		check_admin_referer( self::RESTORE );
		if ( ! self::can_publish() ) {
			wp_die( esc_html__( 'Serve il permesso di pubblicare.', 'xb' ), 403 );
		}
		$id   = isset( $_POST['release'] ) ? absint( $_POST['release'] ) : 0;
		$keep = isset( $_POST['keep_content'] );
		try {
			Plugin::instance()->versioning->restore( $id, $keep );
		} catch ( VersioningException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		Menu::back( self::here(), 'restored' );
	}

	/** Cancels the scheduled release: whoever scheduled it, or anyone who may publish (as DELETE /releases/schedule). */
	public static function cancel_schedule(): void {
		check_admin_referer( self::SCHEDULE_CANCEL );
		$view = Scheduled::view();
		if ( ! current_user_can( 'xb_build' ) || ! ( current_user_can( 'xb_publish' ) || ( null !== $view && $view['mine'] ) ) ) {
			wp_die( esc_html__( 'Serve il permesso di pubblicare.', 'xb' ), 403 );
		}
		if ( ! Scheduled::cancel() ) {
			Menu::back( self::here(), 'error', __( 'Non c\'è nessuna release programmata.', 'xb' ) );
		}
		Menu::back( self::here(), 'done', __( 'Release programmata annullata.', 'xb' ) );
	}

	public static function dismiss_schedule(): void {
		check_admin_referer( self::SCHEDULE_DISMISS );
		if ( ! current_user_can( 'xb_build' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per usare Histeon.', 'xb' ), 403 );
		}
		Scheduled::dismiss_last();
		Menu::back( self::here(), 'done' );
	}

	/**
	 * The scheduled release at the top of the screen: the line with «Annulla», the outcome of the last one, and the
	 * warnings of §11 (no system cron, time zone changed). Scheduling itself happens only in the builder.
	 */
	public static function render_scheduled(): void {
		Scheduled::expire_overdue();
		$view = Scheduled::view();
		$last = Scheduled::last_view();
		if ( null === $view && null === $last ) {
			return;
		}
		?>
		<div class="xb-panel xb-scheduled" data-xb-scheduled>
			<div class="xb-panel-body">
			<?php if ( null !== $view ) : ?>
				<p class="xb-scheduled__line">
					<?php echo Menu::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<strong><?php echo esc_html( self::scheduled_line( $view ) ); ?></strong>
				</p>
				<?php if ( $view['tzChanged'] && '' !== $view['tzThen'] ) : ?>
					<p class="xb-muted"><?php echo esc_html( sprintf( /* translators: 1: date and time as written, 2: time zone */ __( 'Il fuso del sito è cambiato dopo la programmazione: erano le %1$s %2$s. L\'istante resta quello.', 'xb' ), $view['localThen'], $view['tzThen'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( current_user_can( 'xb_publish' ) || $view['mine'] ) : ?>
					<form class="xb-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Annullare la release programmata? Non andrà online nulla.', 'xb' ) ) ); ?>)">
						<?php wp_nonce_field( self::SCHEDULE_CANCEL ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::SCHEDULE_CANCEL ); ?>">
						<button class="xb-btn xb-btn--small"><?php esc_html_e( 'Annulla la programmazione', 'xb' ); ?></button>
					</form>
				<?php endif; ?>
				<?php self::render_cron_warning(); ?>
			<?php endif; ?>
			<?php if ( null !== $last ) : ?>
				<p class="xb-scheduled__last">
					<span class="xb-pill <?php echo 'published' === $last['state'] ? 'is-live' : 'is-warn'; ?>"><?php echo esc_html( self::last_line( $last ) ); ?></span>
				</p>
				<form class="xb-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::SCHEDULE_DISMISS ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SCHEDULE_DISMISS ); ?>">
					<button class="xb-btn xb-btn--small xb-btn--ghost"><?php esc_html_e( 'Chiudi', 'xb' ); ?></button>
				</form>
			<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * «Programmata per il … alle … da … · versione … · N documenti».
	 *
	 * @param array<string, mixed> $view Scheduled::view().
	 */
	public static function scheduled_line( array $view ): string {
		$at = (int) $view['at'];
		if ( ! $view['valid'] ) {
			/* translators: 1: date, 2: time */
			return sprintf( __( 'Release programmata per il %1$s alle %2$s, ma alterata: non partirà.', 'xb' ), wp_date( 'd/m/Y', $at ), wp_date( 'H:i', $at ) );
		}
		$count = count( (array) $view['docs'] ) + ( $view['media'] ? 1 : 0 ) + ( $view['contents'] ? 1 : 0 );
		/* translators: %d: number of documents */
		$docs = sprintf( _n( '%d documento', '%d documenti', $count, 'xb' ), $count );
		if ( '' === $view['userName'] ) {
			/* translators: 1: date, 2: time, 3: version, 4: number of documents */
			return sprintf( __( 'Programmata per il %1$s alle %2$s · versione %3$s · %4$s', 'xb' ), wp_date( 'd/m/Y', $at ), wp_date( 'H:i', $at ), (string) $view['version'], $docs );
		}
		/* translators: 1: date, 2: time, 3: user, 4: version, 5: number of documents */
		return sprintf( __( 'Programmata per il %1$s alle %2$s da %3$s · versione %4$s · %5$s', 'xb' ), wp_date( 'd/m/Y', $at ), wp_date( 'H:i', $at ), (string) $view['userName'], (string) $view['version'], $docs );
	}

	/**
	 * The outcome of the last scheduled release, in a sentence.
	 *
	 * @param array{state: string, code: string, reason: string, version: string, at: int, local: string, done: int, doneLocal: string, userName: string, release: int} $last
	 */
	public static function last_line( array $last ): string {
		return match ( $last['state'] ) {
			/* translators: 1: version, 2: time */
			'published' => sprintf( __( 'Pubblicata la versione %1$s alle %2$s.', 'xb' ), $last['version'], wp_date( 'H:i', $last['done'] ) ),
			/* translators: 1: date, 2: time, 3: reason */
			'skipped' => sprintf( __( 'Saltata la release programmata per il %1$s alle %2$s: %3$s', 'xb' ), wp_date( 'd/m', $last['at'] ), wp_date( 'H:i', $last['at'] ), $last['reason'] ),
			/* translators: 1: date, 2: time, 3: reason */
			default => sprintf( __( 'Annullata la release programmata per il %1$s alle %2$s: %3$s', 'xb' ), wp_date( 'd/m', $last['at'] ), wp_date( 'H:i', $last['at'] ), $last['reason'] ),
		};
	}

	/** Without a system cron the time can slip (WP-Cron runs on a visit): the lines to give the hosting (§11.5). */
	private static function render_cron_warning(): void {
		$cron = Scheduled::cron();
		if ( $cron['system'] ) {
			return;
		}
		?>
		<details class="xb-scheduled__cron">
			<summary><?php esc_html_e( 'Questo sito non ha un cron di sistema: la release può partire in ritardo.', 'xb' ); ?></summary>
			<p class="xb-muted"><?php esc_html_e( 'La release partirà alla prima visita a WordPress dopo l\'ora scelta, non necessariamente all\'ora. Se il sito è servito in statico può slittare di ore; oltre 2 ore di ritardo non parte. Chiedi all\'hosting di aggiungere una di queste righe al cron:', 'xb' ); ?></p>
			<pre><?php echo esc_html( implode( "\n", $cron['lines'] ) ); ?></pre>
			<p class="xb-muted"><?php esc_html_e( 'Poi, in wp-config.php:', 'xb' ); ?> <code><?php echo esc_html( $cron['config'] ); ?></code></p>
		</details>
		<?php
	}

	public static function export(): void {
		check_admin_referer( self::EXPORT );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore esporta le release.', 'xb' ), 403 );
		}
		$id = isset( $_GET['release'] ) ? absint( $_GET['release'] ) : 0;
		try {
			$file = Transfer::instance()->export( $id );
		} catch ( VersioningException | \InvalidArgumentException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		Plugin::instance()->versioning->store()->audit( 'export_release', 'release:' . $id );
		$name = 'xb-release-' . sanitize_file_name( (string) ( $file['release']['version'] ?? $id ) ) . '.xbrelease';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $file, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download.
		exit;
	}

	/**
	 * The published site as a static zip (docs/spec/static.md §7): built like a deploy (anonymous visitor, export
	 * mode), scanned, then streamed and deleted. A bundle the scanner blocks is never handed out.
	 */
	public static function static_zip(): void {
		check_admin_referer( self::ZIP );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore scarica il sito statico.', 'xb' ), 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- builds every page once.
		}
		try {
			$zip = self::build_zip();
		} catch ( \RuntimeException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $zip['name'] . '"' );
		header( 'Content-Length: ' . (string) filesize( $zip['file'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		// A download the browser drops halfway still ends here: the zip of the whole site is deleted.
		ignore_user_abort( true );
		readfile( $zip['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- a download.
		wp_delete_file( $zip['file'] );
		exit;
	}

	/**
	 * Builds the static site and zips it; the bundle folder is deleted, the zip (in the protected work area) is
	 * the caller's to stream and delete.
	 *
	 * @return array{file: string, name: string, pages: int, assets: int}
	 * @throws \RuntimeException With a message for the administrator.
	 */
	public static function build_zip(): array {
		if ( ! class_exists( \ZipArchive::class ) ) {
			throw new \RuntimeException( __( 'Il server non ha l\'estensione PHP zip.', 'xb' ) );
		}
		$build = ( new \XB\StaticSite\Exporter() )->build( 'zip' );
		$dir   = $build['dir'];
		try {
			if ( \XB\StaticSite\Scanner::blocking( $build['findings'] ) ) {
				$first = null;
				foreach ( $build['findings'] as $finding ) {
					if ( 'block' === $finding['severity'] ) {
						$first = $finding;
						break;
					}
				}
				throw new \RuntimeException( __( 'Il sito statico ha problemi bloccanti (dati privati o di sessione nelle pagine): non viene scaricato.', 'xb' ) . ( null === $first ? '' : ' ' . $first['file'] . ': ' . $first['rule'] ) );
			}
			$file = $dir . '.zip';
			$zip  = new \ZipArchive();
			if ( true !== $zip->open( $file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				throw new \RuntimeException( __( 'Impossibile creare lo zip.', 'xb' ) );
			}
			foreach ( \XB\StaticSite\Bundle::files( $dir ) as $relative => $absolute ) {
				$zip->addFile( $absolute, ltrim( $relative, '/' ) );
			}
			$zip->close();
		} finally {
			\XB\StaticSite\Bundle::delete( $dir );
		}
		Plugin::instance()->versioning->store()->audit( 'static_zip', 'site', array( 'pages' => $build['pages'], 'assets' => $build['assets'] ) );
		return array(
			'file'   => $file,
			'name'   => sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . '-statico-' . gmdate( 'Ymd-His' ) ) . '.zip',
			'pages'  => $build['pages'],
			'assets' => $build['assets'],
		);
	}

	private static function import_key(): string {
		return 'xb_admin_import_' . get_current_user_id();
	}

	/** Step 1: the file is checked and the plan shown; nothing is written. */
	public static function import(): void {
		check_admin_referer( self::IMPORT );
		self::release_check( $_FILES['release'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked there, read as data only.
	}

	/** The `.xbrelease` upload checked and its plan kept: shared by the old form and the single upload. */
	private static function release_check( mixed $upload ): never {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore importa le release.', 'xb' ), 403 );
		}
		if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? -1 ) || ! is_uploaded_file( (string) $upload['tmp_name'] ) ) {
			Menu::back( self::here(), 'error', __( 'File non ricevuto.', 'xb' ) );
		}
		if ( (int) $upload['size'] > self::IMPORT_MAX ) {
			Menu::back( self::here(), 'error', __( 'File troppo grande per l\'importazione da qui: usa il pannello Versioni del builder.', 'xb' ) );
		}
		$json = (string) file_get_contents( (string) $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		try {
			$plan = Transfer::instance()->import( json_decode( $json, true, 512, JSON_THROW_ON_ERROR ), false );
		} catch ( \JsonException | \InvalidArgumentException | VersioningException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		set_transient( self::import_key(), array( 'json' => $json, 'plan' => $plan ), 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'import', 'plan', self::here() ) );
		exit;
	}

	/** Step 2: the same file, written to development. */
	public static function confirm_import(): void {
		check_admin_referer( self::CONFIRM );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore importa le release.', 'xb' ), 403 );
		}
		$stored = get_transient( self::import_key() );
		delete_transient( self::import_key() );
		if ( ! is_array( $stored ) || ! is_string( $stored['json'] ?? null ) ) {
			Menu::back( self::here(), 'error', __( 'Importazione scaduta: carica di nuovo il file.', 'xb' ) );
		}
		try {
			Transfer::instance()->import( json_decode( $stored['json'], true, 512, JSON_THROW_ON_ERROR ), true, self::signature_choice() );
		} catch ( \JsonException | \InvalidArgumentException | VersioningException $e ) {
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		Menu::back( self::here(), 'imported' );
	}

	// ------------------------------------------------------------------ the single upload

	/**
	 * «Importa un file»: one field for the three files the site imports. The file says what it is — a release
	 * (`.xbrelease`, JSON), a Histeon Publish project (`.xbsite` with `site.json` version 2) or a whole site to move
	 * (`.xbsite` version 1) — and goes to the check it would have had from its own form: same permissions, same plan.
	 */
	public static function import_file(): void {
		check_admin_referer( self::IMPORT_FILE );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore importa un file.', 'xb' ), 403 );
		}
		$upload = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below and in the check it goes to, read as data only.
		if ( is_array( $upload ) && in_array( $upload['error'] ?? -1, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			Menu::back( self::here(), 'error', self::too_big() );
		}
		if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? -1 ) || ! is_uploaded_file( (string) $upload['tmp_name'] ) ) {
			Menu::back( self::here(), 'error', __( 'File non ricevuto (forse supera il limite di caricamento del server).', 'xb' ) );
		}
		$kind = self::file_kind( (string) $upload['tmp_name'] );
		if ( 'site' === $kind ) {
			self::site_forget();
		}
		match ( $kind ) {
			'release' => self::release_check( $upload ),
			'site'    => self::site_check( $upload ),
			'project' => null !== self::project_refused() ? Menu::back( self::here(), 'error', (string) self::project_refused() ) : CmsScreen::check_upload( $upload ),
			default   => Menu::back( self::here(), 'error', __( 'Non riconosco questo file. Qui si importano un progetto esportato da Histeon Publish (.xbsite), un sito intero esportato con «Esporta tutto il sito» (.xbsite) o una release (.xbrelease).', 'xb' ) ),
		};
	}

	/**
	 * A project package of Histeon Publish (.xbsite v2) carries no signature: with «Richiedi la firma» on,
	 * a file that arrived from elsewhere is not imported from here. The package made on this screen by the HTML
	 * import, and the one sent over the authenticated Histeon Publish link, are not files from elsewhere.
	 */
	public static function project_refused(): ?string {
		return get_option( \XB\Versioning\Signing::REQUIRE_OPTION )
			? __( 'Questo è un progetto di Histeon Publish (.xbsite): questi pacchetti non sono firmati, e qui «Richiedi la firma» è attivo. Invialo con il collegamento a Histeon Publish, oppure spegni l\'opzione in Impostazioni → Firma degli export se ti fidi di chi ti ha dato il file.', 'xb' )
			: null;
	}

	/** The server refused the file for its size: say the limit and the two ways out. */
	private static function too_big(): string {
		return sprintf(
			/* translators: %s: the server's upload limit, e.g. "2 MB" */
			__( 'Il file supera il limite di caricamento del server (%s). Alza upload_max_filesize e post_max_size di PHP, oppure collega Histeon Publish e invia il progetto senza file.', 'xb' ),
			size_format( wp_max_upload_size() )
		);
	}

	/** What an uploaded file is, from its bytes: "release", "project", "site" or "" when it is none of them. */
	public static function file_kind( string $path ): string {
		$head = (string) file_get_contents( $path, false, null, 0, 64 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded temp file.
		if ( str_starts_with( ltrim( $head, "\xEF\xBB\xBF \t\r\n" ), '{' ) ) {
			return 'release';
		}
		if ( ! str_starts_with( $head, "PK\x03\x04" ) || ! class_exists( \ZipArchive::class ) ) {
			return '';
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) {
			return '';
		}
		$stat     = $zip->statName( 'site.json' );
		$manifest = is_array( $stat ) && (int) $stat['size'] <= 16 * 1024 * 1024 ? json_decode( (string) $zip->getFromName( 'site.json' ), true ) : null;
		$zip->close();
		if ( ! is_array( $manifest ) || SiteTransfer::FORMAT !== ( $manifest['format'] ?? null ) ) {
			return '';
		}
		return match ( $manifest['version'] ?? null ) {
			SiteTransfer::VERSION => 'site',
			2                     => 'project',
			default               => '',
		};
	}

	// ------------------------------------------------------------------ moving the whole site

	/** The whole site as a `.xbsite` file (docs/spec/site-transfer.md), built in the temp folder, streamed, deleted. */
	public static function site_export(): void {
		check_admin_referer( self::SITE_EXPORT );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore esporta il sito.', 'xb' ), 403 );
		}
		if ( ! class_exists( \ZipArchive::class ) ) {
			Menu::back( self::here(), 'error', __( 'Il server non ha l\'estensione PHP zip.', 'xb' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- copies every media file once.
		}
		$file = wp_tempnam( 'xbsite' );
		try {
			SiteTransfer::instance()->export( $file );
		} catch ( \RuntimeException $e ) {
			wp_delete_file( $file );
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		$name = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . '-' . gmdate( 'Ymd-His' ) ) . '.' . SiteTransfer::EXTENSION;
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		ignore_user_abort( true ); // The copy of the whole site is deleted even when the browser drops the download.
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- a download.
		wp_delete_file( $file );
		exit;
	}

	private static function site_key(): string {
		return 'xb_admin_site_import_' . get_current_user_id();
	}

	/**
	 * The file kept between the check and the confirmation: only one this screen put in the temp folder, with the
	 * bytes it checked (the same binding as CmsScreen::kept_file).
	 */
	private static function site_file( mixed $stored ): ?string {
		$file = is_array( $stored ) && is_string( $stored['file'] ?? null ) ? $stored['file'] : '';
		$dir  = (string) realpath( get_temp_dir() );
		$real = '' === $file ? false : realpath( $file );
		if ( false === $real || '' === $dir || ! str_starts_with( $real, $dir . DIRECTORY_SEPARATOR ) || 1 !== preg_match( '/^xb-site-[0-9a-f]{32}\.' . preg_quote( SiteTransfer::EXTENSION, '/' ) . '$/D', basename( $real ) ) || ! is_file( $real ) ) {
			return null;
		}
		$hash = is_string( $stored['sha256'] ?? null ) ? $stored['sha256'] : '';
		return '' !== $hash && hash_equals( $hash, (string) hash_file( 'sha256', $real ) ) ? $real : null;
	}

	private static function site_forget(): void {
		$file = self::site_file( get_transient( self::site_key() ) );
		if ( null !== $file ) {
			wp_delete_file( $file );
		}
		delete_transient( self::site_key() );
	}

	/** Step 1: the file is checked and the plan shown; nothing is written. */
	public static function site_import(): void {
		check_admin_referer( self::SITE_IMPORT );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore importa un sito.', 'xb' ), 403 );
		}
		self::site_forget();
		if ( isset( $_POST['cancel'] ) ) {
			Menu::back( self::here(), 'done', __( 'Trasloco annullato.', 'xb' ) );
		}
		self::site_check( $_FILES['site'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked there, read as data only.
	}

	/** The whole-site `.xbsite` upload checked and its plan kept: shared by the old form and the single upload. */
	private static function site_check( mixed $upload ): never {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore importa un sito.', 'xb' ), 403 );
		}
		if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? -1 ) || ! is_uploaded_file( (string) $upload['tmp_name'] ) ) {
			Menu::back( self::here(), 'error', __( 'File non ricevuto (forse supera il limite di caricamento del server).', 'xb' ) );
		}
		if ( (int) $upload['size'] > self::SITE_MAX ) {
			Menu::back( self::here(), 'error', __( 'File troppo grande.', 'xb' ) );
		}
		$file = trailingslashit( get_temp_dir() ) . 'xb-site-' . bin2hex( random_bytes( 16 ) ) . '.' . SiteTransfer::EXTENSION;
		if ( ! move_uploaded_file( (string) $upload['tmp_name'], $file ) ) {
			Menu::back( self::here(), 'error', __( 'File non ricevuto.', 'xb' ) );
		}
		chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- private until it is imported or deleted.
		try {
			$plan = SiteTransfer::instance()->import( $file, false );
		} catch ( \InvalidArgumentException | VersioningException $e ) {
			wp_delete_file( $file );
			Menu::back( self::here(), 'error', $e->getMessage() );
		}
		set_transient( self::site_key(), array( 'file' => $file, 'sha256' => (string) hash_file( 'sha256', $file ), 'plan' => $plan ), 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'site', 'plan', self::here() ) );
		exit;
	}

	/** Step 2: the same file, imported (in development, or published at once by an administrator who publishes). */
	public static function site_confirm(): void {
		check_admin_referer( self::SITE_CONFIRM );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore importa un sito.', 'xb' ), 403 );
		}
		$stored = get_transient( self::site_key() );
		$file   = self::site_file( $stored );
		delete_transient( self::site_key() );
		if ( null === $file ) {
			Menu::back( self::here(), 'error', __( 'Importazione scaduta: carica di nuovo il file.', 'xb' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- every media file is processed once.
		}
		$publish = isset( $_POST['publish'] ) && self::can_publish();
		$error = '';
		try {
			$done = SiteTransfer::instance()->import( $file, true, $publish, false, self::signature_choice() );
		} catch ( \InvalidArgumentException | VersioningException $e ) {
			$error = $e->getMessage();
		}
		// Not in a finally: the redirect exits, and exit skips finally blocks.
		wp_delete_file( $file );
		if ( '' !== $error || ! isset( $done ) ) {
			Menu::back( self::here(), 'error', $error );
		}
		$message = sprintf(
			/* translators: 1: contents, 2: media files */
			__( 'Sito importato: %1$d contenuti, %2$d media.', 'xb' ),
			count( $done['contents'] ),
			$done['media']
		);
		$message .= '' !== $done['published'] ? ' ' . sprintf( /* translators: %s: version */ __( 'Pubblicato come release %s.', 'xb' ), $done['published'] ) : '';
		$message .= array() !== $done['issues'] ? ' ' . implode( ' · ', $done['issues'] ) : '';
		Menu::back( self::here(), 'done', $message );
	}

	// ------------------------------------------------------------------ screen

	public static function render(): void {
		if ( ! current_user_can( 'xb_build' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per usare Histeon.', 'xb' ), 403 );
		}
		$versioning = Plugin::instance()->versioning;
		$store      = $versioning->store();
		$changes    = Controller::visible_changes();
		$current    = (int) get_option( Versioning::OPTION_CURRENT, 0 );
		$releases   = $store->releases( 100 );
		$plan       = isset( $_GET['import'] ) ? get_transient( self::import_key() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of the user's own pending import.
		Menu::open( self::SLUG, __( 'Release', 'xb' ), __( 'Ciò che salvi nel builder resta in sviluppo. Una release lo mette online tutto insieme, e si può sempre tornare indietro.', 'xb' ) );
		self::render_scheduled();
		$scheduled = Scheduled::view();
		?>
		<?php if ( is_array( $plan ) && is_array( $plan['plan'] ?? null ) && self::can_manage() ) : ?>
			<?php $p = $plan['plan']; ?>
			<div class="xb-panel" style="border-color:var(--xb-accent)">
				<div class="xb-panel-head">
					<span class="xb-stat-icon"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div>
						<h2><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Importare la release %s?', 'xb' ), (string) $p['release'] ) ); ?></h2>
						<p><?php echo esc_html( sprintf( /* translators: %s: site */ __( 'Da %s. Ecco cosa cambierebbe; nulla è ancora stato scritto.', 'xb' ), (string) $p['site'] ) ); ?></p>
					</div>
				</div>
				<table class="xb-table">
					<tbody>
					<?php foreach ( (array) $p['documents'] as $row ) : ?>
						<tr><td><strong><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></strong></td><td class="xb-muted"><?php echo esc_html( (string) ( $row['target'] ?? '' ) ); ?></td><td><span class="xb-pill <?php echo 'errore' === ( $row['action'] ?? '' ) ? 'is-warn' : 'is-dev'; ?>"><?php echo esc_html( self::action_label( (string) ( $row['action'] ?? '' ) ) ); ?></span></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="xb-panel-body" style="border-top:1px solid var(--xb-line)">
					<?php foreach ( (array) $p['issues'] as $issue ) : ?>
						<p><span class="xb-pill is-warn"><?php echo esc_html( (string) $issue ); ?></span></p>
					<?php endforeach; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::CONFIRM ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::CONFIRM ); ?>">
						<?php self::signature_fields( (array) ( $p['signature'] ?? array() ) ); ?>
						<button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Importa in sviluppo', 'xb' ); ?></button>
						<a class="xb-btn" href="<?php echo esc_url( self::here() ); ?>"><?php esc_html_e( 'Annulla', 'xb' ); ?></a>
						<span class="xb-muted" style="margin-left:8px"><?php esc_html_e( 'Niente va online finché non pubblichi una release.', 'xb' ); ?></span>
					</form>
				</div>
			</div>
		<?php endif; ?>

		<?php self::render_site_plan(); ?>
		<?php CmsScreen::render_plan(); ?>

		<h2 class="xb-section-title"><?php esc_html_e( 'Da pubblicare', 'xb' ); ?> <small><?php echo esc_html( sprintf( /* translators: %s: next version */ __( 'la prossima release sarà la %s', 'xb' ), $versioning->next_version( null ) ) ); ?></small></h2>
		<div class="xb-panel">
			<?php if ( array() === $changes ) : ?>
				<div class="xb-empty"><?php echo Menu::icon( 'yes-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Tutto è online', 'xb' ); ?></strong><span><?php esc_html_e( 'Quando salvi qualcosa nel builder, compare qui pronto da pubblicare.', 'xb' ); ?></span></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::PUBLISH ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::PUBLISH ); ?>">
					<?php
					// After a send, only what the send brought starts chosen: work of the site goes online when someone picks it.
					$sent = array_filter( $changes, static fn ( array $change ): bool => $change['sent'] );
					if ( array() !== $sent && count( $sent ) < count( $changes ) ) :
						?>
						<p class="xb-muted xb-panel-body"><?php esc_html_e( 'Sono selezionate le modifiche arrivate da Histeon CMS. Quelle fatte sul sito vanno online solo se le scegli.', 'xb' ); ?></p>
					<?php endif; ?>
					<table class="xb-table">
						<tbody>
						<?php foreach ( $changes as $change ) : ?>
							<tr>
								<td style="width:32px"><input type="checkbox" name="docs[]" value="<?php echo esc_attr( $change['doc'] ); ?>" <?php checked( array() === $sent || $change['sent'] ); ?> <?php disabled( ! self::can_publish() ); ?> aria-label="<?php echo esc_attr( Menu::describe( $change['doc'] ) ); ?>"></td>
								<td><strong><?php echo esc_html( Menu::describe( $change['doc'] ) ); ?></strong></td>
								<td><div class="xb-row-actions"><?php OverviewScreen::doc_links( $change['doc'] ); ?></div></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div class="xb-panel-body" style="border-top:1px solid var(--xb-line)">
						<?php if ( self::can_publish() && self::checks_block() ) : ?>
							<p class="xb-muted"><?php esc_html_e( 'I controlli prima di pubblicare sono attivi (Impostazioni): pubblica dal builder, con «Pubblica», dove le pagine passano dai controlli.', 'xb' ); ?></p>
						<?php elseif ( self::can_publish() ) : ?>
							<div class="xb-form-row">
								<label for="xb-version"><?php esc_html_e( 'Versione', 'xb' ); ?></label>
								<div><input id="xb-version" type="text" name="version" placeholder="<?php echo esc_attr( $versioning->next_version( null ) ); ?>" size="10" pattern="\d+\.\d+\.\d+"> <span class="description"><?php esc_html_e( 'Lascia vuoto per la prossima in automatico.', 'xb' ); ?></span></div>
							</div>
							<div class="xb-form-row">
								<label for="xb-notes"><?php esc_html_e( 'Note', 'xb' ); ?></label>
								<textarea id="xb-notes" name="notes" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Cosa cambia in questa release (facoltativo)', 'xb' ); ?>"></textarea>
							</div>
							<p style="text-align:right;margin:12px 0 0"><button class="xb-btn xb-btn--primary"><?php echo Menu::icon( 'cloud-upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Pubblica i documenti scelti', 'xb' ); ?></button></p>
						<?php else : ?>
							<p class="xb-muted"><?php esc_html_e( 'Per pubblicare serve il permesso xb_publish: chiedi a chi gestisce il sito.', 'xb' ); ?></p>
						<?php endif; ?>
					</div>
				</form>
			<?php endif; ?>
		</div>

		<?php ProposalsSection::render( $changes ); ?>

		<h2 class="xb-section-title"><?php esc_html_e( 'Storico delle release', 'xb' ); ?></h2>
		<div class="xb-panel">
			<?php if ( array() === $releases ) : ?>
				<div class="xb-empty"><?php echo Menu::icon( 'cloud-upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Nessuna release finora', 'xb' ); ?></strong></div>
			<?php else : ?>
				<ol class="xb-timeline">
				<?php foreach ( $releases as $release ) : ?>
					<?php
					$full       = $store->get_release( $release['id'] );
					$is_current = $release['id'] === $current;
					?>
					<li class="<?php echo $is_current ? 'is-live is-current' : ''; ?>">
						<div class="xb-tl-title">
							<?php echo esc_html( 'v' . $release['version'] ); ?>
							<?php if ( $is_current ) : ?>
								<span class="xb-pill is-live"><?php esc_html_e( 'online', 'xb' ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $release['restored_from'] ) : ?>
								<span class="xb-pill is-plain"><?php esc_html_e( 'ripristino', 'xb' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="xb-tl-actions">
							<?php if ( self::can_publish() && ! $is_current ) : ?>
								<form class="xb-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Rimettere online questa release? Diventa una nuova release, e le modifiche in sviluppo restano.', 'xb' ) . ( null === $scheduled ? '' : ' ' . __( 'Ripristinare annulla anche la release programmata.', 'xb' ) ) ) ); ?>)">
									<?php wp_nonce_field( self::RESTORE ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( self::RESTORE ); ?>">
									<input type="hidden" name="release" value="<?php echo esc_attr( (string) $release['id'] ); ?>">
									<input type="hidden" name="keep_content" value="1">
									<button class="xb-btn xb-btn--small"><?php echo Menu::icon( 'undo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Ripristina', 'xb' ); ?></button>
								</form>
							<?php endif; ?>
							<?php if ( self::can_manage() ) : ?>
								<a class="xb-btn xb-btn--small xb-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT . '&release=' . $release['id'] ), self::EXPORT ) ); ?>"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Esporta', 'xb' ); ?></a>
							<?php endif; ?>
						</div>
						<div class="xb-tl-meta"><?php echo esc_html( Menu::date( $release['created'] ) . ' · ' . Menu::user_name( $release['author'] ) . ( '' !== $release['notes'] ? ' · ' . $release['notes'] : '' ) ); ?></div>
						<?php if ( null !== $full ) : ?>
							<details>
								<summary><?php echo esc_html( sprintf( /* translators: %d: number of documents */ _n( '%d documento', '%d documenti', count( $full['items'] ), 'xb' ), count( $full['items'] ) ) ); ?></summary>
								<ul>
								<?php foreach ( array_keys( $full['items'] ) as $doc ) : ?>
									<li>
										<?php if ( Menu::can_see( (string) $doc ) ) : ?>
											<a href="<?php echo esc_url( Menu::url( HistoryScreen::SLUG, array( 'doc' => (string) $doc ) ) ); ?>"><?php echo esc_html( Menu::describe( (string) $doc ) ); ?></a>
										<?php else : ?>
											<?php echo esc_html( \XB\Versioning\Documents::valid( (string) $doc ) ? __( 'Documento riservato', 'xb' ) : (string) $doc ); ?>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>

		<?php if ( self::can_manage() ) : ?>
			<h2 class="xb-section-title"><?php esc_html_e( 'Sito statico', 'xb' ); ?> <small><?php esc_html_e( 'le pagine pubblicate come HTML, con le loro risorse', 'xb' ); ?></small></h2>
			<?php self::render_static_bridge(); ?>
			<div class="xb-panel">
				<div class="xb-panel-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
						<?php wp_nonce_field( self::ZIP ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ZIP ); ?>">
						<button class="xb-btn"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Scarica il sito statico (zip)', 'xb' ); ?></button>
						<span class="xb-muted"><?php esc_html_e( 'Ogni pagina viene generata come la vede un visitatore e controllata: se contiene dati privati o di sessione, lo zip non viene creato. Su siti grandi può richiedere qualche minuto.', 'xb' ); ?></span>
					</form>
				</div>
			</div>

			<h2 class="xb-section-title" id="xb-importa"><?php esc_html_e( 'Importa un file', 'xb' ); ?> <small><?php esc_html_e( 'da Histeon Publish o da un altro sito Histeon', 'xb' ); ?></small></h2>
			<div class="xb-panel">
				<div class="xb-panel-body">
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
						<?php wp_nonce_field( self::IMPORT_FILE ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_FILE ); ?>">
						<label for="xb-import-file"><strong><?php esc_html_e( 'File da importare', 'xb' ); ?></strong></label>
						<input id="xb-import-file" type="file" name="file" accept=".xbsite,.xbrelease,application/zip,application/json" required>
						<button class="xb-btn xb-btn--primary"><?php echo Menu::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Controlla il file', 'xb' ); ?></button>
						<span class="xb-muted"><?php echo esc_html( sprintf( /* translators: %s: the server's upload limit */ __( 'Limite di caricamento del server: %s', 'xb' ), size_format( wp_max_upload_size() ) ) ); ?></span>
					</form>
					<p class="xb-muted"><?php esc_html_e( 'Riconosco il file e ti mostro cosa cambierebbe: nulla viene scritto prima della tua conferma, e l’importazione va in sviluppo.', 'xb' ); ?></p>
					<ul class="xb-facts">
						<li><strong><?php esc_html_e( 'Un progetto di Histeon Publish', 'xb' ); ?></strong> · <?php esc_html_e( '.xbsite, da «Esporta .xbsite» nelle impostazioni del progetto: pagine, articoli, contenuti, stili e media', 'xb' ); ?></li>
						<li><strong><?php esc_html_e( 'Un sito intero', 'xb' ); ?></strong> · <?php esc_html_e( '.xbsite, da «Esporta tutto il sito» di un altro WordPress con Histeon: per un trasloco', 'xb' ); ?></li>
						<li><strong><?php esc_html_e( 'Una release', 'xb' ); ?></strong> · <?php esc_html_e( '.xbrelease, dallo storico delle release di un altro sito Histeon', 'xb' ); ?></li>
					</ul>
					<p class="xb-muted"><?php echo wp_kses( sprintf( /* translators: %s: link to the HTML conversion */ __( 'Hai una pagina HTML o lo ZIP di un sito? %s.', 'xb' ), '<a href="#xb-html-import">' . esc_html__( 'Convertili in un progetto Histeon', 'xb' ) . '</a>' ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
				</div>
			</div>

			<h2 class="xb-section-title" id="xb-esporta"><?php esc_html_e( 'Esporta', 'xb' ); ?> <small><?php esc_html_e( 'per lavorare in Histeon Publish o spostare il sito', 'xb' ); ?></small></h2>
			<div class="xb-panel">
				<div class="xb-panel-body" style="display:grid;gap:12px">
					<?php CmsScreen::export_form(); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
						<?php wp_nonce_field( self::SITE_EXPORT ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::SITE_EXPORT ); ?>">
						<button class="xb-btn"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Esporta tutto il sito', 'xb' ); ?></button>
						<span class="xb-muted"><?php esc_html_e( 'Per un trasloco in un altro WordPress con Histeon: ciò che è online, cioè pagine, articoli, componenti, template, menu, stili, impostazioni e i media che usano. Utenti, commenti e opzioni degli altri plugin non sono inclusi.', 'xb' ); ?></span>
					</form>
					<p class="xb-muted"><?php esc_html_e( 'Una singola release si esporta dallo storico qui sopra, come file .xbrelease.', 'xb' ); ?></p>
				</div>
			</div>

			<?php CmsScreen::render(); ?>

			<?php RemoteScreen::render(); ?>

			<?php CmsScreen::render_html_import(); ?>
		<?php endif; ?>
		<?php
		Menu::close();
	}

	/** The pending site import: what the file would do, and the two ways to confirm. */
	/** What an import would do to a document ("crea", "aggiorna", "saltato", "errore"), in the user's language. */
	private static function action_label( string $action ): string {
		$labels = array(
			'crea'     => __( 'crea', 'xb' ),
			'aggiorna' => __( 'aggiorna', 'xb' ),
			'saltato'  => __( 'saltato', 'xb' ),
			'errore'   => __( 'errore', 'xb' ),
		);
		return $labels[ $action ] ?? $action;
	}

	private static function render_site_plan(): void {
		$stored = isset( $_GET['site'] ) && self::can_manage() ? get_transient( self::site_key() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of the user's own pending import.
		if ( ! is_array( $stored ) || ! is_array( $stored['plan'] ?? null ) || null === self::site_file( $stored ) ) {
			return;
		}
		$p    = $stored['plan'];
		$rows = array_merge( (array) $p['documents'], (array) $p['contents'] );
		?>
		<div class="xb-panel" style="border-color:var(--xb-accent)">
			<div class="xb-panel-head">
				<span class="xb-stat-icon"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2><?php esc_html_e( 'Importare questo sito?', 'xb' ); ?></h2>
					<p><?php echo esc_html( sprintf( /* translators: 1: site, 2: media count */ __( 'Da %1$s, con %2$d media. Ecco cosa cambierebbe; nulla è ancora stato scritto.', 'xb' ), (string) $p['site'], (int) $p['media'] ) ); ?></p>
				</div>
			</div>
			<table class="xb-table">
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr><td><strong><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></strong></td><td><span class="xb-pill <?php echo in_array( $row['action'] ?? '', array( 'errore', 'saltato' ), true ) ? 'is-warn' : 'is-dev'; ?>"><?php echo esc_html( self::action_label( (string) ( $row['action'] ?? '' ) ) ); ?></span></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="xb-panel-body" style="border-top:1px solid var(--xb-line)">
				<?php foreach ( (array) $p['issues'] as $issue ) : ?>
					<p><span class="xb-pill is-warn"><?php echo esc_html( (string) $issue ); ?></span></p>
				<?php endforeach; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( self::SITE_CONFIRM ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SITE_CONFIRM ); ?>">
					<?php self::signature_fields( (array) ( $p['signature'] ?? array() ) ); ?>
					<button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Importa in sviluppo', 'xb' ); ?></button>
					<?php if ( self::can_publish() ) : ?>
						<button class="xb-btn" name="publish" value="1"><?php esc_html_e( 'Importa e pubblica', 'xb' ); ?></button>
					<?php endif; ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( self::SITE_IMPORT ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SITE_IMPORT ); ?>">
					<button class="xb-btn" name="cancel" value="1"><?php esc_html_e( 'Annulla', 'xb' ); ?></button>
				</form>
				<p class="xb-muted"><?php esc_html_e( 'In sviluppo: niente va online finché non pubblichi una release, e i contenuti nuovi sono bozze. «Importa e pubblica» mette online tutto quello che il file contiene, con lo stato che aveva sul sito di origine.', 'xb' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Static Bridge, the engine that serves the published site as static HTML on this same server: where its copy
	 * stands, or how to get it (docs/integration/static-bridge.md).
	 */
	private static function render_static_bridge(): void {
		$status = \XB\StaticSite\StaticBridge::status();
		?>
		<div class="xb-panel xb-static-bridge">
			<div class="xb-panel-head">
				<h2><?php esc_html_e( 'Static Bridge', 'xb' ); ?></h2>
				<?php if ( null === $status ) : ?>
					<span class="xb-pill"><?php esc_html_e( 'non attivo', 'xb' ); ?></span>
				<?php elseif ( $status['serving'] ) : ?>
					<span class="xb-pill is-live"><?php esc_html_e( 'serve il sito statico', 'xb' ); ?></span>
				<?php else : ?>
					<span class="xb-pill is-dev"><?php esc_html_e( 'attivo, copia statica non ancora generata', 'xb' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="xb-panel-body">
				<?php if ( null === $status ) : ?>
					<p class="xb-muted"><?php esc_html_e( 'Con Static Bridge (open source, GPL) chi visita riceve le pagine come HTML statico, servito dallo stesso server; l\'amministrazione resta WordPress. Histeon gli dice quali pagine rigenerare a ogni release: un componente aggiorna solo le pagine che lo usano, stili e impostazioni aggiornano tutto. I filtri dei loop restano dinamici.', 'xb' ); ?></p>
				<?php else : ?>
					<ul class="xb-facts">
						<?php if ( $status['serving'] ) : ?>
							<li><strong><?php echo esc_html( number_format_i18n( $status['pages'] ) ); ?></strong> <?php esc_html_e( 'pagine statiche', 'xb' ); ?></li>
							<?php if ( $status['published_at'] > 0 ) : ?>
								<li>
								<?php
								printf(
									/* translators: %s: date and time of the last full static copy */
									esc_html__( 'copia completa del %s', 'xb' ),
									'<strong>' . esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['published_at'] ) ) . '</strong>'
								);
								?>
								</li>
							<?php endif; ?>
						<?php endif; ?>
						<li><?php echo $status['incremental'] ? esc_html__( 'si aggiorna da sola a ogni release Histeon', 'xb' ) : esc_html__( 'aggiornamento automatico spento: rigenera da Static Bridge', 'xb' ); ?></li>
						<?php if ( $status['pending'] > 0 ) : ?>
							<li><strong><?php echo esc_html( number_format_i18n( $status['pending'] ) ); ?></strong> <?php esc_html_e( 'pagine in coda di rigenerazione', 'xb' ); ?></li>
						<?php endif; ?>
						<?php if ( in_array( $status['export'], array( 'running', 'paused', 'failed' ), true ) ) : ?>
							<li><?php echo esc_html( array( 'running' => __( 'generazione completa in corso', 'xb' ), 'paused' => __( 'generazione completa in pausa', 'xb' ), 'failed' => __( 'ultima generazione completa non riuscita', 'xb' ) )[ $status['export'] ] ); ?></li>
						<?php endif; ?>
					</ul>
					<?php if ( null !== $status['last'] && '' !== $status['last']['message'] ) : ?>
						<p class="xb-muted<?php echo $status['last']['error'] ? ' is-error' : ''; ?>"><?php esc_html_e( 'Ultima attività:', 'xb' ); ?> <?php echo esc_html( $status['last']['message'] ); ?> <?php echo esc_html( $status['last']['time'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $status['admin_url'] ) : ?>
						<p><a class="xb-btn" href="<?php echo esc_url( $status['admin_url'] ); ?>"><?php esc_html_e( 'Apri Static Bridge', 'xb' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Who signed the file and, when it asks for it, the administrator's choice (P8, docs/spec/signing.md): trusting a
	 * new key after comparing its fingerprint, or importing a file without a signature.
	 *
	 * @param array<string, mixed> $signature One of Signing::check(), as the plan reports it.
	 */
	private static function signature_fields( array $signature ): void {
		$status      = (string) ( $signature['status'] ?? '' );
		$fingerprint = (string) ( $signature['fingerprint'] ?? '' );
		$site        = (string) ( $signature['site'] ?? '' );
		$texts       = array(
			'own'      => __( 'Firmato da questo sito.', 'xb' ),
			/* translators: %s: declared site */
			'trusted'  => sprintf( __( 'Firmato da un sito di cui ti fidi (%s).', 'xb' ), $site ),
			/* translators: %s: declared site */
			'rotated'  => sprintf( __( 'Firmato con la nuova chiave di un sito di cui ti fidi (%s).', 'xb' ), $site ),
			/* translators: 1: declared site, 2: key fingerprint */
			'new'      => sprintf( __( 'Firmato da una chiave che questo sito non conosce: sito dichiarato %1$s, impronta %2$s. Confrontala con quella in Impostazioni → Firma degli export del sito di origine.', 'xb' ), $site, $fingerprint ),
			'unsigned' => __( 'Il file non è firmato: non si sa da quale sito arriva.', 'xb' ),
			'revoked'  => __( 'Firmato con una chiave revocata: non si può importare.', 'xb' ),
			'invalid'  => __( 'La firma non corrisponde al contenuto: non si può importare.', 'xb' ),
		);
		if ( ! isset( $texts[ $status ] ) ) {
			return;
		}
		printf( '<p><span class="xb-pill %s">%s</span></p>', in_array( $status, array( 'own', 'trusted', 'rotated' ), true ) ? 'is-live' : 'is-warn', esc_html( $texts[ $status ] ) );
		if ( 'new' === $status ) {
			/* translators: %s: key fingerprint */
			printf( '<p><label><input type="checkbox" name="xb_trust_key" value="1"> %s</label></p>', esc_html( sprintf( __( 'Mi fido di questa chiave (impronta %s)', 'xb' ), $fingerprint ) ) );
		} elseif ( 'unsigned' === $status && ! get_option( \XB\Versioning\Signing::REQUIRE_OPTION ) ) {
			printf( '<p><label><input type="checkbox" name="xb_allow_unsigned" value="1"> %s</label></p>', esc_html__( 'Importa anche senza firma', 'xb' ) );
		}
	}

	/** @return array{trust: bool, allowUnsigned: bool} */
	private static function signature_choice(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- read inside handlers that checked their nonce.
		return array(
			'trust'         => ! empty( $_POST['xb_trust_key'] ),
			'allowUnsigned' => ! empty( $_POST['xb_allow_unsigned'] ),
		);
		// phpcs:enable
	}
}
