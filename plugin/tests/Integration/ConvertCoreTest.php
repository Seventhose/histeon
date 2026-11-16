<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Export\Core\Prova;

/**
 * The conversion to Fondamenta's blocks and core text blocks (docs/spec/export-core.md §11-§12): what Histeon draws
 * and what WordPress draws from the converted blocks are the same HTML.
 */
final class ConvertCoreTest extends IntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! Prova::ready() ) {
			$this->markTestSkipped( 'Serve Fondamenta 0.2 attiva nel WordPress di sviluppo.' );
		}
		\XB\Export\Core\Converter::forget_patterns();
	}

	/** @param array<string, mixed> $result */
	private function assertSame_html( array $result ): void {
		$this->assertContains( $result['esito'], array( 'uguale', 'classi del core' ), implode( "\n", $result['differenze'] ) . "\n--- prima\n" . $result['prima'] . "\n--- dopo\n" . $result['dopo'] );
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private static function names( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$out[] = (string) $block['blockName'];
			array_push( $out, ...self::names( $block['innerBlocks'] ) );
		}
		return $out;
	}

	public function test_static_page_is_drawn_the_same(): void {
		$this->component( 'prova/scheda', array( $this->el( 'div', array( 'class' => 'scheda scheda--{props.tono}' ), array( $this->el( 'h3', array( 'class' => 'scheda__titolo' ), array( $this->text( '{props.titolo}' ) ) ), $this->block( 'xb/slot', array() ) ) ) ) );
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Prova conversione',
				'post_content' => $this->markup(
					array(
						$this->el(
							'section',
							array( 'class' => 'hero', 'data-xb-reveal' => 'up' ),
							array(
								$this->el( 'h1', array( 'class' => 'hero__title' ), array( $this->text( '{this.title}' ) ) ),
								$this->el( 'p', array( 'class' => 'hero__lead' ), array( $this->text( 'Un testo con ' ), $this->el( 'strong', array(), array( $this->text( 'forza' ) ) ), $this->text( ' e un "apice".' ) ) ),
								$this->el( 'a', array( 'class' => 'pulsante', 'href' => '/contatti/' ), array( $this->text( 'Scrivici' ) ) ),
								$this->el( 'img', array( 'src' => '/foto.jpg', 'alt' => 'Foto', 'loading' => 'lazy' ) ),
								$this->el( 'ul', array( 'class' => 'elenco' ), array( $this->el( 'li', array(), array( $this->text( 'uno' ) ) ), $this->el( 'li', array( 'class' => 'x' ), array( $this->text( 'due' ) ) ) ) ),
							)
						),
						$this->el( 'div', array( 'style' => 'color:red', 'aria-label' => 'riquadro' ), array( $this->text( 'Testo ' ), $this->el( 'div', array(), array( $this->text( 'dentro' ) ) ) ) ),
						$this->el( 'svg', array( 'viewBox' => '0 0 10 10' ), array( $this->el( 'path', array( 'd' => 'M0 0h10' ) ) ) ),
						$this->block( 'xb/raw', array( 'content' => '<hr class="riga">' ) ),
						$this->block( 'xb/passthrough', array(), array( $this->block( 'core/separator', array() ) ) ),
						$this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => 'user.loggedIn' ), array( $this->el( 'p', array(), array( $this->text( 'Bentornato' ) ) ) ) ), $this->block( 'xb/branch', array(), array( $this->el( 'p', array(), array( $this->text( 'Ciao, visitatore' ) ) ) ) ) ) ),
						$this->block(
							'xb/loop',
							array(
								'source' => array(
									'kind' => 'expr',
									'expr' => '[{t: "alfa"}, {t: "beta"}]',
								),
								'as'     => 'v',
							),
							array( $this->el( 'span', array( 'class' => 'voce voce--{v.t}' ), array( $this->text( '{v.t}' ) ) ) )
						),
						$this->block( 'xb/component', array( 'ref' => 'prova/scheda', 'props' => array( 'titolo' => 'Prima', 'tono' => 'chiaro' ) ), array( $this->block( 'xb/slot-fill', array( 'name' => 'default' ), array( $this->el( 'p', array(), array( $this->text( 'Nel riquadro' ) ) ) ) ) ) ),
					)
				),
			)
		);
		$post   = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$result = Prova::compare( $post );
		$this->assertSame_html( $result );
		$this->assertStringContainsString( 'Ciao, visitatore', $result['dopo'] );
		$this->assertStringNotContainsString( 'Bentornato', $result['dopo'] );

		$converted = Prova::convert( $post );
		$names     = self::names( $converted['blocks'] );
		$this->assertNotContains( 'xb/element', $names );
		foreach ( array( 'fondamenta/elemento', 'core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/html', 'core/separator' ) as $name ) {
			$this->assertContains( $name, $names );
		}
		// The markup reads back the same.
		$markup = \XB\Blocks::serialize( $converted['blocks'] );
		$this->assertSame( $markup, \XB\Blocks::serialize( array_values( parse_blocks( $markup ) ) ) );
		$this->assertStringNotContainsString( 'wp:xb/', $markup );
	}

	public function test_loop_of_posts_with_bound_values(): void {
		$image = self::upload_image();
		$ids   = array();
		foreach ( array( 'Primo articolo' => '2026-01-10 10:00:00', 'Secondo articolo' => '2026-02-20 10:00:00' ) as $title => $date ) {
			$post = $this->post(
				array(
					'post_title'   => $title,
					'post_date'    => $date,
					'post_excerpt' => 'Primo articolo' === $title ? 'Un riassunto' : '',
					'post_content' => 'Testo',
					'post_category' => array( 1 ),
				)
			);
			$ids[] = $post;
			update_post_meta( $post, 'prezzo', 'Primo articolo' === $title ? '12' : '30' );
			if ( 'Secondo articolo' === $title ) {
				set_post_thumbnail( $post, $image );
			}
		}
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->el(
							'ul',
							array( 'class' => 'schede' ),
							array(
								$this->block(
									'xb/loop',
									array(
										'source' => array(
											'kind' => 'preset',
											'id'   => 'posts',
											'args' => array( 'limit' => '5', 'include' => '[' . implode( ', ', $ids ) . ']' ),
										),
										'as'     => 'x',
									),
									array(
										$this->el(
											'li',
											array( 'class' => 'scheda scheda--{x.slug}' ),
											array(
												$this->el( 'h3', array(), array( $this->el( 'a', array( 'href' => '{x.url}' ), array( $this->text( '{x.title}' ) ) ) ) ),
												$this->el( 'time', array( 'datetime' => '{x.date}' ), array( $this->text( '{x.date.date(\'j F Y\')}' ) ) ),
												$this->el( 'p', array( 'class' => 'prezzo' ), array( $this->text( 'Prezzo: {x.meta.prezzo} €' ) ) ),
												$this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => 'x.image' ), array( $this->el( 'img', array( 'src' => '{x.image.url}', 'alt' => '{x.image.alt}' ) ) ) ) ) ),
												$this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => 'x.excerpt' ), array( $this->el( 'p', array(), array( $this->text( '{x.excerpt.truncateWords(2)}' ) ) ) ) ), $this->block( 'xb/branch', array(), array( $this->el( 'p', array(), array( $this->text( 'Nessun riassunto' ) ) ) ) ) ) ),
												$this->el( 'ul', array(), array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'x.categories' ), 'as' => 'c' ), array( $this->el( 'li', array(), array( $this->el( 'a', array( 'href' => '{c.url}' ), array( $this->text( '{c.name}' ) ) ) ) ) ) ) ) ),
											)
										),
									)
								),
							)
						),
					)
				),
			)
		);
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$converted = Prova::convert( $post );
		$names     = self::names( $converted['blocks'] );
		$this->assertContains( 'fondamenta/loop', $names );
		$this->assertContains( 'fondamenta/se', $names );
		// `x.meta.prezzo` has no legame: the note says so, the rest of the card converts.
		$this->assertNotEmpty( array_filter( $converted['notes'], static fn ( array $n ): bool => 'perso' === $n['cosa'] ) );
		$result = Prova::compare( $post );
		$this->assertStringContainsString( 'Primo articolo', $result['dopo'] );
		$this->assertStringContainsString( 'Un riassunto', $result['dopo'] );
		$this->assertStringContainsString( 'alt="Alt della foto"', $result['dopo'] );
		$this->assertStringContainsString( 'width="1"', $result['dopo'] );
		// Everything but the price (a value of `meta`, which has no legame) is drawn as Histeon draws it.
		$strip = static fn ( string $html ): string => (string) preg_replace( '#<p class="prezzo">.*?</p>#', '', $html );
		$this->assertSame( Prova::canonical( $strip( $result['prima'] ), true ), Prova::canonical( $strip( $result['dopo'] ), true ) );
	}

	public function test_loop_with_fields_is_drawn_the_same(): void {
		$ids = array();
		foreach ( array( 'Uno' => '12', 'Due' => '30' ) as $title => $price ) {
			$post  = $this->post( array( 'post_title' => $title ) );
			$ids[] = $post;
			update_post_meta( $post, 'prezzo', $price );
		}
		$this->set_option(
			\XB\Content\ContentTypes::OPTION,
			array(
				'types'      => array(),
				'taxonomies' => array(),
				'fields'     => array( 'post' => array( array( 'name' => 'prezzo', 'label' => 'Prezzo', 'type' => 'text' ) ) ),
			)
		);
		\XB\Content\ContentTypes::forget();
		$id   = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->block(
							'xb/loop',
							array(
								'source' => array(
									'kind' => 'preset',
									'id'   => 'posts',
									'args' => array( 'limit' => '2', 'orderby' => '"title"', 'order' => '"asc"', 'include' => '[' . implode( ', ', $ids ) . ']' ),
								),
								'as'     => 'x',
							),
							array(
								$this->el(
									'article',
									array( 'class' => 'voce' ),
									array(
										$this->el( 'h2', array( 'class' => 'voce__titolo' ), array( $this->el( 'a', array( 'href' => '{x.url}' ), array( $this->text( '{x.title}' ) ) ) ) ),
										$this->el( 'p', array(), array( $this->text( 'Prezzo: {x.fields.prezzo} €' ) ) ),
										$this->el( 'span', array( 'class' => 'voce__tipo voce__tipo--{x.type}' ), array( $this->text( '{x.title.upper()}' ) ) ),
									)
								),
							)
						),
					)
				),
			)
		);
		$post = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$result = Prova::compare( $post );
		$this->assertSame_html( $result );
		$this->assertStringContainsString( 'Prezzo: 30 €', $result['dopo'] );
		$this->assertStringContainsString( 'Prezzo: 12 €', $result['dopo'] );
		$this->assertStringContainsString( 'voce__tipo--post', $result['dopo'] );
		$this->assertSame( array(), array_filter( $result['notes'], static fn ( array $n ): bool => 'perso' === $n['cosa'] ) );
	}

	public function test_template_is_drawn_the_same_with_its_sample(): void {
		$this->post(
			array(
				'post_title'    => 'Articolo campione',
				'post_content'  => '<!-- wp:paragraph --><p>Il testo del campione.</p><!-- /wp:paragraph -->',
				'post_category' => array( 1 ),
			)
		);
		$id = $this->post(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'single-post',
				'post_title'   => 'Prova conversione',
				'post_content' => $this->markup(
					array(
						$this->el(
							'main',
							array( 'class' => 'pagina' ),
							array(
								$this->el( 'h1', array( 'class' => 'pagina__titolo' ), array( $this->text( '{this.title}' ) ) ),
								$this->el( 'p', array( 'class' => 'pagina__sito' ), array( $this->text( '© {site.name}' ) ) ),
								$this->el( 'ul', array( 'class' => 'categorie' ), array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.categories' ), 'as' => 'c' ), array( $this->el( 'li', array(), array( $this->el( 'a', array( 'href' => '{c.url}' ), array( $this->text( '{c.name}' ) ) ) ) ) ) ) ) ),
								$this->el( 'div', array( 'class' => 'pagina__testo' ), array( $this->block( 'xb/post-content', array() ) ) ),
							)
						),
					)
				),
			)
		);
		wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
		$template = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $template );
		$result = Prova::compare( $template );
		$this->assertSame_html( $result );
		$this->assertStringContainsString( 'Articolo campione', $result['dopo'] );
		$this->assertContains( 'fondamenta/contenuto', self::names( Prova::convert( $template )['blocks'] ) );
	}

	public function test_conditions_page_values_and_lists_of_values(): void {
		$this->set_option(
			\XB\Content\ContentTypes::OPTION,
			array(
				'types'      => array(),
				'taxonomies' => array(),
				'fields'     => array( 'post' => array( array( 'name' => 'anni', 'label' => 'Anni', 'type' => 'integer', 'multiple' => true ) ) ),
			)
		);
		\XB\Content\ContentTypes::forget();
		$sample = $this->post( array( 'post_title' => 'Campione con anni', 'post_name' => 'campione-anni' ) );
		update_post_meta( $sample, 'anni', array( 2019, 2021 ) );
		$id = $this->post(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'single-post',
				'post_title'   => 'Prova condizioni',
				'post_content' => $this->markup(
					array(
						$this->el(
							'nav',
							array( 'class' => 'menu' ),
							array(
								$this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => 'url.path == "/festival/" || url.path.includes("/campione")' ), array( $this->el( 'a', array( 'aria-current' => 'page', 'href' => '/festival/' ), array( $this->text( 'Festival' ) ) ) ) ), $this->block( 'xb/branch', array(), array( $this->el( 'a', array( 'href' => '/festival/' ), array( $this->text( 'Festival' ) ) ) ) ) ) ),
								$this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => 'props.page.slug == "campione-anni"' ), array( $this->el( 'span', array( 'class' => 'qui' ), array( $this->text( 'Sei qui' ) ) ) ) ) ) ),
								$this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => '!this.excerpt' ), array( $this->el( 'span', array(), array( $this->text( 'Senza riassunto' ) ) ) ) ) ) ),
							)
						),
						$this->el( 'ul', array( 'class' => 'anni' ), array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'this.fields.anni' ), 'as' => 'a' ), array( $this->el( 'li', array(), array( $this->el( 'a', array( 'href' => '/edizione-{a}/' ), array( $this->text( '{a}' ) ) ) ) ) ) ) ) ),
						$this->el( 'p', array( 'class' => 'sigla' ), array( $this->text( '{this.slug.slice(0, 8).upper()}' ) ) ),
					)
				),
			)
		);
		wp_set_post_terms( $id, array( get_stylesheet() ), 'wp_theme' );
		$template = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $template );
		// The sample of a single template is a published post: this one, the newest.
		$saved = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/campione-anni/';
		try {
			$result = Prova::compare( $template );
		} finally {
			$_SERVER['REQUEST_URI'] = $saved;
		}
		$this->assertSame_html( $result );
		$this->assertSame( array(), array_filter( $result['notes'], static fn ( array $n ): bool => 'perso' === $n['cosa'] ) );
		$this->assertStringContainsString( 'aria-current="page"', $result['dopo'] );
		$this->assertStringContainsString( '/edizione-2021/', $result['dopo'] );
		$this->assertStringContainsString( 'CAMPIONE', $result['dopo'] );
		$names = self::names( Prova::convert( $template )['blocks'] );
		$this->assertContains( 'fondamenta/se', $names );
		$this->assertContains( 'fondamenta/loop', $names );
	}

	public function test_a_component_becomes_a_synced_pattern_with_overrides(): void {
		$id = $this->component(
			'prova/carta',
			array(
				$this->el(
					'article',
					array( 'class' => 'carta' ),
					array(
						$this->el( 'h3', array( 'class' => 'carta__titolo' ), array( $this->text( '{props.titolo}' ) ) ),
						$this->el( 'a', array( 'class' => 'carta__link', 'href' => '{props.url}' ), array( $this->text( '{props.etichetta}' ) ) ),
						$this->el( 'p', array( 'class' => 'carta__fisso' ), array( $this->text( 'Sempre uguale' ) ) ),
						$this->el( 'p', array( 'class' => 'carta__nota' ), array( $this->el( 'span', array( 'class' => 'carta__tipo' ), array( $this->text( 'PDF' ) ) ), $this->el( 'span', array(), array( $this->text( '{props.nota}' ) ) ) ) ),
					)
				),
			),
			array(
				'titolo'    => array( 'type' => 'string', 'default' => 'Titolo' ),
				'url'       => array( 'type' => 'url', 'default' => '/' ),
				'etichetta' => array( 'type' => 'string', 'default' => 'Leggi' ),
				'nota'      => array( 'type' => 'string', 'default' => '' ),
			)
		);
		$page = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup(
					array(
						$this->block( 'xb/component', array( 'ref' => 'prova/carta', 'props' => array( 'titolo' => 'Prima & seconda', 'url' => '/uno/', 'etichetta' => 'Vai', 'nota' => 'Una nota' ) ) ),
						$this->block( 'xb/component', array( 'ref' => 'prova/carta', 'props' => array( 'titolo' => 'Altra' ) ) ),
					)
				),
			)
		);
		$post = get_post( $page );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$converted = Prova::convert( $post );
		$this->assertSame( array( 'core/block', 'core/block' ), self::names( $converted['blocks'] ) );
		$this->assertSame( $id, $converted['blocks'][0]['attrs']['ref'] );
		$this->assertSame( array( 'contenuto' => 'Vai', 'href' => '/uno/' ), $converted['blocks'][0]['attrs']['content']['etichetta · url'] );
		$this->assertSame( 'Prima &amp; seconda', $converted['blocks'][0]['attrs']['content']['titolo']['content'] );
		// A prop in an inline element among others: that element is a block of its own, and is overridden.
		$this->assertSame( 'Una nota', $converted['blocks'][0]['attrs']['content']['nota']['contenuto'] );
		// Only what the instance gives is overridden: the rest shows the pattern's defaults.
		$this->assertSame( array( 'titolo' ), array_keys( $converted['blocks'][1]['attrs']['content'] ) );
		// The pattern: the component's own post, converted, its overridable blocks named and bound.
		$definition = get_post( $id );
		$this->assertInstanceOf( \WP_Post::class, $definition );
		$pattern = Prova::convert( $definition );
		$markup  = \XB\Blocks::serialize( $pattern['blocks'] );
		$this->assertStringContainsString( '"metadata":{"name":"titolo","bindings":{"__default":{"source":"core/pattern-overrides"}}}', $markup );
		$this->assertStringContainsString( '"href":"/"', $markup );
		$this->assertStringNotContainsString( 'wp:xb/', $markup );
		// Drawn with the overrides: the same HTML as Histeon's two instances.
		$this->assertSame_html( Prova::compare( $post ) );
	}

	/** D6 of doc 25: a filtered list (the builder's facets) is Fondamenta's filters: same HTML with any parameters. */
	public function test_a_filtered_list_is_drawn_the_same_with_any_parameters(): void {
		register_post_type( 'xbt_cfac', array( 'public' => true ) );
		register_taxonomy( 'xbt_ctema', 'xbt_cfac', array( 'public' => true ) );
		$terms = array();
		try {
			foreach ( array( 'xbt-ca' => 'Alfa', 'xbt-cb' => 'Beta' ) as $slug => $name ) {
				$term            = wp_insert_term( $name, 'xbt_ctema', array( 'slug' => $slug ) );
				$terms[ $slug ]  = is_array( $term ) ? (int) $term['term_id'] : 0;
			}
			foreach ( array( 'A Uno', 'A Due', 'A Tre', 'A Quattro', 'B Cinque', 'B Sei' ) as $title ) {
				$id = $this->post( array( 'post_type' => 'xbt_cfac', 'post_title' => $title ) );
				wp_set_object_terms( $id, array( $terms[ str_starts_with( $title, 'A' ) ? 'xbt-ca' : 'xbt-cb' ] ), 'xbt_ctema' );
			}
			$definition = array(
				'query'   => 'posts',
				'type'    => 'xbt_cfac',
				'perPage' => 2,
				'filters' => array(
					array( 'kind' => 'search', 'key' => 'q', 'label' => 'Cerca' ),
					array( 'kind' => 'taxonomy', 'key' => 'tema', 'label' => 'Tema', 'taxonomy' => 'xbt_ctema', 'multiple' => true ),
					array( 'kind' => 'sort', 'key' => 'ordine', 'label' => 'Ordina', 'options' => array( array( 'value' => 'titolo', 'label' => 'Titolo', 'orderby' => 'title', 'order' => 'asc' ) ) ),
				),
			);
			$this->set_option( \XB\Render\Facets::OPTION, \XB\Render\Facets::validate( array( 'temi' => $definition ) ) );
			$f      = 'facets.temi';
			$branch = fn ( string $test, array $inner ): array => $this->block( 'xb/if', array(), array( $this->block( 'xb/branch', array( 'test' => $test ), array_values( $inner ) ) ) );
			$loop   = fn ( string $expr, string $as, array $inner ): array => $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => $expr ), 'as' => $as ), array_values( $inner ) );
			$page   = $this->post(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Filtri',
					'post_content' => $this->markup(
						array(
							$this->el(
								'form',
								array( 'class' => 'xb-facets__form', 'method' => 'get', 'action' => '{' . $f . '.action}', 'data-xb-facet' => 'temi' ),
								array(
									$loop( $f . '.hidden', 'h', array( $this->el( 'input', array( 'type' => 'hidden', 'name' => '{h.name}', 'value' => '{h.value}' ) ) ) ),
									$this->el( 'label', array(), array( $this->el( 'span', array(), array( $this->text( 'Cerca' ) ) ), $this->el( 'input', array( 'type' => 'search', 'name' => 'q', 'value' => '{' . $f . '.values.q}' ) ) ) ),
									$this->el(
										'fieldset',
										array(),
										array(
											$this->el( 'legend', array(), array( $this->text( '{' . $f . '.filters.tema.label}' ) ) ),
											$loop( $f . '.filters.tema.options', 'o', array( $this->el( 'label', array( 'class' => 'opzione' ), array( $this->el( 'input', array( 'type' => 'checkbox', 'name' => 'tema[]', 'value' => '{o.value}', 'checked' => '{o.selected}' ) ), $this->text( ' {o.label} ' ), $this->el( 'span', array(), array( $this->text( '({o.count})' ) ) ) ) ) ) ),
										)
									),
									$this->el( 'select', array( 'name' => 'ordine' ), array( $this->el( 'option', array( 'value' => '' ), array( $this->text( 'Predefinito' ) ) ), $loop( $f . '.filters.ordine.options', 'o', array( $this->el( 'option', array( 'value' => '{o.value}', 'selected' => '{o.selected}' ), array( $this->text( '{o.label}' ) ) ) ) ) ) ),
								)
							),
							$this->el(
								'div',
								array( 'data-xb-facet' => 'temi' ),
								array(
									$this->el( 'p', array(), array( $this->el( 'span', array( 'data-xb-facet-total' => 'temi' ), array( $this->text( '{' . $f . '.total}' ) ) ), $this->text( ' risultati' ) ) ),
									$branch( $f . '.hasFilters', array( $this->el( 'a', array( 'class' => 'azzera', 'href' => '{' . $f . '.resetUrl}' ), array( $this->text( 'Azzera i filtri' ) ) ) ) ),
									$this->el( 'ul', array( 'class' => 'attivi' ), array( $loop( $f . '.active', 'a', array( $this->el( 'li', array(), array( $this->el( 'a', array( 'href' => '{a.url}' ), array( $this->text( '{a.label}: {a.text}' ) ) ) ) ) ) ) ) ),
									$this->el( 'ul', array( 'class' => 'risultati' ), array( $loop( $f . '.items', 'item', array( $this->el( 'li', array(), array( $this->el( 'a', array( 'href' => '{item.url}' ), array( $this->text( '{item.title}' ) ) ) ) ) ) ) ) ),
									$branch(
										$f . '.pages > 1',
										array(
											$this->el(
												'nav',
												array( 'class' => 'pagine' ),
												array(
													$loop(
														$f . '.pageLinks',
														'p',
														array(
															$this->block(
																'xb/if',
																array(),
																array(
																	$this->block( 'xb/branch', array( 'test' => 'p.number' ), array( $this->el( 'a', array( 'href' => '{p.url}', 'aria-current' => '{p.current ? "page" : null}' ), array( $this->text( '{p.number}' ) ) ) ) ),
																	$this->block( 'xb/branch', array(), array( $this->el( 'span', array(), array( $this->text( '…' ) ) ) ) ),
																)
															),
														)
													),
												)
											),
										)
									),
								)
							),
						)
					),
				)
			);
			$post = get_post( $page );
			$this->assertInstanceOf( \WP_Post::class, $post );
			$converted = Prova::convert( $post );
			$markup    = \XB\Blocks::serialize( $converted['blocks'] );
			$this->assertStringNotContainsString( 'wp:xb/', $markup );
			$this->assertStringContainsString( '"fonte":"filtri","facet":"temi","elenco":"opzioni","filtro":"tema"', $markup );
			$this->assertStringContainsString( '"modi":["se:page"]', $markup );
			foreach ( array( array(), array( 'tema' => 'xbt-ca' ), array( 'tema' => array( 'xbt-ca', 'xbt-cb' ), 'ordine' => 'titolo', 'pagina' => '2' ), array( 'q' => 'Uno' ), array( 'pagina' => '9', 'altro' => '<b>' ) ) as $get ) {
				$_GET = $get;
				\XB\Render\Facets::forget();
				$result = Prova::compare( $post );
				$this->assertSame_html( $result );
				$this->assertStringContainsString( 'class="risultati"', $result['dopo'], (string) wp_json_encode( $get ) );
			}
			// The second page of the A results, the options counted with the other filters, the boxes ticked.
			$_GET = array( 'tema' => 'xbt-ca', 'pagina' => '2' );
			\XB\Render\Facets::forget();
			$after = Prova::compare( $post )['dopo'];
			$this->assertStringContainsString( '<span data-xb-facet-total="temi">4</span>', $after );
			$this->assertMatchesRegularExpression( '/<input (checked value="xbt-ca"|[^>]*value="xbt-ca"[^>]* checked)/', $after );
			$this->assertStringContainsString( '<a aria-current="page" href="/?tema=xbt-ca&#038;pagina=2">2</a>', $after );
		} finally {
			$_GET = array();
			\XB\Render\Facets::forget();
			foreach ( $terms as $id ) {
				wp_delete_term( $id, 'xbt_ctema' );
			}
			unregister_taxonomy( 'xbt_ctema' );
			unregister_post_type( 'xbt_cfac' );
		}
	}

	private static function upload_image(): int {
		$file = wp_upload_bits( 'prova-conversione.png', null, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
		$id   = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'Prova', 'post_status' => 'inherit' ), $file['file'] );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Alt della foto' );
		return $id;
	}
}
