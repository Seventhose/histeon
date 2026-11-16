<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

use XB\Plugin;
use XB\Rest\Controller;

/**
 * What an AI agent may change on this site, by area (doc 26 voce 215, docs/spec/connector.md §4). One choice for
 * the site, made by an administrator in the builder's ✦ panel: the connector in a builder tab and the Abilities
 * of WordPress both follow it. Reading is always allowed, and so are undo and saving in development; publishing
 * never is. The keys are those of `SCOPES` in editor/src/lib/connector.svelte.ts.
 */
final class Scopes {

	public const OPTION = 'xb_agent_scopes';

	/** Every area, in the order the panel shows them. */
	public const ALL = array( 'testi', 'struttura', 'stile', 'componenti', 'media', 'contenuti', 'dati' );

	/**
	 * Allowed until an administrator says otherwise: what the agent could always do, plus media and loop queries
	 * (both with versions or a way back). Contents and their fields change the live site at once: off.
	 */
	public const DEFAULT = array( 'testi', 'struttura', 'stile', 'componenti', 'media', 'dati' );

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Controller::NAMESPACE,
			'/agent-scopes',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn (): \WP_REST_Response => new \WP_REST_Response( self::describe() ),
					'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
					'args'                => array(
						'scopes' => array(
							'type'     => 'array',
							'items'    => array(
								'type' => 'string',
								'enum' => self::ALL,
							),
							'required' => true,
						),
					),
				),
			)
		);
	}

	/** @return array{enabled: list<string>, canChange: bool} */
	public static function describe(): array {
		return array(
			'enabled'   => self::enabled(),
			'canChange' => current_user_can( 'manage_options' ),
		);
	}

	/** @return list<string> */
	public static function enabled(): array {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return self::DEFAULT;
		}
		return array_values( array_intersect( self::ALL, array_map( 'strval', $stored ) ) );
	}

	public static function allowed( string $scope ): bool {
		return in_array( $scope, self::enabled(), true );
	}

	/** @param \WP_REST_Request<array<string, mixed>> $request */
	public static function save( \WP_REST_Request $request ): \WP_REST_Response {
		$wanted = array_values( array_intersect( self::ALL, array_map( 'strval', (array) $request->get_param( 'scopes' ) ) ) );
		update_option( self::OPTION, $wanted, false );
		Plugin::instance()->versioning->store()->audit( 'agent-scopes', 'site', array( 'scopes' => $wanted ) );
		return new \WP_REST_Response( self::describe() );
	}

	/** The refusal an agent reads: which area is off and where it is turned on. */
	public static function refusal( string $scope ): \WP_Error {
		return new \WP_Error(
			'xb_agent_scope',
			/* translators: %s: area key, e.g. contenuti */
			sprintf( __( 'Su questo sito l’agente non può cambiare «%s»: lo decide l’amministratore nel pannello ✦ del builder, «Cosa può fare l’agente».', 'xb' ), $scope ),
			array( 'status' => 403 )
		);
	}
}
