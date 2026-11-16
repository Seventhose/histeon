<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Export\Etch\Exporter;
use XB\Export\Etch\Tokens;
use XB\Import\Etch\Migration;
use XB\Render\LoopPresets;
use XB\Scripts\Scripts;

/**
 * Export of a Histeon project to Etch's data (docs/spec/export-etch.md). The Etch-shaped fixtures are written for these
 * tests from the format in docs/spec/import-etch.md, like ImportEtchTest and the C4 test in Publish: no third-party
 * data or code, and Etch is never installed.
 */
final class ExportEtchTest extends IntegrationTestCase {

	private const OPTIONS = array( 'etch_loops', 'etch_global_stylesheets', 'etch_styles', Exporter::BACKUP, LoopPresets::TYPES, \XB\Styles\PageStyles::SAFELIST, Migration::OPTION );

	protected function setUp(): void {
		parent::setUp();
		foreach ( self::OPTIONS as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
			delete_option( $option );
		}
	}

	/** @param array<string, mixed> $attrs */
	private static function etch( string $name, array $attrs, string $inner = '' ): string {
		$json = (string) wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return '' === $inner ? "<!-- wp:etch/$name $json /-->" : "<!-- wp:etch/$name $json -->$inner<!-- /wp:etch/$name -->";
	}

