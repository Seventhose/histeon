<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Render\ComponentRegistry;
use XB\Rest\Controller;
use XB\Spec;
use XB\Versioning\Documents;

/**
 * XB → Pagine, Articoli, Componenti: the content built with XB (or every item, to start using XB on it),
 * with the builder, Gutenberg, versions and the live page one click away. Only items the user may edit.
 */
final class ContentScreen {

	public const PAGES      = 'xb-pages';
	public const POSTS      = 'xb-posts';
	public const COMPONENTS = 'xb-components';
	private const TYPES     = array(
		self::PAGES      => 'page',
		self::POSTS      => 'post',
		self::COMPONENTS => 'wp_block',
	);
	private const PER_PAGE  = 30;

	public static function register(): void {
		// Screens only: every action happens in the builder or in Gutenberg.
	}

	public static function menu(): void {
		add_submenu_page( Menu::SLUG, __( 'Pagine Histeon', 'xb' ), __( 'Pagine', 'xb' ), 'xb_build', self::PAGES, array( self::class, 'render' ) );
		add_submenu_page( Menu::SLUG, __( 'Articoli Histeon', 'xb' ), __( 'Articoli', 'xb' ), 'xb_build', self::POSTS, array( self::class, 'render' ) );
		add_submenu_page( Menu::SLUG, __( 'Componenti Histeon', 'xb' ), __( 'Componenti', 'xb' ), 'xb_build', self::COMPONENTS, array( self::class, 'render' ) );
	}

	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list with filters.
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::PAGES;
		$type = self::TYPES[ $slug ] ?? 'page';
		$object = get_post_type_object( $type );
		if ( ! current_user_can( 'xb_build' ) || null === $object || ! current_user_can( $object->cap->edit_posts ) ) {
			wp_die( esc_html__( 'Non hai i permessi per vedere questi contenuti.', 'xb' ), 403 );
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$all    = isset( $_GET['all'] ) && '1' === $_GET['all'];
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable

		$filter = static function ( string $where ) use ( $all ): string {
			global $wpdb;
			return $all ? $where : $where . $wpdb->prepare( " AND {$wpdb->posts}.post_content LIKE %s", '%' . $wpdb->esc_like( '<!-- wp:xb/' ) . '%' );
		};
		add_filter( 'posts_where', $filter );
		$query = new \WP_Query(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				// Other people's private posts only for who may read them: the count and the search would tell.
				'perm'             => 'readable',
				's'                => $search,
				'posts_per_page'   => self::PER_PAGE,
				'paged'            => $paged,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		remove_filter( 'posts_where', $filter );
		$pending = array_column( Controller::visible_changes(), 'doc' );
		$titles  = array(
			'page'     => __( 'Pagine', 'xb' ),
			'post'     => __( 'Articoli', 'xb' ),
			'wp_block' => __( 'Componenti', 'xb' ),
		);
		$base      = Menu::url( $slug, array_filter( array( 's' => $search, 'all' => $all ? '1' : '' ) ) );
		$subtitles = array(
			'page'     => __( 'Le pagine del sito: aprile nel builder, in Gutenberg o guardane le versioni.', 'xb' ),
			'post'     => __( 'Gli articoli costruiti con Histeon.', 'xb' ),
			'wp_block' => __( 'Blocchi riutilizzabili con props e slot, da inserire in qualsiasi pagina.', 'xb' ),
		);
		Menu::open(
			$slug,
			$titles[ $type ],
			$subtitles[ $type ],
			'page' === $type && current_user_can( $object->cap->create_posts )
				? static function (): void {
					printf( '<a class="xb-btn xb-btn--primary" href="%s">%s%s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . OverviewScreen::NEW_PAGE ), OverviewScreen::NEW_PAGE ) ), Menu::icon( 'plus-alt2' ), esc_html__( 'Nuova pagina', 'xb' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup is fixed.
				}
				: null
		);
		?>
		<div class="xb-panel">
			<div class="xb-toolbar">
				<div class="xb-segmented" role="tablist">
					<a role="tab" aria-selected="<?php echo $all ? 'false' : 'true'; ?>" class="<?php echo $all ? '' : 'is-active'; ?>" href="<?php echo esc_url( Menu::url( $slug, array_filter( array( 's' => $search ) ) ) ); ?>"><?php esc_html_e( 'Costruiti con Histeon', 'xb' ); ?></a>
					<a role="tab" aria-selected="<?php echo $all ? 'true' : 'false'; ?>" class="<?php echo $all ? 'is-active' : ''; ?>" href="<?php echo esc_url( Menu::url( $slug, array_filter( array( 's' => $search, 'all' => '1' ) ) ) ); ?>"><?php esc_html_e( 'Tutti', 'xb' ); ?></a>
				</div>
				<span class="xb-muted"><?php echo esc_html( sprintf( /* translators: %d: number of items */ _n( '%d elemento', '%d elementi', (int) $query->found_posts, 'xb' ), (int) $query->found_posts ) ); ?></span>
				<form method="get" class="xb-search" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">
					<?php if ( $all ) : ?>
						<input type="hidden" name="all" value="1">
					<?php endif; ?>
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Cerca per titolo…', 'xb' ); ?>" aria-label="<?php esc_attr_e( 'Cerca', 'xb' ); ?>">
					<button class="xb-btn"><?php echo Menu::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'Cerca', 'xb' ); ?></span></button>
				</form>
			</div>
			<?php
			$rows = array_values( array_filter( $query->posts, static fn ( $p ): bool => $p instanceof \WP_Post && current_user_can( 'edit_post', $p->ID ) ) );
			if ( array() === $rows ) :
				?>
				<div class="xb-empty">
					<?php echo Menu::icon( 'wp_block' === $type ? 'screenoptions' : 'admin-page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<strong><?php echo esc_html( '' !== $search ? __( 'Nessun risultato', 'xb' ) : __( 'Ancora niente qui', 'xb' ) ); ?></strong>
					<span><?php echo esc_html( $all ? __( 'Nessun contenuto di questo tipo.', 'xb' ) : __( 'Guarda "Tutti" per aprire nel builder un contenuto esistente.', 'xb' ) ); ?></span>
				</div>
			<?php else : ?>
				<table class="xb-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Titolo', 'xb' ); ?></th>
							<th class="xb-hide-small"><?php echo esc_html( 'wp_block' === $type ? __( 'Chiave', 'xb' ) : __( 'Stato', 'xb' ) ); ?></th>
							<th><?php esc_html_e( 'Histeon', 'xb' ); ?></th>
							<th class="xb-hide-small"><?php esc_html_e( 'Modificato', 'xb' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Azioni', 'xb' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $rows as $post ) :
						$doc   = Documents::for_post( $post->ID );
						$xb    = Spec::has_xb_blocks( $post->post_content );
						$link  = 'wp_block' === $type ? '' : (string) get_permalink( $post );
						$thumb = 'wp_block' === $type ? '' : (string) get_the_post_thumbnail_url( $post, 'thumbnail' );
						?>
						<tr>
							<td>
								<span class="xb-title">
									<span class="xb-thumb" <?php echo '' !== $thumb ? 'style="background-image:url(' . esc_url( $thumb ) . ')"' : ''; ?>><?php echo '' === $thumb ? Menu::icon( 'wp_block' === $type ? 'screenoptions' : 'admin-page' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span><?php echo esc_html( '' !== $post->post_title ? $post->post_title : __( '(senza titolo)', 'xb' ) ); ?>
										<?php if ( 'wp_block' !== $type ) : ?>
											<span class="xb-muted" style="display:block;font-weight:400;font-size:12px">/<?php echo esc_html( $post->post_name ); ?></span>
										<?php endif; ?>
									</span>
								</span>
							</td>
							<td class="xb-hide-small">
								<?php if ( 'wp_block' === $type ) : ?>
									<code><?php echo esc_html( (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true ) ); ?></code>
								<?php else : ?>
									<span class="xb-pill is-plain"><?php echo esc_html( (string) get_post_status_object( (string) $post->post_status )?->label ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( in_array( $doc, $pending, true ) ) : ?>
									<span class="xb-pill is-dev"><?php esc_html_e( 'da pubblicare', 'xb' ); ?></span>
								<?php elseif ( $xb ) : ?>
									<span class="xb-pill is-live"><?php esc_html_e( 'online', 'xb' ); ?></span>
								<?php else : ?>
									<span class="xb-pill"><?php esc_html_e( 'non Histeon', 'xb' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="xb-hide-small xb-muted"><?php echo esc_html( Menu::date( $post->post_modified_gmt ) ); ?></td>
							<td>
								<div class="xb-row-actions">
									<?php if ( BuilderScreen::can_open( $post->ID ) ) : ?>
										<a class="xb-btn xb-btn--small xb-btn--primary" href="<?php echo esc_url( BuilderScreen::url( $post->ID ) ); ?>"><?php echo esc_html( $xb ? __( 'Apri in Histeon', 'xb' ) : __( 'Converti in Histeon', 'xb' ) ); ?></a>
									<?php endif; ?>
									<a class="xb-btn xb-btn--small" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Gutenberg', 'xb' ); ?></a>
									<?php if ( Menu::can_see( $doc ) ) : ?>
										<a class="xb-btn xb-btn--small xb-btn--ghost" href="<?php echo esc_url( Menu::url( HistoryScreen::SLUG, array( 'doc' => $doc ) ) ); ?>" title="<?php esc_attr_e( 'Versioni', 'xb' ); ?>"><?php echo Menu::icon( 'backup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'Versioni', 'xb' ); ?></span></a>
									<?php endif; ?>
									<?php if ( '' !== $link && 'publish' === $post->post_status ) : ?>
										<a class="xb-btn xb-btn--small xb-btn--ghost" href="<?php echo esc_url( $link ); ?>" title="<?php esc_attr_e( 'Vedi sul sito', 'xb' ); ?>"><?php echo Menu::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="screen-reader-text"><?php esc_html_e( 'Vedi sul sito', 'xb' ); ?></span></a>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		if ( $query->max_num_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', $base ),
						'format'  => '',
						'current' => $paged,
						'total'   => (int) $query->max_num_pages,
					)
				)
			);
			echo '</div></div>';
		}
		Menu::close();
	}
}
