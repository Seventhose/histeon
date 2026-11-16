<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Forms;

use XB\Deploy\CloudflareClient;
use XB\Deploy\Credentials;

/**
 * Form submissions from the static site — threat model T10.
 *
 * The Worker verifies Turnstile and forwards the fields signed with HMAC-SHA256 (shared secret, generated here,
 * sent to Cloudflare as a Worker secret). This endpoint accepts only a valid signature, a timestamp within five
 * minutes and a submission id never seen before (replay protection), then stores the fields as plain text in a
 * private post type and notifies the site administrator.
 */
final class Forms {

	public const POST_TYPE   = 'xb_submission';
	public const SECRET      = 'xb_forms_secret';
	public const SEEN_PREFIX = 'xb_form_seen_';
	public const RATE_PREFIX = 'xb_forms_rate_';
	/** Post meta: sha256 of each email address found in a submission (exact lookup for the privacy tools). */
	public const EMAIL_META = '_xb_email_hash';
	private const MAX_BODY   = 65536;
	private const WINDOW     = 300;
	/** Accepted submissions per minute, site-wide: a stolen key cannot flood the inbox. */
	private const PER_MINUTE = 30;
	/** Submissions are personal data: deleted after this many days (filter xb_form_retention_days). */
	private const RETENTION_DAYS = 180;

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Messaggi dai form', 'xb' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor' ),
				// Personal data: administrators only, and nobody creates submissions by hand.
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_posts'           => 'manage_options',
					'edit_others_posts'    => 'manage_options',
					'edit_private_posts'   => 'manage_options',
					'edit_published_posts' => 'manage_options',
					'read_private_posts'   => 'manage_options',
					'delete_posts'         => 'manage_options',
					'delete_private_posts' => 'manage_options',
					'delete_others_posts'  => 'manage_options',
					'publish_posts'        => 'do_not_allow',
					'create_posts'         => 'do_not_allow',
				),
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-email-alt',
			)
		);
	}

	public static function routes(): void {
		register_rest_route(
			'xb/v1',
			'/forms',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive' ),
				// Authentication is the HMAC signature, checked in receive(): there is no user session.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Signing key shared with the Worker: derived from a random value in the database *and* the AUTH salt
	 * in wp-config.php, so a database leak alone is not enough to forge submissions.
	 */
	public static function secret(): string {
		$seed = get_option( self::SECRET );
		if ( ! is_string( $seed ) || strlen( $seed ) < 64 ) {
			$seed = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET, $seed, false );
		}
		return hash_hmac( 'sha256', 'xb-forms|' . $seed, wp_salt( 'auth' ) );
	}

	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'xb_forms_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'xb_forms_cleanup' );
		}
	}

	/** Forgets replay markers past the time window and deletes submissions past the retention period. */
	public static function cleanup(): void {
		global $wpdb;
		$cutoff = time() - 2 * self::WINDOW;
		// 5000 at a time, a few times over: a day of heavy traffic leaves more than one batch, and never an endless run.
		for ( $round = 0; $round < 10; $round++ ) {
			$seen  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 5000", $wpdb->esc_like( self::SEEN_PREFIX ) . '%', $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rates = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s LIMIT 5000", $wpdb->esc_like( self::RATE_PREFIX ) . '%', self::RATE_PREFIX . gmdate( 'YmdHi', time() - 2 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( array_merge( $seen, $rates ) as $name ) {
				delete_option( (string) $name ); // Through the API, so the options cache stays consistent.
			}
			if ( count( $seen ) < 5000 && count( $rates ) < 5000 ) {
				break;
			}
		}
		$days = (int) apply_filters( 'xb_form_retention_days', self::RETENTION_DAYS );
		$old  = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'numberposts' => 500,
				'fields'      => 'ids',
				'date_query'  => array( array( 'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ),
			)
		);
		foreach ( $old as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/** Sends the signing secret and the Turnstile secret to the Worker. */
	public static function configure_worker( string $turnstile_secret ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_.-]{10,200}$/', $turnstile_secret ) ) {
			throw new \InvalidArgumentException( __( 'Secret Turnstile non valido.', 'xb' ) );
		}
		$client = CloudflareClient::from_settings();
		$client->put_secret( 'XB_FORMS_SECRET', self::secret() );
		$client->put_secret( 'TURNSTILE_SECRET', $turnstile_secret );
		update_option( 'xb_forms_configured', gmdate( 'c' ), false );
	}

	/**
	 * Atomic claim of a name: INSERT IGNORE succeeds for exactly one of any number of concurrent requests.
	 * (add_option() is not enough: it reads first and then writes with ON DUPLICATE KEY UPDATE.)
	 */
	private static function claim( string $name ): bool {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->rows_affected;
	}

	/** Atomic per-minute counter (site-wide); returns the count including this request. */
	private static function count_this_minute(): int {
		global $wpdb;
		$name = self::RATE_PREFIX . gmdate( 'YmdHi' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off') ON DUPLICATE KEY UPDATE option_value = option_value + 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function receive( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new \WP_Error( 'xb_form_too_large', 'Payload Too Large', array( 'status' => 413 ) );
		}
		$signature = (string) $request->get_header( 'x_xb_signature' );
		if ( ! hash_equals( hash_hmac( 'sha256', $body, self::secret() ), $signature ) ) {
			return new \WP_Error( 'xb_form_signature', 'Forbidden', array( 'status' => 403 ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! is_int( $data['ts'] ?? null ) || ! is_string( $data['id'] ?? null ) || ! is_array( $data['fields'] ?? null ) ) {
			return new \WP_Error( 'xb_form_invalid', 'Bad Request', array( 'status' => 400 ) );
		}
		if ( abs( time() - $data['ts'] ) > self::WINDOW ) {
			return new \WP_Error( 'xb_form_expired', 'Forbidden', array( 'status' => 403 ) );
		}
		if ( ! self::claim( self::SEEN_PREFIX . substr( hash( 'sha256', $data['id'] ), 0, 40 ) ) ) {
			return new \WP_Error( 'xb_form_replay', 'Forbidden', array( 'status' => 403 ) );
		}
		if ( self::count_this_minute() > self::PER_MINUTE ) {
			return new \WP_Error( 'xb_form_rate', 'Too Many Requests', array( 'status' => 429 ) );
		}

		$form   = sanitize_key( (string) ( $data['form'] ?? 'contatti' ) );
		$fields = array();
		foreach ( array_slice( $data['fields'], 0, 50, true ) as $name => $value ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $name ) && is_string( $value ) ) {
				$fields[ $name ] = sanitize_textarea_field( mb_substr( $value, 0, 5000 ) );
			}
		}
		$text = '';
		foreach ( $fields as $name => $value ) {
			$text .= $name . ': ' . $value . "\n";
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => sprintf( '%s — %s', '' === $form ? 'form' : $form, wp_date( 'Y-m-d H:i' ) ),
				'post_content' => wp_slash( esc_html( $text ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'xb_form_store', 'Server Error', array( 'status' => 500 ) );
		}
		foreach ( array_unique( array_filter( array_map( static fn ( string $v ): string => strtolower( trim( $v ) ), $fields ), static fn ( string $v ): bool => false !== is_email( $v ) ) ) as $email ) {
			add_post_meta( $id, self::EMAIL_META, hash( 'sha256', $email ) );
		}
		wp_mail( (string) get_option( 'admin_email' ), sprintf( '[%s] %s', wp_specialchars_decode( (string) get_bloginfo( 'name' ) ), sprintf( __( 'Nuovo messaggio dal form %s', 'xb' ), $form ) ), $text );
		return new \WP_REST_Response( array( 'stored' => true ), 201 );
	}

	/**
	 * Privacy tools (Tools → Export/Erase personal data): submissions that contain the email address.
	 *
	 * @param array<string, mixed> $exporters
	 * @return array<string, mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['xb-forms'] = array(
			'exporter_friendly_name' => __( 'Messaggi dai form Histeon', 'xb' ),
			'callback'               => array( self::class, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers
	 * @return array<string, mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['xb-forms'] = array(
			'eraser_friendly_name' => __( 'Messaggi dai form Histeon', 'xb' ),
			'callback'             => array( self::class, 'erase_personal_data' ),
		);
		return $erasers;
	}

	private const PRIVACY_PAGE = 100;

	/**
	 * Submissions holding exactly this email address (hash lookup, never a substring search: smith@ must not
	 * match jsmith@).
	 *
	 * @return list<int>
	 */
	private static function submissions_for( string $email, int $page ): array {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return array();
		}
		return array_values(
			array_map(
				'intval',
				get_posts(
					array(
						'post_type'   => self::POST_TYPE,
						'post_status' => 'private',
						'numberposts' => self::PRIVACY_PAGE,
						'paged'       => max( 1, $page ),
						'orderby'     => 'ID',
						'order'       => 'ASC',
						'fields'      => 'ids',
						'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							array(
								'key'   => self::EMAIL_META,
								'value' => hash( 'sha256', $email ),
							),
						),
					)
				)
			)
		);
	}

	/** @return array{data: list<array<string, mixed>>, done: bool} */
	public static function export_personal_data( string $email, int $page = 1 ): array {
		$ids  = self::submissions_for( $email, $page );
		$data = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$data[] = array(
				'group_id'    => 'xb-forms',
				'group_label' => __( 'Messaggi dai form', 'xb' ),
				'item_id'     => 'xb-submission-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Messaggio', 'xb' ),
						'value' => $post->post_content,
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PRIVACY_PAGE,
		);
	}

	/**
	 * Always reads the first page: what was deleted in the previous call is gone, so the next batch moves up.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array {
		unset( $page );
		$ids     = self::submissions_for( $email, 1 );
		$removed = false;
		$kept    = false;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				$removed = true;
			} else {
				$kept = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $kept,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PRIVACY_PAGE || $kept,
		);
	}

	/** @return bool Whether Cloudflare credentials exist (forms need the Worker). */
	public static function available(): bool {
		return Credentials::ready();
	}
}
