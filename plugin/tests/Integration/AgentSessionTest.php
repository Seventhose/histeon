<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Ai\AgentSession;
use XB\Plugin;
use XB\Versioning\Documents;
use XB\Versioning\Store;

/** Lista della spesa 158: a restore point before the AI agent, then «Tieni» or «Annulla tutto». */
final class AgentSessionTest extends IntegrationTestCase {

	private function agent_writes( int $id, string $text ): void {
		$versioning         = Plugin::instance()->versioning;
		$doc                = Documents::for_post( $id );
		$copy               = $versioning->working_copy( $doc );
		$payload            = $copy['payload'];
		$payload['content'] = $this->markup( array( $this->el( 'p', array(), array( $this->text( $text ) ) ) ) );
		$versioning->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], 'agente', true, agent: true );
	}

	public function test_undo_brings_every_document_back_to_before_the_agent(): void {
		$this->set_option( AgentSession::OPTION, array() );
		$store = Plugin::instance()->versioning->store();
		$a     = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Originale A' ) ) ) ) ) ) );
		$b     = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Originale B' ) ) ) ) ) ) );
		$before_a = (string) Plugin::instance()->versioning->working_copy( Documents::for_post( $a ) )['head'];
		$this->agent_writes( $a, 'Agente 1' );
		$this->agent_writes( $a, 'Agente 2' );
		$this->agent_writes( $b, 'Agente B' );

		$sessions = $this->request( 'GET', '/agent-sessions' )->get_data();
		$this->assertCount( 1, $sessions );
		$this->assertSame( 3, $sessions[0]['writes'] );
		$this->assertSame( array( Documents::for_post( $a ), Documents::for_post( $b ) ), array_column( $sessions[0]['docs'], 'doc' ) );
		$this->assertSame( $before_a, AgentSession::all()[1]['docs'][ Documents::for_post( $a ) ], 'the restore point is the head before the first write' );

		$result = $this->request( 'POST', '/agent-sessions/1', array( 'action' => 'undo' ) )->get_data();
		$this->assertCount( 2, $result['restored'] );
		foreach ( array( $a => 'Originale A', $b => 'Originale B' ) as $id => $text ) {
			$content = (string) Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) )['payload']['content'];
			$this->assertStringContainsString( $text, $content );
			$this->assertStringNotContainsString( 'Agente', $content );
		}
		$this->assertNotSame( $before_a, $store->head( Documents::for_post( $a ), Store::DEV ), 'a new commit: the agent\'s versions stay in the history' );
		$this->assertSame( array(), $this->request( 'GET', '/agent-sessions' )->get_data() );
	}

	public function test_keep_ends_the_session_and_others_cannot_touch_it(): void {
		$this->set_option( AgentSession::OPTION, array() );
		$id = $this->post( array( 'post_type' => 'page', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'X' ) ) ) ) ) ) );
		$this->agent_writes( $id, 'Agente' );
		$builder = $this->user( 'editor' );
		( new \WP_User( $builder ) )->add_cap( 'xb_build' );
		wp_set_current_user( $builder );
		$this->assertSame( array(), $this->request( 'GET', '/agent-sessions' )->get_data(), 'someone else\'s session is not shown' );
		$this->assertSame( 403, $this->request( 'POST', '/agent-sessions/1', array( 'action' => 'undo' ) )->get_status() );
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->request( 'POST', '/agent-sessions/1', array( 'action' => 'keep' ) )->get_status() );
		$this->assertSame( array(), AgentSession::all() );
		$this->assertStringContainsString( 'Agente', (string) Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) )['payload']['content'] );
	}
}
