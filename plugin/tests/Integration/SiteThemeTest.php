<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Export\Core\Prova;
use XB\Export\Core\SiteTheme;

/** The site's own theme, a child of Fondamenta (docs/spec/export-core.md §13). */
final class SiteThemeTest extends IntegrationTestCase {

	public function test_the_theme_carries_sheet_templates_and_parts_converted(): void {
		if ( ! Prova::ready() ) {
			$this->markTestSkipped( 'Serve Fondamenta 0.2 attiva nel WordPress di sviluppo.' );
		}
		$this->set_option(
			\XB\Styles\StyleRegistry::OPTION_STYLES,
			array(
				array(
					'id'       => 'st1',
					'selector' => '.tema-prova',
					'css'      => 'color:#123456',
				),
			)
		);
		$id = $this->post(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'single-post',
				'post_title'   => 'Articolo',
				'post_content' => $this->markup( array( $this->el( 'main', array( 'class' => 'tema-prova' ), array( $this->el( 'h1', array(), array( $this->text( '{this.title}' ) ) ), $this->block( 'xb/post-content', array() ) ) ) ) ),
			)
		);
		wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
		$this->set_option( \XB\Render\Facets::OPTION, \XB\Render\Facets::validate( array( 'notizie' => array( 'query' => 'posts', 'type' => 'post', 'perPage' => 5, 'filters' => array( array( 'kind' => 'search', 'key' => 'q', 'label' => 'Cerca' ) ) ) ) ) );
		$this->set_option(
			\XB\Fonts\LocalFonts::OPTION,
			array(
				array(
					'family' => 'Prova Sans',
					'weight' => '400',
					'style'  => 'normal',
					'file'   => 'prova-sans/prova-sans-400.woff2',
					'format' => 'woff2',
					'range'  => '',
				),
			)
		);

		$page = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Con uno script' ) ) ) ) ),
			)
		);
		$this->assertTrue( \XB\Scripts\Scripts::set( $page, 'console.log( "pagina" );' ) );
		$path  = get_temp_dir() . 'tema-prova-' . wp_generate_password( 6, false ) . '.zip';
		$theme = ( new SiteTheme() )->zip( $path );
		$this->assertFileExists( $path );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$this->assertNotFalse( $zip->locateName( $theme['slug'] . '/style.css' ) );
		$zip->close();
		unlink( $path );

		$files = $theme['files'];
		$this->assertStringEndsWith( '-tema', $theme['slug'] );
		$this->assertStringContainsString( "\nTemplate: fondamenta\n", $files['style.css'] );
		$this->assertStringContainsString( '.tema-prova{color:#123456;}', $files['assets/sito.css'] );
		$this->assertStringContainsString( "add_editor_style( 'assets/sito.css' )", $files['functions.php'] );
		$json = json_decode( $files['theme.json'], true );
		$this->assertIsArray( $json );
		$this->assertSame( 3, $json['version'] );
		$this->assertContains( 'header', array_column( $json['templateParts'], 'name' ) );
		// The fonts in the editor's menus, the local ones too; the presets without the builder's name.
		$fonts = array_column( $json['settings']['typography']['fontFamilies'], 'fontFamily', 'slug' );
		$this->assertSame( '"Prova Sans"', $fonts['prova-sans'] );
		if ( 'xb-tokens' === \XB\Plugin::instance()->frameworks->active()->id() ) {
			$this->assertSame( \XB\Framework\HistosCss\SiteDesign::current()['font_body'], $fonts['testo'] );
			$this->assertNotEmpty( $json['settings']['color']['palette'] );
		}
		$this->assertStringNotContainsString( 'Histeon', $files['theme.json'] );
		$this->assertStringContainsString( '@font-face{font-family:"Prova Sans"', $files['assets/sito.css'] );
		// Templates and parts in Fondamenta's blocks, with no Histeon block left; Fondamenta's own files too.
		foreach ( array( 'templates/single-post.html', 'templates/index.html', 'templates/404.html', 'parts/header.html', 'parts/footer.html' ) as $file ) {
			$this->assertArrayHasKey( $file, $files );
			$this->assertStringNotContainsString( '<!-- wp:xb/', $files[ $file ], $file );
		}
		$this->assertStringContainsString( '<!-- wp:fondamenta/elemento {"tagName":"main"', $files['templates/single-post.html'] );
		$this->assertStringContainsString( '"legami":{"#testo":[{"chiave":"title"}]}', $files['templates/single-post.html'] );
		$this->assertStringContainsString( '<!-- wp:fondamenta/contenuto /-->', $files['templates/single-post.html'] );
		$this->assertStringNotContainsString( 'Histeon', $files['style.css'] . $files['functions.php'] . $files['README.md'] );
		// The page's script, printed on that page only; the generated PHP parses.
		$this->assertSame( "console.log( \"pagina\" );\n", $files[ 'assets/js/documento-' . $page . '.js' ] );
		$this->assertStringContainsString( "'pagine' => \n", $files['functions.php'] );
		$this->assertNotEmpty( token_get_all( $files['functions.php'], TOKEN_PARSE ) );
		// The filtered lists, as data for Fondamenta's filters.
		$facets = json_decode( $files['facets.json'], true );
		$this->assertSame( 'post', $facets['notizie']['base']['post_type'] );
		$this->assertSame( 5, $facets['notizie']['perPage'] );
		$this->assertStringContainsString( "'fondamenta_facets'", $files['functions.php'] );
	}
}
