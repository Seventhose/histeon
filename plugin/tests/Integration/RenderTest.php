<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;

final class RenderTest extends IntegrationTestCase {

	public function test_elements_text_and_interpolation(): void {
		$html = $this->render(
			array(
				$this->el(
					'section',
					array(
						'class' => 'hero',
						'id'    => 'top',
					),
					array( $this->el( 'p', array(), array( $this->text( 'Benvenuti su <strong>{site.name}</strong> \{letterale\}' ) ) ) )
				),
			)
		);
		$this->assertSame( '<section class="hero" id="top"><p>Benvenuti su <strong>XB Dev</strong> {letterale}</p></section>', $html );
	}

	public function test_a_password_protected_post_shows_no_excerpt_without_the_password(): void {
		$id = $this->post( array( 'post_title' => 'Riservato', 'post_status' => 'publish', 'post_password' => 'chiave', 'post_content' => 'TESTO-RISERVATO e altro', 'post_excerpt' => 'RIASSUNTO-RISERVATO' ) );
		$GLOBALS['post'] = get_post( $id );
		$previous = get_current_user_id();
		wp_set_current_user( 0 );
		$html = $this->render( array( $this->text( '{this.title}|{this.excerpt}|{props.page.excerpt}|{this.meta ? "META-VISIBILE" : "-"}|{this.fields ? "CAMPI-VISIBILI" : "-"}|{this.acf ? "ACF-VISIBILE" : "-"}' ) ) );
		wp_set_current_user( $previous );
		unset( $GLOBALS['post'] );
		$this->assertStringContainsString( 'Riservato', $html );
		$this->assertStringNotContainsString( 'RISERVATO', $html );
		$this->assertStringNotContainsString( 'VISIBIL', $html, 'custom fields are part of what the password protects' );
	}

	/** dsl.md §3.4: inline tags in text carry no interpolations; braces inside a tag are kept as written. */
	public function test_braces_inside_an_inline_tag_are_not_interpolated(): void {
		$id = $this->post( array( 'post_title' => 'probe data-probe=1 onmouseover=alert(1)' ) );
		$GLOBALS['post'] = get_post( $id );
		$html = $this->render(
			array(
				$this->el(
					'p',
					array(),
					array( $this->text( '<abbr title="{this.title}">x</abbr> <a href=\'{"javascript:alert(1)"}\'>y</a> {1<2 ? "sì" : "no"} {this.title}' ) )
				),
			)
		);
		unset( $GLOBALS['post'] );
		$this->assertSame( '<p><abbr title="{this.title}">x</abbr> <a href=\'{"javascript:alert(1)"}\'>y</a> sì probe data-probe=1 onmouseover=alert(1)</p>', $html );
		$processor = new \WP_HTML_Tag_Processor( $html );
		$this->assertTrue( $processor->next_tag( array( 'tag_name' => 'abbr' ) ) );
		$this->assertSame( array( 'title' ), $processor->get_attribute_names_with_prefix( '' ) );
	}

	public function test_expression_output_is_escaped_for_its_context(): void {
		$id = $this->post( array( 'post_title' => '<img src=x onerror=alert(1)>"quote' ) );
		$GLOBALS['post'] = get_post( $id );
		$html = $this->render(
			array(
				$this->el(
					'a',
					array(
						'href'  => '{"javascript:alert(1)"}',
						'title' => '{this.title}',
					),
					array( $this->text( '{this.title}' ) )
				),
			)
		);
		unset( $GLOBALS['post'] );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( 'href=""', $html );
		$this->assertStringNotContainsString( '"quote', $html, 'a quote in the value must not close the attribute' );
	}

	public function test_boolean_attributes_follow_the_expression(): void {
		$html = $this->render(
			array(
				$this->el(
					'input',
					array(
						'type'     => 'checkbox',
						'checked'  => '{true}',
						'disabled' => '{false}',
						'required' => true,
					)
				),
			)
		);
		$this->assertSame( '<input type="checkbox" checked required>', $html );
	}
	public function test_date_and_number_modifiers_use_the_site_locale(): void {
		$this->assertSame( '1,234.50|23/10/2026', $this->render( array( $this->text( '{1234.5.number(2)}|{"2026-10-23".date("d/m/Y")}' ) ) ) );
	}

