<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Proposals;
use XB\Versioning\VersioningException;

/**
 * Histeon → Release, «Proposte per il cliente» (lista della spesa 160): save what is in development as a proposal,
 * share its signed link or a side-by-side comparison of two, and choose the one that becomes the next release.
 */
final class ProposalsSection {

	private const CREATE = 'xb_proposal_create';
	private const CHOOSE = 'xb_proposal_choose';
	private const DELETE = 'xb_proposal_delete';

	public static function register(): void {
		add_action( 'admin_post_' . self::CREATE, array( self::class, 'create' ) );
		add_action( 'admin_post_' . self::CHOOSE, array( self::class, 'choose' ) );
		add_action( 'admin_post_' . self::DELETE, array( self::class, 'delete' ) );
	}

	private static function back(): string {
		return Menu::url( ReleasesScreen::SLUG ) . '#xb-proposte';
	}

	public static function create(): void {
		check_admin_referer( self::CREATE );
		try {
			$p = Proposals::create( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '', isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0 );
			/* translators: %s: proposal name */
			Menu::back( self::back(), 'done', sprintf( __( 'Proposta «%s» salvata: copia il suo link e mandalo al cliente.', 'xb' ), $p['name'] ) );
		} catch ( VersioningException $e ) {
			Menu::back( self::back(), 'error', $e->getMessage() );
		}
	}

	public static function choose(): void {
		check_admin_referer( self::CHOOSE );
		try {
			$id     = isset( $_POST['proposal'] ) ? sanitize_key( wp_unslash( $_POST['proposal'] ) ) : '';
			$result = Proposals::choose( $id );
			$failed = array_map( static fn ( string $doc ): string => Documents::label( $doc ), array_keys( $result['failed'] ) );
			Menu::back(
				self::back(),
				'done',
				array() === $failed
					? __( 'Proposta scelta: ora è in sviluppo. Controllala e pubblicala come release.', 'xb' )
					/* translators: %s: documents */
					: sprintf( __( 'Proposta scelta, tranne: %s.', 'xb' ), implode( ', ', $failed ) )
			);
		} catch ( VersioningException $e ) {
			Menu::back( self::back(), 'error', $e->getMessage() );
		}
	}

	public static function delete(): void {
		check_admin_referer( self::DELETE );
		try {
			Proposals::delete( isset( $_POST['proposal'] ) ? sanitize_key( wp_unslash( $_POST['proposal'] ) ) : '' );
			Menu::back( self::back(), 'done', __( 'Proposta eliminata: il suo link non funziona più.', 'xb' ) );
		} catch ( VersioningException $e ) {
			Menu::back( self::back(), 'error', $e->getMessage() );
		}
	}

	/** @param list<array{doc: string, label: string, sent: bool}> $changes */
	public static function render( array $changes ): void {
		if ( ! current_user_can( 'xb_build' ) ) {
			return;
		}
		$proposals = array_values( Proposals::all() );
		$pages     = array_values( array_filter( array_map( static fn ( array $c ): int => (int) Documents::post_id( $c['doc'] ), $changes ), static fn ( int $id ): bool => $id > 0 && in_array( get_post_type( $id ), array( 'page', 'post' ), true ) ) );
		$form      = static function ( string $action, string $field = '', string $value = '' ): void {
			echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
			wp_nonce_field( $action );
			if ( '' !== $field ) {
				echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value ) . '">';
			}
		};
		?>
		<h2 class="xb-section-title" id="xb-proposte"><?php esc_html_e( 'Proposte per il cliente', 'xb' ); ?> <small><?php esc_html_e( 'versioni da mostrare e confrontare prima di scegliere', 'xb' ); ?></small></h2>
		<div class="xb-panel">
			<div class="xb-panel-body">
				<p class="xb-muted"><?php esc_html_e( 'Salva ciò che è in sviluppo come proposta, poi cambia e salva la seconda. Ogni proposta ha il suo link di anteprima, valido 7 giorni: mostra il sito online con le modifiche di quella proposta. La proposta scelta torna in sviluppo e diventa la prossima release.', 'xb' ); ?></p>
				<?php if ( array() !== $changes ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-inline">
						<?php $form( self::CREATE ); ?>
						<label><?php esc_html_e( 'Nome', 'xb' ); ?> <input name="name" required maxlength="80" placeholder="<?php esc_attr_e( 'Proposta A', 'xb' ); ?>"></label>
						<?php if ( array() !== $pages ) : ?>
							<label><?php esc_html_e( 'Si apre su', 'xb' ); ?>
								<select name="post">
									<?php foreach ( $pages as $page ) : ?>
										<option value="<?php echo esc_attr( (string) $page ); ?>"><?php echo esc_html( get_the_title( $page ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						<?php endif; ?>
						<button class="xb-btn"><?php esc_html_e( 'Salva lo sviluppo come proposta', 'xb' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<?php if ( array() !== $proposals ) : ?>
				<table class="xb-table"><tbody>
				<?php foreach ( $proposals as $i => $p ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $p['name'] ); ?></strong><div class="xb-muted"><?php echo esc_html( Menu::date( gmdate( 'Y-m-d H:i:s', $p['created'] ) ) . ' · ' . sprintf( /* translators: %d: documents */ _n( '%d documento', '%d documenti', count( $p['items'] ), 'xb' ), count( $p['items'] ) ) ); ?></div></td>
						<td><input class="xb-copy" readonly value="<?php echo esc_attr( Proposals::url( $p ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: proposal name */ __( 'Link di anteprima di %s', 'xb' ), $p['name'] ) ); ?>" onfocus="this.select()" style="width:100%"></td>
						<td><div class="xb-row-actions">
							<?php if ( isset( $proposals[ $i + 1 ] ) || isset( $proposals[ $i - 1 ] ) ) : ?>
								<?php $other = $proposals[ $i + 1 ] ?? $proposals[ $i - 1 ]; ?>
								<a class="xb-btn" target="_blank" rel="noopener" href="<?php echo esc_url( Proposals::compare_url( $p, $other ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: other proposal name */ __( 'Confronta con %s', 'xb' ), $other['name'] ) ); ?></a>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-inline" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Mettere in sviluppo questa proposta? Ciò che è in sviluppo ora resta nella storia delle versioni.', 'xb' ); ?>">
								<?php $form( self::CHOOSE, 'proposal', $p['id'] ); ?>
								<button class="xb-btn xb-btn--primary"><?php esc_html_e( 'Scegli questa', 'xb' ); ?></button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="xb-inline">
								<?php $form( self::DELETE, 'proposal', $p['id'] ); ?>
								<button class="xb-btn"><?php esc_html_e( 'Elimina', 'xb' ); ?></button>
							</form>
						</div></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