	/**
	 * A block tree compared by meaning: attribute order, an empty attribute map written as [] or left out, the id of
	 * an element script and whitespace between blocks do not count.
	 *
	 * @param array<array<string, mixed>> $blocks
	 * @return list<mixed>
	 */
	private static function normal( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				if ( '' !== trim( (string) $block['innerHTML'] ) ) {
					$out[] = array( 'html' => trim( (string) $block['innerHTML'] ) );
				}
				continue;
			}
			$attrs = $block['attrs'];
			if ( str_starts_with( (string) $block['blockName'], 'etch/' ) && in_array( $block['blockName'], array( 'etch/element', 'etch/component' ), true ) ) {
				$attrs['attributes'] = is_array( $attrs['attributes'] ?? null ) ? $attrs['attributes'] : array();
			}
			if ( isset( $attrs['script']['code'] ) ) {
				$attrs['script'] = trim( (string) base64_decode( (string) $attrs['script']['code'], true ) );
			}
			ksort( $attrs );
			$out[] = array(
				'name'  => $block['blockName'],
				'attrs' => $attrs,
				'html'  => array_values( array_filter( array_map( static fn ( mixed $c ): string => is_string( $c ) ? trim( $c ) : '#', (array) $block['innerContent'] ) ) ),
				'inner' => self::normal( $block['innerBlocks'] ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $bundle
	 * @return array<string, mixed>
	 */
	private static function by_id( array $bundle, int $id ): array {
		foreach ( $bundle['posts'] as $post ) {
			if ( $post['id'] === $id ) {
				return $post;
			}
		}
		self::fail( "post $id not exported" );
	}

	public function test_round_trip_etch_to_histos_and_back(): void {
		$loops       = array(
			'k1' => array( 'name' => 'Notizie', 'key' => 'news', 'global' => true, 'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post', 'posts_per_page' => '$count ?? -1', 'orderby' => 'title', 'order' => 'ASC', 'post_status' => 'publish' ) ) ),
			'k2' => array( 'name' => 'Principale', 'key' => 'mainQuery', 'global' => true, 'config' => array( 'type' => 'main-query', 'args' => array( 'posts_per_page' => '$count ?? 10' ) ) ),
			'k3' => array( 'name' => 'Menu', 'key' => 'nav', 'global' => true, 'config' => array( 'type' => 'json', 'data' => array( array( 'label' => 'Home', 'url' => '/' ), array( 'label' => 'Chi siamo', 'url' => '/chi-siamo/' ) ) ) ),
		);
		$sheets      = array( 'main' => array( 'name' => 'Main', 'css' => '.pagina{max-width:60rem}.notizia{color:#c83c28}.js-open{display:block}', 'type' => 'custom' ) );
		$styles      = array(
			's1' => array( 'collection' => 'default', 'selector' => '.card', 'css' => 'padding: 1rem;', 'readonly' => false, 'type' => 'class' ),
			'ro' => array( 'selector' => ':where([data-x])', 'css' => 'x:1', 'readonly' => true ),
		);
		$component_c = self::etch( 'element', array( 'tag' => 'div', 'attributes' => array( 'class' => 'col-contatti' ) ), self::etch( 'element', array( 'tag' => 'h3', 'attributes' => array() ), self::etch( 'text', array( 'content' => 'Contatti' ) ) ) . self::etch( 'element', array( 'tag' => 'p', 'attributes' => array() ), self::etch( 'text', array( 'content' => 'Via Roma 1, Firenze' ) ) ) );
		update_option( 'etch_loops', $loops );
		update_option( 'etch_global_stylesheets', $sheets );
		update_option( 'etch_styles', $styles );
		$component = $this->post( array( 'post_type' => 'wp_block', 'post_title' => 'Col contatti', 'post_content' => $component_c ) );
		update_post_meta( $component, 'etch_component_html_key', 'ColContatti' );

		$page_c = self::etch(
			'element',
			array( 'tag' => 'main', 'attributes' => array( 'class' => 'pagina', 'data-id' => '{this.id}' ), 'metadata' => array( 'name' => 'Pagina' ), 'script' => array( 'id' => 'a1', 'code' => base64_encode( "document.querySelector('.pagina').classList.add('js-open');" ) ) ),
			self::etch( 'element', array( 'tag' => 'h1', 'attributes' => array( 'class' => 'titolo' ) ), self::etch( 'text', array( 'content' => '{this.title}' ) ) )
			. self::etch( 'element', array( 'tag' => 'p', 'attributes' => array() ), self::etch( 'text', array( 'content' => "Pubblicato il {this.date.dateFormat('j F Y')} · " ) ) . self::etch( 'element', array( 'tag' => 'a', 'attributes' => array( 'href' => '{this.permalink.relative}' ) ), self::etch( 'text', array( 'content' => 'link' ) ) ) )
			. self::etch( 'component', array( 'ref' => $component, 'attributes' => array( 'title' => '{this.title}', 'size' => 'l' ) ) )
			. self::etch( 'element', array( 'tag' => 'section', 'attributes' => array( 'class' => 'notizie' ) ), self::etch( 'loop', array( 'loopId' => 'k1', 'itemId' => 'item', 'loopParams' => array( '$count' => 3, '$quando' => 'futuri' ) ), self::etch( 'element', array( 'tag' => 'a', 'attributes' => array( 'class' => 'notizia', 'href' => '{item.permalink.relative}' ) ), self::etch( 'text', array( 'content' => '{item.title}' ) ) ) ) )
			. self::etch( 'element', array( 'tag' => 'nav', 'attributes' => array() ), self::etch( 'loop', array( 'loopId' => 'k3', 'itemId' => 'link' ), self::etch( 'element', array( 'tag' => 'a', 'attributes' => array( 'href' => '{link.url}' ) ), self::etch( 'text', array( 'content' => '{link.label}' ) ) ) ) )
			. self::etch( 'loop', array( 'loopId' => 'k2', 'itemId' => 'post' ), self::etch( 'element', array( 'tag' => 'h2', 'attributes' => array() ), self::etch( 'text', array( 'content' => '{post.title}' ) ) ) )
			. self::etch( 'element', array( 'tag' => 'input', 'attributes' => array( 'type' => 'checkbox', 'checked' => '' ) ) )
			. self::etch( 'element', array( 'tag' => 'style', 'attributes' => array() ), self::etch( 'text', array( 'content' => '.x > .y { color: red }' ) ) )
		)
			. '<!-- wp:group --><div class="wp-block-group">' . self::etch( 'element', array( 'tag' => 'p', 'attributes' => array() ), self::etch( 'text', array( 'content' => 'nel gruppo' ) ) ) . '</div><!-- /wp:group -->'
			. '<p class="libero">HTML fuori dai blocchi</p>'
			. self::etch( 'condition', array( 'test' => 'x' ) )
			. '<!-- wp:post-content /-->';
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Chi siamo', 'post_content' => $page_c ) );

		Migration::instance()->prepare();
		$this->assertSame( 200, $this->publish()->get_status() );
		$this->assertStringNotContainsString( 'wp:etch/element', $this->content( $page ), 'the page is Histeon content now' );
		$this->assertNotSame( '', Scripts::get( $page ) );

		$plan = ( new Exporter() )->plan();
		$this->assertSame( self::normal( parse_blocks( $page_c ) ), self::normal( parse_blocks( (string) self::by_id( $plan, $page )['content'] ) ), 'the page comes back as Etch wrote it' );
		$this->assertSame( self::normal( parse_blocks( $component_c ) ), self::normal( parse_blocks( (string) self::by_id( $plan, $component )['content'] ) ) );
		$this->assertSame( array( 'etch_component_html_key' => 'ColContatti' ), self::by_id( $plan, $component )['meta'] );
		// The way back adds the visibility Histeon applies when it renders a loop (LoopPresets::visible).
		$expected = $loops;
		foreach ( $expected as $i => $loop ) {
			if ( 'wp-query' === $loop['config']['type'] ) {
				$expected[ $i ]['config']['args'] = $loop['config']['args'] + array( 'has_password' => false, 'perm' => 'readable' );
			}
		}
		$this->assertSame( $expected, $plan['options']['etch_loops'], 'queries keep their ids, keys and Etch\'s own fields' );
		$this->assertSame( $sheets['main'], $plan['options']['etch_global_stylesheets']['main'] );
		$this->assertSame( $styles['s1'], $plan['options']['etch_styles']['s1'] );
		$this->assertArrayNotHasKey( 'ro', $plan['options']['etch_styles'], 'Etch\'s read-only defaults are not Histeon\'s to write' );
		$this->assertStringContainsString( '--xb-space-m', (string) $plan['options']['etch_global_stylesheets'][ Tokens::SHEET_TOKENS ]['css'], 'Histeon CSS travels with the export' );
		$actions = array_column( $plan['diff']['options'], 'action', 'key' );
		$this->assertSame( 'replace', $actions['k1'], 'the Etch query gains the visibility Histeon applies' );
		$this->assertSame( 'add', $actions[ Tokens::SHEET_TOKENS ] );
		$this->assertSame( 64, strlen( (string) $plan['fingerprint'] ) );
	}

