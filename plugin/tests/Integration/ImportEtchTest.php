<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Import\Etch\Converter;
use XB\Import\Etch\Migration;
use XB\Plugin;
use XB\Render\LoopPresets;
use XB\Scripts\Scripts;
use XB\Versioning\Documents;

/**
 * Import of Etch content (docs/spec/import-etch.md, ADR 0008). The Etch-shaped fixtures below are written for
 * these tests from the format described in the spec; no third-party data or code is used.
 */
final class ImportEtchTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'etch_loops', 'etch_global_stylesheets', 'etch_styles', LoopPresets::OPTION, LoopPresets::TYPES, LoopPresets::TAXONOMIES, \XB\Styles\PageStyles::SAFELIST, Migration::OPTION, \XB\Framework\Reset::OPTION ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
	}

	/** @param array<string, mixed> $attrs */
	private static function etch( string $name, array $attrs, string $inner = '' ): string {
		$json = (string) wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return '' === $inner ? "<!-- wp:etch/$name $json /-->" : "<!-- wp:etch/$name $json -->$inner<!-- /wp:etch/$name -->";
	}

	/**
	 * @param array<int, string>                                            $components
	 * @param array<string, array{kind: string, id?: string, expr?: string}> $loops
	 * @return list<array<string, mixed>>
	 */
	private static function convert( string $content, array $components = array(), array $loops = array() ): array {
		$converter = new Converter( $components, $loops );
		return $converter->convert( parse_blocks( $content ) );
	}

	public function test_elements_text_and_expressions_are_converted(): void {
		$content = self::etch(
			'element',
			array( 'tag' => 'section', 'attributes' => array( 'class' => 'hero', 'data-x' => '{this.id}' ), 'metadata' => array( 'name' => 'Eroe' ) ),
			self::etch( 'text', array( 'content' => "Pubblicato il {this.date.dateFormat('j F Y')} · <a href=\"{this.permalink.relative}\">link</a>" ) )
		);
		$blocks = self::convert( $content );
		$this->assertSame( 'xb/element', $blocks[0]['blockName'] );
		$this->assertSame( 'section', $blocks[0]['attrs']['tag'] );
		$this->assertSame( 'Eroe', $blocks[0]['attrs']['label'] );
		$this->assertSame( '{this.id}', $blocks[0]['attrs']['attributes']['data-x'] );
		$this->assertSame( "Pubblicato il {this.date.date('j F Y')} · <a href=\"{this.url}\">link</a>", $blocks[0]['innerBlocks'][0]['attrs']['content'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{10}$/', $blocks[0]['attrs']['uid'] );
	}

	public function test_untranslatable_expressions_become_text_and_are_reported(): void {
		$converter = new Converter( array(), array() );
		$blocks    = $converter->convert( parse_blocks( self::etch( 'text', array( 'content' => 'a {this.foo(} b' ) ) ) );
		$this->assertSame( 'a \{this.foo(\} b', $blocks[0]['attrs']['content'] );
		$this->assertNotEmpty( $converter->report() );
	}

	public function test_loops_components_and_foreign_blocks(): void {
		$content = self::etch( 'loop', array( 'loopId' => 'k1', 'itemId' => 'ev', 'loopParams' => array( '$count' => 3, '$quando' => 'futuri' ) ), self::etch( 'text', array( 'content' => '{ev.title} {ev.evento.giorno}' ) ) )
			. self::etch( 'component', array( 'ref' => 77, 'attributes' => array( 'title' => '{this.title}', 'size' => 'l' ) ) )
			. '<!-- wp:paragraph --><p>core</p><!-- /wp:paragraph -->'
			. '<!-- wp:core/post-content /-->';
		$converter = new Converter( array( 77 => 'card' ), array( 'k1' => array( 'kind' => 'preset', 'id' => 'etch_events' ) ) );
		$blocks    = $converter->convert( parse_blocks( $content ) );
		$this->assertSame( array( 'kind' => 'preset', 'id' => 'etch_events', 'args' => array( 'count' => '3', 'quando' => '"futuri"' ) ), $blocks[0]['attrs']['source'] );
		$this->assertSame( 'ev', $blocks[0]['attrs']['as'] );
		$this->assertSame( array( 'evento' => true ), $converter->expressions->custom, 'fields added by plugins are reported' );
		$this->assertSame( array( 'ref' => 'card', 'props' => array( 'title' => array( 'expr' => 'this.title' ), 'size' => 'l' ) ), array_diff_key( $blocks[1]['attrs'], array( 'uid' => 1 ) ) );
		$this->assertSame( 'xb/passthrough', $blocks[2]['blockName'] );
		$this->assertSame( 'core/paragraph', $blocks[2]['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'xb/post-content', $blocks[3]['blockName'], 'no layout wrapper around the content' );
	}

	public function test_unknown_etch_blocks_are_kept_and_reported(): void {
		$converter = new Converter( array(), array() );
		$blocks    = $converter->convert( parse_blocks( self::etch( 'svg', array( 'src' => 'x' ) ) ) );
		$this->assertSame( 'xb/passthrough', $blocks[0]['blockName'] );
		$this->assertSame( 1, $converter->counts['kept'] );
		$this->assertNotEmpty( $converter->report() );
	}

	public function test_component_keys(): void {
		$post = get_post( $this->post( array( 'post_type' => 'wp_block', 'post_title' => '2 Colonne – Città' ) ) );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 'col-contatti', Migration::key_for( 'ColContatti', $post ) );
		$this->assertSame( 'n2-colonne-citta', Migration::key_for( '', $post ) );
	}

	public function test_import_keeps_the_authors_trust_level(): void {
		$author  = $this->user( 'author' );
		$hostile = self::etch(
			'element',
			array( 'tag' => 'div', 'attributes' => array( 'class' => 'x', 'onclick' => 'alert(1)' ), 'script' => array( 'id' => 'a', 'code' => base64_encode( 'alert(1)' ) ) ),
			self::etch( 'element', array( 'tag' => 'script' ), self::etch( 'text', array( 'content' => 'alert(2)' ) ) )
		);
		$untrusted = $this->post( array( 'post_type' => 'page', 'post_author' => $author, 'post_content' => $hostile ) );
		$trusted   = $this->post( array( 'post_type' => 'page', 'post_author' => 1, 'post_content' => self::etch( 'element', array( 'tag' => 'div', 'script' => array( 'id' => 'b', 'code' => base64_encode( 'console.log("ok")' ) ) ) ) ) );

		$report = Migration::instance()->prepare();
		$rows   = array_column( $report['documents'], null, 'id' );
		$this->assertFalse( $rows[ $untrusted ]['trusted'] );
		$this->assertSame( 0, $rows[ $untrusted ]['scripts'], 'an author without unfiltered_html never gets JavaScript imported' );
		$this->assertTrue( $rows[ $trusted ]['trusted'] );
		$this->assertSame( 1, $rows[ $trusted ]['scripts'] );

		$store = Plugin::instance()->versioning->store();
		$head  = $store->get( (string) $store->head( Documents::for_post( $untrusted ), 'dev' ) );
		$this->assertNotNull( $head );
		$this->assertFalse( $head['trusted'], 'recorded as untrusted, whoever ran the import' );
		$this->assertStringNotContainsString( 'onclick', (string) $head['payload']['content'] );
		$this->assertStringNotContainsString( '"tag":"script"', (string) $head['payload']['content'] );
		$this->assertSame( '', (string) $head['payload']['script'] );
		$this->assertStringContainsString( '<!-- wp:etch/', $this->content( $untrusted ), 'nothing is live before a release' );

		$this->assertSame( 200, $this->publish()->get_status() );
		$this->assertStringNotContainsString( 'onclick', $this->content( $untrusted ) );
		$this->assertSame( '', Scripts::get( $untrusted ) );
		$this->assertStringContainsString( 'console.log("ok")', Scripts::get( $trusted ) );
	}

	public function test_presets_styles_safelist_and_reset(): void {
		update_option( 'etch_loops', array( 'k1' => array( 'key' => 'news', 'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post', 'posts_per_page' => '$count ?? -1' ) ) ), 'k2' => array( 'key' => 'mainQuery', 'config' => array( 'type' => 'main-query', 'args' => array( 'posts_per_page' => '$count ?? 10' ) ) ), 'k3' => array( 'key' => 'nav', 'config' => array( 'type' => 'json', 'data' => array( array( 'label' => 'Home', 'url' => '/' ) ) ) ) ) );
		update_option( 'etch_global_stylesheets', array( 'main' => array( 'name' => 'Main', 'css' => '.js-open{color:red}.never{x:1}' ), 'empty' => array( 'css' => '/* only a comment */' ) ) );
		update_option( 'etch_styles', array( 'ro' => array( 'selector' => ':where([data-x])', 'css' => 'x:1', 'readonly' => true ) ) );
		$this->post( array( 'post_type' => 'page', 'post_content' => self::etch( 'element', array( 'tag' => 'div', 'script' => array( 'id' => 'c', 'code' => base64_encode( "el.classList.add('js-open')" ) ) ) ) ) );

		$report = Migration::instance()->prepare();
		$this->assertSame( array( 'etch_news', 'etch_mainQuery' ), $report['presets'] );
		$settings = Plugin::instance()->versioning->working_copy( Documents::SETTINGS );
		$this->assertSame( 'main', $settings['payload']['presets']['etch_mainQuery']['type'], 'queries go live with the release' );
		$this->assertSame( array( 'globals' => 1, 'selectors' => 0, 'bytes' => 30 ), $report['styles'], 'read-only Etch defaults and empty sheets are not imported' );
		$this->assertSame( array( '.js-open' ), $report['safelist'], 'classes added by scripts stay in on-demand CSS' );
		$this->assertTrue( $settings['payload']['reset'] );
		$this->assertSame( '[{label: "Home", url: "/"}]', Migration::literal( array( array( 'label' => 'Home', 'url' => '/' ) ) ) );
	}

	/** Notes 68 and 69 of the 3 November audit: what would stop the import stops it before any document is written. */
	public function test_an_import_that_cannot_finish_writes_nothing(): void {
		$page = $this->post( array( 'post_type' => 'page', 'post_content' => self::etch( 'element', array( 'tag' => 'p' ), self::etch( 'text', array( 'content' => 'Ciao' ) ) ) ) );
		$head = static fn (): string => (string) Plugin::instance()->versioning->working_copy( Documents::for_post( $page ) )['head'];
		$before = $head();
		// Styles past the builder's ceiling.
		update_option( 'etch_global_stylesheets', array( 'big' => array( 'css' => '.a{color:red}/*' . str_repeat( 'x', \XB\Rest\Controller::MAX_CSS_BYTES ) . '*/' ) ) );
		$plan = Migration::instance()->plan();
		$this->assertNotSame( array(), array_filter( $plan['issues'], static fn ( string $issue ): bool => str_contains( $issue, 'dimensione massima' ) ), 'the plan says it' );
		try {
			Migration::instance()->prepare();
			$this->fail( 'the import went on' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'dimensione massima', $e->getMessage() );
		}
		$this->assertSame( $before, $head(), 'no document written' );
		update_option( 'etch_global_stylesheets', array() );
		// A query that the settings would refuse when they are saved, after the documents.
		update_option( 'etch_loops', array( 'k1' => array( 'key' => 'news', 'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post', 's' => str_repeat( 'x', 70000 ) ) ) ) ) );
		try {
			Migration::instance()->prepare();
			$this->fail( 'a query WordPress would refuse' );
		} catch ( \InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertSame( $before, $head(), 'still no document written' );
	}

	public function test_preset_defaults_limits_and_single_post_type(): void {
		$this->set_option( LoopPresets::OPTION, array( 'p' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => '$count ?? -1' ) ) ) );
		$seen = null;
		$spy  = static function ( \WP_Query $q ) use ( &$seen ): void {
			$seen = $q->get( 'post_type' );
		};
		add_action( 'pre_get_posts', $spy );
		( new LoopPresets() )->run( 'p', array() );
		remove_action( 'pre_get_posts', $spy );
		$this->assertSame( 'post', $seen, 'one type as a string, like WordPress: plugins compare it that way' );
	}

	public function test_loop_types_allowlist_never_opens_internals(): void {
		$this->assertFalse( LoopPresets::listable( 'wp_block' ) );
		$this->set_option( LoopPresets::TYPES, array( 'wp_block', 'xb_submission', 'wp_navigation' ) );
		foreach ( array( 'wp_block', 'xb_submission', 'wp_navigation', 'revision' ) as $type ) {
			$this->assertFalse( LoopPresets::listable( $type ), $type );
		}
		$this->assertTrue( LoopPresets::listable( 'post' ) );
	}

	public function test_post_data_filter_adds_fields_without_replacing_builtins(): void {
		$id     = $this->post( array( 'post_title' => 'Titolo vero' ) );
		$filter = static fn ( array $data, int $post_id ): array => $data + array( 'evento' => array( 'giorno' => '7' ), 'title' => 'falso', 'for' => $post_id );
		add_filter( 'xb_post_data', $filter, 10, 2 );
		$this->go_to( $id );
		$html = $this->render( array( $this->text( '{this.evento.giorno}|{this.title}|{this.for}' ) ) );
		remove_filter( 'xb_post_data', $filter );
		$this->assertSame( '7|Titolo vero|' . $id, $html );
	}

	public function test_text_written_in_xb_keeps_straight_quotes(): void {
		$html = $this->render( array( $this->text( 'l\'evento "Viva" <strong class="a">ok</strong>' ) ) );
		$this->assertSame( 'l&#039;evento &quot;Viva&quot; <strong class="a">ok</strong>', $html );
		$this->assertSame( $html, wptexturize( $html ), 'wptexturize leaves it as written' );
	}

	/** Etch conditions become xb/if (seen on a real site, 7 November: counts of the series, featured images, search). */
	public function test_conditions_become_if_blocks(): void {
		$count = array( 'leftHand' => 'item.count', 'operator' => '==', 'rightHand' => '0' );
		$or    = array(
			'leftHand'  => array( 'leftHand' => 'url.parameter.s', 'operator' => 'isTruthy', 'rightHand' => null ),
			'operator'  => '||',
			'rightHand' => array( 'leftHand' => 'this.type', 'operator' => '!==', 'rightHand' => '"page"' ),
		);
		$content   = self::etch( 'loop', array( 'loopId' => 't1', 'itemId' => 'item' ), self::etch( 'condition', array( 'condition' => $count, 'conditionString' => 'item.count == 0' ), self::etch( 'text', array( 'content' => 'In preparazione' ) ) ) )
			. self::etch( 'condition', array( 'condition' => $or ), self::etch( 'text', array( 'content' => 'Ricerca' ) ) )
			. self::etch( 'loop', array( 'loopId' => 'p1', 'itemId' => 'post' ), self::etch( 'condition', array( 'condition' => array( 'leftHand' => 'post.featuredImage', 'operator' => 'isTruthy', 'rightHand' => '' ) ), self::etch( 'element', array( 'tag' => 'img', 'attributes' => array( 'src' => '{post.featuredImage}' ) ) ) ) )
			. self::etch( 'condition', array( 'condition' => array( 'leftHand' => 'url.parameter.s', 'operator' => 'isFalsy', 'rightHand' => null ) ) );
		$converter = new Converter( array(), array( 't1' => array( 'kind' => 'preset', 'id' => 'etch_c', 'item' => 'term' ), 'p1' => array( 'kind' => 'preset', 'id' => 'etch_p', 'item' => 'post' ) ) );
		$blocks    = $converter->convert( parse_blocks( $content ) );
		$if        = $blocks[0]['innerBlocks'][0];
		$this->assertSame( 'xb/if', $if['blockName'] );
		$this->assertSame( 'xb/branch', $if['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'item.count == 0', $if['innerBlocks'][0]['attrs']['test'] );
		$this->assertSame( 'In preparazione', $if['innerBlocks'][0]['innerBlocks'][0]['attrs']['content'] );
		$this->assertSame( 'url.query.s || (this.type != "page")', $blocks[1]['innerBlocks'][0]['attrs']['test'], 'strict in Histeon already' );
		$branch = $blocks[2]['innerBlocks'][0]['innerBlocks'][0];
		$this->assertSame( 'post.image.url', $branch['attrs']['test'] );
		$this->assertSame( '{post.image.url}', $branch['innerBlocks'][0]['attrs']['attributes']['src'] );
		$this->assertSame( '!url.query.s', $blocks[3]['innerBlocks'][0]['attrs']['test'] );
		$this->assertSame( 0, $converter->counts['kept'] );
		$this->assertSame( array(), $converter->report() );
		$this->assertSame( array(), $converter->expressions->custom );

		// Rendered: the first true branch only.
		$this->assertSame( 'sì', $this->render( array( $this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => '!url.query.s' ), array( $this->text( 'sì' ) ) ) ) ) ) ) );

		$unknown = new Converter( array(), array() );
		$kept    = $unknown->convert( parse_blocks( self::etch( 'condition', array( 'condition' => array( 'leftHand' => 'a', 'operator' => 'matches', 'rightHand' => 'b' ) ) ) ) );
		$this->assertSame( 'xb/passthrough', $kept[0]['blockName'], 'an operator Etch does not write is kept, not guessed' );
		$this->assertNotEmpty( $unknown->report() );
	}

	/** An image whose ID is an expression or a prop (seen on two real sites, 8 November 2026): the image itself. */
	public function test_dynamic_images_from_expressions_and_props(): void {
		$source = self::etch( 'dynamic-image', array( 'tag' => 'img', 'attributes' => array( 'alt' => '', 'mediaId' => '{props.image}', 'useSrcSet' => 'true', 'maximumSize' => 'medium', 'loading' => 'lazy', 'class' => 'card__image' ) ) );
		$card   = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Scheda',
				'post_content' => $source,
			)
		);
		$this->assertSame( array( 'image' ), Converter::image_props( parse_blocks( $source ) ) );
		$converter = new Converter( array( $card => 'card' ), array( 'p1' => array( 'kind' => 'preset', 'id' => 'etch_p' ) ), array( $card => array( 'image' ) ) );

		// The component reads the file from the image its prop holds, at the size Etch printed.
		$definition = $converter->convert( parse_blocks( $source ) );
		$this->assertSame( array( 'src' => '{props.image.medium}', 'alt' => '', 'loading' => 'lazy', 'class' => 'card__image' ), $definition[0]['attrs']['attributes'], 'useSrcSet and maximumSize are no HTML attributes' );

		$blocks = $converter->convert(
			parse_blocks(
				self::etch( 'loop', array( 'loopId' => 'p1', 'itemId' => 'item' ), self::etch( 'component', array( 'ref' => $card, 'attributes' => array( 'image' => '{item.image.id}' ) ) ) )
				. self::etch( 'component', array( 'ref' => $card, 'attributes' => array( 'image' => '308' ) ) )
				. self::etch( 'dynamic-image', array( 'tag' => 'img', 'attributes' => array( 'alt' => '{this.image.alt}', 'mediaId' => '{this.image.id}', 'maximumSize' => 'large' ) ) )
				. self::etch( 'dynamic-image', array( 'tag' => 'img', 'attributes' => array( 'mediaId' => '{this.image.id}' ) ) )
				. self::etch( 'dynamic-image', array( 'tag' => 'img', 'attributes' => array( 'alt' => '', 'src' => '{this.author.avatar}', 'loading' => 'lazy' ) ) )
			)
		);
		$this->assertSame( array( 'image' => array( 'expr' => 'item.image' ) ), $blocks[0]['innerBlocks'][0]['attrs']['props'], 'the prop receives the image, not its ID' );
		$this->assertArrayNotHasKey( 'props', $blocks[1]['attrs'], 'an ID written by hand has no image in Histeon: reported' );
		$this->assertSame( array( 'src' => '{this.image.large}', 'alt' => '{this.image.alt}' ), $blocks[2]['attrs']['attributes'] );
		$this->assertSame( array( 'src' => '{this.image.url}', 'alt' => '{this.image.alt}' ), $blocks[3]['attrs']['attributes'], 'no size: the original; no alt: the image\'s' );
		$this->assertSame( array( 'alt' => '', 'src' => '{this.author.avatar}', 'loading' => 'lazy' ), $blocks[4]['attrs']['attributes'], 'no attachment: an image with its src' );
		$this->assertSame( 0, $converter->counts['kept'] );
		$this->assertCount( 1, $converter->report() );
		$this->assertStringContainsString( 'image', $converter->report()[0] );

		// Rendered: the image passed to the component draws with its file, dimensions and srcset.
		$upload = wp_upload_bits( 'xb-etch-' . wp_generate_password( 6, false ) . '.gif', null, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) );
		$this->assertEmpty( $upload['error'] );
		$image = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'foto', 'post_status' => 'inherit' ), $upload['file'] );
		$this->track( $image );
		$file = basename( $upload['file'], '.gif' );
		wp_update_attachment_metadata(
			$image,
			array(
				'width'  => 1200,
				'height' => 600,
				'file'   => _wp_relative_upload_path( $upload['file'] ),
				'sizes'  => array( 'medium' => array( 'file' => "$file-300x150.gif", 'width' => 300, 'height' => 150, 'mime-type' => 'image/gif' ) ),
			)
		);
		$this->component( 'etch-card-image', array( $this->el( 'img', array( 'src' => '{props.image.medium}', 'alt' => '' ) ) ) );
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Con foto' ) );
		set_post_thumbnail( $page, $image );
		$this->go_to( $page );
		$html = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'etch-card-image', 'props' => array( 'image' => array( 'expr' => 'this.image' ) ) ) ) ) );
		$this->assertMatchesRegularExpression( '/ src="[^"]*-300x150\.gif"/', $html );
		$this->assertStringContainsString( ' width="300"', $html );
		$this->assertStringContainsString( ' srcset=', $html );
	}

	/** A loop over a value (`target`), seen on two real sites (8 November 2026): an expression source. */
	public function test_loops_over_a_value(): void {
		$converter = new Converter( array(), array( 'posts' => array( 'kind' => 'preset', 'id' => 'etch_posts' ) ) );
		$blocks    = $converter->convert(
			parse_blocks(
				self::etch( 'loop', array( 'target' => 'this.tags', 'itemId' => 'tag' ), self::etch( 'text', array( 'content' => '{tag.name};' ) ) )
				. self::etch( 'loop', array( 'target' => 'posts.length().equal(0, [1], [])', 'itemId' => 'empty' ), self::etch( 'text', array( 'content' => 'Vuoto' ) ) )
			)
		);
		$this->assertSame( array( 'kind' => 'expr', 'expr' => 'this.tags' ), $blocks[0]['attrs']['source'] );
		$this->assertSame( 'tag', $blocks[0]['attrs']['as'] );
		$this->assertSame( array(), $converter->expressions->custom, 'the fields of a term are no fields added by plugins' );
		$this->assertSame( 'xb/passthrough', $blocks[1]['blockName'], 'the results of a query read by its key have no Histeon form' );
		$this->assertCount( 2, $converter->report(), 'what the loop reads, and the block kept' );

		$post = $this->post( array( 'post_type' => 'post', 'post_title' => 'Con argomenti', 'tags_input' => array( 'alfa', 'beta' ) ) );
		$this->go_to( $post );
		$this->assertSame( 'alfa;beta;', $this->render( array( $blocks[0] ) ) );
	}

	public function test_raw_html(): void {
		$converter = new Converter( array(), array( 'k' => array( 'kind' => 'preset', 'id' => 'etch_k', 'item' => 'post' ) ) );
		$blocks    = $converter->convert(
			parse_blocks(
				self::etch( 'raw-html', array( 'content' => '<hr class="x">' ) )
				. self::etch( 'loop', array( 'loopId' => 'k', 'itemId' => 'item' ), self::etch( 'raw-html', array( 'content' => '{item.excerpt}' ) ) )
				. self::etch( 'raw-html', array( 'content' => '<b>{this.title}</b>' ) )
			)
		);
		$this->assertSame( array( 'xb/raw', '<hr class="x">' ), array( $blocks[0]['blockName'], $blocks[0]['attrs']['content'] ) );
		$this->assertSame( array( 'xb/text', '{item.excerpt}' ), array( $blocks[1]['innerBlocks'][0]['blockName'], $blocks[1]['innerBlocks'][0]['attrs']['content'] ) );
		$this->assertSame( 'xb/passthrough', $blocks[2]['blockName'], 'markup and expressions together have no Histeon form' );
		$this->assertCount( 2, array_filter( $converter->report(), static fn ( string $i ): bool => str_contains( $i, 'HTML grezzo' ) ), 'both are reported' );
	}

	/** wp-terms queries become term presets; a private taxonomy they list is allowed explicitly (for example WooCommerce product_type). */
	public function test_term_queries_and_private_taxonomies(): void {
		register_taxonomy( 'xb_tipo_privato', 'post', array( 'public' => false ) );
		$term = term_exists( 'Semplice', 'xb_tipo_privato' ) ?: wp_insert_term( 'Semplice', 'xb_tipo_privato' );
		$this->assertIsArray( $term );
		update_option(
			'etch_loops',
			array(
				'c1' => array( 'key' => 'collane', 'config' => array( 'type' => 'wp-terms', 'args' => array( 'taxonomy' => 'category', 'hide_empty' => false, 'orderby' => 'name', 'fields' => 'ids' ) ) ),
				'c2' => array( 'key' => 'tipi', 'config' => array( 'type' => 'wp-terms', 'args' => array( 'taxonomy' => 'xb_tipo_privato', 'slug' => array( 'semplice' ), 'hide_empty' => false ) ) ),
				'c3' => array( 'key' => 'faq', 'config' => array( 'type' => 'json', 'data' => array( array( 'question' => 'D?', 'answer' => 'R.' ) ) ) ),
			)
		);
		$this->post(
			array(
				'post_type'    => 'page',
				'post_content' => self::etch( 'loop', array( 'loopId' => 'c1', 'itemId' => 'item' ), self::etch( 'text', array( 'content' => '{item.name} {item.count} {item.permalink}' ) ) )
					. self::etch( 'loop', array( 'loopId' => 'c3', 'itemId' => 'faq' ), self::etch( 'text', array( 'content' => '{faq.question} {faq.answer}' ) ) ),
			)
		);

		$report = Migration::instance()->prepare();
		$this->assertContains( 'etch_collane', $report['presets'] );
		$this->assertSame( array( 'xb_tipo_privato' ), $report['taxonomies'] );
		$this->assertSame( array(), $report['custom'], 'term fields and static data are not plugin fields' );
		$this->assertSame( array(), array_filter( $report['issues'], static fn ( string $i ): bool => str_contains( $i, 'non supportata' ) ) );
		$settings = Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['payload'];
		$this->assertSame( 'terms', $settings['presets']['etch_collane']['type'] );
		$this->assertArrayNotHasKey( 'fields', $settings['presets']['etch_collane']['args'], 'visibility arguments are dropped' );
		$this->assertSame( LoopPresets::MAX_ITEMS, $settings['presets']['etch_collane']['args']['number'], 'all the terms, as get_terms() lists them' );
		$this->assertSame( array( 'xb_tipo_privato' ), $settings['loopTaxonomies'], 'it goes live with the release' );

		// Without Etch's queries (Etch off, they would allow it at render time: Runtime::taxonomies) only the setting does.
		update_option( 'etch_loops', array() );
		\XB\Import\Etch\Runtime::queries_changed();
		$this->assertFalse( LoopPresets::listable_taxonomy( 'xb_tipo_privato' ), 'private, not allowed' );
		$this->set_option( LoopPresets::TAXONOMIES, array( 'xb_tipo_privato' ) );
		$this->assertTrue( LoopPresets::listable_taxonomy( 'xb_tipo_privato' ) );
		$items = ( new LoopPresets() )->run_preset( 'etch_tipi', array( 'type' => 'terms', 'args' => array( 'taxonomy' => 'xb_tipo_privato', 'hide_empty' => false ) ), array() );
		$this->assertSame( array( 'Semplice' ), array_map( static fn ( $t ): string => (string) $t, $items ) );
		$this->set_option( LoopPresets::TAXONOMIES, array( 'nav_menu', 'post_format' ) );
		$this->assertSame( array(), \XB\Render\Queries::validate_taxonomies( array( 'nav_menu', 'post_format', 'nessuna' ) ), 'never WordPress internals' );
		$this->assertFalse( LoopPresets::listable_taxonomy( 'nav_menu' ) );
		wp_delete_term( (int) $term['term_id'], 'xb_tipo_privato' );
		unregister_taxonomy( 'xb_tipo_privato' );
	}

	public function test_reading_time(): void {
		$id = $this->post( array( 'post_content' => str_repeat( 'parola ', 401 ) ) );
		$this->go_to( $id );
		$this->assertSame( '3', $this->render( array( $this->text( '{this.readingTime}' ) ) ), 'as Etch: 200 words a minute, rounded up' );
	}

	/** Etch's custom media queries: no browser understands `@media (--name)`, so the import writes the conditions. */
	public function test_custom_media_queries_are_resolved(): void {
		update_option(
			'etch_global_stylesheets',
			array(
				'cm'   => array( 'name' => 'Custom media', 'type' => 'custom-media', 'css' => "/* Definizioni */\n@custom-media --nav-collapse (width < 64em);\n@custom-media --wide (width >= 80em);\n@custom-media --both (--wide) and (hover: hover);" ),
				'main' => array( 'name' => 'Main', 'css' => '@media (--wide) { .g-wide { gap: 2rem; } }' ),
			)
		);
		update_option(
			'etch_styles',
			array(
				'n1' => array( 'selector' => '.cm-nav', 'css' => "display: flex;\n  @media (--nav-collapse) { display: none; }\n  @media (--both) { gap: 1rem; }\n  @media print and (--wide) { gap: 0; }\n  @media (--ignota) { color: red; }" ),
			)
		);
		$report = Migration::instance()->prepare();
		$this->assertSame( 1, $report['styles']['globals'], 'the sheet of custom media is not a sheet of its own' );
		$styles = Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload'];
		$global = array_column( $styles['globals'], 'css', 'id' );
		$this->assertArrayNotHasKey( 'etch-cm', $global );
		$this->assertSame( '@media (width >= 80em) { .g-wide { gap: 2rem; } }', $global['etch-main'] );
		$entry = array_column( $styles['styles'], 'css', 'selector' )['.cm-nav'];
		$this->assertStringContainsString( '@media (width < 64em) { display: none; }', $entry );
		$this->assertStringContainsString( '@media (width >= 80em) and (hover: hover) { gap: 1rem; }', $entry, 'a definition naming another one' );
		$this->assertStringContainsString( '@media print and (width >= 80em) { gap: 0; }', $entry, 'a name inside a longer query' );
		$this->assertStringContainsString( '@media (--ignota)', $entry, 'an unknown name stays as written' );
	}

	/**
	 * Etch printed the selector styles in the order the page's elements name them: of two rules of equal specificity
	 * on the same element, the later one wins. The import keeps that order, not the order of Etch's option.
	 */
	public function test_selector_styles_keep_the_order_etch_printed(): void {
		update_option(
			'etch_styles',
			array(
				'burger' => array( 'selector' => '.ord-burger', 'css' => 'display: none;' ),
				'after'  => array( 'selector' => '.ord-after', 'css' => 'color: blue;' ),
				'page'   => array( 'selector' => '.ord-page', 'css' => 'color: green;' ),
				'icon'   => array( 'selector' => '.ord-icon', 'css' => 'display: inline-flex;' ),
				'free'   => array( 'selector' => '.ord-free', 'css' => 'color: red;' ),
				'before' => array( 'selector' => '.ord-before', 'css' => 'color: black;' ),
			)
		);
		$header = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Testata ordine',
				'post_content' => self::etch( 'element', array( 'tag' => 'header', 'styles' => array( 'before' ) ), self::etch( 'element', array( 'tag' => 'a', 'styles' => array( 'icon' ) ) ) . self::etch( 'element', array( 'tag' => 'button', 'styles' => array( 'burger', 'icon' ) ) ) ),
			)
		);
		$this->post(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'ordine',
				'post_content' => self::etch( 'component', array( 'ref' => $header ) ) . '<!-- wp:post-content /-->' . self::etch( 'element', array( 'tag' => 'footer', 'styles' => array( 'after' ) ) ),
			)
		);
		$this->post( array( 'post_type' => 'page', 'post_content' => self::etch( 'element', array( 'tag' => 'main', 'styles' => array( 'page', 'icon' ) ) ) ) );

		Migration::instance()->prepare();
		$styles = Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload']['styles'];
		$order  = array_values( array_filter( array_column( $styles, 'selector' ), static fn ( string $s ): bool => str_starts_with( $s, '.ord-' ) ) );
		$this->assertSame( array( '.ord-before', '.ord-icon', '.ord-burger', '.ord-page', '.ord-after', '.ord-free' ), $order, 'header, content, then what follows the content; unused last' );

		$this->assertSame( 200, $this->publish()->get_status() );
		$css = Plugin::instance()->styles->all_css();
		$this->assertLessThan( strpos( $css, '.ord-burger' ), strpos( $css, '.ord-icon' ), 'display:none of the menu button wins on the desktop, as with Etch' );
	}

	/**
	 * Etch's read-only defaults live in the site's own option and reach the page when an element names them
	 * (`:where([data-etch-element="section"])`: sections are flex columns). The ones the elements use are imported.
	 */
	public function test_read_only_defaults_used_by_elements_are_imported(): void {
		update_option(
			'etch_styles',
			array(
				'etch-section-style' => array( 'selector' => ':where([data-etch-element="section"])', 'css' => 'display: flex; flex-direction: column;', 'readonly' => true, 'type' => 'element' ),
				'etch-iframe-style'  => array( 'selector' => ':where([data-etch-element="iframe"])', 'css' => 'aspect-ratio: 16/9;', 'readonly' => true, 'type' => 'element' ),
				'hero'               => array( 'selector' => '.ro-hero', 'css' => 'padding: 1rem;' ),
			)
		);
		$this->post( array( 'post_type' => 'page', 'post_content' => self::etch( 'element', array( 'tag' => 'section', 'attributes' => array( 'data-etch-element' => 'section', 'class' => 'ro-hero' ), 'styles' => array( 'etch-section-style', 'hero' ) ) ) ) );
		$report = Migration::instance()->prepare();
		$this->assertSame( 2, $report['styles']['selectors'] );
		$selectors = array_column( Plugin::instance()->versioning->working_copy( Documents::STYLES )['payload']['styles'], 'selector' );
		$this->assertContains( ':where([data-etch-element="section"])', $selectors, 'used by an element, as Etch printed it' );
		$this->assertNotContains( ':where([data-etch-element="iframe"])', $selectors, 'used by none: Etch did not print it' );
	}

	/**
	 * Comments outside the blocks stay in the saved content as they are: a plugin may look for them there (a cookie
	 * policy marked with a comment). Inside a block attribute their quotes would be encoded.
	 */
	public function test_html_comments_outside_blocks_stay_as_written(): void {
		$comment = '<!-- [cmplz-document type="cookie-statement" region="eu"] -->';
		$page    = $this->post( array( 'post_type' => 'page', 'post_content' => $comment . "\n" . self::etch( 'element', array( 'tag' => 'p' ), self::etch( 'text', array( 'content' => 'Testo' ) ) ) . "\n<p>libero</p>" ) );
		$report  = Migration::instance()->prepare();
		$content = (string) Plugin::instance()->versioning->working_copy( Documents::for_post( $page ) )['payload']['content'];
		$this->assertStringStartsWith( $comment, $content, 'the comment, word for word' );
		$this->assertStringContainsString( '<!-- wp:xb/raw', $content, 'other HTML outside blocks is still a raw block' );
		$issues  = array_column( $report['documents'], 'issues', 'id' )[ $page ];
		$this->assertCount( 1, array_filter( $issues, static fn ( string $i ): bool => str_contains( $i, 'fuori dai blocchi' ) ), 'only the HTML that is not a comment' );
		$this->assertSame( 200, $this->publish()->get_status() );
		$this->assertStringContainsString( $comment, $this->content( $page ) );
	}

	/**
	 * A component's key is written on its post when a release publishes it: before the first release, the development
	 * preview finds the imported components by the key of their development version.
	 */
	public function test_preview_before_the_first_release_draws_imported_components(): void {
		wp_set_current_user( 1 );
		$component = $this->post(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Piede anteprima',
				'post_author'  => 1,
				'post_content' => self::etch( 'element', array( 'tag' => 'footer' ), self::etch( 'text', array( 'content' => 'Dal piede importato' ) ) ),
			)
		);
		$page = $this->post( array( 'post_type' => 'page', 'post_author' => 1, 'post_content' => self::etch( 'component', array( 'ref' => $component ) ) ) );
		Migration::instance()->prepare();
		$this->assertSame( '', (string) get_post_meta( $component, \XB\Render\ComponentRegistry::META_KEY, true ), 'no key before a release' );

		\XB\Versioning\Preview::use_dev( false );
		Plugin::instance()->components->forget();
		try {
			$html = do_blocks( \XB\Versioning\Preview::content( $this->content( $page ), $page ) );
		} finally {
			\XB\Versioning\Preview::reset();
			Plugin::instance()->components->forget();
		}
		$this->assertMatchesRegularExpression( '#<footer[^>]*>Dal piede importato</footer>#', $html );
		$this->assertNull( \XB\Versioning\Preview::dev_component( 'piede-anteprima' ), 'outside a preview, only published keys' );
	}

	/**
	 * Products imported together share their date: a loop ordered by date breaks ties by ID, so a list keeps its order
	 * when the query changes plan (seen comparing an imported site with its Etch version, 7 November 2026).
	 */
	public function test_loops_ordered_by_date_are_stable(): void {
		$date = '2026-01-02 03:04:05';
		$ids  = array();
		foreach ( array( 'Uno', 'Due', 'Tre' ) as $title ) {
			$ids[] = $this->post( array( 'post_title' => 'Pari ' . $title, 'post_date' => $date, 'post_date_gmt' => $date ) );
		}
		$sql     = '';
		$capture = static function ( string $request ) use ( &$sql ): string {
			$sql = $request;
			return $request;
		};
		add_filter( 'posts_request', $capture );
		$items = ( new LoopPresets() )->run_preset( 'pari', array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 's' => 'Pari', 'orderby' => 'date', 'order' => 'DESC' ) ), array() );
		remove_filter( 'posts_request', $capture );
		$this->assertStringContainsString( '.ID DESC', $sql, 'the ID breaks ties, in the same direction' );
		$this->assertSame( array_reverse( $ids ), array_map( static fn ( $p ): int => (int) $p->id(), $items ) );
		$this->assertSame( array( 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ) ), LoopPresets::stable_order( array( 'orderby' => 'title', 'order' => 'asc' ) ) );
		$this->assertSame( array( 'orderby' => 'rand' ), LoopPresets::stable_order( array( 'orderby' => 'rand' ) ), 'random stays random' );
	}
}
