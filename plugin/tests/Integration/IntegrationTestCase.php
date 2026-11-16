<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use PHPUnit\Framework\TestCase;
use XB\Plugin;
use XB\Versioning\Preview;
use XB\Versioning\Schema;

/**
 * Base class: builds XB block trees in PHP, creates posts and users, and cleans up after each test.
 */
abstract class IntegrationTestCase extends TestCase {

	/** @var list<int> */
	private array $posts = array();
	/** @var list<int> */
	private array $users = array();
	/** @var array<string, mixed> */
	private array $options = array();
	private int $uid = 0;
	/** @var array{refs: array<mixed>, commits: array<string, true>, release: int, audit: int, current: mixed} */
	private array $versioning = array(
		'refs'    => array(),
		'commits' => array(),
		'release' => 0,
		'audit'   => 0,
		'current' => false,
	);

	/** What a release writes when documents go live (Documents::go_live): restored after each test. */
	private const LIVE_OPTIONS = array(
		\XB\Styles\StyleRegistry::OPTION_STYLES,
		\XB\Styles\StyleRegistry::OPTION_GLOBAL,
		\XB\Content\ContentTypes::OPTION,
		\XB\Scripts\SiteScripts::OPTION,
		\XB\Fields\Fields::OPTION,
		\XB\Framework\HistosCss\Design::OPTION,
		\XB\Framework\Reset::OPTION,
		\XB\Framework\TokenOverrides::OPTION,
		\XB\Render\Facets::OPTION,
		\XB\Render\LoopPresets::OPTION,
		\XB\Render\Sources::OPTION,
		\XB\Styles\NamedQueries::OPTION,
		\XB\Framework\Frameworks::OPTION,
		\XB\Framework\Carry::COMPAT,
		'xb_cms_pending_contents',
	);

	/** @var array<string, mixed> */
	private array $live = array();

