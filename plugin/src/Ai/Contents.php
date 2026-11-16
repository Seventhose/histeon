<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

use XB\Rest\Controller;

/**
 * An AI agent on the site's contents (doc 26 voce 216, docs/spec/ai.md «Contenuti»): search every type, read a
 * content with its fields, change its title, excerpt and fields. Never its status: a draft stays a draft and nothing
 * gets published. Contents have no Histeon versions, so before the first change of a session the old values go into
 * the agent's session (AgentSession), and «Annulla tutto» puts them back.
 *
 * Every read and write goes through WordPress's own REST routes (`wp/v2/<type>`) as the current user: the same
 * permissions, schema and sanitization of the registered fields as the block editor. Allowed only while the area
 * «contenuti» is on (Scopes), whoever asks: the connector in a builder tab or an Ability.
 */
final class Contents {

	/** At most this many results per search. */
	public const PER_PAGE = 20;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Controller::NAMESPACE,
			'/agent/contents',
			array(
				'methods'             => 'GET',
				'callback'            => static fn ( \WP_REST_Request $r ): \WP_REST_Response => self::no_store( self::search( (string) $r->get_param( 'search' ), (string) $r->get_param( 'type' ), max( 1, (int) $r->get_param( 'page' ) ) ) ),
				'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ),
				'args'                => array(
					'search' => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'type'   => array(
						'type'      => 'string',
						'maxLength' => 40,
					),
					'page'   => array( 'type' => 'integer' ),
				),
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			'/agent/contents/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static function ( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
						$out = self::get( (int) $r['id'] );
						return is_wp_error( $out ) ? $out : self::no_store( $out );
					},
					'permission_callback' => static fn ( \WP_REST_Request $r ): bool => current_user_can( 'xb_build' ) && current_user_can( 'edit_post', (int) $r['id'] ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => static function ( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
						$out = self::update( (int) $r['id'], (array) $r->get_json_params() );
						return is_wp_error( $out ) ? $out : self::no_store( $out );
					},
					'permission_callback' => static fn ( \WP_REST_Request $r ): bool => current_user_can( 'xb_build' ) && current_user_can( 'edit_post', (int) $r['id'] ),
				),
			)
		);
	}

	/**
	 * Contents the user may edit, of every type shown in REST (articles, pages, records), any status but the bin.
	 *
	 * @return array{items: list<array{id: int, type: string, typeLabel: string, title: string, status: string, modified: string}>, pages: int}
	 */
	public static function search( string $search, string $type, int $page ): array {
		$types = self::types();
		if ( '' !== $type ) {
			$types = array_values( array_intersect( $types, array( $type ) ) );
		}
		if ( array() === $types ) {
			return array(
				'items' => array(),
				'pages' => 0,
			);
		}
		$query = new \WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				's'                   => mb_substr( $search, 0, 100 ),
				'posts_per_page'      => self::PER_PAGE,
				'paged'               => $page,
				'orderby'             => '' === $search ? 'modified' : 'relevance',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$object  = get_post_type_object( $post->post_type );
			$items[] = array(
				'id'        => $post->ID,
				'type'      => $post->post_type,
				'typeLabel' => $object instanceof \WP_Post_Type ? (string) $object->labels->singular_name : $post->post_type,
				'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'status'    => $post->post_status,
				'modified'  => (string) $post->post_modified_gmt,
			);
		}
		return array(
			'items' => $items,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * A content as its REST route gives it to who edits it: title and excerpt as written, status, link, fields.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( int $id ): array|\WP_Error {
		$route = self::route( $id );
		if ( is_wp_error( $route ) ) {
			return $route;
		}
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error() ?? new \WP_Error( 'xb_agent_content', __( 'Contenuto non leggibile.', 'xb' ), array( 'status' => 500 ) );
		}
		$data = (array) $response->get_data();
		return array(
			'id'      => $id,
			'type'    => (string) get_post_type( $id ),
			'title'   => (string) ( $data['title']['raw'] ?? '' ),
			'excerpt' => (string) ( $data['excerpt']['raw'] ?? '' ),
			'status'  => (string) ( $data['status'] ?? '' ),
			'link'    => (string) ( $data['link'] ?? '' ),
			'fields'  => is_array( $data['meta'] ?? null ) ? $data['meta'] : (object) array(),
		);
	}

	/**
	 * Changes title, excerpt and fields; nothing else is accepted. The old values go first into the agent's session.
	 *
	 * @param array<string, mixed> $input `{title?, excerpt?, fields?}`.
	 * @return array<string, mixed>|\WP_Error The content after the change.
	 */
	public static function update( int $id, array $input ): array|\WP_Error {
		if ( ! Scopes::allowed( 'contenuti' ) ) {
			return Scopes::refusal( 'contenuti' );
		}
		$unknown = array_diff( array_keys( $input ), array( 'title', 'excerpt', 'fields' ) );
		if ( array() !== $unknown ) {
			/* translators: %s: names */
			return new \WP_Error( 'xb_agent_content', sprintf( __( 'Si cambiano solo title, excerpt e fields, non: %s.', 'xb' ), implode( ', ', $unknown ) ), array( 'status' => 400 ) );
		}
		$route = self::route( $id );
		if ( is_wp_error( $route ) ) {
			return $route;
		}
		$fields = $input['fields'] ?? array();
		if ( ! is_array( $fields ) || ( array() !== $fields && array_is_list( $fields ) ) ) {
			return new \WP_Error( 'xb_agent_content', __( 'fields va scritto come oggetto: {"nome": valore}.', 'xb' ), array( 'status' => 400 ) );
		}
		$before = self::get( $id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$body     = array();
		$snapshot = array( 'meta' => array() );
		foreach ( array( 'title', 'excerpt' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				if ( ! is_string( $input[ $key ] ) ) {
					/* translators: %s: field name */
					return new \WP_Error( 'xb_agent_content', sprintf( __( '%s va scritto come testo.', 'xb' ), $key ), array( 'status' => 400 ) );
				}
				$body[ $key ]     = $input[ $key ];
				$snapshot[ $key ] = $before[ $key ];
			}
		}
		$known = (array) $before['fields'];
		foreach ( $fields as $name => $value ) {
			if ( ! array_key_exists( (string) $name, $known ) ) {
				/* translators: %s: field name */
				return new \WP_Error( 'xb_agent_content', sprintf( __( 'Questo contenuto non ha il campo «%s».', 'xb' ), (string) $name ), array( 'status' => 400 ) );
			}
			$snapshot['meta'][ (string) $name ] = $known[ $name ];
		}
		if ( array() !== $fields ) {
			$body['meta'] = $fields;
		}
		if ( array() === $body ) {
			return $before;
		}
		AgentSession::record_content( $id, $snapshot );
		$error = self::write( $route, $body );
		if ( null !== $error ) {
			return $error;
		}
		return self::get( $id );
	}

	/**
	 * Puts back what AgentSession kept (its undo): the same REST route, with the permissions of who undoes.
	 *
	 * @param array{title?: string, excerpt?: string, meta: array<string, mixed>} $snapshot
	 */
	public static function restore( int $id, array $snapshot ): ?string {
		$route = self::route( $id );
		if ( is_wp_error( $route ) ) {
			return $route->get_error_message();
		}
		$body = array_intersect_key( $snapshot, array_flip( array( 'title', 'excerpt' ) ) );
		if ( array() !== $snapshot['meta'] ) {
			$body['meta'] = $snapshot['meta'];
		}
		$error = array() === $body ? null : self::write( $route, $body );
		return null === $error ? null : $error->get_error_message();
	}

	/** @param array<string, mixed> $body */
	private static function write( string $route, array $body ): ?\WP_Error {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$response = rest_do_request( $request );
		return $response->is_error() ? $response->as_error() : null;
	}

	/** The REST route of a content the user may edit, or why there is none. */
	private static function route( int $id ): string|\WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::types(), true ) || 'trash' === $post->post_status ) {
			return new \WP_Error( 'xb_not_found', __( 'Contenuto inesistente.', 'xb' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'xb_forbidden', __( 'Non puoi modificare questo contenuto.', 'xb' ), array( 'status' => 403 ) );
		}
		$object = get_post_type_object( $post->post_type );
		$base   = $object instanceof \WP_Post_Type && is_string( $object->rest_base ) && '' !== $object->rest_base ? $object->rest_base : $post->post_type;
		$space  = $object instanceof \WP_Post_Type && is_string( $object->rest_namespace ) && '' !== $object->rest_namespace ? $object->rest_namespace : 'wp/v2';
		return '/' . $space . '/' . $base . '/' . $id;
	}

	/** @return list<string> Types with a REST route, not WordPress's internal ones nor attachments. */
	private static function types(): array {
		$out = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' === $name || in_array( $name, \XB\Render\LoopPresets::INTERNAL_TYPES, true ) ) {
				continue;
			}
			$out[] = (string) $name;
		}
		return $out;
	}

	/** @param array<string, mixed> $data */
	private static function no_store( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
