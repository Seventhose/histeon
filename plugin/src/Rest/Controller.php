<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Rest;

use XB\Plugin;
use XB\Render\ComponentRegistry;
use XB\Render\Context;
use XB\Scripts\Scripts;
use XB\Versioning\Documents;
use XB\Versioning\Preview;
use XB\Versioning\ReleaseChecks;
use XB\Versioning\Versioning;
use XB\Versioning\VersioningException;
use XB\Spec;
use XB\Styles\Selectors;
use XB\Styles\StyleRegistry;

/**
 * REST API of the builder — namespace `xb/v1`, docs/spec/rest.md.
 *
 * Every route requires `xb_build`; document routes also require `edit_post` on that document (threat model T2).
 * Writes go through wp_update_post(), so ContentSanitizer applies exactly as for any other save.
 * Responses are never cacheable.
 */
final class Controller {

	/** Contents moved or trashed per call of /content/items. */
	private const MOVE_BATCH = 200;

	public const NAMESPACE = 'xb/v1';

	/** Post types the builder can open. */
	public const DOCUMENT_TYPES = \XB\Versioning\Documents::POST_TYPES;

	public const MAX_CSS_BYTES = 500000;

	public static function register(): void {
		$controller = new self();
		register_rest_route(
			self::NAMESPACE,
			'/documents/(?P<id>\\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_document' ),
					'permission_callback' => array( $controller, 'can_read_document' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_document' ),
					'permission_callback' => array( $controller, 'can_edit_document' ),
					'args'                => array(
						'content'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'head'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'base'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'script'   => array( 'type' => 'string' ),
						'schema'   => array( 'type' => 'object' ),
						'ref'      => array( 'type' => 'string' ),
						// The page's own look (voce 186, PageLook): sent only when it changed.
						'styles'   => array( 'type' => 'array' ),
						// The builder has the content it sent: it gets it back only when the server changed it.
						'lean'     => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/paste/sanitize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'sanitize_paste' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
					'styles'  => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/render',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'render' ),
				'permission_callback' => array( $controller, 'can_render' ),
				'args'                => array(
					'content' => array(
						'type'     => 'string',
						'required' => true,
					),
					'post_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'context'     => array(
						'type'    => 'integer',
						'default' => 0,
					),
					// The site CSS is only for the JS API's render(): the canvas draws with its own live CSS.
					'css'         => array(
						'type'    => 'boolean',
						'default' => false,
					),
					// A component being edited: its props schema as it is in the builder (JSON), for the sample values.
					'schema'      => array(
						'type'    => 'string',
						'default' => '',
					),
					// Hash of the globalCss the canvas already has: an equal one is not sent again.
					'global_hash' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/editor-render',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'editor_render' ),
				'permission_callback' => array( $controller, 'can_edit_post_content' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'items'   => array(
						'type'     => 'object',
						'required' => true,
					),
					'tests'   => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/changes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'get_changes' ),
				'permission_callback' => array( $controller, 'can_build' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/releases/(?P<id>\\d+)/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'export_release' ),
				'permission_callback' => array( $controller, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/releases/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'import_release' ),
				'permission_callback' => array( $controller, 'can_manage' ),
				'args'                => array(
					'data'  => array(
						'type'     => 'object',
						'required' => true,
					),
					'write' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/library',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'library' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'type'   => array(
						'type'    => 'string',
						'default' => 'page',
						'enum'    => array( 'page', 'post', 'wp_block' ),
					),
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/templates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'list_templates' ),
					'permission_callback' => array( $controller, 'can_edit_templates' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'open_template' ),
					'permission_callback' => array( $controller, 'can_edit_templates' ),
					'args'                => array(
						'type' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => \XB\Templates\Templates::TYPES,
						),
						'id'   => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/changes/diff',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'diff_change' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'doc' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/releases',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'list_releases' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'publish' ),
					'permission_callback' => array( $controller, 'can_release' ),
					'args'                => array(
						'version' => array( 'type' => 'string' ),
						'notes'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'docs'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						// No schema on purpose: a wrong outcome is dropped by ReleaseChecks::clean(), never a 400.
						'checks'  => array(
							'description' => 'The outcome of the page checks: { errors, warnings, rules: { <rule>: <count> }, at }. A reminder, not a proof.',
						),
					),
				),
			)
		);
		// The scheduled release (docs/spec/versioning.md §11): only from the builder, never with an application password.
		register_rest_route(
			self::NAMESPACE,
			'/releases/schedule',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'schedule' ),
					'permission_callback' => array( $controller, 'can_schedule' ),
					'args'                => array(
						'at'      => array(
							'type'        => 'string',
							'required'    => true,
							'description' => 'Date and time in the site time zone, as <input type="datetime-local"> writes it: 2026-11-20T09:00.',
						),
						'version' => array( 'type' => 'string' ),
						'notes'   => array(
							'type'    => 'string',
							'default' => '',
						),
						'docs'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'checks'  => array(
							'description' => 'The outcome of the page checks, as for POST /releases.',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $controller, 'cancel_schedule' ),
					'permission_callback' => array( $controller, 'can_cancel_schedule' ),
					'args'                => array(
						// true: close the line with the outcome of the last scheduled release instead.
						'last' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/releases/(?P<id>\\d+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'restore' ),
				'permission_callback' => array( $controller, 'can_restore' ),
				'args'                => array(
					'keepContent' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/preview-link',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'preview_link' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'post'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'context' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'as'      => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[a-z0-9_]{0,40}$',
					),
					// How long the link lasts (V3): 1 hour, 1 day, 2 days or 7 days; any other value is a 400.
					'ttl'     => array(
						'type'    => 'integer',
						'enum'    => Preview::LINK_TTLS,
						'default' => Preview::LINK_TTLS[2],
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/preview-link/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'revoke_previews' ),
				'permission_callback' => array( $controller, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'audit_log' ),
				'permission_callback' => array( $controller, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/framework',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'get_framework' ),
				'permission_callback' => array( $controller, 'can_read_builder' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/styles',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_styles' ),
					'permission_callback' => array( $controller, 'can_read_builder' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_styles' ),
					'permission_callback' => array( $controller, 'can_build' ),
					'args'                => array(
						'head' => array(
							'type'     => 'string',
							'required' => true,
						),
						'base' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		// The site's own scripts (lista della spesa 148): read by whoever builds, written only with unfiltered_html.
		register_rest_route(
			self::NAMESPACE,
			'/agent-sessions',
			array(
				'methods'             => 'GET',
				'callback'            => static fn (): \WP_REST_Response => self::no_store( \XB\Ai\AgentSession::visible() ),
				'permission_callback' => array( $controller, 'can_build' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/agent-sessions/(?P<user>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'agent_session' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'action' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'keep', 'undo' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/site-scripts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_site_scripts' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_site_scripts' ),
					'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ) && current_user_can( 'unfiltered_html' ),
					'args'                => array(
						'scripts' => array( 'required' => true, 'type' => 'array' ),
						'head'    => array( 'required' => true, 'type' => 'string' ),
						'base'    => array( 'required' => true, 'type' => 'string' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/queries',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_queries' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_queries' ),
					'permission_callback' => array( $controller, 'can_build' ),
					'args'                => array(
						'presets' => array(
							'type'     => 'object',
							'required' => true,
						),
						'sources' => array(
							'type'    => array( 'object', 'null' ),
							'default' => null,
						),
						'head'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'base'    => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/named-queries',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_named_queries' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_named_queries' ),
					'permission_callback' => array( $controller, 'can_build' ),
					'args'                => array(
						'queries' => array(
							'type'     => 'object',
							'required' => true,
						),
						'head'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'base'    => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/extensions',
			array(
				'methods'             => 'GET',
				'callback'            => static fn (): \WP_REST_Response => new \WP_REST_Response( \XB\Render\Extensions::describe() ),
				'permission_callback' => array( $controller, 'can_build' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/shortcuts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_shortcuts' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_shortcuts' ),
					'permission_callback' => array( $controller, 'can_build' ),
					'args'                => array(
						'shortcuts' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/facets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_facets' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_facets' ),
					'permission_callback' => array( $controller, 'can_build' ),
					'args'                => array(
						'facets' => array(
							'type'     => 'object',
							'required' => true,
						),
						'head'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'base'   => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'preview_design' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'design' => array(
						'type'     => 'object',
						'required' => true,
					),
					'tokens' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/tokens',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_tokens' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_tokens' ),
					'permission_callback' => array( $controller, 'can_build' ),
					'args'                => array(
						'tokens' => array(
							'type'     => 'object',
							'required' => true,
						),
						'design' => array(
							'type'     => 'object',
							'required' => false,
						),
						'siteTokens' => array(
							'type'     => 'object',
							'required' => false,
						),
						'recipes' => array(
							'type'     => 'object',
							'required' => false,
						),
						'head'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'base'   => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/facets/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'test_facet' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'facet'  => array(
						'type'     => 'object',
						'required' => true,
					),
					'params' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/content/items',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'move_content_items' ),
				'permission_callback' => array( $controller, 'can_manage' ),
				'args'                => array(
					'type'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9_-]{1,20}$',
					),
					'action' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'trash', 'move' ),
					),
					'to'     => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/content',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'get_content' ),
					'permission_callback' => array( $controller, 'can_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'save_content' ),
					'permission_callback' => array( $controller, 'can_manage' ),
					'args'                => array(
						'content'      => array(
							'type'     => 'object',
							'required' => true,
						),
						'publicFields' => array(
							'type'     => 'array',
							'required' => true,
						),
						'head'         => array(
							'type'     => 'string',
							'required' => true,
						),
						'base'         => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/queries/terms',
			array(
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $request ): \WP_REST_Response => self::no_store(
					array(
						'terms' => \XB\Render\Queries::search_terms(
							(string) $request['taxonomy'],
							(string) $request['search'],
							array_values( array_filter( array_map( 'intval', explode( ',', (string) $request['include'] ) ) ) )
						),
					)
				),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'taxonomy' => array(
						'type'     => 'string',
						'required' => true,
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'include'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/queries/options',
			array(
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $request ): \WP_REST_Response => self::no_store( \XB\Render\Queries::options( (string) $request['type'] ) ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'type' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[a-z0-9_-]{0,20}$',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/queries/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'test_query' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'id'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'preset'  => array(
						'type'     => 'object',
						'required' => true,
					),
					'params'  => array(
						'type'    => 'object',
						'default' => array(),
					),
					// A grouped loop's {field, how?} (doc 23 of Publish, item 205): the preview counts its groups.
					'groupBy' => array(
						'type' => 'object',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/selectors',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'list_selectors' ),
				'permission_callback' => array( $controller, 'can_build' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/selectors/rename',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'rename_selector' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'from'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'to'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'dryRun' => array( 'type' => 'boolean' ),
					'html'   => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'id'     => array( 'type' => 'string' ),
								'before' => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/selectors/safelist',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'safelist_selector' ),
				// The safelist is a site setting (Settings → Histeon): the same people may change it.
				'permission_callback' => array( $controller, 'can_manage' ),
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
					'on'   => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/selectors/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'delete_selectors' ),
				'permission_callback' => array( $controller, 'can_build' ),
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/component-schema',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'component_schema' ),
				'permission_callback' => static fn (): bool => current_user_can( 'edit_posts' ),
				'args'                => array(
					'ref' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/components',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $controller, 'list_components' ),
					'permission_callback' => array( $controller, 'can_read_builder' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $controller, 'create_component' ),
					'permission_callback' => array( $controller, 'can_create_component' ),
					'args'                => array(
						'ref'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'title'   => array(
							'type'     => 'string',
							'required' => true,
						),
						// Markup of the selection it is made from (component from the selection); empty otherwise.
						'content' => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 1048576,
						),
					),
				),
			)
		);
	}

	/** What Gutenberg's side panel asks for a component: its props, the fields of the panel. */
	/**
	 * The slots a component offers, in the order they appear (Gutenberg shows one editable area each, list 134).
	 * Anything that is not a block is skipped.
	 *
	 * @param array<mixed> $blocks
	 * @return list<string>
	 */
	public static function slot_names( array $blocks ): array {
		$names = array();
		$walk  = static function ( array $list ) use ( &$walk, &$names ): void {
			foreach ( $list as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				if ( 'xb/slot' === ( $block['blockName'] ?? null ) ) {
					$name = (string) ( $block['attrs']['name'] ?? 'default' );
					if ( '' !== $name && ! in_array( $name, $names, true ) ) {
						$names[] = $name;
					}
				}
				$walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() );
			}
		};
		$walk( $blocks );
		return $names;
	}

	public function component_schema( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ref        = (string) $request->get_param( 'ref' );
		$definition = Plugin::instance()->components->find( $ref );
		if ( null === $definition ) {
			return new \WP_Error( 'xb_component_unknown', __( 'Componente sconosciuto.', 'xb' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response(
			array(
				'props' => (object) \XB\Content\ArticleBlocks::labelled( $definition['props'] ),
				'rows'  => \XB\Content\ArticleBlocks::rows( $ref ),
				'slots' => self::slot_names( $definition['blocks'] ),
			)
		);
	}

	// ------------------------------------------------------------------ permissions

	/**
	 * Markup and styles pasted from another site (a clip or HTML) come from whoever wrote the clipboard: they get
	 * the restricted tier whoever pastes them, before they enter the document (docs/spec/quick-actions.md §5).
	 */
	public function sanitize_paste( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$content = (string) $request->get_param( 'content' );
		$styles  = $request->get_param( 'styles' );
		if ( strlen( $content ) > 2 * 1024 * 1024 || ! is_array( $styles ) || count( $styles ) > 500 ) {
			return new \WP_Error( 'xb_paste_too_large', __( 'Contenuto incollato troppo grande.', 'xb' ), array( 'status' => 413 ) );
		}
		$clean = array();
		foreach ( $styles as $style ) {
			if ( is_array( $style ) && is_string( $style['selector'] ?? null ) && is_string( $style['css'] ?? null ) ) {
				$clean[] = array( 'selector' => $style['selector'], 'css' => $style['css'] );
			}
		}
		return rest_ensure_response(
			array(
				'content' => Plugin::instance()->sanitizer->restricted_content( $content ),
				'styles'  => array_values( Documents::restricted_styles( array( 'styles' => $clean ) )['styles'] ),
			)
		);
	}

	public function can_build(): bool {
		return current_user_can( 'xb_build' );
	}

	public function can_edit_document( \WP_REST_Request $request ): bool {
		return self::document_access( (int) $request['id'], 'content' );
	}

	/** Reading a document: also a client's page seen only in the builder (level `view`, voce 186). */
	public function can_read_document( \WP_REST_Request $request ): bool {
		return self::document_access( (int) $request['id'], 'view' );
	}

	/**
	 * Who builds: `edit_post` on a builder document. A client: his level on it (Security\ClientAccess, voce 186),
	 * at least `$need`.
	 */
	private static function document_access( int $id, string $need ): bool {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::DOCUMENT_TYPES, true ) ) {
			return false;
		}
		if ( \XB\Capabilities::content_only() ) {
			return \XB\Security\ClientAccess::allows( $post->ID, $need );
		}
		return current_user_can( 'xb_build' ) && current_user_can( 'edit_post', $post->ID );
	}

	/** What the builder reads to draw a page (styles, components, framework): also in client mode, never written. */
	public function can_read_builder(): bool {
		return current_user_can( 'xb_build' ) || current_user_can( 'xb_edit_content' );
	}

	public function can_create_component(): bool {
		$type = get_post_type_object( 'wp_block' );
		return current_user_can( 'xb_build' )
			&& null !== $type
			&& current_user_can( $type->cap->create_posts )
			&& current_user_can( $type->cap->publish_posts );
	}

	/** Whoever edits this post in Gutenberg (clients included): the editor shows XB content as the site does. */
	public function can_edit_post_content( \WP_REST_Request $request ): bool {
		$post = get_post( (int) $request['post_id'] );
		return $post instanceof \WP_Post && in_array( $post->post_type, self::DOCUMENT_TYPES, true ) && current_user_can( 'edit_post', $post->ID );
	}

	public function can_render( \WP_REST_Request $request ): bool {
		$post_id = (int) $request['post_id'];
		if ( \XB\Capabilities::content_only() ) {
			// Client mode draws only its own document, changed only as client mode may change it: no new expressions.
			// A page seen only in the builder (`view`, voce 186) draws too, unchanged.
			return $post_id > 0 && \XB\Security\ClientAccess::allows( $post_id, 'view' ) && in_array( get_post_type( $post_id ), self::DOCUMENT_TYPES, true )
				&& '' === \XB\Security\ContentOnly::check( (string) ( Plugin::instance()->versioning->working_copy( Documents::for_post( $post_id ) )['payload']['content'] ?? '' ), (string) $request['content'], \XB\Capabilities::content_props() );
		}
		return current_user_can( 'xb_build' ) && ( 0 === $post_id || current_user_can( 'edit_post', $post_id ) );
	}

	// ------------------------------------------------------------------ documents

	public function get_document( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::guard( fn (): array => self::document( (int) $request['id'] ) );
	}

	/**
	 * Saves the builder's work as a dev commit; the live page changes only when a release is published.
	 * Everything is validated before anything is written.
	 */
	public function save_document( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request['id'];
		$type = (string) get_post_type( $id );
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $head ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $base ) ) {
			return new \WP_Error( 'xb_invalid_head', __( 'Versione di partenza mancante: ricarica il builder.', 'xb' ), array( 'status' => 400 ) );
		}
		$doc     = Documents::for_post( $id );
		$current = Plugin::instance()->versioning->working_copy( $doc )['payload'];
		$payload = array(
			'content' => (string) $request['content'],
			'script'  => is_string( $request['script'] ) ? $request['script'] : (string) ( $current['script'] ?? '' ),
		);
		// The page's own look (voce 186): kept as it is unless sent; checked with its closed grammar when sent.
		try {
			$look_before = \XB\Styles\PageLook::validate( $current[ \XB\Styles\PageLook::KEY ] ?? array() );
		} catch ( \InvalidArgumentException ) {
			$look_before = array();
		}
		$look = $look_before;
		if ( null !== $request['styles'] ) {
			if ( ! \XB\Styles\PageLook::supports( $id ) ) {
				return new \WP_Error( 'xb_invalid_page_styles', __( 'Solo pagine e articoli hanno stili propri.', 'xb' ), array( 'status' => 400 ) );
			}
			try {
				$look = \XB\Styles\PageLook::validate( $request['styles'] );
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'xb_invalid_page_styles', $e->getMessage(), array( 'status' => 400 ) );
			}
		}
		if ( array() !== $look ) {
			$payload[ \XB\Styles\PageLook::KEY ] = $look;
		}
		if ( 'wp_block' === $type ) {
			$payload['ref']    = $current['ref'] ?? '';
			$payload['schema'] = $current['schema'] ?? array( 'props' => array() );
			if ( null !== $request['schema'] ) {
				$schema = self::clean_schema( $request['schema'] );
				if ( is_wp_error( $schema ) ) {
					return $schema;
				}
				$payload['schema'] = $schema;
			}
			if ( null !== $request['ref'] ) {
				$valid = self::check_ref( (string) $request['ref'], $id );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$payload['ref'] = (string) $request['ref'];
			}
		} elseif ( null !== $request['schema'] || null !== $request['ref'] ) {
			return new \WP_Error( 'xb_invalid_schema', __( 'Solo i componenti hanno chiave e schema di props.', 'xb' ), array( 'status' => 400 ) );
		}
		if ( \XB\Capabilities::content_only() ) {
			// Client mode (docs/spec/client-mode.md): texts and pictures only, the rest exactly as it is.
			$why = \XB\Security\ContentOnly::check( (string) ( $current['content'] ?? '' ), $payload['content'], \XB\Capabilities::content_props() );
			if ( '' === $why && $payload['script'] !== (string) ( $current['script'] ?? '' ) ) {
				$why = __( 'script della pagina', 'xb' );
			}
			if ( '' === $why && ( wp_json_encode( $payload['schema'] ?? null ) !== wp_json_encode( $current['schema'] ?? null ) || (string) ( $payload['ref'] ?? '' ) !== (string) ( $current['ref'] ?? '' ) ) ) {
				$why = __( 'props del componente', 'xb' );
			}
			if ( '' !== $why ) {
				/* translators: %s: what was changed */
				return new \WP_Error( 'xb_content_only', sprintf( __( 'In modalità contenuti si cambiano solo testi e immagini (%s).', 'xb' ), $why ), array( 'status' => 403 ) );
			}
			// The page's own look: only with the level `look` on this page, only in the groups given (voce 186).
			$changed = \XB\Styles\PageLook::changed( $look_before, $look );
			if ( array() !== $changed ) {
				if ( ! \XB\Security\ClientAccess::allows( $id, 'look' ) ) {
					return new \WP_Error( 'xb_content_only', __( 'Non puoi cambiare l\'aspetto di questa pagina.', 'xb' ), array( 'status' => 403 ) );
				}
				$groups = \XB\Security\ClientAccess::groups( $id );
				foreach ( $changed as $prop ) {
					if ( ! in_array( \XB\Styles\PageLook::group( $prop ), $groups, true ) ) {
						/* translators: %s: CSS property */
						return new \WP_Error( 'xb_content_only', sprintf( __( 'Non puoi cambiare %s su questa pagina.', 'xb' ), $prop ), array( 'status' => 403 ) );
					}
				}
			}
		}
		$lean = true === $request['lean'];
		return self::guard(
			static function () use ( $doc, $payload, $head, $base, $id, $lean ): array {
				Plugin::instance()->versioning->save_dev( $doc, $payload, $head, $base );
				$saved = self::document( $id );
				// Stored as sent (nothing filtered, nothing merged): the builder keeps what it has (V15).
				if ( $lean && $saved['content'] === $payload['content'] ) {
					unset( $saved['content'] );
					$saved['unchanged'] = true;
				}
				return $saved;
			}
		);
	}

	/** @return array<string, mixed> */
	private static function document( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			throw new VersioningException( __( 'Documento inesistente.', 'xb' ), 404 );
		}
		$copy      = Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) );
		$payload   = $copy['payload'];
		$component = 'wp_block' === $post->post_type;
		return array(
			'id'             => $post->ID,
			'type'           => $post->post_type,
			'title'          => $post->post_title,
			'status'         => $post->post_status,
			'content'        => (string) ( $payload['content'] ?? '' ),
			'head'           => $copy['head'],
			'base'           => $copy['base'],
			'merged'         => $copy['merged'],
			'conflicts'      => $copy['conflicts'],
			'published'      => $copy['head'] === $copy['base'],
			'link'           => get_permalink( $post ),
			'script'         => (string) ( $payload['script'] ?? '' ),
			'scriptEditable' => current_user_can( 'unfiltered_html' ),
			'ref'            => $component ? (string) ( $payload['ref'] ?? '' ) : null,
			// Props as a JSON object even when there are none: an empty list ([]) would lose the first prop added.
			'schema'         => $component ? array( 'props' => (object) ( is_array( $payload['schema']['props'] ?? null ) ? $payload['schema']['props'] : array() ) ) : null,
			// The page's own look in development (voce 186), and what this user may do on this document.
			'pageStyles'     => is_array( $payload[ \XB\Styles\PageLook::KEY ] ?? null ) ? array_values( $payload[ \XB\Styles\PageLook::KEY ] ) : array(),
			'access'         => array(
				'level'  => \XB\Security\ClientAccess::level( $post->ID ),
				'groups' => \XB\Security\ClientAccess::groups( $post->ID ),
			),
		);
	}

	/**
	 * Runs a versioning operation and turns its failures into REST errors with their status.
	 *
	 * @param callable(): mixed $operation
	 */
	private static function guard( callable $operation ): \WP_REST_Response|\WP_Error {
		try {
			return self::no_store( $operation() );
		} catch ( VersioningException $e ) {
			return new \WP_Error( 'xb_versioning', $e->getMessage(), array( 'status' => $e->status ) );
		}
	}

	// ------------------------------------------------------------------ releases

	public function can_publish(): bool {
		return current_user_can( 'xb_build' ) && current_user_can( 'xb_publish' );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	/**
	 * A 403 when the request is authenticated with an application password, the way an agent outside the browser
	 * talks to the REST API of WordPress: what goes online is published by a person, with the login cookie and the
	 * `wp_rest` nonce of the builder (threat model T6 and T30). Null for any other request.
	 */
	public static function app_password_refusal( string $message ): ?\WP_Error {
		return null === rest_get_authenticated_app_password() ? null : new \WP_Error( 'xb_app_password', $message, array( 'status' => 403 ) );
	}

	/** Publishing now: xb_build and xb_publish, and never with an application password (threat model T6). */
	public function can_release(): bool|\WP_Error {
		return self::app_password_refusal( __( 'Una release si pubblica solo dal builder o dalla schermata Release, non con una password per applicazioni.', 'xb' ) ) ?? $this->can_publish();
	}

	/** Restoring a release (it goes online as a new release): as publishing (threat model T6). */
	public function can_restore(): bool|\WP_Error {
		return self::app_password_refusal( __( 'Una release si ripristina solo dal builder o dalla schermata Release, non con una password per applicazioni.', 'xb' ) ) ?? $this->can_publish();
	}

	/** Scheduling: as publishing (threat model T30). */
	public function can_schedule(): bool|\WP_Error {
		return self::app_password_refusal( __( 'Una release si programma solo dal builder, non con una password per applicazioni.', 'xb' ) ) ?? $this->can_publish();
	}

	/**
	 * Cancelling: whoever scheduled it, or anyone with xb_build and xb_publish, and never with an application password
	 * (BASSA-S5): an agent outside the browser cannot cancel what a person scheduled (threat model T30).
	 */
	public function can_cancel_schedule(): bool|\WP_Error {
		$refused = self::app_password_refusal( __( 'Una release programmata si annulla solo dal builder o dalla schermata Release, non con una password per applicazioni.', 'xb' ) );
		if ( null !== $refused ) {
			return $refused;
		}
		$view = \XB\Versioning\Scheduled::view();
		return current_user_can( 'xb_build' ) && ( current_user_can( 'xb_publish' ) || ( null !== $view && $view['mine'] ) );
	}

	public function get_changes(): \WP_REST_Response|\WP_Error {
		return self::guard( static fn (): array => self::visible_changes() );
	}

	/** A published release as a portable file (.xbrelease): docs/spec/versioning.md §8. */
	public function export_release( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['id'];
		return self::guard(
			static function () use ( $id ): array {
				$file = \XB\Versioning\Transfer::instance()->export( $id );
				Plugin::instance()->versioning->store()->audit( 'export_release', 'release:' . $id );
				return $file;
			}
		);
	}

	/** Dry run, or dev commits with `write`: nothing goes live until a release is published. */
	public function import_release( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( $request->get_body() ) > \XB\Versioning\Transfer::MAX_BYTES ) {
			return new \WP_Error( 'xb_release_too_large', __( 'Il file di release supera la dimensione massima.', 'xb' ), array( 'status' => 413 ) );
		}
		try {
			// The signature (P8): `trust` trusts a new key, `allowUnsigned` accepts a file without a signature.
			$options = array(
				'trust'         => true === $request['trust'],
				'allowUnsigned' => true === $request['allowUnsigned'],
			);
			return self::no_store( \XB\Versioning\Transfer::instance()->import( $request['data'], (bool) $request['write'], $options ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_release', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( VersioningException $e ) {
			return new \WP_Error( 'xb_versioning', $e->getMessage(), array( 'status' => $e->status ) );
		}
	}

	// ------------------------------------------------------------------ builder sections

	/** Pages, posts or components the user may edit, for the builder's content section. */
	public function library( \WP_REST_Request $request ): \WP_REST_Response {
		$type  = (string) $request['type'];
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'numberposts'      => 200,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				's'                => mb_substr( (string) $request['search'], 0, 100 ),
				'suppress_filters' => false,
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $post->ID,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'slug'     => $post->post_name,
				'status'   => $post->post_status,
				'modified' => get_post_modified_time( 'c', true, $post ),
				'xb'       => \XB\Spec::has_xb_blocks( $post->post_content ),
				'ref'      => 'wp_block' === $type ? (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true ) : '',
				'url'      => 'wp_block' === $type ? '' : (string) get_permalink( $post ),
			);
		}
		return self::no_store( array( 'items' => $items ) );
	}

	public function can_edit_templates(): bool {
		return current_user_can( 'xb_build' ) && current_user_can( 'edit_theme_options' ) && wp_is_block_theme();
	}

	public function list_templates(): \WP_REST_Response {
		return self::no_store( array( 'items' => \XB\Templates\Templates::all() ) );
	}

	/** The database post of a template (written from the theme file the first time), to open in the builder. */
	public function open_template( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return self::no_store( array( 'post' => \XB\Templates\Templates::materialize( (string) $request['type'], (string) $request['id'] ) ) );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'xb_template', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/** Online and dev version of one changed document, for the publish dialog's diff. */
	public function diff_change( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$doc = (string) $request['doc'];
		if ( ! in_array( $doc, array_column( self::visible_changes(), 'doc' ), true ) ) {
			return new \WP_Error( 'xb_forbidden', __( 'Documento non tra le modifiche che puoi vedere.', 'xb' ), array( 'status' => 403 ) );
		}
		$store   = Plugin::instance()->versioning->store();
		$payload = static function ( string $branch ) use ( $store, $doc ): ?array {
			$head = $store->head( $doc, $branch );
			$commit = null === $head ? null : $store->get( $head );
			return is_array( $commit ) ? $commit['payload'] : null;
		};
		return self::no_store(
			array(
				'doc'   => $doc,
				'label' => Documents::label( $doc ),
				'prod'  => $payload( \XB\Versioning\Store::PROD ),
				'dev'   => $payload( \XB\Versioning\Store::DEV ),
			)
		);
	}

	/**
	 * Changes the current user may know about: documents they can edit, plus styles and settings.
	 *
	 * @return list<array{doc: string, label: string, sent: bool}>
	 */
	public static function visible_changes(): array {
		return array_values(
			array_filter(
				Plugin::instance()->versioning->changes(),
				static function ( array $change ): bool {
					$id = Documents::post_id( $change['doc'] );
					return null === $id || current_user_can( 'edit_post', $id );
				}
			)
		);
	}

	public function list_releases(): \WP_REST_Response|\WP_Error {
		return self::guard(
			static function (): array {
				$store    = Plugin::instance()->versioning->store();
				$current  = (int) get_option( Versioning::OPTION_CURRENT, 0 );
				$releases = array();
				$list     = $store->releases();
				// The outcome of the page checks each release went out with, when the builder sent one (V1.5).
				$checks = ReleaseChecks::of_releases( $store, array_column( $list, 'id' ) );
				foreach ( $list as $release ) {
					$author     = get_userdata( $release['author'] );
					$outcome    = $checks[ $release['id'] ] ?? null;
					$releases[] = $release + array(
						'authorName' => $author instanceof \WP_User ? $author->display_name : '',
						'current'    => $release['id'] === $current,
						'checks'     => null === $outcome ? null : array(
							'errors'   => $outcome['errors'],
							'warnings' => $outcome['warnings'],
							// M-B1: how many pages the round checked of those of the release; null when it did not say.
							'pages'    => $outcome['pages'] ?? null,
						),
					);
				}
				// A record past its tolerance is closed here too, as on a visit to wp-admin (§11.5).
				\XB\Versioning\Scheduled::expire_overdue();
				return array(
					'releases'      => $releases,
					'next'          => Plugin::instance()->versioning->next_version( null ),
					'scheduled'     => \XB\Versioning\Scheduled::view(),
					'scheduledLast' => \XB\Versioning\Scheduled::last_view(),
					'cron'          => \XB\Versioning\Scheduled::cron(),
					'timezone'      => wp_timezone_string(),
				);
			}
		);
	}

	public function publish( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Only what this publisher may see (as the Release screen and the agent's publish): a page they cannot edit is
		// neither published nor confirmed to have changed by their request.
		$docs    = $request['docs'];
		$visible = array_column( self::visible_changes(), 'doc' );
		if ( is_array( $docs ) ) {
			$docs = array_values( array_intersect( array_filter( $docs, 'is_string' ), $visible ) );
		} elseif ( count( $visible ) !== count( Plugin::instance()->versioning->changes() ) ) {
			$docs = $visible;
		}
		return self::guard(
			static fn (): array => Plugin::instance()->versioning->publish(
				is_string( $request['version'] ) ? $request['version'] : null,
				sanitize_textarea_field( (string) $request['notes'] ),
				$docs,
				$request['checks']
			)
		);
	}

	public function schedule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$docs    = $request['docs'];
		$visible = array_column( self::visible_changes(), 'doc' );
		if ( is_array( $docs ) ) {
			$docs = array_values( array_intersect( array_filter( $docs, 'is_string' ), $visible ) );
		} elseif ( count( $visible ) !== count( Plugin::instance()->versioning->changes() ) ) {
			$docs = $visible;
		}
		return self::guard(
			static fn (): array => \XB\Versioning\Scheduled::schedule(
				(string) $request['at'],
				is_string( $request['version'] ) && '' !== $request['version'] ? $request['version'] : null,
				sanitize_textarea_field( (string) $request['notes'] ),
				$docs,
				$request['checks']
			)
		);
	}

	public function cancel_schedule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( true === $request['last'] ) {
			\XB\Versioning\Scheduled::dismiss_last();
			return self::no_store( array( 'dismissed' => true ) );
		}
		return self::guard(
			static function (): array {
				if ( ! \XB\Versioning\Scheduled::cancel() ) {
					throw new VersioningException( __( 'Non c\'è nessuna release programmata.', 'xb' ), 404 );
				}
				return array( 'cancelled' => true );
			}
		);
	}

	public function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::guard( static fn (): array => Plugin::instance()->versioning->restore( (int) $request['id'], false !== $request['keepContent'] ) );
	}

	public function preview_link( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request['post'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'xb_forbidden', __( 'Non puoi vedere l\'anteprima di questo contenuto.', 'xb' ), array( 'status' => 403 ) );
		}
		Plugin::instance()->versioning->store()->audit( 'preview_link', 'post:' . $id );
		$post = get_post( $id );
		$page = \XB\Templates\Templates::is_template( $post ) ? \XB\Templates\Templates::url( $post, (int) $request['context'] ) : null;
		// The same page as visitors see it now (visual comparison before publishing); none for what has no page of its own.
		$live = $page;
		if ( null === $page && $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			$live = get_permalink( $post );
		}
		$url = Preview::url( $id, $page, is_string( $request['as'] ) ? (string) $request['as'] : '', (int) $request['ttl'] );
		return self::no_store(
			array(
				'url'     => $url,
				'expires' => Preview::expires( $url ),
				'live'    => $live,
				'roles'   => Preview::roles(),
			)
		);
	}

	public function revoke_previews(): \WP_REST_Response {
		Preview::revoke();
		Plugin::instance()->versioning->store()->audit( 'revoke_previews', 'preview' );
		return self::no_store( array( 'revoked' => true ) );
	}

	public function audit_log(): \WP_REST_Response {
		return self::no_store( Plugin::instance()->versioning->store()->audit_log() );
	}

	// ------------------------------------------------------------------ preview

	/** Fragments the block editor shows as the site renders them, at most this many per request. */
	private const EDITOR_RENDER_ITEMS = 400;
	private const EDITOR_RENDER_BYTES = 2 * 1024 * 1024;

	/**
	 * Renders pieces of a post for the block editor, as the live site renders them: expressions, loops,
	 * components and raw HTML that Gutenberg cannot work out by itself. `items` maps a key to block markup.
	 * The live state is used (Gutenberg edits the live post, not the builder's dev version), and authors without
	 * unfiltered_html get the same filtering a save would apply.
	 */
	public function editor_render( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$items = $request['items'];
		$tests = is_array( $request['tests'] ) ? $request['tests'] : array();
		if ( ! is_array( $items ) || count( $items ) + count( $tests ) > self::EDITOR_RENDER_ITEMS || strlen( (string) $request->get_body() ) > self::EDITOR_RENDER_BYTES ) {
			return new \WP_Error( 'xb_too_large', __( 'Troppi elementi da mostrare in una volta.', 'xb' ), array( 'status' => 413 ) );
		}
		$target  = get_post( (int) $request['post_id'] );
		$plugin  = Plugin::instance();
		$trusted = current_user_can( 'unfiltered_html' );
		$stored  = $target instanceof \WP_Post ? $target->post_content : '';
		$out     = array();
		$passed  = array();
		global $post;
		$previous_post = $post;
		if ( $target instanceof \WP_Post ) {
			$post = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
			setup_postdata( $target );
		}
		try {
			foreach ( $items as $key => $markup ) {
				if ( ! is_string( $markup ) || 1 !== preg_match( '/^[a-z0-9:_-]{1,64}$/i', (string) $key ) ) {
					continue;
				}
				$markup = $trusted ? $markup : $plugin->sanitizer->restricted_content( $markup, $stored );
				$plugin->styles->reset();
				$out[ (string) $key ] = $plugin->renderer->render_blocks( parse_blocks( $markup ), Context::from_globals() );
			}
			foreach ( $tests as $key => $expression ) {
				if ( is_string( $expression ) && strlen( $expression ) <= 1000 && 1 === preg_match( '/^[a-z0-9:_-]{1,64}$/i', (string) $key ) ) {
					$passed[ (string) $key ] = $plugin->renderer->test( $expression, Context::from_globals() );
				}
			}
		} finally {
			$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			wp_reset_postdata();
		}
		return self::no_store(
			array(
				'items'   => (object) $out,
				'tests'   => (object) $passed,
				'version' => \XB\Render\ComponentRegistry::render_version(),
			)
		);
	}

	/**
	 * Renders unsaved content for the builder canvas, annotated with data-xb-uid.
	 * Authors without unfiltered_html get the same filtering a save would apply, so the preview never
	 * shows (or runs) markup they could not store.
	 */
	public function render( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request['post_id'];
		$blocks  = parse_blocks( (string) $request['content'] );
		$plugin  = Plugin::instance();
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$stored = $post_id > 0 ? get_post_field( 'post_content', $post_id, 'raw' ) : '';
			$blocks = parse_blocks( $plugin->sanitizer->restricted_content( (string) $request['content'], is_string( $stored ) ? $stored : '' ) );
		}

		Preview::use_dev();
		$target = $post_id > 0 ? get_post( $post_id ) : null;
		$target = $target instanceof \WP_Post ? $target : null;
		/**
		 * The canvas draws this document: plugins that depend on the page (its language: lists, component
		 * translations) set themselves up, and undo it on xb_render_document_done (docs/spec/multilingual.md).
		 */
		do_action( 'xb_render_document', $target );
		$plugin->components->forget();
		try {
			$html = self::render_html( $target, $blocks, (int) $request['context'], (string) $request['schema'] );
		} finally {
			do_action( 'xb_render_document_done', $target );
			$plugin->components->forget();
		}
		return self::render_response( $html, (bool) $request['css'], (string) $request['global_hash'] );
	}

	/** @param array<array<string, mixed>> $blocks Parsed blocks (parse_blocks). */
	private static function render_html( ?\WP_Post $target, array $blocks, int $context, string $schema = '' ): string {
		$plugin = Plugin::instance();
		if ( \XB\Templates\Templates::is_template( $target ) ) {
			// A template is previewed with a sample post (or a list) as the page it would render.
			return \XB\Templates\Templates::with_context( $target, $context, static fn (): string => $plugin->renderer->render_blocks( $blocks, Context::from_globals( true ) ) );
		}
		global $post;
		$previous_post = $post;
		if ( $target instanceof \WP_Post ) {
			$post = $target; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
			setup_postdata( $target );
		}
		try {
			$scope = Context::from_globals( true );
			if ( $target instanceof \WP_Post && 'wp_block' === $target->post_type ) {
				// A component drawn by itself: sample props, not a white page (E5).
				$page  = is_array( $scope->scope['props'] ?? null ) ? $scope->scope['props'] : array();
				$scope = $scope->with( array( 'props' => ComponentRegistry::sample_props( self::component_props( $target, $schema ) ) + $page ) );
			}
			return $plugin->renderer->render_blocks( $blocks, $scope );
		} finally {
			$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			wp_reset_postdata();
		}
	}

	/**
	 * The props of a component for its sample drawing: the schema being edited when it would save, else the one saved
	 * in development (what the builder opens), else the published one.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function component_props( \WP_Post $component, string $editing ): array {
		if ( '' !== $editing && strlen( $editing ) <= 200000 ) {
			$clean = self::clean_schema( json_decode( $editing, true ) );
			if ( is_array( $clean ) ) {
				return $clean['props'];
			}
		}
		try {
			$stored = Plugin::instance()->versioning->working_copy( Documents::for_post( $component->ID ) )['payload']['schema'] ?? null;
		} catch ( VersioningException ) {
			$stored = json_decode( (string) get_post_meta( $component->ID, ComponentRegistry::META_SCHEMA, true ), true );
		}
		$props = is_array( $stored ) && is_array( $stored['props'] ?? null ) ? $stored['props'] : array();
		return array_filter( $props, static fn ( mixed $definition, mixed $name ): bool => is_string( $name ) && is_array( $definition ), ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * The canvas drawing: HTML, the shell stylesheets and the site-wide CSS (theme.json, fonts, tokens). That CSS
	 * rarely changes between keystrokes, so it is sent only when its hash differs from the one the canvas has.
	 * The stored styles as one sheet (`css`) only when asked: the canvas uses its own live CSS.
	 */
	private static function render_response( string $html, bool $with_css = false, string $known_global = '' ): \WP_REST_Response {
		$plugin    = Plugin::instance();
		$framework = $plugin->frameworks->active();
		/** @var list<string> $stylesheets */
		$stylesheets = array_values(
			array_filter(
				(array) apply_filters(
					'xb_canvas_stylesheets',
					array_merge( \XB\Framework\Reset::enabled() ? array( \XB\Framework\Reset::url() ) : array(), array( includes_url( 'css/dist/block-library/style.min.css' ), get_stylesheet_uri() ), 'xb-tokens' === $framework->id() ? \XB\Framework\XbTokensProvider::canvas_stylesheets() : $framework->stylesheets() )
				),
				static fn ( mixed $url ): bool => is_string( $url ) && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true )
			)
		);
		$global_css  = \XB\Fonts\LocalFonts::css() . wp_get_global_stylesheet() . $plugin->frameworks->alias_css() . ( 'xb-tokens' === $framework->id() ? ( \XB\Framework\HistosCss\Module::active() ? \XB\Framework\HistosCss\Tokens::css_changes( \XB\Framework\HistosCss\SiteDesign::current( true ) ) : '' ) . \XB\Framework\TokenOverrides::css( true ) : '' );
		$global_hash = md5( $global_css );
		$data        = array(
			'html'        => $html,
			'globalHash'  => $global_hash,
			'stylesheets' => $stylesheets,
		);
		if ( ! hash_equals( $global_hash, $known_global ) ) {
			$data['globalCss'] = $global_css;
		}
		if ( $with_css ) {
			$data['css'] = $plugin->styles->all_css();
		}
		return self::no_store( $data );
	}

	// ------------------------------------------------------------------ framework

	/**
	 * Active framework: names for autocompletion in the CSS and HTML panels. The lists only change with the
	 * framework's files (its generator rewrites them when its settings change), the recipes and the plugin, so
	 * they are built once per version (uploads/xb-cache/fw-lists-*.json) and sent with an ETag: an opening of the
	 * builder with nothing changed gets a 304 and no body.
	 */
	public function get_framework( ?\WP_REST_Request $request = null ): \WP_REST_Response {
		$active = Plugin::instance()->frameworks->active();
		$key    = self::framework_key( $active );
		$data   = \XB\Styles\RulesCache::get( 'fw-lists', $key, false );
		if ( null === $data ) {
			$data = self::framework_lists( $active );
			\XB\Styles\RulesCache::put( 'fw-lists', $key, $data );
		}
		// The site's own recipes and variables (dev) are not in the cached lists: they change with the Design system,
		// not with the framework's files. They win over the framework's recipes, and they are part of the ETag.
		$site_recipes = \XB\Framework\SiteRecipes::current();
		$site_tokens  = \XB\Framework\SiteTokens::current( true );
		foreach ( $site_tokens as $name => $value ) {
			$data['variables'][] = array(
				'name'  => \XB\Framework\SiteTokens::PREFIX . $name,
				'group' => 'site',
				'value' => $value,
			);
		}
		$data['recipes'] = (object) ( $site_recipes + (array) ( $data['recipes'] ?? array() ) );
		$etag            = '"' . md5( $key . (string) wp_json_encode( array( $site_recipes, $site_tokens ) ) ) . '"';
		$response        = new \WP_REST_Response( $data );
		$response->header( 'ETag', $etag );
		// The browser keeps it but asks every time: a new framework file or recipe is seen at once.
		$response->header( 'Cache-Control', 'private, no-cache' );
		if ( null !== $request && $etag === trim( (string) $request->get_header( 'if_none_match' ) ) ) {
			$response->set_status( 304 );
			$response->set_data( null );
		}
		return $response;
	}

	/** What the lists of the active framework are built from: its files, the recipes, the plugin. */
	private static function framework_key( \XB\Framework\Provider $active ): string {
		$files = array();
		foreach ( array_merge( \XB\Framework\Carry::files( $active ), array( \XB\Framework\XbTokensProvider::file(), \XB\Framework\XbTokensProvider::classes_file() ) ) as $file ) {
			$files[] = $file . '|' . (string) @filesize( $file ) . '|' . (string) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is part of the key.
		}
		return md5( (string) wp_json_encode( array( $active->id(), $files, get_option( \XB\Framework\AcssToCore::RECIPES, array() ), \XB\Framework\HistosCss\Module::active(), XB_VERSION, get_user_locale() ) ) );
	}

	/** @return array<string, mixed> */
	private static function framework_lists( \XB\Framework\Provider $active ): array {
		$values     = self::variable_values( \XB\Framework\Carry::variables( $active ) );
		$variables  = array();
		foreach ( $active->variables() as $group => $names ) {
			foreach ( $names as $name ) {
				$variables[] = array(
					'name'  => $name,
					'group' => $group,
				) + ( isset( $values[ '--' . $name ] ) ? array( 'value' => $values[ '--' . $name ] ) : array() );
			}
		}
		foreach ( $active->aliases() as $token => $variable ) {
			$variables[] = array(
				'name'  => $token,
				'group' => 'xb',
			) + ( isset( $values[ '--' . $variable ] ) ? array( 'value' => $values[ '--' . $variable ] ) : array() );
		}
		$classes = array();
		foreach ( $active->classes() as $group => $names ) {
			foreach ( $names as $name ) {
				$classes[] = array(
					'name'  => $name,
					'group' => $group,
				);
			}
		}
		return array(
			'active'    => array(
				'id'    => $active->id(),
				'label' => $active->label(),
			),
			'variables' => $variables,
			'classes'   => $classes,
			// Recipes, completions of the CSS editor (`@name`): Histeon CSS's own, then those the site imported from
			// Automatic.css (they win on the same name).
			'recipes'   => \XB\Framework\AcssToCore::clean_recipes( (array) get_option( \XB\Framework\AcssToCore::RECIPES, array() ) + ( \XB\Framework\HistosCss\Module::active() ? \XB\Framework\HistosCss\Recipes::all() : array() ) ),
		);
	}


	/**
	 * Values of the framework's `:root` variables, for agents choosing between `--space-m` and `--space-l`: a value
	 * that is just another variable (`var(--x)`) is replaced by that one's, a few levels deep. Long values (a
	 * gradient, a font stack) are cut, since they only need to tell the scale.
	 *
	 * @param array<string, string> $raw `--name` => value, as Carry::variables() reads them.
	 * @return array<string, string>
	 */
	private static function variable_values( array $raw ): array {
		$out = array();
		foreach ( $raw as $name => $value ) {
			for ( $depth = 0; $depth < 4 && 1 === preg_match( '/^var\(\s*(--[a-zA-Z0-9_-]+)\s*\)$/', $value, $m ) && isset( $raw[ $m[1] ] ); $depth++ ) {
				$value = $raw[ $m[1] ];
			}
			$value        = (string) preg_replace( '/\s+/', ' ', $value );
			$out[ $name ] = mb_strlen( $value ) > 120 ? mb_substr( $value, 0, 117 ) . '...' : $value;
		}
		return $out;
	}

	// ------------------------------------------------------------------ styles

	// ------------------------------------------------------------------ queries

	/** Custom loop queries (dev version) and what the builder needs to write them — docs/spec/queries.md. */
	public function get_queries(): \WP_REST_Response|\WP_Error {
		return self::guard(
			static function (): array {
				$copy  = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
				$types = array();
				foreach ( get_post_types( array(), 'objects' ) as $type ) {
					if ( \XB\Render\LoopPresets::listable( $type->name ) ) {
						$types[] = array(
							'name'  => $type->name,
							'label' => (string) $type->labels->singular_name,
						);
					}
				}
				return array(
					'presets' => (object) ( is_array( $copy['payload']['presets'] ?? null ) ? $copy['payload']['presets'] : array() ),
					'sources' => (object) ( is_array( $copy['payload']['sources'] ?? null ) ? $copy['payload']['sources'] : array() ),
					'types'   => $types,
					'head'    => $copy['head'],
					'base'    => $copy['base'],
				);
			}
		);
	}

	public function get_site_scripts( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$copy    = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
		$scripts = $copy['payload'][ \XB\Scripts\SiteScripts::KEY ] ?? array();
		try {
			$scripts = \XB\Scripts\SiteScripts::validate( $scripts );
		} catch ( \InvalidArgumentException ) {
			$scripts = array();
		}
		return new \WP_REST_Response(
			array(
				'scripts'  => $scripts,
				'canWrite' => current_user_can( 'unfiltered_html' ),
				'types'    => array_values( get_post_types( array( 'public' => true ) ) ),
				'head'     => (string) $copy['head'],
				'base'     => (string) $copy['base'],
			)
		);
	}

	/** Keeps or undoes what an AI agent changed in its session (lista della spesa 158). */
	public function agent_session( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user = (int) $request['user'];
		try {
			if ( 'undo' === $request['action'] ) {
				return self::no_store( \XB\Ai\AgentSession::undo( $user ) );
			}
			\XB\Ai\AgentSession::keep( $user );
			return self::no_store( array( 'kept' => true ) );
		} catch ( \XB\Versioning\VersioningException $e ) {
			return new \WP_Error( 'xb_agent_session', $e->getMessage(), array( 'status' => $e->status ) );
		}
	}

	public function save_site_scripts( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$scripts = \XB\Scripts\SiteScripts::validate( $request['scripts'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_site_scripts', $e->getMessage(), array( 'status' => 400 ) );
		}
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		return self::guard(
			static function () use ( $scripts, $head, $base ): array {
				$versioning = Plugin::instance()->versioning;
				$payload    = $versioning->working_copy( Documents::SETTINGS )['payload'];
				$payload[ \XB\Scripts\SiteScripts::KEY ] = $scripts;
				$commit     = $versioning->save_dev( Documents::SETTINGS, $payload, $head, $base, __( 'Script del sito', 'xb' ) );
				return array(
					'head' => $commit['id'],
					'base' => $base,
				);
			}
		);
	}

	public function save_queries( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$presets = \XB\Render\Queries::validate( $request['presets'] );
			$sources = null === $request['sources'] ? null : \XB\Render\Sources::validate( $request['sources'], array_keys( $presets ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_query', $e->getMessage(), array( 'status' => 400 ) );
		}
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		return self::guard(
			static function () use ( $presets, $sources, $head, $base ): array {
				$versioning         = Plugin::instance()->versioning;
				$payload            = $versioning->working_copy( Documents::SETTINGS )['payload'];
				$payload['presets'] = $presets;
				if ( null !== $sources ) {
					$payload['sources'] = $sources;
				}
				$commit             = $versioning->save_dev( Documents::SETTINGS, $payload, $head, $base, __( 'Query dei loop', 'xb' ) );
				return array(
					'head' => $commit['id'],
					'base' => $base,
				);
			}
		);
	}

	// ------------------------------------------------------------------ content hub

	/**
	 * Taxonomies whose terms the builder edits (doc 26 voce 212): shown in the admin and in REST, managed by this user.
	 * The table writes through WordPress's own route, with its permissions.
	 *
	 * @return list<array{name: string, label: string, hierarchical: bool, rest: string}>
	 */
	private static function term_taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'show_ui' => true, 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
			if ( ! current_user_can( $taxonomy->cap->manage_terms ) ) {
				continue;
			}
			$out[] = array(
				'name'         => $taxonomy->name,
				'label'        => (string) $taxonomy->labels->name,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'rest'         => ( is_string( $taxonomy->rest_namespace ) && '' !== $taxonomy->rest_namespace ? $taxonomy->rest_namespace : 'wp/v2' ) . '/' . ( is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name ),
			);
		}
		return $out;
	}

	/**
	 * Content types, taxonomies and fields (dev version), the fields of other plugins with whether pages read
	 * them, and what the builder needs to write them — docs/spec/content.md.
	 */
	public function get_content(): \WP_REST_Response|\WP_Error {
		return self::guard(
			static function (): array {
				$copy    = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
				$live    = \XB\Content\ContentTypes::live();
				$content = $copy['payload']['content'] ?? null;
				try {
					$content = is_array( $content ) ? \XB\Content\ContentTypes::validate( $content, false ) : $live;
				} catch ( \InvalidArgumentException ) {
					$content = $live;
				}
				$public = $copy['payload']['publicFields'] ?? null;
				$public = is_array( $public ) ? array_values( array_filter( $public, 'is_string' ) ) : \XB\Fields\Fields::public_ids();
				$types  = array();
				foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
					if ( \XB\Render\LoopPresets::listable( $type->name ) || isset( $content['types'][ $type->name ] ) ) {
						$types[] = array(
							'name'  => $type->name,
							'label' => (string) $type->labels->singular_name,
							'own'   => isset( $live['types'][ $type->name ] ),
							// How many contents a type has: removing a published type leaves them unreachable.
							'count' => self::count_items( $type->name ),
							// The table of contents edits through WordPress's own REST API, with its permissions.
							'rest'  => $type->show_in_rest && current_user_can( $type->cap->edit_posts ) ? ( ( is_string( $type->rest_namespace ) ? $type->rest_namespace : 'wp/v2' ) . '/' . ( is_string( $type->rest_base ) && '' !== $type->rest_base ? $type->rest_base : $type->name ) ) : null,
						);
					}
				}
				return array(
					'content'      => self::json_object( $content ),
					'live'         => self::json_object( $live ),
					'publicFields' => $public,
					'providers'    => \XB\Fields\Fields::describe( $public ),
					'postTypes'    => $types,
					'termTaxonomies' => self::term_taxonomies(),
					'fieldTypes'   => \XB\Content\ContentTypes::FIELD_TYPES,
					'supports'     => \XB\Content\ContentTypes::SUPPORTS,
					'canManage'    => current_user_can( 'manage_options' ),
					'head'         => $copy['head'],
					'base'         => $copy['base'],
				);
			}
		);
	}

	/** Contents of a post type in any state but trash and auto-draft. */
	private static function count_items( string $type ): int {
		global $wpdb;
		// Not wp_count_posts(): its cache does not follow a change of post type.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit')", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The contents of a content type, before it is removed (docs/spec/content.md §7): moved to another type or put
	 * in the trash (recoverable). Administrators only, at most MOVE_BATCH per call: `left` says what remains.
	 * Each content goes through WordPress (wp_update_post, wp_trash_post) with its own permission checks.
	 */
	public function move_content_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type   = (string) $request['type'];
		$action = (string) $request['action'];
		$to     = (string) $request['to'];
		if ( ! post_type_exists( $type ) || in_array( $type, \XB\Render\LoopPresets::INTERNAL_TYPES, true ) || in_array( $type, array( 'page', 'post', 'attachment' ), true ) ) {
			return new \WP_Error( 'xb_invalid_type', __( 'Tipo di contenuto non valido.', 'xb' ), array( 'status' => 400 ) );
		}
		if ( 'move' === $action ) {
			$target = get_post_type_object( $to );
			if ( null === $target || $to === $type || in_array( $to, \XB\Render\LoopPresets::INTERNAL_TYPES, true ) || 'attachment' === $to || ! $target->show_ui ) {
				return new \WP_Error( 'xb_invalid_type', __( 'Tipo di destinazione non valido.', 'xb' ), array( 'status' => 400 ) );
			}
		}
		$ids   = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array_values( array_diff( get_post_stati(), array( 'trash', 'auto-draft', 'inherit' ) ) ),
				'numberposts'      => self::MOVE_BATCH,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$done  = 0;
		$skip  = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'trash' === $action ? 'delete_post' : 'edit_post', $id ) ) {
				++$skip;
				continue;
			}
			$ok = 'trash' === $action ? false !== wp_trash_post( $id ) : ! is_wp_error( wp_update_post( array( 'ID' => $id, 'post_type' => $to ), true ) );
			$ok ? ++$done : ++$skip;
		}
		Plugin::instance()->versioning->store()->audit( 'content_items_' . $action, 'type:' . $type, array( 'to' => $to, 'done' => $done, 'skipped' => $skip ) );
		return self::no_store(
			array(
				'done'    => $done,
				'skipped' => $skip,
				'left'    => self::count_items( $type ),
			)
		);
	}

	public function save_content( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$content = \XB\Content\ContentTypes::validate( $request['content'] );
			$public  = \XB\Fields\Fields::validate( $request['publicFields'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_content', $e->getMessage(), array( 'status' => 400 ) );
		}
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		return self::guard(
			static function () use ( $content, $public, $head, $base ): array {
				$versioning              = Plugin::instance()->versioning;
				$payload                 = $versioning->working_copy( Documents::SETTINGS )['payload'];
				$payload['content']      = $content;
				$payload['publicFields'] = $public;
				$commit                  = $versioning->save_dev( Documents::SETTINGS, $payload, $head, $base, __( 'Tipi di contenuto e campi', 'xb' ) );
				return array(
					'head' => $commit['id'],
					'base' => $base,
				);
			}
		);
	}

	/**
	 * Empty maps stay JSON objects (`{}`), not lists.
	 *
	 * @param array{types: array<string, mixed>, taxonomies: array<string, mixed>, fields: array<string, mixed>, groups?: array<string, mixed>} $content
	 * @return array<string, object>
	 */
	private static function json_object( array $content ): array {
		return array(
			'types'      => (object) $content['types'],
			'taxonomies' => (object) $content['taxonomies'],
			'fields'     => (object) $content['fields'],
			'groups'     => (object) ( $content['groups'] ?? array() ),
		);
	}

	/** Runs a preset as a visitor would see it (published, readable content): the first ten titles. */
	public function test_query( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (string) $request['id'];
		try {
			$valid = \XB\Render\Queries::validate( array( $id => $request['preset'] ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_query', $e->getMessage(), array( 'status' => 400 ) );
		}
		$params = array();
		foreach ( (array) $request['params'] as $name => $value ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) && ( is_scalar( $value ) || null === $value ) ) {
				$params[ $name ] = $value;
			}
		}
		$group_by = null;
		if ( null !== $request['groupBy'] ) {
			$group_by = \XB\Render\Queries::group_by( $request['groupBy'] );
			if ( null === $group_by ) {
				return new \WP_Error( 'xb_invalid_group_by', __( 'Raggruppamento non valido: un campo (date, title, fields.anno) e, se serve, il modo ("letter" o un formato di data).', 'xb' ), array( 'status' => 400 ) );
			}
		}
		return self::no_store( \XB\Render\Queries::test( $id, $valid[ $id ], $params, $group_by ) );
	}

	public function get_styles(): \WP_REST_Response|\WP_Error {
		return self::guard( static fn (): array => self::styles_document() );
	}

	/** @return array<string, mixed> */
	private static function styles_document(): array {
		$copy = Plugin::instance()->versioning->working_copy( Documents::STYLES );
		return array(
			'styles'  => is_array( $copy['payload']['styles'] ?? null ) ? array_values( $copy['payload']['styles'] ) : array(),
			'globals' => is_array( $copy['payload']['globals'] ?? null ) ? array_values( $copy['payload']['globals'] ) : array(),
			// Named media queries (settings, dev version): the builder resolves them in its preview.
			'queries' => (object) self::named_queries()['queries'],
			'head'    => $copy['head'],
			'base'    => $copy['base'],
		);
	}

	/**
	 * Named media queries of the settings in dev, with that document's heads.
	 *
	 * @return array{queries: array<string, string>, head: string|null, base: string|null}
	 */
	private static function named_queries(): array {
		$copy    = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
		$queries = $copy['payload']['queries'] ?? null;
		try {
			$queries = is_array( $queries ) ? \XB\Styles\NamedQueries::validate( $queries ) : \XB\Styles\NamedQueries::all();
		} catch ( \InvalidArgumentException ) {
			$queries = \XB\Styles\NamedQueries::DEFAULTS;
		}
		return array(
			'queries' => $queries,
			'head'    => $copy['head'],
			'base'    => $copy['base'],
		);
	}

	public function get_named_queries(): \WP_REST_Response|\WP_Error {
		return self::guard(
			static function (): array {
				$data            = self::named_queries();
				$data['queries'] = (object) $data['queries'];
				return $data;
			}
		);
	}

	public function save_named_queries( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$queries = \XB\Styles\NamedQueries::validate( $request['queries'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_queries', $e->getMessage(), array( 'status' => 400 ) );
		}
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		return self::guard(
			static function () use ( $queries, $head, $base ): array {
				$versioning         = Plugin::instance()->versioning;
				$payload            = $versioning->working_copy( Documents::SETTINGS )['payload'];
				$payload['queries'] = $queries;
				$commit             = $versioning->save_dev( Documents::SETTINGS, $payload, $head, $base, __( 'Media query con nome', 'xb' ) );
				return array(
					'head' => $commit['id'],
					'base' => $base,
				);
			}
		);
	}

	/** The current user's changes to the builder's keyboard shortcuts — docs/spec/shortcuts.md. */
	public function get_shortcuts(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'shortcuts' => (object) \XB\Admin\Shortcuts::get( get_current_user_id() ) ) );
	}

	public function save_shortcuts( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$map = \XB\Admin\Shortcuts::validate( $request['shortcuts'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_shortcuts', $e->getMessage(), array( 'status' => 400 ) );
		}
		\XB\Admin\Shortcuts::save( get_current_user_id(), $map );
		return new \WP_REST_Response( array( 'shortcuts' => (object) $map ) );
	}

	/**
	 * Facets of the settings in dev, with that document's heads — docs/spec/facets.md.
	 *
	 * @return array<string, mixed>
	 */
	private static function facets_document(): array {
		$copy   = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
		$facets = $copy['payload']['facets'] ?? array();
		try {
			$facets = \XB\Render\Facets::validate( $facets, null, false );
		} catch ( \InvalidArgumentException ) {
			$facets = array();
		}
		return array(
			'facets' => (object) $facets,
			'head'   => $copy['head'],
			'base'   => $copy['base'],
		);
	}

	public function get_facets(): \WP_REST_Response|\WP_Error {
		return self::guard( static fn (): array => self::facets_document() );
	}

	public function save_facets( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$copy    = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
		$presets = is_array( $copy['payload']['presets'] ?? null ) ? array_map( 'strval', array_keys( $copy['payload']['presets'] ) ) : array();
		try {
			$facets = \XB\Render\Facets::validate( $request['facets'], $presets );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_facets', $e->getMessage(), array( 'status' => 400 ) );
		}
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		return self::guard(
			static function () use ( $facets, $head, $base ): array {
				$versioning        = Plugin::instance()->versioning;
				$payload           = $versioning->working_copy( Documents::SETTINGS )['payload'];
				$payload['facets'] = $facets;
				$commit            = $versioning->save_dev( Documents::SETTINGS, $payload, $head, $base, __( 'Filtri', 'xb' ) );
				return array(
					'head' => $commit['id'],
					'base' => $base,
				);
			}
		);
	}

	/**
	 * Histeon Tokens values of the settings in dev, with that document's heads — docs/spec/design-system.md.
	 *
	 * @return array<string, mixed>
	 */
	private static function tokens_document(): array {
		$copy = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
		try {
			$tokens = \XB\Framework\TokenOverrides::validate( $copy['payload']['tokens'] ?? array() );
		} catch ( \InvalidArgumentException ) {
			$tokens = array();
		}
		try {
			$design = \XB\Framework\HistosCss\Design::validate( $copy['payload']['design'] ?? array() );
		} catch ( \InvalidArgumentException ) {
			$design = \XB\Framework\HistosCss\Design::DEFAULTS;
		}
		try {
			$site_tokens = \XB\Framework\SiteTokens::validate( $copy['payload']['siteTokens'] ?? array() );
		} catch ( \InvalidArgumentException ) {
			$site_tokens = array();
		}
		return array(
			'tokens'     => (object) $tokens,
			// The site's own variables and recipes (docs/spec/design-system.md), with the recipes it can override.
			'siteTokens' => (object) $site_tokens,
			'recipes'    => array(
				'site'     => (object) \XB\Framework\SiteRecipes::current(),
				'imported' => (object) \XB\Framework\AcssToCore::clean_recipes( (array) get_option( \XB\Framework\AcssToCore::RECIPES, array() ) ),
				'histos'   => (object) ( \XB\Framework\HistosCss\Module::active() ? \XB\Framework\HistosCss\Recipes::all() : array() ),
			),
			'design'   => (object) $design,
			'defaults' => (object) \XB\Framework\HistosCss\Design::DEFAULTS,
			'contrast' => \XB\Framework\HistosCss\Tokens::contrast( $design ),
			'names'    => \XB\Framework\TokenOverrides::names(),
			'editable' => 'xb-tokens' === Plugin::instance()->frameworks->active()->id(),
			'head'     => $copy['head'],
			'base'     => $copy['base'],
		);
	}

	/**
	 * The Histeon CSS panel's live preview (docs/spec/histos-css.md §6): the tokens that some parameters change, as
	 * `:root{…}`. Nothing is saved. Parameters are validated: the CSS can neither close its rule nor load anything.
	 */
	public function preview_design( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! \XB\Framework\HistosCss\Module::active() ) {
			return new \WP_Error( 'xb_module_off', __( 'Il modulo Histeon CSS è spento (Histeon → Moduli).', 'xb' ), array( 'status' => 404 ) );
		}
		try {
			$design = \XB\Framework\HistosCss\Design::validate( $request['design'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_design', $e->getMessage(), array( 'status' => 400 ) );
		}
		$out = array(
			'css'        => \XB\Framework\HistosCss\Tokens::preview_css( $design ),
			'classesOff' => \XB\Framework\HistosCss\Utilities::off_patterns( $design ),
			'design'     => (object) $design,
			'contrast'   => \XB\Framework\HistosCss\Tokens::contrast( $design ),
		);
		// «Export tokens (DTCG)»: every printed token, light and dark, only when asked (the live preview stays small).
		if ( true === $request['tokens'] ) {
			$out['tokens'] = \XB\Framework\HistosCss\Tokens::export( $design );
		}
		return new \WP_REST_Response( $out );
	}

	public function get_tokens(): \WP_REST_Response|\WP_Error {
		return self::guard( static fn (): array => self::tokens_document() );
	}

	public function save_tokens( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$tokens = \XB\Framework\TokenOverrides::validate( $request['tokens'] );
			// The design parameters, the site's variables and recipes are optional: without them, the saved ones stay.
			$design      = null === $request['design'] ? null : \XB\Framework\HistosCss\Design::changes( \XB\Framework\HistosCss\Design::validate( $request['design'] ) );
			$site_tokens = null === $request['siteTokens'] ? null : \XB\Framework\SiteTokens::validate( $request['siteTokens'] );
			$recipes     = null === $request['recipes'] ? null : \XB\Framework\SiteRecipes::validate( $request['recipes'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_tokens', $e->getMessage(), array( 'status' => 400 ) );
		}
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		return self::guard(
			static function () use ( $tokens, $design, $site_tokens, $recipes, $head, $base ): array {
				$versioning        = Plugin::instance()->versioning;
				$payload           = $versioning->working_copy( Documents::SETTINGS )['payload'];
				$payload['tokens'] = $tokens;
				if ( null !== $design ) {
					$payload['design'] = $design;
				}
				if ( null !== $site_tokens ) {
					$payload['siteTokens'] = $site_tokens;
				}
				if ( null !== $recipes ) {
					$payload['recipes'] = $recipes;
				}
				$commit            = $versioning->save_dev( Documents::SETTINGS, $payload, $head, $base, __( 'Design system', 'xb' ) );
				return array(
					'head' => $commit['id'],
					'base' => $base,
				);
			}
		);
	}

	/** Runs a facet being edited with test parameters, as a visitor would see it (dev queries). */
	public function test_facet( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$copy    = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
		$presets = is_array( $copy['payload']['presets'] ?? null ) ? array_map( 'strval', array_keys( $copy['payload']['presets'] ) ) : array();
		try {
			$facets = \XB\Render\Facets::validate( array( 'prova' => $request['facet'] ), $presets );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_invalid_facets', $e->getMessage(), array( 'status' => 400 ) );
		}
		$params = is_array( $request['params'] ) ? $request['params'] : array();
		return self::guard(
			static function () use ( $facets, $params ): array {
				Preview::use_dev(); // Like the canvas: the queries saved in dev, not yet released.
				return \XB\Render\Facets::test( $facets['prova'], $params );
			}
		);
	}

	public function save_styles( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$styles  = $request['styles'];
		$globals = $request['globals'];
		if ( ! is_array( $styles ) ) {
			return new \WP_Error( 'xb_invalid_styles', __( 'Formato degli stili non valido.', 'xb' ), array( 'status' => 400 ) );
		}
		$current = Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload'];
		$existing_styles = array();
		foreach ( (array) ( $current['styles'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) ) {
				$existing_styles[ $entry['id'] ] = $entry;
			}
		}
		$existing_globals = array();
		foreach ( (array) ( $current['globals'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) ) {
				$existing_globals[ $entry['id'] ] = $entry;
			}
		}
		$clean = array();
		$bytes = 0;
		foreach ( $styles as $entry ) {
			$selector = is_array( $entry ) && is_string( $entry['selector'] ?? null ) ? trim( $entry['selector'] ) : '';
			$css      = is_array( $entry ) && is_string( $entry['css'] ?? null ) ? $entry['css'] : '';
			$id       = is_array( $entry ) && is_string( $entry['id'] ?? null ) ? $entry['id'] : '';
			if ( strlen( $selector ) > 500 || ! \XB\Styles\CssCompiler::valid_selector( $selector ) || 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $id ) ) {
				return new \WP_Error( 'xb_invalid_selector', sprintf( __( 'Voce di stile non valida: "%s".', 'xb' ), $selector ), array( 'status' => 400 ) );
			}
			$assets = self::clean_css_asset_mappings( array_key_exists( 'assets', $entry ) ? $entry['assets'] : ( $existing_styles[ $id ]['assets'] ?? array() ) );
			if ( is_wp_error( $assets ) ) {
				return $assets;
			}
			$bytes  += strlen( $css );
			$style = array(
				'id'       => $id,
				'selector' => $selector,
				'css'      => $css,
			);
			if ( array() !== $assets ) {
				$style['assets'] = $assets;
			}
			$clean[] = $style;
		}
		$clean_globals = array();
		foreach ( is_array( $globals ) ? $globals : array() as $sheet ) {
			if ( is_array( $sheet ) && is_string( $sheet['css'] ?? null ) && is_string( $sheet['id'] ?? null ) && 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $sheet['id'] ) ) {
				$assets = self::clean_css_asset_mappings( array_key_exists( 'assets', $sheet ) ? $sheet['assets'] : ( $existing_globals[ $sheet['id'] ]['assets'] ?? array() ) );
				if ( is_wp_error( $assets ) ) {
					return $assets;
				}
				$bytes          += strlen( $sheet['css'] );
				$global = array(
					'id'  => $sheet['id'],
					'css' => $sheet['css'],
				);
				if ( array() !== $assets ) {
					$global['assets'] = $assets;
				}
				try {
					$source = \XB\Remote\PageProfile::validate_framework_source( $sheet['source'] ?? ( $existing_globals[ $sheet['id'] ]['source'] ?? null ) );
				} catch ( \InvalidArgumentException $error ) {
					return new \WP_Error( 'xb_invalid_style_source', $error->getMessage(), array( 'status' => 400 ) );
				}
				if ( null !== $source ) {
					$global['source'] = $source;
				}
				$clean_globals[] = $global;
			}
		}
		if ( $bytes > self::MAX_CSS_BYTES ) {
			return new \WP_Error( 'xb_styles_too_large', __( 'Gli stili superano la dimensione massima.', 'xb' ), array( 'status' => 413 ) );
		}
		$head = (string) $request['head'];
		$base = (string) $request['base'];
		return self::guard(
			static function () use ( $clean, $clean_globals, $globals, $head, $base ): array {
				$versioning = Plugin::instance()->versioning;
				$current    = $versioning->working_copy( Documents::STYLES )['payload'];
				$versioning->save_dev(
					Documents::STYLES,
					array(
						'styles'  => $clean,
						'globals' => is_array( $globals ) ? $clean_globals : ( $current['globals'] ?? array() ),
					),
					$head,
					$base
				);
				return self::styles_document();
			}
		);
	}

	/**
	 * Keep the stable media links on CSS sheets saved by older and current builder clients.
	 * The full profile validator verifies that each linked ID is part of the transfer archive.
	 *
	 * @return list<array{reference: string, assetId: string}>|\WP_Error
	 */
	private static function clean_css_asset_mappings( mixed $raw ): array|\WP_Error {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > 500 ) {
			return new \WP_Error( 'xb_invalid_css_assets', __( 'Collegamenti ai file CSS non validi.', 'xb' ), array( 'status' => 400 ) );
		}
		$clean = array();
		$seen = array();
		foreach ( $raw as $mapping ) {
			if ( ! is_array( $mapping ) || 2 !== count( $mapping ) || ! is_string( $mapping['reference'] ?? null ) || '' === trim( $mapping['reference'] ) || strlen( $mapping['reference'] ) > 2048 || ! is_string( $mapping['assetId'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $mapping['assetId'] ) ) {
				return new \WP_Error( 'xb_invalid_css_assets', __( 'Collegamenti ai file CSS non validi.', 'xb' ), array( 'status' => 400 ) );
			}
			$keys = array_keys( $mapping );
			sort( $keys );
			if ( array( 'assetId', 'reference' ) !== $keys || isset( $seen[ $mapping['reference'] ] ) ) {
				return new \WP_Error( 'xb_invalid_css_assets', __( 'I collegamenti ai file CSS contengono campi non validi o riferimenti duplicati.', 'xb' ), array( 'status' => 400 ) );
			}
			$seen[ $mapping['reference'] ] = true;
			$clean[] = array( 'reference' => $mapping['reference'], 'assetId' => strtolower( $mapping['assetId'] ) );
		}
		return $clean;
	}

	// ------------------------------------------------------------------ selectors

	/** Every style selector with the documents that use it (docs/spec/styles.md §4). */
	public function list_selectors(): \WP_REST_Response|\WP_Error {
		return self::guard( static fn (): array => Selectors::instance()->usage() );
	}

	/** Renames a class and its BEM children everywhere, as dev commits; `dryRun` only reports. */
	public function rename_selector( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$from = (string) $request['from'];
		$to   = (string) $request['to'];
		$dry  = true === $request['dryRun'];
		// Free-HTML occurrences to rewrite too (C4), each with the text it had in the preview.
		$html = array();
		foreach ( is_array( $request['html'] ) ? $request['html'] : array() as $pick ) {
			if ( is_array( $pick ) && is_string( $pick['id'] ?? null ) && is_string( $pick['before'] ?? null ) ) {
				$html[] = array(
					'id'     => $pick['id'],
					'before' => $pick['before'],
				);
			}
		}
		return self::guard( static fn (): array => Selectors::instance()->rename( $from, $to, ! $dry, array_slice( $html, 0, 500 ) ) );
	}

	/** Deletes style entries that nothing uses, as one dev commit. */
	/** A class or id used by scripts, props or widgets, always included in the CSS (lista migliorie C6). */
	public function safelist_selector( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$name = (string) $request['name'];
		$on   = (bool) $request['on'];
		return self::guard( static fn (): array => array( 'safelist' => \XB\Styles\PageStyles::set_safelisted( $name, $on ) ) );
	}

	public function delete_selectors( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ids = array_values( array_filter( (array) $request['ids'], 'is_string' ) );
		return self::guard( static fn (): array => Selectors::instance()->delete( $ids ) );
	}

	// ------------------------------------------------------------------ components

	public function list_components(): \WP_REST_Response {
		$posts = get_posts(
			array(
				'post_type'   => 'wp_block',
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => 500,
				'meta_key'    => ComponentRegistry::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		$items = array();
		// The builder works in development: a prop added there (a variant, lista della spesa 157) can be set on the
		// pages before the component is published, as the canvas already draws it.
		$store = Plugin::instance()->versioning->store();
		$dev   = $store->unpublished( array_values( array_map( static fn ( \WP_Post $p ): string => Documents::for_post( $p->ID ), $posts ) ) );
		foreach ( $posts as $post ) {
			if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$ref = (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true );
			if ( 1 !== preg_match( Spec::COMPONENT_REF_PATTERN, $ref ) ) {
				continue;
			}
			$draft  = isset( $dev[ Documents::for_post( $post->ID ) ] ) ? $store->get( $dev[ Documents::for_post( $post->ID ) ] ) : null;
			$schema = is_array( $draft['payload']['schema'] ?? null ) ? $draft['payload']['schema'] : json_decode( (string) get_post_meta( $post->ID, ComponentRegistry::META_SCHEMA, true ), true );
			$items[] = array(
				'id'    => $post->ID,
				'ref'   => $ref,
				'title' => $post->post_title,
				'props' => is_array( $schema ) && is_array( $schema['props'] ?? null ) ? $schema['props'] : array(),
			);
		}
		return self::no_store( $items );
	}

	public function create_component( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ref   = (string) $request['ref'];
		$valid = self::check_ref( $ref, 0 );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$title = sanitize_text_field( (string) $request['title'] );
		$content = (string) $request['content'];
		if ( '' !== $content && ! Spec::has_xb_blocks( $content ) ) {
			return new \WP_Error( 'xb_invalid_content', __( 'Il contenuto del componente non è markup di Histeon.', 'xb' ), array( 'status' => 400 ) );
		}
		// The post is live but empty, so the live site renders exactly as before; the markup is a dev commit and goes
		// online with the release (with the first page that uses it: Versioning::publish). Nothing is published
		// here, whoever asks: the builder or an agent through the connector. The dev commit filters the markup of
		// authors without unfiltered_html, as any save.
		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => '' === $title ? $ref : $title,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, ComponentRegistry::META_KEY, $ref );
		update_post_meta( $id, ComponentRegistry::META_SCHEMA, wp_slash( '{"props":{}}' ) );
		Plugin::instance()->components->forget();
		$versioning = Plugin::instance()->versioning;
		if ( '' !== $content ) {
			try {
				$doc  = Documents::for_post( $id );
				$copy = $versioning->working_copy( $doc );
				$versioning->save_dev( $doc, array( 'content' => $content ) + $copy['payload'], $copy['head'], $copy['base'], __( 'Componente creato', 'xb' ) );
			} catch ( VersioningException $e ) {
				wp_delete_post( $id, true );
				Plugin::instance()->components->forget();
				return new \WP_Error( 'xb_versioning', $e->getMessage(), array( 'status' => $e->status ) );
			}
		}
		$versioning->store()->audit( 'create_component', 'post:' . $id, array( 'ref' => $ref ) );
		$response = self::no_store( self::document( $id ) );
		$response->set_status( 201 );
		return $response;
	}

	/** A component key must be well formed and unique. */
	private static function check_ref( string $ref, int $own_id ): true|\WP_Error {
		if ( 1 !== preg_match( Spec::COMPONENT_REF_PATTERN, $ref ) ) {
			return new \WP_Error( 'xb_invalid_ref', __( 'Chiave non valida: usa minuscole e trattini, con al massimo un namespace (es. "ui/card-hero").', 'xb' ), array( 'status' => 400 ) );
		}
		$taken = get_posts(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'exclude'     => $own_id > 0 ? array( $own_id ) : array(),
				'meta_key'    => ComponentRegistry::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $ref, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		// The key namespace also holds the active theme's template parts (owner() resolves both): claiming a part's
		// key would replace it live without edit_theme_options.
		$owner = ComponentRegistry::owner( $ref );
		if ( array() !== $taken || ( null !== $owner && $owner !== $own_id ) ) {
			return new \WP_Error( 'xb_ref_taken', __( 'Esiste già un componente con questa chiave.', 'xb' ), array( 'status' => 409 ) );
		}
		return true;
	}

	private const PROP_TYPES = array( 'string', 'number', 'boolean', 'url', 'image', 'rich-text', 'enum', 'array', 'object' );

	/**
	 * Validates a props schema: {"props": {"name": {"type": …, "default": scalar|null, "sample"?: scalar, "label"?: string,
	 * "options"?: string[]}}}. The sample is what the builder shows when the component is open by itself (E5).
	 *
	 * @return array{props: array<string, array<string, mixed>>}|\WP_Error
	 */
	private static function clean_schema( mixed $schema ): array|\WP_Error {
		$props = is_array( $schema ) && is_array( $schema['props'] ?? null ) ? $schema['props'] : null;
		if ( null === $props || count( $props ) > 64 ) {
			return new \WP_Error( 'xb_invalid_schema', __( 'Schema delle props non valido.', 'xb' ), array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( $props as $name => $definition ) {
			$type = is_array( $definition ) ? ( $definition['type'] ?? 'string' ) : null;
			if ( ! is_array( $definition ) || ! is_string( $name ) || 1 !== preg_match( Spec::IDENTIFIER_PATTERN, $name ) || ! in_array( $type, self::PROP_TYPES, true ) ) {
				return new \WP_Error( 'xb_invalid_schema', sprintf( __( 'Prop non valida: "%s".', 'xb' ), is_string( $name ) ? $name : '?' ), array( 'status' => 400 ) );
			}
			$entry   = array( 'type' => $type );
			$default = $definition['default'] ?? null;
			if ( null !== $default ) {
				if ( ! is_scalar( $default ) || ( is_string( $default ) && strlen( $default ) > 2000 ) ) {
					return new \WP_Error( 'xb_invalid_schema', sprintf( __( 'Default non valido per "%s".', 'xb' ), $name ), array( 'status' => 400 ) );
				}
				$entry['default'] = $default;
			}
			$sample = $definition['sample'] ?? null;
			if ( null !== $sample ) {
				if ( ! is_scalar( $sample ) || ( is_string( $sample ) && strlen( $sample ) > 2000 ) ) {
					/* translators: %s: prop name */
					return new \WP_Error( 'xb_invalid_schema', sprintf( __( 'Valore di prova non valido per "%s".', 'xb' ), $name ), array( 'status' => 400 ) );
				}
				$entry['sample'] = $sample;
			}
			if ( is_string( $definition['label'] ?? null ) ) {
				$entry['label'] = sanitize_text_field( $definition['label'] );
			}
			if ( 'enum' === $type && is_array( $definition['options'] ?? null ) ) {
				$entry['options'] = array_values( array_map( 'sanitize_text_field', array_filter( $definition['options'], 'is_string' ) ) );
			}
			$clean[ $name ] = $entry;
		}
		return array( 'props' => $clean );
	}

	private static function no_store( mixed $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