	protected function setUp(): void {
		global $wpdb;
		foreach ( self::LIVE_OPTIONS as $option ) {
			$this->live[ $option ] = get_option( $option );
		}
		wp_set_current_user( 1 );
		Plugin::instance()->styles->reset();
		Plugin::instance()->components->forget();
		Plugin::instance()->renderer->reset_budget();
		Preview::reset();
		// Snapshot of the versioning tables, restored at tear down so tests never alter the dev site's history.
		$this->versioning = array(
			'refs'    => (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'refs' ), ARRAY_A ),
			'commits' => array_fill_keys( array_map( 'strval', $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'commits' ) ) ), true ),
			'release' => (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Schema::table( 'releases' ) ),
			'audit'   => (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Schema::table( 'audit' ) ),
			'current' => get_option( 'xb_current_release' ),
		);
	}

	protected function tearDown(): void {
		wp_set_current_user( 1 );
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		unset( $GLOBALS['post'] );
		foreach ( $this->posts as $id ) {
			// Test cleanup must bypass Histeon' production guard against deleting linked content.
			delete_post_meta( $id, '_xb_page_profile_id' );
			delete_post_meta( $id, '_xb_page_profile_tombstone' );
			wp_delete_post( $id, true );
		}
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		foreach ( $this->options as $name => $value ) {
			false === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		$this->posts   = array();
		$this->users   = array();
		$this->options = array();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'refs' ) );
		foreach ( $this->versioning['refs'] as $row ) {
			if ( is_array( $row ) ) {
				$wpdb->insert( Schema::table( 'refs' ), $row );
			}
		}
		foreach ( $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'commits' ) ) as $id ) {
			if ( ! isset( $this->versioning['commits'][ (string) $id ] ) ) {
				$wpdb->delete( Schema::table( 'commits' ), array( 'id' => $id ) );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'release_items' ) . ' WHERE release_id > %d', $this->versioning['release'] ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'releases' ) . ' WHERE id > %d', $this->versioning['release'] ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'audit' ) . ' WHERE id > %d', $this->versioning['audit'] ) );
		false === $this->versioning['current'] ? delete_option( 'xb_current_release' ) : update_option( 'xb_current_release', $this->versioning['current'] );
		foreach ( $this->live as $option => $value ) {
			false === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		$this->live = array();
		Preview::reset();
	}

	// ------------------------------------------------------------------ REST

	/** @param array<string, mixed> $body */
	protected function request( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/xb/v1' . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * Saves a document through the builder API (a dev commit), using the heads it reports.
	 *
	 * @param array<string, mixed> $extra
	 */
	protected function save_dev( int $post_id, string $content, array $extra = array() ): \WP_REST_Response {
		$doc = $this->request( 'GET', "/documents/$post_id" )->get_data();
		return $this->request(
			'POST',
			"/documents/$post_id",
			array_merge(
				array(
					'content' => $content,
					'head'    => is_array( $doc ) ? $doc['head'] : '',
					'base'    => is_array( $doc ) ? $doc['base'] : '',
				),
				$extra
			)
		);
	}

	/** @param list<string>|null $docs */
	protected function publish( ?array $docs = null, string $notes = 'test' ): \WP_REST_Response {
		$body = array( 'notes' => $notes );
		if ( null !== $docs ) {
			$body['docs'] = $docs;
		}
		return $this->request( 'POST', '/releases', $body );
	}

	// ------------------------------------------------------------------ block builders

	protected function uid(): string {
		return sprintf( 'it%06d', ++$this->uid );
	}

	/**
	 * @param array<string, string|true> $attributes
	 * @param list<array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	protected function el( string $tag, array $attributes = array(), array $children = array(), ?string $uid = null ): array {
		$attrs = array(
			'uid' => $uid ?? $this->uid(),
			'tag' => $tag,
		);
		if ( array() !== $attributes ) {
			$attrs['attributes'] = $attributes;
		}
		return $this->block( 'xb/element', $attrs, $children );
	}

	/** @return array<string, mixed> */
	protected function text( string $content, ?string $uid = null ): array {
		return $this->block(
			'xb/text',
			array(
				'uid'     => $uid ?? $this->uid(),
				'content' => $content,
			)
		);
	}

	/**
	 * @param array<string, mixed>       $attrs
	 * @param list<array<string, mixed>> $children
	 * @return array<string, mixed>
	 */
	protected function block( string $name, array $attrs, array $children = array() ): array {
		$attrs['uid'] ??= $this->uid();
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $children ), null ),
		);
	}

	/** @param array<array<string, mixed>> $blocks */
	protected function markup( array $blocks ): string {
		return serialize_blocks( self::normalize( $blocks ) );
	}

	/** @param array<array<string, mixed>> $blocks */
	protected function render( array $blocks ): string {
		return do_blocks( $this->markup( $blocks ) );
	}

	protected function content( int $post_id ): string {
		$content = get_post_field( 'post_content', $post_id, 'raw' );
		return is_string( $content ) ? $content : '';
	}

	/**
	 * The exact block shape serialize_blocks() expects.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return list<array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<array<string, mixed>>, innerHTML: string, innerContent: array<string|null>}>
	 */
	private static function normalize( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$inner = is_array( $block['innerBlocks'] ?? null ) ? self::normalize( $block['innerBlocks'] ) : array();
			$out[] = array(
				'blockName'    => is_string( $block['blockName'] ?? null ) ? $block['blockName'] : null,
				'attrs'        => is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
				'innerBlocks'  => $inner,
				'innerHTML'    => is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '',
				'innerContent' => is_array( $block['innerContent'] ?? null )
					? array_values( array_filter( $block['innerContent'], static fn ( mixed $c ): bool => null === $c || is_string( $c ) ) )
					: array_fill( 0, count( $inner ), null ),
			);
		}
		return $out;
	}

	// ------------------------------------------------------------------ fixtures

	/**
	 * Creates a post. `post_content` is given unslashed: wp_insert_post() expects slashed data, and block
	 * attributes serialized by serialize_blocks() contain backslash escapes (\u002d…) that would otherwise be lost.
	 *
	 * @param array<string, mixed> $args
	 */
	protected function post( array $args = array() ): int {
		if ( isset( $args['post_content'] ) && is_string( $args['post_content'] ) ) {
			$args['post_content'] = wp_slash( $args['post_content'] );
		}
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'  => 'Post di test',
					'post_status' => 'publish',
					'post_type'   => 'post',
				),
				$args
			),
			true
		);
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		return $id;
	}

	/** Deletes a post created outside post() at tear down. */
	protected function track( int $post_id ): void {
		$this->posts[] = $post_id;
	}

	/** Sets up the main query as if visiting the post. */
	protected function go_to( int $post_id ): void {
		$GLOBALS['wp_query'] = new \WP_Query(
			array(
				'p'         => $post_id,
				'post_type' => 'any',
			)
		);
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['post']         = get_post( $post_id );
	}

	protected function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'xb_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => $role,
			)
		);
		$this->assertIsInt( $id );
		$this->users[] = $id;
		return $id;
	}

	/** For tests that switch to Automatic.css: its plugin must be active, its sheets alone are not enough. */
	protected function needs_active_acss(): void {
		if ( ! class_exists( '\\Automatic_CSS\\API' ) ) {
			$this->markTestSkipped( 'Serve Automatic.css attivo nel WordPress di sviluppo (qui ci sono solo i suoi fogli).' );
		}
	}

	protected function set_option( string $name, mixed $value ): void {
		if ( ! array_key_exists( $name, $this->options ) ) {
			$this->options[ $name ] = get_option( $name );
		}
		update_option( $name, $value );
	}

	/**
	 * Registers a component definition.
	 *
	 * @param list<array<string, mixed>>           $blocks
	 * @param array<string, array<string, mixed>> $props
	 */
	protected function component( string $ref, array $blocks, array $props = array() ): int {
		$id = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => $ref,
				'post_content' => $this->markup( $blocks ),
			)
		);
		update_post_meta( $id, 'xb_component_key', $ref );
		update_post_meta( $id, 'xb_component_schema', wp_slash( (string) wp_json_encode( array( 'props' => $props ) ) ) );
		Plugin::instance()->components->forget();
		return $id;
	}
}
