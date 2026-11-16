<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Cms\FrontPage;
use XB\Cms\Link;
use XB\Remote\PageProfile;
use XB\Remote\PageProfileAdapter;
use XB\Remote\ProfileConflictException;
use XB\Remote\OperationConflictException;

/**
 * Histeon → Release → Histeon CMS (docs/spec/cms-bridge.md §1): the pairing code for a Histeon CMS project, with its
 * permissions chosen one by one, and its revocation. Separate from staging → production.
 */
final class CmsScreen {

	private const CREATE = 'xb_admin_cms_create';
	private const REVOKE = 'xb_admin_cms_revoke';
	private const FILE_EXPORT = 'xb_admin_cms_file_export';
	private const FILE_CHECK  = 'xb_admin_cms_file_check';
	private const FILE_APPLY  = 'xb_admin_cms_file_apply';
	/** «Importa HTML» → «Modello per i nomi» (doc 27 of Histeon Publish, G4). */
	private const CHOICES = 'xb_admin_import_choices';
	/** The package as a file (§13): kept between the check and the confirmation, then deleted. */
	private const FILE_EXTENSION = 'xbsite';
	private const FILE_TTL       = 30 * MINUTE_IN_SECONDS;

	public static function register(): void {
		add_action( 'admin_post_' . self::CREATE, array( self::class, 'create' ) );
		add_action( 'admin_post_' . self::REVOKE, array( self::class, 'revoke' ) );
		add_action( 'admin_post_' . self::FILE_EXPORT, array( self::class, 'file_export' ) );
		add_action( 'admin_post_' . self::FILE_CHECK, array( self::class, 'file_check' ) );
		add_action( 'admin_post_' . self::FILE_APPLY, array( self::class, 'file_apply' ) );
		add_action( 'admin_post_' . self::CHOICES, array( self::class, 'save_choices' ) );
		\XB\Cms\Pairing::register_admin();
	}

