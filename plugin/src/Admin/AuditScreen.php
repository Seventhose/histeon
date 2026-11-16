<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Plugin;
use XB\Versioning\Documents;

/**
 * Histeon → Registro: the important operations, who did them and when (docs/spec/audit.md). Read only, for
 * administrators, with filters and a CSV export. Every value comes from the database and is printed escaped; the
 * export neutralises spreadsheet formulas.
 */
final class AuditScreen {

	public const SLUG     = 'xb-audit';
	private const EXPORT  = 'xb_admin_audit_export';
	private const PER_PAGE = 50;

	public static function register(): void {
		add_action( 'admin_post_' . self::EXPORT, array( self::class, 'export' ) );
	}

	public static function menu(): void {
		add_submenu_page( Menu::SLUG, __( 'Registro di Histeon', 'xb' ), __( 'Registro', 'xb' ), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	private static function can_see(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	/** @return array<string, string> Operation key → what it means. */
	public static function labels(): array {
		return array(
			'save'                 => __( 'Salvataggio in sviluppo', 'xb' ),
			'publish'              => __( 'Release pubblicata', 'xb' ),
			'restore'              => __( 'Release ripristinata', 'xb' ),
			'revert'               => __( 'Versione riportata in sviluppo', 'xb' ),
			'import'               => __( 'Importazione', 'xb' ),
			'import_release'       => __( 'Release importata', 'xb' ),
			'versions_repair'      => __( 'Versioni riparate dopo uno spostamento', 'xb' ),
			'export_release'       => __( 'Release esportata', 'xb' ),
			'import_site'          => __( 'Sito importato (trasloco)', 'xb' ),
			'export_site'          => __( 'Sito esportato (trasloco)', 'xb' ),
			'static_zip'           => __( 'Sito statico scaricato', 'xb' ),
			'deploy'               => __( 'Pubblicazione su Cloudflare', 'xb' ),
			'deploy_failed'        => __( 'Pubblicazione su Cloudflare non riuscita', 'xb' ),
			'deploy_rollback'      => __( 'Ritorno a una pubblicazione precedente', 'xb' ),
			'deploy_config'        => __( 'Impostazioni di Cloudflare', 'xb' ),
			'updates_config'       => __( 'Impostazioni degli aggiornamenti', 'xb' ),
			'update_backup'        => __( 'Copia di sicurezza di Histeon', 'xb' ),
			'update_restore'       => __( 'Histeon rimesso da una copia di sicurezza', 'xb' ),
			'framework'            => __( 'Framework CSS cambiato', 'xb' ),
			'framework_reactivate' => __( 'Framework riattivato', 'xb' ),
			'acss_to_core'         => __( 'Da Automatic.css a Core Framework', 'xb' ),
			'migration'            => __( 'Migrazione', 'xb' ),
			'preview_link'         => __( 'Link di anteprima creato', 'xb' ),
			'revoke_previews'      => __( 'Link di anteprima revocati', 'xb' ),
			'create_component'     => __( 'Componente creato', 'xb' ),
			'create_template'      => __( 'Template creato', 'xb' ),
			'theme_carry'          => __( 'Template del sito portati al nuovo tema', 'xb' ),
			'library_import'       => __( 'Libreria di sezioni importata', 'xb' ),
			'library_delete'       => __( 'Sezione tolta dalla libreria', 'xb' ),
			'rename_selector'      => __( 'Selettore rinominato', 'xb' ),
			'delete_selectors'     => __( 'Selettori eliminati', 'xb' ),
			'ability'              => __( 'Operazione di un agente IA', 'xb' ),
			'remote_link'          => __( 'Codice di abbinamento creato', 'xb' ),
			'remote_revoke'        => __( 'Collegamento con lo staging revocato', 'xb' ),
			'remote_pair'          => __( 'Indirizzo dello staging fissato al collegamento', 'xb' ),
			'remote_send'          => __( 'Inviato alla produzione', 'xb' ),
			'remote_import'        => __( 'Ricevuto dallo staging', 'xb' ),
			'remote_refused'       => __( 'Richiesta dallo staging rifiutata', 'xb' ),
			'client_access'        => __( 'Permessi di un cliente cambiati', 'xb' ),
		);
	}

	public static function label( string $action ): string {
		return self::labels()[ $action ] ?? $action;
	}

	/** What the row is about, in words where Histeon knows the object. */
	public static function object_label( string $object ): string {
		if ( 1 === preg_match( '/^release:(\d+)$/', $object, $m ) ) {
			$release = Plugin::instance()->versioning->store()->get_release( (int) $m[1] );
			/* translators: %s: release version */
			return null === $release ? $object : sprintf( __( 'Release %s', 'xb' ), (string) $release['version'] );
		}
		if ( Documents::valid( $object ) && Menu::can_see( $object ) ) {
			return Menu::describe( $object );
		}
		return $object;
	}

	/**
	 * The details as short «key: value» lines (lists shortened), never markup.
	 *
	 * @return list<string>
	 */
	public static function detail_lines( string $details ): array {
		$data = json_decode( $details, true );
		if ( ! is_array( $data ) ) {
			return '' === trim( $details ) ? array() : array( mb_substr( $details, 0, 200 ) );
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$items = array_map( static fn ( mixed $v ): string => is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v ), array_slice( array_values( $value ), 0, 8 ) );
				$more  = count( $value ) - count( $items );
				/* translators: %d: how many more items */
				$value = implode( ', ', $items ) . ( $more > 0 ? ' ' . sprintf( __( '(e altri %d)', 'xb' ), $more ) : '' );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? __( 'sì', 'xb' ) : __( 'no', 'xb' );
			} elseif ( null === $value ) {
				$value = '—';
			}
			$out[] = mb_substr( $key . ': ' . (string) $value, 0, 200 );
			if ( count( $out ) >= 12 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The filters of the request: an operation the log knows, a user id, two days.
	 *
	 * @return array{action: string, user: int, from: string, to: string}
	 */
	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters of a view (and of the nonce-checked export).
		$action = isset( $_REQUEST['op'] ) ? sanitize_key( wp_unslash( $_REQUEST['op'] ) ) : '';
		$user   = isset( $_REQUEST['user'] ) ? absint( $_REQUEST['user'] ) : 0;
		$days   = array();
		foreach ( array( 'from', 'to' ) as $key ) {
			$day          = isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			$days[ $key ] = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : '';
		}
		// phpcs:enable
		return array(
			'action' => mb_substr( $action, 0, 32 ),
			'user'   => $user,
			'from'   => $days['from'],
			'to'     => $days['to'],
		);
	}

	// ------------------------------------------------------------------ export

	/** A spreadsheet cell: text starting like a formula gets a leading quote (CSV injection). */
	public static function cell( string $value ): string {
		return 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	public static function export(): void {
		check_admin_referer( self::EXPORT );
		if ( ! self::can_see() ) {
			wp_die( esc_html__( 'Solo un amministratore legge il registro.', 'xb' ), 403 );
		}
		$page = Plugin::instance()->versioning->store()->audit_page( self::filters(), 1, 500 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="histos-registro-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			exit;
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 mark for spreadsheets.
		fputcsv( $out, array( __( 'Quando (UTC)', 'xb' ), __( 'Chi', 'xb' ), __( 'Operazione', 'xb' ), __( 'Oggetto', 'xb' ), __( 'Dettagli', 'xb' ) ), ',', '"', '' );
		foreach ( $page['rows'] as $row ) {
			fputcsv(
				$out,
				array_map(
					array( self::class, 'cell' ),
					array( $row['at'], Menu::user_name( $row['user_id'] ), self::label( $row['action'] ), self::object_label( $row['object'] ), implode( ' · ', self::detail_lines( $row['details'] ) ) )
				),
				',',
				'"',
				''
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ------------------------------------------------------------------ screen

	public static function render(): void {
		if ( ! self::can_see() ) {
			wp_die( esc_html__( 'Solo un amministratore legge il registro.', 'xb' ), 403 );
		}
		$store   = Plugin::instance()->versioning->store();
		$filters = self::filters();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page number of a read-only view.
		$current = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$page    = $store->audit_page( $filters, $current, self::PER_PAGE );
		$facets  = $store->audit_facets();
		$pages   = max( 1, (int) ceil( $page['total'] / self::PER_PAGE ) );
		$query   = array_filter( array( 'op' => $filters['action'], 'user' => $filters['user'] ? (string) $filters['user'] : '', 'from' => $filters['from'], 'to' => $filters['to'] ) );
		Menu::open( self::SLUG, __( 'Registro', 'xb' ), __( 'Le operazioni importanti, chi le ha fatte e quando: pubblicazioni, ripristini, importazioni, traslochi, configurazioni e le azioni degli agenti IA.', 'xb' ) );
		?>
		<div class="xb-panel">
			<form method="get" class="xb-toolbar xb-audit-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label><?php esc_html_e( 'Operazione', 'xb' ); ?>
					<select name="op">
						<option value=""><?php esc_html_e( 'Tutte', 'xb' ); ?></option>
						<?php foreach ( $facets['actions'] as $action ) : ?>
							<option value="<?php echo esc_attr( $action ); ?>" <?php selected( $filters['action'], $action ); ?>><?php echo esc_html( self::label( $action ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Chi', 'xb' ); ?>
					<select name="user">
						<option value="0"><?php esc_html_e( 'Tutti', 'xb' ); ?></option>
						<?php foreach ( $facets['users'] as $user ) : ?>
							<option value="<?php echo esc_attr( (string) $user ); ?>" <?php selected( $filters['user'], $user ); ?>><?php echo esc_html( Menu::user_name( $user ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Dal', 'xb' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
				<label><?php esc_html_e( 'Al', 'xb' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
				<button class="xb-btn"><?php esc_html_e( 'Filtra', 'xb' ); ?></button>
				<?php if ( array() !== $query ) : ?>
					<a class="xb-btn" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>"><?php esc_html_e( 'Azzera', 'xb' ); ?></a>
				<?php endif; ?>
				<span class="xb-muted xb-audit-count"><?php echo esc_html( sprintf( /* translators: %d: number of entries */ _n( '%d voce', '%d voci', $page['total'], 'xb' ), $page['total'] ) ); ?></span>
			</form>
			<?php if ( array() === $page['rows'] ) : ?>
				<div class="xb-empty"><?php echo Menu::icon( 'list-view' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Nessuna voce', 'xb' ); ?></strong><span><?php echo esc_html( array() === $query ? __( 'Le operazioni importanti compaiono qui appena qualcuno le fa.', 'xb' ) : __( 'Nessuna voce con questi filtri.', 'xb' ) ); ?></span></div>
			<?php else : ?>
				<table class="xb-table xb-audit">
					<thead><tr><th scope="col"><?php esc_html_e( 'Quando', 'xb' ); ?></th><th scope="col"><?php esc_html_e( 'Chi', 'xb' ); ?></th><th scope="col"><?php esc_html_e( 'Operazione', 'xb' ); ?></th><th scope="col"><?php esc_html_e( 'Oggetto', 'xb' ); ?></th><th scope="col" class="xb-hide-small"><?php esc_html_e( 'Dettagli', 'xb' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $page['rows'] as $row ) : ?>
						<tr>
							<td class="xb-muted"><time datetime="<?php echo esc_attr( str_replace( ' ', 'T', $row['at'] ) . 'Z' ); ?>"><?php echo esc_html( Menu::date( $row['at'] ) ); ?></time></td>
							<td><?php echo esc_html( Menu::user_name( $row['user_id'] ) ); ?></td>
							<td><strong><?php echo esc_html( self::label( $row['action'] ) ); ?></strong></td>
							<td><?php echo esc_html( self::object_label( $row['object'] ) ); ?></td>
							<td class="xb-hide-small xb-muted">
								<?php foreach ( self::detail_lines( $row['details'] ) as $line ) : ?>
									<div><?php echo esc_html( $line ); ?></div>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="xb-toolbar xb-audit-foot">
					<?php if ( $pages > 1 ) : ?>
						<nav class="xb-pager" aria-label="<?php esc_attr_e( 'Pagine del registro', 'xb' ); ?>">
							<?php if ( $current > 1 ) : ?>
								<a class="xb-btn xb-btn--small" href="<?php echo esc_url( Menu::url( self::SLUG, $query + array( 'paged' => (string) ( $current - 1 ) ) ) ); ?>"><?php esc_html_e( '← Più recenti', 'xb' ); ?></a>
							<?php endif; ?>
							<span class="xb-muted"><?php echo esc_html( sprintf( /* translators: 1: page, 2: pages */ __( 'Pagina %1$d di %2$d', 'xb' ), $current, $pages ) ); ?></span>
							<?php if ( $current < $pages ) : ?>
								<a class="xb-btn xb-btn--small" href="<?php echo esc_url( Menu::url( self::SLUG, $query + array( 'paged' => (string) ( $current + 1 ) ) ) ); ?>"><?php esc_html_e( 'Meno recenti →', 'xb' ); ?></a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-audit-export">
						<?php wp_nonce_field( self::EXPORT ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT ); ?>">
						<?php foreach ( $query as $key => $value ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
						<?php endforeach; ?>
						<button class="xb-btn"><?php echo Menu::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Esporta CSV', 'xb' ); ?></button>
						<span class="xb-muted"><?php esc_html_e( 'Le ultime 500 voci con questi filtri.', 'xb' ); ?></span>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
		Menu::close();
	}
}
