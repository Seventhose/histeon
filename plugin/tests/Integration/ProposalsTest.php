<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Preview;
use XB\Versioning\Proposals;

/** Lista della spesa 160: proposals A and B, each with its signed preview; the chosen one goes back to development. */
final class ProposalsTest extends IntegrationTestCase {

	private function save( int $id, string $text ): void {
		$versioning         = Plugin::instance()->versioning;
		$doc                = Documents::for_post( $id );
		$copy               = $versioning->working_copy( $doc );
		$payload            = $copy['payload'];
		$payload['content'] = $this->markup( array( $this->el( 'p', array(), array( $this->text( $text ) ) ) ) );
		$versioning->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], $text );
	}

	/** The content a visitor of the signed link sees for the page. */
	private function previewed( string $url, int $id ): string {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_GET                   = $query;
		$_SERVER['REQUEST_URI'] = (string) wp_parse_url( $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY );
		Preview::detect();
		$stored  = get_post_field( 'post_content', $id );
		$content = Preview::content( is_string( $stored ) ? $stored : '', $id );
		Preview::reset();
		$_GET = array();
		return $content;
	}

	public function test_two_proposals_each_with_its_preview_and_the_chosen_one_in_development(): void {
		$this->set_option( Proposals::OPTION, array() );
		$id = $this->post( array( 'post_type' => 'page', 'post_title' => 'Proposte', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Online' ) ) ) ) ) ) );
		$this->save( $id, 'Versione A' );
		$a = Proposals::create( 'Proposta A', $id );
		$this->save( $id, 'Versione B' );
		$b = Proposals::create( 'Proposta B', $id );

		$this->assertStringContainsString( 'Versione A', $this->previewed( Proposals::url( $a ), $id ) );
		$this->assertStringContainsString( 'Versione B', $this->previewed( Proposals::url( $b ), $id ) );
		// The proposal is inside the signature: another id in the same token opens nothing.
		$forged = str_replace( '.' . $a['id'] . '.', '.' . $b['id'] . '.', Proposals::url( $a ) );
		$this->assertStringContainsString( 'Online', $this->previewed( $forged, $id ) );
		$this->assertStringContainsString( Preview::COMPARE . '=', Proposals::compare_url( $a, $b ) );

		$result = Proposals::choose( $a['id'] );
		$this->assertSame( array( Documents::for_post( $id ) ), $result['restored'] );
		$this->assertStringContainsString( 'Versione A', (string) Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) )['payload']['content'] );
		Proposals::delete( $b['id'] );
		$this->assertStringContainsString( 'Online', $this->previewed( Proposals::url( $b ), $id ), 'a deleted proposal shows the live site' );
	}

	public function test_nothing_in_development_is_no_proposal(): void {
		$this->set_option( Proposals::OPTION, array() );
		$this->expectException( \XB\Versioning\VersioningException::class );
		$id = $this->post( array( 'post_type' => 'page' ) );
		Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) );
		foreach ( Plugin::instance()->versioning->changes() as $change ) {
			unset( $change );
			$this->markTestSkipped( 'the development site has other changes waiting' );
		}
		Proposals::create( 'Vuota', $id );
	}
}
