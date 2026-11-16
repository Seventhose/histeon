<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Export\Core\Analyzer;
use XB\Export\Core\Report;

/** F0, the trial report of the way out to Gutenberg (docs/spec/export-core.md). */
final class ExportCoreTest extends IntegrationTestCase {

	/** @return array<string, mixed> The report entry of one document. */
	private function entry( int $id ): array {
		$report = ( new Report() )->build( $id );
		$this->assertCount( 1, $report['documents'] );
		return $report['documents'][0];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed> The first item of a node, by uid.
	 */
	private static function item( array $entry, string $uid ): array {
		foreach ( $entry['items'] as $item ) {
			if ( $uid === $item['uid'] ) {
				return $item;
			}
		}
		self::fail( 'nessun elemento ' . $uid );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return list<array<string, mixed>>
	 */
	private static function notes( array $entry, string $fate ): array {
		return array_values( array_filter( $entry['notes'], static fn ( array $n ): bool => $fate === $n['fate'] ) );
	}

	public function test_page_elements_by_destination(): void {
		$card = $this->component( 'test/scheda', array( $this->el( 'div', array( 'class' => 'scheda' ), array( $this->el( 'h3', array(), array( $this->text( '{props.titolo}' ) ) ) ) ) ) );
		$box  = $this->component( 'test/riquadro', array( $this->el( 'div', array( 'class' => 'riquadro riquadro--{props.tono}' ), array( $this->block( 'xb/slot', array() ) ) ) ) );
		unset( $card, $box );
		$id    = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Uscita',
				'post_content' => $this->markup(
					array(
						$this->el(
							'section',
							array( 'class' => 'hero' ),
							array(
								$this->el( 'h1', array( 'class' => 'hero__title' ), array( $this->text( '{this.title}' ) ), 'h1aaaaaaaa' ),
								$this->el( 'p', array(), array( $this->text( 'Testo con ' ), $this->el( 'strong', array(), array( $this->text( 'forza' ) ) ) ), 'paaaaaaaaa' ),
								$this->el( 'img', array( 'src' => '/foto.jpg', 'alt' => '' ), array(), 'imgaaaaaaa' ),
								$this->el( 'ul', array(), array( $this->el( 'li', array(), array( $this->text( 'uno' ) ) ), $this->el( 'li', array(), array( $this->text( 'due' ) ) ) ), 'ulaaaaaaaa' ),
							),
							'secaaaaaaa'
						),
						$this->el( 'svg', array( 'viewBox' => '0 0 1 1' ), array( $this->el( 'path', array( 'd' => 'M0 0' ) ) ), 'svgaaaaaaa' ),
						$this->block( 'xb/raw', array( 'uid' => 'rawaaaaaaa', 'content' => '<hr>' ) ),
						$this->el( 'form', array( 'action' => '/xb-form', 'method' => 'post' ), array( $this->el( 'input', array( 'name' => 'xb_form', 'type' => 'hidden', 'value' => 'contatti' ) ) ), 'formaaaaaa' ),
						$this->block(
							'xb/loop',
							array(
								'uid'    => 'loopaaaaaa',
								'source' => array(
									'kind' => 'preset',
									'id'   => 'posts',
								),
								'as'     => 'post',
							),
							array(
								$this->el( 'h3', array(), array( $this->el( 'a', array( 'href' => '{post.url}' ), array( $this->text( '{post.title}' ) ) ) ), 'h3aaaaaaaa' ),
								$this->el( 'p', array( 'class' => 'prezzo' ), array( $this->text( 'Prezzo: {post.fields.prezzo} €' ) ), 'prezzoaaaa' ),
								$this->el( 'span', array(), array( $this->text( '{post.date.date(\'j F Y\')}' ) ), 'dataaaaaaa' ),
								$this->block( 'xb/component', array( 'uid' => 'loopcompaa', 'ref' => 'test/scheda', 'props' => array( 'titolo' => array( 'expr' => 'post.title' ) ) ) ),
								$this->el( 'a', array( 'href' => '{post.url}', 'class' => 'scheda' ), array( $this->el( 'div', array(), array( $this->el( 'img', array( 'src' => '{post.image.url}' ), array(), 'loopimgaaa' ) ) ) ), 'cardlinkaa' ),
							)
						),
						$this->block(
							'xb/loop',
							array(
								'uid'    => 'termsaaaaa',
								'source' => array(
									'kind' => 'preset',
									'id'   => 'terms',
								),
								'as'     => 't',
							),
							array( $this->el( 'span', array(), array( $this->text( '{t.name}' ) ) ) )
						),
						$this->block( 'xb/if', array( 'uid' => 'ifaaaaaaaa' ), array( $this->block( 'xb/branch', array( 'test' => 'user.loggedIn' ), array( $this->el( 'p', array(), array( $this->text( 'Ciao' ) ) ) ) ) ) ),
						$this->block( 'xb/component', array( 'uid' => 'compaaaaaa', 'ref' => 'test/scheda', 'props' => array( 'titolo' => 'Uno' ) ) ),
						$this->block( 'xb/component', array( 'uid' => 'compbbbbbb', 'ref' => 'test/riquadro', 'props' => array( 'tono' => 'chiaro' ) ), array( $this->block( 'xb/slot-fill', array( 'name' => 'default' ), array( $this->el( 'p', array(), array( $this->text( 'Dentro' ) ) ) ) ) ) ),
						$this->block( 'xb/passthrough', array( 'uid' => 'passaaaaaa' ), array( $this->block( 'core/separator', array() ) ) ),
					)
				),
			)
		);
		$before = get_post( $id );
		$entry  = $this->entry( $id );

		$this->assertSame( 'contenuto', $entry['kind'] );
		$this->assertSame( array( Analyzer::FONDAMENTA, 'fondamenta/elemento' ), array( self::item( $entry, 'secaaaaaaa' )['dest'], self::item( $entry, 'secaaaaaaa' )['target'] ) );
		// In a page `this` is that page: the title is written, the heading stays a core heading.
		$this->assertSame( 'core/heading', self::item( $entry, 'h1aaaaaaaa' )['target'] );
		$this->assertSame( 'core/paragraph', self::item( $entry, 'paaaaaaaaa' )['target'] );
		$this->assertSame( 'fondamenta/elemento', self::item( $entry, 'imgaaaaaaa' )['target'] );
		$this->assertSame( 'core/list', self::item( $entry, 'ulaaaaaaaa' )['target'] );
		$svg = self::item( $entry, 'svgaaaaaaa' );
		$this->assertSame( array( Analyzer::FROZEN, 'core/html', 2 ), array( $svg['dest'], $svg['target'], $svg['elements'] ) );
		$this->assertSame( Analyzer::FROZEN, self::item( $entry, 'rawaaaaaaa' )['dest'] );
		$form = self::item( $entry, 'formaaaaaa' );
		$this->assertSame( Analyzer::LOST, $form['dest'] );
		$this->assertStringContainsString( 'Fluent Forms', $form['reason'] );

		// The loop on posts is a Fondamenta query; inside it the title link and the date are core dynamic blocks,
		// a field in mixed text is lost.
		$this->assertSame( array( Analyzer::DYNAMIC, 'fondamenta/loop' ), array( self::item( $entry, 'loopaaaaaa' )['dest'], self::item( $entry, 'loopaaaaaa' )['target'] ) );
		$this->assertSame( array( Analyzer::DYNAMIC, 'fondamenta/elemento' ), array( self::item( $entry, 'h3aaaaaaaa' )['dest'], self::item( $entry, 'h3aaaaaaaa' )['target'] ) );
		$this->assertStringContainsString( 'title', self::item( $entry, 'h3aaaaaaaa' )['reason'] );
		$this->assertSame( Analyzer::DYNAMIC, self::item( $entry, 'dataaaaaaa' )['dest'] );
		// A component fed by the loop is expanded, and its {props.titolo} reads as {post.title}.
		$fed = self::item( $entry, 'loopcompaa' );
		$this->assertSame( Analyzer::UNROLLED, $fed['dest'] );
		$this->assertStringContainsString( 'props calcolate', $fed['reason'] );
		$titles = array_filter( $entry['items'], static fn ( array $i ): bool => Analyzer::DYNAMIC === $i['dest'] && str_contains( $i['reason'], 'title' ) && str_contains( $i['path'], 'test/scheda' ) );
		$this->assertCount( 1, $titles );
		// A link around a whole card keeps its element, with its address as a legame; so does the image inside.
		$card = self::item( $entry, 'cardlinkaa' );
		$this->assertSame( array( Analyzer::DYNAMIC, 'fondamenta/elemento' ), array( $card['dest'], $card['target'] ) );
		$this->assertStringContainsString( 'url', $card['reason'] );
		$this->assertStringContainsString( 'image.url', self::item( $entry, 'loopimgaaa' )['reason'] );
		// A value inside written text is a legame too.
		$this->assertSame( Analyzer::DYNAMIC, self::item( $entry, 'prezzoaaaa' )['dest'] );
		$this->assertContains( 'post.fields.prezzo', array_column( self::notes( $entry, Analyzer::BOUND ), 'expr' ) );
		$lost = self::notes( $entry, Analyzer::DROPPED );
		$this->assertContains( 'user.loggedIn', array_column( $lost, 'expr' ) );
		$this->assertContains( 'this.title', array_column( self::notes( $entry, Analyzer::WRITTEN ), 'expr' ) );

		$this->assertSame( Analyzer::FROZEN, self::item( $entry, 'termsaaaaa' )['dest'] );
		$this->assertSame( array( 'risolte' => 0, 'se' => 0, 'perse' => 1 ), $entry['conditions'] );
		$this->assertSame( array( Analyzer::PATTERN, 'core/block' ), array( self::item( $entry, 'compaaaaaa' )['dest'], self::item( $entry, 'compaaaaaa' )['target'] ) );
		$box = self::item( $entry, 'compbbbbbb' );
		$this->assertSame( Analyzer::UNROLLED, $box['dest'] );
		$this->assertStringContainsString( 'slot', $box['reason'] );
		$this->assertSame( array( Analyzer::GUTENBERG, 'core/separator' ), array( self::item( $entry, 'passaaaaaa' )['dest'], self::item( $entry, 'passaaaaaa' )['target'] ) );
		$this->assertSame( array( Analyzer::PATTERN => 1, Analyzer::UNROLLED => 2 ), $entry['components'] );
		$this->assertGreaterThan( 0.5, $entry['share'] );
		$this->assertLessThan( 1.0, $entry['share'] );

		// Nothing written.
		$after = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $before );
		$this->assertInstanceOf( \WP_Post::class, $after );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
	}

	public function test_template_expressions_and_content(): void {
		$id    = $this->post(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'single-prova-uscita',
				'post_title'   => 'Prova uscita',
				'post_content' => $this->markup(
					array(
						$this->el(
							'main',
							array(),
							array(
								$this->el( 'h1', array(), array( $this->text( '{this.title}' ) ), 'tth1aaaaaa' ),
								$this->el( 'p', array(), array( $this->text( '{this.fields.sottotitolo}' ) ), 'ttsubaaaaa' ),
								$this->el( 'p', array(), array( $this->text( '© {site.name}' ) ), 'ttsiteaaaa' ),
								$this->el( 'p', array( 'class' => 'x--{this.slug}' ), array( $this->text( 'Fisso' ) ), 'ttclassaaa' ),
								$this->el( 'ul', array(), array( $this->block( 'xb/loop', array( 'uid' => 'tttermsaaa', 'source' => array( 'kind' => 'expr', 'expr' => 'this.terms.category' ), 'as' => 'c' ), array( $this->el( 'li', array(), array( $this->text( '{c.name}' ) ) ) ) ) ) ),
								$this->block( 'xb/loop', array( 'uid' => 'ttpagesaaa', 'source' => array( 'kind' => 'expr', 'expr' => 'props.page.pageLinks' ), 'as' => 'l' ), array( $this->el( 'a', array( 'href' => '{l.url}' ), array( $this->text( '{l.number}' ) ) ) ) ),
								$this->el( 'p', array(), array( $this->text( '{this.title.upper()}' ) ), 'ttupperaaa' ),
								$this->block( 'xb/post-content', array( 'uid' => 'ttcontaaaa' ) ),
							)
						),
					)
				),
			)
		);
		wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
		$entry = $this->entry( $id );
		$this->assertSame( 'template', $entry['kind'] );
		$this->assertSame( array( Analyzer::DYNAMIC, 'fondamenta/elemento' ), array( self::item( $entry, 'tth1aaaaaa' )['dest'], self::item( $entry, 'tth1aaaaaa' )['target'] ) );
		$this->assertStringContainsString( 'fields.sottotitolo', self::item( $entry, 'ttsubaaaaa' )['reason'] );
		$this->assertSame( 'core/paragraph', self::item( $entry, 'ttsiteaaaa' )['target'] );
		$this->assertSame( 'fondamenta/contenuto', self::item( $entry, 'ttcontaaaa' )['target'] );
		$this->assertSame( array( Analyzer::DYNAMIC, 'fondamenta/loop (termini)', 2 ), array( self::item( $entry, 'tttermsaaa' )['dest'], self::item( $entry, 'tttermsaaa' )['target'], self::item( $entry, 'tttermsaaa' )['elements'] ) );
		$this->assertSame( 'fondamenta/loop (pagine)', self::item( $entry, 'ttpagesaaa' )['target'] );
		// A computed class and the upper() format are legami now.
		$this->assertStringContainsString( 'slug', self::item( $entry, 'ttclassaaa' )['reason'] );
		$this->assertStringContainsString( 'title', self::item( $entry, 'ttupperaaa' )['reason'] );
		$this->assertSame( array(), self::notes( $entry, Analyzer::DROPPED ) );
		$this->assertContains( 'site.name', array_column( self::notes( $entry, Analyzer::WRITTEN ), 'expr' ) );
	}

	public function test_component_definition_says_synced_or_expanded(): void {
		$plain = $this->component( 'test/semplice', array( $this->el( 'p', array(), array( $this->text( '{props.testo}' ) ) ) ) );
		$slot  = $this->component( 'test/con-slot', array( $this->el( 'div', array(), array( $this->block( 'xb/slot', array() ) ) ) ) );
		$this->assertSame( array( 'synced' => true, 'reason' => '' ), $this->entry( $plain )['pattern'] );
		$this->assertFalse( $this->entry( $slot )['pattern']['synced'] );
		// A prop as the whole text of a paragraph is an override of the synced pattern.
		$this->assertSame( 'override del pattern', self::notes( $this->entry( $plain ), Analyzer::BOUND )[0]['target'] );
	}

	public function test_whole_site_fingerprint_is_stable_and_needs_permission(): void {
		$this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'p', array(), array( $this->text( 'Uno' ) ) ) ) ),
			)
		);
		$one = ( new Report() )->build();
		$two = ( new Report() )->build();
		$this->assertSame( $one['fingerprint'], $two['fingerprint'] );
		$this->assertSame( Report::FORMAT, $one['format'] );
		$this->assertGreaterThan( 0, $one['summary']['elements'] );
		$this->assertSame( get_stylesheet(), $one['site']['theme'] );
		$this->assertNotFalse( json_decode( Report::file( $one ), true ) );

		wp_set_current_user( $this->user( 'editor' ) );
		$this->expectException( \RuntimeException::class );
		( new Report() )->build();
	}

	public function test_unknown_document(): void {
		$id = $this->post( array( 'post_content' => '<!-- wp:paragraph --><p>Solo Gutenberg</p><!-- /wp:paragraph -->' ) );
		$this->expectException( \RuntimeException::class );
		( new Report() )->build( $id );
	}
}
