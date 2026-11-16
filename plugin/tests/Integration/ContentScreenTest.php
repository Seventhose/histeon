<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\ContentScreen;

/** Histeon → Pagine/Articoli: lists, counts and search show only what the user may read. */
final class ContentScreenTest extends IntegrationTestCase {

	protected function tearDown(): void {
		unset( $_GET['page'], $_GET['s'], $_GET['all'] );
		parent::tearDown();
	}

	public function test_the_count_and_the_search_say_nothing_of_other_peoples_private_posts(): void {
		$word = 'zqprivato' . wp_generate_password( 6, false, false );
		$this->post( array( 'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Riservato ' . $word, 'post_content' => $this->markup( array( $this->text( $word ) ) ) ) );
		$author = $this->user( 'author' );
		( new \WP_User( $author ) )->add_cap( 'xb_build' );
		wp_set_current_user( $author );
		$_GET['page'] = 'xb-posts';
		$_GET['s']    = $word;
		$_GET['all']  = '1';
		ob_start();
		ContentScreen::render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Riservato ' . $word, $html, 'the search box repeats the word; no row has it' );
		$this->assertMatchesRegularExpression( '/\b0 elementi\b/', $html, 'an author learns nothing from the count' );
	}
}