	public function test_histos_content_is_reported_written_with_a_backup_and_restored(): void {
		$this->set_option( LoopPresets::OPTION, array( 'eventi' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => '$count ?? 3' ) ) ) );
		$card = $this->component( 'ui/card-hero', array( $this->el( 'article', array( 'class' => 'card' ), array( $this->text( '{props.title}' ), $this->block( 'xb/slot', array( 'name' => 'default' ) ) ) ) ) );
		$page = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Histeon',
				'post_content' => $this->markup(
					array(
						$this->el(
							'section',
							array( 'class' => 'xb-text-l hero', 'style' => 'gap:var(--xb-space-m)' ),
							array(
								$this->text( 'Graffa \{letterale\}' ),
								$this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => 'this.title' ), array( $this->text( 'sì' ) ) ) ) ),
								$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'eventi', 'args' => array( 'count' => '2' ) ), 'as' => 'ev' ), array( $this->text( '{ev.title} {ev.url}' ) ) ),
								$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'terms' ), 'as' => 't' ), array( $this->text( '{t.name}' ) ) ),
								$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'limit' => '4', 'type' => '"page"' ) ), 'as' => 'p' ), array( $this->text( '{p.title}' ) ) ),
								$this->block( 'xb/component', array( 'ref' => 'ui/card-hero', 'props' => array( 'title' => array( 'expr' => 'this.title' ) ) ), array( $this->block( 'xb/slot-fill', array( 'name' => 'default' ), array( $this->text( 'dentro' ) ) ) ) ),
							)
						),
					)
				),
			)
		);
		Scripts::set( $page, 'console.log("histos")' );
		update_option( 'etch_global_stylesheets', array( 'altro' => array( 'name' => 'Altro', 'css' => '.altro{}' ) ) );

		$plan   = ( new Exporter() )->plan();
		$row    = array_column( $plan['report']['documents'], null, 'id' )[ $page ];
		$issues = implode( "\n", $row['issues'] );
		$this->assertStringContainsString( 'Condizione', $issues, 'what has no observed Etch equivalent is reported, not exported' );
		$this->assertStringContainsString( 'terms', $issues );
		$this->assertStringContainsString( 'slot', $issues );
		$this->assertSame( 1, $row['scripts'] );
		// B2: the report also says what changed on the way, not only what was left out.
		$changes = implode( "\n", $row['changes'] );
		$this->assertStringContainsString( 'Loop su «eventi» → query di Etch «eventi»', $changes );
		$this->assertStringContainsString( '{ev.url} → {ev.permalink.relative}', $changes );
		$this->assertStringContainsString( 'Chiave del componente «ui/card-hero» → «UiCardHero»', implode( "\n", array_column( $plan['report']['documents'], null, 'id' )[ $card ]['changes'] ) );
		$content = (string) self::by_id( $plan, $page )['content'];
		$this->assertStringNotContainsString( 'wp:xb/', $content );
		$this->assertStringContainsString( '"loopId":"eventi","itemId":"ev","loopParams":{"$count":2}', $content );
		$this->assertStringContainsString( '{ev.title} {ev.permalink.relative}', $content );
		$this->assertSame( 'Graffa {"{"}letterale{"}"}', parse_blocks( $content )[0]['innerBlocks'][0]['attrs']['content'], 'a literal brace stays literal: Etch prints a text escaped, an entity would show as written' );
		$this->assertStringContainsString( '"ref":' . $card, $content );
		$this->assertSame( 'UiCardHero', self::by_id( $plan, $card )['meta']['etch_component_html_key'] );
		$posts_loop = array_values( array_filter( $plan['options']['etch_loops'], static fn ( array $l ): bool => str_starts_with( (string) $l['key'], 'histos_posts_' ) ) );
		$this->assertSame( array( 'has_password' => false, 'perm' => 'readable', 'post_status' => 'publish', 'post_type' => 'page', 'posts_per_page' => 4 ), $posts_loop[0]['config']['args'], 'built-in posts preset written out with the visibility Histeon applies: Etch runs it as written' );
		foreach ( $plan['options']['etch_loops'] as $loop ) {
			if ( 'wp-query' === ( $loop['config']['type'] ?? null ) ) {
				$this->assertSame( 'publish', $loop['config']['args']['post_status'] ?? null, 'every exported wp-query lists published content only' );
				$this->assertFalse( $loop['config']['args']['has_password'] ?? null, 'and no password-protected post' );
			}
			if ( 'main-query' === ( $loop['config']['type'] ?? null ) ) {
				$this->assertSame( array(), array_diff( array_keys( $loop['config']['args'] ?? array() ), array( 'posts_per_page', 'offset', 'order', 'orderby' ) ), 'a main query keeps only what Histeon reads of it' );
			}
		}
		$this->assertNotEmpty( $plan['report']['skipped'] );
		$this->assertStringNotContainsString( '"post_author"', Exporter::file( $plan ) );
		$this->assertStringNotContainsString( ABSPATH, Exporter::file( $plan ), 'no server paths in the file' );

		try {
			( new Exporter() )->apply( 'not-the-fingerprint' );
			$this->fail( 'a write needs the fingerprint of the preview' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'impronta', strtolower( $e->getMessage() ) );
		}
		$before = $this->content( $page );
		$done   = ( new Exporter() )->apply( (string) $plan['fingerprint'] );
		$this->assertTrue( $done['written'] );
		$this->assertStringContainsString( 'wp:etch/element', $this->content( $page ) );
		$this->assertSame( '', Scripts::get( $page ), 'the script moved onto an Etch element' );
		$stored = (array) get_option( 'etch_global_stylesheets' );
		$this->assertArrayHasKey( 'altro', $stored, 'existing Etch entries Histeon does not write stay' );
		$this->assertArrayHasKey( Tokens::SHEET_TOKENS, $stored );
		$this->assertArrayHasKey( 'eventi', (array) get_option( 'etch_loops' ) );
		$this->assertCount( 1, Exporter::backups() );

		$again = ( new Exporter() )->plan();
		$this->assertNotContains( $page, array_column( $again['posts'], 'id' ), 'content already in Etch is left alone' );

		Exporter::restore();
		$this->assertSame( $before, $this->content( $page ) );
		$this->assertSame( 'console.log("histos")', Scripts::get( $page ) );
		$this->assertSame( array( 'altro' => array( 'name' => 'Altro', 'css' => '.altro{}' ) ), get_option( 'etch_global_stylesheets' ) );
		$this->assertFalse( get_option( 'etch_loops' ), 'an option that did not exist is removed again' );
		$this->assertSame( '', (string) get_post_meta( $card, 'etch_component_html_key', true ) );
		$this->assertSame( array(), Exporter::backups() );
	}

	public function test_a_restore_leaves_no_change_to_publish_and_keeps_the_date(): void {
		global $wpdb;
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Datata', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Testo' ) ) ) ) ) ) );
		Scripts::set( $page, 'console.log("data")' );
		$versioning = \XB\Plugin::instance()->versioning;
		$doc        = \XB\Versioning\Documents::for_post( $page );
		$versioning->ensure( $doc );
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2020-01-02 03:04:05', 'post_modified_gmt' => '2020-01-02 02:04:05' ), array( 'ID' => $page ) );
		clean_post_cache( $page );
		$this->assertNotContains( $doc, array_column( $versioning->changes(), 'doc' ) );

		$plan = ( new Exporter() )->plan();
		( new Exporter() )->apply( (string) $plan['fingerprint'] );
		$this->assertContains( $doc, array_column( $versioning->changes(), 'doc' ), 'Etch content is live: a Histeon release would put the Histeon content back' );

		Exporter::restore();
		$this->assertNotContains( $doc, array_column( $versioning->changes(), 'doc' ), 'restored as it was: nothing to publish' );
		$this->assertSame( '2020-01-02 03:04:05', get_post_field( 'post_modified', $page, 'raw' ) );
		$this->assertSame( '2020-01-02 02:04:05', get_post_field( 'post_modified_gmt', $page, 'raw' ) );
	}

	/** A page shown with a Histeon CMS template: its `_wp_page_template` is no template of the theme, and stays. */
	public function test_a_page_with_a_histeon_template_is_written_and_restored_with_its_template(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Con template', 'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Testo' ) ) ) ) ) ) );
		update_post_meta( $page, '_wp_page_template', 'pagina-del-progetto' );

		$plan = ( new Exporter() )->plan();
		( new Exporter() )->apply( (string) $plan['fingerprint'] );
		$this->assertStringContainsString( 'wp:etch/element', $this->content( $page ) );
		$this->assertSame( 'pagina-del-progetto', get_post_meta( $page, '_wp_page_template', true ) );

		Exporter::restore();
		$this->assertStringNotContainsString( 'wp:etch/element', $this->content( $page ) );
		$this->assertSame( 'pagina-del-progetto', get_post_meta( $page, '_wp_page_template', true ) );
	}

	/**
	 * Etch prints the content of a text as plain text (observed on a site with Etch 1.7.0: `&amp;` came out as
	 * `&amp;amp;` and a tag written in a text showed as written). An XB text is HTML: its entities are decoded and
	 * its inline formatting becomes elements.
	 */
	public function test_a_text_reaches_etch_as_plain_text_and_its_inline_formatting_as_elements(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Testi', 'post_content' => $this->markup( array(
			$this->el( 'h2', array(), array( $this->text( 'Blog &amp; News &lt;oggi&gt; \{x\} &#123;y} caff&egrave;' ) ) ),
			$this->el( 'p', array(), array( $this->text( 'Leggi <a href="/x?a=1&amp;b=2" onclick="z()" title="{no}">qui <strong>ora</strong></a>,<br/>poi {this.title} e {"a<b>c"} fine' ) ) ),
			$this->el( 'p', array(), array( $this->text( 'Non <strong>chiuso' ) ) ),
			$this->el( 'p', array(), array( $this->block( 'xb/text', array( 'content' => '<b> & {x}', 'verbatim' => true ) ) ) ),
		) ) ) );

		$plan   = ( new Exporter() )->plan();
		$blocks = self::normal( parse_blocks( (string) self::by_id( $plan, $page )['content'] ) );
		$text   = static fn ( string $content ): array => array( 'name' => 'etch/text', 'attrs' => array( 'content' => $content ), 'html' => array(), 'inner' => array() );
		$this->assertSame( array( $text( 'Blog & News <oggi> {"{"}x{"}"} {"{"}y{"}"} caffè' ) ), $blocks[0]['inner'], 'entities decoded, a literal brace as an expression that prints it' );
		$inner = $blocks[1]['inner'];
		$this->assertSame( array( 'etch/text', 'etch/element', 'etch/text', 'etch/element', 'etch/text' ), array_column( $inner, 'name' ) );
		$this->assertSame( 'Leggi ', $inner[0]['attrs']['content'] );
		$this->assertSame( array( 'attributes' => array( 'href' => '/x?a=1&b=2' ), 'tag' => 'a' ), $inner[1]['attrs'], 'only the attributes inline formatting may have, their entities decoded' );
		$this->assertSame( 'qui ', $inner[1]['inner'][0]['attrs']['content'] );
		$this->assertSame( 'strong', $inner[1]['inner'][1]['attrs']['tag'] );
		$this->assertSame( array( $text( 'ora' ) ), $inner[1]['inner'][1]['inner'] );
		$this->assertSame( ',', $inner[2]['attrs']['content'] );
		$this->assertSame( 'br', $inner[3]['attrs']['tag'] );
		$this->assertSame( array(), $inner[3]['inner'] );
		$this->assertSame( 'poi {this.title} e {"a<b>c"} fine', $inner[4]['attrs']['content'], 'a tag inside an expression is not formatting' );
		$this->assertSame( array( $text( 'Non <strong>chiuso' ) ), $blocks[2]['inner'], 'formatting that is not closed stays text' );
		$this->assertStringContainsString( 'Formattazione non chiusa', implode( "\n", array_column( $plan['report']['documents'], null, 'id' )[ $page ]['issues'] ) );
		$this->assertSame( array( $text( '<b> & {"{"}x{"}"}' ) ), $blocks[3]['inner'], 'verbatim text is plain text: Etch escapes it as Histeon does' );
	}

	/** Doc 23 of Publish, item 187: Etch's loops cannot group a query's result, so a grouped loop stays out, named. */
	public function test_a_loop_in_groups_is_left_out_and_named(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Archivio per anno', 'post_content' => $this->markup( array(
			$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'groupBy' => array( 'field' => 'date', 'how' => 'Y' ) ), 'as' => 'anno' ), array( $this->text( '{anno.key}' ) ) ),
			$this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts' ), 'as' => 'p' ), array( $this->text( '{p.title}' ) ) ),
		) ) ) );

		$plan    = ( new Exporter() )->plan();
		$issues  = implode( "\n", array_column( $plan['report']['documents'], null, 'id' )[ $page ]['issues'] );
		$content = (string) self::by_id( $plan, $page )['content'];
		$this->assertStringContainsString( 'Loop a gruppi sulla query «posts» non esportato', $issues );
		$this->assertStringNotContainsString( '{anno.key}', $content, 'the grouped loop does not reach Etch' );
		$this->assertStringContainsString( 'wp:etch/loop', $content, 'the plain loop next to it still does' );
	}

	public function test_nothing_the_author_could_not_hold_reaches_etch(): void {
		$author = $this->user( 'author' );
		$page   = $this->post( array( 'post_type' => 'page', 'post_author' => $author, 'post_title' => 'Autore', 'post_content' => '' ) );
		// Written straight to the database, past the sanitizer: the export filters again for this author.
		$hostile = $this->markup(
			array(
				$this->el( 'div', array( 'class' => 'ok', 'onclick' => 'alert(1)' ), array( $this->text( 'x' ) ) ),
				$this->el( 'script', array(), array( $this->block( 'xb/text', array( 'content' => 'alert(2)', 'verbatim' => true ) ) ) ),
				$this->block( 'xb/passthrough', array(), array( array( 'blockName' => 'etch/element', 'attrs' => array( 'tag' => 'div', 'script' => array( 'id' => 'x', 'code' => base64_encode( 'alert(3)' ) ) ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ) ),
				// Histeon escapes these when it renders; Etch renders what it receives.
				$this->el( 'a', array( 'href' => "{'javascript:alert(6)'}" ), array( $this->text( 'a' ) ) ),
				$this->el( 'a', array( 'href' => "java{''}script:alert(7)" ), array( $this->text( 'b' ) ) ),
				$this->el( 'a', array( 'href' => '{this.url}', 'class' => 'keep-dynamic' ), array( $this->text( 'c' ) ) ),
				$this->el( 'a', array( 'href' => '/pagina/{this.id}', 'class' => 'keep-relative' ), array( $this->text( 'd' ) ) ),
				$this->el( 'p', array( 'style' => 'color:{this.title}' ), array( $this->text( 'e' ) ) ),
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $hostile ), array( 'ID' => $page ) );
		clean_post_cache( $page );
		Scripts::set( $page, 'alert(4)' );
		$admin_page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Admin', 'post_content' => $this->markup( array( $this->block( 'xb/raw', array( 'content' => '<b>ok</b><!-- wp:etch/element {"tag":"div","script":{"id":"y","code":"YWxlcnQoNSk="}} /-->' ) ) ) ) ) );
		$this->set_option( LoopPresets::OPTION, array( 'segreti' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'xb_submission' ) ) ) );
		$this->post( array( 'post_type' => 'page', 'post_title' => 'Loop', 'post_content' => $this->markup( array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'segreti' ), 'as' => 'm' ), array( $this->text( '{m.title}' ) ) ) ) ) ) );

		$plan    = ( new Exporter() )->plan();
		$content = (string) self::by_id( $plan, $page )['content'];
		foreach ( array( 'onclick', 'alert(2)', 'script', 'alert(3)', 'alert(6)', 'alert(7)', 'color:' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $content, $needle );
		}
		$this->assertStringContainsString( '"href":"{this.permalink.relative}"', $content, 'a plain path stays dynamic' );
		$this->assertStringContainsString( '/pagina/{this.id}', $content, 'a relative path with an expression stays' );
		$this->assertStringNotContainsString( 'wp:etch/element {"tag":"div","script"', (string) self::by_id( $plan, $admin_page )['content'], 'raw HTML never turns into a block' );
		$this->assertArrayNotHasKey( 'segreti', $plan['options']['etch_loops'], 'Etch would run the query unchecked: internal types never leave' );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->expectException( \RuntimeException::class );
		( new Exporter() )->plan();
	}

	public function test_admin_action_needs_an_administrator_and_a_nonce(): void {
		update_option( 'etch_global_stylesheets', array( 'main' => array( 'css' => '.a{}' ) ) );
		ob_start();
		\XB\Export\Etch\Admin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'value="' . \XB\Export\Etch\Admin::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringNotContainsString( 'value="acss"', $html, 'the ACSS names are offered only where Automatic.css is installed' );

		add_filter( 'wp_die_handler', static fn () => static function ( $message ): void {
			throw new \RuntimeException( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
		} );
		try {
			unset( $_REQUEST['_wpnonce'] );
			try {
				\XB\Export\Etch\Admin::handle();
				$this->fail( 'no nonce, no action' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringStartsWith( 'wp_die', $e->getMessage() );
			}
			$editor = $this->user( 'editor' );
			$user   = get_user_by( 'id', $editor );
			$this->assertInstanceOf( \WP_User::class, $user );
			$user->add_cap( 'xb_build' );
			wp_set_current_user( $editor );
			$_REQUEST['_wpnonce'] = wp_create_nonce( \XB\Export\Etch\Admin::ACTION );
			try {
				\XB\Export\Etch\Admin::handle();
				$this->fail( 'an editor exported' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'permessi', $e->getMessage() );
			}
		} finally {
			unset( $_REQUEST['_wpnonce'] );
			remove_all_filters( 'wp_die_handler' );
		}
	}

	public function test_acss_names_only_on_request(): void {
		$tokens = new Tokens( Tokens::ACSS );
		$this->assertSame( 'gap:var(--space-m);--xb-space-m:1px;color:var(--xb-nope)', $tokens->css( 'gap:var(--xb-space-m);--xb-space-m:1px;color:var(--xb-nope)' ) );
		$this->assertSame( 'hero text--l xb-stack', $tokens->classes( 'hero xb-text-l xb-stack' ) );
		$this->assertSame( 'gap:var(--xb-space-m)', ( new Tokens() )->css( 'gap:var(--xb-space-m)' ), 'by default the tokens keep their Histeon names' );
		$issues = array();
		$sheets = $tokens->sheets( 'a{gap:var(--space-m);color:var(--xb-primary-ultra-dark)}', $issues );
		if ( array() !== $issues ) {
			$this->markTestSkipped( 'Il sito di prova non usa Histeon CSS.' );
		}
		$this->assertStringNotContainsString( '--xb-space-m:', (string) $sheets[ Tokens::SHEET_TOKENS ], 'ACSS defines what has an ACSS name' );
		if ( ! Exporter::acss_available() ) {
			$this->expectException( \RuntimeException::class );
			( new Exporter( Tokens::ACSS ) )->build();
		}
	}
}
