<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

use XB\Versioning\Documents;
use XB\Content\ContentTypes;
use XB\Render\ComponentRegistry;

/**
 * WordPress adapter for the static page transfer profile.
 *
 * @phpstan-type ParsedBlock array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}
 */
final class PageProfileAdapter {


	/** @var list<callable>|null Writes held back while export() runs. */
	private static ?array $later = null;

	/** A check reading (`check`): a content the project knows that cannot travel is named too, not thrown. */
	private static bool $checking = false;
	private const PROJECT_ID_OPTION = 'xb_page_profile_project_id';
	private const PROJECT_NAME_OPTION = 'xb_page_profile_project_name';
	private const PAGE_ID_META = '_xb_page_profile_id';
	/** Set only when an import from Histeon CMS writes the post (is_transfer_imported()). */
	public const IMPORTED_META = '_xb_transfer_imported';
	private const IMPORTED_MIGRATION_OPTION = 'xb_transfer_imported_markers';
	private const IMPORTED_MIGRATION_CURSOR = 'xb_transfer_imported_markers_cursor';
	/** The value IMPORTED_MIGRATION_OPTION holds once migrate_imported_markers() has run to the end. */
	private const IMPORTED_MIGRATION_VERSION = '1';
	private const TEMPLATE_REF_META = '_xb_page_profile_ref';
	private const TEMPLATE_ASSIGNMENT_META = '_xb_page_profile_template_ref';
	private const TEMPLATE_SCHEMA_META = '_xb_page_profile_template_schema';
	private const MANAGED_TEMPLATE_META = '_xb_histos_managed_template';
	private const STATUS_MODE_META = '_xb_page_profile_status_mode';
	private const MEDIA_ID_META = '_xb_page_profile_id';
	private const MEDIA_HASH_META = '_xb_page_profile_sha256';
	private const BASE_HASH_META = '_xb_page_profile_base_hash';
	private const TOMBSTONE_META = '_xb_page_profile_tombstone';
	private const PENDING_FIELDS_META = '_xb_page_profile_pending_fields';
	private const PENDING_MEDIA_META = '_xb_page_profile_pending_media';
	private const PENDING_FILE_META = '_xb_page_profile_pending_file';
	private const WRITTEN_MEDIA_OPTION = 'xb_page_profile_written_media';
	/** Days a file written by an interrupted send waits for the send to be taken up again. */
	private const WRITTEN_MEDIA_DAYS = 7;
	/** The send being applied now: its own waiting media count already, before it is complete. */
	private static ?string $running_operation = null;
	private const MEDIA_ALIAS_META = '_xb_page_profile_media_alias';
	private const CREATING_META = '_xb_page_profile_creating';
	private const TOMBSTONES_OPTION = 'xb_page_profile_tombstones';
	private const STYLES_BASE_OPTION = 'xb_page_profile_styles_base_hash';
	/** Seconds without progress after which a send counts as interrupted: each entity reports, so a host that killed it shows soon. */
	private const OPERATION_LOCK_TTL = 300;
	private const ALLOWED_MEDIA = array( 'image/avif', 'image/gif', 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' );
	private const ALLOWED_CSS_MEDIA = array( 'image/avif', 'image/gif', 'image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'font/otf', 'font/ttf', 'font/woff', 'font/woff2' );
	/** WordPress post types of profile documents: pages and posts (docs/spec/cms-bridge.md §12). */
	private const DOCUMENT_TYPES = array( 'page', 'post' );
	/** @var list<string> Entities included in the current, already-preflighted delete batch. */
	private static array $planned_deletion_ids = array();

	private static function profile_entity_kind_for_post( \WP_Post $post ): ?string {
		if ( in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return 'document';
		}
		if ( isset( ContentTypes::live()['types'][ $post->post_type ] ) ) {
			return 'content-record';
		}
		if ( 'attachment' === $post->post_type ) {
			return 'asset';
		}
		if ( 'wp_block' === $post->post_type && 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true ) ) ) {
			return 'component';
		}
		if ( self::is_profile_template( $post ) ) {
			return 'wp_template' === $post->post_type ? 'template' : ( 'wp_template_part' === $post->post_type ? 'template-part' : null );
		}
		return null;
	}

	/** @return 'published'|'draft'|null Null means an older profile omitted this field and its default is unchanged. */
	private static function transfer_status( \WP_Post $post ): ?string {
		$mode = (string) get_post_meta( $post->ID, self::STATUS_MODE_META, true );
		if ( str_starts_with( $mode, 'implicit:' ) && substr( $mode, strlen( 'implicit:' ) ) === $post->post_status ) {
			return null;
		}
		return 'publish' === $post->post_status ? 'published' : 'draft';
	}

	/** @param array<string, mixed> $payload */
	private static function save_transfer_status_mode( int $post_id, array $payload, string $status ): void {
		$mode = array_key_exists( 'status', $payload ) ? 'explicit' : 'implicit:' . $status;
		update_post_meta( $post_id, self::STATUS_MODE_META, $mode );
	}

	/**
	 * Resolve a supported profile tombstone to its linked WordPress post.
	 *
	 * @param array<string, mixed> $previous_payload
	 */
	private static function post_for_profile_tombstone( string $entity_kind, string $id, array $previous_payload ): ?\WP_Post {
		if ( 'content-record' === $entity_kind ) {
			$type = $previous_payload['typeKey'] ?? null;
			return is_string( $type ) ? ContentProfileAdapter::linked_record_by_id( $id, $type ) : null;
		}
		$type = match ( $entity_kind ) {
			'document' => self::DOCUMENT_TYPES,
			'component' => 'wp_block',
			'template-part' => 'wp_template_part',
			'template' => 'wp_template',
			'asset' => 'attachment',
			default => null,
		};
		return null !== $type ? self::post_by_profile_id( $id, $type ) : null;
	}

	/**
	 * @param array{secret: string, user: int, publish: bool, read: bool, write: bool, label: string, created: int, last: int} $link
	 * @return array<string, mixed>
	 */
	public static function capabilities( array $link ): array {
		$project_id = (string) get_option( self::PROJECT_ID_OPTION, '' );
		$can_initialize = self::can_initialize_project();
		return array(
			'contractVersion' => 2,
			'profile'         => PageProfile::PROFILE,
			'siteId'          => self::site_id(),
			'projectId'       => ! $can_initialize && self::valid_uuid( $project_id ) ? $project_id : null,
			'canInitializeProject' => $can_initialize,
			'projectName'     => (string) get_option( self::PROJECT_NAME_OPTION, get_bloginfo( 'name' ) ),
			'capabilities'    => array(
				'read'      => (bool) $link['read'],
				'write'     => (bool) $link['write'],
				'publish'   => (bool) $link['publish'] && current_user_can( 'xb_publish' ),
				'profiles'  => array( PageProfile::PROFILE, PageProfile::PROJECT_PROFILE, PageProfile::TEMPLATE_PROJECT_PROFILE, PageProfile::RELATIONAL_PROJECT_PROFILE, PageProfile::POSTS_PROJECT_PROFILE ),
				'selection' => array( 'workspace-head' ),
				// A send without publishing never changes media online: changes wait for the next release (§5).
				'stagedMedia' => true,
				// The markup the site reads and writes beyond the base rules: SVG, picture/srcset, details, textarea
				// text (docs/spec/transfer-markup.md). Declared with srcset export and restore, which ship with it.
				// The readings export() answers; `check` since 0.5.7 (doc 23 of Publish, item 197).
				'readings'    => array( 'adopt', 'read', 'linked', 'check' ),
				'markup'      => array( TransferMarkup::ID, TransferMarkup::MEDIA_LINKS_ID, TransferMarkup::URL_PATH_ID, TransferMarkup::LOOP_GROUP_ID, TransferMarkup::SVG_TEXT_ID ),
				// Products travel only to a site with WooCommerce active (docs/spec/ecommerce.md, queue C6).
				'commerce'    => ProductProfile::available() ? array( ProductProfile::CAPABILITY ) : array(),
				// Documents' JavaScript travels only to a link whose user may store it (unfiltered_html, threat model T1).
				'scripts'     => current_user_can( 'unfiltered_html' ) ? array( PageProfile::SCRIPT_CAPABILITY ) : array(),
				// The home and posts page of the site, read and set by Publish with the user's yes (Bridge::FRONT_PAGE).
				'siteSettings' => array( \XB\Cms\FrontPage::CAPABILITY ),
				// V1.6: signed preview links of the changed pages for Publish's checks (Bridge::PREVIEW_LINKS, T32).
				'previewLinks' => (bool) $link['read'],
			),
			'limits'          => array( 'archiveBytes' => PageProfile::MAX_ARCHIVE_BYTES, 'entries' => PageProfile::MAX_ENTRIES ),
			// Doc 23 item 125: Publish says before a send that the site's PHP is too old for current Histeon. Only to
			// an authenticated link, like the rest of this answer.
			'environment'     => array(
				'php'     => PHP_VERSION,
				'histeon' => defined( 'XB_VERSION' ) ? XB_VERSION : '',
			),
		);
	}

	/**
	 * Whether an import from Histeon CMS wrote this post (a page, component, template part or template): the marker
	 * IMPORTED_META, set only on the import's write path. A profile identity is not enough: a download gives one to
	 * every native document it carries, and a download changes nothing on the site. Rendering only reads the marker:
	 * until migrate_imported_markers() has marked older imports, those render as native content, which is the
	 * behaviour they had before the marker and safe, since their values passed the import's checks.
	 */
	public static function is_transfer_imported( int $post_id ): bool {
		return $post_id > 0 && '' !== (string) get_post_meta( $post_id, self::IMPORTED_META, true );
	}

