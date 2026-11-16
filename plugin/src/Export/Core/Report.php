<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

use XB\Content\ContentTypes;
use XB\Import\Etch\Migration;
use XB\Plugin;
use XB\Render\Facets;
use XB\Render\Queries;
use XB\Scripts\Scripts;
use XB\Scripts\SiteScripts;
use XB\Spec;
use XB\Styles\StyleRegistry;
use XB\Versioning\Documents;

/**
 * F0, the trial report of the way out to Gutenberg — docs/spec/export-core.md. For every document with Histeon
 * blocks (live version) and for the site, what would become a block, what would be frozen into `core/html` and
 * what would be lost, with the reasons. It writes nothing: not the content, not an option, not a cache.
 */
final class Report {

	public const FORMAT  = 'xb-core-export-report';
	public const VERSION = 1;

	private const MAX_DOCS = 2000;
	private const STATUSES = array( 'publish', 'draft', 'private', 'future', 'pending' );
	/** Post types that never hold a page: WordPress internals, Histeon's form messages. */
	private const SKIPPED_TYPES = array( 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'custom_css', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'xb_submission' );
	/** Templates Histeon CMS manages (Templates::MANAGED_META). */
	private const MANAGED_TEMPLATE = '_xb_histos_managed_template';

	/** Reading the project: an administrator who may build (as the preview of the export to Etch). */
	public static function can_read(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	/**
	 * The report. With $doc, that document only (the site section stays whole).
	 *
	 * @return array<string, mixed>
	 */
	public function build( ?int $doc = null ): array {
		if ( ! self::can_read() ) {
			throw new \RuntimeException( __( 'Serve un amministratore con il permesso xb_build per leggere il rapporto.', 'xb' ) );
		}
		$documents = array();
		$issues    = array();
		$totals    = array(
			'counts'      => array(),
			'expressions' => array(),
			'loops'       => array(),
			'components'  => array(),
			'conditions'  => array(),
		);
		$forms     = 0;
		$behaviors = 0;
		$scripts   = 0;
		foreach ( $this->posts( $doc ) as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				/* translators: 1: post title, 2: post ID */
				$issues[] = sprintf( __( 'Non puoi modificare «%1$s» (#%2$d): saltato', 'xb' ), get_the_title( $post ), $post->ID );
				continue;
			}
			$trusted = Migration::author_trusted( $post );
			// What Histeon would let this author save: the same filter again, whatever reached the database.
			$content  = $trusted ? $post->post_content : Plugin::instance()->sanitizer->restricted_content( $post->post_content );
			$kind     = self::kind( $post );
			$analyzer = new Analyzer( $kind );
			$analyzer->analyze( array_values( parse_blocks( $content ) ) );
			$script = '' !== trim( Scripts::get( $post->ID ) );
			$entry  = array(
				'id'          => $post->ID,
				'type'        => $post->post_type,
				'kind'        => $kind,
				'slug'        => $post->post_name,
				'title'       => get_the_title( $post ),
				'status'      => $post->post_status,
				'trusted'     => $trusted,
				'cms'         => '' !== (string) get_post_meta( $post->ID, self::MANAGED_TEMPLATE, true ),
				'share'       => round( $analyzer->share(), 4 ),
				'counts'      => $analyzer->counts,
				'expressions' => $analyzer->expressions,
				'loops'       => $analyzer->loops,
				'components'  => $analyzer->components,
				'conditions'  => $analyzer->conditions,
				'script'      => $script,
				'items'       => $analyzer->items,
				'notes'       => $analyzer->notes,
			);
			if ( 'componente' === $kind ) {
				$entry['pattern'] = $this->pattern( $post );
			}
			$documents[] = $entry;
			foreach ( array_keys( $totals ) as $group ) {
				foreach ( $entry[ $group ] as $key => $n ) {
					$totals[ $group ][ $key ] = ( $totals[ $group ][ $key ] ?? 0 ) + $n;
				}
			}
			$forms     += $analyzer->forms;
			$behaviors += $analyzer->behaviors;
			$scripts   += $script ? 1 : 0;
		}
		if ( null !== $doc && array() === $documents && array() === $issues ) {
			/* translators: %d: post ID */
			throw new \RuntimeException( sprintf( __( 'Il documento #%d non esiste o non ha blocchi di Histeon.', 'xb' ), $doc ) );
		}
		$elements = array_sum( $totals['counts'] );
		$real     = 0;
		foreach ( Analyzer::REAL as $dest ) {
			$real += $totals['counts'][ $dest ] ?? 0;
		}
		$report = array(
			'format'    => self::FORMAT,
			'version'   => self::VERSION,
			'summary'   => array(
				'documents' => count( $documents ),
				'elements'  => $elements,
				'real'      => $real,
				'share'     => $elements > 0 ? round( $real / $elements, 4 ) : 1.0,
			) + $totals,
			'site'      => $this->site( $forms, $behaviors, $scripts ),
			'documents' => $documents,
			'issues'    => $issues,
		);
		$report['fingerprint'] = hash( 'sha256', (string) wp_json_encode( array( $report['summary'], $report['site'], $report['documents'] ) ) );
		$report['generated']   = gmdate( 'c' );
		return $report;
	}

