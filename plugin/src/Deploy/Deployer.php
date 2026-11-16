<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Deploy;

use XB\Plugin;
use XB\StaticSite\Bundle;
use XB\StaticSite\Exporter;
use XB\StaticSite\Scanner;
use XB\StaticSite\SecurityHeaders;
use XB\Versioning\Schema;
use XB\Versioning\Versioning;

/**
 * Builds the static bundle of what is live and deploys it to Cloudflare Workers Static Assets.
 * One deploy = one Worker version, tagged with the XB release; rollback = deploying an older version.
 */
final class Deployer {

	public const MAX_FILES = 20000; // Workers Free; Paid allows 100k (filter xb_cloudflare_max_files).
	public const AUTO      = 'xb_deploy_on_release';
	/** Post meta: when a content last left `publish` (draft, pending, private, trash), for the rollback warning. */
	public const WITHDRAWN_META = '_xb_withdrawn';
	/** Withdrawn contents named in a rollback warning; the rest are counted. */
	private const WITHDRAWN_NAMED = 10;
	/** Upload request size: whole buckets are split so memory stays bounded. */
	private const MAX_REQUEST_BYTES = 40 * 1024 * 1024;

	public function __construct( private readonly ?CloudflareClient $client = null ) {}

	/**
	 * @param bool $scheduled True only when called by the xb_static_deploy event that a release scheduled.
	 * @return array{id: int, version: string, files: int, uploaded: int, findings: list<array<string, string>>, warnings: list<string>}
	 */
	public function deploy( string $message = '', bool $scheduled = false ): array {
		if ( ! $scheduled && ! current_user_can( 'xb_publish' ) && ! self::cli() ) {
			throw new DeployException( __( 'Serve il permesso xb_publish per pubblicare su Cloudflare.', 'xb' ), 403 );
		}
		return Plugin::instance()->versioning->store()->locked( 'deploy', fn (): array => $this->run( $message ) );
	}

	/** @return array{id: int, version: string, files: int, uploaded: int, findings: list<array<string, string>>, warnings: list<string>} */
	private function run( string $message ): array {
		$client  = $this->client ?? CloudflareClient::from_settings();
		$release = (int) get_option( Versioning::OPTION_CURRENT, 0 );
		$label   = 0 < $release ? 'release-' . $release : 'manual';
		$build   = ( new Exporter() )->build( $label );
		try {
			return $this->upload( $client, $build, $release, $message );
		} finally {
			Bundle::delete( $build['dir'] ); // Cloudflare keeps the version; nothing stays on the origin.
		}
	}