	private static function can(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	/** Who may convert a site with «Importa HTML», and so ask a model its names (Import\ChoicesRest). */
	public static function can_import(): bool {
		return self::can();
	}

	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Solo un amministratore collega Histeon CMS.', 'xb' ), 403 );
		}
	}

	private static function here(): string {
		return Menu::url( ReleasesScreen::SLUG ) . '#xb-cms';
	}

	private static function code_key(): string {
		return 'xb_cms_code_' . get_current_user_id();
	}

	public static function create(): void {
		self::guard( self::CREATE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$read    = isset( $_POST['read'] );
		$write   = isset( $_POST['write'] );
		$publish = $write && isset( $_POST['publish'] ) && current_user_can( 'xb_publish' );
		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		// phpcs:enable
		if ( ! $read && ! $write ) {
			Menu::back( self::here(), 'error', __( 'Scegli almeno un permesso: scaricare il sito o inviare modifiche.', 'xb' ) );
		}
		$code = Link::create( $label, $read, $write, $publish );
		// Shown once, to this user, for a few minutes: the code holds the secret.
		set_transient( self::code_key(), $code, 10 * MINUTE_IN_SECONDS );
		\XB\Plugin::instance()->versioning->store()->audit(
			'cms_link',
			'cms',
			array(
				'read'    => $read,
				'write'   => $write,
				'publish' => $publish,
				'label'   => $label,
			)
		);
		Menu::back( self::here(), 'done', __( 'Codice creato: incollalo in Histeon CMS. Non verrà più mostrato.', 'xb' ) );
	}

	public static function revoke(): void {
		self::guard( self::REVOKE );
		Link::revoke();
		delete_transient( self::code_key() );
		\XB\Plugin::instance()->versioning->store()->audit( 'cms_revoke', 'cms', array( 'by' => 'admin' ) );
		Menu::back( self::here(), 'done', __( 'Collegamento revocato: Histeon CMS non può più leggere né inviare.', 'xb' ) );
	}

	// ------------------------------------------------------------------ the package as a file (§13)

	/** The `.xbsite` v2 package of the site, as Histeon CMS downloads it, streamed from memory. */
	public static function file_export(): void {
		self::guard( self::FILE_EXPORT );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- every linked media file is read once.
		}
		try {
			$archive = PageProfileAdapter::export();
		} catch ( \DomainException | \InvalidArgumentException | \RuntimeException $e ) {
			Menu::back( Menu::url( ReleasesScreen::SLUG ), 'error', $e->getMessage() );
		}
		\XB\Plugin::instance()->versioning->store()->audit( 'cms_file_export', 'cms', array( 'bytes' => strlen( $archive ) ) );
		$name = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) . '-histos-cms-' . gmdate( 'Ymd-His' ) ) . '.' . self::FILE_EXTENSION;
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) strlen( $archive ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $archive; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a ZIP download.
		exit;
	}

	private static function file_key(): string {
		return 'xb_admin_cms_file_' . get_current_user_id();
	}

	/**
	 * The file kept between the check and the confirmation: only one this screen put in the temp folder, with the
	 * bytes it checked.
	 */
	private static function kept_file( mixed $stored ): ?string {
		$file = is_array( $stored ) && is_string( $stored['file'] ?? null ) ? $stored['file'] : '';
		$dir  = (string) realpath( get_temp_dir() );
		$real = '' === $file ? false : realpath( $file );
		if ( false === $real || '' === $dir || ! str_starts_with( $real, $dir . DIRECTORY_SEPARATOR ) || 1 !== preg_match( '/^xb-cms-[0-9a-f]{32}\.' . self::FILE_EXTENSION . '$/', basename( $real ) ) || ! is_file( $real ) ) {
			return null;
		}
		$hash = is_string( $stored['sha256'] ?? null ) ? $stored['sha256'] : '';
		return '' !== $hash && hash_equals( $hash, (string) hash_file( 'sha256', $real ) ) ? $real : null;
	}

	private static function file_forget(): void {
		$file = self::kept_file( get_transient( self::file_key() ) );
		if ( null !== $file ) {
			wp_delete_file( $file );
		}
		delete_transient( self::file_key() );
	}

	/** A refusal of the import, with the conflicting items when there are any. */
	private static function refusal( \Throwable $e ): string {
		$message = $e->getMessage();
		if ( $e instanceof ProfileConflictException ) {
			$titles   = array_slice( array_filter( array_map( static fn ( array $conflict ): string => (string) ( $conflict['title'] ?? '' ), $e->conflicts ) ), 0, 10 );
			$message .= array() !== $titles ? ' ' . implode( ' · ', $titles ) : '';
		}
		return $message;
	}

	/** Step 1: the file is checked in full and the plan shown; nothing is written. */
	public static function file_check(): void {
		self::guard( self::FILE_CHECK );
		self::file_forget();
		if ( isset( $_POST['cancel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
			Menu::back( Menu::url( ReleasesScreen::SLUG ), 'done', __( 'Importazione annullata.', 'xb' ) );
		}
		self::check_upload( $_FILES['package'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- checked in guard(), read as data only.
	}

	/**
	 * A project package upload checked in full and its plan kept: from the HTML conversion and from the single
	 * «Importa un file» of the Release screen. A refusal comes back at the top of the screen, where the message is.
	 */
	public static function check_upload( mixed $upload ): never {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Solo un amministratore collega Histeon CMS.', 'xb' ), 403 );
		}
		self::file_forget();
		$top = Menu::url( ReleasesScreen::SLUG );
		if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? -1 ) || ! is_uploaded_file( (string) $upload['tmp_name'] ) ) {
			Menu::back( $top, 'error', __( 'File non ricevuto (forse supera il limite di caricamento del server).', 'xb' ) );
		}
		if ( (int) $upload['size'] > PageProfile::MAX_ARCHIVE_BYTES ) {
			Menu::back( $top, 'error', __( 'File troppo grande.', 'xb' ) );
		}
		$file = trailingslashit( get_temp_dir() ) . 'xb-cms-' . bin2hex( random_bytes( 16 ) ) . '.' . self::FILE_EXTENSION;
		if ( ! move_uploaded_file( (string) $upload['tmp_name'], $file ) ) {
			Menu::back( $top, 'error', __( 'File non ricevuto.', 'xb' ) );
		}
		chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- private until it is imported or deleted.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- the whole package is checked once.
		}
		$error = '';
		try {
			$plan = PageProfileAdapter::import( (string) file_get_contents( $file ), false, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file.
		} catch ( \Throwable $e ) {
			$error = $e instanceof \Error ? __( 'Il pacchetto non si può leggere.', 'xb' ) : self::refusal( $e );
		}
		if ( '' !== $error || ! isset( $plan ) ) {
			wp_delete_file( $file );
			Menu::back( $top, 'error', $error );
		}
		set_transient( self::file_key(), array( 'file' => $file, 'sha256' => (string) hash_file( 'sha256', $file ), 'plan' => $plan, 'name' => sanitize_file_name( (string) ( $upload['name'] ?? '' ) ) ), self::FILE_TTL );
		wp_safe_redirect( add_query_arg( 'xbsite', 'plan', Menu::url( ReleasesScreen::SLUG ) ) . '#xb-cms-file' );
		exit;
	}

	/** Step 2: the same bytes, imported in development, or published at once by someone who publishes. */
	public static function file_apply(): void {
		self::guard( self::FILE_APPLY );
		$stored = get_transient( self::file_key() );
		$file   = self::kept_file( $stored );
		delete_transient( self::file_key() );
		if ( null === $file ) {
			Menu::back( Menu::url( ReleasesScreen::SLUG ), 'error', __( 'Importazione scaduta: carica di nuovo il file.', 'xb' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- every media file is processed once.
		}
		$publish = isset( $_POST['publish'] ) && current_user_can( 'xb_publish' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$error   = '';
		try {
			$report = PageProfileAdapter::import( (string) file_get_contents( $file ), $publish ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file.
		} catch ( ProfileConflictException | OperationConflictException | \DomainException | \InvalidArgumentException | \RuntimeException $e ) {
			$error = self::refusal( $e );
		}
		// Not in a finally: the redirect exits, and exit skips finally blocks.
		wp_delete_file( $file );
		if ( '' !== $error || ! isset( $report ) ) {
			Menu::back( Menu::url( ReleasesScreen::SLUG ), 'error', $error );
		}
		\XB\Plugin::instance()->versioning->store()->audit(
			'cms_file_import',
			'cms',
			array(
				'operation' => (string) ( $report['operationId'] ?? '' ),
				'published' => (bool) ( $report['published'] ?? false ),
				'changed'   => count( (array) ( $report['changed'] ?? array() ) ),
			)
		);
		$message = ( $report['published'] ?? false )
			? sprintf( /* translators: %d: number of items */ __( 'Pacchetto importato e pubblicato: %d elementi cambiati.', 'xb' ), count( (array) ( $report['changed'] ?? array() ) ) )
			: sprintf( /* translators: %d: number of items */ __( 'Pacchetto importato in sviluppo: %d elementi cambiati. Va online con la prossima release.', 'xb' ), count( (array) ( $report['changed'] ?? array() ) ) );
		// Doc 23 items 234-235: what the person ticked on the plan, once the pages it names are online.
		$site = is_array( $stored['plan']['site'] ?? null ) ? $stored['plan']['site'] : array();
		if ( ( $report['published'] ?? false ) && array() !== $site && current_user_can( 'manage_options' ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
			$chosen = array(
				'front'      => isset( $_POST['site_front'] ),
				'blog'       => isset( $_POST['site_blog'] ),
				'permalinks' => isset( $_POST['site_permalinks'] ),
			);
			// phpcs:enable
			$said = FrontPage::after_import( $site, $chosen );
			if ( array() !== $said ) {
				\XB\Plugin::instance()->versioning->store()->audit( 'cms_file_import_site', 'cms', $chosen );
				$message .= ' ' . implode( ' ', $said );
			}
		}
		Menu::back( Menu::url( ReleasesScreen::SLUG ), 'done', $message );
	}

	private static function action_label( string $action ): string {
		return array(
			'crea'      => __( 'crea', 'xb' ),
			'aggiorna'  => __( 'aggiorna', 'xb' ),
			'invariato' => __( 'invariato', 'xb' ),
		)[ $action ] ?? $action;
	}

	/** The package checked from «Importa un file», at the top of the Release screen: what it would change, and the confirmation. */
	public static function render_plan(): void {
		if ( ! self::can() ) {
			return;
		}
		$post   = admin_url( 'admin-post.php' );
		$stored = isset( $_GET['xbsite'] ) ? get_transient( self::file_key() ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of the user's own pending import.
		$plan   = is_array( $stored ) && is_array( $stored['plan'] ?? null ) && null !== self::kept_file( $stored ) ? $stored['plan'] : null;
		if ( null === $plan ) {
			return;
		}
		?>
		<div class="xb-panel" id="xb-cms-file" style="border-color:var(--xb-accent)">
			<div class="xb-panel-head">
				<span class="xb-stat-icon"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2><?php echo esc_html( sprintf( /* translators: %s: project name */ __( 'Importare il progetto «%s»?', 'xb' ), (string) ( $plan['project']['name'] ?? '' ) ) ); ?></h2>
					<p><?php echo esc_html( sprintf( /* translators: %s: file name */ __( 'Dal file %s. Ecco cosa cambierebbe; nulla è ancora stato scritto.', 'xb' ), (string) ( $stored['name'] ?? '' ) ) ); ?></p>
				</div>
			</div>
			<div class="xb-panel-body">
					<?php $changes = array_values( array_filter( (array) $plan['entities'], static fn ( array $row ): bool => 'invariato' !== ( $row['action'] ?? '' ) ) ); ?>
					<?php if ( ! empty( $plan['alreadyApplied'] ) ) : ?>
						<p><span class="xb-pill is-warn"><?php esc_html_e( 'Questo pacchetto è già stato importato.', 'xb' ); ?></span></p>
					<?php elseif ( array() === $changes && 0 === (int) $plan['deletions'] ) : ?>
						<p><span class="xb-pill"><?php esc_html_e( 'Il sito ha già tutto quello che il pacchetto contiene.', 'xb' ); ?></span></p>
					<?php else : ?>
						<table class="xb-table">
							<tbody>
							<?php foreach ( $changes as $row ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $row['kind'] ); ?></td>
									<td><strong><?php echo esc_html( (string) $row['label'] ); ?></strong><?php echo is_string( $row['role'] ?? null ) ? ' <span class="xb-pill">' . esc_html( $row['role'] ) . '</span>' : ''; ?><?php echo ! empty( $row['script'] ) ? ' <span class="xb-pill is-warn">' . esc_html__( 'con JavaScript', 'xb' ) . '</span>' : ''; ?></td>
									<td><span class="xb-pill is-dev"><?php echo esc_html( self::action_label( (string) $row['action'] ) ); ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( (int) $plan['deletions'] > 0 ) : ?>
							<p><span class="xb-pill is-warn"><?php echo esc_html( sprintf( /* translators: %d: number of items */ __( '%d elementi andrebbero nel Cestino, solo pubblicando.', 'xb' ), (int) $plan['deletions'] ) ); ?></span></p>
						<?php endif; ?>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( $post ); ?>" style="display:inline">
						<?php wp_nonce_field( self::FILE_APPLY ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::FILE_APPLY ); ?>">
						<?php self::render_site_offers( is_array( $plan['site'] ?? null ) ? $plan['site'] : array(), (string) ( $plan['project']['defaultLocale'] ?? '' ) ); ?>
						<button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Importa in sviluppo', 'xb' ); ?></button>
						<?php if ( current_user_can( 'xb_publish' ) ) : ?>
							<button class="xb-btn" name="publish" value="1"><?php esc_html_e( 'Importa e pubblica', 'xb' ); ?></button>
						<?php endif; ?>
					</form>
					<form method="post" action="<?php echo esc_url( $post ); ?>" style="display:inline">
						<?php wp_nonce_field( self::FILE_CHECK ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::FILE_CHECK ); ?>">
						<button class="xb-btn" name="cancel" value="1"><?php esc_html_e( 'Annulla', 'xb' ); ?></button>
					</form>
					<p class="xb-muted"><?php esc_html_e( 'In sviluppo: niente va online finché non pubblichi una release. «Importa e pubblica» mette online quello che il pacchetto contiene, con gli stati che ha.', 'xb' ); ?></p>
				</div>
		</div>
		<?php
	}

	/**
	 * Doc 23 items 234-236: what the site still needs to look as in Publish, each a box the person ticks or not.
	 * They apply with «Importa e pubblica» only: the pages they name must be online.
	 *
	 * @param array<string, mixed> $site   The plan's `site`.
	 * @param string               $locale The project's language (BCP 47).
	 */
	private static function render_site_offers( array $site, string $locale ): void {
		$offers   = FrontPage::offers( $site );
		$language = self::language_note( $locale );
		if ( array() === $offers && '' === $language ) {
			return;
		}
		?>
		<fieldset class="xb-cms-offers" style="margin:0 0 12px">
			<legend><strong><?php esc_html_e( 'Perché il sito sia come in Publish', 'xb' ); ?></strong></legend>
			<?php if ( isset( $offers['front'] ) ) : ?>
				<p><label><input type="checkbox" name="site_front" value="1"<?php checked( $offers['front']['on'] ); ?>>
				<?php
				echo esc_html(
					null === $offers['front']['blog']
						/* translators: %s: page title */
						? sprintf( __( 'La pagina «%s» del progetto diventa la pagina iniziale del sito.', 'xb' ), $offers['front']['home'] )
						/* translators: 1: home page title, 2: posts page title */
						: sprintf( __( 'La pagina «%1$s» del progetto diventa la pagina iniziale del sito, e «%2$s» la pagina degli articoli.', 'xb' ), $offers['front']['home'], $offers['front']['blog'] )
				);
				?>
				</label></p>
			<?php endif; ?>
			<?php if ( isset( $offers['blog'] ) ) : ?>
				<p><label><input type="checkbox" name="site_blog" value="1"<?php checked( $offers['blog']['on'] ); ?>> <?php esc_html_e( 'Crea la pagina «Blog» con l\'elenco degli articoli, a /blog/, come in Publish.', 'xb' ); ?></label></p>
			<?php endif; ?>
			<?php if ( isset( $offers['permalinks'] ) ) : ?>
				<p><label><input type="checkbox" name="site_permalinks" value="1"<?php checked( $offers['permalinks']['on'] ); ?>> <?php esc_html_e( 'Articoli a /blog/nome-articolo/, categorie a /category/… e tag a /tag/…, come in Publish. Vale per tutti gli articoli del sito; i vecchi indirizzi portano ai nuovi.', 'xb' ); ?></label></p>
			<?php endif; ?>
			<?php if ( array() !== $offers ) : ?>
				<p class="xb-muted"><?php esc_html_e( 'Queste scelte valgono con «Importa e pubblica»: con «Importa in sviluppo» si fanno dopo, in Impostazioni → Lettura e Permalink.', 'xb' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $language ) : ?>
				<p><span class="xb-pill is-warn"><?php echo esc_html( $language ); ?></span></p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Doc 23 item 236: what the dates and texts of the site will look like against Publish, said before the import.
	 * Month and day names follow the project (Modifiers::project_names) when this PHP knows its language; a site in
	 * another language keeps WordPress's own texts in its own.
	 */
	public static function language_note( string $locale ): string {
		$site     = determine_locale();
		$speaks   = \XB\Expr\Modifiers::intl_speaks( $locale );
		$language = strtolower( (string) strtok( str_replace( '-', '_', $locale ), '_' ) );
		if ( '' === $locale || $speaks && $language === strtolower( (string) strtok( $site, '_' ) ) ) {
			return '';
		}
		if ( ! $speaks && $language === strtolower( (string) strtok( $site, '_' ) ) ) {
			/* translators: %s: project language */
			return sprintf( __( 'Questo server non ha i nomi dei mesi in %s: nelle date useranno quelli di WordPress, che senza il pacchetto della lingua sono in inglese. Installa la lingua del sito in Impostazioni → Generali.', 'xb' ), $locale );
		}
		return $speaks
			/* translators: 1: project language, 2: site language */
			? sprintf( __( 'Il progetto è in %1$s, il sito in %2$s: le date useranno i mesi e i giorni del progetto, come in Publish; i testi di WordPress (moduli, ricerca, pagine d\'errore) restano nella lingua del sito.', 'xb' ), $locale, $site )
			/* translators: 1: project language, 2: site language */
			: sprintf( __( 'Il progetto è in %1$s, il sito in %2$s, e questo server non ha i nomi dei mesi della lingua del progetto: le date usciranno nella lingua del sito. Per averle come in Publish, metti il sito nella lingua del progetto (Impostazioni → Generali).', 'xb' ), $locale, $site );
	}

	/** «Scarica il progetto per Histeon Publish»: the same package as a file, for the Export section. */
	public static function export_form(): void {
		if ( ! self::can() ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
			<?php wp_nonce_field( self::FILE_EXPORT ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::FILE_EXPORT ); ?>">
			<button class="xb-btn"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Scarica il progetto per Histeon Publish', 'xb' ); ?></button>
			<span class="xb-muted"><?php esc_html_e( 'Un file .xbsite con pagine, articoli, componenti, template, stili, contenuti e media, da aprire in Histeon Publish (Importa .xbsite) anche senza collegamento.', 'xb' ); ?></span>
		</form>
		<?php
	}

	/**
	 * Saves who answers the names of «Importa HTML» (Import\ChoicesProvider): provider, server, model and keys, which
	 * are stored encrypted and never shown again.
	 */
	public static function save_choices(): void {
		self::guard( self::CHOICES );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$forget = array();
		foreach ( array( \XB\Import\ChoicesProvider::HAIKU, \XB\Import\ChoicesProvider::COMPATIBLE ) as $provider ) {
			if ( isset( $_POST[ 'forget_' . $provider ] ) ) {
				$forget[] = $provider;
			}
		}
		$haiku_key      = trim( wp_unslash( (string) ( $_POST['haiku_key'] ?? '' ) ) ); // Checked by ChoicesProvider::save(); never echoed.
		$compatible_key = trim( wp_unslash( (string) ( $_POST['compatible_key'] ?? '' ) ) );
		$here           = Menu::url( ReleasesScreen::SLUG ) . '#xb-html-import-model';
		try {
			\XB\Import\ChoicesProvider::save(
				sanitize_key( wp_unslash( (string) ( $_POST['provider'] ?? '' ) ) ),
				esc_url_raw( wp_unslash( (string) ( $_POST['url'] ?? '' ) ), array( 'http', 'https' ) ),
				sanitize_text_field( wp_unslash( (string) ( $_POST['model'] ?? '' ) ) ),
				$haiku_key,
				$compatible_key,
				$forget
			);
		} catch ( \InvalidArgumentException $e ) {
			Menu::back( $here, 'error', $e->getMessage() );
		}
		// phpcs:enable
		\XB\Plugin::instance()->versioning->store()->audit(
			'import_choices_config',
			'cms',
			array(
				'provider'   => \XB\Import\ChoicesProvider::settings()['provider'],
				'keyChanged' => '' !== $haiku_key || '' !== $compatible_key || array() !== $forget,
			)
		);
		Menu::back( $here, 'done', __( 'Modello per i nomi salvato.', 'xb' ) );
	}

	/**
	 * «Importa HTML» (doc 18 F6 of Histeon Publish): a page, a site's ZIP or its folder, converted in the administrator's
	 * browser by Histeon Publish's own converter and import (assets/html-import), into a `.xbsite` that goes to «Controlla»
	 * like any package: the server checks it and shows the plan; nothing is written before the confirmation. Without
	 * unfiltered_html the site's scripts could not be saved, so the recipes without JavaScript are the only choice.
	 */
	public static function render_html_import(): void {
		if ( ! self::can() ) {
			return;
		}
		$post = admin_url( 'admin-post.php' );
		wp_enqueue_script_module( 'xb-html-import', plugin_dir_url( XB_FILE ) . 'assets/html-import/screen.js', array(), \XB\Plugin::asset_version( 'assets/html-import/screen.js' ) );
		$project = PageProfileAdapter::can_initialize_project() ? '' : (string) get_option( 'xb_page_profile_project_id', '' );
		$scripts = current_user_can( 'unfiltered_html' );
		$model   = \XB\Import\ChoicesProvider::public_view();
		$colors  = self::add_page_framework();
		$config  = array(
			// Doc 27 G4: the names a model proposes, asked through the plugin's own route, never the provider.
			'refine'    => array(
				'url'      => rest_url( \XB\Rest\Controller::NAMESPACE . \XB\Import\ChoicesRest::ROUTE ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'ready'    => $model['ready'],
				'provider' => $model['provider'],
				'model'    => $model['modelName'],
				'batch'    => \XB\Import\Choices::BATCH_SIZE,
				'max'      => \XB\Import\ChoicesRest::MAX,
				// «Claude dal connettore»: the page waits for the agent through the Histeon Connector on this computer,
				// with this user's pairing (the builder's ✦ AI), then sends the answer to be checked here.
				'answer'   => rest_url( \XB\Rest\Controller::NAMESPACE . \XB\Import\ChoicesRest::ANSWER ),
				'connector' => \XB\Import\ChoicesProvider::CONNECTOR === $model['provider']
					? array(
						'port'   => BuilderScreen::CONNECTOR_PORT,
						'secret' => BuilderScreen::connector_secret( get_current_user_id() ),
						'system' => \XB\Import\Choices::SYSTEM_PROMPT,
					)
					: null,
			),
			// V5.2, «Importa una pagina di questo sito»: the two readings of AddPageRest, and the paths the page never
			// downloads (wp-admin, the REST API, the login), whatever folder WordPress lives in.
			'site'      => array(
				'list'      => rest_url( \XB\Rest\Controller::NAMESPACE . \XB\Import\AddPageRest::LIST ),
				'base'      => rest_url( \XB\Rest\Controller::NAMESPACE . \XB\Import\AddPageRest::BASE ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				// V5.6: the framework the page is converted for is the site's, never the select of «Converti»; «I colori
				// del sito diventano quelli della pagina» only where the site's colours are Histeon CSS's variables.
				'framework' => $colors['framework'],
				'replace'   => $colors['replace'],
				'forbidden' => array_values(
					array_unique(
						array(
							untrailingslashit( (string) wp_parse_url( admin_url(), PHP_URL_PATH ) ),
							untrailingslashit( (string) wp_parse_url( rest_url(), PHP_URL_PATH ) ),
							(string) wp_parse_url( wp_login_url(), PHP_URL_PATH ),
						)
					)
				),
			),
			'projectId' => 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $project ) ? strtolower( $project ) : null,
			'strings'   => array(
				'working'   => __( 'Conversione in corso, in questo browser…', 'xb' ),
				'ready'     => __( 'Convertito. Prosegui al controllo: il sito mostra cosa scriverebbe, e nulla è scritto prima della conferma.', 'xb' ),
				'failed'    => __( 'Conversione non riuscita:', 'xb' ),
				'noFiles'   => __( 'Scegli una pagina HTML, uno ZIP o una cartella.', 'xb' ),
				'noPage'    => __( 'Fra i file non c’è una pagina HTML.', 'xb' ),
				'oneSource' => __( 'Scegli i file oppure una cartella, non entrambi.', 'xb' ),
				'tooBig'    => __( 'Il sito supera i limiti: 5000 file o 200 MB.', 'xb' ),
				/* translators: 1: percentage, 2: selectable elements, 3: elements of the site */
				'measure'   => __( 'Elementi selezionabili uno per uno nel builder: %1$s%% (%2$s su %3$s).', 'xb' ),
				/* translators: %d: number of files */
				'refused'   => __( '%d file con un percorso non ammesso lasciati fuori.', 'xb' ),
				// Lista della spesa 163: the report by what happened.
				'groups'    => array(
					'removed'    => __( 'Tolto', 'xb' ),
					'script'     => __( 'Portato nel JavaScript della pagina', 'xb' ),
					'breakpoint' => __( 'Media query e breakpoint', 'xb' ),
					'check'      => __( 'Da controllare', 'xb' ),
				),
				// V5.2: «Importa una pagina di questo sito».
				'site'      => array(
					'loading'    => __( 'Carico l’elenco delle pagine…', 'xb' ),
					/* translators: %s: reason */
					'listFailed' => __( 'L’elenco delle pagine non si è caricato: %s', 'xb' ),
					'empty'      => __( 'Nessuna pagina pubblicata da importare.', 'xb' ),
					/* translators: %d: pages */
					'histeon'    => __( '%d pagine fatte con Histeon non sono nell’elenco: sono già pagine di Histeon, non serve importarle.', 'xb' ),
					/* translators: %d: pages */
					'protected'  => __( '%d pagine con password non sono nell’elenco: un visitatore non ne vede il contenuto.', 'xb' ),
					/* translators: %d: pages */
					'more'       => __( 'L’elenco ha solo le prime %d pagine.', 'xb' ),
					'choose'     => __( 'Scegli una pagina dell’elenco.', 'xb' ),
					/* translators: %d: pages shown */
					'shown'      => __( 'Pagine trovate: %d.', 'xb' ),
					'fetching'   => __( 'Scarico la pagina come la vede un visitatore…', 'xb' ),
					/* translators: %d: files */
					'assets'     => __( 'Scarico fogli di stile, immagini e caratteri dello stesso sito (%d)…', 'xb' ),
					'base'       => __( 'Leggo gli stili del sito…', 'xb' ),
					'converting' => __( 'Converto la pagina in questo browser…', 'xb' ),
					'otherSite'  => __( 'La pagina non è su questo sito, o porta a un altro sito: non si scarica.', 'xb' ),
					'forbidden'  => __( 'La pagina porta all’amministrazione, all’accesso o all’API del sito: non si scarica.', 'xb' ),
					/* translators: %d: HTTP status */
					'status'     => __( 'La pagina ha risposto con il codice %d: un visitatore non la vede.', 'xb' ),
					'notHtml'    => __( 'All’indirizzo della pagina non c’è una pagina HTML.', 'xb' ),
					/* translators: %s: reason */
					'baseFailed' => __( 'Gli stili del sito non si sono letti: %s', 'xb' ),
					/* translators: %d: files */
					'skipped'    => __( '%d file dello stesso sito non scaricati (indirizzi non ammessi, non trovati, o oltre i limiti).', 'xb' ),
					/* translators: %s: the new page's address */
					'address'    => __( 'La pagina nuova avrà l’indirizzo %s; la pagina d’origine resta com’è.', 'xb' ),
					/* translators: %s: slug */
					'slug'       => __( 'Nel progetto la pagina si chiama «%s» ed è una pagina nuova, in sviluppo.', 'xb' ),
					/* translators: 1: styles, 2: sheets, 3: media, 4: media queries */
					'entered'    => __( 'Entrano: %1$d stili nuovi, %2$d fogli della pagina, %3$d media nuovi, %4$d media query.', 'xb' ),
					/* translators: 1: styles, 2: media */
					'same'       => __( 'Già uguali nel sito, non ripetuti: %1$d stili, %2$d media.', 'xb' ),
					/* translators: 1: selectors, 2: variables, 3: media queries */
					'kept'       => __( 'Il sito li aveva già diversi: %1$d selettori (quelli della pagina valgono solo su questa pagina, altrove restano quelli del sito), %2$d variabili e %3$d media query (restano quelle del sito, non importate).', 'xb' ),
					'design'     => __( 'Le impostazioni di design della pagina (caratteri, misure, ombre) non entrano nel sito: restano quelle del sito. Per i colori vale la scelta fatta.', 'xb' ),
					/* translators: %d: sheets */
					/* translators: %s: the page's mark, an HTML attribute */
					'scoped'     => __( 'Gli stili della pagina valgono solo su questa pagina (il segno %s sui suoi elementi principali): le altre pagine del sito non cambiano.', 'xb' ),
					'scopeLimit' => __( 'Un elemento nuovo aggiunto nel builder al primo livello della pagina non prende gli stili della pagina: mettilo dentro una sezione che c’è già.', 'xb' ),
					/* translators: 1: kind of name, 2: the page's name, 3: the new name */
					'renamedCss' => __( '%1$s «%2$s» della pagina entra come «%3$s»: il sito ha già quel nome.', 'xb' ),
					'renamedKinds' => array(
						'animazione' => __( 'Animazione', 'xb' ),
						'carattere'  => __( 'Carattere', 'xb' ),
						'contatore'  => __( 'Stile di contatore', 'xb' ),
						'proprieta'  => __( 'Proprietà CSS registrata', 'xb' ),
					),
					// V5.6: the colours of the added page (result.addPage.colors of the converter).
					'colors'     => array(
						/* translators: %d: values */
						'pagina'      => __( 'Colori: la pagina tiene i suoi colori (%d valori rimessi come colori della pagina). Il sito non cambia.', 'xb' ),
						'sito'        => __( 'Colori: la pagina prende i colori del sito. Il sito non cambia.', 'xb' ),
						/* translators: %d: CSS variables */
						'sostituisci' => __( 'Colori: i colori del sito diventano quelli della pagina; cambiano %d variabili, in tutte le pagine del sito.', 'xb' ),
						'page'        => __( 'Colori del marchio della pagina, che la conversione scrive come variabili:', 'xb' ),
						'none'        => __( 'La pagina non ha colori del marchio diventati variabili: i suoi colori restano quelli della pagina, qualunque sia la scelta.', 'xb' ),
						'others'      => __( 'Gli altri colori della pagina sono valori scritti nella pagina e restano suoi in ogni scelta.', 'xb' ),
						'families'    => array(
							'primary'   => __( 'colore principale', 'xb' ),
							'secondary' => __( 'colore secondario', 'xb' ),
						),
						'title'       => __( 'I colori del sito diventano quelli della pagina', 'xb' ),
						'whole'       => __( 'Cambia l’aspetto di tutto il sito, solo nei colori: queste variabili cambiano in tutte le pagine, in sviluppo fino alla release. Caratteri, misure e il resto del design restano com’erano.', 'xb' ),
						'settings'    => __( 'Impostazioni del design che cambiano', 'xb' ),
						'light'       => __( 'Tema chiaro', 'xb' ),
						'dark'        => __( 'Tema scuro', 'xb' ),
						/* translators: 1: CSS variable, 2: sheet */
						'covered'     => __( 'La variabile %1$s è dichiarata anche nel foglio «%2$s»: dove vale quella regola il colore non cambia.', 'xb' ),
						'confirm'     => __( 'Sì, cambia i colori del sito', 'xb' ),
						'cancel'      => __( 'Annulla', 'xb' ),
						'waiting'     => __( 'Guarda i colori che cambiano, poi conferma o annulla: il pacchetto non va al controllo prima della conferma.', 'xb' ),
						'cancelled'   => __( 'Annullato: i colori del sito non cambiano. Scegli di nuovo i colori e importa la pagina.', 'xb' ),
					),
					/* translators: 1: media name, 2: new name */
					'renamed'    => __( '%1$s entra come %2$s: il sito ha già un media con quel nome e un contenuto diverso.', 'xb' ),
					/* translators: %s: reason */
					'leftOut'    => __( 'Rimasto fuori: %s', 'xb' ),
					'keptList'   => __( 'Selettori, variabili e media query del sito rimasti com’erano', 'xb' ),
					// The converter's AddPageError, by code.
					'codes'      => array(
						'base-non-valida'    => __( 'Gli stili del sito letti per la pagina non sono validi: ricarica la schermata e riprova.', 'xb' ),
						'base-hash'          => __( 'Gli stili del sito sono cambiati mentre li leggevi: ricarica la schermata e riprova.', 'xb' ),
						'base-progetto'      => __( 'La pagina non è stata preparata per il progetto di questo sito: ricarica la schermata e riprova.', 'xb' ),
						'base-file-mancante' => __( 'Manca un’immagine o un carattere che gli stili del sito usano: ricarica la schermata e riprova.', 'xb' ),
						'prototipo'          => __( 'La pagina è un prototipo di Histeon: importala con «Converti».', 'xb' ),
						'piu-pagine'         => __( 'Si aggiunge una pagina alla volta.', 'xb' ),
						'slug-mancante'      => __( 'La pagina non ha un indirizzo né un titolo da cui ricavare il suo nome.', 'xb' ),
						/* translators: %s: slug */
						'slug-usato'         => __( 'Il sito ha già una pagina «%s».', 'xb' ),
						'entita-inattese'    => __( 'La conversione ha dato più di una pagina con i suoi stili e media: la pagina non è stata importata.', 'xb' ),
						/* translators: %s: framework */
						'colori-senza-variabili' => __( 'I colori di questo sito non sono variabili di Histeon CSS (framework: %s): scegli «La pagina tiene i suoi colori» o «La pagina prende i colori del sito».', 'xb' ),
						'colori-pagina-assenti'  => __( 'La pagina non ha colori del marchio che diventino variabili: non c’è niente da sostituire nel sito. Scegli «La pagina tiene i suoi colori».', 'xb' ),
					),
				),
				// Doc 27 G4: «Rifinisci i nomi con un modello», as in Histeon Publish.
				'refine'    => array(
					/* translators: 1: batch, 2: batches */
					'asking'      => __( 'Il modello sta scegliendo i nomi (lotto %1$d di %2$d)…', 'xb' ),
					/* translators: 1: model, 2: provider, 3: seconds, 4: names, 5: alternative texts */
					'summary'     => __( 'Proposte di %1$s (%2$s) in %3$d s: %4$d nomi, %5$d testi alternativi.', 'xb' ),
					'haiku'       => __( 'Claude API', 'xb' ),
					'compatibile' => __( 'server compatibile', 'xb' ),
					'connettore'  => __( 'dal connettore', 'xb' ),
					'waiting'     => __( 'In attesa di Claude: in Claude Code o Claude Desktop, con il connettore di Histeon, scrivi «Rispondi alle scelte dei nomi di Histeon». La domanda aspetta 15 minuti.', 'xb' ),
					'checking'    => __( 'Controllo dei nomi di Claude…', 'xb' ),
					'off'         => __( 'Il connettore di Histeon non risponde su questo computer: avvialo con i comandi del builder (✦ AI → Connettore AI), oppure apri Claude Code o Claude Desktop con il connettore, poi riprova.', 'xb' ),
					'otherPair'   => __( 'Il connettore è accoppiato con un altro sito o con un segreto vecchio: ricopia le istruzioni dal builder (✦ AI → Connettore AI) e riavvialo.', 'xb' ),
					'impostor'    => __( 'Sulla porta del connettore risponde un programma che non conosce l’accoppiamento: la domanda non è partita.', 'xb' ),
					'insecure'    => __( 'Il connettore richiede l’amministrazione in HTTPS (o su localhost).', 'xb' ),
					'late'        => __( 'Claude non ha risposto in tempo dal connettore.', 'xb' ),
					/* translators: 1: failed batches, 2: batches, 3: reason */
					'failed'      => __( '%1$d lotti su %2$d senza risposta (%3$s): quei nomi restano quelli del convertitore.', 'xb' ),
					/* translators: 1: choices left out, 2: choices asked */
					'skipped'     => __( '%1$d scelte oltre le prime %2$d restano coi nomi del convertitore.', 'xb' ),
					'none'        => __( 'Il modello non ha proposto nomi diversi da quelli del convertitore.', 'xb' ),
					'nothing'     => __( 'Non ci sono nomi da rifinire: un prototipo Histeon si importa così com’è.', 'xb' ),
					'before'      => __( 'Prima', 'xb' ),
					'after'       => __( 'Dopo', 'xb' ),
					'where'       => __( 'Dove', 'xb' ),
					'apply'       => __( 'Applica', 'xb' ),
					/* translators: 1: proposed name, 2: current name */
					'applyRow'    => __( 'Applica %1$s al posto di %2$s', 'xb' ),
					'alts'        => __( 'Testi alternativi suggeriti: il modello non vede le immagini, accettali uno per uno.', 'xb' ),
					'accept'      => __( 'Accetta', 'xb' ),
					/* translators: %d: answers */
					'rejected'    => __( '%d risposte del modello lasciate fuori', 'xb' ),
					'applyAll'    => __( 'Applica tutte', 'xb' ),
					'applyPicked' => __( 'Applica le scelte segnate', 'xb' ),
					/* translators: %d: names */
					'applied'     => __( 'Nomi scelti nel prototipo: %d.', 'xb' ),
					'reset'       => __( 'Torna ai nomi del convertitore', 'xb' ),
					/* translators: %d: choices */
					'notApplied'  => __( '%d scelte non applicate', 'xb' ),
					'cancel'      => __( 'Annulla', 'xb' ),
					'stopped'     => __( 'fermato', 'xb' ),
					'applying'    => __( 'Conversione con i nomi scelti…', 'xb' ),
					'kinds'       => array(
						'classe'     => __( 'classe', 'xb' ),
						'componente' => __( 'componente', 'xb' ),
						'prop'       => __( 'campo', 'xb' ),
						'tipo'       => __( 'tipo di contenuto', 'xb' ),
						'alt'        => __( 'testo alternativo', 'xb' ),
					),
				),
			),
		);
		?>
		<h2 class="xb-section-title"><?php esc_html_e( 'Converti una pagina HTML', 'xb' ); ?> <small><?php esc_html_e( 'una pagina, lo ZIP di un sito o la sua cartella diventano un progetto Histeon', 'xb' ); ?></small></h2>
		<div class="xb-panel" id="xb-html-import" data-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
			<div class="xb-panel-body">
				<p class="xb-muted"><?php esc_html_e( 'Una pagina HTML, lo ZIP di un sito o la sua cartella diventano un progetto Histeon da modificare elemento per elemento. Un prototipo Histeon in un solo file si importa così com’è, con template, componenti, contenuti e loop. La conversione avviene in questo browser e nulla del sito viene eseguito; i file di altri siti (immagini, librerie) non si scaricano qui: caricali in WordPress, o converti il sito con Histeon Publish.', 'xb' ); ?></p>
				<fieldset class="xb-site-page" data-site>
					<legend><?php esc_html_e( 'Importa una pagina di questo sito', 'xb' ); ?></legend>
					<p class="xb-muted"><?php esc_html_e( 'Si copia l’aspetto di una pagina che il sito ha già, fatta con un altro strumento: nasce una pagina nuova di Histeon, in sviluppo, e la pagina d’origine resta com’è. La struttura resta da rifinire nel builder: parti e componenti non si creano.', 'xb' ); ?></p>
					<p class="xb-muted"><?php esc_html_e( 'La pagina si scarica in questo browser come la vede un visitatore, con i fogli di stile e le immagini di questo sito; i caratteri e i file di altri siti non entrano, e niente della pagina viene eseguito.', 'xb' ); ?></p>
					<p><button type="button" class="xb-btn" data-site-open><?php esc_html_e( 'Importa una pagina di questo sito', 'xb' ); ?></button></p>
					<div class="xb-site-page__picker" data-site-picker hidden>
						<p><label for="xb-site-page-search"><?php esc_html_e( 'Cerca una pagina', 'xb' ); ?></label><br><input id="xb-site-page-search" type="search" class="regular-text" data-site-search autocomplete="off"></p>
						<p><label for="xb-site-page-list"><?php esc_html_e( 'Pagina da importare', 'xb' ); ?></label><br><select id="xb-site-page-list" class="xb-site-page__list" size="8" data-site-list></select></p>
						<p class="xb-muted" role="status" data-site-count></p>
						<ul class="xb-muted xb-site-page__left" data-site-left></ul>
						<fieldset class="xb-site-page__colors" data-site-colors>
							<legend><?php esc_html_e( 'Colori della pagina', 'xb' ); ?></legend>
							<p class="xb-muted"><?php esc_html_e( 'La conversione scrive i colori del marchio della pagina come variabili, e nel sito una variabile ha il valore del sito. Quali colori vuoi?', 'xb' ); ?></p>
							<p><label><input type="radio" name="xb-site-page-colors" value="pagina" checked> <?php esc_html_e( 'La pagina tiene i suoi colori', 'xb' ); ?></label><br><span class="xb-muted"><?php esc_html_e( 'Il sito non cambia, e la pagina ha i colori dell’originale.', 'xb' ); ?></span></p>
							<p><label><input type="radio" name="xb-site-page-colors" value="sito"> <?php esc_html_e( 'La pagina prende i colori del sito', 'xb' ); ?></label><br><span class="xb-muted"><?php esc_html_e( 'Il sito non cambia; i colori del marchio della pagina diventano quelli del sito.', 'xb' ); ?></span></p>
							<?php if ( $colors['replace'] ) : ?>
								<p><label><input type="radio" name="xb-site-page-colors" value="sostituisci"> <?php esc_html_e( 'I colori del sito diventano quelli della pagina', 'xb' ); ?></label><br><span class="xb-muted"><?php esc_html_e( 'Cambia l’aspetto di tutto il sito, solo nei colori: prima di proseguire vedi le variabili che cambiano, prima → dopo, e confermi.', 'xb' ); ?></span></p>
							<?php else : ?>
								<p class="xb-muted" data-site-colors-why><?php esc_html_e( 'La terza scelta, «I colori del sito diventano quelli della pagina», non c’è: il sito non usa Histeon CSS (il framework di Histeon con il modulo Histeon CSS attivo), quindi i suoi colori non sono variabili che l’importazione possa cambiare.', 'xb' ); ?></p>
							<?php endif; ?>
						</fieldset>
						<p><button type="button" class="xb-btn xb-btn--primary" data-site-import><?php esc_html_e( 'Importa questa pagina', 'xb' ); ?></button></p>
					</div>
				</fieldset>
				<p>
					<label><?php esc_html_e( 'Pagina, file o ZIP', 'xb' ); ?> <input type="file" data-pages multiple accept=".html,.htm,.zip,.css,.png,.jpg,.jpeg,.gif,.webp,.avif,.svg,.woff,.woff2,.js"></label>
					<label><?php esc_html_e( 'oppure una cartella', 'xb' ); ?> <input type="file" data-folder webkitdirectory></label>
				</p>
				<p>
					<label><?php esc_html_e( 'Framework di arrivo', 'xb' ); ?>
						<select data-framework>
							<option value="histos-css" selected><?php esc_html_e( 'Histeon CSS (consigliato, libero)', 'xb' ); ?></option>
							<option value="acss"><?php esc_html_e( 'Automatic.css (se è attivo nel sito)', 'xb' ); ?></option>
							<option value="core"><?php esc_html_e( 'Core Framework (preset predefinito)', 'xb' ); ?></option>
							<option value="none"><?php esc_html_e( 'Nessuno: valori così come sono', 'xb' ); ?></option>
						</select>
					</label>
				</p>
				<p>
					<label><input type="checkbox" data-recipes <?php echo $scripts ? '' : 'checked disabled'; ?>> <?php esc_html_e( 'Senza JavaScript: usa le ricette (menu, fisarmoniche, schede, caroselli, finestre modali) al posto degli script del sito', 'xb' ); ?></label>
					<?php if ( ! $scripts ) : ?>
						<br><span class="xb-muted"><?php esc_html_e( 'Il tuo utente non può salvare JavaScript (permesso unfiltered_html): gli script del sito non verranno salvati.', 'xb' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<label><input type="checkbox" data-refine <?php disabled( ! $model['ready'] ); ?>> <?php esc_html_e( 'Rifinisci i nomi con un modello', 'xb' ); ?></label>
					<br><span class="xb-muted">
					<?php
					echo esc_html(
						$model['ready']
							/* translators: %s: model */
							? sprintf( __( 'Dopo la conversione %s propone nomi più chiari per classi, componenti, campi e tipi: li vedi prima → dopo e scegli quali applicare. Non scrive codice; il sito non cambia finché non prosegui al controllo.', 'xb' ), $model['modelName'] )
							: (string) \XB\Import\ChoicesProvider::not_ready()
					);
					?>
					</span>
				</p>
				<p><button type="button" class="xb-btn xb-btn--primary" data-convert><?php esc_html_e( 'Converti', 'xb' ); ?></button> <span role="status" data-status></span> <button type="button" class="xb-btn" data-refine-cancel hidden><?php esc_html_e( 'Annulla', 'xb' ); ?></button></p>
				<p class="notice notice-error inline" role="alert" data-error hidden></p>
				<section class="xb-site-colors" data-colors-confirm aria-labelledby="xb-site-colors-title" hidden></section>
				<div data-result hidden>
					<p><strong data-measure></strong> <span data-refused></span></p>
					<ul class="xb-site-page__report" data-added hidden></ul>
					<fieldset class="xb-refine" data-refine-view hidden></fieldset>
					<ul data-notes></ul>
					<p><a href="#" data-prototype><?php esc_html_e( 'Scarica il prototipo', 'xb' ); ?></a></p>
					<form method="post" action="<?php echo esc_url( $post ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( self::FILE_CHECK ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::FILE_CHECK ); ?>">
						<input type="file" name="package" data-package hidden>
						<button class="xb-btn xb-btn--primary" data-continue><?php esc_html_e( 'Prosegui al controllo', 'xb' ); ?></button>
					</form>
				</div>
				<?php self::render_choices_settings( $model ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * V5.6: the framework «Importa una pagina di questo sito» converts for, as the converter names it, and whether the
	 * site's colours can take the page's. Only with Histeon's own framework and the Histeon CSS module on does the site
	 * print its design (Frameworks::enqueue), so only then are its colours variables the package can change.
	 *
	 * @return array{framework: string, replace: bool}
	 */
	private static function add_page_framework(): array {
		$id = \XB\Plugin::instance()->frameworks->active()->id();
		if ( 'xb-tokens' === $id ) {
			$histeon = \XB\Framework\HistosCss\Module::active();
			return array(
				'framework' => $histeon ? 'histos-css' : 'none',
				'replace'   => $histeon,
			);
		}
		$map = array(
			'acss'           => 'acss',
			'core-framework' => 'core',
		);
		return array(
			'framework' => $map[ $id ] ?? 'none',
			'replace'   => false,
		);
	}

	/**
	 * «Modello per i nomi»: who answers the names of «Importa HTML». Claude dal connettore (recommended, no key), Claude
	 * Haiku 5.5 with a key (any hosting), or a server compatible with OpenAI's chat API for a site next to a model on the
	 * same computer.
	 *
	 * @param array{provider: string, url: string, model: string, modelName: string, haikuKeySet: bool, haikuFromConfig: bool, compatibleKeySet: bool, unreadable: bool, salts: bool, ready: bool} $model
	 */
	private static function render_choices_settings( array $model ): void {
		$haiku = \XB\Import\ChoicesProvider::HAIKU;
		$other = \XB\Import\ChoicesProvider::COMPATIBLE;
		?>
		<details class="xb-refine-settings" id="xb-html-import-model" <?php echo $model['ready'] ? '' : 'open'; ?>>
			<summary><?php esc_html_e( 'Modello per i nomi', 'xb' ); ?> <span class="xb-muted"><?php echo esc_html( $model['modelName'] ); ?></span></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<?php wp_nonce_field( self::CHOICES ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CHOICES ); ?>">
				<p class="xb-muted"><?php esc_html_e( 'Il modello riceve solo i nomi da scegliere e un breve contesto di ciascuno (tag, classi vicine, un pezzo di testo) e risponde con dei nomi, che il plugin controlla uno per uno. Con Claude dal connettore la pagina aspetta il Claude del tuo abbonamento sul tuo computer, senza chiave. Con Claude Haiku e con il server compatibile le chiamate partono da questo server, mai dal browser; con Claude Haiku si consumano crediti del tuo account Anthropic.', 'xb' ); ?></p>
				<?php if ( $model['unreadable'] ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Una chiave salvata non si può più decifrare (le chiavi di wp-config.php sono cambiate): inseriscila di nuovo.', 'xb' ); ?></p></div>
				<?php elseif ( ! $model['salts'] ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'AUTH_KEY e AUTH_SALT in wp-config.php mancano o sono quelle di esempio: le chiavi non possono essere salvate nel database. Puoi definire XB_ANTHROPIC_API_KEY in wp-config.php.', 'xb' ); ?></p></div>
				<?php endif; ?>
				<fieldset>
					<legend><?php esc_html_e( 'Chi sceglie i nomi', 'xb' ); ?></legend>
					<p><label><input type="radio" name="provider" value="<?php echo esc_attr( \XB\Import\ChoicesProvider::CONNECTOR ); ?>" <?php checked( $model['provider'], \XB\Import\ChoicesProvider::CONNECTOR ); ?>> <?php esc_html_e( 'Claude dal connettore (consigliato: il Claude del tuo abbonamento, in Claude Code o Claude Desktop con il connettore di Histeon; nessuna chiave)', 'xb' ); ?></label></p>
					<p><label><input type="radio" name="provider" value="<?php echo esc_attr( $haiku ); ?>" <?php checked( $model['provider'], $haiku ); ?>> <?php esc_html_e( 'Claude Haiku 5.5 con una chiave API (funziona su qualsiasi hosting, anche senza il connettore)', 'xb' ); ?></label></p>
					<p><label><input type="radio" name="provider" value="<?php echo esc_attr( $other ); ?>" <?php checked( $model['provider'], $other ); ?>> <?php esc_html_e( 'Un server compatibile con OpenAI (llama-server, LM Studio, Ollama), per un sito sullo stesso computer del modello', 'xb' ); ?></label></p>
				</fieldset>
				<p>
					<label for="xb-refine-haiku-key"><?php esc_html_e( 'Chiave API di Anthropic', 'xb' ); ?></label><br>
					<?php if ( $model['haikuFromConfig'] ) : ?>
						<?php esc_html_e( 'Definita in wp-config.php (XB_ANTHROPIC_API_KEY).', 'xb' ); ?>
					<?php else : ?>
						<input id="xb-refine-haiku-key" name="haiku_key" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $model['haikuKeySet'] ? __( 'impostata: lascia vuoto per non cambiarla', 'xb' ) : 'sk-ant-…' ); ?>">
						<?php if ( $model['haikuKeySet'] ) : ?>
							<label><input type="checkbox" name="forget_<?php echo esc_attr( $haiku ); ?>" value="1"> <?php esc_html_e( 'Togli la chiave', 'xb' ); ?></label>
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<p>
					<label for="xb-refine-url"><?php esc_html_e( 'Indirizzo del server compatibile', 'xb' ); ?></label><br>
					<input id="xb-refine-url" name="url" class="regular-text code" value="<?php echo esc_attr( $model['url'] ); ?>" maxlength="200">
				</p>
				<p>
					<label for="xb-refine-model"><?php esc_html_e( 'Nome del modello sul server', 'xb' ); ?></label><br>
					<input id="xb-refine-model" name="model" class="regular-text code" value="<?php echo esc_attr( $model['model'] ); ?>" maxlength="80">
				</p>
				<p>
					<label for="xb-refine-compatible-key"><?php esc_html_e( 'Chiave del server (se ne chiede una)', 'xb' ); ?></label><br>
					<input id="xb-refine-compatible-key" name="compatible_key" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $model['compatibleKeySet'] ? __( 'impostata: lascia vuoto per non cambiarla', 'xb' ) : '' ); ?>">
					<?php if ( $model['compatibleKeySet'] ) : ?>
						<label><input type="checkbox" name="forget_<?php echo esc_attr( $other ); ?>" value="1"> <?php esc_html_e( 'Togli la chiave', 'xb' ); ?></label>
					<?php endif; ?>
				</p>
				<p class="description"><?php esc_html_e( 'Le chiavi sono salvate cifrate e non vengono mai mostrate né restituite.', 'xb' ); ?></p>
				<p><button type="submit" class="xb-btn"><?php esc_html_e( 'Salva il modello per i nomi', 'xb' ); ?></button></p>
			</form>
		</details>
		<?php
	}

	public static function render(): void {
		if ( ! self::can() ) {
			return;
		}
		$link = Link::get();
		$code = get_transient( self::code_key() );
		if ( is_string( $code ) ) {
			delete_transient( self::code_key() );
		}
		$post = admin_url( 'admin-post.php' );
		?>
		<h2 class="xb-section-title" id="xb-cms"><?php esc_html_e( 'Collega Histeon Publish', 'xb' ); ?> <small><?php esc_html_e( 'per scaricare il sito in Histeon Publish e ricevere le sue modifiche senza passare da un file', 'xb' ); ?></small></h2>
		<?php \XB\Cms\Pairing::render(); ?>
		<div class="xb-panel">
			<div class="xb-panel-body">
				<?php if ( is_string( $code ) ) : ?>
					<p><label for="xb-cms-new"><strong><?php esc_html_e( 'Il codice di abbinamento (solo adesso: non verrà più mostrato)', 'xb' ); ?></strong></label></p>
					<textarea id="xb-cms-new" rows="3" class="large-text code" readonly><?php echo esc_textarea( $code ); ?></textarea>
					<p class="xb-muted"><?php esc_html_e( 'Incollalo in Histeon Publish: in Histeon CMS scegli il filtro «Collegati», incollalo in «Collega un sito Histeon WordPress» e premi «Verifica e collega →». Contiene il segreto del collegamento: non mandarlo per email in chiaro.', 'xb' ); ?></p>
				<?php endif; ?>
				<?php if ( null === $link ) : ?>
					<form method="post" action="<?php echo esc_url( $post ); ?>">
						<?php wp_nonce_field( self::CREATE ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::CREATE ); ?>">
						<p><label><?php esc_html_e( 'Nome del progetto', 'xb' ); ?> <input name="label" maxlength="80" placeholder="<?php esc_attr_e( 'Sito principale', 'xb' ); ?>"></label></p>
						<fieldset>
							<legend><?php esc_html_e( 'Cosa può fare Histeon CMS', 'xb' ); ?></legend>
							<p><label><input type="checkbox" name="read" value="1"> <?php esc_html_e( 'Scaricare il sito (pagine, componenti, stili, contenuti, media)', 'xb' ); ?></label></p>
							<p><label><input type="checkbox" name="write" value="1"> <?php esc_html_e( 'Inviare modifiche: arrivano in sviluppo, il sito online non cambia', 'xb' ); ?></label></p>
							<?php if ( current_user_can( 'xb_publish' ) ) : ?>
								<p><label><input type="checkbox" name="publish" value="1"> <?php esc_html_e( 'Anche pubblicare ciò che invia (richiede «Inviare modifiche»)', 'xb' ); ?></label></p>
							<?php endif; ?>
						</fieldset>
						<p><button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Crea il codice di abbinamento', 'xb' ); ?></button></p>
					</form>
				<?php else : ?>
					<ul class="xb-facts">
						<li><?php echo esc_html( '' !== $link['label'] ? $link['label'] : __( 'Un progetto Histeon CMS', 'xb' ) ); ?> · <?php echo esc_html( sprintf( /* translators: %s: user name */ __( 'collegato da %s', 'xb' ), Menu::user_name( $link['user'] ) ) ); ?></li>
						<li><?php echo $link['read'] ? esc_html__( 'può scaricare il sito', 'xb' ) : esc_html__( 'non può scaricare il sito', 'xb' ); ?></li>
						<li><?php echo $link['write'] ? ( $link['publish'] ? esc_html__( 'può inviare modifiche e pubblicarle', 'xb' ) : esc_html__( 'può inviare modifiche in sviluppo, non pubblicare', 'xb' ) ) : esc_html__( 'non può inviare modifiche', 'xb' ); ?></li>
						<li><?php echo $link['last'] > 0 ? esc_html( sprintf( /* translators: %s: date */ __( 'ultimo uso: %s', 'xb' ), (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $link['last'] ) ) ) : esc_html__( 'mai usato finora', 'xb' ); ?></li>
					</ul>
					<form method="post" action="<?php echo esc_url( $post ); ?>">
						<?php wp_nonce_field( self::REVOKE ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE ); ?>">
						<button class="xb-btn"><?php esc_html_e( 'Revoca il collegamento', 'xb' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
