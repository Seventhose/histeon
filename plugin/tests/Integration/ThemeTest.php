<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** End to end: a page served by the dev site with Fondamenta and the plugin active; Fondamenta without the plugin. */
final class ThemeTest extends IntegrationTestCase {

	public function test_singular_template_with_styles_in_head(): void {
		$this->set_option(
			'xb_styles',
			array(
				array(
					'id'       => 'h',
					'selector' => '.site-header',
					'css'      => 'display:flex',
				),
			)
		);
		$id   = $this->post(
			array(
				'post_title'   => 'Pagina XB',
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'p', array( 'class' => 'lead' ), array( $this->text( 'Contenuto di {this.title}' ) ) ) ) ),
			)
		);
		// Request the canonical URL through the compose network: the site believes it lives at home_url().
		$response = wp_remote_get(
			str_replace( home_url(), 'http://wordpress', (string) get_permalink( $id ) ),
			array(
				'headers'     => array( 'Host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . (string) wp_parse_url( home_url(), PHP_URL_PORT ) ),
				'redirection' => 0,
			)
		);
		$this->assertIsArray( $response );
		$html = wp_remote_retrieve_body( $response );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ), substr( $html, 0, 500 ) );
		$this->assertMatchesRegularExpression( '#<head>.*<style id="xb-styles">\s*\.site-header\{display:flex;\}.*</head>#s', $html );
		$this->assertStringContainsString( '<header class="site-header">', $html );
		$this->assertStringContainsString( '<p class="lead">Contenuto di Pagina XB</p>', $html );
		$this->assertStringContainsString( '© ' . gmdate( 'Y' ) . ' XB Dev', $html );
	}

	public function test_fondamenta_templates_have_the_post_content_and_xb_blank_the_same_files(): void {
		$root = get_theme_root();
		foreach ( array( 'templates/index.html', 'templates/404.html', 'templates/page.html', 'templates/singular.html', 'parts/header.html', 'parts/footer.html' ) as $file ) {
			$own = (string) file_get_contents( $root . '/fondamenta/' . $file );
			$this->assertSame( $own, (string) file_get_contents( $root . '/xb-blank/' . $file ), $file );
			if ( str_starts_with( $file, 'templates/' ) ) {
				$this->assertStringContainsString( '<!-- wp:xb/post-content ', $own, $file );
			}
		}
		$this->assertSame( 'Fondamenta', wp_get_theme( 'fondamenta' )->get( 'Name' ) );
		$this->assertStringNotContainsString( 'Histeon', (string) file_get_contents( $root . '/fondamenta/style.css' ) . (string) file_get_contents( $root . '/fondamenta/functions.php' ) );
	}

	public function test_fondamenta_without_the_plugin_says_what_is_missing(): void {
		$this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Ciao' ) ) ) ) ),
			)
		);
		delete_transient( 'fondamenta_fermo' );
		$code    = 'echo defined( "XB_VERSION" ) ? "attivo" : fondamenta_stopped_notice();';
		$process = proc_open( array( 'wp', '--path=' . ABSPATH, '--skip-plugins', 'eval', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		proc_close( $process );
		delete_transient( 'fondamenta_fermo' );
		$this->assertStringContainsString( 'Fondamenta è ferma', $out, $err );
		$this->assertMatchesRegularExpression( '/\d+ contenut[oi] (è fatto|sono fatti) con i suoi blocchi/', $out );
		$this->assertStringContainsString( 'riattivalo', $out );
	}
}
