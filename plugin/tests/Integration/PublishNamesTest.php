<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\AcssLegacy;
use XB\Framework\Frameworks;
use XB\Render\LoopPresets;

/**
 * A project of Histeon Publish shown on WordPress as it was written (lista migliorie D9–D11, C8): the `page` root of
 * its templates, its data names, the `limit` and `offset` its loops pass to any query, and no sheets of an
 * Automatic.css only tried out.
 */
final class PublishNamesTest extends IntegrationTestCase {

	public function test_templates_read_the_page_root_as_props_page(): void {
		$id = $this->post( array( 'post_title' => 'Inseguendo la notte', 'post_date' => '2020-12-17 10:00:00' ) );
		$this->go_to( $id );
		$html = $this->render( array( $this->text( '[{page.title}]|[{props.page.title}]|[{page.publishedAt.date("Y")}]' ) ) );
		$this->assertSame( '[Inseguendo la notte]|[Inseguendo la notte]|[2020]', $html );
	}

	/** A header part marks the active menu item from the page it is on; a component reads `page` as in Publish. */
	public function test_parts_read_the_page_and_components_read_the_page_root(): void {
		$id = $this->post( array( 'post_title' => 'Contatti', 'post_name' => 'contatti', 'post_type' => 'page' ) );
		$part = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => 'zq-testata', 'post_title' => 'Testata', 'post_content' => $this->markup( array( $this->text( '[{props.page.slug}|{page.title}]' ) ) ) ), true );
		$this->assertIsInt( $part );
		$this->track( $part );
		wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
		update_post_meta( $part, \XB\Render\ComponentRegistry::META_KEY, 'site/zq-testata' );
		$this->component( 'zq/scheda', array( $this->text( '({page.title}|{props.page ? "prop" : "-"})' ) ) );
		\XB\Plugin::instance()->components->forget();
		$this->go_to( $id );
		$html = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'site/zq-testata' ) ), $this->block( 'xb/component', array( 'ref' => 'zq/scheda' ) ) ) );
		$this->assertSame( '[contatti|Contatti](Contatti|-)', $html );
	}

	/** On a list the slot of a template is empty, as in Publish: never the text of the first item listed. */
	public function test_the_slot_of_a_list_template_stays_empty(): void {
		$cat = wp_insert_term( 'Elenco zq', 'category' );
		$this->assertIsArray( $cat );
		$id = $this->post( array( 'post_title' => 'Primo', 'post_content' => '<p>Testo del primo zq</p>', 'post_category' => array( (int) $cat['term_id'] ) ) );
		$this->go_to( $id );
		$this->assertStringContainsString( 'Testo del primo zq', $this->render( array( $this->block( 'xb/post-content', array() ) ) ) );
		$GLOBALS['wp_query'] = new \WP_Query( array( 'cat' => (int) $cat['term_id'] ) );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['post']         = get_post( $id );
		$this->assertTrue( is_archive() );
		$html = $this->render( array( $this->text( '[' ), $this->block( 'xb/post-content', array() ), $this->text( ']' ) ) );
		wp_delete_term( (int) $cat['term_id'], 'category' );
		$this->assertSame( '[]', $html );
	}

	public function test_publish_names_of_dates_and_terms(): void {
		$cat = wp_insert_term( 'Edizione 2020 zq', 'category' );
		$this->assertIsArray( $cat );
		$id = $this->post( array( 'post_title' => 'Con categoria', 'post_date' => '2020-11-26 09:00:00', 'post_category' => array( (int) $cat['term_id'] ) ) );
		$this->go_to( $id );
		$html = $this->render(
			array(
				$this->text( '{this.publishedAt.date("j")}/{this.updatedAt ? "m" : ""}/{page.date.date("Y")}|' ),
				$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'page.terms.category' ), 'as' => 'c' ), array( $this->text( '[{c.label}={c.name}]' ) ) ),
			)
		);
		wp_delete_term( (int) $cat['term_id'], 'category' );
		$this->assertSame( '26/m/2020|[Edizione 2020 zq=Edizione 2020 zq]', $html );
	}

	public function test_a_loop_limit_and_offset_reach_a_preset_with_fixed_numbers(): void {
		$ids = array();
		foreach ( array( 'Uno', 'Due', 'Tre', 'Quattro', 'Cinque' ) as $n => $title ) {
			$ids[] = $this->post( array( 'post_title' => 'zq ' . $title, 'post_date' => sprintf( '2001-01-0%d 10:00:00', 5 - $n ) ) );
		}
		$this->set_option(
			LoopPresets::OPTION,
			array(
				'zq_fissa'   => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => 6, 'offset' => 0, 'orderby' => 'date', 'order' => 'DESC', 'post__in' => $ids ) ),
				'zq_propria' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => '$quanti ?? 3', 'orderby' => 'date', 'order' => 'DESC', 'post__in' => $ids ) ),
			)
		);
		$presets = new LoopPresets();
		$titles  = static fn ( array $items ): string => implode( ',', array_map( static fn ( mixed $p ): string => str_replace( 'zq ', '', (string) $p->get( 'title' ) ), $items ) );
		$this->assertSame( 'Uno,Due,Tre,Quattro,Cinque', $titles( $presets->run( 'zq_fissa', array() ) ) );
		$this->assertSame( 'Uno,Due', $titles( $presets->run( 'zq_fissa', array( 'limit' => 2 ) ) ) );
		$this->assertSame( 'Tre,Quattro', $titles( $presets->run( 'zq_fissa', array( 'limit' => '2', 'offset' => '2' ) ) ) );
		$this->assertSame( 'Uno,Due,Tre', $titles( $presets->run( 'zq_propria', array() ) ), 'its own default' );
		$this->assertSame( 'Uno', $titles( $presets->run( 'zq_propria', array( 'limit' => 1 ) ) ), '`limit` reaches it, as in Publish' );
		$this->assertSame( 'Uno,Due', $titles( $presets->run( 'zq_propria', array( 'quanti' => 2, 'limit' => 1 ) ) ), 'its own parameter wins when passed' );
	}

	public function test_imported_queries_read_limit_and_offset_and_export_back_unchanged(): void {
		$to   = new \ReflectionMethod( \XB\Remote\ContentProfileAdapter::class, 'query_to_wordpress' );
		$from = new \ReflectionMethod( \XB\Remote\ContentProfileAdapter::class, 'query_from_wordpress' );
		$payload = array( 'key' => 'articoli_recenti', 'label' => 'Ultimi articoli', 'typeKey' => 'post', 'limit' => 6, 'orderBy' => 'publishedAt', 'order' => 'desc' );
		$preset  = $to->invoke( null, $payload );
		$this->assertSame( '$limit ?? 6', $preset['args']['posts_per_page'] );
		$this->assertSame( '$offset ?? 0', $preset['args']['offset'] );
		$back = $from->invoke( null, 'articoli_recenti', $preset['args'] );
		$this->assertSame( 6, $back['limit'] );
		$this->assertSame( 0, $back['offset'] );
		$this->assertArrayNotHasKey( 'limitParam', $back, '`limit` is a base argument, not a parameter of the query' );
		$this->assertArrayNotHasKey( 'offsetParam', $back );
		$named = $to->invoke( null, $payload + array( 'limitParam' => 'quanti', 'offset' => 4 ) );
		$this->assertSame( '$quanti ?? 6', $named['args']['posts_per_page'] );
		$this->assertSame( '$offset ?? 4', $named['args']['offset'] );
		$this->assertSame( 'quanti', $from->invoke( null, 'articoli_recenti', $named['args'] )['limitParam'] );
	}

	public function test_sheets_left_by_trying_automatic_css_are_not_served_to_a_site_on_another_framework(): void {
		if ( class_exists( '\\Automatic_CSS\\API' ) ) {
			$this->markTestSkipped( 'Automatic.css è attivo nel WordPress di sviluppo.' );
		}
		$dir     = wp_upload_dir()['basedir'] . '/automatic-css';
		$existed = is_dir( $dir );
		$file    = $dir . '/automatic.css';
		$had     = is_file( $file );
		wp_mkdir_p( $dir );
		if ( ! $had ) {
			file_put_contents( $file, 'h1{color:#000}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture in uploads.
		}
		$before = get_option( Frameworks::OPTION );
		try {
			foreach ( array( 'xb-tokens' => false, 'core-framework' => false, 'acss' => true, 'none' => true ) as $chosen => $served ) {
				update_option( Frameworks::OPTION, $chosen );
				$this->assertSame( $served, AcssLegacy::needed(), 'framework chosen: ' . $chosen );
			}
			delete_option( Frameworks::OPTION );
			$this->assertTrue( AcssLegacy::needed(), 'nothing chosen yet: the site may still rely on Automatic.css' );
		} finally {
			false === $before ? delete_option( Frameworks::OPTION ) : update_option( Frameworks::OPTION, $before );
			if ( ! $had ) {
				wp_delete_file( $file );
			}
			if ( ! $existed ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
			}
		}
	}
}
