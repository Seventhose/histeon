<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Framework;

/**
 * XB's own CSS reset (assets/reset/xb-reset.css), off by default. Kept in the versioned `settings` document,
 * so turning it on goes live with a release like everything else. Imports from builders that ship a reset of
 * their own turn it on (ADR 0008, I6).
 */
final class Reset {

	public const OPTION = 'xb_reset';

	public static function enabled(): bool {
		/** On without the option too: Etch content rendered while Etch is off needs it, as the import turns it on. */
		return (bool) apply_filters( 'xb_reset_enabled', (bool) get_option( self::OPTION, false ) );
	}

	public static function url(): string {
		$file = dirname( XB_FILE ) . '/assets/reset/xb-reset.css';
		return plugin_dir_url( XB_FILE ) . 'assets/reset/xb-reset.css?ver=' . ( is_readable( $file ) ? (string) filemtime( $file ) : XB_VERSION );
	}

	/** Front end: first, so the theme, the framework and XB styles all come after it. */
	public static function enqueue(): void {
		if ( self::enabled() ) {
			wp_enqueue_style( 'xb-reset', self::url(), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version is in the URL.
		}
	}
}