	public function test_expression_and_modifier_values_match_the_static_runtime(): void {
		$this->assertSame( 'true|false|2|uno due…|0|false', $this->render( array( $this->text( '{[] || "fallback"}|{[] && "fallback"}|{[1,2,3].slice(1,-1).join("|")}|{"uno due tre".truncateWords(2)}|{true.number(0)}|{{"a":1,"b":2} == {"b":2,"a":1}}' ) ) ) );
	}

	public function test_shared_m2_render_fixture_matches_the_php_runtime(): void {
		$json = file_get_contents( __DIR__ . '/../fixtures/histos-runtime-m2-v1.json' );
		if ( false === $json ) {
			throw new \RuntimeException( 'Fixture di rendering M2 non leggibile.' );
		}
		/** @var array{locale: string, timeZone: string, components: list<array{ref: string, title: string, schema: array{props: array<string, array<string, mixed>>}, content: string}>, instance: string, expectedBody: string} $fixture */
		$fixture = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		foreach ( $fixture['components'] as $component ) {
			$this->component( $component['ref'], array_values( parse_blocks( $component['content'] ) ), $component['schema']['props'] );
		}
		$old_timezone = get_option( 'timezone_string' );
		$old_offset   = get_option( 'gmt_offset' );
		update_option( 'timezone_string', $fixture['timeZone'] );
		try {
			$this->assertSame( $fixture['expectedBody'], $this->render( parse_blocks( $fixture['instance'] ) ) );
		} finally {
			update_option( 'timezone_string', $old_timezone );
			update_option( 'gmt_offset', $old_offset );
		}
	}

