<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Store;
use XB\Versioning\VersioningException;

/**
 * XB → Versioni: every versioned document (pages, components, templates, styles, settings) and, for each, its
 * versions — in development or online, by whom, in which release — with a comparison against the current
 * development version and a way to bring an earlier one back into development.
 */
final class HistoryScreen {

	public const SLUG    = 'xb-history';
	private const REVERT = 'xb_admin_revert';

	public static function register(): void {
		add_action( 'admin_post_' . self::REVERT, array( self::class, 'revert' ) );
	}

	public static function menu(): void {
		add_submenu_page( Menu::SLUG, __( 'Versioni Histeon', 'xb' ), __( 'Versioni', 'xb' ), 'xb_build', self::SLUG, array( self::class, 'render' ) );
	}

	public static function revert(): void {
		check_admin_referer( self::REVERT );
		$doc    = isset( $_POST['doc'] ) ? sanitize_text_field( wp_unslash( $_POST['doc'] ) ) : '';
		$commit = isset( $_POST['commit'] ) ? sanitize_key( wp_unslash( $_POST['commit'] ) ) : '';
		if ( ! Menu::can_see( $doc ) ) {
			wp_die( esc_html__( 'Non puoi modificare questo documento.', 'xb' ), 403 );
		}
		$back = Menu::url( self::SLUG, array( 'doc' => $doc ) );
		try {
			Plugin::instance()->versioning->revert( $doc, $commit );
		} catch ( VersioningException $e ) {
			Menu::back( $back, 'error', $e->getMessage() );
		}
		Menu::back( $back, 'reverted' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'xb_build' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per usare Histeon.', 'xb' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views.
		$doc     = isset( $_GET['doc'] ) ? sanitize_text_field( wp_unslash( $_GET['doc'] ) ) : '';
		$compare = isset( $_GET['compare'] ) ? sanitize_key( wp_unslash( $_GET['compare'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		if ( '' !== $doc && ! Menu::can_see( $doc ) ) {
			wp_die( esc_html__( 'Non puoi vedere le versioni di questo documento.', 'xb' ), 403 );
		}
		if ( '' === $doc ) {
			Menu::open( self::SLUG, __( 'Versioni', 'xb' ), __( 'Ogni salvataggio nel builder, ogni modifica in Gutenberg e ogni release lascia una versione: puoi confrontarla e riportarla in sviluppo.', 'xb' ) );
			self::documents( $search );
		} else {
			Menu::open(
				self::SLUG,
				Menu::describe( $doc ),
				'' !== $compare ? __( 'Confronto con la versione attuale in sviluppo', 'xb' ) : __( 'Versioni del documento, dalla più recente', 'xb' ),
				static function () use ( $doc, $compare ): void {
					$back = '' !== $compare ? Menu::url( self::SLUG, array( 'doc' => $doc ) ) : Menu::url( self::SLUG );
					printf( '<a class="xb-btn" href="%s">%s%s</a>', esc_url( $back ), Menu::icon( 'arrow-left-alt2' ), esc_html( '' !== $compare ? __( 'Versioni', 'xb' ) : __( 'Tutti i documenti', 'xb' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is fixed.
					OverviewScreen::doc_links( $doc );
				}
			);
			'' !== $compare ? self::compare( $doc, $compare ) : self::versions( $doc );
		}
		Menu::close();
	}

	/** Every versioned document the user may see. */
	private static function documents( string $search ): void {
		$store = Plugin::instance()->versioning->store();
		$docs  = array_values( array_unique( array_merge( $store->docs( Store::DEV ), $store->docs( Store::PROD ) ) ) );
		$docs  = array_values( array_filter( $docs, array( Menu::class, 'can_see' ) ) );
		if ( '' !== $search ) {
			$docs = array_values( array_filter( $docs, static fn ( string $d ): bool => false !== mb_stripos( Menu::describe( $d ), $search ) ) );
		}
		usort( $docs, static fn ( string $a, string $b ): int => strcasecmp( Menu::describe( $a ), Menu::describe( $b ) ) );
		?>
		<div class="xb-panel">
			<div class="xb-toolbar">
				<span class="xb-muted"><?php echo esc_html( sprintf( /* translators: %d: number of documents */ _n( '%d documento con versioni', '%d documenti con versioni', count( $docs ), 'xb' ), count( $docs ) ) ); ?></span>
				<form method="get" class="xb-search" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Cerca un documento…', 'xb' ); ?>" aria-label="<?php esc_attr_e( 'Cerca', 'xb' ); ?>">
					<button class="xb-btn"><?php echo Menu::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'Cerca', 'xb' ); ?></span></button>
				</form>
			</div>
			<?php if ( array() === $docs ) : ?>
				<div class="xb-empty"><?php echo Menu::icon( 'backup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Nessun documento', 'xb' ); ?></strong><span><?php esc_html_e( 'Le versioni nascono al primo salvataggio nel builder.', 'xb' ); ?></span></div>
			<?php else : ?>
				<table class="xb-table">
					<thead><tr><th><?php esc_html_e( 'Documento', 'xb' ); ?></th><th><?php esc_html_e( 'Versioni', 'xb' ); ?></th><th><?php esc_html_e( 'Stato', 'xb' ); ?></th><th class="xb-hide-small"><?php esc_html_e( 'Ultima', 'xb' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Azioni', 'xb' ); ?></span></th></tr></thead>
					<tbody>
					<?php foreach ( $docs as $doc ) : ?>
						<?php
						$dev  = $store->head( $doc, Store::DEV );
						$prod = $store->head( $doc, Store::PROD );
						$last = $store->history( $doc, 1 )[0] ?? null;
						?>
						<tr>
							<td><a class="xb-title" href="<?php echo esc_url( Menu::url( self::SLUG, array( 'doc' => $doc ) ) ); ?>"><?php echo esc_html( Menu::describe( $doc ) ); ?></a></td>
							<td><span class="xb-pill is-plain"><?php echo esc_html( (string) $store->count_versions( $doc ) ); ?></span></td>
							<td>
								<?php if ( null !== $dev && $dev !== $prod ) : ?>
									<span class="xb-pill is-dev"><?php esc_html_e( 'da pubblicare', 'xb' ); ?></span>
								<?php else : ?>
									<span class="xb-pill is-live"><?php esc_html_e( 'online', 'xb' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="xb-hide-small xb-muted"><?php echo null === $last ? '' : esc_html( Menu::date( $last['created'] ) . ' · ' . Menu::user_name( $last['author'] ) ); ?></td>
							<td><div class="xb-row-actions"><a class="xb-btn xb-btn--small" href="<?php echo esc_url( Menu::url( self::SLUG, array( 'doc' => $doc ) ) ); ?>"><?php esc_html_e( 'Versioni', 'xb' ); ?></a></div></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/** One document's versions, newest first. */
	private static function versions( string $doc ): void {
		$store    = Plugin::instance()->versioning->store();
		$dev      = $store->head( $doc, Store::DEV );
		$prod     = $store->head( $doc, Store::PROD );
		$history  = $store->history( $doc, 300 );
		$releases = $store->releases_of( $doc );
		?>
		<div class="xb-panel">
			<?php if ( array() === $history ) : ?>
				<div class="xb-empty"><?php echo Menu::icon( 'backup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Nessuna versione salvata', 'xb' ); ?></strong></div>
			<?php else : ?>
				<ol class="xb-timeline">
				<?php foreach ( $history as $commit ) : ?>
					<?php
					$live  = Store::PROD === $commit['branch'];
					$state = ( $live ? 'is-live' : 'is-dev' ) . ( $commit['id'] === $prod ? ' is-current' : '' );
					?>
					<li class="<?php echo esc_attr( $state ); ?>">
						<div class="xb-tl-title">
							<?php echo esc_html( '' !== $commit['message'] ? $commit['message'] : __( 'Salvataggio', 'xb' ) ); ?>
							<span class="xb-pill <?php echo $live ? 'is-live' : 'is-dev'; ?>"><?php echo esc_html( $live ? __( 'online', 'xb' ) : __( 'sviluppo', 'xb' ) ); ?></span>
							<?php if ( $commit['id'] === $dev ) : ?>
								<span class="xb-pill is-plain"><?php esc_html_e( 'attuale in sviluppo', 'xb' ); ?></span>
							<?php endif; ?>
							<?php if ( $commit['id'] === $prod ) : ?>
								<span class="xb-pill is-live"><?php esc_html_e( 'attuale online', 'xb' ); ?></span>
							<?php endif; ?>
							<?php foreach ( $releases[ $commit['id'] ] ?? array() as $version ) : ?>
								<span class="xb-pill is-plain"><?php echo esc_html( 'v' . $version ); ?></span>
							<?php endforeach; ?>
							<?php if ( ! $commit['trusted'] ) : ?>
								<span class="xb-pill is-warn" title="<?php esc_attr_e( 'Scritta da chi non ha unfiltered_html: viene filtrata di nuovo a ogni ripristino.', 'xb' ); ?>"><?php esc_html_e( 'non fidata', 'xb' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="xb-tl-actions">
							<?php if ( $commit['id'] !== $dev ) : ?>
								<a class="xb-btn xb-btn--small" href="<?php echo esc_url( Menu::url( self::SLUG, array( 'doc' => $doc, 'compare' => $commit['id'] ) ) ); ?>"><?php echo Menu::icon( 'columns' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Confronta', 'xb' ); ?></a>
								<form class="xb-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( (string) wp_json_encode( __( 'Riportare questa versione in sviluppo? Andrà online con la prossima release.', 'xb' ) ) ); ?>)">
									<?php wp_nonce_field( self::REVERT ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( self::REVERT ); ?>">
									<input type="hidden" name="doc" value="<?php echo esc_attr( $doc ); ?>">
									<input type="hidden" name="commit" value="<?php echo esc_attr( $commit['id'] ); ?>">
									<button class="xb-btn xb-btn--small"><?php echo Menu::icon( 'undo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Ripristina', 'xb' ); ?></button>
								</form>
							<?php endif; ?>
						</div>
						<div class="xb-tl-meta"><?php echo esc_html( Menu::date( $commit['created'] ) . ' · ' . Menu::user_name( $commit['author'] ) ); ?></div>
					</li>
				<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/** A version against the current development version, as text (one block or rule per line). */
	private static function compare( string $doc, string $commit_id ): void {
		$store = Plugin::instance()->versioning->store();
		try {
			$old     = $store->get( $commit_id );
			$current = $store->get( $store->head( $doc, Store::DEV ) );
		} catch ( VersioningException $e ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			return;
		}
		if ( null === $old || $old['doc'] !== $doc || null === $current ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Versione inesistente per questo documento.', 'xb' ) . '</p></div>';
			return;
		}
		$diff = wp_text_diff(
			self::readable( $old['payload'] ),
			self::readable( $current['payload'] ),
			array(
				'title_left'  => sprintf( /* translators: %s: date */ __( 'Versione del %s', 'xb' ), Menu::date( $old['created'] ) ),
				'title_right' => __( 'Attuale in sviluppo', 'xb' ),
			)
		);
		?>
		<div class="xb-panel">
			<div class="xb-panel-body xb-diff">
				<?php if ( '' === $diff ) : ?>
					<div class="xb-empty"><?php echo Menu::icon( 'yes-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><strong><?php esc_html_e( 'Nessuna differenza', 'xb' ); ?></strong><span><?php esc_html_e( 'Questa versione è uguale a quella attuale in sviluppo.', 'xb' ); ?></span></div>
				<?php else : ?>
					<?php echo wp_kses_post( $diff ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A payload as lines that diff well: block markup one block per line, scripts and CSS as written,
	 * anything else as indented JSON.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function readable( array $payload ): string {
		$out = '';
		foreach ( $payload as $key => $value ) {
			$out .= '== ' . $key . " ==\n";
			if ( 'content' === $key && is_string( $value ) ) {
				$out .= trim( (string) preg_replace( '/(<!-- \/?wp:)/', "\n$1", $value ) ) . "\n";
			} elseif ( is_string( $value ) ) {
				$out .= $value . "\n";
			} elseif ( 'styles' === $key && is_array( $value ) ) {
				foreach ( $value as $entry ) {
					$out .= is_array( $entry ) ? (string) ( $entry['selector'] ?? '' ) . " {\n" . (string) ( $entry['css'] ?? '' ) . "\n}\n" : '';
				}
			} elseif ( 'globals' === $key && is_array( $value ) ) {
				foreach ( $value as $sheet ) {
					$out .= is_array( $sheet ) ? '/* ' . (string) ( $sheet['id'] ?? '' ) . " */\n" . str_replace( '}', "}\n", (string) ( $sheet['css'] ?? '' ) ) . "\n" : '';
				}
			} else {
				$out .= (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
			}
		}
		return $out;
	}
}
