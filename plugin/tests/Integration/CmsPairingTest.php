<?php
/**
 * Doc 23 item 237 of Histeon Publish: the one-click pairing's claim. The code goes once, only to whoever has the
 * verifier of the challenge the administrator confirmed; every claim is forgotten after its first try.
 *
 * @package XB
 */

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Cms\Link;
use XB\Cms\Pairing;
use XB\Deploy\Credentials;

final class CmsPairingTest extends IntegrationTestCase {

	private function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private function claim( string $claim, string $verifier ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/' . \XB\Rest\Controller::NAMESPACE . Pairing::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'claim' => $claim, 'verifier' => $verifier ) ) );
		return rest_do_request( $request );
	}

	public function test_the_code_goes_once_and_only_with_the_verifier(): void {
		$this->set_option( Link::OPTION, get_option( Link::OPTION, false ) );
		delete_transient( 'xb_cms_pair_failures' );
		wp_set_current_user( 0 );
		$verifier = $this->b64url( random_bytes( 32 ) );
		$code     = Link::CODE_PREFIX . 'eyJ1cmwiOiJodHRwOi8vbG9jYWxob3N0In0';
		$claim    = bin2hex( random_bytes( 16 ) );
		$store    = fn ( string $id ) => set_transient( 'xb_cms_pair_' . $id, array( 'code' => Credentials::encrypt_with( $code, 'xb-cms-pairing-v1' ), 'challenge' => $this->b64url( hash( 'sha256', $verifier, true ) ) ), 300 );

		// A wrong verifier: refused, and the claim is gone for the right one too.
		$store( $claim );
		$this->assertSame( 403, $this->claim( $claim, $this->b64url( random_bytes( 32 ) ) )->get_status() );
		$this->assertSame( 403, $this->claim( $claim, $verifier )->get_status() );

		// The right verifier: the code, once.
		$claim = bin2hex( random_bytes( 16 ) );
		$store( $claim );
		$answer = $this->claim( $claim, $verifier );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame( array( 'code' => $code ), $answer->get_data() );
		$this->assertSame( 'no-store', $answer->get_headers()['Cache-Control'] ?? null );
		$this->assertSame( 403, $this->claim( $claim, $verifier )->get_status() );

		// Nothing that looks like a claim: refused without reading anything.
		$this->assertSame( 403, $this->claim( '../xb_cms_code_1', $verifier )->get_status() );
		delete_transient( 'xb_cms_pair_failures' );
	}

	public function test_too_many_refused_claims_stop_the_route_for_a_while(): void {
		set_transient( 'xb_cms_pair_failures', 30, 600 );
		$this->assertSame( 429, $this->claim( bin2hex( random_bytes( 16 ) ), $this->b64url( random_bytes( 32 ) ) )->get_status() );
		delete_transient( 'xb_cms_pair_failures' );
	}
}