	public function test_shared_modifier_fixture_matches_the_php_runtime(): void {
		$json = file_get_contents( __DIR__ . '/../fixtures/histos-runtime-modifiers-m2-v1.json' );
		if ( false === $json ) {
			throw new \RuntimeException( 'Fixture condivisa dei modifier M2 non leggibile.' );
		}
		/** @var array{expressions: list<string>, expectedText: string} $fixture */
		$fixture = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$content = '{' . implode( '}|{', $fixture['expressions'] ) . '}';
		$rendered = $this->render( array( $this->text( $content ) ) );
		// esc_html() encodes quotes in PHP text nodes; decode entities to compare the same visible text as TS.
		$this->assertSame( $fixture['expectedText'], html_entity_decode( $rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	public function test_shared_date_fixture_matches_site_timezone_boundaries(): void {
		$json = file_get_contents( __DIR__ . '/../fixtures/histos-runtime-dates-m2-v1.json' );
		if ( false === $json ) {
			throw new \RuntimeException( 'Fixture condivisa delle date M2 non leggibile.' );
		}
		/** @var array{timeZone: string, props: array<string, string>, expressions: list<string>, expectedText: string} $fixture */
		$fixture = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$old_timezone = get_option( 'timezone_string' );
		$old_offset   = get_option( 'gmt_offset' );
		update_option( 'timezone_string', $fixture['timeZone'] );
		try {
			$content = '{' . implode( '}|{', $fixture['expressions'] ) . '}';
			$props = array_map( static fn ( string $value ): array => array( 'type' => 'string', 'default' => $value ), $fixture['props'] );
			$this->component( 'test/date-boundaries', array( $this->el( 'p', array(), array( $this->text( $content ) ) ) ), $props );
			$rendered = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/date-boundaries' ) ) ) );
			$this->assertSame( $fixture['expectedText'], strip_tags( html_entity_decode( $rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		} finally {
			update_option( 'timezone_string', $old_timezone );
			update_option( 'gmt_offset', $old_offset );
		}
	}

	public function test_component_chain_accepts_sixteen_levels_and_stops_at_seventeen(): void {
		$chain = function ( int $count ): array {
			$child_ref = null;
			for ( $index = $count - 1; $index >= 0; --$index ) {
				$ref = sprintf( 'test/c%d-node-n%d', $count, $index );
				$blocks = null === $child_ref
					? array( $this->text( 'Limite rispettato' ) )
					: array( $this->block( 'xb/component', array( 'ref' => $child_ref ) ) );
				$this->component( $ref, $blocks );
				$child_ref = $ref;
			}
			return array( $this->block( 'xb/component', array( 'ref' => (string) $child_ref ) ) );
		};

		$this->assertSame( 'Limite rispettato', $this->render( $chain( 16 ) ) );
		$too_deep = $this->render( $chain( 17 ) );
		$this->assertStringContainsString( 'profondità massima di 16', $too_deep );
		$this->assertStringNotContainsString( 'Limite rispettato', $too_deep, 'il runtime non deve eseguire il componente oltre il limite' );
	}

	public function test_static_html_and_wordpress_blocks_match_the_shared_cms_fixture(): void {
		$json = file_get_contents( __DIR__ . '/../fixtures/histos-runtime-static-m2-v1.json' );
		if ( false === $json ) {
			throw new \RuntimeException( 'Fixture statica M2 non leggibile.' );
		}
		/** @var array{content: string, expectedBody: string} $fixture */
		$fixture = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( $fixture['expectedBody'], $this->render( parse_blocks( $fixture['content'] ) ) );
	}

	public function test_conditions_pick_the_first_true_branch(): void {
		$if = fn ( string $flag ): array => $this->block(
			'xb/if',
			array(),
			array(
				$this->block( 'xb/branch', array( 'test' => $flag . ' == "a"' ), array( $this->text( 'A' ) ) ),
				$this->block( 'xb/branch', array( 'test' => $flag . ' == "b"' ), array( $this->text( 'B' ) ) ),
				$this->block( 'xb/branch', array(), array( $this->text( 'altro' ) ) ),
			)
		);
		$this->assertSame( 'B', $this->render( array( $if( '"b"' ) ) ) );
		$this->assertSame( 'altro', $this->render( array( $if( '"z"' ) ) ) );
	}

	public function test_loops_over_expressions_with_index(): void {
		$loop = $this->block(
			'xb/loop',
			array(
				'source' => array(
					'kind' => 'expr',
					'expr' => '["x", "y"]',
				),
				'as'     => 'item',
				'index'  => 'i',
			),
			array( $this->el( 'li', array( 'data-i' => '{i}' ), array( $this->text( '{item.upper()}' ) ) ) )
		);
		$this->assertSame( '<li data-i="0">X</li><li data-i="1">Y</li>', $this->render( array( $loop ) ) );
	}

	public function test_a_loop_shows_at_most_a_thousand_items_and_says_so_in_the_builder(): void {
		$this->set_option( \XB\Render\Sources::OPTION, get_option( \XB\Render\Sources::OPTION, false ) );
		$render = function ( int $count ): string {
			update_option( \XB\Render\Sources::OPTION, \XB\Render\Sources::validate( array( 'righe' => array( 'data' => array_fill( 0, $count, 'a' ) ) ) ) );
			return $this->render( array( $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'data.righe' ), 'as' => 'x' ), array( $this->text( '.' ) ) ) ) );
		};
		$exact = $render( 1000 );
		$this->assertSame( 1000, substr_count( $exact, '.' ) );
		$this->assertStringNotContainsString( 'il loop supera', $exact );
		$over = $render( 1001 );
		$this->assertSame( 1000, substr_count( (string) preg_replace( '/<!--.*?-->/s', '', $over ), '.' ), 'visitors see the first 1000' );
		$this->assertStringContainsString( 'il loop supera 1000 elementi', $over, 'the builder says why the rest is missing' );
	}

	public function test_loop_depth_accepts_six_levels_and_stops_at_seven(): void {
		$nested = $this->text( 'Limite rispettato' );
		for ( $depth = 0; $depth < 6; ++$depth ) {
			$nested = $this->block(
				'xb/loop',
				array(
					'source' => array(
						'kind' => 'expr',
						'expr' => '[1]',
					),
					'as'     => 'item' . $depth,
				),
				array( $nested )
			);
		}
		$this->assertSame( 'Limite rispettato', $this->render( array( $nested ) ) );

		$nested = $this->block(
			'xb/loop',
			array(
				'source' => array(
					'kind' => 'expr',
					'expr' => '[1]',
				),
				'as'     => 'seventh',
			),
			array( $nested )
		);
		$this->assertStringContainsString( 'troppi loop annidati', $this->render( array( $nested ) ) );
	}
	public function test_posts_preset_returns_only_public_content(): void {
		$category = wp_insert_term( 'XB test ' . wp_generate_password( 4, false ), 'category' );
		$this->assertIsArray( $category );
		$term = (int) $category['term_id'];
		foreach ( array( 'publish', 'publish', 'publish', 'draft', 'private' ) as $i => $status ) {
			$this->post(
				array(
					'post_title'    => "P$i",
					'post_status'   => $status,
					'post_category' => array( $term ),
				)
			);
		}
		wp_set_current_user( 0 );
		$loop = $this->block(
			'xb/loop',
			array(
				'source' => array(
					'kind' => 'preset',
					'id'   => 'posts',
					'args' => array(
						'category' => (string) $term,
						'limit'    => '10',
						'orderby'  => '"title"',
						'order'    => '"asc"',
					),
				),
				'as'     => 'p',
			),
			array( $this->text( '{p.title};' ) )
		);
		$html = $this->render( array( $loop ) );
		wp_delete_term( $term, 'category' );
		$this->assertSame( 'P0;P1;P2;', $html );
	}

	public function test_components_props_slots_and_isolation(): void {
		$this->component(
			'test/card',
			array(
				$this->el(
					'article',
					array( 'class' => 'card card--{props.variant}' ),
					array(
						$this->el( 'h3', array(), array( $this->text( '{props.title}{outer}' ) ) ),
						$this->el( 'div', array(), array( $this->block( 'xb/slot', array( 'name' => 'default' ) ) ) ),
						$this->block(
							'xb/if',
							array(),
							array( $this->block( 'xb/branch', array( 'test' => 'slots.footer.empty == false' ), array( $this->block( 'xb/slot', array( 'name' => 'footer' ) ) ) ) )
						),
					)
				),
			),
			array(
				'variant' => array(
					'type'    => 'string',
					'default' => 'base',
				),
			)
		);
		$instance = $this->block(
			'xb/loop',
			array(
				'source' => array(
					'kind' => 'expr',
					'expr' => '["uno"]',
				),
				'as'     => 'outer',
			),
			array(
				$this->block(
					'xb/component',
					array(
						'ref'   => 'test/card',
						'props' => array( 'title' => array( 'tpl' => 'Titolo {outer}' ) ),
					),
					array( $this->block( 'xb/slot-fill', array( 'name' => 'default' ), array( $this->text( 'corpo {outer}' ) ) ) )
				),
			)
		);
		$this->assertSame(
			'<article class="card card--base"><h3>Titolo uno</h3><div>corpo uno</div></article>',
			$this->render( array( $instance ) )
		);
	}

	public function test_declared_slot_is_exposed_as_empty_when_the_instance_does_not_fill_it(): void {
		$this->component(
			'test/empty-slot',
			array(
				$this->block(
					'xb/if',
					array(),
					array(
						$this->block( 'xb/branch', array( 'test' => 'slots.default.empty' ), array( $this->text( 'vuoto' ) ) ),
						$this->block( 'xb/branch', array(), array( $this->text( 'pieno' ) ) ),
					)
				),
				$this->block( 'xb/slot', array( 'name' => 'default' ) ),
			)
		);

		$this->assertSame( 'vuoto', $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/empty-slot' ) ) ) ) );
	}

	public function test_components_cannot_include_themselves(): void {
		$this->component( 'test/loop', array( $this->text( 'x' ), $this->block( 'xb/component', array( 'ref' => 'test/loop' ) ) ) );
		$self = array( $this->block( 'xb/component', array( 'ref' => 'test/loop' ) ) );
		$this->assertStringContainsString( 'include sé stesso', $this->render( $self ), 'builders see the problem when debugging' );
		wp_set_current_user( 0 );
		$this->assertSame( 'x', $this->render( $self ), 'visitors never do' );
	}

	public function test_passthrough_renders_foreign_blocks(): void {
		$paragraph = parse_blocks( '<!-- wp:paragraph --><p>Nativo</p><!-- /wp:paragraph -->' )[0];
		$html      = $this->render( array( $this->el( 'div', array(), array( $this->block( 'xb/passthrough', array(), array( $paragraph ) ) ) ) ) );
		$this->assertMatchesRegularExpression( '#^<div><p[^>]*>Nativo</p></div>$#', trim( $html ) );
	}

	/**
	 * WordPress runs shortcodes on the content and on a block template's markup, never on a block by itself: a
	 * component drawn in a template (a footer with a form) runs those of its shortcode and HTML blocks itself, while
	 * in the content the_content runs them, once.
	 */
	public function test_shortcodes_in_a_component_run_outside_the_content_too(): void {
		$calls = 0;
		add_shortcode(
			'xb_prova_sc',
			static function () use ( &$calls ): string {
				++$calls;
				return '<form class="sc-form"></form>';
			}
		);
		$shortcode = parse_blocks( '<!-- wp:shortcode -->[xb_prova_sc]<!-- /wp:shortcode -->' )[0];
		$html      = parse_blocks( '<!-- wp:html --><div>[xb_prova_sc]</div><!-- /wp:html -->' )[0];
		$this->component( 'sc-piede', array( $this->el( 'footer', array(), array( $this->block( 'xb/passthrough', array(), array( $shortcode ) ), $this->block( 'xb/passthrough', array(), array( $html ) ) ) ) ) );
		try {
			$out = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'sc-piede' ) ) ) );
			$this->assertSame( 2, substr_count( $out, '<form class="sc-form"></form>' ), 'in a template: the shortcode block and the HTML block' );
			$this->assertStringNotContainsString( '[xb_prova_sc]', $out );

			$calls = 0;
			$out   = (string) apply_filters( 'the_content', $this->markup( array( $this->block( 'xb/component', array( 'ref' => 'sc-piede' ) ) ) ) );
			$this->assertSame( 2, substr_count( $out, '<form class="sc-form"></form>' ) );
			$this->assertSame( 2, $calls, 'in the content: once each, by the_content' );

			$text = parse_blocks( '<!-- wp:paragraph --><p>[xb_prova_sc]</p><!-- /wp:paragraph -->' )[0];
			$this->assertStringContainsString( '[xb_prova_sc]', $this->render( array( $this->block( 'xb/passthrough', array(), array( $shortcode ) ), $this->block( 'xb/passthrough', array(), array( $text ) ) ) ), 'outside a component nothing new runs (the template ran them already)' );
		} finally {
			remove_shortcode( 'xb_prova_sc' );
		}
	}

