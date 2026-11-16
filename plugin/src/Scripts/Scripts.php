<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Scripts;

/**
 * JavaScript attached to a document (page, post or component) — the builder's JS panel.
 *
 * Threat model T1: JavaScript is the most powerful thing an author can store, so:
 * - it lives in protected post meta `_xb_script`, never in block attributes, so no content filter or
 *   block editor round trip can create or alter it;
 * - only users with `unfiltered_html` can write it (REST check, plus an auth callback on the meta itself
 *   for every other meta API);
 * - it never runs in the builder canvas (sandboxed iframe), only on the public page;
 * - it is printed once per request as `<script type="module">`, after the content.
 */
final class Scripts {

	public const META      = '_xb_script';
	public const MAX_BYTES = 100000;

	/** @var array<int, true> */
	private array $used = array();

	public static function register_meta(): void {
		foreach ( array( 'page', 'post', 'wp_block' ) as $type ) {
			register_post_meta(
				$type,
				self::META,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn (): bool => current_user_can( 'unfiltered_html' ),
				)
			);
		}
	}

	public static function get( int $post_id ): string {
		$code = get_post_meta( $post_id, self::META, true );
		return is_string( $code ) ? $code : '';
	}

	/**
	 * Stores (or removes, with '') the script of a document. The caller must have checked unfiltered_html;
	 * this method checks again because it is the last line of defence.
	 */
	public static function set( int $post_id, string $code ): bool {
		if ( ! current_user_can( 'unfiltered_html' ) || ! current_user_can( 'edit_post', $post_id ) || strlen( $code ) > self::MAX_BYTES ) {
			return false;
		}
		if ( '' === trim( $code ) ) {
			delete_post_meta( $post_id, self::META );
			return true;
		}
		// update_post_meta() unslashes: slash first so backslashes in the code survive.
		update_post_meta( $post_id, self::META, wp_slash( $code ) );
		return self::get( $post_id ) === $code;
	}

	/** Marks a document (page or component definition) as rendered on this request. */
	public function use( int $post_id ): void {
		$this->used[ $post_id ] = true;
	}

	/** Prints the scripts of the queried document and of every component rendered. */
	public function print_footer(): void {
		$queried = is_singular() ? get_queried_object_id() : 0;
		if ( $queried > 0 ) {
			$this->used = array( $queried => true ) + $this->used;
		}
		foreach ( array_keys( $this->used ) as $post_id ) {
			$code = (string) apply_filters( 'xb_document_script', self::get( $post_id ), $post_id );
			if ( '' === $code ) {
				continue;
			}
			// The script was written by a trusted author; this only keeps the markup well formed.
			// Every letter in its two cases: the `i` flag of PCRE follows the PHP locale (T31, P1).
			$safe = (string) preg_replace( '#</([sS][cC][rR][iI][pP][tT])#', '<\/$1', $code );
			printf( "<script type=\"module\" data-xb-script=\"%d\">\n%s\n</script>\n", (int) $post_id, $safe ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted author code, see class doc.
		}
		$this->used = array();
	}
}