	/**
	 * Marks the posts written by imports made before the marker existed, from the import's own operation records
	 * (`xb_remote_operation_*`: the entities each import changed, the posts its report names as created or updated).
	 * Downloads leave no record. Runs on `admin_init` (the next admin visit after the update), within a time budget
	 * per request, never in a render: records in batches by option id, ids matched with one `IN (…)` query per batch on the profile identity
	 * of document post types only, progress saved after every batch so an interrupted run resumes. Returns true once
	 * the migration is done (IMPORTED_MIGRATION_OPTION holds IMPORTED_MIGRATION_VERSION).
	 */
	public static function migrate_imported_markers( float $budget_seconds = 5.0, int $batch_size = 50 ): bool {
		if ( self::IMPORTED_MIGRATION_VERSION === get_option( self::IMPORTED_MIGRATION_OPTION ) ) {
			return true;
		}
		global $wpdb;
		$started = microtime( true );
		$cursor  = (int) get_option( self::IMPORTED_MIGRATION_CURSOR, 0 );
		$types   = array_merge( self::DOCUMENT_TYPES, array( 'wp_block', 'wp_template', 'wp_template_part' ) );
		$type_in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-time migration over the operation records, in batches.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT %d", $wpdb->esc_like( 'xb_remote_operation_' ) . '%', $cursor, max( 1, $batch_size ) ), ARRAY_A );
			if ( ! is_array( $rows ) || array() === $rows ) {
				update_option( self::IMPORTED_MIGRATION_OPTION, self::IMPORTED_MIGRATION_VERSION, true );
				delete_option( self::IMPORTED_MIGRATION_CURSOR );
				return true;
			}
			$entity_ids = array();
			$post_ids   = array();
			foreach ( $rows as $row ) {
				$cursor = max( $cursor, (int) $row['option_id'] );
				if ( str_starts_with( (string) $row['option_name'], 'xb_remote_operation_lock_' ) ) {
					continue;
				}
				$status = maybe_unserialize( (string) $row['option_value'] );
				if ( ! is_array( $status ) || ! in_array( $status['action'] ?? null, array( 'import', 'publish' ), true ) ) {
					continue;
				}
				foreach ( is_array( $status['changed'] ?? null ) ? $status['changed'] : array() as $entity_id ) {
					if ( is_string( $entity_id ) && self::valid_uuid( $entity_id ) ) {
						$entity_ids[ $entity_id ] = true;
					}
				}
				$report = is_array( $status['report'] ?? null ) ? $status['report'] : array();
				foreach ( array( 'pages', 'components', 'templateParts', 'templates' ) as $group ) {
					foreach ( is_array( $report[ $group ] ?? null ) ? $report[ $group ] : array() as $entry ) {
						if ( is_array( $entry ) && in_array( $entry['action'] ?? null, array( 'created', 'updated' ), true ) && is_int( $entry['postId'] ?? null ) && $entry['postId'] > 0 ) {
							$post_ids[ $entry['postId'] ] = true;
						}
					}
				}
				unset( $status, $report );
			}
			$marked = array();
			foreach ( array_chunk( array_keys( $entity_ids ), 500 ) as $chunk ) {
				$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders -- placeholders built above.
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND m.meta_value IN ($in) AND p.post_type IN ($type_in)", array_merge( array( self::PAGE_ID_META ), $chunk, $types ) ) );
				foreach ( is_array( $found ) ? $found : array() as $id ) {
					$marked[ (int) $id ] = true;
				}
			}
			foreach ( array_chunk( array_keys( $post_ids ), 500 ) as $chunk ) {
				$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders -- placeholders built above.
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($in) AND post_type IN ($type_in)", array_merge( $chunk, $types ) ) );
				foreach ( is_array( $found ) ? $found : array() as $id ) {
					$marked[ (int) $id ] = true;
				}
			}
			foreach ( array_keys( $marked ) as $id ) {
				update_post_meta( $id, self::IMPORTED_META, '1' );
			}
			unset( $rows, $entity_ids, $post_ids, $marked );
			update_option( self::IMPORTED_MIGRATION_CURSOR, $cursor, false );
		} while ( microtime( true ) - $started < $budget_seconds );
		return false;
	}

	/**
	 * A first push may initialize an unpaired WordPress site only when no transferable Histeon data would be replaced.
	 * Ordinary WordPress pages and posts without Histeon blocks remain outside the CMS project.
	 */
	public static function can_initialize_project(): bool {
		return array() === self::initialization_blockers();
	}

	/**
	 * What stops a first push, in words for the administrator; empty when the site can receive its first project.
	 * The framework saved by «Primo avvio» is not on the list: it is a choice, not data, and the project brings its own.
	 *
	 * @return list<string>
	 */
	public static function initialization_blockers(): array {
		$found       = array();
		$post_types  = array_values( get_post_types() );
		$post_status = array( 'draft', 'publish', 'private', 'pending', 'future', 'inherit' );
		$managed_meta = array(
			self::PAGE_ID_META             => __( 'pagine o articoli già collegati a un progetto Histeon', 'xb' ),
			self::TEMPLATE_REF_META        => __( 'template di Histeon', 'xb' ),
			self::TEMPLATE_ASSIGNMENT_META => __( 'template di Histeon', 'xb' ),
			self::MANAGED_TEMPLATE_META    => __( 'template di Histeon', 'xb' ),
			ComponentRegistry::META_KEY    => __( 'componenti di Histeon', 'xb' ),
		);
		foreach ( $managed_meta as $meta_key => $label ) {
			$matches = get_posts( array( 'post_type' => $post_types, 'post_status' => $post_status, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => $meta_key, 'meta_compare' => 'EXISTS', 'suppress_filters' => true ) );
			if ( array() !== $matches ) {
				$found[] = $label;
			}
		}
		$histos_blocks = get_posts( array( 'post_type' => $post_types, 'post_status' => $post_status, 'posts_per_page' => 1, 'fields' => 'ids', 's' => 'wp:xb/', 'suppress_filters' => true ) );
		if ( array() !== $histos_blocks ) {
			$found[] = __( 'contenuti costruiti con i blocchi Histeon', 'xb' );
		}
		$term_meta = get_terms( array( 'taxonomy' => array_values( get_taxonomies() ), 'hide_empty' => false, 'number' => 1, 'fields' => 'ids', 'meta_key' => self::PAGE_ID_META ) );
		if ( is_wp_error( $term_meta ) || array() !== $term_meta ) {
			$found[] = __( 'termini già collegati a un progetto Histeon', 'xb' );
		}

		// The project locale is imported as part of the CMS profile; a default locale alone does not mean existing content would be replaced.
		$leftover = __( 'i resti di un invio precedente da Histeon Publish', 'xb' );
		$options  = array(
			\XB\Styles\StyleRegistry::OPTION_STYLES          => __( 'stili salvati nel pannello CSS', 'xb' ),
			\XB\Styles\StyleRegistry::OPTION_GLOBAL          => __( 'stili salvati nel pannello CSS', 'xb' ),
			\XB\Render\LoopPresets::OPTION                   => __( 'query e sorgenti di dati', 'xb' ),
			\XB\Render\LoopPresets::TYPES                    => __( 'query e sorgenti di dati', 'xb' ),
			\XB\Render\LoopPresets::TAXONOMIES               => __( 'query e sorgenti di dati', 'xb' ),
			\XB\Render\Sources::OPTION                       => __( 'query e sorgenti di dati', 'xb' ),
			\XB\Fields\Fields::OPTION                        => __( 'campi personalizzati', 'xb' ),
			\XB\Render\Facets::OPTION                        => __( 'filtri', 'xb' ),
			\XB\Framework\TokenOverrides::OPTION             => __( 'impostazioni di Histeon CSS (colori, caratteri, misure)', 'xb' ),
			\XB\Framework\HistosCss\Design::OPTION           => __( 'impostazioni di Histeon CSS (colori, caratteri, misure)', 'xb' ),
			'xb_page_profile_content_type_ids'               => $leftover,
			'xb_page_profile_content_type_templates'         => $leftover,
			'xb_page_profile_taxonomy_ids'                   => $leftover,
			'xb_page_profile_query_ids'                      => $leftover,
			'xb_page_profile_source_ids'                     => $leftover,
			'xb_page_profile_relational_bases'               => $leftover,
			'xb_page_profile_styles_base_hash'               => $leftover,
			self::TOMBSTONES_OPTION                          => $leftover,
		);
		foreach ( $options as $option => $label ) {
			if ( ! self::empty_option( get_option( $option, array() ) ) ) {
				$found[] = $label;
			}
		}
		if ( (bool) get_option( \XB\Framework\Reset::OPTION, false ) ) {
			$found[] = __( 'il reset CSS di Histeon', 'xb' );
		}
		$queries = get_option( \XB\Styles\NamedQueries::OPTION, null );
		if ( null !== $queries && ! self::same_map( \XB\Styles\NamedQueries::DEFAULTS, $queries ) ) {
			$found[] = __( 'media query con nome', 'xb' );
		}
		$content = get_option( \XB\Content\ContentTypes::OPTION, null );
		if ( null !== $content ) {
			$custom = ! is_array( $content ) || array() !== array_diff( array_keys( $content ), array( 'types', 'taxonomies', 'fields' ) );
			if ( ! $custom ) {
				try {
					$custom = \XB\Content\ContentTypes::empty() !== \XB\Content\ContentTypes::validate( $content, false );
				} catch ( \InvalidArgumentException ) {
					$custom = true;
				}
			}
			if ( $custom ) {
				$found[] = __( 'tipi di contenuto o tassonomie di Histeon', 'xb' );
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * The same keys and values: a settings document stores its maps sorted.
	 *
	 * @param array<string, string> $first
	 */
	private static function same_map( array $first, mixed $second ): bool {
		if ( ! is_array( $second ) ) {
			return false;
		}
		ksort( $first );
		ksort( $second );
		return $first === $second;
	}

	private static function empty_option( mixed $value ): bool {
		return null === $value || false === $value || '' === $value || array() === $value;
	}

	/**
	 * A consistent snapshot of pages, global styles and referenced image assets. A reading writes nothing while it
	 * runs: identities wait in later() until the archive is whole. A download (`adopt`) then stores them; a check before
	 * a download (`read`) drops them, and identities are derived (derived_id()), so both show the same ones. A send and
	 * its rollback read only what the project owns (`linked`): the site's other content is none of their business.
	 * A check before a send (`check`, doc 23 of Publish item 197) is a `read` that names every content which cannot
	 * travel, the project's own as well (`blocks`: a send or a download would stop there), instead of stopping at the
	 * first: its archive is for reading, never for importing.
	 */
	public static function export( string $reading = 'adopt' ): string {
		if ( 'linked' === $reading ) {
			$snapshot = self::snapshot( false );
			return PageProfile::archive( $snapshot['manifest'], $snapshot['entities'], $snapshot['references'], $snapshot['files'] );
		}
		self::$later    = array();
		self::$checking = 'check' === $reading;
		try {
			$snapshot = self::snapshot();
			$archive  = PageProfile::archive( $snapshot['manifest'], $snapshot['entities'], $snapshot['references'], $snapshot['files'] );
			$writes   = self::held();
		} finally {
			self::$later    = null;
			self::$checking = false;
		}
		if ( 'adopt' === $reading ) {
			foreach ( $writes as $write ) {
				$write();
			}
			self::initialize_export_bases( $snapshot['entities'] );
		}
		return $archive;
	}

	/**
	 * Whether a development commit is a send of Histeon CMS: its last author is the project, not someone on the site.
	 * Commits saved before the rename (0.3.12) carry the old name, in Italian or in the site's language.
	 */
	public static function is_send( string $message ): bool {
		return in_array( $message, array( 'Invio da Histeon CMS', __( 'Invio da Histeon CMS', 'xb' ), 'Invio da Histos CMS', 'Sent from Histos CMS' ), true );
	}

	/** @return list<callable> */
	private static function held(): array {
		return self::$later ?? array();
	}

	/** A write of the reading: held while export() runs, at once anywhere else. */
	public static function later( callable $write ): void {
		if ( null === self::$later ) {
			$write();
			return;
		}
		self::$later[] = $write;
	}

	/** The identity `$scope` gets on this site: the same at every reading, so it can wait to be stored. */
	public static function derived_id( string $scope ): string {
		$hash = hash( 'sha256', 'xb-profile|' . (string) get_option( 'siteurl' ) . '|' . $scope );
		return sprintf( '%s-%s-4%s-%x%s-%s', substr( $hash, 0, 8 ), substr( $hash, 8, 4 ), substr( $hash, 13, 3 ), 8 + hexdec( $hash[16] ) % 4, substr( $hash, 17, 3 ), substr( $hash, 20, 12 ) );
	}

	/**
	 * A content of the site that cannot travel stays out of the reading, named in the manifest: one dynamic srcset must
	 * not stop every send to a site with content of its own. One the project knows (it has a base) still stops it: left
	 * out, it would read as deleted.
	 *
	 * @param array<int|string, array<string, string|bool>> $left_out
	 */
	private static function leave_out( \WP_Post $post, \DomainException $why, array &$left_out ): void {
		$title = '' !== $post->post_title ? $post->post_title : $post->post_name;
		$known = 1 === preg_match( '/^[0-9a-f]{64}$/', (string) get_post_meta( $post->ID, self::BASE_HASH_META, true ) );
		if ( $known && ! self::$checking ) {
			$named = $title . ': ' . $why->getMessage();
			throw $why instanceof TransferMarkupViolation ? new TransferMarkupViolation( $why->reason, $named, $why ) : new \DomainException( $named, 0, $why );
		}
		$left_out[ $post->ID ] = array(
			'kind'   => match ( $post->post_type ) {
				'page'             => 'page',
				'post'             => 'post',
				'wp_block'         => 'component',
				'wp_template_part' => 'template-part',
				default            => 'template',
			},
			'title'  => mb_substr( $title, 0, 160 ),
			'reason' => mb_substr( $why->getMessage(), 0, 300 ),
		) + ( $known ? array( 'blocks' => true ) : array() );
	}

	/** The post a transferred content became here (documents and records share the meta), or 0. */
	public static function post_of_entity( mixed $id ): int {
		if ( ! is_string( $id ) || ! self::valid_uuid( $id ) ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'meta_key'         => self::PAGE_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'numberposts'      => 2,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		// Two posts with one identity (a copy made by a duplicator plugin): neither is surely the one, as
		// post_by_profile_id refuses them.
		return 1 === count( $found ) ? (int) $found[0] : 0;
	}

	/** The profile identity of a post, or null when it has none (the reverse of post_of_entity). */
	public static function entity_of_post( int $post_id ): ?string {
		$id = $post_id > 0 ? (string) get_post_meta( $post_id, self::PAGE_ID_META, true ) : '';
		return self::valid_uuid( $id ) ? $id : null;
	}

	/** Prevent WordPress from trashing a linked Histeon entity that surviving content still references. */
	public static function protect_linked_content_before_trash( mixed $check, \WP_Post $post, string $previous_status ): mixed {
		// Cheap checks first: most posts are not linked to a Histeon CMS project.
		$record_id = null === $check ? (string) get_post_meta( $post->ID, self::PAGE_ID_META, true ) : '';
		if ( ! self::valid_uuid( $record_id ) ) {
			return $check;
		}
		$entity_kind = self::profile_entity_kind_for_post( $post );
		if ( null === $entity_kind ) {
			return $check;
		}
		try {
			if ( 'content-record' === $entity_kind ) {
				$files = array();
				$entities = ContentProfileAdapter::snapshot( false, $files );
				if ( ContentProfileAdapter::has_incoming_record_reference( $record_id, $entities, self::$planned_deletion_ids ) ) {
					return false;
				}
			} else {
				$snapshot = self::snapshot( false );
				foreach ( $snapshot['references'] as $reference ) {
					if ( $record_id === ( $reference['targetId'] ?? null ) && ! in_array( $reference['ownerId'] ?? null, self::$planned_deletion_ids, true ) ) {
						return false;
					}
				}
			}
		} catch ( \Throwable $error ) {
			error_log( 'Histeon: blocked trash of linked content because its references could not be checked (' . $error::class . ').' );
			return false;
		}
		return $check;
	}

	/** Keep linked Histeon content out of permanent deletion until a recoverable tombstone exists. */
	public static function protect_linked_content_before_permanent_delete( mixed $check, \WP_Post $post, bool $force_delete ): mixed {
		if ( null !== $check ) {
			return $check;
		}
		$record_id = (string) get_post_meta( $post->ID, self::PAGE_ID_META, true );
		if ( ! self::valid_uuid( $record_id ) ) {
			return $check;
		}
		$entity_kind = self::profile_entity_kind_for_post( $post );
		if ( null === $entity_kind ) {
			return $check;
		}
		$tombstone = get_post_meta( $post->ID, self::TOMBSTONE_META, true );
		$remembered = get_option( self::TOMBSTONES_OPTION, array() );
		if ( 'trash' === $post->post_status && is_array( $tombstone ) && 'tombstone' === ( $tombstone['kind'] ?? null ) && $entity_kind === ( $tombstone['payload']['entityKind'] ?? null ) && isset( $remembered[ $record_id ] ) && hash_equals( (string) ( $remembered[ $record_id ]['revisionHash'] ?? '' ), (string) ( $tombstone['revisionHash'] ?? '' ) ) ) {
			return $check;
		}
		return false;
	}

	/** Record a recoverable deletion for a linked Histeon page or relational record before WordPress moves it to Trash. */
	public static function record_profile_trash( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || is_array( get_post_meta( $post_id, self::TOMBSTONE_META, true ) ) ) {
			return;
		}
		$page_id = (string) get_post_meta( $post_id, self::PAGE_ID_META, true );
		$base_hash = (string) get_post_meta( $post_id, self::BASE_HASH_META, true );
		if ( ! self::valid_uuid( $page_id ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $base_hash ) ) {
			return;
		}
		$entity_kind = self::profile_entity_kind_for_post( $post );
		if ( null === $entity_kind ) {
			return;
		}
		try {
			$files = array();
			$snapshot = 'content-record' === $entity_kind
				? ContentProfileAdapter::snapshot( false, $files )
				: self::snapshot( false )['entities'];
			$previous = null;
			foreach ( $snapshot as $entity ) {
				if ( $page_id === $entity['id'] && $entity_kind === $entity['kind'] ) {
					$previous = $entity;
					break;
				}
			}
			if ( ! is_array( $previous ) || ! is_array( $previous['payload'] ?? null ) ) {
				return;
			}
			$tombstone = self::make_tombstone( $previous, $entity_kind, $base_hash, gmdate( 'Y-m-d\\TH:i:s.000\\Z' ) );
			update_post_meta( $post_id, self::TOMBSTONE_META, $tombstone );
			self::remember_profile_tombstone( $tombstone );
		} catch ( \Throwable $error ) {
			error_log( 'Histeon: could not record a linked content deletion for transfer (' . $error::class . ').' );
		}
	}

	/** A restored Histeon post is live again and must be exported as its original profile entity. */
	public static function clear_profile_tombstone_on_restore( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) { return; }
		$tombstone = get_post_meta( $post_id, self::TOMBSTONE_META, true );
		if ( ! is_array( $tombstone ) || 'tombstone' !== ( $tombstone['kind'] ?? null ) ) { return; }
		$expected_kind = self::profile_entity_kind_for_post( $post );
		if ( null === $expected_kind ) { return; }
		if ( $expected_kind !== ( $tombstone['payload']['entityKind'] ?? null ) ) { return; }
		delete_post_meta( $post_id, self::TOMBSTONE_META );
		self::forget_profile_tombstone( (string) get_post_meta( $post_id, self::PAGE_ID_META, true ) );
	}

	/** @param array<string, mixed> $entity */
	private static function remember_profile_tombstone( array $entity ): void {
		if ( ! self::valid_uuid( $entity['id'] ?? null ) || 'tombstone' !== ( $entity['kind'] ?? null ) ) {
			throw new \InvalidArgumentException( 'Identità della cancellazione non valida.' );
		}
		$items = get_option( self::TOMBSTONES_OPTION, array() );
		$items = is_array( $items ) ? $items : array();
		$items[ $entity['id'] ] = $entity;
		update_option( self::TOMBSTONES_OPTION, $items, false );
	}

	private static function forget_profile_tombstone( string $id ): void {
		if ( ! self::valid_uuid( $id ) ) {
			return;
		}
		$items = get_option( self::TOMBSTONES_OPTION, array() );
		if ( ! is_array( $items ) || ! isset( $items[ $id ] ) ) {
			return;
		}
		unset( $items[ $id ] );
		if ( array() === $items ) {
			delete_option( self::TOMBSTONES_OPTION );
		} else {
			update_option( self::TOMBSTONES_OPTION, $items, false );
		}
	}

	/**
	 * @param array<string, mixed> $previous
	 * @return array<string, mixed>
	 */
	private static function make_tombstone( array $previous, string $entity_kind, string $base_hash, string $deleted_at ): array {
		$payload = array(
			'entityKind' => $entity_kind,
			'deletedAt' => $deleted_at,
			'label' => (string) ( $previous['payload']['title'] ?? 'Pagina' ),
			'previousRevisionHash' => (string) $previous['revisionHash'],
			'previousPayload' => $previous['payload'],
		);
		$tombstone = array( 'id' => $previous['id'], 'kind' => 'tombstone', 'schemaVersion' => 1, 'baseHash' => $base_hash, 'payload' => $payload );
		$tombstone['revisionHash'] = PageProfile::revision_hash( $tombstone );
		return $tombstone;
	}

	/** Public identity check shared by the relational adapter. */
	public static function valid_profile_identity( mixed $id ): bool {
		return self::valid_uuid( $id );
	}

	/**
	 * Public media bridge shared by relational fields.
	 *
	 * @param array<string, string> $files
	 * @return array<string, mixed>
	 */
	public static function transfer_asset_entity( \WP_Post $post, array &$files, bool $ensure_identity = true ): array {
		return self::asset_entity( $post, $files, $ensure_identity );
	}

	/** The first download becomes the common base; later exports preserve the last confirmed sync base. */
	/** @param list<array<string, mixed>> $entities */
	private static function initialize_export_bases( array $entities ): void {
		foreach ( $entities as $entity ) {
			if ( ! is_string( $entity['id'] ?? null ) || ! is_string( $entity['revisionHash'] ?? null ) ) {
				continue;
			}
			if ( 'tombstone' === ( $entity['kind'] ?? null ) ) {
				continue;
			}
			if ( in_array( $entity['kind'] ?? null, array( 'content-type', 'content-record', 'taxonomy', 'term', 'query-preset', 'query-source' ), true ) ) {
				ContentProfileAdapter::initialize_export_base( $entity );
				continue;
			}
			if ( 'styles' === ( $entity['kind'] ?? null ) ) {
				$base = (string) get_option( self::STYLES_BASE_OPTION, '' );
				if ( ! preg_match( '/^[0-9a-f]{64}$/', $base ) ) {
					update_option( self::STYLES_BASE_OPTION, $entity['revisionHash'], false );
					update_option( 'xb_page_profile_styles_id', $entity['id'], false );
				}
				continue;
			}
			$type = match ( $entity['kind'] ?? null ) {
				'asset' => 'attachment',
				'component' => 'wp_block',
				'template-part' => 'wp_template_part',
				'template' => 'wp_template',
				default => self::DOCUMENT_TYPES,
			};
			$post = self::post_by_profile_id( $entity['id'], $type );
				if ( $post instanceof \WP_Post && ! preg_match( '/^[0-9a-f]{64}$/', (string) get_post_meta( $post->ID, self::BASE_HASH_META, true ) ) ) {
				update_post_meta( $post->ID, self::BASE_HASH_META, $entity['revisionHash'] );
			}
		}
	}

	/**
	 * Import or update a profile. Existing entities require a matching common base hash; otherwise the entire
	 * operation stops with a conflict report before any page, style or media is written.
	 *
	 * @return array<string, mixed>
	 */
	public static function import( string $archive, bool $may_publish, bool $check_only = false ): array {
		if ( ! $check_only ) {
			// A real site takes longer than a request lasts: Publish stops waiting for the answer and reads the status instead.
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			}
		}
		$profile    = PageProfile::decode( $archive );
		$tombstones = array_values( array_filter( $profile['entities'], static fn ( array $entity ): bool => 'tombstone' === ( $entity['kind'] ?? null ) ) );
		foreach ( $tombstones as $tombstone ) {
			if ( ! in_array( $tombstone['payload']['entityKind'] ?? null, array( 'document', 'content-record', 'component', 'template-part', 'template', 'asset' ), true ) ) {
				throw new \DomainException( 'WordPress può ricevere cancellazioni recuperabili solo per pagine, record CMS, componenti, template e media collegati.' );
			}
		}
		$profile['entities'] = array_values( array_filter( $profile['entities'], static fn ( array $entity ): bool => 'tombstone' !== ( $entity['kind'] ?? null ) ) );
		$transfer_query_presets = ProfileValidator::transfer_query_presets_from_entities( $profile['entities'] );
		$manifest   = $profile['manifest'];
		$operation_id = $manifest['selection']['snapshot'];
		$operation_action = $may_publish ? 'publish' : 'import';
		$published = 'publish' === $operation_action && current_user_can( 'xb_publish' );
		$operation_project_id = $manifest['project']['id'];
		$request_hash = hash( 'sha256', $archive );
		$status_key = 'xb_remote_operation_' . $operation_id;
		$existing_operation = get_option( $status_key );
		if ( is_array( $existing_operation ) ) {
			if ( (int) ( $existing_operation['userId'] ?? 0 ) !== get_current_user_id() ) {
				throw new OperationConflictException( 'Questo identificativo operazione appartiene a un altro collegamento WordPress.' );
			}
			if ( ! hash_equals( (string) ( $existing_operation['requestHash'] ?? '' ), $request_hash ) || ( $existing_operation['action'] ?? null ) !== $operation_action || ( $existing_operation['projectId'] ?? null ) !== $operation_project_id ) {
				throw new OperationConflictException( 'Lo stesso identificativo operazione è già associato a una richiesta diversa. Crea un nuovo piano.' );
			}
			if ( 'complete' === ( $existing_operation['state'] ?? null ) && true === ( $existing_operation['complete'] ?? false ) && is_array( $existing_operation['report'] ?? null ) ) {
				return $check_only ? array( 'check' => true, 'alreadyApplied' => true, 'project' => $manifest['project'], 'entities' => array(), 'deletions' => 0 ) : $existing_operation['report'];
			}
			throw new OperationConflictException( 'Questa operazione non è completa. Controlla lo stato e crea un nuovo piano prima di riprovare.' );
		}
		$content_plan = ContentProfileAdapter::preflight( $profile['entities'] );
		$has_content = false;
		$needs_content_admin = false;
		$needs_content_editor = false;
		$needs_term_editor = false;
		foreach ( $profile['entities'] as $incoming_entity ) {
			$kind = $incoming_entity['kind'];
			$has_content = $has_content || in_array( $kind, array( 'content-type', 'content-record', 'taxonomy', 'term', 'query-preset', 'query-source' ), true );
			$needs_content_admin = $needs_content_admin || in_array( $kind, array( 'content-type', 'taxonomy', 'query-preset', 'query-source' ), true );
			$needs_content_editor = $needs_content_editor || 'content-record' === $kind;
			$needs_term_editor = $needs_term_editor || 'term' === $kind;
		}
		foreach ( $tombstones as $tombstone ) {
			$needs_content_editor = $needs_content_editor || 'content-record' === ( $tombstone['payload']['entityKind'] ?? null );
		}
		if ( $needs_content_admin && ! current_user_can( 'manage_options' ) ) {
			throw new \DomainException( 'Per importare tipi, tassonomie o query CMS il collegamento WordPress deve poter gestire le impostazioni del plugin.' );
		}
		if ( $needs_content_editor && ! current_user_can( 'edit_posts' ) ) {
			throw new \DomainException( 'Il collegamento WordPress non può creare o aggiornare i contenuti CMS.' );
		}
		// Each record that already exists, as each document: overwritten only by someone who may edit that post.
		foreach ( $profile['entities'] as $incoming_entity ) {
			$record = 'content-record' === $incoming_entity['kind'] && is_string( $incoming_entity['payload']['typeKey'] ?? null ) ? ContentProfileAdapter::linked_record_by_id( $incoming_entity['id'], $incoming_entity['payload']['typeKey'] ) : null;
			if ( $record instanceof \WP_Post && ! current_user_can( 'edit_post', $record->ID ) ) {
				throw new \DomainException( 'Il collegamento WordPress non può aggiornare il record ' . $incoming_entity['id'] . '.' );
			}
		}
		if ( $needs_term_editor && ! current_user_can( 'manage_categories' ) ) {
			throw new \DomainException( 'Il collegamento WordPress non può gestire i termini delle tassonomie.' );
		}
		$blockers = self::initialization_blockers();
		$can_initialize = array() === $blockers;
		$project_id = (string) get_option( self::PROJECT_ID_OPTION, '' );
		if ( $can_initialize ) {
			// A failed pre-profile export in an older plugin could leave an orphan project ID. With no Histeon data,
			// it is safe to replace that marker when the first real profile is committed.
			$project_id = '';
		} elseif ( ! self::valid_uuid( $project_id ) ) {
			throw new \DomainException(
				sprintf(
					/* translators: %s: the Histeon data found on the site, separated by semicolons */
					__( 'Questo WordPress ha già dati Histeon che non vengono da un progetto: %s. L’importazione si è fermata per non sovrascriverli. Se ti servono, collega Histeon Publish con «Scaricare il sito» e scaricali prima; se il sito è nuovo e non ti servono, importa il progetto in un WordPress senza dati Histeon.', 'xb' ),
					implode( '; ', $blockers )
				)
			);
		}
		if ( self::valid_uuid( $project_id ) && $project_id !== $manifest['project']['id'] ) {
			throw new \DomainException( 'This WordPress site is already paired with a different Histeon project.' );
		}
		$current = array();
		if ( self::valid_uuid( $project_id ) ) {
			foreach ( self::snapshot( false, true )['entities'] as $entity ) {
				$current[ $entity['id'] ] = $entity;
			}
		}
		$apply_tombstones = array();
		$active_page_count = count( array_filter( $current, static fn ( array $entity ): bool => 'document' === ( $entity['kind'] ?? null ) && 'page' === ( $entity['payload']['documentType'] ?? null ) ) );
		foreach ( $tombstones as $tombstone ) {
			$existing = $current[ $tombstone['id'] ] ?? null;
			if ( is_array( $existing ) && 'tombstone' === ( $existing['kind'] ?? null ) && hash_equals( (string) $existing['revisionHash'], (string) $tombstone['revisionHash'] ) ) {
				continue;
			}
			$previous_hash = $tombstone['payload']['previousRevisionHash'] ?? null;
			$base_hash = $tombstone['baseHash'] ?? null;
			$entity_kind = (string) $tombstone['payload']['entityKind'];
			$post = self::post_for_profile_tombstone( $entity_kind, $tombstone['id'], $tombstone['payload']['previousPayload'] );
			$stored_base = $post instanceof \WP_Post ? (string) get_post_meta( $post->ID, self::BASE_HASH_META, true ) : '';
			if ( ! is_array( $existing ) || $entity_kind !== ( $existing['kind'] ?? null ) || ! is_string( $previous_hash ) || ! is_string( $base_hash ) || ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! hash_equals( $existing['revisionHash'], $previous_hash ) || ! hash_equals( $stored_base, $base_hash ) || ! hash_equals( $base_hash, $previous_hash ) ) {
				throw new ProfileConflictException( array( array( 'id' => $tombstone['id'], 'kind' => 'tombstone', 'title' => (string) $tombstone['payload']['label'], 'baseHash' => is_string( $base_hash ) ? $base_hash : null, 'currentHash' => is_array( $existing ) ? (string) ( $existing['revisionHash'] ?? '' ) : null, 'incomingHash' => $tombstone['revisionHash'] ) ) );
			}
			if ( ! current_user_can( 'delete_post', $post->ID ) ) {
				throw new \DomainException( 'Il collegamento WordPress non può cestinare questo contenuto Histeon del progetto.' );
			}
			$deleting_ids = array_column( $tombstones, 'id' );
			foreach ( $profile['references'] as $reference ) {
				if ( ( $reference['targetId'] ?? null ) === $tombstone['id'] && ! in_array( $reference['ownerId'] ?? null, $deleting_ids, true ) ) {
					throw new ProfileConflictException( array( array( 'id' => $tombstone['id'], 'kind' => 'tombstone', 'title' => (string) $tombstone['payload']['label'], 'baseHash' => $base_hash, 'currentHash' => $existing['revisionHash'], 'incomingHash' => $tombstone['revisionHash'] ) ) );
				}
			}
			$apply_tombstones[] = array( 'entity' => $tombstone, 'post' => $post );
		}
		$deleting_page_count = count( array_filter( $apply_tombstones, static fn ( array $deletion ): bool => 'document' === ( $deletion['entity']['payload']['entityKind'] ?? null ) && 'page' === ( $deletion['entity']['payload']['previousPayload']['documentType'] ?? null ) ) );
		if ( $deleting_page_count >= $active_page_count && array() !== $apply_tombstones ) {
			throw new \DomainException( 'Il progetto WordPress deve mantenere almeno una pagina Histeon attiva per poter esportare e sincronizzare il profilo.' );
		}
		if ( array() !== $apply_tombstones && ! EMPTY_TRASH_DAYS ) {
			throw new \DomainException( 'Il Cestino di WordPress è disattivato: attivalo per mantenere recuperabili le pagine eliminate da Histeon.' );
		}
		$conflicts = array();
		foreach ( $profile['entities'] as $entity ) {
			$live = $current[ $entity['id'] ] ?? null;
			if ( null === $live || hash_equals( $entity['revisionHash'], $live['revisionHash'] ) ) {
				continue;
			}
			if ( self::matches_unpublished_content_projection( $entity, $live, $published ) ) {
				continue;
			}
			$base = $entity['baseHash'] ?? null;
			if ( ! is_string( $base ) || ! hash_equals( $base, $live['revisionHash'] ) ) {
				$conflicts[] = array(
					'id'          => $entity['id'],
					'kind'        => $entity['kind'],
					'title'       => (string) ( $entity['payload']['title'] ?? $entity['payload']['path'] ?? 'Stili globali' ),
					'baseHash'    => is_string( $base ) ? $base : null,
					'currentHash' => $live['revisionHash'],
					'incomingHash'=> $entity['revisionHash'],
				);
			}
		}
		if ( array() !== $conflicts ) {
			throw new ProfileConflictException( $conflicts );
		}
		$candidate_entities = $current;
		foreach ( $profile['entities'] as $incoming_entity ) { $candidate_entities[ $incoming_entity['id'] ] = $incoming_entity; }
		foreach ( $tombstones as $tombstone ) { unset( $candidate_entities[ $tombstone['id'] ] ); }
		foreach ( $apply_tombstones as $deletion ) {
			$tombstone = $deletion['entity'];
			if ( 'content-record' === ( $tombstone['payload']['entityKind'] ?? null ) && ContentProfileAdapter::has_incoming_record_reference( $tombstone['id'], array_values( $candidate_entities ) ) ) {
				throw new ProfileConflictException( array( array( 'id' => $tombstone['id'], 'kind' => 'tombstone', 'title' => (string) $tombstone['payload']['label'], 'baseHash' => $tombstone['baseHash'], 'currentHash' => $tombstone['payload']['previousRevisionHash'], 'incomingHash' => $tombstone['revisionHash'] ) ) );
			}
		}
		$has_page_content = (bool) array_filter(
			$tombstones,
			static fn ( array $entity ): bool => in_array( $entity['payload']['entityKind'] ?? null, array( 'document', 'component', 'template-part', 'template', 'asset' ), true )
		) || (bool) array_filter( $profile['entities'], static fn ( array $entity ): bool => in_array( $entity['kind'], array( 'document', 'component', 'template-part', 'template', 'styles', 'asset' ), true ) );
		if ( $has_page_content && ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'xb_build' ) ) ) {
			throw new \DomainException( 'The linked WordPress administrator cannot edit Histeon pages.' );
		}
		// Documents' JavaScript (threat model T1): only a user who may store it (unfiltered_html) imports it, and only on
		// pages, posts and components, the documents WordPress prints it for. Checked before anything is written.
		foreach ( $profile['entities'] as $entity ) {
			if ( 'tombstone' === $entity['kind'] || '' === ( $entity['payload']['script'] ?? '' ) ) {
				continue;
			}
			if ( ! in_array( $entity['kind'], array( 'document', 'component' ), true ) ) {
				throw new \DomainException( 'The ' . $entity['kind'] . ' ' . $entity['id'] . ' carries JavaScript, which WordPress keeps only for pages, posts and components. Nothing was written.' );
			}
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				throw new \DomainException( 'The document ' . $entity['id'] . ' carries JavaScript, and the WordPress user of this link cannot save it (it lacks the unfiltered_html capability): link a user who can, or remove the script, then send again. Nothing was written.' );
			}
		}
		$has_posts = (bool) array_filter( $profile['entities'], static fn ( array $entity ): bool => 'document' === $entity['kind'] && 'post' === $entity['payload']['documentType'] );
		if ( $has_posts && ! current_user_can( 'edit_posts' ) ) {
			throw new \DomainException( 'Il collegamento WordPress non può modificare gli articoli.' );
		}
		$asset_ids = array();
		$image_asset_ids = array();
		$asset_media_types = array();
		$component_ids_by_ref = array();
		$component_entities_by_ref = array();
		$template_ids_by_ref = array();
		foreach ( $profile['entities'] as $entity ) {
			if ( 'asset' === $entity['kind'] ) {
				if ( ! in_array( $entity['payload']['mediaType'], self::ALLOWED_CSS_MEDIA, true ) ) {
					throw new \DomainException( 'The static page profile only imports approved image and font formats.' );
				}
				$asset_ids[ $entity['id'] ] = true;
				$asset_media_types[ $entity['id'] ] = $entity['payload']['mediaType'];
				if ( in_array( $entity['payload']['mediaType'], self::ALLOWED_MEDIA, true ) ) {
					$image_asset_ids[ $entity['id'] ] = true;
				}
			} elseif ( in_array( $entity['kind'], array( 'component', 'template-part' ), true ) ) {
				if ( isset( $component_entities_by_ref[ $entity['payload']['ref'] ] ) ) {
					throw new \DomainException( 'The incoming Histeon project contains duplicate component references.' );
				}
				$component_ids_by_ref[ $entity['payload']['ref'] ] = $entity['id'];
				$component_entities_by_ref[ $entity['payload']['ref'] ] = $entity;
			} elseif ( 'template' === $entity['kind'] ) {
				$template_ids_by_ref[ $entity['payload']['ref'] ] = $entity['id'];
			}
		}
		$content_assets = (bool) array_filter( $profile['entities'], static fn ( array $entity ): bool => 'asset' === $entity['kind'] );
		if ( $content_assets && ! current_user_can( 'upload_files' ) ) {
			throw new \DomainException( 'Il collegamento WordPress non può caricare le immagini usate dai campi CMS.' );
		}
		if ( $template_ids_by_ref && ! current_user_can( 'edit_theme_options' ) ) {
			throw new \DomainException( 'The linked WordPress administrator cannot edit templates of the active theme.' );
		}
		if ( array() !== $asset_ids && ! current_user_can( 'upload_files' ) ) {
			throw new \DomainException( 'The linked WordPress administrator cannot upload page media.' );
		}
		foreach ( $profile['entities'] as $entity ) {
			if ( ! in_array( $entity['kind'], array( 'document', 'component', 'template-part', 'template' ), true ) ) {
				continue;
			}
			/** @var list<ParsedBlock> $blocks */
			$blocks = array_values( parse_blocks( $entity['payload']['content'] ) );
			ProfileValidator::assert_page_blocks( $blocks, $image_asset_ids, $component_ids_by_ref, in_array( $entity['kind'], array( 'component', 'template-part', 'template' ), true ), $entity['id'], true, $transfer_query_presets );
			ProfileValidator::assert_component_bindings( $blocks, $component_entities_by_ref, $entity['id'] );
			if ( 'document' === $entity['kind'] ) {
				$present = self::post_by_profile_id( $entity['id'], self::entity_post_type( $entity ) );
				\XB\Content\ArticleBlocks::assert_foreign_known( $blocks, $present instanceof \WP_Post ? parse_blocks( self::dev_content( $present ) ) : array(), $present instanceof \WP_Post ? static fn (): array => self::earlier_blocks( $present ) : null, (string) ( $entity['payload']['title'] ?? $entity['id'] ) );
			}
			// A form is the one element of histos-transfer-markup-v1 beyond the restricted tier (threat model T26): for a
			// link user without unfiltered_html the save would drop it after other writes, so it stops the send here.
			if ( ! current_user_can( 'unfiltered_html' ) && self::contains_form( $blocks ) ) {
				throw new \DomainException( 'The document ' . $entity['id'] . ' contains a form, and the WordPress user of this link cannot save forms (it lacks the unfiltered_html capability): link a user who can, or remove the form, then send again. Nothing was written.' );
			}
			$type = self::entity_post_type( $entity );
			$existing = self::post_by_profile_id( $entity['id'], 'document' === $entity['kind'] ? self::DOCUMENT_TYPES : $type );
			if ( $existing instanceof \WP_Post && $existing->post_type !== $type ) {
				throw new \DomainException( 'Un documento Histeon non può passare da pagina ad articolo, o viceversa: ' . $existing->post_name );
			}
			// A title is text, and is printed as such by themes that do not escape it (T26): for an owner of the link with
			// unfiltered_html WordPress would keep it as it came, so it is held to the same check as an excerpt.
			if ( is_string( $entity['payload']['title'] ?? null ) && self::markup_filtered( $entity['payload']['title'] ) ) {
				throw new \DomainException( 'Il titolo di ' . $entity['id'] . ' contiene markup che WordPress rimuoverebbe. Correggilo prima del trasferimento.' );
			}
			// wp_kses_post() also writes a plain & as &amp;, which is no markup («Rossi&figli»): see no_excerpt_filter().
			if ( 'document' === $entity['kind'] && 'post' === $type && self::filtered_excerpt( $entity['payload']['excerpt'] ) !== $entity['payload']['excerpt'] ) {
				throw new \DomainException( 'Il riassunto dell\'articolo «' . $entity['payload']['title'] . '» contiene markup che WordPress rimuoverebbe. Correggilo prima del trasferimento.' );
			}
			if ( 'template' === $entity['kind'] ) {
				$slug = $existing instanceof \WP_Post ? self::wanted_template_slug( $existing, $entity['payload']['ref'], $entity['payload']['role'] ?? null ) : self::profile_template_slug( $entity['payload']['ref'], $entity['payload']['role'] ?? null );
				foreach ( self::theme_templates_named( $slug ) as $holder ) {
					// A project template that gives the slug up in this same send (its role moves) is no obstacle.
					if ( self::frees_slug_in( $holder, $slug, $profile['entities'] ) ) {
						continue;
					}
					if ( ! $existing instanceof \WP_Post || $holder->ID !== $existing->ID ) {
						throw new \DomainException( 'Il tema attivo ha già un template «' . $slug . '» che non appartiene a questo template Histeon: Histeon non lo sovrascrive.' );
					}
				}
			}
			$template_kind = in_array( $entity['kind'], array( 'template', 'template-part' ), true );
			$can_update = $template_kind ? current_user_can( 'edit_theme_options' ) : ( ! $existing instanceof \WP_Post || current_user_can( 'edit_post', $existing->ID ) );
			if ( ! $can_update ) {
				throw new \DomainException( 'The linked WordPress administrator cannot update one of the target Histeon documents.' );
			}
			if ( in_array( $entity['kind'], array( 'component', 'template-part' ), true ) ) {
				$owner_id = ComponentRegistry::owner( $entity['payload']['ref'] );
				if ( null !== $owner_id && ( ! $existing || $existing->ID !== $owner_id ) ) {
					throw new \DomainException( 'Another WordPress reusable block already uses the incoming Histeon component key: ' . $entity['payload']['ref'] );
				}
				if ( ! $existing && ! current_user_can( 'template-part' === $entity['kind'] ? 'edit_theme_options' : 'edit_posts' ) ) {
					throw new \DomainException( 'The linked WordPress administrator cannot create Histeon components.' );
				}
				if ( 'template-part' === $entity['kind'] && ! $existing && get_block_template( get_stylesheet() . '//' . self::profile_template_slug( $entity['payload']['ref'] ), 'wp_template_part' ) instanceof \WP_Block_Template ) {
					throw new \DomainException( 'The active theme already has a template part with the generated Histeon identifier.' );
				}
			} elseif ( 'template' === $entity['kind'] && ! $existing && ! isset( $entity['payload']['role'] ) && get_block_template( get_stylesheet() . '//' . self::profile_template_slug( $entity['payload']['ref'] ), 'wp_template' ) instanceof \WP_Block_Template ) {
				throw new \DomainException( 'The active theme already has a template with the generated Histeon identifier.' );
			} elseif ( 'document' === $entity['kind'] && 'page' === $type && ! $existing && '' !== $entity['payload']['slug'] ) {
				$collision = get_page_by_path( $entity['payload']['slug'], 'OBJECT', 'page' );
				if ( $collision instanceof \WP_Post ) {
					throw new \DomainException( 'Another WordPress page already uses the incoming slug: ' . $entity['payload']['slug'] );
				}
			} elseif ( 'document' === $entity['kind'] && 'post' === $type && ! $existing ) {
				$collision = get_posts( array( 'post_type' => 'post', 'name' => $entity['payload']['slug'], 'post_status' => 'any', 'posts_per_page' => 1, 'suppress_filters' => true ) );
				if ( array() !== $collision ) {
					throw new \DomainException( 'Un altro articolo WordPress usa già lo slug in arrivo: ' . $entity['payload']['slug'] );
				}
			}
		}
		foreach ( $profile['entities'] as $entity ) {
			if ( 'styles' === $entity['kind'] ) {
				foreach ( array_merge( $entity['payload']['styles'], $entity['payload']['globals'] ) as $rule ) {
					\XB\Styles\CssAssets::validate_profile( (string) $rule['css'], $rule['assets'] ?? array(), $asset_ids, $asset_media_types );
				}
				$restricted = Documents::restricted_styles( array( 'styles' => $entity['payload']['styles'], 'globals' => $entity['payload']['globals'] ) );
				if ( PageProfile::canonical( $restricted['styles'] ) !== PageProfile::canonical( $entity['payload']['styles'] ) || PageProfile::canonical( $restricted['globals'] ) !== PageProfile::canonical( $entity['payload']['globals'] ) ) {
					throw new \DomainException( 'The style profile contains CSS which the receiving site would remove; fix it before importing.' );
				}
			}
		}

		self::assert_srcset_urls_listable( $profile['references'] );

		// Everything is checked: a check stops here, before any lock or write (docs/spec/cms-bridge.md §13).
		if ( $check_only ) {
			return self::check_plan( $profile, $current, $apply_tombstones );
		}

		$lock_keys = array(
			'xb_remote_operation_lock_' . $operation_id,
			'xb_remote_project_lock_' . $operation_project_id,
		);
		$acquired_locks = array();
		foreach ( $lock_keys as $lock_key ) {
			$lock = get_option( $lock_key );
			if ( is_array( $lock ) ) {
				$lock_is_fresh = (int) ( $lock['updatedAt'] ?? $lock['createdAt'] ?? 0 ) > time() - self::lock_ttl();
				if ( $lock_is_fresh ) {
					self::release_operation_locks( $acquired_locks, $operation_id, $request_hash );
					throw new OperationConflictException( 'È già in corso un trasferimento per questo progetto. Controlla lo stato prima di riprovare.' );
				}
				delete_option( $lock_key );
			} elseif ( false !== $lock ) {
				self::release_operation_locks( $acquired_locks, $operation_id, $request_hash );
				throw new OperationConflictException( 'È già in corso un trasferimento per questo progetto. Controlla lo stato prima di riprovare.' );
			}
			$lock_record = array( 'operationId' => $operation_id, 'projectId' => $operation_project_id, 'requestHash' => $request_hash, 'userId' => get_current_user_id(), 'createdAt' => time(), 'updatedAt' => time() );
			if ( ! add_option( $lock_key, $lock_record, '', false ) ) {
				self::release_operation_locks( $acquired_locks, $operation_id, $request_hash );
				throw new OperationConflictException( 'È già in corso un trasferimento per questo progetto. Controlla lo stato prima di riprovare.' );
			}
			$acquired_locks[] = $lock_key;
		}
		try {
		self::$running_operation = $operation_id;
		// Bind the target project before the first write so an interrupted first import is recognizable and can
		// be reconciled by a fresh plan. All profile, capability, collision, and lock checks have already passed.
		update_option( self::PROJECT_ID_OPTION, $manifest['project']['id'], false );
		update_option( self::PROJECT_NAME_OPTION, sanitize_text_field( $manifest['project']['name'] ), false );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		self::prune_written_media();
		self::no_excerpt_filter();
		$progress = static function ( string $phase, array $changed ) use ( $operation_id, $operation_action, $published, $operation_project_id, $request_hash ): void {
			self::save_status( $operation_id, array( 'operationId' => $operation_id, 'action' => $operation_action, 'published' => $published, 'state' => 'applying', 'phase' => $phase, 'changed' => array_values( array_unique( $changed ) ), 'updatedAt' => gmdate( 'c' ), 'userId' => get_current_user_id(), 'projectId' => $operation_project_id, 'requestHash' => $request_hash ) );
		};
		$media_ids      = array();
		$media_moves    = array();
		$changed        = array();
		$skipped        = array();
		$pending        = array();
		$touched_docs   = array();
		$content_result = array( 'changed' => array(), 'pending' => array(), 'records' => 0, 'terms' => 0 );

		// Settings first, without writing: design, tokens and named queries from the styles entity, content types,
		// queries and data sources from the CMS plan. One validation for all; a refusal stops before any write.
		$settings      = array();
		$styles_entity = null;
		foreach ( $profile['entities'] as $entity ) {
			if ( 'styles' !== $entity['kind'] ) {
				continue;
			}
			$before = $current[ $entity['id'] ]['revisionHash'] ?? null;
			if ( null !== $before && hash_equals( $entity['revisionHash'], $before ) ) {
				$skipped[] = $entity['id'];
				continue;
			}
			$styles_entity = $entity;
			foreach ( array( 'design' => 'design', 'tokenOverrides' => 'tokens', 'queries' => 'queries' ) as $from => $to ) {
				if ( array_key_exists( $from, $entity['payload'] ) ) {
					$settings[ $to ] = $entity['payload'][ $from ];
				}
			}
		}
		if ( $has_content ) {
			$settings = array_merge( $settings, ContentProfileAdapter::staged_settings( $content_plan ) );
		}
		$settings = self::validated_settings( $settings );
		// A Publish project is written with the --xb-* tokens of Histeon CSS: a site with no framework chosen yet now
		// has one (otherwise sheets left by a framework only tried out would still be served: AcssLegacy).
		if ( null !== $styles_entity && null === ( self::dev_payload( Documents::SETTINGS )['framework'] ?? null ) ) {
			$settings['framework'] = 'xb-tokens';
		}

		$progress( 'media', $changed );
		foreach ( $profile['entities'] as $entity ) {
			if ( 'asset' !== $entity['kind'] ) {
				continue;
			}
			$before                     = $current[ $entity['id'] ]['revisionHash'] ?? null;
			[ $media_ids[ $entity['id'] ], $media_waits ] = self::import_asset( $entity, $profile['files'][ $entity['payload']['path'] ], $before, $published, $media_moves, $operation_id );
			if ( null !== $before && hash_equals( $entity['revisionHash'], $before ) ) {
				$skipped[] = $entity['id'];
			} else {
				$changed[] = $entity['id'];
			}
			if ( $media_waits ) {
				$pending[] = array( 'id' => $entity['id'], 'reason' => 'next-release' );
			}
			$progress( 'media', $changed );
		}

		// Settings (content types, queries, sources, design, tokens, named queries): the development copy
		// (docs/spec/cms-bridge.md §5). The live site does not change. Without a publish, contents follow here.
		// The identity maps go first: after an interruption, a new plan must recognise the project's own names.
		if ( $has_content ) {
			$progress( 'cms-content', $changed );
			$changed = array_merge( $changed, ContentProfileAdapter::stage_identities( $content_plan ) );
			ContentProfileAdapter::queue_layouts( $content_plan );
		}
		if ( array() !== $settings ) {
			self::save_dev( Documents::SETTINGS, $settings );
			$touched_docs[] = Documents::SETTINGS;
		}
		if ( $has_content ) {
			$progress( 'cms-content', $changed );
			if ( ! $published ) {
				// Records and terms wait for the next release, in the development queue.
				$queued = ContentProfileAdapter::queue_contents( $content_plan, $media_ids, (string) ( $manifest['project']['defaultLocale'] ?? '' ) );
				$changed = array_merge( $changed, $queued );
				foreach ( $queued as $queued_id ) {
					$pending[] = array( 'id' => $queued_id, 'reason' => 'next-release' );
				}
				$progress( 'cms-content', $changed );
			}
		}

		// Histeon documents: development commits, as a save in the builder. A new one is a draft; an existing one
		// keeps its title, slug, status and template until a publish. Components first: pages use them.
		$pages          = array();
		$components     = array();
		$template_parts = array();
		$templates      = array();
		$document_posts = array();
		$template_refs_by_ref = array();
		foreach ( $profile['entities'] as $template_entity ) {
			if ( 'template' === $template_entity['kind'] ) {
				$template_refs_by_ref[ $template_entity['payload']['ref'] ] = $template_entity['id'];
			}
		}
		$phases = array( 'component' => 'components', 'template-part' => 'components', 'template' => 'templates', 'document' => 'pages' );
		foreach ( $phases as $kind => $phase ) {
			$progress( $phase, $changed );
			foreach ( $profile['entities'] as $entity ) {
				if ( $kind !== $entity['kind'] ) {
					continue;
				}
				$type     = self::entity_post_type( $entity );
				$existing = self::post_by_profile_id( $entity['id'], $type );
				$before   = $current[ $entity['id'] ]['revisionHash'] ?? null;
				$payload  = $entity['payload'];
				$entry    = array( 'id' => $entity['id'] ) + ( 'document' === $kind ? array() : array( 'ref' => $payload['ref'] ) );
				if ( null !== $before && hash_equals( $entity['revisionHash'], $before ) ) {
					$entry['action'] = 'unchanged';
					if ( $existing instanceof \WP_Post ) {
						$document_posts[ $entity['id'] ] = $existing->ID;
					}
				} else {
					$content = 'template' === $kind ? self::restore_template_content( $payload['content'], $media_ids ) : self::restore_media( $payload['content'], $media_ids, 'document' !== $kind );
					$is_new  = ! $existing instanceof \WP_Post || self::being_created( $existing );
					$post_id = $existing instanceof \WP_Post ? $existing->ID : self::create_profile_post( $entity, $type );
					$changes = array( 'content' => $content );
					// The document's JavaScript follows the transfer, set or cleared, for a user who may store it; for one
					// who may not, a transfer with scripts stopped above, and the stored script stays as it is.
					if ( in_array( $kind, array( 'document', 'component' ), true ) && is_string( $payload['script'] ?? null ) && current_user_can( 'unfiltered_html' ) ) {
						$changes['script'] = $payload['script'];
					}
					if ( 'component' === $kind ) {
						$changes['ref']    = $payload['ref'];
						$changes['schema'] = $payload['schema'];
					}
					self::save_dev( Documents::for_post( $post_id ), $changes, $content );
					$touched_docs[] = Documents::for_post( $post_id );
					update_post_meta( $post_id, self::PAGE_ID_META, $entity['id'] );
					// Written by an import: its dynamic image props are held to the site's uploads (Renderer::attribute).
					update_post_meta( $post_id, self::IMPORTED_META, '1' );
					delete_post_meta( $post_id, self::TOMBSTONE_META );
					self::forget_profile_tombstone( $entity['id'] );
					if ( 'template' === $kind ) {
						update_post_meta( $post_id, self::TEMPLATE_SCHEMA_META, wp_slash( (string) wp_json_encode( $payload['schema'] ) ) );
					}
					if ( $is_new && 'document' === $kind ) {
						self::assign_page_template( $post_id, $payload['templateRef'] ?? null, $template_refs_by_ref );
					}
					// Title, slug, status and page template change with the next release: until then they wait
					// on the post, and the profile shows them as they are in development.
					if ( self::stage_document_fields( (int) $post_id, $kind, $payload ) && ! $published ) {
						$pending[] = array( 'id' => $entity['id'], 'reason' => 'published-fields' );
					}
					delete_post_meta( $post_id, self::CREATING_META );
					$document_posts[ $entity['id'] ] = $post_id;
					$entry  += array(
						'postId' => $post_id,
						'action' => $is_new ? 'created' : 'updated',
						'status' => (string) get_post_status( $post_id ),
					);
					$changed[] = $entity['id'];
				}
				match ( $kind ) {
					'component'     => $components[]     = $entry,
					'template-part' => $template_parts[] = $entry + array( 'area' => $payload['area'] ),
					'template'      => $templates[]      = $entry,
					default         => $pages[]          = $entry + array( 'documentType' => $payload['documentType'], 'templateRef' => $payload['templateRef'] ?? null ),
				};
				if ( 'unchanged' === $entry['action'] ) {
					$skipped[] = $entity['id'];
				} else {
					$progress( $phase, $changed );
				}
			}
		}
		\XB\Plugin::instance()->components->forget();

		// A replaced image: the other documents that show it take its new file in development too. One whose
		// development copy was live carries nothing else, so a publish releases it with the image.
		foreach ( $media_moves as $move ) {
			$followed = self::follow_media( $move['from'], $move['to'], array_values( array_unique( $touched_docs ) ) );
			if ( $published ) {
				$touched_docs = array_merge( $touched_docs, $followed );
			}
		}

		$progress( 'styles', $changed );
		if ( null !== $styles_entity ) {
			self::save_dev( Documents::STYLES, array( 'styles' => $styles_entity['payload']['styles'], 'globals' => $styles_entity['payload']['globals'] ) );
			$touched_docs[] = Documents::STYLES;
			update_option( 'xb_page_profile_styles_id', $styles_entity['id'], false );
			$changed[] = $styles_entity['id'];
			$progress( 'styles', $changed );
		}

		// Publish: a Histeon release of everything touched (types are registered by the settings it carries), then
		// titles, slugs, statuses and templates of the documents, then the contents with their own statuses.
		if ( $published ) {
			$progress( 'publish', $changed );
			// What this send publishes replaces anything an earlier send left waiting for it.
			ContentProfileAdapter::dequeue( array_keys( array_merge( $content_plan['entities']['term'] ?? array(), $content_plan['entities']['content-record'] ?? array() ) ) );
			// Only what differs from production: a document sent unchanged has nothing to release.
			$docs = array_values( array_intersect( array_unique( $touched_docs ), array_column( \XB\Plugin::instance()->versioning->changes(), 'doc' ) ) );
			if ( array() !== $docs ) {
				try {
					\XB\Plugin::instance()->versioning->publish( null, __( 'Histeon CMS', 'xb' ), $docs );
				} catch ( \XB\Versioning\VersioningException $e ) {
					throw new \RuntimeException( sprintf( /* translators: %s: error message */ __( 'Pubblicazione non riuscita: %s. Tutto è in sviluppo.', 'xb' ), $e->getMessage() ), 0, $e );
				}
			}
			ContentTypes::forget();
			ContentTypes::register();
			if ( $has_content ) {
				ContentProfileAdapter::publish_layouts( $content_plan );
			}
			// Terms before the documents: the posts point to them.
			if ( $has_content ) {
				$progress( 'cms-content', $changed );
				$content_result = ContentProfileAdapter::apply_contents( $content_plan, $media_ids, true, (string) ( $manifest['project']['defaultLocale'] ?? '' ) );
				ContentProfileAdapter::dequeue( $content_result['changed'] );
				$changed        = array_merge( $changed, $content_result['changed'] );
				$pending        = array_merge( $pending, $content_result['pending'] );
			}
			$releasing = array();
			foreach ( $profile['entities'] as $entity ) {
				if ( 'template' === $entity['kind'] && isset( $document_posts[ $entity['id'] ] ) ) {
					$releasing[ $document_posts[ $entity['id'] ] ] = self::document_fields( 'template', $entity['payload'] );
				}
			}
			self::release_role_slugs( $releasing );
			foreach ( $profile['entities'] as $entity ) {
				if ( ! isset( $document_posts[ $entity['id'] ] ) || ! in_array( $entity['kind'], array( 'document', 'component', 'template-part', 'template' ), true ) ) {
					continue;
				}
				self::publish_document_fields( $document_posts[ $entity['id'] ], $entity['kind'], self::document_fields( $entity['kind'], $entity['payload'] ) + $entity['payload'], $template_refs_by_ref );
			}
		}

		// Deletions go to the Trash only when publishing; in development they wait.
		$deletions = array();
		if ( ! $published ) {
			foreach ( $apply_tombstones as $deletion ) {
				$pending[] = array( 'id' => $deletion['entity']['id'], 'reason' => 'deletion' );
			}
		} else {
			$planned_deletion_ids = array_map( static fn ( array $deletion ): string => (string) $deletion['entity']['id'], $apply_tombstones );
			$deleted_record_owner_ids = array_values( array_filter( $planned_deletion_ids, static fn ( string $id ): bool => isset( $current[ $id ] ) && 'content-record' === ( $current[ $id ]['kind'] ?? null ) ) );
			self::$planned_deletion_ids = $planned_deletion_ids;
			try {
				foreach ( $apply_tombstones as $deletion ) {
					$tombstone = $deletion['entity'];
					$post = $deletion['post'];
					if ( 'content-record' === ( $tombstone['payload']['entityKind'] ?? null ) ) {
						$fresh_files = array();
						$fresh_content_entities = ContentProfileAdapter::snapshot( false, $fresh_files );
						if ( ContentProfileAdapter::has_incoming_record_reference( $tombstone['id'], $fresh_content_entities, $deleted_record_owner_ids ) ) {
							throw new ProfileConflictException( array( array( 'id' => $tombstone['id'], 'kind' => 'tombstone', 'title' => (string) $tombstone['payload']['label'], 'baseHash' => $tombstone['baseHash'], 'currentHash' => $tombstone['payload']['previousRevisionHash'], 'incomingHash' => $tombstone['revisionHash'] ) ) );
						}
					}
					update_post_meta( $post->ID, self::TOMBSTONE_META, $tombstone );
					self::remember_profile_tombstone( $tombstone );
					$trashed = wp_trash_post( $post->ID );
					$stored_post = get_post( $post->ID );
					if ( ! $trashed instanceof \WP_Post || ! $stored_post instanceof \WP_Post || 'trash' !== $stored_post->post_status ) {
						delete_post_meta( $post->ID, self::TOMBSTONE_META );
						self::forget_profile_tombstone( $tombstone['id'] );
						throw new \RuntimeException( 'WordPress non ha spostato nel Cestino il contenuto Histeon; l’operazione resta incompleta.' );
					}
					$deletions[] = array( 'id' => $tombstone['id'], 'postId' => $post->ID, 'label' => $tombstone['payload']['label'], 'action' => 'trashed' );
					$changed[] = $tombstone['id'];
					self::save_status( $operation_id, array( 'operationId' => $operation_id, 'action' => $operation_action, 'published' => $published, 'state' => 'applying', 'phase' => 'pages', 'changed' => array_values( array_unique( $changed ) ), 'updatedAt' => gmdate( 'c' ), 'userId' => get_current_user_id(), 'projectId' => $manifest['project']['id'] ) );
				}
			} finally {
				self::$planned_deletion_ids = array();
			}
		}

		// Files no document uses any more: a waiting file the send replaced or took back.
		foreach ( $media_moves as $move ) {
			foreach ( $move['retire'] as $file ) {
				self::retire_media_file( $move['attachment'], $file );
			}
		}

		update_option( self::PROJECT_ID_OPTION, $manifest['project']['id'], false );
		update_option( self::PROJECT_NAME_OPTION, sanitize_text_field( $manifest['project']['name'] ), false );
		$heads = array();
		// Confirm only the project entities we track. A WordPress site can also contain unrelated Gutenberg pages;
		// importing a Histeon profile must not claim or validate those pages as part of this project.
		foreach ( self::snapshot( false )['entities'] as $entity ) {
			$heads[ $entity['id'] ] = $entity['revisionHash'];
			if ( 'styles' === $entity['kind'] ) {
				update_option( self::STYLES_BASE_OPTION, $entity['revisionHash'], false );
				update_option( 'xb_page_profile_styles_id', $entity['id'], false );
			} elseif ( 'asset' === $entity['kind'] ) {
				$id = self::post_by_profile_id( $entity['id'], 'attachment' );
				if ( $id instanceof \WP_Post ) {
					update_post_meta( $id->ID, self::BASE_HASH_META, $entity['revisionHash'] );
				}
			} elseif ( in_array( $entity['kind'], array( 'document', 'component', 'template-part', 'template' ), true ) ) {
				$post = self::post_by_profile_id( $entity['id'], self::entity_post_type( $entity ) );
				if ( $post instanceof \WP_Post ) {
					update_post_meta( $post->ID, self::BASE_HASH_META, $entity['revisionHash'] );
				}
			}
			if ( in_array( $entity['kind'], array( 'content-type', 'content-record', 'taxonomy', 'term', 'query-preset', 'query-source' ), true ) ) {
				ContentProfileAdapter::mark_base( $entity );
			}
		}
		$report = array(
			'operationId' => $operation_id,
			'action'      => $operation_action,
			'project'     => $manifest['project'],
			'heads'       => $heads,
			'pages'       => $pages,
			'deletions'   => $deletions,
			'components'  => $components,
			'templateParts' => $template_parts,
			'templates'   => $templates,
			'changed'     => array_values( array_unique( $changed ) ),
			'unchanged'   => array_values( array_unique( $skipped ) ),
			'media'       => count( $media_ids ),
			'content'     => array( 'records' => $content_result['records'], 'terms' => $content_result['terms'] ),
			'published'   => $published,
			'pending'     => $pending,
			'complete'    => true,
		);
		self::save_status( $operation_id, array( 'operationId' => $operation_id, 'action' => $operation_action, 'published' => $published, 'state' => 'complete', 'phase' => 'complete', 'complete' => true, 'project' => $manifest['project'], 'heads' => $heads, 'changed' => array_values( array_unique( $changed ) ), 'updatedAt' => gmdate( 'c' ), 'userId' => get_current_user_id(), 'projectId' => $operation_project_id, 'requestHash' => $request_hash, 'report' => $report ) );
		return $report;
		} catch ( \Throwable $error ) {
			$partial = get_option( 'xb_remote_operation_' . $operation_id );
			if ( is_array( $partial ) && (int) ( $partial['userId'] ?? 0 ) === get_current_user_id() ) {
				$partial['state']     = 'partial';
				$partial['updatedAt'] = gmdate( 'c' );
				$partial['failure']   = 'operation-failed';
				$partial['reason']    = substr( $error->getMessage(), 0, 400 );
				self::save_status( $operation_id, $partial );
			}
			throw $error;
		} finally {
			self::$running_operation = null;
			self::release_operation_locks( $acquired_locks, $operation_id, $request_hash );
		}
	}

	/**
	 * What an import that passed every check would do, entity by entity, without doing it.
	 *
	 * @param array<string, mixed>                $profile
	 * @param array<string, array<string, mixed>> $current
	 * @param list<array<string, mixed>>          $apply_tombstones
	 * @return array<string, mixed>
	 */
	private static function check_plan( array $profile, array $current, array $apply_tombstones ): array {
		$labels = array(
			'document'       => __( 'Pagina', 'xb' ),
			'component'      => __( 'Componente', 'xb' ),
			'template-part'  => __( 'Parte di template', 'xb' ),
			'template'       => __( 'Template', 'xb' ),
			'styles'         => __( 'Stili', 'xb' ),
			'asset'          => __( 'Media', 'xb' ),
			'content-type'   => __( 'Tipo di contenuto', 'xb' ),
			'content-record' => __( 'Record', 'xb' ),
			'taxonomy'       => __( 'Tassonomia', 'xb' ),
			'term'           => __( 'Termine', 'xb' ),
			'query-preset'   => __( 'Query', 'xb' ),
			'query-source'   => __( 'Sorgente dati', 'xb' ),
		);
		$rows = array();
		foreach ( $profile['entities'] as $entity ) {
			$kind    = (string) $entity['kind'];
			$payload = (array) $entity['payload'];
			$live    = $current[ $entity['id'] ] ?? null;
			$rows[]  = array(
				'kind'   => 'document' === $kind && 'post' === ( $payload['documentType'] ?? null ) ? __( 'Articolo', 'xb' ) : ( $labels[ $kind ] ?? $kind ),
				'label'  => (string) ( $payload['title'] ?? $payload['label'] ?? $payload['key'] ?? $payload['name'] ?? $payload['slug'] ?? '' ),
				'role'   => is_string( $payload['role'] ?? null ) ? $payload['role'] : null,
				// A page script is the one executable thing a package may carry (docs/spec/cms-bridge.md §16): named in the plan.
				'script' => is_string( $payload['script'] ?? null ) && '' !== trim( $payload['script'] ),
				'action' => null === $live ? 'crea' : ( hash_equals( (string) $live['revisionHash'], (string) $entity['revisionHash'] ) ? 'invariato' : 'aggiorna' ),
			);
		}
		// Doc 23 items 234-236: what the site needs after the import to look as in Publish, proposed on the plan.
		$site = array( 'homePage' => null, 'blogPage' => null, 'posts' => 0 );
		foreach ( $profile['entities'] as $entity ) {
			$payload = (array) $entity['payload'];
			$own_language = ! is_string( $payload['locale'] ?? null ) || strtolower( $payload['locale'] ) === strtolower( (string) ( $profile['manifest']['project']['defaultLocale'] ?? '' ) );
			if ( 'document' !== $entity['kind'] || 'published' !== ( $payload['status'] ?? null ) || ! $own_language ) {
				continue;
			}
			if ( 'post' === ( $payload['documentType'] ?? null ) ) {
				++$site['posts'];
			} elseif ( in_array( $payload['slug'] ?? null, array( 'home', 'blog' ), true ) ) {
				$site[ 'home' === $payload['slug'] ? 'homePage' : 'blogPage' ] = array( 'id' => (string) $entity['id'], 'title' => (string) ( $payload['title'] ?? '' ) );
			}
		}
		return array(
			'check'     => true,
			'project'   => $profile['manifest']['project'],
			'profile'   => $profile['manifest']['profile'],
			'entities'  => $rows,
			'deletions' => count( $apply_tombstones ),
			'site'      => $site,
		);
	}

	/**
	 * The development copy of a Histeon document (docs/spec/cms-bridge.md §4): what the builder is editing.
	 *
	 * @return array<string, mixed>
	 */
	public static function dev_payload( string $doc ): array {
		return \XB\Plugin::instance()->versioning->working_copy( $doc )['payload'];
	}

	/** A post's content as in development, falling back to the live content when it is not a Histeon document. */
	/**
	 * The blocks of the versions of a document this site has kept: a rollback may bring back a block removed since.
	 *
	 * @return array<array<string, mixed>>
	 */
	private static function earlier_blocks( \WP_Post $post ): array {
		$store  = \XB\Plugin::instance()->versioning->store();
		$blocks = array();
		foreach ( $store->history( Documents::for_post( $post->ID ), 100 ) as $version ) {
			$content = $store->get( $version['id'] )['payload']['content'] ?? null;
			if ( is_string( $content ) ) {
				array_push( $blocks, ...parse_blocks( $content ) );
			}
		}
		return $blocks;
	}

	private static function dev_content( \WP_Post $post ): string {
		if ( ! in_array( $post->post_type, Documents::POST_TYPES, true ) ) {
			return $post->post_content;
		}
		$content = self::dev_payload( Documents::for_post( $post->ID ) )['content'] ?? null;
		return is_string( $content ) ? $content : $post->post_content;
	}

	/**
	 * A page's, post's or component's JavaScript as in development (doc 18 §6.6 of Histeon Publish): what a transfer
	 * carries next to the development content.
	 */
	private static function dev_script( \WP_Post $post ): string {
		if ( in_array( $post->post_type, Documents::POST_TYPES, true ) ) {
			$script = self::dev_payload( Documents::for_post( $post->ID ) )['script'] ?? null;
			if ( is_string( $script ) ) {
				return $script;
			}
		}
		return \XB\Scripts\Scripts::get( $post->ID );
	}

	/** A component's props schema as in development. */
	private static function dev_schema( \WP_Post $post ): mixed {
		if ( 'wp_block' === $post->post_type ) {
			$schema = self::dev_payload( Documents::for_post( $post->ID ) )['schema'] ?? null;
			if ( is_array( $schema ) ) {
				return $schema;
			}
		}
		return json_decode( (string) get_post_meta( $post->ID, ComponentRegistry::META_SCHEMA, true ), true );
	}

	/**
	 * Merges changes into a document's development copy as one commit, with the linked administrator's trust. When
	 * `$expected_content` is given, the stored content must be exactly it: sanitizing that would change it stops the
	 * operation (it stays incomplete, and only in development).
	 *
	 * @param array<string, mixed> $changes
	 */
	private static function save_dev( string $doc, array $changes, ?string $expected_content = null ): void {
		$versioning = \XB\Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( $doc );
		$next       = array_merge( $copy['payload'], $changes );
		try {
			$commit = $versioning->save_dev( $doc, $next, $copy['head'], $copy['base'], __( 'Invio da Histeon CMS', 'xb' ) );
		} catch ( \XB\Versioning\VersioningException $e ) {
			throw new \RuntimeException( sprintf( /* translators: 1: document, 2: error message */ __( '%1$s non salvato in sviluppo: %2$s', 'xb' ), Documents::label( $doc ), $e->getMessage() ), 0, $e );
		}
		if ( null !== $expected_content && ( $commit['payload']['content'] ?? null ) !== $expected_content ) {
			throw new \RuntimeException( sprintf( /* translators: %s: document */ __( 'Il sito modificherebbe il contenuto di %s: l\'invio resta incompleto, solo in sviluppo.', 'xb' ), Documents::label( $doc ) ) );
		}
	}

	/**
	 * The settings an import brings, checked with the validators the release import uses. Any refusal stops the
	 * import before it writes.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private static function validated_settings( array $settings ): array {
		$validators = array(
			'design'  => static fn ( mixed $v ): array => \XB\Framework\HistosCss\Design::changes( \XB\Framework\HistosCss\Design::validate( $v ) ),
			'tokens'  => static fn ( mixed $v ): array => \XB\Framework\TokenOverrides::validate( $v ),
			'queries' => static fn ( mixed $v ): array => \XB\Styles\NamedQueries::validate( $v ),
			'content' => static fn ( mixed $v ): array => ContentTypes::validate( $v ),
			'presets' => static fn ( mixed $v ): array => \XB\Render\Queries::validate( $v ),
		);
		$clean = array();
		try {
			foreach ( $validators as $key => $validate ) {
				if ( array_key_exists( $key, $settings ) ) {
					$clean[ $key ] = $validate( $settings[ $key ] );
				}
			}
			if ( array_key_exists( 'sources', $settings ) ) {
				$presets          = $clean['presets'] ?? ( self::dev_payload( Documents::SETTINGS )['presets'] ?? array() );
				$clean['sources'] = \XB\Render\Sources::validate( $settings['sources'], array_map( 'strval', array_keys( is_array( $presets ) ? $presets : array() ) ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			throw new \DomainException( sprintf( /* translators: %s: error message */ __( 'Le impostazioni inviate non sono valide qui: %s', 'xb' ), $e->getMessage() ), 0, $e );
		}
		if ( ( array_key_exists( 'content', $clean ) ) && ! current_user_can( 'manage_options' ) ) {
			throw new \DomainException( __( 'Solo un amministratore può importare tipi di contenuto.', 'xb' ) );
		}
		return $clean;
	}

	/**
	 * A new post for an incoming document: always a draft, with its identity; the content follows in development.
	 *
	 * @param array<string, mixed> $entity
	 */
	private static function create_profile_post( array $entity, string $type ): int {
		$payload = $entity['payload'];
		$postarr = array(
			'post_type'    => $type,
			'post_title'   => (string) $payload['title'],
			'post_content' => '',
			'post_status'  => 'draft',
		);
		if ( in_array( $type, self::DOCUMENT_TYPES, true ) ) {
			$postarr['post_name'] = (string) $payload['slug'];
		} elseif ( 'wp_template' === $type ) {
			$postarr['post_name'] = self::profile_template_slug( (string) $payload['ref'], $payload['role'] ?? null );
			// Another project template still holds the role's slug (it gives it up in this send): this one takes it
			// when the fields are published, after the other has let it go.
			if ( array() !== self::theme_templates_named( $postarr['post_name'] ) ) {
				$postarr['post_name'] = self::profile_template_slug( (string) $payload['ref'] );
			}
		} elseif ( 'wp_template_part' === $type ) {
			$postarr['post_name'] = self::profile_template_slug( (string) $payload['ref'] );
		}
		if ( 'post' === $type ) {
			$postarr['post_excerpt'] = (string) $payload['excerpt'];
		}
		// Marked until its content is saved: a send interrupted in between finds and finishes it, and the profile
		// never shows the empty post.
		$postarr['meta_input'] = array(
			self::PAGE_ID_META  => $entity['id'],
			self::CREATING_META => '1',
		);
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s: error message */ __( 'Documento non creato: %s', 'xb' ), $post_id->get_error_message() ) );
		}
		$post_id = (int) $post_id;
		$created = get_post( $post_id );
		if ( 'wp_template' === $type && ( ! $created instanceof \WP_Post || $created->post_name !== ( $postarr['post_name'] ?? '' ) ) ) {
			throw new \RuntimeException( __( 'WordPress non ha dato al template lo slug del suo ruolo: l\'invio resta incompleto.', 'xb' ) );
		}
		self::save_transfer_status_mode( $post_id, $payload, 'draft' );
		if ( ! in_array( $type, self::DOCUMENT_TYPES, true ) ) {
			wp_set_post_terms( $post_id, array( get_stylesheet() ), 'wp_theme' );
		}
		if ( in_array( $entity['kind'], array( 'component', 'template-part' ), true ) ) {
			update_post_meta( $post_id, ComponentRegistry::META_KEY, $payload['ref'] );
			update_post_meta( $post_id, ComponentRegistry::META_SCHEMA, wp_slash( (string) wp_json_encode( $payload['schema'] ) ) );
		}
		if ( 'template-part' === $entity['kind'] ) {
			wp_set_post_terms( $post_id, array( $payload['area'] ), 'wp_template_part_area' );
		}
		if ( in_array( $entity['kind'], array( 'template-part', 'template' ), true ) ) {
			update_post_meta( $post_id, self::TEMPLATE_REF_META, $payload['ref'] );
			update_post_meta( $post_id, self::MANAGED_TEMPLATE_META, '1' );
		}
		return $post_id;
	}

	/** A post created by a send that has not saved its content yet (the send was interrupted). */
	private static function being_created( \WP_Post $post ): bool {
		return '' !== (string) get_post_meta( $post->ID, self::CREATING_META, true );
	}

	/**
	 * @param array<string, string> $template_refs_by_ref Template ref → profile id.
	 */
	private static function assign_page_template( int $post_id, mixed $template_ref, array $template_refs_by_ref ): void {
		if ( is_string( $template_ref ) ) {
			$template_id   = $template_refs_by_ref[ $template_ref ] ?? null;
			$template_post = is_string( $template_id ) ? self::post_by_profile_id( $template_id, 'wp_template' ) : null;
			if ( ! $template_post instanceof \WP_Post ) {
				throw new \RuntimeException( __( 'Il template della pagina non è stato importato: l\'invio resta incompleto.', 'xb' ) );
			}
			update_post_meta( $post_id, '_wp_page_template', $template_post->post_name );
			update_post_meta( $post_id, self::TEMPLATE_ASSIGNMENT_META, $template_ref );
		} elseif ( '' !== (string) get_post_meta( $post_id, self::TEMPLATE_ASSIGNMENT_META, true ) ) {
			delete_post_meta( $post_id, '_wp_page_template' );
			delete_post_meta( $post_id, self::TEMPLATE_ASSIGNMENT_META );
		}
	}

	/**
	 * The status a document should have once published: what the CMS asks, published when it does not say.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function wanted_status( array $payload ): string {
		return 'published' === ( $payload['status'] ?? 'published' ) ? 'publish' : 'draft';
	}

	/**
	 * The fields of a document that change only with a release (title, status; slug and page template for pages),
	 * as the CMS sent them.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function document_fields( string $kind, array $payload ): array {
		$fields = array( 'kind' => $kind, 'title' => (string) $payload['title'] );
		if ( array_key_exists( 'status', $payload ) ) {
			$fields['status'] = 'published' === $payload['status'] ? 'published' : 'draft';
		}
		if ( 'document' === $kind ) {
			$fields['slug']        = (string) $payload['slug'];
			$fields['templateRef'] = is_string( $payload['templateRef'] ?? null ) ? $payload['templateRef'] : null;
		}
		if ( 'document' === $kind && 'post' === ( $payload['documentType'] ?? null ) ) {
			$fields['documentType'] = 'post';
			$fields['excerpt']      = (string) $payload['excerpt'];
			$fields['publishedAt']  = is_string( $payload['publishedAt'] ) ? $payload['publishedAt'] : null;
			$fields['terms']        = (array) $payload['terms'];
			$fields['image']        = is_string( $payload['image'] ?? null ) ? $payload['image'] : null;
		}
		if ( 'template' === $kind ) {
			$fields['role'] = is_string( $payload['role'] ?? null ) ? $payload['role'] : null;
			$fields['ref']  = (string) $payload['ref'];
		}
		return $fields;
	}

	/**
	 * Keeps the fields the CMS sent on the post until a release applies them. Returns whether any differs from the
	 * live post (otherwise nothing waits).
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function stage_document_fields( int $post_id, string $kind, array $payload ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$fields  = self::document_fields( $kind, $payload );
		$differs = $post->post_title !== $fields['title'] || $post->post_status !== self::wanted_status( $payload );
		if ( 'document' === $kind ) {
			$differs = $differs || $post->post_name !== $fields['slug'] || (string) get_post_meta( $post_id, self::TEMPLATE_ASSIGNMENT_META, true ) !== (string) ( $fields['templateRef'] ?? '' );
		}
		if ( 'post' === $post->post_type ) {
			$live    = self::live_fields( $post );
			$differs = $differs || $live['excerpt'] !== $fields['excerpt'] || ( 'publish' === self::wanted_status( $payload ) && $live['date'] !== $fields['publishedAt'] ) || self::terms_differ( $post, $fields['terms'] ) || $live['image'] !== self::wanted_thumbnail( $fields['image'] );
		}
		if ( 'template' === $kind ) {
			$differs = $differs || $post->post_name !== self::wanted_template_slug( $post, $fields['ref'], $fields['role'] );
		}
		if ( $differs ) {
			$fields['live'] = self::live_fields( $post );
			update_post_meta( $post_id, self::PENDING_FIELDS_META, wp_slash( (string) wp_json_encode( $fields ) ) );
		} else {
			delete_post_meta( $post_id, self::PENDING_FIELDS_META );
		}
		return $differs;
	}

	/**
	 * The live fields a waiting change was staged over: if WordPress changes them afterwards, the waiting change is
	 * stale and never covers nor replaces that edit.
	 *
	 * @return array<string, mixed>
	 */
	private static function live_fields( \WP_Post $post ): array {
		$fields = array(
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'slug'     => $post->post_name,
			'template' => (string) get_post_meta( $post->ID, self::TEMPLATE_ASSIGNMENT_META, true ),
		);
		// Posts also carry excerpt, date and terms; pages keep the same shape as before.
		if ( 'post' === $post->post_type ) {
			$fields['excerpt'] = $post->post_excerpt;
			$fields['date']    = 'publish' === $post->post_status ? self::utc_second( $post ) : null;
			// WordPress term ids: an export that gives a term its project identity is not an edit.
			$fields['terms']   = self::canonical_terms( ContentProfileAdapter::post_term_ids( $post ) );
			$fields['image']   = (int) get_post_thumbnail_id( $post );
		}
		return $fields;
	}

	/** The attachment a post's `image` names (doc 23 item 119), 0 for none or one missing from WordPress. */
	private static function wanted_thumbnail( mixed $image ): int {
		$attachment = is_string( $image ) ? self::post_by_profile_id( $image, 'attachment' ) : null;
		return $attachment instanceof \WP_Post ? $attachment->ID : 0;
	}

	/**
	 * Whether a post's terms are not the ones the CMS sent. In WordPress term ids: the default category WordPress gives
	 * a new post has no project identity yet, and it counts too.
	 *
	 * @param array<string, mixed> $sent
	 */
	private static function terms_differ( \WP_Post $post, array $sent ): bool {
		$wanted = ContentProfileAdapter::wordpress_term_ids( $sent );
		return null === $wanted || PageProfile::canonical( self::canonical_terms( ContentProfileAdapter::post_term_ids( $post ) ) ) !== PageProfile::canonical( self::canonical_terms( array_filter( $wanted ) ) );
	}

	/**
	 * Terms in one order, to compare them.
	 *
	 * @param array<string, mixed> $terms
	 * @return array<string, list<string>>
	 */
	private static function canonical_terms( array $terms ): array {
		$out = array();
		foreach ( $terms as $taxonomy => $ids ) {
			$ids = array_values( array_map( 'strval', (array) $ids ) );
			sort( $ids );
			if ( array() !== $ids ) {
				$out[ (string) $taxonomy ] = $ids;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The portable slug of a post (`^[a-z0-9]+(?:-[a-z0-9]+)*$`). A draft without a usable one gets it from its
	 * title; a published post whose address cannot travel stops the download instead of changing its address.
	 */
	private static function portable_post_slug( \WP_Post $post ): string {
		if ( strlen( $post->post_name ) <= 200 && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $post->post_name ) ) {
			return $post->post_name;
		}
		if ( 'publish' === $post->post_status ) {
			throw new \DomainException( 'L\'articolo «' . $post->post_title . '» ha un indirizzo (' . $post->post_name . ') che il profilo non può trasferire: usa solo lettere latine minuscole, cifre e trattini.' );
		}
		$slug = strtolower( remove_accents( urldecode( '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title ) ) ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );
		$slug = rtrim( substr( $slug, 0, 200 ), '-' );
		return '' !== $slug ? $slug : 'articolo-' . $post->ID;
	}

	/** The publication instant of a post, as the profile writes it. */
	private static function utc_second( \WP_Post $post ): string {
		$stamp = strtotime( (string) $post->post_date_gmt . ' UTC' );
		return gmdate( 'Y-m-d\\TH:i:s\\Z', false !== $stamp && $stamp > 0 ? $stamp : time() );
	}

	/**
	 * The fields waiting for a release on a post, if they still apply to it as it is live.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function pending_fields( \WP_Post $post ): ?array {
		$fields = json_decode( (string) get_post_meta( $post->ID, self::PENDING_FIELDS_META, true ), true );
		if ( ! is_array( $fields ) || ! is_string( $fields['kind'] ?? null ) || ! is_string( $fields['title'] ?? null ) ) {
			return null;
		}
		$live = self::live_fields( $post );
		// Staged by a plugin before doc 23 item 119: its live fields have no image, and it leaves the image alone.
		if ( is_array( $fields['live'] ?? null ) && ! array_key_exists( 'image', $fields['live'] ) ) {
			unset( $live['image'] );
		}
		return ( $fields['live'] ?? null ) === $live ? $fields : null;
	}

	/**
	 * The profile shows a document as it is in development: with the fields still waiting for a release.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function with_pending_fields( \WP_Post $post, array $payload ): array {
		$fields = self::pending_fields( $post );
		if ( null === $fields ) {
			return $payload;
		}
		$payload['title'] = $fields['title'];
		if ( is_string( $fields['status'] ?? null ) ) {
			$payload['status'] = $fields['status'];
		}
		if ( array_key_exists( 'slug', $payload ) && is_string( $fields['slug'] ?? null ) ) {
			$payload['slug'] = $fields['slug'];
		}
		if ( 'document' === $fields['kind'] && array_key_exists( 'templateRef', $fields ) ) {
			if ( is_string( $fields['templateRef'] ) ) {
				$payload['templateRef'] = $fields['templateRef'];
			} else {
				unset( $payload['templateRef'] );
			}
		}
		if ( 'post' === ( $payload['documentType'] ?? null ) && 'post' === ( $fields['documentType'] ?? null ) ) {
			$payload['excerpt']     = (string) $fields['excerpt'];
			$payload['terms']       = (array) $fields['terms'];
			$payload['publishedAt'] = 'published' === $payload['status'] ? ( is_string( $fields['publishedAt'] ) ? $fields['publishedAt'] : ( $payload['publishedAt'] ?? self::utc_second( $post ) ) ) : null;
			if ( array_key_exists( 'image', $fields ) ) {
				if ( is_string( $fields['image'] ) ) {
					$payload['image'] = $fields['image'];
				} else {
					unset( $payload['image'] );
				}
			}
		}
		if ( 'template' === $fields['kind'] && array_key_exists( 'role', $fields ) ) {
			if ( is_string( $fields['role'] ) ) {
				$payload['role'] = $fields['role'];
			} else {
				unset( $payload['role'] );
			}
		}
		return $payload;
	}

	/**
	 * A Histeon release went online (xb_release_published): documents with no unpublished content left take the
	 * title, slug, status and page template waiting for them, unless WordPress changed those since the send.
	 *
	 * @param list<string> $docs
	 */
	public static function apply_pending_fields( int $release_id, array $docs = array() ): void {
		self::no_excerpt_filter();
		$posts = get_posts(
			array(
				'post_type'        => array( 'page', 'post', 'wp_block', 'wp_template', 'wp_template_part' ),
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => self::PENDING_FIELDS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only posts sent by Histeon CMS carry it.
				'suppress_filters' => true,
			)
		);
		if ( array() === $posts ) {
			return;
		}
		$unpublished = array_column( \XB\Plugin::instance()->versioning->changes(), 'doc' );
		try {
			$releasing = array();
			foreach ( $posts as $post_id ) {
				$post   = get_post( (int) $post_id );
				$fields = $post instanceof \WP_Post && 'wp_template' === $post->post_type ? self::pending_fields( $post ) : null;
				if ( null !== $fields && ! in_array( Documents::for_post( (int) $post_id ), $unpublished, true ) ) {
					$releasing[ (int) $post_id ] = $fields;
				}
			}
			self::release_role_slugs( $releasing );
			foreach ( $posts as $post_id ) {
				$post   = get_post( (int) $post_id );
				// A template that just let its role's slug go was checked before the move.
				$fields = $releasing[ (int) $post_id ] ?? ( $post instanceof \WP_Post ? self::pending_fields( $post ) : null );
				if ( null === $fields ) {
					// Changed in WordPress after the send: that edit wins, the CMS sees it at its next download.
					delete_post_meta( (int) $post_id, self::PENDING_FIELDS_META );
					\XB\Plugin::instance()->versioning->store()->audit( 'cms_queue_stale', 'post:' . (int) $post_id, array( 'release' => $release_id ) );
					continue;
				}
				if ( in_array( Documents::for_post( (int) $post_id ), $unpublished, true ) ) {
					continue;
				}
				self::publish_document_fields( (int) $post_id, $fields['kind'], $fields, self::template_refs() );
			}
		} catch ( \Throwable $e ) {
			\XB\Plugin::instance()->versioning->store()->audit( 'cms_queue_failed', 'release:' . $release_id, array( 'error' => mb_substr( $e->getMessage(), 0, 300 ) ) );
		}
	}

	/** @return array<string, string> Template ref → profile id, for every template the project owns. */
	private static function template_refs(): array {
		$refs = array();
		foreach ( get_posts( array( 'post_type' => 'wp_template', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => self::TEMPLATE_REF_META, 'suppress_filters' => true ) ) as $template ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$ref = (string) get_post_meta( $template->ID, self::TEMPLATE_REF_META, true );
			$id  = (string) get_post_meta( $template->ID, self::PAGE_ID_META, true );
			if ( '' !== $ref && '' !== $id ) {
				$refs[ $ref ] = $id;
			}
		}
		return $refs;
	}

	/**
	 * After the release: the document's title, slug, status and page template as the CMS sent them.
	 *
	 * @param array<string, mixed>  $payload
	 * @param array<string, string> $template_refs_by_ref
	 */
	private static function publish_document_fields( int $post_id, string $kind, array $payload, array $template_refs_by_ref ): void {
		$status  = self::wanted_status( $payload );
		$postarr = array(
			'ID'          => $post_id,
			'post_title'  => (string) $payload['title'],
			'post_status' => $status,
		);
		$post_terms = null;
		$before     = get_post( $post_id );
		$old_slug   = $before instanceof \WP_Post ? $before->post_name : '';
		if ( 'document' === $kind ) {
			$postarr['post_name'] = (string) $payload['slug'];
			// wp_update_post() re-validates the stored page template against the theme; assign_page_template() sets it.
			$postarr['page_template'] = '';
		}
		if ( 'document' === $kind && 'post' === get_post_type( $post_id ) ) {
			// The terms first: an article goes online with its categories, or waits for the next release.
			$post_terms = ContentProfileAdapter::wordpress_term_ids( (array) ( $payload['terms'] ?? array() ) );
			if ( null === $post_terms ) {
				\XB\Plugin::instance()->versioning->store()->audit( 'cms_queue_failed', 'post:' . $post_id, array( 'error' => 'terms-not-ready' ) );
				return;
			}
			$postarr['post_excerpt'] = (string) ( $payload['excerpt'] ?? '' );
			if ( 'publish' === $status && is_string( $payload['publishedAt'] ?? null ) ) {
				$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( $payload['publishedAt'] ) );
				$postarr['post_date']     = get_date_from_gmt( $postarr['post_date_gmt'] );
				$postarr['edit_date']     = true;
			}
		}
		// Fields staged by an older plugin say nothing about the role: the slug stays.
		$template_post = 'template' === $kind && is_string( $payload['ref'] ?? null ) && array_key_exists( 'role', $payload ) ? get_post( $post_id ) : null;
		if ( $template_post instanceof \WP_Post ) {
			$postarr['post_name'] = self::wanted_template_slug( $template_post, (string) $payload['ref'], $payload['role'] );
		}
		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s: error message */ __( 'Documento non pubblicato: %s', 'xb' ), $result->get_error_message() ) );
		}
		self::save_transfer_status_mode( $post_id, $payload, $status );
		if ( 'document' === $kind ) {
			self::assign_page_template( $post_id, $payload['templateRef'] ?? null, $template_refs_by_ref );
		}
		if ( null !== $post_terms ) {
			foreach ( ContentProfileAdapter::post_taxonomy_keys() as $taxonomy ) {
				$assigned = wp_set_object_terms( $post_id, $post_terms[ $taxonomy ] ?? array(), $taxonomy, false );
				if ( is_wp_error( $assigned ) ) {
					throw new \RuntimeException( sprintf( /* translators: 1: taxonomy, 2: error message */ __( 'Tassonomia «%1$s» non assegnata: %2$s', 'xb' ), $taxonomy, $assigned->get_error_message() ) );
				}
			}
			// Doc 23 item 119: the featured image as the CMS sent it; fields staged before it say nothing about it.
			if ( array_key_exists( 'image', $payload ) ) {
				$thumbnail = self::wanted_thumbnail( $payload['image'] );
				if ( $thumbnail > 0 ) {
					set_post_thumbnail( $post_id, $thumbnail );
				} elseif ( null === $payload['image'] ) {
					delete_post_thumbnail( $post_id );
				}
			}
		}
		if ( 'template' === $kind ) {
			$after    = get_post( $post_id );
			$new_slug = $after instanceof \WP_Post ? $after->post_name : '';
			if ( '' !== $new_slug && $new_slug !== $old_slug ) {
				self::follow_template_slug( $post_id, $new_slug );
			}
		}
		delete_post_meta( $post_id, self::PENDING_FIELDS_META );
	}

	/** A template took another slug (its role changed): the documents assigned to it follow. */
	private static function follow_template_slug( int $template_id, string $slug ): void {
		$ref = (string) get_post_meta( $template_id, self::TEMPLATE_REF_META, true );
		if ( '' === $ref ) {
			return;
		}
		$assigned = get_posts( array( 'post_type' => self::DOCUMENT_TYPES, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => self::TEMPLATE_ASSIGNMENT_META, 'meta_value' => $ref, 'suppress_filters' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- documents assigned to a Histeon template only.
		foreach ( $assigned as $document_id ) {
			update_post_meta( (int) $document_id, '_wp_page_template', $slug );
		}
	}

	/**
	 * A first import can deliberately store a requested published page or CMS record as a draft when this
	 * connection cannot publish. Treat that exact projection as the same revision on a fresh recovery plan.
	 * CMS records must retain the original base marker; for pages, identity plus exact payload is enough because
	 * their base marker is written only after the page phase finishes. Any local edit still conflicts.

	 * @param array<string, mixed> $incoming
	 * @param array<string, mixed> $current
	 */
	private static function matches_unpublished_content_projection( array $incoming, array $current, bool $published ): bool {
		$kind = $incoming['kind'] ?? null;
		if ( $published || ! in_array( $kind, array( 'content-record', 'document' ), true ) || $kind !== ( $current['kind'] ?? null ) || ( $incoming['id'] ?? null ) !== ( $current['id'] ?? null ) || 'published' !== ( $incoming['payload']['status'] ?? null ) || 'draft' !== ( $current['payload']['status'] ?? null ) ) {
			return false;
		}
		$stored_base = $current['baseHash'] ?? null;
		$incoming_hash = (string) ( $incoming['revisionHash'] ?? '' );
		if ( 'content-record' === $kind && ( ! is_string( $stored_base ) || ! hash_equals( $incoming_hash, $stored_base ) ) ) {
			return false;
		}
		if ( 'document' === $kind && is_string( $stored_base ) && '' !== $stored_base && ! hash_equals( $incoming_hash, $stored_base ) ) {
			return false;
		}
		if ( 'content-record' === $kind && null !== ( $current['payload']['publishedAt'] ?? null ) ) {
			return false;
		}
		$expected_payload = $incoming['payload'];
		$expected_payload['status'] = 'draft';
		if ( 'content-record' === $kind ) {
			$expected_payload['publishedAt'] = null;
		}
		return PageProfile::canonical( $expected_payload ) === PageProfile::canonical( $current['payload'] ?? null );
	}

	/** How long a finished operation's record stays: Publish asks about an operation, or sends it again, within minutes. */
	public const OPERATION_RETENTION_DAYS = 30;

	/**
	 * Deletes the records of operations that ended (complete, partial, failed) more than OPERATION_RETENTION_DAYS ago,
	 * and locks left by an interrupted operation (lista della spesa 153): each record keeps the import's report, so
	 * they grew without end (one site had 68 of them, the largest 333 KB). Waits for migrate_imported_markers(),
	 * which still reads them. Runs with the daily cleanup, in batches; returns how many it deleted.
	 */
	public static function prune_operations( int $days = self::OPERATION_RETENTION_DAYS, int $limit = 500 ): int {
		if ( self::IMPORTED_MIGRATION_VERSION !== get_option( self::IMPORTED_MIGRATION_OPTION ) ) {
			return 0;
		}
		global $wpdb;
		$cutoff  = time() - $days * DAY_IN_SECONDS;
		$deleted = 0;
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $wpdb->esc_like( 'xb_remote_operation_' ) . '%', max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$name  = (string) $row['option_name'];
			$value = maybe_unserialize( (string) $row['option_value'] );
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( str_starts_with( $name, 'xb_remote_operation_lock_' ) ) {
				$at  = (int) ( $value['updatedAt'] ?? $value['createdAt'] ?? 0 );
				$old = 0 < $at && $at <= time() - max( self::lock_ttl(), DAY_IN_SECONDS );
			} else {
				$at  = strtotime( (string) ( $value['updatedAt'] ?? '' ) );
				$old = 'applying' !== ( $value['state'] ?? null ) && false !== $at && $at < $cutoff;
			}
			if ( $old && delete_option( $name ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/** @return array<string, mixed>|null */
	public static function status( string $operation_id, int $user_id ): ?array {
		if ( ! self::valid_uuid( $operation_id ) ) {
			return null;
		}
		$status = get_option( 'xb_remote_operation_' . $operation_id );
		if ( ! is_array( $status ) || (int) ( $status['userId'] ?? 0 ) !== $user_id ) {
			return null;
		}
		if ( 'applying' === ( $status['state'] ?? null ) ) {
			$lock_key = 'xb_remote_operation_lock_' . $operation_id;
			$lock = get_option( $lock_key );
			$last_progress = is_array( $lock ) ? (int) ( $lock['updatedAt'] ?? $lock['createdAt'] ?? 0 ) : 0;
			if ( 0 < $last_progress && $last_progress <= time() - self::lock_ttl() ) {
				delete_option( $lock_key );
				$project_lock_key = 'xb_remote_project_lock_' . (string) ( $status['projectId'] ?? '' );
				$project_lock = get_option( $project_lock_key );
				if ( is_array( $project_lock ) && ( $project_lock['operationId'] ?? null ) === $operation_id ) {
					delete_option( $project_lock_key );
				}
				$status['state'] = 'partial';
				$status['phase'] = 'interrupted';
				$status['updatedAt'] = gmdate( 'c' );
				$status['failure'] = 'operation-interrupted';
				update_option( 'xb_remote_operation_' . $operation_id, $status, false );
			}
		}
		unset( $status['userId'], $status['requestHash'], $status['report'] );
		return $status;
	}

	/** @param array<string, mixed> $status */
	private static function save_status( string $operation_id, array $status ): void {
		$key = 'xb_remote_operation_' . $operation_id;
		$previous = get_option( $key );
		if ( is_array( $previous ) ) {
			$status = array_merge( $previous, $status );
		}
		update_option( $key, $status, false );
		foreach ( array( 'xb_remote_operation_lock_' . $operation_id, 'xb_remote_project_lock_' . (string) ( $status['projectId'] ?? '' ) ) as $lock_key ) {
			$lock = get_option( $lock_key );
			if ( is_array( $lock ) && (int) ( $lock['userId'] ?? 0 ) === get_current_user_id() && is_string( $status['requestHash'] ?? null ) && hash_equals( (string) ( $lock['requestHash'] ?? '' ), $status['requestHash'] ) ) {
				$lock['updatedAt'] = time();
				update_option( $lock_key, $lock, false );
			}
		}
	}

	/** @param list<string> $lock_keys */
	private static function release_operation_locks( array $lock_keys, string $operation_id, string $request_hash ): void {
		foreach ( $lock_keys as $lock_key ) {
			$lock = get_option( $lock_key );
			if ( is_array( $lock ) && ( $lock['operationId'] ?? null ) === $operation_id && (int) ( $lock['userId'] ?? 0 ) === get_current_user_id() && is_string( $lock['requestHash'] ?? null ) && hash_equals( $lock['requestHash'], $request_hash ) ) {
				delete_option( $lock_key );
			}
		}
	}

	/**
	 * V5.2, «Importa una pagina di questo sito»: what the site has today, for one page converted in the browser and added
	 * to it (`AddPageBase` of Histeon Publish's converter, `packages/cms/browser/add-page.ts`). Read as import() reads the
	 * live site before a send (snapshot( false, true )), so the styles entity and its revision are the ones import()
	 * compares the package with: a package built on this base passes, one built on an older base is a conflict.
	 * Nothing is written. The media bytes are only those of the media the styles name (the merged styles carry them).
	 *
	 * @return array{projectId: string, styles: array<string, mixed>, slugs: list<string>, entityIds: list<string>, assets: list<array<string, mixed>>, files: array<string, string>}
	 * @throws \DomainException When the site has no Histeon project, or no global styles yet.
	 * @throws \RuntimeException When a media file of the site cannot be read.
	 */
	public static function add_page_base(): array {
		global $wpdb;
		$project_id = (string) get_option( self::PROJECT_ID_OPTION, '' );
		if ( ! self::valid_uuid( $project_id ) || self::can_initialize_project() ) {
			throw new \DomainException( __( 'Questo sito non ha ancora un progetto Histeon a cui aggiungere la pagina. Se ha già pagine fatte con Histeon, scarica una volta il progetto («Scarica il progetto per Histeon Publish»); se non ne ha, converti la prima pagina con «Converti»: diventa la home del progetto.', 'xb' ) );
		}
		$snapshot = self::snapshot( false, true );
		$styles   = null;
		$assets   = array();
		$ids      = array();
		foreach ( $snapshot['entities'] as $entity ) {
			$ids[] = (string) $entity['id'];
			if ( 'styles' === $entity['kind'] ) {
				$styles = $entity;
			} elseif ( 'asset' === $entity['kind'] ) {
				$assets[] = $entity;
			}
		}
		if ( null === $styles ) {
			throw new \DomainException( __( 'Gli stili globali di questo sito non hanno ancora un’identità di progetto: scarica una volta il progetto («Scarica il progetto per Histeon Publish»), poi riprova.', 'xb' ) );
		}
		$paths = array();
		foreach ( $assets as $asset ) {
			$paths[ $asset['id'] ] = (string) $asset['payload']['path'];
		}
		$files = array();
		foreach ( array_merge( $styles['payload']['styles'], $styles['payload']['globals'] ) as $rule ) {
			foreach ( (array) ( $rule['assets'] ?? array() ) as $mapping ) {
				$path = $paths[ $mapping['assetId'] ?? '' ] ?? null;
				if ( null !== $path && isset( $snapshot['files'][ $path ] ) ) {
					$files[ $path ] = $snapshot['files'][ $path ];
				}
			}
		}
		// Every page's slug, of Histeon or not, in any state: import() refuses a slug another page has.
		$slugs = array_values( array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name <> ''" ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array(
			'projectId' => $project_id,
			'styles'    => $styles,
			'slugs'     => $slugs,
			'entityIds' => array_values( array_unique( $ids ) ),
			'assets'    => $assets,
			'files'     => $files,
		);
	}

	/** Whether a page is already a page of Histeon (built with its blocks, or linked to its project). */
	public static function is_histeon_page( \WP_Post $post ): bool {
		return self::is_profile_page( $post );
	}

	/** @return array{manifest: array<string, mixed>, entities: list<array<string, mixed>>, references: list<array<string, mixed>>, files: array<string, string>} */
	private static function snapshot( bool $ensure_identities = true, bool $allow_empty_project = false ): array {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'publish', 'trash' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$profile_pages = array_values( array_filter( $pages, static fn ( \WP_Post $post ): bool => self::is_profile_page( $post ) ) );
		// Histeon posts, as Histeon pages (docs/spec/cms-bridge.md §12): the ones written in the block editor stay out.
		$profile_posts = array_values(
			array_filter(
				get_posts(
					array(
						'post_type'      => 'post',
						'post_status'    => array( 'draft', 'publish', 'trash' ),
						'posts_per_page' => -1,
						'orderby'        => 'ID',
						'order'          => 'ASC',
					)
				),
				static fn ( \WP_Post $post ): bool => self::is_profile_page( $post )
			)
		);
		if ( array() === $profile_pages && ! $allow_empty_project ) {
			throw new \DomainException( 'Il sito WordPress non contiene ancora pagine Histeon da scaricare. Crea una pagina con il builder Histeon oppure invia prima un progetto da Histeon CMS.' );
		}
		$project_id = (string) get_option( self::PROJECT_ID_OPTION, '' );
		if ( ! self::valid_uuid( $project_id ) ) {
			if ( ! $ensure_identities ) {
				throw new \DomainException( 'Download the current Histeon project once before applying updates to it.' );
			}
			$project_id = self::derived_id( 'project' );
			self::later( static fn () => update_option( self::PROJECT_ID_OPTION, $project_id, false ) );
		}
		$transfer_query_presets = ProfileValidator::transfer_query_presets_from_wordpress();
		$project_name = (string) get_option( self::PROJECT_NAME_OPTION, get_bloginfo( 'name' ) );
		/** @var list<array<string, mixed>> $entities */
		$entities = array();
		/** @var list<array<string, mixed>> $references */
		$references = array();
		/** @var array<string, string> $files */
		$files = array();
		$component_posts = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => array( 'draft', 'publish', 'private', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => ComponentRegistry::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery -- Histeon components only.
				'meta_compare'   => 'EXISTS',
			)
		);
		/** @var array<string, string> $component_ids_by_ref */
		$component_ids_by_ref = array();
		/** @var list<\WP_Post> $eligible_components */
		$eligible_components = array();
		foreach ( $component_posts as $component_post ) {
			$ref = (string) get_post_meta( $component_post->ID, ComponentRegistry::META_KEY, true );
			if ( 1 !== preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $ref ) ) {
				throw new \DomainException( 'A Histeon component has an invalid component key.' );
			}
			$stored_id = (string) get_post_meta( $component_post->ID, self::PAGE_ID_META, true );
			if ( ! $ensure_identities && ! self::valid_uuid( $stored_id ) ) {
				continue;
			}
			if ( self::being_created( $component_post ) ) {
				continue;
			}
			if ( isset( $component_ids_by_ref[ $ref ] ) ) {
				throw new \DomainException( 'Two WordPress reusable blocks use the same Histeon component key: ' . $ref );
			}
			$component_ids_by_ref[ $ref ] = self::identity( $component_post, self::PAGE_ID_META, $ensure_identities );
			$eligible_components[] = $component_post;
		}
		/** @var list<\WP_Post> $eligible_templates */
		$eligible_templates = array();
		/** @var list<\WP_Post> $eligible_template_parts */
		$eligible_template_parts = array();
		/** @var array<string, string> $template_refs_by_slug */
		$template_refs_by_slug = array();
		foreach ( array( 'wp_template', 'wp_template_part' ) as $template_type ) {
			$template_posts = get_posts(
				array(
					'post_type'      => $template_type,
					'post_status'    => array( 'draft', 'publish', 'private', 'pending', 'future' ),
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'tax_query'      => array( array( 'taxonomy' => 'wp_theme', 'field' => 'slug', 'terms' => array( get_stylesheet() ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- active-theme templates only.
				)
			);
			foreach ( $template_posts as $template_post ) {
				if ( ! self::is_profile_template( $template_post ) ) {
					continue;
				}
				$stored_id = (string) get_post_meta( $template_post->ID, self::PAGE_ID_META, true );
				if ( ! $ensure_identities && ! self::valid_uuid( $stored_id ) ) {
					continue;
				}
				if ( self::being_created( $template_post ) ) {
					continue;
				}
				$ref = self::profile_ref_for_template( $template_post, $ensure_identities );
				if ( isset( $component_ids_by_ref[ $ref ] ) ) {
					throw new \DomainException( 'A Histeon template and component share the same portable reference: ' . $ref );
				}
				if ( 'wp_template_part' === $template_type ) {
					$component_ids_by_ref[ $ref ] = self::identity( $template_post, self::PAGE_ID_META, $ensure_identities );
					$eligible_template_parts[] = $template_post;
				} else {
					if ( isset( $template_refs_by_slug[ $template_post->post_name ] ) ) {
						throw new \DomainException( 'Two active-theme Histeon templates use the same slug: ' . $template_post->post_name );
					}
					$template_refs_by_slug[ $template_post->post_name ] = $ref;
					$eligible_templates[] = $template_post;
				}
			}
		}
		/** @var array<int|string, array<string, string|bool>> $left_out */
		$left_out = array();
		// Components and templates first, until none falls out: what uses one left out cannot travel either.
		do {
			$fell_out   = false;
			$entities   = array();
			$references = array();
			$files      = array();
			foreach ( $eligible_components as $component_post ) {
				$before = array( $entities, $references, $files, self::$later );
				try {
					$component_id = $component_ids_by_ref[(string) get_post_meta( $component_post->ID, ComponentRegistry::META_KEY, true )];
					$ref = (string) get_post_meta( $component_post->ID, ComponentRegistry::META_KEY, true );
					$schema = self::dev_schema( $component_post );
					if ( ! is_array( $schema ) ) {
						$schema = array( 'props' => array() );
					}
					/** @var list<ParsedBlock> $blocks */
					$blocks = array_values( parse_blocks( self::dev_content( $component_post ) ) );
					self::normalize_blocks( $blocks, $component_id, $references, $entities, $files, $component_ids_by_ref, $ensure_identities, true, true, $transfer_query_presets );
					$payload = array( 'title' => $component_post->post_title, 'ref' => $ref, 'schema' => $schema, 'content' => serialize_blocks( $blocks ), 'script' => self::dev_script( $component_post ) );
					$status = self::transfer_status( $component_post );
					if ( null !== $status ) {
						$payload['status'] = $status;
					}
					$payload = self::with_pending_fields( $component_post, $payload );
					$entity = array( 'id' => $component_id, 'kind' => 'component', 'schemaVersion' => 1, 'payload' => $payload );
					$entity['revisionHash'] = PageProfile::revision_hash( $entity );
					$base_hash = (string) get_post_meta( $component_post->ID, self::BASE_HASH_META, true );
					if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $base_hash ) ) {
						$entity['baseHash'] = $base_hash;
					}
					$entities[] = $entity;
				} catch ( \DomainException $e ) {
					self::leave_out( $component_post, $e, $left_out );
					[ $entities, $references, $files, self::$later ] = $before;
					unset( $component_ids_by_ref[ (string) get_post_meta( $component_post->ID, ComponentRegistry::META_KEY, true ) ] );
					$eligible_components = array_values( array_filter( $eligible_components, static fn ( \WP_Post $kept ): bool => $kept->ID !== $component_post->ID ) );
					$fell_out = true;
				}
			}
			foreach ( $eligible_template_parts as $part_post ) {
				$before = array( $entities, $references, $files, self::$later );
				try {
					$ref = self::profile_ref_for_template( $part_post, $ensure_identities );
					$part_id = $component_ids_by_ref[ $ref ];
					$script = \XB\Scripts\Scripts::get( $part_post->ID );
					if ( '' !== $script ) {
						throw new \DomainException( 'Template part ' . $ref . ' contains a custom script outside the static project profile.' );
					}
					$schema = json_decode( (string) get_post_meta( $part_post->ID, ComponentRegistry::META_SCHEMA, true ), true );
					if ( ! is_array( $schema ) ) {
						$schema = array( 'props' => array() );
					}
					$area_terms = wp_get_object_terms( $part_post->ID, 'wp_template_part_area', array( 'fields' => 'slugs' ) );
					$area = is_array( $area_terms ) && isset( $area_terms[0] ) && in_array( $area_terms[0], array( 'header', 'footer', 'uncategorized' ), true ) ? $area_terms[0] : 'uncategorized';
					/** @var list<ParsedBlock> $blocks */
					$blocks = array_values( parse_blocks( self::dev_content( $part_post ) ) );
					self::normalize_blocks( $blocks, $part_id, $references, $entities, $files, $component_ids_by_ref, $ensure_identities, true, true, $transfer_query_presets );
					$payload = array( 'title' => $part_post->post_title, 'ref' => $ref, 'schema' => $schema, 'content' => serialize_blocks( $blocks ), 'script' => '', 'area' => $area );
					$status = self::transfer_status( $part_post );
					if ( null !== $status ) {
						$payload['status'] = $status;
					}
					$payload = self::with_pending_fields( $part_post, $payload );
					$entity = array( 'id' => $part_id, 'kind' => 'template-part', 'schemaVersion' => 1, 'payload' => $payload );
					$entity['revisionHash'] = PageProfile::revision_hash( $entity );
					$base_hash = (string) get_post_meta( $part_post->ID, self::BASE_HASH_META, true );
					if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $base_hash ) ) {
						$entity['baseHash'] = $base_hash;
					}
					$entities[] = $entity;
				} catch ( \DomainException $e ) {
					self::leave_out( $part_post, $e, $left_out );
					[ $entities, $references, $files, self::$later ] = $before;
					unset( $component_ids_by_ref[ self::profile_ref_for_template( $part_post, $ensure_identities ) ] );
					$eligible_template_parts = array_values( array_filter( $eligible_template_parts, static fn ( \WP_Post $kept ): bool => $kept->ID !== $part_post->ID ) );
					$fell_out = true;
				}
			}
			foreach ( $eligible_templates as $template_post ) {
				$before = array( $entities, $references, $files, self::$later );
				try {
					$ref = self::profile_ref_for_template( $template_post, $ensure_identities );
					$template_id = self::identity( $template_post, self::PAGE_ID_META, $ensure_identities );
					$script = \XB\Scripts\Scripts::get( $template_post->ID );
					if ( '' !== $script ) {
						throw new \DomainException( 'Template ' . $ref . ' contains a custom script outside the static project profile.' );
					}
					/** @var list<ParsedBlock> $blocks */
					$blocks = array_values( parse_blocks( self::dev_content( $template_post ) ) );
					self::portable_template_content( $blocks );
					self::normalize_blocks( $blocks, $template_id, $references, $entities, $files, $component_ids_by_ref, $ensure_identities, true, true, $transfer_query_presets );
					$schema = json_decode( (string) get_post_meta( $template_post->ID, self::TEMPLATE_SCHEMA_META, true ), true );
					if ( ! is_array( $schema ) || ! is_array( $schema['props'] ?? null ) ) {
						$schema = array( 'props' => array( 'page' => array( 'type' => 'object', 'default' => array( 'title' => '', 'slug' => '', 'url' => '/' ) ) ) );
					}
					$payload = array( 'title' => $template_post->post_title, 'ref' => $ref, 'schema' => $schema, 'content' => serialize_blocks( $blocks ), 'script' => '' );
					// Doc 23 of Publish, items 190 and 204: named here, so that it is left out or named, not a bare 500.
					if ( ! PageProfile::has_default_slot( $payload['content'] ) ) {
						throw new \DomainException( __( 'Il template non ha il blocco «Contenuto del post»: aggiungilo nel builder dove va il testo della pagina, poi riprova.', 'xb' ) );
					}
					// A Histeon template saved under a role's slug has that role in WordPress's hierarchy (§12).
					if ( in_array( $template_post->post_name, PageProfile::TEMPLATE_ROLES, true ) ) {
						$payload['role'] = $template_post->post_name;
					}
					$status = self::transfer_status( $template_post );
					if ( null !== $status ) {
						$payload['status'] = $status;
					}
					$payload = self::with_pending_fields( $template_post, $payload );
					$entity = array( 'id' => $template_id, 'kind' => 'template', 'schemaVersion' => 1, 'payload' => $payload );
					$entity['revisionHash'] = PageProfile::revision_hash( $entity );
					$base_hash = (string) get_post_meta( $template_post->ID, self::BASE_HASH_META, true );
					if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $base_hash ) ) {
						$entity['baseHash'] = $base_hash;
					}
					$entities[] = $entity;
				} catch ( \DomainException $e ) {
					self::leave_out( $template_post, $e, $left_out );
					[ $entities, $references, $files, self::$later ] = $before;
					unset( $template_refs_by_slug[ $template_post->post_name ] );
					$eligible_templates = array_values( array_filter( $eligible_templates, static fn ( \WP_Post $kept ): bool => $kept->ID !== $template_post->ID ) );
					$fell_out = true;
				}
			}
		} while ( $fell_out );
		$exported_posts  = false;
		$post_taxonomies = array() !== $profile_posts ? ContentProfileAdapter::post_taxonomy_keys() : array();
		foreach ( array_merge( $profile_pages, $profile_posts ) as $post ) {
			$stored_page_id = (string) get_post_meta( $post->ID, self::PAGE_ID_META, true );
			if ( ! $ensure_identities && ! self::valid_uuid( $stored_page_id ) ) {
				continue;
			}
			if ( self::being_created( $post ) ) {
				continue;
			}
			if ( 'trash' === $post->post_status ) {
				$tombstone = get_post_meta( $post->ID, self::TOMBSTONE_META, true );
				if ( is_array( $tombstone ) && $stored_page_id === ( $tombstone['id'] ?? null ) && 'tombstone' === ( $tombstone['kind'] ?? null ) ) {
					$entities[] = $tombstone;
				}
				continue;
			}
			$before = array( $entities, $references, $files, self::$later );
			try {
				$page_id = self::identity( $post, self::PAGE_ID_META, $ensure_identities );
				/** @var list<ParsedBlock> $blocks */
				$blocks = array_values( parse_blocks( self::dev_content( $post ) ) );
				self::normalize_blocks( $blocks, $page_id, $references, $entities, $files, $component_ids_by_ref, $ensure_identities, false, true, $transfer_query_presets );
				$payload = array(
					'documentType' => 'page',
					'title'        => $post->post_title,
					'slug'         => $post->post_name,
					'status'       => 'publish' === $post->post_status ? 'published' : 'draft',
					'content'      => serialize_blocks( $blocks ),
					'script'       => self::dev_script( $post ),
				);
				if ( 'post' === $post->post_type ) {
					$payload['documentType'] = 'post';
					$payload['slug']         = self::portable_post_slug( $post );
					$payload['publishedAt']  = 'publish' === $post->post_status ? self::utc_second( $post ) : null;
					$payload['excerpt']      = $post->post_excerpt;
					$payload['terms']        = ContentProfileAdapter::post_terms( $post, $ensure_identities, $post_taxonomies );
					$exported_posts          = true;
					// Doc 23 item 119: the featured image travels as `image`, the asset of its attachment; no key without one.
					$thumbnail = get_post( (int) get_post_thumbnail_id( $post ) );
					if ( $thumbnail instanceof \WP_Post && 'attachment' === $thumbnail->post_type ) {
						$payload['image'] = self::exported_attachment( $thumbnail, $entities, $files, $ensure_identities, true )['id'];
					}
				}
				$template_slug = (string) get_post_meta( $post->ID, '_wp_page_template', true );
				if ( '' !== $template_slug && 'default' !== $template_slug ) {
					$template_ref = $template_refs_by_slug[ $template_slug ] ?? '';
					if ( '' === $template_ref ) {
						throw new \DomainException( ( 'post' === $post->post_type ? 'Post ' : 'Page ' ) . $post->post_name . ' uses a WordPress template that is not part of the Histeon transfer profile.' );
					}
					$payload['templateRef'] = $template_ref;
				}
				$payload = self::with_pending_fields( $post, $payload );
				// A featured image waiting for the release is the one development shows: its asset travels too.
				if ( is_string( $payload['image'] ?? null ) ) {
					$waiting = self::post_by_profile_id( $payload['image'], 'attachment' );
					if ( ! $waiting instanceof \WP_Post ) {
						throw new \DomainException( 'The featured image of post ' . $post->post_name . ' is missing from WordPress.' );
					}
					self::exported_attachment( $waiting, $entities, $files, $ensure_identities, true );
				}
				$entity = array( 'id' => $page_id, 'kind' => 'document', 'schemaVersion' => 1, 'payload' => $payload );
				$entity['revisionHash'] = PageProfile::revision_hash( $entity );
				$base_hash = (string) get_post_meta( $post->ID, self::BASE_HASH_META, true );
				if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $base_hash ) ) {
					$entity['baseHash'] = $base_hash;
				}
				$entities[] = $entity;
			} catch ( \DomainException $e ) {
				self::leave_out( $post, $e, $left_out );
				[ $entities, $references, $files, self::$later ] = $before;
			}
		}
		$known_entity_ids = array_fill_keys( array_column( $entities, 'id' ), true );
		$remembered_tombstones = get_option( self::TOMBSTONES_OPTION, array() );
		if ( is_array( $remembered_tombstones ) ) {
			foreach ( $remembered_tombstones as $id => $tombstone ) {
				if ( ! self::valid_uuid( $id ) || isset( $known_entity_ids[ $id ] ) || ! is_array( $tombstone ) || $id !== ( $tombstone['id'] ?? null ) || 'tombstone' !== ( $tombstone['kind'] ?? null ) ) {
					continue;
				}
				$entity_kind = $tombstone['payload']['entityKind'] ?? null;
				$previous_payload = $tombstone['payload']['previousPayload'] ?? null;
				$post = is_string( $entity_kind ) && is_array( $previous_payload )
					? self::post_for_profile_tombstone( $entity_kind, $id, $previous_payload )
					: null;
				if ( $post instanceof \WP_Post && 'trash' !== $post->post_status ) {
					continue;
				}
				$entities[] = $tombstone;
				$known_entity_ids[ $id ] = true;
			}
		}
		// Keep every linked Histeon image in the editable profile, even when no page currently references it.
		// Static builds still include only referenced assets; retaining the linked library here allows safe,
		// base-checked deletion and restoration without losing an asset as soon as its last block is removed.
		$profile_assets = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_key'       => self::MEDIA_ID_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- linked Histeon media only.
				'meta_compare'   => 'EXISTS',
			)
		);
		foreach ( $profile_assets as $asset_post ) {
			$stored_id = (string) get_post_meta( $asset_post->ID, self::MEDIA_ID_META, true );
			if ( ! $ensure_identities && ! self::valid_uuid( $stored_id ) ) {
				continue;
			}
			$asset_entity = self::asset_entity( $asset_post, $files, $ensure_identities );
			$already_in_profile = false;
			foreach ( $entities as $known_entity ) {
				if ( $asset_entity['id'] === $known_entity['id'] ) {
					$already_in_profile = true;
					break;
				}
			}
			if ( ! $already_in_profile ) {
				$entities[] = $asset_entity;
			}
		}

		// The development copies, as the builder sees them (docs/spec/cms-bridge.md §4).
		$style_data = self::dev_payload( Documents::STYLES );
		$settings   = self::dev_payload( Documents::SETTINGS );
		$payload    = array(
			'styles' => array_map(
				static function ( array $entry, int $index ): array {
					$style = array( 'id' => (string) ( $entry['id'] ?? 'style-' . ( $index + 1 ) ), 'selector' => (string) $entry['selector'], 'css' => (string) $entry['css'] );
					if ( is_array( $entry['assets'] ?? null ) ) {
						$style['assets'] = $entry['assets'];
					}
					return $style;
				},
				(array) ( $style_data['styles'] ?? array() ),
				array_keys( (array) ( $style_data['styles'] ?? array() ) )
			),
			'globals' => array_map(
				static function ( array $entry, int $index ): array {
					$global = array( 'id' => (string) ( $entry['id'] ?? 'global-' . ( $index + 1 ) ), 'css' => (string) $entry['css'] );
					if ( is_array( $entry['assets'] ?? null ) ) {
						$global['assets'] = $entry['assets'];
					}
					$source = PageProfile::validate_framework_source( $entry['source'] ?? null );
					if ( null !== $source ) {
						$global['source'] = $source;
					}
					return $global;
				},
				(array) ( $style_data['globals'] ?? array() ),
				array_keys( (array) ( $style_data['globals'] ?? array() ) )
			),
			'compiledCss' => '',
			'design' => is_array( $settings['design'] ?? null ) ? $settings['design'] : array(),
			'tokenOverrides' => is_array( $settings['tokens'] ?? null ) ? $settings['tokens'] : array(),
			'queries' => is_array( $settings['queries'] ?? null ) ? $settings['queries'] : array(),
		);
		$profile_asset_ids = array();
		$profile_asset_media_types = array();
		foreach ( $entities as $profile_entity ) {
			if ( 'asset' === ( $profile_entity['kind'] ?? null ) ) {
				$profile_asset_ids[ $profile_entity['id'] ] = true;
				$profile_asset_media_types[ $profile_entity['id'] ] = $profile_entity['payload']['mediaType'];
			}
		}
		foreach ( array_merge( $payload['styles'], $payload['globals'] ) as $rule ) {
			\XB\Styles\CssAssets::validate_profile( $rule['css'], $rule['assets'] ?? array(), $profile_asset_ids, $profile_asset_media_types );
		}
		$styles_id = '';
		if ( $ensure_identities ) {
			$styles_id = self::styles_id();
		} else {
			$stored_styles_id = (string) get_option( 'xb_page_profile_styles_id', '' );
			if ( self::valid_uuid( $stored_styles_id ) ) {
				$styles_id = $stored_styles_id;
			} elseif ( ! $allow_empty_project ) {
				$styles_id = self::styles_id( false );
			}
		}
		if ( '' !== $styles_id ) {
			$style_entity = array( 'id' => $styles_id, 'kind' => 'styles', 'schemaVersion' => 1, 'payload' => $payload );
			$style_entity['revisionHash'] = PageProfile::revision_hash( $style_entity );
			$styles_base = (string) get_option( self::STYLES_BASE_OPTION, '' );
			if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $styles_base ) ) {
				$style_entity['baseHash'] = $styles_base;
			}
			$entities[] = $style_entity;
		}
		$content_entities = ContentProfileAdapter::snapshot( $ensure_identities, $files, true, $exported_posts );
		foreach ( ContentProfileAdapter::presets_left_out() as $key => $type ) {
			$left_out[ 'query:' . $key ] = array(
				'kind'   => 'query',
				'title'  => mb_substr( $key, 0, 160 ),
				'reason' => mb_substr( sprintf( 'It queries the type %s, which is not part of the project.', $type ), 0, 300 ),
			);
		}
		$entities = self::merge_snapshot_entities( array_merge( $entities, $content_entities ) );

		$entities = self::sort_entities( $entities );
		// The order of Histeon Publish: srcset references of one block and target differ only by their index.
		$reference_key = static fn ( array $reference ): string => implode( "\0", array( $reference['ownerId'], $reference['blockUid'], $reference['path'], $reference['targetId'], (string) ( $reference['index'] ?? '' ) ) );
		usort( $references, static fn ( array $a, array $b ): int => strcmp( $reference_key( $a ), $reference_key( $b ) ) );
		$files['entities.ndjson']   = array() === $entities ? '' : implode( "\n", array_map( PageProfile::canonical(...), $entities ) ) . "\n";
		$files['references.ndjson'] = array() === $references ? '' : implode( "\n", array_map( PageProfile::canonical(...), $references ) ) . "\n";
		/** @var list<array<string, mixed>> $file_table */
		$file_table = array();
		foreach ( $files as $path => $bytes ) {
			$file_table[] = array(
				'path'      => $path,
				'bytes'     => strlen( $bytes ),
				'sha256'    => hash( 'sha256', $bytes ),
				'mediaType' => str_ends_with( $path, '.ndjson' ) ? 'application/x-ndjson' : 'application/octet-stream',
			);
		}
		usort( $file_table, static fn ( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
		if ( array() !== $left_out && ! $allow_empty_project && ! array_filter( $entities, static fn ( array $entity ): bool => 'document' === $entity['kind'] ) ) {
			$first = reset( $left_out );
			throw new \DomainException( 'Nessuna pagina del sito si può trasportare. La prima, ' . $first['title'] . ': ' . $first['reason'] );
		}
		$has_components = array_filter( $entities, static fn ( array $entity ): bool => in_array( $entity['kind'], array( 'component', 'template-part' ), true ) );
		$has_templates = array_filter( $entities, static fn ( array $entity ): bool => in_array( $entity['kind'], array( 'template', 'template-part' ), true ) );
		$content_counts = array();
		foreach ( array( 'content-type', 'content-record', 'taxonomy', 'term', 'query-preset', 'query-source' ) as $kind ) {
			$content_counts[ $kind ] = count( array_filter( $entities, static fn ( array $entity ): bool => $kind === $entity['kind'] ) );
		}
		$has_relational_content = array_sum( $content_counts ) > 0;
		$has_posts = (bool) array_filter( $entities, static fn ( array $entity ): bool => 'document' === $entity['kind'] && 'post' === ( $entity['payload']['documentType'] ?? null ) );
		$has_roles = (bool) array_filter( $entities, static fn ( array $entity ): bool => 'template' === $entity['kind'] && isset( $entity['payload']['role'] ) );
		$required_capabilities = array_merge(
			array( 'documents:page:v1', 'styles:global:v1', 'media:asset:v1' ),
			$has_components ? array( 'components:definition:v1' ) : array(),
			$has_templates ? array( 'templates:definition:v1' ) : array(),
			$content_counts['content-type'] > 0 ? array( 'content:types:v1' ) : array(),
			$content_counts['content-record'] > 0 ? array( 'content:records:v1' ) : array(),
			$content_counts['taxonomy'] > 0 || $content_counts['term'] > 0 ? array( 'content:taxonomies:v1' ) : array(),
			$content_counts['query-preset'] > 0 || $content_counts['query-source'] > 0 ? array( 'content:queries:v1' ) : array(),
			$has_posts ? array( 'documents:post:v1' ) : array(),
			$has_roles ? array( 'templates:roles:v1' ) : array(),
			ProductProfile::uses_products( $entities ) ? array( ProductProfile::CAPABILITY ) : array(),
			PageProfile::uses_scripts( $entities ) ? array( PageProfile::SCRIPT_CAPABILITY ) : array()
		);
		$manifest = array(
			'format'              => 'xb-site',
			'version'             => 2,
			'generator'           => array( 'name' => 'Histos CMS', 'version' => defined( 'XB_VERSION' ) ? XB_VERSION : 'unknown', 'engineCommit' => PageProfile::ENGINE_COMMIT ),
			'project'             => array( 'id' => $project_id, 'name' => mb_substr( $project_name, 0, 160 ), 'defaultLocale' => ContentProfileAdapter::canonical_locale( (string) get_option( 'xb_page_profile_default_locale', get_locale() ) ) ?: 'en-US' ),
			'selection'           => array( 'kind' => 'workspace-head', 'snapshot' => self::uuid() ),
			'requiredCapabilities'=> $required_capabilities,
			'profile'             => PageProfile::expected_profile( $has_posts || $has_roles, $has_relational_content, (bool) $has_templates, (bool) $has_components ),
			'files'               => $file_table,
		);
		if ( array() !== $left_out ) {
			$manifest['leftOut'] = array_slice( array_values( $left_out ), 0, PageProfile::MAX_LEFT_OUT );
		}
		$manifest['manifestHash'] = PageProfile::manifest_hash( $manifest );
		return array( 'manifest' => $manifest, 'entities' => $entities, 'references' => $references, 'files' => $files );
	}

	/**
	 * @param array<ParsedBlock>         $blocks
	 * @param list<array<string, mixed>> $references
	 * @param list<array<string, mixed>> $entities
	 * @param array<string, string>      $files
	 * @param array<string, string>      $component_ids_by_ref
	 * @param array<string, array<string, true>|null> $transfer_query_presets
	 */
	private static function normalize_blocks( array &$blocks, string $owner_id, array &$references, array &$entities, array &$files, array $component_ids_by_ref, bool $ensure_identities = true, bool $allow_slot = false, bool $validate_tree = true, array $transfer_query_presets = array() ): void {
		if ( $validate_tree ) {
			if ( ! $allow_slot ) {
				/** @var list<ParsedBlock> $carried */
				$carried = \XB\Content\ArticleBlocks::from_core( $blocks );
				$blocks  = $carried;
			}
			self::portable_svg( $blocks );
			ProfileValidator::assert_transfer_block_tree( $blocks, $allow_slot ? 'component' : 'document', $owner_id, $transfer_query_presets );
		}
		foreach ( $blocks as &$block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( ! $allow_slot && 'xb/passthrough' === $name ) {
				continue;
			}
			if ( ! in_array( $name, array( 'xb/element', 'xb/text', 'xb/component', 'xb/if', 'xb/branch', 'xb/loop', 'xb/slot-fill' ), true ) && ( ! $allow_slot || 'xb/slot' !== $name ) ) {
				throw new \DomainException( 'A page contains a Gutenberg or Histeon block outside the static page profile: ' . ( '' === $name ? 'freeform content' : $name ) . '.' );
			}
			$attrs = $block['attrs'];
			if ( isset( $attrs['attributes']['style'] ) && is_string( $attrs['attributes']['style'] ) ) {
				ProfileValidator::assert_css_profile( $attrs['attributes']['style'] );
			}
			$src = $attrs['attributes']['src'] ?? null;
			// A component prop as a whole (`{props.immagine}`) is resolved where the component is used: it travels as is.
			if ( 'xb/element' === $name && null !== $src && '' !== $src && ! ( is_string( $src ) && TransferMarkup::is_transfer_media_prop( $src ) ) ) {
				if ( ! is_string( $src ) ) {
					throw new \DomainException( 'Invalid media URL in a page.' );
				}
				if ( TransferMarkup::is_whole_interpolation( $src ) ) {
					throw new \DomainException( 'An image source can travel to Histeon CMS as an expression only as a whole component prop ({props.name}): ' . $src . ' is not one.' );
				}
				$asset_entity = self::exported_media( $src, $entities, $files, $ensure_identities, false );
				$block['attrs']['attributes']['src'] = 'histos-media://' . $asset_entity['id'];
				$references[] = array( 'ownerId' => $owner_id, 'blockUid' => self::media_block_uid( $attrs ), 'path' => 'attrs.attributes.src', 'targetId' => $asset_entity['id'], 'role' => 'media' );
			}
			$srcset = $attrs['attributes']['srcset'] ?? null;
			$tag    = $attrs['tag'] ?? null;
			if ( 'xb/element' === $name && ( 'img' === $tag || 'source' === $tag ) && null !== $srcset ) {
				// Each candidate becomes the media identity of its attachment, referenced with its position (transfer-markup §5).
				$candidates = is_string( $srcset ) ? TransferMarkup::parse_srcset( $srcset ) : null;
				if ( null === $candidates ) {
					throw new \DomainException( 'The srcset of an <' . (string) $tag . '> is not a static list of at most 16 images of the site, so it cannot travel to Histeon CMS.' );
				}
				$block_uid = self::media_block_uid( $attrs );
				$portable  = array();
				foreach ( $candidates as $index => $candidate ) {
					$asset_entity = self::exported_media( $candidate['url'], $entities, $files, $ensure_identities, true );
					$portable[]   = array( 'url' => 'histos-media://' . $asset_entity['id'], 'descriptor' => $candidate['descriptor'] );
					$references[] = array( 'ownerId' => $owner_id, 'blockUid' => $block_uid, 'path' => 'attrs.attributes.srcset', 'targetId' => $asset_entity['id'], 'role' => 'media', 'index' => $index );
				}
				$block['attrs']['attributes']['srcset'] = self::format_srcset( $portable );
			}
			// Doc 23 item 121: a link to the very file of an image of the site travels as its media identity, like a
			// src. Any other link (a page, an intermediate size, an upload that is no attachment) stays a plain link.
			$href = $attrs['attributes']['href'] ?? null;
			if ( 'xb/element' === $name && 'a' === $tag && is_string( $href ) && self::links_media_file( $href ) ) {
				$asset_entity = self::exported_media( $href, $entities, $files, $ensure_identities, true );
				$block['attrs']['attributes']['href'] = 'histos-media://' . $asset_entity['id'];
				$references[] = array( 'ownerId' => $owner_id, 'blockUid' => self::media_block_uid( $attrs ), 'path' => 'attrs.attributes.href', 'targetId' => $asset_entity['id'], 'role' => 'media' );
			}
			if ( 'xb/component' === $name ) {
				$ref = $attrs['ref'] ?? null;
				$block_uid = $attrs['uid'] ?? null;
				if ( ! is_string( $ref ) || ! isset( $component_ids_by_ref[ $ref ] ) || ! is_string( $block_uid ) || 1 !== preg_match( '/^[a-z0-9]{8,16}$/', $block_uid ) ) {
					throw new \DomainException( 'A Histeon page refers to a component missing from the project or has an invalid block UID.' );
				}
				$references[] = array( 'ownerId' => $owner_id, 'blockUid' => $block_uid, 'path' => 'attrs.ref', 'targetId' => $component_ids_by_ref[ $ref ], 'role' => 'document' );
				// An image of the site written in a prop of the instance travels like the src of an element.
				$uploads = (string) wp_upload_dir( null, false )['baseurl'] . '/';
				foreach ( is_array( $attrs['props'] ?? null ) ? $attrs['props'] : array() as $prop => $value ) {
					if ( is_string( $value ) && ( str_starts_with( $value, 'histos-media://' ) || str_starts_with( $value, $uploads ) && 1 === preg_match( '/\.(?:png|jpe?g|gif|webp|avif|pdf)$/i', $value ) ) ) {
						$asset_entity                    = self::exported_media( $value, $entities, $files, $ensure_identities, false );
						$block['attrs']['props'][ $prop ] = 'histos-media://' . $asset_entity['id'];
						$references[]                    = array( 'ownerId' => $owner_id, 'blockUid' => $block_uid, 'path' => 'attrs.props.' . $prop, 'targetId' => $asset_entity['id'], 'role' => 'media' );
					}
				}
			}
			if ( array() !== $block['innerBlocks'] ) {
				/** @var list<ParsedBlock> $inner_blocks */
				$inner_blocks = $block['innerBlocks'];
				self::normalize_blocks( $inner_blocks, $owner_id, $references, $entities, $files, $component_ids_by_ref, $ensure_identities, $allow_slot, false, $transfer_query_presets );
				$block['innerBlocks'] = $inner_blocks;
			}
		}
		unset( $block );
	}

	/**
	 * The asset entity of an image a document shows, added to `$entities` once: a `histos-media://` identity of a
	 * linked attachment, or an uploads URL of an attachment. With `$original_file` (a srcset candidate) the URL must be
	 * the very file the profile carries, as development shows it: an intermediate size (`-300x200.jpg`), the
	 * unscaled original or an earlier file would come back as another image and its descriptor would lie.
	 *
	 * @param list<array<string, mixed>> $entities
	 * @param array<string, string>      $files
	 * @return array<string, mixed>
	 */
	private static function exported_media( string $url, array &$entities, array &$files, bool $ensure_identities, bool $original_file ): array {
		$media_id = TransferMarkup::media_id( $url );
		if ( null !== $media_id ) {
			$attachment = self::post_by_profile_id( $media_id, 'attachment' );
			if ( ! $attachment instanceof \WP_Post ) {
				throw new \DomainException( 'A Histeon page points to a media identity missing from WordPress.' );
			}
		} elseif ( str_starts_with( $url, 'histos-media://' ) ) {
			throw new \DomainException( 'A Histeon page contains an invalid media identity.' );
		} else {
			$uploads = wp_upload_dir();
			$base    = $uploads['baseurl'];
			// The site's own uploads under the other scheme (http:// on a site moved to https, as on a LocalWP copy) are
			// the same files: the host and the uploads path still have to match (doc 23 of Publish, item 190).
			$scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
			if ( '' !== $base && in_array( $scheme, array( 'http', 'https' ), true ) && 1 === preg_match( '#^https?://#i', $url ) ) {
				$url = set_url_scheme( $url, $scheme );
			}
			if ( '' === $base || ! str_starts_with( $url, untrailingslashit( $base ) . '/' ) ) {
				throw new \DomainException( 'The page profile cannot transfer external, embedded or unresolved media URLs.' );
			}
			$attachment_id = self::attachment_for_url( $url );
			$attachment    = $attachment_id > 0 ? get_post( $attachment_id ) : null;
			if ( ! $attachment instanceof \WP_Post ) {
				throw new \DomainException( 'A page refers to an upload which is not a WordPress attachment.' );
			}
			if ( $original_file && self::dev_media_url( $attachment->ID ) !== $url ) {
				throw new \DomainException( 'A srcset candidate (' . $url . ') is an intermediate size or another file of its attachment: Histeon CMS carries each image once, so list only the original files of the media, with their own descriptors.' );
			}
			self::identity( $attachment, self::MEDIA_ID_META, $ensure_identities );
		}
		return self::exported_attachment( $attachment, $entities, $files, $ensure_identities, false );
	}

	/**
	 * The asset entity of an attachment, added to `$entities` once, in a format the profile transfers; with
	 * `$images_only` (a post's featured image, doc 23 item 119) an image, never a PDF.
	 *
	 * @param list<array<string, mixed>> $entities
	 * @param array<string, string>      $files
	 * @return array<string, mixed>
	 */
	private static function exported_attachment( \WP_Post $attachment, array &$entities, array &$files, bool $ensure_identities, bool $images_only ): array {
		$mime = (string) ( self::pending_media( $attachment )['mediaType'] ?? $attachment->post_mime_type );
		if ( ! in_array( $mime, self::ALLOWED_MEDIA, true ) || $images_only && ! str_starts_with( $mime, 'image/' ) ) {
			throw new \DomainException( 'An image source of a page is not an image format the static page profile transfers.' );
		}
		$asset_entity = self::asset_entity( $attachment, $files, $ensure_identities );
		foreach ( $entities as $known ) {
			if ( $known['id'] === $asset_entity['id'] ) {
				return $asset_entity;
			}
		}
		$entities[] = $asset_entity;
		return $asset_entity;
	}

	/**
	 * Whether a link points to a media file the profile carries: a `histos-media://` identity, or exactly the file an
	 * image attachment shows in development (dev_media_url(), the pending file of a send included).
	 */
	private static function links_media_file( string $href ): bool {
		if ( str_starts_with( $href, 'histos-media://' ) ) {
			return true;
		}
		$base = (string) wp_upload_dir( null, false )['baseurl'];
		if ( '' === $base || ! str_starts_with( $href, untrailingslashit( $base ) . '/' ) ) {
			return false;
		}
		$attachment_id = self::attachment_for_url( $href );
		$attachment    = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		if ( ! $attachment instanceof \WP_Post || self::dev_media_url( $attachment->ID ) !== $href ) {
			return false;
		}
		return in_array( (string) ( self::pending_media( $attachment )['mediaType'] ?? $attachment->post_mime_type ), self::ALLOWED_MEDIA, true );
	}

	/** @param array<string, mixed> $attrs */
	private static function media_block_uid( array $attrs ): string {
		$block_uid = (string) ( $attrs['uid'] ?? '' );
		if ( 1 !== preg_match( '/^[a-z0-9]{8,16}$/', $block_uid ) ) {
			throw new \DomainException( 'A media block is missing a portable Histeon UID.' );
		}
		return $block_uid;
	}

	/**
	 * A srcset written back from its candidates, as Histeon Publish writes it (`url descriptor, url descriptor`).
	 *
	 * @param list<array{url: string, descriptor: string|null}> $candidates
	 */
	private static function format_srcset( array $candidates ): string {
		return implode( ', ', array_map( static fn ( array $candidate ): string => null === $candidate['descriptor'] ? $candidate['url'] : $candidate['url'] . ' ' . $candidate['descriptor'], $candidates ) );
	}

	/**
	 * SVG as the builder stores it, made portable before the transfer checks (transfer-markup §5): the two namespace
	 * declarations an inline SVG never needs leave the `svg` element, exactly as written and nowhere else (any other
	 * `xmlns` stays, and the checks refuse it). Sprite references (`<use>`, `href`) get a message of their own.
	 *
	 * @param array<ParsedBlock> $blocks
	 */
	private static function portable_svg( array &$blocks, bool $in_svg = false ): void {
		foreach ( $blocks as &$block ) {
			if ( 'xb/element' !== ( $block['blockName'] ?? null ) ) {
				if ( array() !== $block['innerBlocks'] ) {
					/** @var array<ParsedBlock> $inner */
					$inner = $block['innerBlocks'];
					self::portable_svg( $inner, $in_svg );
					$block['innerBlocks'] = $inner;
				}
				continue;
			}
			$tag    = $block['attrs']['tag'] ?? null;
			$inside = $in_svg || 'svg' === $tag;
			if ( 'svg' === $tag && is_array( $block['attrs']['attributes'] ?? null ) ) {
				foreach ( array( 'xmlns' => 'http://www.w3.org/2000/svg', 'xmlns:xlink' => 'http://www.w3.org/1999/xlink' ) as $attribute => $namespace ) {
					if ( ( $block['attrs']['attributes'][ $attribute ] ?? null ) === $namespace ) {
						unset( $block['attrs']['attributes'][ $attribute ] );
					}
				}
				if ( array() === $block['attrs']['attributes'] ) {
					unset( $block['attrs']['attributes'] );
				}
			}
			if ( $inside ) {
				$attributes = is_array( $block['attrs']['attributes'] ?? null ) ? $block['attrs']['attributes'] : array();
				$names      = array_map( static fn ( int|string $name ): string => strtolower( (string) $name ), array_keys( $attributes ) );
				if ( 'use' === $tag || array() !== array_intersect( $names, array( 'href', 'xlink:href' ) ) ) {
					throw new TransferMarkupViolation( 'use' === $tag ? 'tag' : 'attribute', 'An SVG uses <use> or an href reference (a sprite or a shape defined elsewhere), which Histeon CMS does not carry: paste the SVG with its shapes drawn in place (path, circle, rect…) to transfer it.' );
				}
			}
			if ( array() !== $block['innerBlocks'] ) {
				/** @var array<ParsedBlock> $inner */
				$inner = $block['innerBlocks'];
				self::portable_svg( $inner, $inside );
				$block['innerBlocks'] = $inner;
			}
		}
		unset( $block );
	}

	/**
	 * The file an attachment transfers. WordPress swaps a very large or a rotated upload for a smaller copy
	 * (`-scaled`, `-rotated`) and keeps the upload beside it: when that upload is the file a transfer wrote here,
	 * it is the asset, so the project gets back the bytes it sent and the send can confirm them. An image edited
	 * in WordPress afterwards has another file name, and travels as it is.
	 */
	private static function transfer_file( int $attachment_id ): string|false {
		$file = get_attached_file( $attachment_id );
		$sent = (string) get_post_meta( $attachment_id, self::MEDIA_HASH_META, true );
		if ( ! is_string( $file ) || '' === $sent || 1 !== preg_match( '/^(.+)-(?:scaled|rotated)(\.[^.\/]+)$/', $file, $made ) ) {
			return $file;
		}
		$upload = wp_get_original_image_path( $attachment_id );
		if ( ! is_string( $upload ) || $upload !== $made[1] . $made[2] || ! is_readable( $upload ) || ! hash_equals( $sent, (string) hash_file( 'sha256', $upload ) ) ) {
			return $file;
		}
		return $upload;
	}

	/**
	 * @param array<string, string> $files
	 * @return array<string, mixed>
	 */
	private static function asset_entity( \WP_Post $attachment, array &$files, bool $ensure_identity = true ): array {
		// As in development: with the file, title, caption and alt waiting for the next release.
		$pending   = self::pending_media( $attachment );
		$file_path = null !== $pending && null !== $pending['file'] ? self::upload_path( $pending['file'] ) : self::transfer_file( $attachment->ID );
		if ( ! is_string( $file_path ) || ! is_readable( $file_path ) || (int) filesize( $file_path ) > PageProfile::MAX_ARCHIVE_BYTES ) {
			throw new \DomainException( 'A referenced media file is missing or too large.' );
		}
		$mime = null !== $pending ? $pending['mediaType'] : (string) $attachment->post_mime_type;
		if ( ! in_array( $mime, self::ALLOWED_CSS_MEDIA, true ) ) {
			throw new \DomainException( 'The static page profile only transfers approved image and font formats.' );
		}
		$bytes = file_get_contents( $file_path );
		if ( ! is_string( $bytes ) ) {
			throw new \RuntimeException( 'Could not read a referenced media file.' );
		}
		$sha  = hash( 'sha256', $bytes );
		$path = 'media/' . $sha;
		$base = (string) get_post_meta( $attachment->ID, self::BASE_HASH_META, true );
		$payload = array(
			'sha256'   => $sha,
			'mediaType'=> $mime,
			'bytes'    => strlen( $bytes ),
			'path'     => $path,
			'alt'      => null !== $pending ? $pending['alt'] : (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'title'    => null !== $pending ? $pending['title'] : $attachment->post_title,
			'caption'  => null !== $pending ? $pending['caption'] : $attachment->post_excerpt,
		);
		$entity = array( 'id' => self::identity( $attachment, self::MEDIA_ID_META, $ensure_identity ), 'kind' => 'asset', 'schemaVersion' => 1, 'payload' => $payload );
		$entity['revisionHash'] = PageProfile::revision_hash( $entity );
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $base ) ) {
			$entity['baseHash'] = $base;
		}
		if ( ! isset( $files[ $path ] ) ) {
			$files[ $path ] = $bytes;
		}
		return $entity;
	}

	/** @param array<string, int> $media_ids */
	private static function restore_media( string $content, array $media_ids, bool $allow_slot = false ): string {
		$blocks = parse_blocks( $content );
		$visit  = static function ( array &$items ) use ( &$visit, $media_ids, $allow_slot ): void {
			foreach ( $items as &$block ) {
				$name = $block['blockName'] ?? null;
				if ( ! $allow_slot && 'xb/passthrough' === $name ) {
					continue;
				}
				if ( ! in_array( $name, array( 'xb/element', 'xb/text', 'xb/component', 'xb/if', 'xb/branch', 'xb/loop', 'xb/slot-fill' ), true ) && ( ! $allow_slot || 'xb/slot' !== $name ) ) {
					throw new \DomainException( 'The incoming page contains a block outside the static page profile.' );
				}
				$source = $block['attrs']['attributes']['src'] ?? null;
				// A whole component prop (`{props.immagine}`) passed the checks before the writes: it stays an expression,
				// resolved at render, where it must be an image of the site (Renderer::attribute).
				if ( 'xb/element' === ( $block['blockName'] ?? '' ) && null !== $source && '' !== $source && ! ( is_string( $source ) && TransferMarkup::is_transfer_media_prop( $source ) ) ) {
					$block['attrs']['attributes']['src'] = self::restored_media_url( $source, $media_ids );
				}
				// Doc 23 item 121: a link to a media file becomes the address of its attachment's file.
				$href = $block['attrs']['attributes']['href'] ?? null;
				if ( 'xb/element' === ( $block['blockName'] ?? '' ) && 'a' === ( $block['attrs']['tag'] ?? null ) && is_string( $href ) && str_starts_with( $href, 'histos-media://' ) ) {
					$block['attrs']['attributes']['href'] = self::restored_media_url( $href, $media_ids );
				}
				$srcset = $block['attrs']['attributes']['srcset'] ?? null;
				$tag    = $block['attrs']['tag'] ?? null;
				if ( 'xb/element' === ( $block['blockName'] ?? '' ) && ( 'img' === $tag || 'source' === $tag ) && null !== $srcset ) {
					$candidates = is_string( $srcset ) ? TransferMarkup::parse_srcset( $srcset ) : null;
					if ( null === $candidates ) {
						throw new \DomainException( 'The incoming page contains a srcset that is not a list of project media.' );
					}
					foreach ( $candidates as &$candidate ) {
						$candidate['url'] = self::restored_media_url( $candidate['url'], $media_ids );
						// Checked before the writes (assert_srcset_urls_listable()); kept so a srcset is never written broken.
						if ( 1 === preg_match( '/[ \t\n\r\f,]/', $candidate['url'] ) ) {
							throw new \DomainException( 'An image of the site has a URL that a srcset cannot list (a space or a comma): ' . $candidate['url'] );
						}
					}
					unset( $candidate );
					$block['attrs']['attributes']['srcset'] = self::format_srcset( $candidates );
				}
				if ( isset( $block['attrs']['attributes']['style'] ) && is_string( $block['attrs']['attributes']['style'] ) ) {
					ProfileValidator::assert_css_profile( $block['attrs']['attributes']['style'] );
				}
				if ( 'xb/component' === $name && is_array( $block['attrs']['props'] ?? null ) ) {
					foreach ( $block['attrs']['props'] as $prop => $value ) {
						if ( is_string( $value ) && str_starts_with( $value, 'histos-media://' ) ) {
							$block['attrs']['props'][ $prop ] = self::restored_media_url( $value, $media_ids );
						}
					}
				}
				if ( is_array( $block['innerBlocks'] ?? null ) && array() !== $block['innerBlocks'] ) {
					$visit( $block['innerBlocks'] );
				}
			}
			unset( $block );
		};
		$visit( $blocks );
		// Blocks::serialize, as every save: an xb/* block keeps no HTML of its own, hidden from the builder.
		return \XB\Blocks::serialize( $blocks );
	}

	/**
	 * Before any write: every image a srcset of the send lists will have a URL a srcset can hold (no space or comma),
	 * both the file an existing attachment shows in development and the uploads folder a new file goes to. File names
	 * are sanitized (sanitize_file_name() drops both), so only the uploads URL or an existing file can fail.
	 *
	 * @param list<array<string, mixed>> $references
	 */
	private static function assert_srcset_urls_listable( array $references ): void {
		$targets = array();
		foreach ( $references as $reference ) {
			if ( 'attrs.attributes.srcset' === ( $reference['path'] ?? null ) && is_string( $reference['targetId'] ?? null ) ) {
				$targets[ $reference['targetId'] ] = true;
			}
		}
		if ( array() === $targets ) {
			return;
		}
		$urls = array( (string) wp_upload_dir( null, false )['url'] );
		foreach ( array_keys( $targets ) as $target_id ) {
			$attachment = self::post_by_profile_id( (string) $target_id, 'attachment' );
			$url        = $attachment instanceof \WP_Post ? self::dev_media_url( $attachment->ID ) : false;
			if ( is_string( $url ) ) {
				$urls[] = $url;
			}
		}
		foreach ( $urls as $url ) {
			if ( 1 === preg_match( '/[ \t\n\r\f,]/', $url ) ) {
				throw new \DomainException( 'An image of the site has a URL that a srcset cannot list (a space or a comma): ' . $url . '. Nothing was written.' );
			}
		}
	}

	private static function lock_ttl(): int {
		return (int) apply_filters( 'xb_remote_operation_lock_ttl', self::OPERATION_LOCK_TTL );
	}

	/**
	 * An excerpt passed the check above, so the save keeps it as it is also for a user without unfiltered_html, for
	 * whom WordPress would rewrite its & and the send would look changed.
	 */
	private static function no_excerpt_filter(): void {
		remove_filter( 'excerpt_save_pre', 'wp_filter_post_kses' );
	}

	/** Whether wp_kses_post() would change a text beyond writing its plain & as &amp; (a title, an excerpt). */
	public static function markup_filtered( string $text ): bool {
		return self::filtered_excerpt( $text ) !== $text;
	}

	/** What the save would leave of an excerpt. */
	private static function filtered_excerpt( string $excerpt ): string {
		$clean = wp_kses_post( $excerpt );
		return str_replace( '&amp;', '&', $clean ) === str_replace( '&amp;', '&', $excerpt ) ? $excerpt : $clean;
	}

	/** @param array<array<string, mixed>> $blocks */
	private static function contains_form( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( 'xb/element' === ( $block['blockName'] ?? null ) && is_array( $block['attrs'] ?? null ) && is_string( $block['attrs']['tag'] ?? null ) && 'form' === strtolower( $block['attrs']['tag'] ) ) {
				return true;
			}
			if ( is_array( $block['innerBlocks'] ?? null ) && self::contains_form( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The development URL of the attachment a `histos-media://` identity of the send became.
	 *
	 * @param array<string, int> $media_ids
	 */
	private static function restored_media_url( mixed $source, array $media_ids ): string {
		$id = is_string( $source ) ? TransferMarkup::media_id( $source ) : null;
		if ( null === $id ) {
			throw new \DomainException( is_string( $source ) && str_starts_with( $source, 'histos-media://' ) ? 'The incoming page contains an invalid media identity.' : 'The incoming page contains external or unresolved media.' );
		}
		$attachment_id = $media_ids[ $id ] ?? 0;
		$url           = $attachment_id > 0 ? self::dev_media_url( $attachment_id ) : false;
		if ( ! is_string( $url ) ) {
			throw new \DomainException( 'A page media reference was not resolved during import.' );
		}
		return $url;
	}

	/** Convert the CMS page slot and its page prop root to the native WordPress FSE equivalents. */
	/** @param array<string, int> $media_ids */
	private static function restore_template_content( string $content, array $media_ids ): string {
		$content = self::restore_media( $content, $media_ids, true );
		/** @var list<ParsedBlock> $blocks */
		$blocks  = array_values( parse_blocks( $content ) );
		$visit = static function ( array &$nodes ) use ( &$visit ): void {
			foreach ( $nodes as &$block ) {
				if ( 'xb/slot' === ( $block['blockName'] ?? null ) && 'default' === ( $block['attrs']['name'] ?? 'default' ) ) {
					$block['blockName'] = 'xb/post-content';
					unset( $block['attrs']['name'] );
				}
				if ( is_array( $block['innerBlocks'] ?? null ) ) {
					$visit( $block['innerBlocks'] );
				}
			}
			unset( $block );
		};
		$visit( $blocks );
		return serialize_blocks( $blocks );
	}

	/** Restore native FSE post-content blocks and page context expressions to the portable Histeon form. */
	/** @param array<ParsedBlock> $blocks */
	private static function portable_template_content( array &$blocks ): void {
		foreach ( $blocks as &$block ) {
			if ( 'xb/post-content' === ( $block['blockName'] ?? null ) ) {
				$block['blockName'] = 'xb/slot';
				$block['attrs']['name'] = 'default';
			}
			/** @var array<ParsedBlock> $inner_blocks */
			$inner_blocks = $block['innerBlocks'];
			self::portable_template_content( $inner_blocks );
			$block['innerBlocks'] = $inner_blocks;
		}
		unset( $block );
	}

	/**
	 * Brings one asset in. A new one is created at once: no live page shows it yet. An existing one changes only with
	 * a publish; without it the new bytes wait in a file of their own, and title, caption and alt on the attachment,
	 * for the next release (docs/spec/cms-bridge.md §5). Pages, CMS fields and CSS that show the attachment online
	 * keep its live file and texts until then.
	 *
	 * @param array<string, mixed>                                                               $entity
	 * @param list<array{attachment: int, from: list<string>, to: string, retire: list<string>}> $moves  Files the
	 *        documents should leave for another, and the files the send no longer needs.
	 * @return array{0: int, 1: bool} The attachment, and whether its change waits for a release.
	 */
	private static function import_asset( array $entity, string $bytes, ?string $before_hash, bool $published, array &$moves, string $operation_id = '' ): array {
		$existing = self::post_by_profile_id( $entity['id'], 'attachment' );
		$payload  = $entity['payload'];
		$wanted   = array(
			'sha256'    => (string) $payload['sha256'],
			'mediaType' => (string) $payload['mediaType'],
			'title'     => sanitize_text_field( (string) $payload['title'] ),
			'caption'   => sanitize_text_field( (string) $payload['caption'] ),
			'alt'       => sanitize_text_field( (string) $payload['alt'] ),
		);
		if ( ! $existing instanceof \WP_Post ) {
			return array( self::attach_media_file( null, self::upload_media( $payload, $bytes ), true, $entity['id'], $wanted ), false );
		}
		$live       = self::live_media( $existing );
		$waiting    = self::pending_media( $existing );
		$same_bytes = hash_equals( $live['sha256'], $wanted['sha256'] );
		// The file an earlier send left waiting (even if WordPress has changed the attachment since).
		$stored       = json_decode( (string) get_post_meta( $existing->ID, self::PENDING_MEDIA_META, true ), true );
		$waiting_file = is_array( $stored ) && is_string( $stored['file'] ?? null ) && null !== self::upload_path( $stored['file'] ) ? $stored['file'] : null;
		$from         = array_values( array_filter( array_merge( array( $live['file'] ), self::media_aliases( $existing->ID ), null !== $waiting_file ? array( $waiting_file ) : array() ) ) );
		if ( ! $same_bytes && null !== $before_hash && ! hash_equals( $before_hash, (string) ( $entity['baseHash'] ?? '' ) ) ) {
			throw new \DomainException( 'Media update does not match the last common base.' );
		}
		$reuse = null !== $waiting && null !== $waiting['file'] && hash_equals( $waiting['sha256'], $wanted['sha256'] ) ? $waiting['file'] : null;

		if ( ! $published ) {
			if ( $same_bytes && self::same_media_texts( $live, $wanted ) ) {
				// Back to what is live (a rollback, or the CMS took its change back): nothing waits any more.
				self::forget_pending_media( $existing->ID );
				if ( null !== $waiting_file ) {
					$moves[] = array( 'attachment' => $existing->ID, 'from' => array( $waiting_file ), 'to' => (string) wp_get_attachment_url( $existing->ID ), 'retire' => array( $waiting_file ) );
				}
				return array( $existing->ID, false );
			}
			if ( null !== $waiting && hash_equals( $waiting['sha256'], $wanted['sha256'] ) && $waiting['mediaType'] === $wanted['mediaType'] && self::same_media_texts( $waiting, $wanted ) ) {
				return array( $existing->ID, true );
			}
			$file = null;
			if ( ! $same_bytes ) {
				$file = $reuse ?? _wp_relative_upload_path( self::upload_media( $payload, $bytes ) );
			}
			// The send it comes from: it goes online only once that send is complete (M6).
			update_post_meta( $existing->ID, self::PENDING_MEDIA_META, wp_slash( (string) wp_json_encode( $wanted + array( 'file' => $file, 'live' => $live, 'operation' => $operation_id ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
			if ( null !== $file ) {
				update_post_meta( $existing->ID, self::PENDING_FILE_META, $file );
				self::media_used( $wanted['sha256'] );
			} else {
				delete_post_meta( $existing->ID, self::PENDING_FILE_META );
			}
			$retire = null !== $waiting_file && $waiting_file !== $file ? array( $waiting_file ) : array();
			if ( null !== $file || array() !== $retire ) {
				$moves[] = array( 'attachment' => $existing->ID, 'from' => $from, 'to' => null !== $file ? (string) self::upload_url( $file ) : (string) wp_get_attachment_url( $existing->ID ), 'retire' => $retire );
			}
			return array( $existing->ID, true );
		}

		// A publish: the change goes online now, and replaces whatever an earlier send left waiting.
		self::forget_pending_media( $existing->ID );
		if ( $same_bytes ) {
			if ( ! self::same_media_texts( $live, $wanted ) ) {
				self::write_media_texts( $existing->ID, $wanted );
			}
			if ( null !== $waiting_file ) {
				$moves[] = array( 'attachment' => $existing->ID, 'from' => array( $waiting_file ), 'to' => (string) wp_get_attachment_url( $existing->ID ), 'retire' => array( $waiting_file ) );
			}
			return array( $existing->ID, false );
		}
		$attachment_id = null !== $reuse
			? self::attach_media_file( $existing->ID, (string) self::upload_path( $reuse ), false, $entity['id'], $wanted )
			: self::attach_media_file( $existing->ID, self::upload_media( $payload, $bytes ), true, $entity['id'], $wanted );
		$moves[] = array( 'attachment' => $attachment_id, 'from' => $from, 'to' => (string) wp_get_attachment_url( $attachment_id ), 'retire' => null !== $waiting_file && $waiting_file !== $reuse ? array( $waiting_file ) : array() );
		return array( $attachment_id, false );
	}

	/**
	 * Writes transferred bytes to a new file in the uploads folder, named after the media title.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function upload_media( array $payload, string $bytes ): string {
		$extension = match ( $payload['mediaType'] ) {
			'image/avif' => 'avif', 'image/gif' => 'gif', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
			'application/pdf' => 'pdf',
			'font/otf' => 'otf', 'font/ttf' => 'ttf', 'font/woff' => 'woff', 'font/woff2' => 'woff2',
			default => throw new \DomainException( 'Unsupported media type.' ),
		};
		// Written by a send that was interrupted before it used the file: the same bytes, no second copy.
		$sha      = (string) $payload['sha256'];
		$written  = (array) get_option( self::WRITTEN_MEDIA_OPTION, array() );
		$earlier  = self::written_file( $written[ $sha ] ?? null );
		$previous = null !== $earlier ? self::upload_path( $earlier ) : null;
		if ( null !== $previous && is_file( $previous ) && hash_file( 'sha256', $previous ) === $sha ) {
			return $previous;
		}
		$filename = sanitize_file_name( (string) $payload['title'] );
		if ( '' === $filename ) {
			$filename = 'histos-' . substr( $sha, 0, 12 );
		}
		if ( ! str_ends_with( strtolower( $filename ), '.' . $extension ) ) {
			// Sanitized again whole: «foto.php» + «.png» must not keep an inner extension a server could run.
			$filename = sanitize_file_name( $filename . '.' . $extension );
			$filename = '' === $filename ? 'histos-' . substr( $sha, 0, 12 ) . '.' . $extension : $filename;
		}
		// WordPress refuses fonts by default; these come from a checked profile, and the bytes must say what they are.
		$fonts = array( 'otf' => 'font/otf', 'ttf' => 'font/ttf', 'woff' => 'font/woff', 'woff2' => 'font/woff2' );
		if ( isset( $fonts[ $extension ] ) && ! in_array( substr( $bytes, 0, 4 ), array( "OTTO", "\x00\x01\x00\x00", 'true', 'wOFF', 'wOF2' ), true ) ) {
			throw new \DomainException( 'A transferred font is not a font: ' . $filename );
		}
		$allow = static fn ( array $mimes ): array => $mimes + $fonts;
		add_filter( 'upload_mimes', $allow );
		$uploaded = wp_upload_bits( $filename, null, $bytes );
		remove_filter( 'upload_mimes', $allow );
		if ( ! empty( $uploaded['error'] ) ) {
			throw new \RuntimeException( 'Could not write a transferred media file (' . $filename . '): ' . wp_strip_all_tags( (string) $uploaded['error'] ) );
		}
		$written[ $sha ] = array(
			'file' => _wp_relative_upload_path( (string) $uploaded['file'] ),
			'at'   => time(),
		);
		update_option( self::WRITTEN_MEDIA_OPTION, $written, false );
		return (string) $uploaded['file'];
	}

	/** The file of an entry of the written media: `{file, at}`, or the bare path of earlier versions. */
	private static function written_file( mixed $entry ): ?string {
		$file = is_array( $entry ) ? ( $entry['file'] ?? null ) : $entry;
		return is_string( $file ) && '' !== $file ? $file : null;
	}

	/**
	 * Files written by a send that was never taken up again: after WRITTEN_MEDIA_DAYS a later send deletes them, as
	 * long as no attachment has them (as its file or an earlier one). An entry of an earlier version, without a date,
	 * gets one now. At most 200 a time, so a send never waits long on it.
	 */
	private static function prune_written_media(): void {
		$written = (array) get_option( self::WRITTEN_MEDIA_OPTION, array() );
		if ( array() === $written ) {
			return;
		}
		global $wpdb;
		$limit   = time() - self::WRITTEN_MEDIA_DAYS * DAY_IN_SECONDS;
		$changed = false;
		$done    = 0;
		foreach ( $written as $sha => $entry ) {
			$file = self::written_file( $entry );
			if ( null === $file ) {
				unset( $written[ $sha ] );
				$changed = true;
				continue;
			}
			if ( ! is_array( $entry ) || ! is_int( $entry['at'] ?? null ) ) {
				$written[ $sha ] = array( 'file' => $file, 'at' => time() );
				$changed         = true;
				continue;
			}
			if ( $entry['at'] > $limit || $done >= 200 ) {
				continue;
			}
			++$done;
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_wp_attached_file', %s) AND meta_value = %s", self::MEDIA_ALIAS_META, $file ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$path = self::upload_path( $file );
			if ( 0 === $used && null !== $path && is_file( $path ) ) {
				wp_delete_file( $path );
			}
			unset( $written[ $sha ] );
			$changed = true;
		}
		if ( $changed ) {
			update_option( self::WRITTEN_MEDIA_OPTION, $written, false );
		}
	}

	/** A written file now belongs to an attachment or a waiting change: no later send may take it for its own. */
	private static function media_used( string $sha ): void {
		$written = (array) get_option( self::WRITTEN_MEDIA_OPTION, array() );
		if ( isset( $written[ $sha ] ) ) {
			unset( $written[ $sha ] );
			update_option( self::WRITTEN_MEDIA_OPTION, $written, false );
		}
	}

	/**
	 * Makes a file the attachment's own (a new attachment when `$attachment_id` is null), with its sizes, identity,
	 * hash and texts. The file it replaces stays, and still leads to the attachment: a release or page not sent
	 * again can show it.
	 *
	 * @param array{sha256: string, mediaType: string, title: string, caption: string, alt: string} $media
	 */
	private static function attach_media_file( ?int $attachment_id, string $file, bool $fresh, string $profile_id, array $media ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$previous   = null !== $attachment_id ? (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) : '';
		$attachment = array(
			'post_mime_type' => $media['mediaType'],
			'post_title'     => $media['title'],
			'post_excerpt'   => $media['caption'],
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		if ( null !== $attachment_id ) {
			$attachment['ID'] = $attachment_id;
		}
		$inserted = wp_insert_attachment( wp_slash( $attachment ), $file, 0, true );
		if ( is_wp_error( $inserted ) ) {
			if ( $fresh ) {
				wp_delete_file( $file );
			}
			throw new \RuntimeException( 'Could not register a transferred attachment: ' . $inserted->get_error_message() );
		}
		$inserted = (int) $inserted;
		wp_update_attachment_metadata( $inserted, wp_generate_attachment_metadata( $inserted, $file ) );
		update_post_meta( $inserted, self::MEDIA_ID_META, $profile_id );
		update_post_meta( $inserted, self::MEDIA_HASH_META, $media['sha256'] );
		update_post_meta( $inserted, '_wp_attachment_image_alt', $media['alt'] );
		self::media_used( $media['sha256'] );
		if ( '' !== $previous && $previous !== (string) get_post_meta( $inserted, '_wp_attached_file', true ) ) {
			self::remember_media_alias( $inserted, $previous );
		}
		return $inserted;
	}

	/** @param array{title: string, caption: string, alt: string} $media */
	private static function write_media_texts( int $attachment_id, array $media ): void {
		$updated = wp_update_post( wp_slash( array( 'ID' => $attachment_id, 'post_title' => $media['title'], 'post_excerpt' => $media['caption'] ) ), true );
		if ( is_wp_error( $updated ) ) {
			throw new \RuntimeException( 'Could not update transferred media metadata: ' . $updated->get_error_message() );
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $media['alt'] );
	}

	/**
	 * An attachment as it is live: what a waiting change was staged over. If WordPress changes it afterwards, the
	 * waiting change is stale and never covers nor replaces that edit.
	 *
	 * @return array{sha256: string, file: string, mediaType: string, title: string, caption: string, alt: string}
	 */
	private static function live_media( \WP_Post $attachment ): array {
		return array(
			'sha256'    => (string) get_post_meta( $attachment->ID, self::MEDIA_HASH_META, true ),
			'file'      => (string) get_post_meta( $attachment->ID, '_wp_attached_file', true ),
			'mediaType' => (string) $attachment->post_mime_type,
			'title'     => $attachment->post_title,
			'caption'   => $attachment->post_excerpt,
			'alt'       => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private static function same_media_texts( array $a, array $b ): bool {
		return $a['title'] === $b['title'] && $a['caption'] === $b['caption'] && $a['alt'] === $b['alt'] && $a['mediaType'] === $b['mediaType'];
	}

	/**
	 * The change waiting for a release on an attachment, if it still applies to the attachment as it is live and
	 * its file is whole.
	 *
	 * @return array{sha256: string, mediaType: string, title: string, caption: string, alt: string, file: ?string}|null
	 */
	private static function pending_media( \WP_Post $attachment ): ?array {
		if ( 'attachment' !== $attachment->post_type || 'inherit' !== $attachment->post_status ) {
			return null;
		}
		$stored = json_decode( (string) get_post_meta( $attachment->ID, self::PENDING_MEDIA_META, true ), true );
		if ( ! is_array( $stored ) || ! is_string( $stored['sha256'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $stored['sha256'] ) || ! in_array( $stored['mediaType'] ?? null, self::ALLOWED_CSS_MEDIA, true ) ) {
			return null;
		}
		foreach ( array( 'title', 'caption', 'alt' ) as $key ) {
			if ( ! is_string( $stored[ $key ] ?? null ) ) {
				return null;
			}
		}
		if ( ( $stored['live'] ?? null ) !== self::live_media( $attachment ) ) {
			return null;
		}
		// A send that failed, was interrupted or is still running elsewhere leaves nothing to release (M6). Older
		// entries carry no send: they stay as before.
		$operation = $stored['operation'] ?? '';
		if ( is_string( $operation ) && '' !== $operation && $operation !== self::$running_operation ) {
			$status = get_option( 'xb_remote_operation_' . $operation );
			if ( ! is_array( $status ) || 'complete' !== ( $status['state'] ?? null ) ) {
				return null;
			}
		}
		$file = $stored['file'] ?? null;
		if ( null === $file ) {
			if ( ! hash_equals( $stored['live']['sha256'], $stored['sha256'] ) ) {
				return null;
			}
		} else {
			$path = is_string( $file ) ? self::upload_path( $file ) : null;
			if ( null === $path || ! is_readable( $path ) || ! hash_equals( $stored['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return null;
			}
		}
		return array(
			'sha256'    => $stored['sha256'],
			'mediaType' => $stored['mediaType'],
			'title'     => $stored['title'],
			'caption'   => $stored['caption'],
			'alt'       => $stored['alt'],
			'file'      => $file,
		);
	}

	/**
	 * How many media changes from Histeon CMS wait for a release and would go online with it (M6): a send that only
	 * changes a media's texts or file has no document to release, so the release list shows them as one item.
	 */
	public static function pending_media_count(): int {
		$count = 0;
		$ids   = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => self::PENDING_MEDIA_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only media sent by Histeon CMS carry it.
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$attachment = get_post( (int) $id );
			if ( $attachment instanceof \WP_Post && null !== self::pending_media( $attachment ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * The media changes waiting for a release, by attachment id, with what each one carries (its sha256): the
	 * scheduled release compares them with those of when it was scheduled (docs/spec/versioning.md §11.4 a).
	 *
	 * @return array<int, string>
	 */
	public static function pending_media_marks(): array {
		$out = array();
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => self::PENDING_MEDIA_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only media sent by Histeon CMS carry it.
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$attachment = get_post( (int) $id );
			$pending    = $attachment instanceof \WP_Post ? self::pending_media( $attachment ) : null;
			if ( null !== $pending ) {
				$out[ (int) $id ] = (string) $pending['sha256'];
			}
		}
		return $out;
	}

	private static function forget_pending_media( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::PENDING_MEDIA_META );
		delete_post_meta( $attachment_id, self::PENDING_FILE_META );
	}

	/** The address of an attachment as in development: its waiting file, if a release will give it one. */
	private static function dev_media_url( int $attachment_id ): string|false {
		$attachment = get_post( $attachment_id );
		$pending    = $attachment instanceof \WP_Post ? self::pending_media( $attachment ) : null;
		$url        = null !== $pending && null !== $pending['file'] ? self::upload_url( $pending['file'] ) : null;
		return $url ?? wp_get_attachment_url( $attachment_id );
	}

	/** An upload-relative path as a file under the uploads folder, or null when it would lead anywhere else. */
	private static function upload_path( string $file ): ?string {
		if ( '' === $file || 0 !== validate_file( $file ) || str_starts_with( $file, '/' ) ) {
			return null;
		}
		$basedir = (string) wp_upload_dir( null, false )['basedir'];
		return '' === $basedir ? null : trailingslashit( $basedir ) . $file;
	}

	private static function upload_url( string $file ): ?string {
		$baseurl = (string) wp_upload_dir( null, false )['baseurl'];
		return null === self::upload_path( $file ) || '' === $baseurl ? null : trailingslashit( $baseurl ) . $file;
	}

	/** @return list<string> Earlier files of an attachment that still lead to it. */
	private static function media_aliases( int $attachment_id ): array {
		return array_values( array_filter( array_map( 'strval', (array) get_post_meta( $attachment_id, self::MEDIA_ALIAS_META, false ) ), static fn ( string $file ): bool => null !== self::upload_path( $file ) ) );
	}

	private static function remember_media_alias( int $attachment_id, string $file ): void {
		if ( ! in_array( $file, self::media_aliases( $attachment_id ), true ) ) {
			add_post_meta( $attachment_id, self::MEDIA_ALIAS_META, $file );
		}
	}

	/**
	 * The attachment an uploads URL shows: its own file or a size of it, the file waiting for a release, or an
	 * earlier file it had.
	 */
	private static function attachment_for_url( string $url ): int {
		$attachment_id = \XB\Versioning\SiteTransfer::attachment_for( $url );
		if ( $attachment_id > 0 ) {
			return $attachment_id;
		}
		$baseurl = trailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		if ( ! str_starts_with( $url, $baseurl ) ) {
			return 0;
		}
		$file  = substr( $url, strlen( $baseurl ) );
		$files = array_values( array_unique( array( $file, (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $file ) ) ) );
		$found = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 2,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only media sent by Histeon CMS carry these.
					'relation' => 'OR',
					array( 'key' => self::PENDING_FILE_META, 'value' => $files, 'compare' => 'IN' ),
					array( 'key' => self::MEDIA_ALIAS_META, 'value' => $files, 'compare' => 'IN' ),
				),
			)
		);
		return 1 === count( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Documents in development that show an attachment by one of `$from` (or a size of it) take `$to` instead.
	 *
	 * @param list<string> $from Upload-relative files.
	 * @param list<string> $skip Documents the send has just written.
	 * @return list<string> The documents rewritten whose development copy was live: this is all a release of them carries.
	 */
	private static function follow_media( array $from, string $to, array $skip ): array {
		$stems = array_values( array_unique( array_filter( array_map( static fn ( string $file ): string => (string) preg_replace( '/\.[a-z0-9]+$/i', '', $file ), $from ) ) ) );
		if ( array() === $stems || '' === $to ) {
			return array();
		}
		$known      = array_fill_keys( $from, true );
		$baseurl    = trailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		$versioning = \XB\Plugin::instance()->versioning;
		$store      = $versioning->store();
		$clean      = array();
		foreach ( $store->docs( \XB\Versioning\Store::DEV ) as $doc ) {
			if ( null === Documents::post_id( $doc ) || in_array( $doc, $skip, true ) || ! Documents::valid( $doc ) ) {
				continue;
			}
			$head    = $store->get( $store->head( $doc, \XB\Versioning\Store::DEV ) );
			$content = is_array( $head ) ? ( $head['payload']['content'] ?? null ) : null;
			if ( ! is_string( $content ) || array() === array_filter( $stems, static fn ( string $stem ): bool => str_contains( $content, $stem ) ) ) {
				continue;
			}
			$copy   = $versioning->working_copy( $doc );
			$blocks = parse_blocks( (string) ( $copy['payload']['content'] ?? '' ) );
			$count  = 0;
			$visit  = static function ( array &$items ) use ( &$visit, &$count, $known, $baseurl, $to ): void {
				foreach ( $items as &$block ) {
					$src = $block['attrs']['attributes']['src'] ?? null;
					if ( 'xb/element' === ( $block['blockName'] ?? null ) && is_string( $src ) && $src !== $to && str_starts_with( $src, $baseurl ) ) {
						$file = substr( $src, strlen( $baseurl ) );
						if ( isset( $known[ $file ] ) || isset( $known[ (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $file ) ] ) ) {
							$block['attrs']['attributes']['src'] = $to;
							++$count;
						}
					}
					// A srcset candidate follows only when it is the file itself: a size of it has its own descriptor.
					$srcset     = $block['attrs']['attributes']['srcset'] ?? null;
					$candidates = 'xb/element' === ( $block['blockName'] ?? null ) && in_array( $block['attrs']['tag'] ?? null, array( 'img', 'source' ), true ) && is_string( $srcset ) ? TransferMarkup::parse_srcset( $srcset ) : null;
					if ( null !== $candidates ) {
						$moved = 0;
						foreach ( $candidates as &$candidate ) {
							if ( $candidate['url'] !== $to && str_starts_with( $candidate['url'], $baseurl ) && isset( $known[ substr( $candidate['url'], strlen( $baseurl ) ) ] ) ) {
								$candidate['url'] = $to;
								++$moved;
							}
						}
						unset( $candidate );
						if ( $moved > 0 ) {
							$block['attrs']['attributes']['srcset'] = self::format_srcset( $candidates );
							$count += $moved;
						}
					}
					if ( is_array( $block['innerBlocks'] ?? null ) && array() !== $block['innerBlocks'] ) {
						$visit( $block['innerBlocks'] );
					}
				}
				unset( $block );
			};
			$visit( $blocks );
			if ( 0 === $count ) {
				continue;
			}
			$was_live = $copy['head'] === $copy['base'];
			try {
				$versioning->save_dev( $doc, array( 'content' => serialize_blocks( $blocks ) ) + $copy['payload'], $copy['head'], $copy['base'], __( 'Immagine sostituita da Histeon CMS', 'xb' ), true );
			} catch ( \XB\Versioning\VersioningException $e ) {
				throw new \RuntimeException( sprintf( /* translators: 1: document, 2: error message */ __( '%1$s non salvato in sviluppo: %2$s', 'xb' ), Documents::label( $doc ), $e->getMessage() ), 0, $e );
			}
			if ( $was_live ) {
				$clean[] = $doc;
			}
		}
		return $clean;
	}

	/**
	 * A file of an attachment the send or the release no longer needs: deleted, unless a document in development
	 * or online still shows it (then it keeps leading to the attachment).
	 */
	private static function retire_media_file( int $attachment_id, string $file ): void {
		$path = self::upload_path( $file );
		if ( null === $path || $file === (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) || in_array( $file, self::media_aliases( $attachment_id ), true ) ) {
			return;
		}
		$store   = \XB\Plugin::instance()->versioning->store();
		$needles = array( $file, str_replace( '/', '\\/', $file ) );
		foreach ( array( \XB\Versioning\Store::DEV, \XB\Versioning\Store::PROD ) as $branch ) {
			foreach ( $store->docs( $branch ) as $doc ) {
				$commit  = $store->get( $store->head( $doc, $branch ) );
				$encoded = is_array( $commit ) ? (string) wp_json_encode( $commit['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
				foreach ( $needles as $needle ) {
					if ( str_contains( $encoded, $needle ) ) {
						self::remember_media_alias( $attachment_id, $file );
						return;
					}
				}
			}
		}
		wp_delete_file_from_directory( $path, (string) wp_upload_dir( null, false )['basedir'] );
	}

	/**
	 * A Histeon release went online (xb_release_published): media changes waiting for it become live, the new file
	 * with its sizes, title, caption and alt. One whose attachment WordPress changed after the send is dropped: that
	 * edit wins (`cms_queue_stale`). An error is logged (`cms_queue_failed`) and never fails the release.
	 *
	 * @param list<string> $docs
	 */
	public static function apply_pending_media( int $release_id, array $docs = array() ): void {
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_key'         => self::PENDING_MEDIA_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only media sent by Histeon CMS carry it.
				'suppress_filters' => true,
			)
		);
		if ( array() === $attachments ) {
			return;
		}
		$audit   = \XB\Plugin::instance()->versioning->store();
		$applied = 0;
		foreach ( $attachments as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			try {
				$attachment = get_post( $attachment_id );
				$pending    = $attachment instanceof \WP_Post ? self::pending_media( $attachment ) : null;
				$stored     = json_decode( (string) get_post_meta( $attachment_id, self::PENDING_MEDIA_META, true ), true );
				$file       = is_array( $stored ) && is_string( $stored['file'] ?? null ) ? $stored['file'] : null;
				if ( null === $pending ) {
					self::forget_pending_media( $attachment_id );
					if ( null !== $file ) {
						self::retire_media_file( $attachment_id, $file );
					}
					$audit->audit( 'cms_queue_stale', 'post:' . $attachment_id, array( 'release' => $release_id ) );
					continue;
				}
				if ( null !== $pending['file'] ) {
					self::attach_media_file( $attachment_id, (string) self::upload_path( $pending['file'] ), false, (string) get_post_meta( $attachment_id, self::MEDIA_ID_META, true ), $pending );
				} else {
					self::write_media_texts( $attachment_id, $pending );
				}
				self::forget_pending_media( $attachment_id );
				++$applied;
			} catch ( \Throwable $e ) {
				$audit->audit( 'cms_queue_failed', 'release:' . $release_id, array( 'error' => mb_substr( $e->getMessage(), 0, 300 ) ) );
			}
		}
		if ( $applied > 0 ) {
			$audit->audit( 'cms_queue_applied', 'release:' . $release_id, array( 'media' => $applied ) );
		}
	}

	/**
	 * @param string|list<string> $type
	 * @return \WP_Post|null
	 */
	private static function post_by_profile_id( string $profile_id, string|array $type = 'page' ): ?\WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'inherit', 'trash' ),
				'posts_per_page' => 2,
				'meta_key'       => self::PAGE_ID_META,
				'meta_value'     => $profile_id,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		if ( count( $posts ) > 1 ) {
			throw new \DomainException( 'More than one WordPress entity has the same Histeon identity.' );
		}
		return $posts[0] ?? null;
	}

	/** The deterministic, collision-resistant theme slug used for imported Histeon FSE templates. */
	/**
	 * The slug an existing template should have: its role's; a private one when it loses its role; otherwise the
	 * one it has (a template made in WordPress keeps its name and the pages that use it).
	 */
	private static function wanted_template_slug( \WP_Post $template, string $ref, mixed $role ): string {
		if ( is_string( $role ) || in_array( $template->post_name, PageProfile::TEMPLATE_ROLES, true ) ) {
			return self::profile_template_slug( $ref, $role );
		}
		return $template->post_name;
	}

	/**
	 * Whether a project template holding a slug gives it up in the incoming entities (its role moves or goes).
	 *
	 * @param list<array<string, mixed>> $entities
	 */
	private static function frees_slug_in( \WP_Post $holder, string $slug, array $entities ): bool {
		$id = (string) get_post_meta( $holder->ID, self::PAGE_ID_META, true );
		if ( ! self::valid_uuid( $id ) ) {
			return false;
		}
		foreach ( $entities as $entity ) {
			if ( 'template' === ( $entity['kind'] ?? null ) && $id === ( $entity['id'] ?? null ) ) {
				return self::wanted_template_slug( $holder, (string) $entity['payload']['ref'], $entity['payload']['role'] ?? null ) !== $slug;
			}
		}
		return false;
	}

	/**
	 * Before the fields of templates are published: the ones leaving a role's slug move to their private slug
	 * first, so a template taking that role (or two templates swapping) never meets the old holder.
	 *
	 * @param array<int, array<string, mixed>> $fields_by_post Template post id → the fields about to be published.
	 */
	private static function release_role_slugs( array $fields_by_post ): void {
		foreach ( $fields_by_post as $post_id => $fields ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || ! is_string( $fields['ref'] ?? null ) || ! array_key_exists( 'role', $fields ) || ! in_array( $post->post_name, PageProfile::TEMPLATE_ROLES, true ) ) {
				continue;
			}
			if ( self::wanted_template_slug( $post, $fields['ref'], $fields['role'] ) === $post->post_name ) {
				continue;
			}
			$private = self::profile_template_slug( $fields['ref'] );
			$result  = wp_update_post( array( 'ID' => $post_id, 'post_name' => $private ), true );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( sprintf( /* translators: %s: error message */ __( 'Documento non pubblicato: %s', 'xb' ), $result->get_error_message() ) );
			}
			self::follow_template_slug( $post_id, $private );
		}
	}

	/** A template with a role takes the role's slug (it replaces the theme's template), otherwise a private one. */
	private static function profile_template_slug( string $ref, mixed $role = null ): string {
		if ( is_string( $role ) && in_array( $role, PageProfile::TEMPLATE_ROLES, true ) ) {
			return $role;
		}
		return 'histos-' . substr( hash( 'sha256', $ref ), 0, 24 );
	}

	/**
	 * The active theme's templates saved in the database with a slug (Site Editor customizations and Histeon ones).
	 *
	 * @return list<\WP_Post>
	 */
	private static function theme_templates_named( string $slug ): array {
		return array_values( get_posts(
			array(
				'post_type'        => 'wp_template',
				'name'             => $slug,
				'post_status'      => array( 'draft', 'publish', 'private', 'pending', 'future', 'trash' ),
				'posts_per_page'   => 10,
				'suppress_filters' => true,
				'tax_query'        => array( array( 'taxonomy' => 'wp_theme', 'field' => 'slug', 'terms' => array( get_stylesheet() ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery -- active-theme templates only.
			)
		) );
	}

	/**
	 * The WordPress post type of a profile entity: pages and posts by their document type.
	 *
	 * @param array<string, mixed> $entity
	 */
	private static function entity_post_type( array $entity ): string {
		if ( 'document' === ( $entity['kind'] ?? null ) ) {
			return 'post' === ( $entity['payload']['documentType'] ?? null ) ? 'post' : 'page';
		}
		return self::profile_post_type( (string) ( $entity['kind'] ?? '' ) );
	}

	private static function is_profile_template( \WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
			return false;
		}
		$profile_id   = (string) get_post_meta( $post->ID, self::PAGE_ID_META, true );
		$profile_ref  = (string) get_post_meta( $post->ID, self::TEMPLATE_REF_META, true );
		$component_ref = (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true );
		$managed      = '1' === (string) get_post_meta( $post->ID, self::MANAGED_TEMPLATE_META, true );
		$has_histos   = 1 === preg_match( '/<!--\\s*wp:xb\\//', $post->post_content );
		return $managed || self::valid_uuid( $profile_id ) || 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $profile_ref ) || 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $component_ref ) || $has_histos;
	}

	private static function is_profile_page( \WP_Post $post ): bool {
		$profile_id   = (string) get_post_meta( $post->ID, self::PAGE_ID_META, true );
		$template_ref = (string) get_post_meta( $post->ID, self::TEMPLATE_ASSIGNMENT_META, true );
		return self::valid_uuid( $profile_id ) || 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $template_ref ) || \XB\Spec::has_xb_blocks( $post->post_content );
	}

	private static function profile_ref_for_template( \WP_Post $post, bool $persist = true ): string {
		$stored_ref = (string) get_post_meta( $post->ID, self::TEMPLATE_REF_META, true );
		if ( 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $stored_ref ) ) {
			return $stored_ref;
		}
		$component_ref = (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true );
		if ( 1 === preg_match( \XB\Spec::COMPONENT_REF_PATTERN, $component_ref ) ) {
			if ( $persist ) {
				self::later( static fn () => update_post_meta( $post->ID, self::TEMPLATE_REF_META, $component_ref ) );
			}
			return $component_ref;
		}
		$slug = sanitize_title( $post->post_name );
		// A role's slug may start with a digit (`404`); a ref segment may not.
		if ( 'wp_template' === $post->post_type && in_array( $slug, PageProfile::TEMPLATE_ROLES, true ) && 1 !== preg_match( '/^[a-z]/', $slug ) ) {
			$slug = 'role-' . $slug;
		}
		if ( '' === $slug || 1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $slug ) ) {
			throw new \DomainException( 'A Histeon template has a slug that cannot be transferred.' );
		}
		$prefix = 'wp_template_part' === $post->post_type ? 'theme-part' : 'theme-template';
		$ref    = $prefix . '/' . $slug;
		if ( $persist ) {
			self::later(
				static function () use ( $post, $ref ): void {
					update_post_meta( $post->ID, self::TEMPLATE_REF_META, $ref );
					update_post_meta( $post->ID, self::MANAGED_TEMPLATE_META, '1' );
				}
			);
		}
		return $ref;
	}

	/** Map a portable project entity to its WordPress post type. */
	private static function profile_post_type( string $kind ): string {
		return match ( $kind ) {
			'component' => 'wp_block',
			'template-part' => 'wp_template_part',
			'template' => 'wp_template',
			'document' => 'page',
			'asset' => 'attachment',
			default => throw new \InvalidArgumentException( 'Unsupported Histeon project entity kind.' ),
		};
	}

	private static function identity( \WP_Post $post, string $meta_key, bool $create = true ): string {
		$id = (string) get_post_meta( $post->ID, $meta_key, true );
		if ( ! self::valid_uuid( $id ) ) {
			if ( ! $create ) {
				throw new \DomainException( 'Download the current page profile once before updating it.' );
			}
			$id = self::derived_id( $meta_key . ':' . $post->ID );
			self::later( static fn () => update_post_meta( $post->ID, $meta_key, $id ) );
		}
		$duplicates = get_posts( array( 'post_type' => $post->post_type, 'post_status' => 'any', 'posts_per_page' => 2, 'meta_key' => $meta_key, 'meta_value' => $id ) );
		if ( count( $duplicates ) > 1 ) {
			throw new \DomainException( 'Duplicate Histeon identity found in WordPress.' );
		}
		return $id;
	}

	private static function styles_id( bool $create = true ): string {
		$id = (string) get_option( 'xb_page_profile_styles_id', '' );
		if ( ! self::valid_uuid( $id ) ) {
			if ( ! $create ) {
				throw new \DomainException( 'Download the current page profile once before updating global styles.' );
			}
			$id = self::derived_id( 'styles' );
			self::later( static fn () => update_option( 'xb_page_profile_styles_id', $id, false ) );
		}
		return $id;
	}

	private static function site_id(): string {
		$id = (string) get_option( 'xb_remote_site_id', '' );
		if ( ! self::valid_uuid( $id ) ) {
			$id = self::uuid();
			update_option( 'xb_remote_site_id', $id, false );
		}
		return $id;
	}

	private static function uuid(): string {
		return function_exists( 'wp_generate_uuid4' ) ? strtolower( wp_generate_uuid4() ) : sprintf( '%08x-%04x-4%03x-%04x-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffff ), random_int( 0, 0x0fff ), random_int( 0x8000, 0xbfff ), random_int( 0, 0xffffffffffff ) );
	}

	private static function valid_uuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}

	/**
	 * @param list<array<string, mixed>> $entities
	 * @return list<array<string, mixed>>
	 */
	private static function sort_entities( array $entities ): array {
		usort( $entities, static fn ( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );
		return $entities;
	}

	/**
	 * Merge entities contributed by the page and relational snapshots.
	 * A media attachment may be referenced by a page and by one or more relational records, but its
	 * portable identity must appear only once in the transfer profile.
	 *
	 * @param list<array<string, mixed>> $entities
	 * @return list<array<string, mixed>>
	 */
	private static function merge_snapshot_entities( array $entities ): array {
		$by_id = array();
		foreach ( $entities as $entity ) {
			$id = $entity['id'] ?? null;
			if ( ! is_string( $id ) || '' === $id ) {
				throw new \DomainException( 'The WordPress snapshot contains an entity without a portable identity.' );
			}
			if ( isset( $by_id[ $id ] ) ) {
				if ( PageProfile::canonical( $by_id[ $id ] ) !== PageProfile::canonical( $entity ) ) {
					throw new \DomainException( 'The WordPress snapshot contains conflicting copies of a portable entity.' );
				}
				continue;
			}
			$by_id[ $id ] = $entity;
		}
		return array_values( $by_id );
	}
}