	public function test_post_content_does_not_recurse(): void {
		$blocks          = array( $this->text( 'prima ' ), $this->block( 'xb/post-content', array() ) );
		$id              = $this->post( array( 'post_content' => $this->markup( $blocks ) ) );
		$GLOBALS['post'] = get_post( $id );
		$html            = $this->render( array( $this->block( 'xb/post-content', array() ) ) );
		unset( $GLOBALS['post'] );
		$this->assertStringContainsString( 'prima', $html );
	}

	public function test_meta_is_exposed_only_when_public(): void {
		register_post_meta(
			'post',
			'xb_test_price',
			array(
				'single'       => true,
				'type'         => 'string',
				'show_in_rest' => true,
			)
		);
		$id = $this->post();
		update_post_meta( $id, 'xb_test_price', '42' );
		update_post_meta( $id, '_xb_test_secret', 's3' );
		update_post_meta( $id, 'xb_test_unregistered', 'u' );
		$GLOBALS['post'] = get_post( $id );
		$html            = $this->render( array( $this->text( '{this.meta.xb_test_price}|{this.meta._xb_test_secret}|{this.meta.xb_test_unregistered}' ) ) );
		unset( $GLOBALS['post'] );
		unregister_post_meta( 'post', 'xb_test_price' );
		$this->assertSame( '42||', $html );
	}
	public function test_dynamic_srcset_and_style_are_checked_at_render(): void {
		$html = $this->render(
			array(
				$this->el(
					'img',
					array(
						'srcset' => '{"javascript:alert(1) 1x, /ok.png 2x"}',
						'style'  => '{"color:red;background:url(javascript:alert(1))"}',
					)
				),
			)
		);
		$this->assertStringNotContainsString( 'javascript', $html );
		$this->assertStringContainsString( '/ok.png 2x', $html );
	}
}
