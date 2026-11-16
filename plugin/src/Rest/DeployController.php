<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Rest;

use XB\Deploy\Credentials;
use XB\Deploy\DeployException;
use XB\Deploy\Deployer;
use XB\Forms\Forms;

/**
 * xb/v1 deploy routes — docs/spec/static.md.
 * Configuration needs manage_options + xb_manage_deploy; deploying and rolling back need xb_publish, never with an
 * application password (threat model T6).
 * The API token is accepted on write and never returned.
 */
final class DeployController {

	public static function register(): void {
		$self = new self();
		register_rest_route(
			'xb/v1',
			'/deploy',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $self, 'status' ),
					'permission_callback' => static fn (): bool => current_user_can( 'xb_build' ) && current_user_can( 'xb_publish' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $self, 'deploy' ),
					'permission_callback' => array( self::class, 'can_go_online' ),
				),
			)
		);
		register_rest_route(
			'xb/v1',
			'/deploy/(?P<id>\d+)/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $self, 'rollback' ),
				'permission_callback' => array( self::class, 'can_go_online' ),
			)
		);
		register_rest_route(
			'xb/v1',
			'/deploy/config',
			array(
				'methods'             => 'POST',
				'callback'            => array( $self, 'configure' ),
				'permission_callback' => array( self::class, 'can_configure' ),
				'args'                => array(
					'account' => array(
						'type'     => 'string',
						'required' => true,
					),
					'script'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'token'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'auto'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			'xb/v1',
			'/deploy/turnstile',
			array(
				'methods'             => 'POST',
				'callback'            => array( $self, 'turnstile' ),
				'permission_callback' => array( self::class, 'can_configure' ),
				'args'                => array(
					'secret' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Deploying and rolling back put a version of the site online: xb_build and xb_publish, and never with an
	 * application password, as publishing a release (threat model T6). WP-CLI stays for large sites.
	 */
	public static function can_go_online(): bool|\WP_Error {
		return Controller::app_password_refusal( __( 'Il sito statico si mette online solo dal builder o con WP-CLI, non con una password per applicazioni.', 'xb' ) )
			?? ( current_user_can( 'xb_build' ) && current_user_can( 'xb_publish' ) );
	}

	public static function can_configure(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_manage_deploy' );
	}

	public function status(): \WP_REST_Response {
		return self::no_store(
			array(
				'config'          => Credentials::public_view(),
				'auto'            => (bool) get_option( Deployer::AUTO, false ),
				'formsConfigured' => is_string( get_option( 'xb_forms_configured' ) ),
				'history'         => self::history(),
			)
		);
	}

	/**
	 * The deploys, and for the versions that can be put back among the latest ones the contents withdrawn since they
	 * were built (doc 23, voce 171): the panel says it before the rollback.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function history(): array {
		$deployer = new Deployer();
		$out      = array();
		foreach ( $deployer->history() as $i => $row ) {
			if ( $i < 8 && 'superseded' === $row['status'] && '' !== $row['version_id'] ) {
				$row['withdrawn'] = $deployer->withdrawn_since( $row['id'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	public function deploy(): \WP_REST_Response|\WP_Error {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- large sites; WP-CLI is the alternative.
		}
		return self::run( static fn (): array => ( new Deployer() )->deploy() );
	}

	public function rollback( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::run( static fn (): array => ( new Deployer() )->rollback( (int) $request['id'] ) );
	}

	public function configure( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			Credentials::save( (string) $request['account'], (string) $request['script'], (string) $request['token'] );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_deploy_config', $e->getMessage(), array( 'status' => 400 ) );
		}
		update_option( Deployer::AUTO, (bool) $request['auto'], false );
		\XB\Plugin::instance()->versioning->store()->audit( 'deploy_config', 'cloudflare', array( 'tokenChanged' => '' !== (string) $request['token'] ) );
		return $this->status();
	}

	public function turnstile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return self::run(
			static function () use ( $request ): array {
				Forms::configure_worker( (string) $request['secret'] );
				return array( 'configured' => true );
			}
		);
	}

	/** @param callable(): array<string, mixed> $operation */
	private static function run( callable $operation ): \WP_REST_Response|\WP_Error {
		try {
			return self::no_store( $operation() );
		} catch ( DeployException $e ) {
			return new \WP_Error( 'xb_deploy', $e->getMessage(), array( 'status' => $e->status >= 400 && $e->status < 600 ? $e->status : 502 ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_deploy', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	private static function no_store( mixed $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
