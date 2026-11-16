<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Admin;

use XB\Modules\FatalGuard;
use XB\Modules\Registry;

/**
 * Histeon → Panoramica, «Stato del sito» — docs/spec/overview.md. One card with what needs a look: the modules (and
 * one stopped by an error), Static Bridge's copy, imports still recommended, and whatever a module adds through
 * the `xb_site_status` filter (Lingua Bridge: translations to do). Rows from the filter are data: only known fields,
 * plain text, links into this site's admin.
 */
final class SiteStatus {

	/**
	 * @return list<array{label: string, value: string, state: string, url: string}> state: ok, warn or info.
	 */
	public static function rows(): array {
		$rows = array();
		foreach ( Registry::all() as $module ) {
			$rows[] = self::module_row( $module );
		}
		$static = \XB\StaticSite\StaticBridge::status();
		if ( null !== $static ) {
			$failed = 'failed' === $static['export'] || ( null !== $static['last'] && $static['last']['error'] );
			$value  = $static['serving']
				/* translators: 1: pages, 2: pages waiting */
				? sprintf( __( '%1$d pagine statiche · %2$d da rigenerare', 'xb' ), $static['pages'], $static['pending'] )
				: __( 'copia statica non attiva: i visitatori ricevono WordPress', 'xb' );
			if ( null !== $static['last'] && '' !== $static['last']['message'] ) {
				$value .= ' · ' . $static['last']['message'];
			}
			$rows[] = array(
				'label' => __( 'Copia statica', 'xb' ),
				'value' => $value,
				'state' => $failed ? 'warn' : ( $static['serving'] ? 'ok' : 'info' ),
				'url'   => $static['admin_url'],
			);
		}
		foreach ( LegacyReminder::pending() as $line ) {
			$rows[] = array(
				'label' => __( 'Importazione consigliata', 'xb' ),
				'value' => $line,
				'state' => 'warn',
				'url'   => SettingsScreen::url() . '#xb-etch',
			);
		}
		/**
		 * Rows a module adds to the site status: list of {label, value, state (ok|warn|info), url}.
		 *
		 * @param array<mixed> $rows
		 */
		$extra = apply_filters( 'xb_site_status', array() );
		foreach ( is_array( $extra ) ? array_slice( $extra, 0, 10 ) : array() as $row ) {
			$clean = self::clean( $row );
			if ( null !== $clean ) {
				$rows[] = $clean;
			}
		}
		return $rows;
	}

	/** @return array{label: string, value: string, state: string, url: string} */
	private static function module_row( \XB\Modules\Module $module ): array {
		$status = Registry::status( $module );
		$paused = FatalGuard::paused( $module->id );
		$words  = array(
			Registry::ON         => array( __( 'acceso', 'xb' ), 'ok' ),
			Registry::OFF        => array( __( 'spento', 'xb' ), 'info' ),
			Registry::STANDALONE => array( __( 'attivo come plugin separato', 'xb' ), 'ok' ),
			/* translators: %s: PHP version */
			Registry::PHP        => array( sprintf( __( 'serve PHP %s', 'xb' ), $module->php ), 'info' ),
			Registry::PLANNED    => array( __( 'non incluso in questa copia', 'xb' ), 'info' ),
			Registry::FORCED     => array( __( 'spento da wp-config.php', 'xb' ), 'warn' ),
			Registry::PAUSED     => array( __( 'fermato dopo un errore fatale', 'xb' ), 'warn' ),
		);
		[ $value, $state ] = $words[ $status ] ?? array( $status, 'info' );
		if ( null !== $paused ) {
			/* translators: 1: file, 2: line */
			$value = __( 'fermato dopo un errore fatale', 'xb' ) . ' · ' . sprintf( __( '%1$s, riga %2$d', 'xb' ), $paused['file'], $paused['line'] );
			$state = 'warn';
		}
		return array(
			'label' => $module->name,
			'value' => $value,
			'state' => $state,
			'url'   => Menu::url( ModulesScreen::SLUG ),
		);
	}

	/**
	 * A row from the filter, or null: known fields only, plain text, a link into this admin.
	 *
	 * @return array{label: string, value: string, state: string, url: string}|null
	 */
	public static function clean( mixed $row ): ?array {
		if ( ! is_array( $row ) || ! is_string( $row['label'] ?? null ) || ! is_string( $row['value'] ?? null ) ) {
			return null;
		}
		$label = mb_substr( trim( wp_strip_all_tags( $row['label'] ) ), 0, 60 );
		$value = mb_substr( trim( wp_strip_all_tags( $row['value'] ) ), 0, 200 );
		if ( '' === $label ) {
			return null;
		}
		$url = is_string( $row['url'] ?? null ) ? esc_url_raw( $row['url'] ) : '';
		return array(
			'label' => $label,
			'value' => $value,
			'state' => in_array( $row['state'] ?? '', array( 'ok', 'warn', 'info' ), true ) ? (string) $row['state'] : 'info',
			'url'   => str_starts_with( $url, admin_url() ) ? $url : '',
		);
	}

	public static function render(): void {
		$rows = self::rows();
		if ( array() === $rows ) {
			return;
		}
		$warn = count( array_filter( $rows, static fn ( array $r ): bool => 'warn' === $r['state'] ) );
		?>
		<h2 class="xb-section-title"><?php esc_html_e( 'Stato del sito', 'xb' ); ?> <small><?php echo esc_html( 0 === $warn ? __( 'tutto in ordine', 'xb' ) : sprintf( /* translators: %d: count */ _n( '%d cosa da guardare', '%d cose da guardare', $warn, 'xb' ), $warn ) ); ?></small></h2>
		<div class="xb-panel">
			<table class="xb-table xb-status">
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><span class="xb-dot is-<?php echo esc_attr( $row['state'] ); ?>" aria-hidden="true"></span><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
						<td class="xb-muted"><?php echo esc_html( $row['value'] ); ?></td>
						<td><?php if ( '' !== $row['url'] ) : ?><a class="xb-btn xb-btn--small xb-btn--ghost" href="<?php echo esc_url( $row['url'] ); ?>"><?php esc_html_e( 'Apri', 'xb' ); ?></a><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
