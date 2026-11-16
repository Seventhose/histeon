<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Framework\Frameworks;
use XB\Plugin;

/**
 * XB → Impostazioni: framework choice (first-run wizard) — docs/spec/framework.md.
 * Requires manage_options and xb_build; the form is protected by a nonce.
 */
final class SettingsScreen {

	public const SLUG      = 'xb-settings';
	public const ACTION    = 'xb_save_settings';
	public const MIGRATION = 'xb_framework_migration';
	public const REACTIVATE = 'xb_framework_reactivate';
	/** Plugins of the frameworks XB reads, by their title in WordPress (to switch back to one turned off). */
	private const FRAMEWORK_PLUGINS = array(
		'acss'           => 'Automatic.css',
		'core-framework' => 'Core Framework',
	);
	public const CLOUDFLARE = 'xb_cloudflare_settings';
	public const TURNSTILE  = 'xb_turnstile_settings';
	public const ETCH       = 'xb_etch_import';
	public const FONTS      = 'xb_local_fonts_action';
	public const ACSS_CORE  = 'xb_acss_to_core';
	public const UPDATES    = 'xb_updates_settings';
	public const SIGNING    = 'xb_signing_action';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
		add_action( 'admin_post_' . self::MIGRATION, array( self::class, 'migrate' ) );
		add_action( 'admin_post_' . self::REACTIVATE, array( self::class, 'reactivate' ) );
		add_action( 'admin_post_' . self::CLOUDFLARE, array( self::class, 'save_cloudflare' ) );
		add_action( 'admin_post_' . self::TURNSTILE, array( self::class, 'save_turnstile' ) );
		add_action( 'admin_post_' . self::ETCH, array( self::class, 'import_etch' ) );
		\XB\Export\Etch\Admin::register();
		\XB\Export\Core\Admin::register();
		add_action( 'admin_post_' . self::FONTS, array( self::class, 'fonts' ) );
		add_action( 'admin_post_' . self::ACSS_CORE, array( self::class, 'acss_to_core' ) );
		add_action( 'admin_post_' . self::UPDATES, array( self::class, 'save_updates' ) );
		add_action( 'admin_post_' . self::SIGNING, array( self::class, 'signing' ) );
		add_action( 'admin_notices', array( self::class, 'first_run_notice' ) );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	/** XB → Impostazioni (registered by Menu, in the menu's order). */
	public static function menu(): void {
		add_submenu_page( Menu::SLUG, __( 'Impostazioni Histeon', 'xb' ), __( 'Impostazioni', 'xb' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	public static function url( string $query = '' ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . $query );
	}

	public static function first_run_notice(): void {
		$screen = get_current_screen();
		if ( ! self::can_manage() || ! SetupScreen::needed() || ( $screen && ( str_ends_with( (string) $screen->id, '_page_' . self::SLUG ) || str_ends_with( (string) $screen->id, '_page_' . SetupScreen::SLUG ) ) ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Histeon è attivo: tre scelte (framework, moduli, importazioni) e il builder è pronto.', 'xb' ),
			esc_url( SetupScreen::url() ),
			esc_html__( 'Primo avvio', 'xb' )
		);
	}

	public static function render(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		$frameworks = Plugin::instance()->frameworks;
		$active     = $frameworks->active();
		$saved      = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		$index = array(
			'xb-framework' => __( 'Framework CSS', 'xb' ),
			'xb-loading'   => __( 'Caricamento del CSS', 'xb' ),
			'xb-migration' => __( 'Cambio di framework', 'xb' ),
			'xb-fonts'     => __( 'Font locali', 'xb' ),
			'xb-signing'   => __( 'Firma degli export', 'xb' ),
		);
		if ( \XB\Import\Etch\Migration::detected() ) {
			$index['xb-etch'] = __( 'Importa da Etch', 'xb' );
		}
		if ( \XB\Export\Etch\Admin::visible() ) {
			$index[ \XB\Export\Etch\Admin::ANCHOR ] = __( 'Esporta verso Etch', 'xb' );
		}
		if ( \XB\Export\Core\Admin::visible() ) {
			$index[ \XB\Export\Core\Admin::ANCHOR ] = __( 'Uscita verso Gutenberg', 'xb' );
		}
		if ( self::can_deploy_settings() ) {
			$index['xb-cloudflare'] = __( 'Sito statico', 'xb' );
		}
		Menu::open(
			self::SLUG,
			__( 'Impostazioni', 'xb' ),
			__( 'Framework, caricamento degli stili, migrazioni e pubblicazione statica.', 'xb' ),
			static function (): void {
				echo '<a class="xb-btn xb-btn--ghost" href="' . esc_url( SetupScreen::url() ) . '">' . esc_html__( 'Primo avvio', 'xb' ) . '</a>';
			}
		);
		?>
		<?php if ( $saved ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Impostazioni salvate.', 'xb' ); ?></p></div>
		<?php endif; ?>
		<div class="xb-layout">
			<nav class="xb-index" aria-label="<?php esc_attr_e( 'Sezioni delle impostazioni', 'xb' ); ?>">
				<?php foreach ( $index as $anchor => $label ) : ?>
					<a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<section class="xb-panel" id="xb-framework">
				<div class="xb-panel-head"><div>
				<h2><?php esc_html_e( 'Framework CSS', 'xb' ); ?></h2>
				<p><?php esc_html_e( 'Histeon è completamente open source e non gestisce codici di licenza: i framework di terze parti compaiono qui solo se li hai installati tu. Puoi cambiare scelta in qualsiasi momento; i componenti scritti con i token --xb-* continuano a funzionare.', 'xb' ); ?></p>
				</div></div>
				<div class="xb-panel-body">
				<fieldset class="xb-choices">
					<legend class="screen-reader-text"><?php esc_html_e( 'Framework CSS', 'xb' ); ?></legend>
					<?php foreach ( $frameworks->all() as $provider ) : ?>
						<label class="xb-choice">
							<input type="radio" name="xb_framework" value="<?php echo esc_attr( $provider->id() ); ?>" <?php checked( $active->id(), $provider->id() ); ?> <?php disabled( ! $provider->available() ); ?>>
							<strong><?php echo esc_html( $provider->label() ); ?></strong>
							<?php if ( '' !== $provider->note() ) : ?>
								<span><?php echo esc_html( $provider->note() ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php
				$others = array_filter( $frameworks->all(), static fn ( $p ): bool => $p->id() !== $active->id() && $p->available() && array() !== $p->stylesheets() && 'xb-tokens' !== $p->id() );
				foreach ( $others as $other ) :
					?>
					<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( __( 'Anche %s è installato e carica il suo foglio di stile su ogni pagina, anche se non è il framework scelto: se non lo usi, disattiva il suo plugin.', 'xb' ), $other->label() ) ); ?></p></div>
				<?php endforeach; ?>
				</div>
				</section>

				<section class="xb-panel" id="xb-loading">
				<div class="xb-panel-head"><div>
				<h2><?php esc_html_e( 'Caricamento del CSS', 'xb' ); ?></h2>
				<p><?php esc_html_e( 'Gli stili di Histeon arrivano in pagina solo se c\'è un elemento che li usa: classi e id vengono letti dall\'HTML finale di ogni pagina. Lo stesso può valere per il foglio del framework.', 'xb' ); ?></p>
				</div></div>
				<div class="xb-panel-body">
				<fieldset class="xb-choices">
					<legend class="screen-reader-text"><?php esc_html_e( 'Caricamento del framework', 'xb' ); ?></legend>
					<label class="xb-choice"><input type="radio" name="xb_framework_loading" value="on-demand" <?php checked( \XB\Styles\PageStyles::mode(), 'on-demand' ); ?>> <strong><?php esc_html_e( 'Framework su richiesta', 'xb' ); ?></strong>
						<span><?php esc_html_e( 'Ogni pagina riceve solo le regole del framework che usa (variabili e regole generali restano sempre).', 'xb' ); ?></span></label>
					<label class="xb-choice"><input type="radio" name="xb_framework_loading" value="full" <?php checked( \XB\Styles\PageStyles::mode(), 'full' ); ?>> <strong><?php esc_html_e( 'Framework completo', 'xb' ); ?></strong>
						<span><?php esc_html_e( 'Il foglio intero su ogni pagina, come lo carica il framework.', 'xb' ); ?></span></label>
				</fieldset>
				<label class="xb-choice"><input type="checkbox" name="xb_css_global_file" value="1" <?php checked( \XB\Styles\PageStyles::global_file() ); ?>> <strong><?php esc_html_e( 'Fogli globali in un file in cache', 'xb' ); ?></strong>
					<span class="description"><?php esc_html_e( 'I fogli globali arrivano interi in un file che il browser tiene fra una pagina e l\'altra; il CSS dei selettori resta nella pagina, solo quello che serve. Conviene con fogli globali grandi, come quelli importati. Un foglio con indirizzi url() relativi resta nella pagina.', 'xb' ); ?></span></label>
				<?php
				$hidden = array_filter( get_post_types( array( 'show_ui' => true ), 'objects' ), static fn ( \WP_Post_Type $t ): bool => ! is_post_type_viewable( $t ) && ! in_array( $t->name, \XB\Render\LoopPresets::INTERNAL_TYPES, true ) );
				if ( array() !== $hidden ) :
					$allowed = (array) get_option( \XB\Render\LoopPresets::TYPES, array() );
					?>
					<h3><?php esc_html_e( 'Contenuti non pubblici nei loop', 'xb' ); ?></h3>
					<p><?php esc_html_e( 'Questi tipi di contenuto non hanno pagine pubbliche: i loop non li mostrano, a meno che tu non li ammetta qui. Anche se ammessi, compaiono solo i contenuti pubblicati e senza password.', 'xb' ); ?></p>
					<fieldset>
						<?php foreach ( $hidden as $type ) : ?>
							<label style="display:block"><input type="checkbox" name="xb_loop_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $allowed, true ) ); ?>> <?php echo esc_html( sprintf( '%s (%s)', $type->labels->singular_name, $type->name ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<?php
				// Non-public taxonomies with a screen, plus those already allowed (an import may allow one without a screen).
				$allowed_tax = (array) get_option( \XB\Render\LoopPresets::TAXONOMIES, array() );
				$hidden_tax  = array_filter( get_taxonomies( array( 'public' => false ), 'objects' ), static fn ( \WP_Taxonomy $t ): bool => ! in_array( $t->name, \XB\Render\LoopPresets::INTERNAL_TAXONOMIES, true ) && ( $t->show_ui || in_array( $t->name, $allowed_tax, true ) ) );
				if ( array() !== $hidden_tax ) :
					?>
					<h3><?php esc_html_e( 'Tassonomie non pubbliche nei loop', 'xb' ); ?></h3>
					<p><?php esc_html_e( 'I loop di termini mostrano solo le tassonomie pubbliche, a meno che tu non ammetta qui le altre: nomi, conteggi e descrizioni dei loro termini diventano visibili a chi visita.', 'xb' ); ?></p>
					<fieldset>
						<?php foreach ( $hidden_tax as $taxonomy ) : ?>
							<label style="display:block"><input type="checkbox" name="xb_loop_taxonomies[]" value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php checked( in_array( $taxonomy->name, $allowed_tax, true ) ); ?>> <?php echo esc_html( sprintf( '%s (%s)', $taxonomy->labels->singular_name, $taxonomy->name ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
				<p>
					<label for="xb-css-safelist"><strong><?php esc_html_e( 'Classi e id sempre inclusi', 'xb' ); ?></strong></label><br>
					<textarea id="xb-css-safelist" name="xb_css_safelist" rows="3" class="large-text code" placeholder=".is-open .is-active #modal .is-*"><?php echo esc_textarea( (string) get_option( \XB\Styles\PageStyles::SAFELIST, '' ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Per classi e id che uno script aggiunge dopo il caricamento, invisibili nell\'HTML. Separati da spazi; un * finale vale come prefisso.', 'xb' ); ?></span>
				</p>
				<p>
					<label for="xb-canvas-origins"><strong><?php esc_html_e( 'Origini esterne ammesse nella tela', 'xb' ); ?></strong></label><br>
					<textarea id="xb-canvas-origins" name="xb_canvas_origins" rows="3" class="large-text code" placeholder="https://cdn.esempio.it"><?php echo esc_textarea( implode( "\n", BuilderScreen::declared_origins( (string) get_option( BuilderScreen::CANVAS_ORIGINS, '' ) ) ) ); ?></textarea>
					<span class="description"><?php esc_html_e( 'La tela del builder carica immagini, font e fogli di stile solo dal sito e da queste origini (una per riga, per esempio la CDN delle immagini): così l\'indirizzo di chi lavora non arriva a siti terzi. Un\'immagine da altrove si vede come segnaposto.', 'xb' ); ?></span>
				</p>
				</div>
				</section>
				<section class="xb-panel">
				<div class="xb-panel-head"><div>
					<h2><?php esc_html_e( 'Controlli prima di pubblicare', 'xb' ); ?></h2>
					<p><?php esc_html_e( 'Nella finestra Pubblica del builder, le pagine che cambiano passano dagli stessi controlli della barra laterale (accessibilità, SEO, prestazioni) nella loro versione di sviluppo.', 'xb' ); ?></p>
				</div></div>
				<div class="xb-panel-body">
					<label class="xb-choice"><input type="checkbox" name="xb_checks_block" value="1" <?php checked( ReleasesScreen::checks_block() ); ?>> <strong><?php esc_html_e( 'Blocca la pubblicazione se i controlli trovano errori', 'xb' ); ?></strong>
					<span class="description"><?php esc_html_e( 'Si pubblica solo dal builder, dopo i controlli; gli avvisi non bloccano. È un aiuto per chi costruisce, non una barriera di sicurezza.', 'xb' ); ?></span></label>
				</div>
				</section>
				<p style="text-align:right;margin:-8px 0 24px"><button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Salva le impostazioni', 'xb' ); ?></button></p>
			</form>

			<section class="xb-panel" id="xb-migration">
			<div class="xb-panel-head"><div>
			<h2><?php esc_html_e( 'Cambio di framework con migrazione', 'xb' ); ?></h2>
			<p><?php esc_html_e( 'Riscrive i nomi nativi del framework attuale (variabili e classi) in quelli del nuovo, in tutte le pagine, i componenti e gli stili. Il risultato è una versione di sviluppo: la controlli in anteprima e la pubblichi dal builder; se qualcosa non va, la ripristini come qualsiasi altra versione.', 'xb' ); ?></p>
			</div></div>
			<div class="xb-panel-body">
			<?php
			$report = get_transient( 'xb_migration_report_' . get_current_user_id() );
			if ( is_array( $report ) ) :
				delete_transient( 'xb_migration_report_' . get_current_user_id() );
				?>
				<div class="notice notice-info inline">
					<p><strong><?php echo esc_html( sprintf( '%s → %s', (string) ( $report['from'] ?? '' ), (string) ( $report['to'] ?? '' ) ) ); ?></strong> — <?php echo esc_html( ! empty( $report['prepared'] ) ? __( 'migrazione preparata in sviluppo: pubblicala dal builder.', 'xb' ) : __( 'anteprima (nulla è stato modificato).', 'xb' ) ); ?></p>
					<ul>
						<?php foreach ( (array) ( $report['docs'] ?? array() ) as $row ) : ?>
							<?php if ( is_array( $row ) ) : ?>
								<li><?php echo esc_html( sprintf( /* translators: 1: stylesheet, 2: number of variables, 3: number of classes */ __( '%1$s: %2$d variabili, %3$d classi', 'xb' ), (string) ( $row['label'] ?? '' ), (int) ( $row['variables'] ?? 0 ), (int) ( $row['classes'] ?? 0 ) ) ); ?></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
					<?php if ( is_array( $report['carry'] ?? null ) ) : ?>
						<?php self::render_carry( $report ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $report['unmapped'] ) && is_array( $report['unmapped'] ) ) : ?>
						<p><?php echo esc_html( is_array( $report['carry'] ?? null ) ? __( 'Restano con il loro nome (continuano a valere grazie al foglio portato con te):', 'xb' ) : __( 'Senza corrispondenza (da sistemare a mano):', 'xb' ) ); ?> <code><?php echo esc_html( implode( ' ', array_map( 'strval', $report['unmapped'] ) ) ); ?></code></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php foreach ( self::inactive_frameworks() as $id => $title ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-inline">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::REACTIVATE ); ?>">
					<input type="hidden" name="xb_framework" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( self::REACTIVATE ); ?>
					<p><?php echo esc_html( sprintf( /* translators: %s: framework name */ __( '%s è installato ma spento: riattivalo per poterci tornare. Le sue impostazioni sono rimaste dove le avevi lasciate.', 'xb' ), $title ) ); ?>
					<button class="xb-btn"><?php echo esc_html( sprintf( /* translators: %s: framework name */ __( 'Riattiva %s', 'xb' ), $title ) ); ?></button></p>
				</form>
			<?php endforeach; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::MIGRATION ); ?>">
				<?php wp_nonce_field( self::MIGRATION ); ?>
				<p>
					<label><?php esc_html_e( 'Passa a', 'xb' ); ?>
						<select name="xb_target">
							<?php foreach ( $frameworks->all() as $provider ) : ?>
								<?php if ( $provider->available() && $provider->id() !== $active->id() ) : ?>
									<option value="<?php echo esc_attr( $provider->id() ); ?>"><?php echo esc_html( $provider->label() ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
					</label>
				</p>
				<p>
					<label><input type="checkbox" name="xb_carry" value="1" checked> <?php echo esc_html( sprintf( /* translators: %s: framework name */ __( 'Porta con te le variabili e le regole di %s che il sito usa (consigliato: niente si rompe)', 'xb' ), $active->label() ) ); ?></label>
				</p>
				<p>
					<button class="xb-btn" name="xb_mode" value="plan"><?php esc_html_e( 'Anteprima della migrazione', 'xb' ); ?></button>
					<button class="xb-btn xb-btn--primary" name="xb_mode" value="prepare"><?php esc_html_e( 'Prepara in sviluppo', 'xb' ); ?></button>
				</p>
			</form>
			<?php self::render_acss_to_core(); ?>
			</div>
			</section>
			<?php self::render_fonts(); ?>
			<?php self::render_signing(); ?>
			<?php self::render_etch(); ?>
			<?php \XB\Export\Etch\Admin::render(); ?>
			<?php \XB\Export\Core\Admin::render(); ?>
			<?php self::render_cloudflare(); ?>
			<?php self::render_updates(); ?>
			<?php BackupPanel::render(); ?>
			</div>
		</div>
		<?php
		Menu::close();
	}

	/** Shown only when this site has data saved by Etch (docs/spec/import-etch.md). */
	/** Automatic.css → Core Framework, completely: the design system moves too, not only the names. */
	private static function render_acss_to_core(): void {
		if ( ! \XB\Framework\AcssToCore::available() ) {
			return;
		}
		$plan = \XB\Framework\AcssToCore::plan();
		?>
		<hr>
		<h3 id="xb-acss-core"><?php esc_html_e( 'Da Automatic.css a Core Framework, tutto compreso', 'xb' ); ?></h3>
		<p><?php esc_html_e( 'Core Framework è gratuito e open source (MIT): qui il tuo sistema di design passa da ACSS a Core Framework senza perdere nulla. Nasce un nuovo progetto di Core Framework, copia di quello attuale, con:', 'xb' ); ?></p>
		<ul class="xb-list">
			<li><?php echo esc_html( sprintf( /* translators: %s: colour names */ __( 'i colori di ACSS nel sistema colori di Core Framework (%s);', 'xb' ), array() === $plan['colors'] ? '—' : implode( ', ', array_map( static fn ( string $k, string $v ): string => "$k $v", array_keys( $plan['colors'] ), $plan['colors'] ) ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'un foglio con tutte le %d variabili di ACSS e i loro valori: ogni var(--…) continua a funzionare;', 'xb' ), $plan['variables'] ) ); ?></li>
			<li><?php esc_html_e( 'le regole di ACSS che il sito usa davvero (stili di base degli elementi e classi presenti nelle pagine, lette come le vede chi visita) in un foglio di compatibilità che Histeon carica dove le caricava ACSS: dopo gli stili del sito, così ogni regola vince o perde esattamente come prima;', 'xb' ); ?></li>
			<li><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'le %d recipes di ACSS nell\'editor CSS di Histeon: scrivi @ e il nome.', 'xb' ), $plan['recipes'] ) ); ?></li>
		</ul>
		<?php if ( array() !== $plan['classes'] ) : ?>
			<p class="xb-muted"><?php esc_html_e( 'Classi portate con le loro regole:', 'xb' ); ?> <code><?php echo esc_html( implode( ' ', array_slice( $plan['classes'], 0, 60 ) ) . ( count( $plan['classes'] ) > 60 ? ' …' : '' ) ); ?></code></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACSS_CORE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACSS_CORE ); ?>">
			<button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Crea il progetto Core Framework', 'xb' ); ?></button>
		</form>
		<p class="xb-muted"><?php esc_html_e( 'Poi: 1) apri Core Framework e premi «Save changes», che genera il suo CSS; 2) qui sopra passa a Core Framework con «Prepara in sviluppo» e pubblica dal builder; 3) controlla il sito e disattiva Automatic.css. Il progetto precedente di Core Framework resta disponibile nel suo elenco.', 'xb' ); ?></p>
		<?php
	}

	public static function acss_to_core(): void {
		check_admin_referer( self::ACSS_CORE );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore cambia il framework.', 'xb' ), 403 );
		}
		$back = self::url() . '#xb-acss-core';
		try {
			\XB\Framework\AcssToCore::import();
		} catch ( \RuntimeException $e ) {
			Menu::back( $back, 'error', $e->getMessage() );
		}
		Plugin::instance()->versioning->store()->audit( 'acss_to_core', 'framework' );
		Menu::back( $back, 'done', __( 'Progetto «Automatic.css (importato)» creato e selezionato in Core Framework. Aprilo e premi «Save changes» per generarne il CSS.', 'xb' ) );
	}

	private static function render_fonts(): void {
		$faces    = \XB\Fonts\LocalFonts::faces();
		$families = array();
		foreach ( $faces as $face ) {
			$families[ $face['family'] ][] = 'italic' === $face['style'] ? sprintf( /* translators: %s: font weight */ __( '%s corsivo', 'xb' ), $face['weight'] ) : (string) $face['weight'];
		}
		$action = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<section class="xb-panel" id="xb-fonts">
			<div class="xb-panel-head"><div>
				<h2><?php esc_html_e( 'Font locali', 'xb' ); ?></h2>
				<p><?php esc_html_e( 'I font serviti dal tuo sito: nessuna richiesta a Google o ad altri quando qualcuno visita le pagine (conta anche per il GDPR). Nel CSS si usano con font-family: "Nome".', 'xb' ); ?></p>
			</div></div>
			<div class="xb-panel-body">
				<?php if ( array() === $families ) : ?>
					<p class="xb-muted"><?php esc_html_e( 'Nessun font locale.', 'xb' ); ?></p>
				<?php else : ?>
					<ul class="xb-list">
						<?php foreach ( $families as $family => $variants ) : ?>
							<li style="display:flex;gap:12px;align-items:center;justify-content:space-between">
								<span><strong style="font-family:'<?php echo esc_attr( $family ); ?>'"><?php echo esc_html( $family ); ?></strong> <span class="xb-muted"><?php echo esc_html( implode( ', ', array_unique( $variants ) ) ); ?></span></span>
								<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
									<?php wp_nonce_field( self::FONTS ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( self::FONTS ); ?>">
									<input type="hidden" name="op" value="remove">
									<input type="hidden" name="family" value="<?php echo esc_attr( $family ); ?>">
									<button class="xb-btn"><?php esc_html_e( 'Rimuovi', 'xb' ); ?></button>
								</form>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<h3><?php esc_html_e( 'Importa da Google Fonts', 'xb' ); ?></h3>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
					<?php wp_nonce_field( self::FONTS ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::FONTS ); ?>">
					<input type="hidden" name="op" value="google">
					<label><?php esc_html_e( 'Famiglia', 'xb' ); ?> <input name="family" placeholder="Inter" required pattern="[A-Za-z0-9][A-Za-z0-9 \-]{0,59}"></label>
					<label><?php esc_html_e( 'Pesi', 'xb' ); ?> <input name="weights" value="400, 700" size="12"></label>
					<label><input type="checkbox" name="italic" value="1"> <?php esc_html_e( 'anche corsivo', 'xb' ); ?></label>
					<button class="xb-btn"><?php esc_html_e( 'Scarica nel sito', 'xb' ); ?></button>
				</form>
				<p class="xb-muted"><?php esc_html_e( 'I file vengono scaricati una volta sola dal server (fonts.googleapis.com e fonts.gstatic.com) e poi serviti dal tuo sito.', 'xb' ); ?></p>
				<h3><?php esc_html_e( 'Carica un file', 'xb' ); ?></h3>
				<form method="post" enctype="multipart/form-data" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
					<?php wp_nonce_field( self::FONTS ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::FONTS ); ?>">
					<input type="hidden" name="op" value="upload">
					<label for="xb-font-file" class="screen-reader-text"><?php esc_html_e( 'File del carattere (.woff2 o .woff)', 'xb' ); ?></label>
					<input id="xb-font-file" type="file" name="font" accept=".woff2,.woff" required>
					<label><?php esc_html_e( 'Famiglia', 'xb' ); ?> <input name="family" required pattern="[A-Za-z0-9][A-Za-z0-9 \-]{0,59}"></label>
					<label><?php esc_html_e( 'Peso', 'xb' ); ?>
						<select name="weight"><?php foreach ( range( 100, 900, 100 ) as $w ) : ?><option value="<?php echo (int) $w; ?>" <?php selected( 400, $w ); ?>><?php echo (int) $w; ?></option><?php endforeach; ?></select>
					</label>
					<label><?php esc_html_e( 'Stile', 'xb' ); ?> <select name="style"><option value="normal"><?php esc_html_e( 'normale', 'xb' ); ?></option><option value="italic"><?php esc_html_e( 'corsivo', 'xb' ); ?></option></select></label>
					<button class="xb-btn"><?php esc_html_e( 'Carica', 'xb' ); ?></button>
				</form>
				<p class="xb-muted"><?php esc_html_e( 'Solo WOFF2 o WOFF, al massimo 2 MB: il file viene riconosciuto dal contenuto, non dal nome.', 'xb' ); ?></p>
			</div>
		</section>
		<?php
	}

	/** Local fonts: add from Google Fonts or an upload, or remove a family. Administrators only. */
	public static function fonts(): void {
		check_admin_referer( self::FONTS );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Solo un amministratore gestisce i font.', 'xb' ), 403 );
		}
		$op     = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$family = isset( $_POST['family'] ) ? sanitize_text_field( wp_unslash( $_POST['family'] ) ) : '';
		$back   = self::url() . '#xb-fonts';
		try {
			if ( 'remove' === $op ) {
				\XB\Fonts\LocalFonts::remove( $family );
				Menu::back( $back, 'done', sprintf( /* translators: %s: font family */ __( 'Font %s rimosso.', 'xb' ), $family ) );
			}
			if ( 'google' === $op ) {
				$weights = array_map( 'intval', preg_split( '/[\s,]+/', isset( $_POST['weights'] ) ? sanitize_text_field( wp_unslash( $_POST['weights'] ) ) : '400' ) ?: array() );
				$count   = \XB\Fonts\LocalFonts::import_google( $family, $weights, ! empty( $_POST['italic'] ) );
				Menu::back( $back, 'done', sprintf( /* translators: 1: family, 2: number of files */ __( 'Font %1$s scaricato nel sito: %2$d file.', 'xb' ), $family, $count ) );
			}
			if ( 'upload' === $op ) {
				$upload = $_FILES['font'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read as bytes and checked by signature.
				if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? -1 ) || ! is_uploaded_file( (string) $upload['tmp_name'] ) || (int) $upload['size'] > \XB\Fonts\LocalFonts::MAX_BYTES ) {
					throw new \InvalidArgumentException( __( 'File non ricevuto o troppo grande.', 'xb' ) );
				}
				$weight = isset( $_POST['weight'] ) ? sanitize_text_field( wp_unslash( $_POST['weight'] ) ) : '400';
				$style  = isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : 'normal';
				\XB\Fonts\LocalFonts::add( $family, $weight, $style, (string) file_get_contents( (string) $upload['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				Menu::back( $back, 'done', sprintf( /* translators: %s: font family */ __( 'Font %s caricato.', 'xb' ), $family ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			Menu::back( $back, 'error', $e->getMessage() );
		}
		Menu::back( $back, 'error', __( 'Operazione non valida.', 'xb' ) );
	}

	private static function render_etch(): void {
		if ( ! \XB\Import\Etch\Migration::detected() ) {
			return;
		}
		$frameworks_all = Plugin::instance()->frameworks->all();
		$report = get_transient( 'xb_etch_report_' . get_current_user_id() );
		delete_transient( 'xb_etch_report_' . get_current_user_id() );
		?>
		<section class="xb-panel" id="xb-etch">
		<div class="xb-panel-head"><div>
		<h2><?php esc_html_e( 'Importa da Etch', 'xb' ); ?></h2>
		<p><?php esc_html_e( 'Converte pagine, componenti, template, stili e query salvati con Etch in contenuto Histeon. Il risultato è una versione di sviluppo: la controlli in anteprima e la pubblichi dal builder; si ripristina come qualsiasi altra versione. I dati di Etch non vengono modificati. Dopo la pubblicazione puoi disattivare Etch.', 'xb' ); ?></p>
		</div></div>
		<div class="xb-panel-body">
		<p class="description"><?php esc_html_e( 'Sicurezza: ogni contenuto mantiene il livello di fiducia di chi lo ha scritto. Il JavaScript di autori senza il permesso unfiltered_html non viene importato.', 'xb' ); ?></p>
		<?php if ( is_array( $report ) ) : ?>
			<div class="notice notice-info inline">
				<p><strong><?php echo esc_html( ! empty( $report['written'] ) ? __( 'Importazione preparata in sviluppo: controllala in anteprima e pubblicala dal builder.', 'xb' ) : __( 'Anteprima: nulla è stato modificato.', 'xb' ) ); ?></strong></p>
				<p><?php echo esc_html( sprintf( __( '%1$d documenti, %2$d componenti, %3$d query, %4$d byte di CSS.', 'xb' ), count( (array) ( $report['documents'] ?? array() ) ), count( (array) ( $report['components'] ?? array() ) ), count( (array) ( $report['presets'] ?? array() ) ), (int) ( $report['styles']['bytes'] ?? 0 ) ) ); ?></p>
				<table class="widefat striped" style="max-width:960px">
					<thead><tr><th><?php esc_html_e( 'Documento', 'xb' ); ?></th><th><?php esc_html_e( 'Fiducia', 'xb' ); ?></th><th><?php esc_html_e( 'Convertiti', 'xb' ); ?></th><th><?php esc_html_e( 'Script', 'xb' ); ?></th><th><?php esc_html_e( 'Da verificare', 'xb' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( (array) ( $report['documents'] ?? array() ) as $row ) : ?>
						<?php if ( is_array( $row ) ) : ?>
						<tr>
							<td><?php echo esc_html( sprintf( '%s (%s #%d)', (string) ( $row['title'] ?? '' ), (string) ( $row['type'] ?? '' ), (int) ( $row['id'] ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( ! empty( $row['trusted'] ) ? 'trusted' : 'restricted' ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $row['counts']['converted'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) (int) ( $row['scripts'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( implode( ' · ', array_map( 'strval', (array) ( $row['issues'] ?? array() ) ) ) ); ?></td>
						</tr>
						<?php endif; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php foreach ( (array) ( $report['issues'] ?? array() ) as $issue ) : ?>
					<p>⚠ <?php echo esc_html( (string) $issue ); ?></p>
				<?php endforeach; ?>
				<?php if ( is_array( $report['framework'] ?? null ) ) : ?>
					<?php self::render_carry( $report['framework'] ); ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ETCH ); ?>">
			<?php wp_nonce_field( self::ETCH ); ?>
			<?php $acss = $frameworks_all['acss'] ?? null; ?>
			<?php if ( null !== $acss && array() !== \XB\Framework\Carry::files( $acss ) ) : ?>
				<p class="xb-choices-title"><strong><?php esc_html_e( 'Il sito usa Automatic.css: dove va il suo sistema di design?', 'xb' ); ?></strong></p>
				<div class="xb-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Dove va Automatic.css', 'xb' ); ?>">
					<?php $cf_ok = (bool) ( $frameworks_all['core-framework'] ?? null )?->available(); ?>
					<label class="xb-choice"><input type="radio" name="xb_framework_to" value="xb-tokens" checked> <strong><?php esc_html_e( 'In Histeon CSS', 'xb' ); ?></strong><span><?php esc_html_e( 'Consigliato. Il framework libero di Histeon, già incluso: i suoi token prendono i valori di ACSS, la palette parte dai tuoi colori, le classi equivalenti cambiano nome, il resto continua a valere. Nessun plugin esterno.', 'xb' ); ?></span></label>
					<?php if ( $cf_ok ) : ?>
						<label class="xb-choice"><input type="radio" name="xb_framework_to" value="core-framework"> <strong><?php esc_html_e( 'In Core Framework', 'xb' ); ?></strong><span><?php esc_html_e( 'Un progetto con i colori e tutte le variabili di ACSS, più le sue regole che il sito usa. Open source.', 'xb' ); ?></span></label>
					<?php endif; ?>
					<label class="xb-choice"><input type="radio" name="xb_framework_to" value="keep"> <strong><?php esc_html_e( 'Resta su Automatic.css', 'xb' ); ?></strong><span><?php esc_html_e( 'Serve la sua licenza attiva.', 'xb' ); ?></span></label>
				</div>
			<?php endif; ?>
			<p>
				<button class="xb-btn" name="xb_mode" value="plan"><?php esc_html_e( 'Anteprima dell\'importazione', 'xb' ); ?></button>
				<button class="xb-btn xb-btn--primary" name="xb_mode" value="prepare"><?php esc_html_e( 'Prepara in sviluppo', 'xb' ); ?></button>
			</p>
		</form>
		</div>
		</section>
		<?php
	}

	public static function import_etch(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		check_admin_referer( self::ETCH );
		$prepare = isset( $_POST['xb_mode'] ) && 'prepare' === $_POST['xb_mode'];
		$to      = isset( $_POST['xb_framework_to'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_framework_to'] ) ) : 'keep';
		try {
			$migration = \XB\Import\Etch\Migration::instance();
			$report    = $prepare ? $migration->prepare() : $migration->plan();
			// Etch sites almost always use Automatic.css: its design system moves too, where the user chose.
			if ( in_array( $to, array( 'core-framework', 'xb-tokens' ), true ) ) {
				$framework           = \XB\Framework\Migration::instance();
				$report['framework'] = $prepare ? $framework->prepare( $to, true, 'acss' ) : $framework->plan( $to, 'acss' );
			}
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
		set_transient( 'xb_etch_report_' . get_current_user_id(), $report, 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function can_deploy_settings(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_manage_deploy' );
	}

	/** Aggiornamenti da GitHub, firmati (docs/spec/updates.md). */
	private static function render_updates(): void {
		if ( ! self::can_manage() ) {
			return;
		}
		$settings = \XB\Updates\Updater::settings();
		$saved    = \XB\Updates\Updater::stored();
		$locked   = defined( 'XB_UPDATE_REPOSITORY' ) || defined( 'XB_UPDATE_PUBLIC_KEY' );
		$release  = $settings['enabled'] ? \XB\Updates\Updater::latest() : null;
		?>
		<section class="xb-panel" id="xb-updates">
		<div class="xb-panel-head"><div>
			<h2><?php esc_html_e( 'Aggiornamenti', 'xb' ); ?></h2>
			<p><?php esc_html_e( 'Dalle release su GitHub, come ogni aggiornamento di WordPress, senza licenze né dati del sito inviati. Prima di installare, Histeon verifica la firma (minisign) con la chiave pubblica scritta qui: un pacchetto non firmato da quella chiave non viene installato.', 'xb' ); ?></p>
		</div></div>
		<div class="xb-panel-body">
			<?php if ( $locked ) : ?>
				<p class="xb-muted"><?php esc_html_e( 'Impostati in wp-config.php (XB_UPDATE_REPOSITORY, XB_UPDATE_PUBLIC_KEY).', 'xb' ); ?></p>
			<?php elseif ( ! \XB\Updates\Updater::can_configure() ) : ?>
				<p class="xb-muted"><?php esc_html_e( 'Repository e chiave decidono quale codice riceve l’intera installazione: li cambia solo chi può aggiornare i plugin (su multisito, l’amministratore del network).', 'xb' ); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::UPDATES ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::UPDATES ); ?>">
				<label class="xb-choice"><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $saved['enabled'] ) ); ?>> <strong><?php esc_html_e( 'Cerca gli aggiornamenti su GitHub', 'xb' ); ?></strong></label>
				<p><label for="xb-upd-repo"><?php esc_html_e( 'Repository', 'xb' ); ?></label><br>
				<input id="xb-upd-repo" name="repository" class="regular-text code" placeholder="<?php esc_attr_e( 'proprietario/histos', 'xb' ); ?>" value="<?php echo esc_attr( (string) ( $saved['repository'] ?? '' ) ); ?>"></p>
				<p><label for="xb-upd-key"><?php esc_html_e( 'Chiave pubblica minisign', 'xb' ); ?></label><br>
				<textarea id="xb-upd-key" name="key" rows="2" class="large-text code" placeholder="RWQ…"><?php echo esc_textarea( (string) ( $saved['key'] ?? '' ) ); ?></textarea></p>
				<p><button class="xb-btn"><?php esc_html_e( 'Salva', 'xb' ); ?></button></p>
			</form>
			<?php endif; ?>
			<?php if ( $settings['enabled'] ) : ?>
				<p class="xb-muted"><?php echo esc_html( null === $release ? __( 'Nessuna release firmata trovata (o GitHub non raggiungibile): si riprova tra 12 ore.', 'xb' ) : sprintf( /* translators: 1: latest, 2: installed */ __( 'Ultima release firmata: %1$s · installata: %2$s', 'xb' ), $release['version'], XB_VERSION ) ); ?></p>
			<?php endif; ?>
		</div>
		</section>
		<?php
	}

	public static function save_updates(): void {
		check_admin_referer( self::UPDATES );
		if ( ! self::can_manage() || ! \XB\Updates\Updater::can_configure() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		$back = self::url() . '#xb-updates';
		try {
			\XB\Updates\Updater::save(
				! empty( $_POST['enabled'] ),
				sanitize_text_field( wp_unslash( (string) ( $_POST['repository'] ?? '' ) ) ),
				sanitize_textarea_field( wp_unslash( (string) ( $_POST['key'] ?? '' ) ) )
			);
			Plugin::instance()->versioning->store()->audit( 'updates_config', 'updates' );
		} catch ( \InvalidArgumentException $e ) {
			Menu::back( $back, 'error', $e->getMessage() );
		}
		Menu::back( $back, 'done', __( 'Aggiornamenti salvati.', 'xb' ) );
	}

	private static function render_cloudflare(): void {
		if ( ! self::can_deploy_settings() ) {
			return;
		}
		$view  = \XB\Deploy\Credentials::public_view();
		$error = get_transient( 'xb_settings_error_' . get_current_user_id() );
		delete_transient( 'xb_settings_error_' . get_current_user_id() );
		?>
		<section class="xb-panel" id="xb-cloudflare">
		<div class="xb-panel-head"><div>
		<h2><?php esc_html_e( 'Pubblicazione su Cloudflare', 'xb' ); ?></h2>
		<p><?php esc_html_e( 'Il sito statico va su Cloudflare Workers (Static Assets) del tuo account. Crea un token API con il solo permesso "Account › Workers Scripts › Modifica". Meglio ancora: definisci XB_CLOUDFLARE_API_TOKEN in wp-config.php, così il token non sta nel database.', 'xb' ); ?></p>
		</div></div>
		<div class="xb-panel-body">
		<?php if ( is_string( $error ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::CLOUDFLARE ); ?>">
			<?php wp_nonce_field( self::CLOUDFLARE ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="xb-cf-account"><?php esc_html_e( 'Account ID', 'xb' ); ?></label></th>
					<td><input id="xb-cf-account" name="account" class="regular-text code" value="<?php echo esc_attr( $view['account'] ); ?>" pattern="[0-9a-f]{32}"></td></tr>
				<tr><th scope="row"><label for="xb-cf-script"><?php esc_html_e( 'Nome del Worker', 'xb' ); ?></label></th>
					<td><input id="xb-cf-script" name="script" class="regular-text code" value="<?php echo esc_attr( $view['script'] ); ?>" pattern="[a-z0-9-]{1,63}"></td></tr>
				<tr><th scope="row"><label for="xb-cf-token"><?php esc_html_e( 'Token API', 'xb' ); ?></label></th>
					<td>
						<?php if ( $view['fromConfig'] ) : ?>
							<?php esc_html_e( 'Definito in wp-config.php.', 'xb' ); ?>
						<?php else : ?>
							<?php if ( $view['unreadable'] ) : ?>
								<div class="notice notice-warning inline"><p><?php esc_html_e( 'Il token salvato non si può più decifrare (le chiavi di wp-config.php sono cambiate): inseriscilo di nuovo.', 'xb' ); ?></p></div>
							<?php elseif ( ! \XB\Deploy\Credentials::salts_in_config() ) : ?>
								<div class="notice notice-warning inline"><p><?php esc_html_e( 'AUTH_KEY e AUTH_SALT in wp-config.php mancano o sono quelle di esempio: il token non può essere salvato nel database. Definisci XB_CLOUDFLARE_API_TOKEN in wp-config.php.', 'xb' ); ?></p></div>
							<?php endif; ?>
							<input id="xb-cf-token" name="token" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $view['tokenSet'] ? __( 'impostato: lascia vuoto per non cambiarlo', 'xb' ) : '' ); ?>">
							<p class="description"><?php esc_html_e( 'Salvato cifrato; non viene mai mostrato né restituito.', 'xb' ); ?></p>
						<?php endif; ?>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Automatico', 'xb' ); ?></th>
					<td><label><input type="checkbox" name="auto" value="1" <?php checked( (bool) get_option( \XB\Deploy\Deployer::AUTO, false ) ); ?>> <?php esc_html_e( 'Pubblica su Cloudflare a ogni release, in background', 'xb' ); ?></label></td></tr>
			</table>
			<p><button type="submit" class="xb-btn xb-btn--primary"><?php esc_html_e( 'Salva impostazioni Cloudflare', 'xb' ); ?></button></p>
		</form>
		<?php if ( $view['ready'] ) : ?>
			<h3><?php esc_html_e( 'Form del sito statico (Turnstile)', 'xb' ); ?></h3>
			<p><?php esc_html_e( 'I form puntano a /xb-form: il Worker verifica Turnstile e inoltra i campi firmati a WordPress. Il secret viene inviato a Cloudflare come secret del Worker e non resta su questo sito.', 'xb' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TURNSTILE ); ?>">
				<?php wp_nonce_field( self::TURNSTILE ); ?>
				<p><input name="secret" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Secret key di Turnstile', 'xb' ); ?>"> <?php submit_button( __( 'Invia al Worker', 'xb' ), 'secondary', 'submit', false ); ?></p>
			</form>
		<?php endif; ?>
		</div>
		</section>
		<?php
	}

	public static function save_cloudflare(): void {
		if ( ! self::can_deploy_settings() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare la pubblicazione.', 'xb' ), 403 );
		}
		check_admin_referer( self::CLOUDFLARE );
		try {
			\XB\Deploy\Credentials::save(
				sanitize_text_field( wp_unslash( (string) ( $_POST['account'] ?? '' ) ) ),
				sanitize_text_field( wp_unslash( (string) ( $_POST['script'] ?? '' ) ) ),
				trim( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) ) // Validated by Credentials::save(); never echoed.
			);
			update_option( \XB\Deploy\Deployer::AUTO, ! empty( $_POST['auto'] ), false );
			Plugin::instance()->versioning->store()->audit( 'deploy_config', 'cloudflare', array( 'tokenChanged' => '' !== trim( (string) ( $_POST['token'] ?? '' ) ) ) );
		} catch ( \InvalidArgumentException $e ) {
			set_transient( 'xb_settings_error_' . get_current_user_id(), $e->getMessage(), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function save_turnstile(): void {
		if ( ! self::can_deploy_settings() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare la pubblicazione.', 'xb' ), 403 );
		}
		check_admin_referer( self::TURNSTILE );
		try {
			\XB\Forms\Forms::configure_worker( trim( wp_unslash( (string) ( $_POST['secret'] ?? '' ) ) ) );
		} catch ( \Throwable $e ) {
			set_transient( 'xb_settings_error_' . get_current_user_id(), $e->getMessage(), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * What carrying the previous framework does (or did), in words.
	 *
	 * @param array<mixed> $report A migration report (with `carry`).
	 */
	private static function render_carry( array $report ): void {
		$carry = is_array( $report['carry'] ?? null ) ? $report['carry'] : null;
		if ( null === $carry ) {
			return;
		}
		$labels = array_map( static fn ( \XB\Framework\Provider $p ): string => $p->label(), Plugin::instance()->frameworks->all() );
		$from   = $labels[ (string) $carry['from'] ] ?? (string) $carry['from'];
		$to     = $labels[ (string) $carry['to'] ] ?? (string) $carry['to'];
		if ( 'core-framework' === $carry['route'] ) {
			$text = sprintf( /* translators: 1: number, 2: framework */ __( 'Viene con te: un progetto di Core Framework con i colori e le %1$d variabili di %2$s, più le sue regole che il sito usa (foglio di compatibilità).', 'xb' ), (int) $carry['variables'], $from );
		} else {
			$text = sprintf( /* translators: 1: number, 2: framework, 3: framework */ __( 'Viene con te: le %1$d variabili di %2$s in un foglio globale di Histeon (pubblicato con la release), i token --xb-* con i suoi valori in %3$s, le sue regole che il sito usa nel foglio di compatibilità.', 'xb' ), (int) $carry['variables'], $from, $to );
		}
		if ( ! empty( $report['design'] ) && is_array( $report['design'] ) ) {
			/* translators: %s: colour families */
			$text .= ' ' . sprintf( __( 'La palette di Histeon CSS parte dai colori del sito: %s.', 'xb' ), implode( ', ', array_map( 'strval', $report['design'] ) ) );
		}
		printf( '<p>%s %s</p>', esc_html( '↪' ), esc_html( $text ) );
	}

	/**
	 * Framework plugins installed but turned off, by provider id: the way back after a switch (for example to
	 * Automatic.css after moving to Core Framework).
	 *
	 * @return array<string, string> Provider id => title.
	 */
	private static function inactive_frameworks(): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return array();
		}
		$out = array();
		foreach ( self::FRAMEWORK_PLUGINS as $id => $title ) {
			$file = self::plugin_file( $title );
			if ( null !== $file && ! is_plugin_active( $file ) ) {
				$out[ $id ] = $title;
			}
		}
		return $out;
	}

	private static function plugin_file( string $title ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $data ) {
			if ( ( $data['Name'] ?? '' ) === $title ) {
				return (string) $file;
			}
		}
		return null;
	}

	/** Turns a framework plugin back on: only one of the known ones, found by its title, never a path sent in. */
	public static function reactivate(): void {
		check_admin_referer( self::REACTIVATE );
		if ( ! self::can_manage() || ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Solo un amministratore riattiva un framework.', 'xb' ), 403 );
		}
		$id    = isset( $_POST['xb_framework'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_framework'] ) ) : '';
		$title = self::FRAMEWORK_PLUGINS[ $id ] ?? null;
		$file  = null === $title ? null : self::plugin_file( $title );
		$back  = self::url() . '#xb-migration';
		if ( null === $file ) {
			Menu::back( $back, 'error', __( 'Framework non installato.', 'xb' ) );
		}
		$result = activate_plugin( (string) $file );
		if ( is_wp_error( $result ) ) {
			Menu::back( $back, 'error', $result->get_error_message() );
		}
		Plugin::instance()->versioning->store()->audit( 'framework_reactivate', 'framework', array( 'framework' => $id ) );
		Menu::back( $back, 'done', sprintf( /* translators: %s: framework name */ __( '%s è di nuovo attivo: sceglilo in «Passa a», guarda l\'anteprima della migrazione e preparala in sviluppo.', 'xb' ), (string) $title ) );
	}

	public static function migrate(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		check_admin_referer( self::MIGRATION );
		$target  = isset( $_POST['xb_target'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_target'] ) ) : '';
		$prepare = isset( $_POST['xb_mode'] ) && 'prepare' === $_POST['xb_mode'];
		$carry   = ! empty( $_POST['xb_carry'] );
		try {
			$migration = \XB\Framework\Migration::instance();
			$report    = $prepare ? $migration->prepare( $target, $carry ) : $migration->plan( $target );
			if ( ! $carry ) {
				$report['carry'] = null;
			}
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
		set_transient( 'xb_migration_report_' . get_current_user_id(), $report + array( 'prepared' => $prepare ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function save(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$id = isset( $_POST['xb_framework'] ) ? sanitize_key( wp_unslash( (string) $_POST['xb_framework'] ) ) : '';
		if ( ! Plugin::instance()->frameworks->select( $id ) ) {
			wp_die( esc_html__( 'Framework non disponibile.', 'xb' ), 400 );
		}
		$mode = isset( $_POST['xb_framework_loading'] ) && 'full' === $_POST['xb_framework_loading'] ? 'full' : 'on-demand';
		update_option( \XB\Styles\PageStyles::MODE, $mode );
		update_option( \XB\Styles\PageStyles::GLOBAL_FILE, ! empty( $_POST['xb_css_global_file'] ), false );
		$entries = preg_split( '/[\s,]+/', isset( $_POST['xb_css_safelist'] ) ? wp_unslash( (string) $_POST['xb_css_safelist'] ) : '' ) ?: array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every entry is checked against a strict pattern below.
		$entries = array_slice( array_values( array_unique( array_filter( $entries, static fn ( string $e ): bool => 1 === preg_match( '/^[.#]?-?[_a-zA-Z0-9\\\\:\/-]{1,100}\*?$/', $e ) ) ) ), 0, 500 );
		update_option( \XB\Styles\PageStyles::SAFELIST, implode( ' ', $entries ), false );
		update_option( BuilderScreen::CANVAS_ORIGINS, implode( "\n", BuilderScreen::declared_origins( isset( $_POST['xb_canvas_origins'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['xb_canvas_origins'] ) ) : '' ) ), false );
		$types = isset( $_POST['xb_loop_types'] ) && is_array( $_POST['xb_loop_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['xb_loop_types'] ) ) : array();
		update_option( \XB\Render\LoopPresets::TYPES, \XB\Render\Queries::validate_types( $types ) );
		$taxonomies = isset( $_POST['xb_loop_taxonomies'] ) && is_array( $_POST['xb_loop_taxonomies'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['xb_loop_taxonomies'] ) ) : array();
		update_option( \XB\Render\LoopPresets::TAXONOMIES, \XB\Render\Queries::validate_taxonomies( $taxonomies ) );
		update_option( ReleasesScreen::CHECKS_BLOCK, ! empty( $_POST['xb_checks_block'] ), false );
		Plugin::instance()->versioning->record_live( \XB\Versioning\Documents::SETTINGS );
		Plugin::instance()->versioning->store()->audit( 'framework', 'settings', array( 'framework' => $id ) );
		wp_safe_redirect( self::url( '&saved=1' ) );
		exit;
	}

	/**
	 * This site's signing key and the keys it trusts (P8, docs/spec/signing.md): the fingerprint to compare, a new key,
	 * revocation, and whether unsigned files are refused.
	 */
	private static function render_signing(): void {
		$signing = \XB\Versioning\Signing::class;
		$public  = $signing::public_key();
		$action  = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<section class="xb-panel" id="xb-signing">
			<div class="xb-panel-head"><div>
				<h2><?php esc_html_e( 'Firma degli export', 'xb' ); ?></h2>
				<p><?php esc_html_e( 'I file di release e di trasloco che esporti sono firmati con la chiave di questo sito. Chi li importa confronta l\'impronta qui sotto con quella che vede nell\'anteprima: se coincide, il file arriva davvero da qui. La chiave privata non lascia mai il sito.', 'xb' ); ?></p>
			</div></div>
			<div class="xb-panel-body">
				<?php if ( $signing::unreadable() ) : ?>
					<p class="xb-muted"><?php esc_html_e( 'La chiave di firma di questo sito non si legge più: le chiavi di wp-config.php sono cambiate. Gli export escono senza firma finché non generi una nuova chiave; chi importa i tuoi file dovrà fidarsi di nuovo.', 'xb' ); ?></p>
					<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
						<?php wp_nonce_field( self::SIGNING ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::SIGNING ); ?>">
						<button class="xb-btn" name="op" value="rotate"><?php esc_html_e( 'Genera una nuova chiave', 'xb' ); ?></button>
					</form>
				<?php elseif ( '' === $public ) : ?>
					<p class="xb-muted"><?php esc_html_e( 'Questo sito non può firmare: servono libsodium e le chiavi AUTH_KEY e AUTH_SALT in wp-config.php. Gli export restano senza firma.', 'xb' ); ?></p>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Impronta di questo sito', 'xb' ); ?></strong><br><code style="font-size:14px"><?php echo esc_html( $signing::fingerprint( $public ) ); ?></code></p>
					<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Generare una nuova chiave? I siti che si fidano di questa passeranno alla nuova con il prossimo file firmato.', 'xb' ) ) ); ?>)">
						<?php wp_nonce_field( self::SIGNING ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::SIGNING ); ?>">
						<button class="xb-btn" name="op" value="rotate"><?php esc_html_e( 'Genera una nuova chiave', 'xb' ); ?></button>
					</form>
				<?php endif; ?>
				<h3><?php esc_html_e( 'Siti di cui ti fidi', 'xb' ); ?></h3>
				<?php $keys = $signing::trusted(); ?>
				<?php if ( array() === $keys ) : ?>
					<p class="xb-muted"><?php esc_html_e( 'Nessuno: la prima volta che importi un file firmato da un altro sito, l\'anteprima ti chiede se fidarti della sua chiave.', 'xb' ); ?></p>
				<?php else : ?>
					<table class="xb-table"><tbody>
					<?php foreach ( $keys as $key ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $key['site'] ); ?></strong><br><code><?php echo esc_html( $key['fingerprint'] ); ?></code></td>
							<td><?php echo $key['revoked'] ? '<span class="xb-pill is-warn">' . esc_html__( 'revocata', 'xb' ) . '</span>' : '<span class="xb-pill is-live">' . esc_html__( 'fidata', 'xb' ) . '</span>'; ?></td>
							<td>
								<?php if ( ! $key['revoked'] ) : ?>
									<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
										<?php wp_nonce_field( self::SIGNING ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( self::SIGNING ); ?>">
										<input type="hidden" name="fingerprint" value="<?php echo esc_attr( $key['fingerprint'] ); ?>">
										<button class="xb-btn xb-btn--small" name="op" value="revoke"><?php esc_html_e( 'Revoca', 'xb' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
					<?php wp_nonce_field( self::SIGNING ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SIGNING ); ?>">
					<input type="hidden" name="op" value="require">
					<p><label><input type="checkbox" name="require" value="1" <?php checked( (bool) get_option( $signing::REQUIRE_OPTION ) ); ?>> <?php esc_html_e( 'Richiedi la firma: rifiuta i file non firmati', 'xb' ); ?></label>
					<button class="xb-btn xb-btn--small"><?php esc_html_e( 'Salva', 'xb' ); ?></button></p>
				</form>
			</div>
		</section>
		<?php
	}

	/** Rotate this site's key, revoke a trusted one, or require signatures. Administrators only. */
	public static function signing(): void {
		check_admin_referer( self::SIGNING );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per configurare Histeon.', 'xb' ), 403 );
		}
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$back = self::url() . '#xb-signing';
		if ( 'rotate' === $op ) {
			\XB\Versioning\Signing::rotate();
			Menu::back( $back, 'done', __( 'Nuova chiave generata: confronta la nuova impronta con chi importa i tuoi file.', 'xb' ) );
		}
		if ( 'revoke' === $op ) {
			$fingerprint = isset( $_POST['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['fingerprint'] ) ) : '';
			\XB\Versioning\Signing::revoke( $fingerprint );
			Menu::back( $back, 'done', __( 'Chiave revocata: i file firmati con lei saranno rifiutati.', 'xb' ) );
		}
		if ( 'require' === $op ) {
			update_option( \XB\Versioning\Signing::REQUIRE_OPTION, ! empty( $_POST['require'] ), false );
			Menu::back( $back, 'done', __( 'Impostazioni salvate.', 'xb' ) );
		}
		Menu::back( $back, 'error', __( 'Operazione sconosciuta.', 'xb' ) );
	}
}