	/**
	 * @param array{dir: string, pages: int, assets: int, findings: list<array{severity: string, file: string, rule: string, excerpt: string}>, log: list<string>} $build
	 * @return array{id: int, version: string, files: int, uploaded: int, findings: list<array<string, string>>, warnings: list<string>}
	 */
	private function upload( CloudflareClient $client, array $build, int $release, string $message ): array {
		if ( Scanner::blocking( $build['findings'] ) ) {
			// The excerpts may contain the very secret found: they are returned to the caller, never stored.
			$rules = array_values( array_unique( array_map( static fn ( array $f ): string => $f['rule'] . ' (' . $f['file'] . ')', array_filter( $build['findings'], static fn ( array $f ): bool => 'block' === $f['severity'] ) ) ) );
			$this->record( $release, '', 'blocked', 0, 0, null, 'Scansione: ' . implode( ', ', array_slice( $rules, 0, 10 ) ) );
			throw new DeployException( __( 'La scansione ha trovato dati che non devono finire online: deploy bloccato.', 'xb' ) . ' ' . implode( ', ', array_slice( $rules, 0, 5 ) ), 409 );
		}

		$files = Bundle::files( $build['dir'] );
		// Configuration for the host, not a file to serve: it goes with the Worker version instead.
		unset( $files[ '/' . SecurityHeaders::FILE ] );
		$max   = (int) apply_filters( 'xb_cloudflare_max_files', self::MAX_FILES );
		if ( count( $files ) > $max ) {
			throw new DeployException( sprintf( __( 'Il sito ha %1$d file, oltre il limite di %2$d per versione.', 'xb' ), count( $files ), $max ), 413 );
		}
		$manifest = array();
		$by_hash  = array();
		foreach ( $files as $path => $file ) {
			$base64              = base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$hash                = substr( hash( 'sha256', $base64 . pathinfo( $file, PATHINFO_EXTENSION ) ), 0, 32 );
			$manifest[ $path ]   = array(
				'hash' => $hash,
				'size' => (int) filesize( $file ),
			);
			$by_hash[ $hash ]    = $file;
		}

		$session  = $client->upload_session( $manifest );
		$jwt      = $session['jwt'];
		$uploaded = 0;
		foreach ( $session['buckets'] as $bucket ) {
			// Split each bucket into requests of bounded size: memory never holds more than one chunk.
			$chunk = array();
			$bytes = 0;
			foreach ( $bucket as $i => $hash ) {
				if ( ! isset( $by_hash[ $hash ] ) ) {
					continue;
				}
				$encoded         = base64_encode( (string) file_get_contents( $by_hash[ $hash ] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$chunk[ $hash ]  = $encoded;
				$bytes          += strlen( $encoded );
				$last            = $i === array_key_last( $bucket );
				if ( $bytes >= self::MAX_REQUEST_BYTES || $last ) {
					$uploaded  += count( $chunk );
					$completion = $client->upload_bucket( $session['jwt'], $chunk );
					if ( null !== $completion ) {
						$jwt = $completion;
					}
					$chunk = array();
					$bytes = 0;
				}
			}
			if ( array() !== $chunk ) {
				$uploaded  += count( $chunk );
				$completion = $client->upload_bucket( $session['jwt'], $chunk );
				if ( null !== $completion ) {
					$jwt = $completion;
				}
			}
		}

		// The forms of the static site travel from the Worker to WordPress with what visitors wrote: only over HTTPS,
		// unless this is a local site (Remote\Link::local()). Otherwise the Worker answers that forms are off.
		$endpoint = rest_url( 'xb/v1/forms' );
		$warnings = array();
		if ( ! str_starts_with( $endpoint, 'https://' ) && ! \XB\Remote\Link::local() ) {
			$endpoint   = '';
			$warnings[] = __( 'I moduli del sito statico sono spenti: l\'indirizzo REST di WordPress non è https, e i messaggi dei visitatori viaggerebbero in chiaro.', 'xb' );
		}
		$tag     = 0 < $release ? (string) ( Plugin::instance()->versioning->store()->get_release( $release )['version'] ?? '' ) : '';
		$text    = '' === $message ? ( '' === $tag ? 'Histeon deploy' : 'Histeon release ' . $tag ) : $message;
		$version = $client->upload_version(
			$jwt,
			WorkerScript::source(),
			array_filter(
				array(
					'workers/message' => mb_substr( $text, 0, 100 ),
					'workers/tag'     => $tag,
				)
			),
			array(
				'XB_FORMS_ENDPOINT'   => $endpoint,
				'XB_SECURITY_HEADERS' => (string) wp_json_encode( SecurityHeaders::all() ),
			),
			SecurityHeaders::file()
		);
		$client->deploy( $version, $text );
		$id = $this->record( $release, $version, 'live', count( $files ), $uploaded, null, $text );
		Plugin::instance()->versioning->store()->audit( 'deploy', 'cloudflare:' . $version, array( 'release' => $release, 'files' => count( $files ) ) + ( '' === $endpoint ? array( 'forms' => false ) : array() ) );
		return array(
			'id'       => $id,
			'version'  => $version,
			'files'    => count( $files ),
			'uploaded' => $uploaded,
			'findings' => $build['findings'],
			'warnings' => $warnings,
		);
	}

	private static function cli(): bool {
		return defined( 'WP_CLI' ) && true === constant( 'WP_CLI' );
	}

	/**
	 * transition_post_status: a content that leaves `publish` remembers when, so that putting back a static version
	 * built before can say it serves that content again (doc 23, voce 171). Only on a site that deploys.
	 */
	public static function note_withdrawal( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' === $old_status && 'publish' !== $new_status && Credentials::ready() ) {
			update_post_meta( $post->ID, self::WITHDRAWN_META, time() );
		}
	}

	/**
	 * Contents a deploy's static version shows that have been withdrawn since (now a draft, pending, private, scheduled
	 * or in the trash): published when that version was built, and withdrawn after. WordPress keeps no date for a
	 * change of status, so the withdrawal comes from note_withdrawal() or, for the trash, from WordPress's own
	 * `_wp_trash_meta_*`; a content deleted for good is not in the database any more and is not listed. A rollback
	 * does not withdraw them again: a new deploy does (docs/spec/static.md).
	 *
	 * @return array{count: int, items: list<string>} How many, and the first ones as «title» (status).
	 */
	public function withdrawn_since( int $deploy_id ): array {
		$built = $this->built_at( $deploy_id );
		if ( null === $built ) {
			return array( 'count' => 0, 'items' => array() );
		}
		$types = array_values( array_filter( get_post_types( array( 'public' => true ) ), static fn ( string $t ): bool => 'attachment' !== $t && is_post_type_viewable( $t ) ) );
		$query = new \WP_Query(
			array(
				'post_type'        => $types,
				'post_status'      => array( 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page'   => self::WITHDRAWN_NAMED,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'ignore_sticky_posts' => true,
				// Published by then: what was published only after the build is not in that version.
				'date_query'       => array(
					array(
						'column'    => 'post_date_gmt',
						'before'    => gmdate( 'Y-m-d H:i:s', $built ),
						'inclusive' => true,
					),
				),
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- only on the deploy panel and on a rollback.
					'relation' => 'OR',
					array(
						'key'     => self::WITHDRAWN_META,
						'value'   => $built,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
					array(
						'relation' => 'AND',
						array(
							'key'   => '_wp_trash_meta_status',
							'value' => 'publish',
						),
						array(
							'key'     => '_wp_trash_meta_time',
							'value'   => $built,
							'compare' => '>=',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$status  = get_post_status_object( $post->post_status );
			$items[] = sprintf(
				/* translators: 1: content title, 2: its status now (draft, private, trash…) */
				__( '«%1$s» (%2$s)', 'xb' ),
				'' !== $post->post_title ? $post->post_title : $post->post_name,
				$status instanceof \stdClass && is_string( $status->label ?? null ) ? $status->label : $post->post_status
			);
		}
		return array(
			'count' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	/** When the files a deploy serves were built: a rollback serves the version of the deploy it went back to. */
	private function built_at( int $deploy_id ): ?int {
		$deploy = $this->get( $deploy_id );
		for ( $hops = 0; null !== $deploy && null !== $deploy['rollback_of'] && $hops < 50; $hops++ ) {
			$deploy = $this->get( $deploy['rollback_of'] );
		}
		if ( null === $deploy ) {
			return null;
		}
		$time = strtotime( $deploy['created'] . ' UTC' );
		return false === $time ? null : $time;
	}

	/** The rollback warning, or none. */
	private function withdrawn_warning( int $deploy_id ): ?string {
		$withdrawn = $this->withdrawn_since( $deploy_id );
		if ( 0 === $withdrawn['count'] ) {
			return null;
		}
		$names = implode( ', ', $withdrawn['items'] ) . ( $withdrawn['count'] > count( $withdrawn['items'] ) ? ', …' : '' );
		return sprintf(
			/* translators: 1: number of contents, 2: their titles */
			_n(
				'Questa versione rimette online %1$d contenuto ritirato dopo che era stata generata: %2$s. Per toglierlo di nuovo dal sito statico, pubblica ora su Cloudflare.',
				'Questa versione rimette online %1$d contenuti ritirati dopo che era stata generata: %2$s. Per toglierli di nuovo dal sito statico, pubblica ora su Cloudflare.',
				$withdrawn['count'],
				'xb'
			),
			$withdrawn['count'],
			$names
		);
	}

	/**
	 * Instant rollback: puts an earlier Worker version back at 100%. The warnings name the contents withdrawn since
	 * that version was built, which it serves again (doc 23, voce 171): withdrawing them is a new deploy.
	 *
	 * @return array{id: int, version: string, warnings: list<string>}
	 */
	public function rollback( int $deploy_id ): array {
		if ( ! current_user_can( 'xb_publish' ) && ! self::cli() ) {
			throw new DeployException( __( 'Serve il permesso xb_publish per ripristinare.', 'xb' ), 403 );
		}
		$deploy = $this->get( $deploy_id );
		if ( null === $deploy || '' === $deploy['version_id'] || ! in_array( $deploy['status'], array( 'live', 'superseded' ), true ) ) {
			throw new DeployException( __( 'Deploy non ripristinabile.', 'xb' ), 404 );
		}
		$client = $this->client ?? CloudflareClient::from_settings();
		Plugin::instance()->versioning->store()->locked( 'deploy', static fn () => $client->deploy( $deploy['version_id'], sprintf( /* translators: %s: Cloudflare version ID */ __( 'Histeon rollback a %s', 'xb' ), $deploy['version_id'] ) ) );
		$id = $this->record( $deploy['release_id'], $deploy['version_id'], 'live', $deploy['files'], 0, $deploy_id, sprintf( /* translators: %d: deploy number */ __( 'Ripristino del deploy #%d', 'xb' ), $deploy_id ) );
		Plugin::instance()->versioning->store()->audit( 'deploy_rollback', 'cloudflare:' . $deploy['version_id'], array( 'deploy' => $deploy_id ) );
		$warning = $this->withdrawn_warning( $deploy_id );
		return array(
			'id'       => $id,
			'version'  => $deploy['version_id'],
			'warnings' => null === $warning ? array() : array( $warning ),
		);
	}

	/** @return list<array{id: int, created: string, release_id: ?int, version_id: string, status: string, files: int, uploaded: int, rollback_of: ?int, message: string}> */
	public function history( int $limit = 30 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'deploys' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::row( $row );
			}
		}
		return $out;
	}

	/** @return array{id: int, created: string, release_id: ?int, version_id: string, status: string, files: int, uploaded: int, rollback_of: ?int, message: string}|null */
	private function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'deploys' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? self::row( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: int, created: string, release_id: ?int, version_id: string, status: string, files: int, uploaded: int, rollback_of: ?int, message: string}
	 */
	private static function row( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'created'     => (string) $row['created'],
			'release_id'  => null === $row['release_id'] ? null : (int) $row['release_id'],
			'version_id'  => (string) $row['version_id'],
			'status'      => (string) $row['status'],
			'files'       => (int) $row['files'],
			'uploaded'    => (int) $row['uploaded'],
			'rollback_of' => null === $row['rollback_of'] ? null : (int) $row['rollback_of'],
			'message'     => (string) $row['message'],
		);
	}

	private function record( ?int $release, string $version, string $status, int $files, int $uploaded, ?int $rollback_of, string $message ): int {
		global $wpdb;
		if ( 'live' === $status ) {
			$wpdb->update( Schema::table( 'deploys' ), array( 'status' => 'superseded' ), array( 'status' => 'live' ) );
		}
		$wpdb->insert(
			Schema::table( 'deploys' ),
			array(
				'created'     => gmdate( 'Y-m-d H:i:s' ),
				'author'      => get_current_user_id(),
				'release_id'  => 0 < (int) $release ? $release : null,
				'target'      => 'cloudflare',
				'version_id'  => $version,
				'status'      => $status,
				'files'       => $files,
				'uploaded'    => $uploaded,
				'rollback_of' => $rollback_of,
				'message'     => $message,
			)
		);
		return (int) $wpdb->insert_id;
	}
}