	/**
	 * The report as the JSON file of --file.
	 *
	 * @param array<string, mixed> $report
	 */
	public static function file( array $report ): string {
		return (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/** What a document is on the way out: a content (its own `this`), a template, a part or a component. */
	public static function kind( \WP_Post $post ): string {
		return match ( $post->post_type ) {
			'wp_template' => 'template',
			'wp_template_part' => 'parte',
			'wp_block' => 'componente',
			default => 'contenuto',
		};
	}

	/**
	 * Documents with Histeon blocks, components first.
	 *
	 * @return list<\WP_Post>
	 */
	private function posts( ?int $doc ): array {
		global $wpdb;
		$skipped  = implode( ',', array_fill( 0, count( self::SKIPPED_TYPES ), '%s' ) );
		$statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$only     = null === $doc ? '' : $wpdb->prepare( ' AND ID = %d', $doc );
		$ids      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a read no API gives (content search across every type).
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ($skipped) AND post_status IN ($statuses) AND post_content LIKE %s{$only} ORDER BY FIELD(post_type, 'wp_block', 'wp_template_part', 'wp_template') DESC, ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above, $only prepared.
				array_merge( self::SKIPPED_TYPES, self::STATUSES, array( '%' . $wpdb->esc_like( 'wp:xb/' ) . '%', self::MAX_DOCS ) )
			)
		);
		$posts = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && Spec::has_xb_blocks( $post->post_content ) ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * A component definition on its own: synced pattern or expanded where used.
	 *
	 * @return array{synced: bool, reason: string}
	 */
	private function pattern( \WP_Post $post ): array {
		$key = (string) get_post_meta( $post->ID, \XB\Render\ComponentRegistry::META_KEY, true );
		$why = Analyzer::expand_reason( $key, array_values( parse_blocks( $post->post_content ) ), array() );
		return array(
			'synced' => null === $why,
			'reason' => $why ?? '',
		);
	}

	/**
	 * The site: content types and fields, queries, facets, forms, scripts, styles, modules, unpublished changes.
	 *
	 * @return array<string, mixed>
	 */
	private function site( int $forms, int $behaviors, int $scripts ): array {
		$content = ContentTypes::live();
		$fields  = 0;
		$repeat  = 0;
		foreach ( $content['fields'] as $list ) {
			foreach ( $list as $field ) {
				++$fields;
				$repeat += 'repeater' === $field['type'] ? 1 : 0;
			}
		}
		$meta = 0;
		foreach ( Queries::presets() as $preset ) {
			$meta += array() !== array_intersect( array_keys( $preset['args'] ), array( 'meta_query', 'meta_key', 'meta_value', 'meta_compare' ) ) ? 1 : 0;
		}
		$facets   = get_option( Facets::OPTION, array() );
		$site_js  = get_option( SiteScripts::OPTION, array() );
		$registry = new StyleRegistry();
		$css      = 0;
		foreach ( $registry->globals( true, true ) as $sheet ) {
			$css += strlen( (string) $sheet['css'] );
		}
		foreach ( $registry->entries( true, true ) as $entry ) {
			$css += strlen( (string) $entry['selector'] ) + strlen( (string) $entry['css'] ) + 2;
		}
		$pending = array_filter( Plugin::instance()->versioning->changes(), static fn ( array $c ): bool => null !== Documents::post_id( (string) $c['doc'] ) );
		return array(
			'types'      => array_keys( $content['types'] ),
			'taxonomies' => array_keys( $content['taxonomies'] ),
			'fields'     => $fields,
			'repeaters'  => $repeat,
			'queries'    => array(
				'presets'   => count( Queries::presets() ),
				'metaQuery' => $meta,
			),
			'facets'     => is_array( $facets ) ? count( $facets ) : 0,
			'forms'      => $forms,
			'behaviors'  => $behaviors,
			'scripts'    => array(
				'documents' => $scripts,
				'site'      => is_array( $site_js ) ? count( $site_js ) : 0,
			),
			'css'        => array(
				'framework' => Plugin::instance()->frameworks->active()->id(),
				'bytes'     => $css,
			),
			'modules'    => array(
				'lingua-bridge' => defined( 'LINGUA_BRIDGE_VERSION' ),
				'static-bridge' => class_exists( '\\Static_Bridge_Plugin', false ),
			),
			'theme'      => get_stylesheet(),
			'pending'    => count( $pending ),
		);
	}
}
