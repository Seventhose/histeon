<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/**
 * What the renderer prints for the dynamic image source a Histeon CMS transfer carries (docs/spec/transfer-markup.md
 * §5, item 10): in a component a transfer profile manages, `src={props.immagine}` only as an image of this site.
 * Content WordPress alone manages renders as before W2: external sources, offloaded media and dynamic styles with
 * url() included.
 */
final class TransferMarkupRenderTest extends IntegrationTestCase {

	/** A component as an import from Histeon CMS leaves it: with its profile identity and the import's marker. */
	private function managed( int $component_id ): int {
		update_post_meta( $component_id, '_xb_page_profile_id', wp_generate_uuid4() );
		update_post_meta( $component_id, \XB\Remote\PageProfileAdapter::IMPORTED_META, '1' );
		return $component_id;
	}

	private function image_with( string $value, bool $managed = true ): string {
		$id = $this->component( 'test/immagine', array( $this->el( 'img', array( 'src' => '{props.immagine}', 'alt' => '' ) ) ), array( 'immagine' => array( 'type' => 'image', 'default' => '' ) ) );
		if ( $managed ) {
			$this->managed( $id );
		}
		\XB\Plugin::instance()->renderer->reset_budget();
		return $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/immagine', 'props' => array( 'immagine' => $value ) ) ) ) );
	}

	public function test_a_dynamic_image_prop_of_a_managed_component_prints_only_images_of_this_site(): void {
		$uploads = untrailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		$path    = (string) wp_parse_url( $uploads, PHP_URL_PATH );
		$other   = str_starts_with( $uploads, 'https:' ) ? 'http:' . substr( $uploads, 6 ) : 'https:' . substr( $uploads, 5 );
		foreach ( array( $uploads . '/2026/10/logo.png', $other . '/2026/10/logo.png', $path . '/2026/10/logo.png', $uploads . '/2026/10/logo.png?v=2', $uploads . '/2026/10/logo..png', $path . '/.htaccess-free/logo.png' ) as $kept ) {
			$this->assertStringContainsString( ' src="' . esc_url( $kept ) . '"', $this->image_with( $kept ), $kept );
		}
		foreach (
			array(
				'https://tracker.example.com/pixel.gif',
				'//tracker.example.com/pixel.gif',
				'/\\tracker.example.com/pixel.gif',
				'\\\\tracker.example.com/pixel.gif',
				'javascript:alert(1)',
				'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
				$uploads . '.tracker.example.com/pixel.gif',
				$uploads . '@tracker.example.com/pixel.gif',
				$uploads,
				' ' . $uploads . '/2026/10/logo.png',
				$uploads . "/\t/tracker.example.com",
				$uploads . '/2026/10/&#92;logo.png',
				'//' . (string) wp_parse_url( $uploads, PHP_URL_HOST ) . '.tracker.example.com' . $path . '/x.png',
				'histos-media://550e8400-e29b-41d4-a716-446655440064',
				// Dot segments climb out of the uploads to other URLs of the site (review of A6).
				$uploads . '/../../wp-login.php?action=logout',
				$path . '/../../wp-admin/admin-post.php?action=x',
				$path . '/%2e%2e/%2e%2e/wp-json/',
				$path . '/%2E%2E/wp-json/',
				$uploads . '/2026/./../../wp-login.php',
				$uploads . '/2026/..',
				$uploads . '/&#46;&#46;/&#46;&#46;/wp-login.php',
				// Dot segments split by a tab or newline written as a reference: the URL parser drops them (re-review).
				$path . '/.&#9;./.&#9;./wp-login.php?action=logout',
				$path . '/.&#x9;./.&#x09;./wp-login.php',
				$path . '/.&#10;./.&#13;./wp-json/',
				$path . '/.&Tab;./wp-json/',
				$path . '/.&NewLine;./wp-json/',
				$uploads . '/.&#9;./.&#9;./wp-login.php',
				$path . '/&#x2e;&#x2E;/wp-json/',
				$path . '/&period;&period;/wp-json/',
				$path . '/&#37;2e&#37;2e/wp-json/',
				$path . '/.%2e/wp-json/',
				$path . '/%2e./wp-json/',
				$path . '/&#46&#46/wp-json/',
				$path . '/&#92;../wp-json/',
				$path . '/..&#47;wp-json/',
				$path . '/x/..',
				$path . '/x/.',
				// Characters esc_url() drops after the check would join a dot segment (third review).
				$path . '/.^./.^./wp-login.php?action=logout',
				$path . '/.<./wp-json/',
				$path . '/.>./wp-json/',
				$path . '/.".".//wp-json/',
				$path . '/.`./wp-json/',
				$path . '/.{./wp-json/',
				$path . '/.}./wp-json/',
				$uploads . '/.^./.^./wp-login.php',
				$uploads . '/2026/10/logo.png?a=1&b=2',
				$path . '/x/&#63;/../../y',
			)
			as $stripped
		) {
			$html = $this->image_with( $stripped );
			$this->assertStringNotContainsString( ' src=', $html, $stripped );
			$this->assertStringContainsString( '<img', $html, $stripped );
		}
		$this->assertStringContainsString( ' src=""', $this->image_with( '' ), 'An empty prop stays an empty source, as before.' );
	}

	/**
	 * The review's regression (A6): content WordPress alone manages renders as on main. A hero with a dynamic
	 * background from the uploads, a logo prop on a CDN, an attachment a media-offload plugin serves from a CDN.
	 */
	public function test_native_content_renders_dynamic_sources_and_styles_as_before(): void {
		$uploads = untrailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		$this->component( 'test/hero', array( $this->el( 'section', array( 'style' => 'background-image:url({props.bg})' ) ), $this->el( 'img', array( 'src' => '{props.bg}', 'alt' => '' ) ) ), array( 'bg' => array( 'type' => 'image', 'default' => '' ) ) );
		$html = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/hero', 'props' => array( 'bg' => $uploads . '/2026/10/hero.jpg' ) ) ) ) );
		$this->assertStringContainsString( 'style="background-image:url(' . $uploads . '/2026/10/hero.jpg)"', $html );
		$this->assertStringContainsString( ' src="' . $uploads . '/2026/10/hero.jpg"', $html );

		$this->component( 'test/logo', array( $this->el( 'img', array( 'src' => '{props.logo}', 'alt' => '' ) ) ), array( 'logo' => array( 'type' => 'url', 'default' => '' ) ) );
		$html = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/logo', 'props' => array( 'logo' => 'https://cdn.example.com/brand/logo.svg' ) ) ) ) );
		$this->assertStringContainsString( ' src="https://cdn.example.com/brand/logo.svg"', $html );

		$file = wp_upload_bits( 'transfer-render-offload.png', null, (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true ) );
		$this->assertEmpty( $file['error'] );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'offload', 'post_status' => 'inherit' ), $file['file'] );
		$this->track( $id );
		$cdn = static fn ( string $url ): string => str_replace( $uploads, 'https://cdn.example.com/uploads', $url );
		add_filter( 'wp_get_attachment_url', $cdn );
		try {
			$offloaded = (string) wp_get_attachment_url( $id );
			$this->assertStringStartsWith( 'https://cdn.example.com/uploads/', $offloaded );
			$this->assertStringContainsString( ' src="' . $offloaded . '"', $this->image_with( $offloaded, false ) );
		} finally {
			remove_filter( 'wp_get_attachment_url', $cdn );
			wp_delete_file( $file['file'] );
		}

		// External and same-site sources the managed case refuses, in a component WordPress alone manages.
		foreach ( array( 'https://tracker.example.com/pixel.gif', $uploads . '/../../wp-login.php' ) as $value ) {
			$this->assertStringContainsString( ' src="' . esc_url( $value ) . '"', $this->image_with( $value, false ), $value );
		}
	}

	/** Only the form a transfer carries is held to the site's uploads: other expressions are WordPress data (avatars). */
	public function test_other_dynamic_sources_of_a_managed_component_keep_their_behaviour(): void {
		$this->managed( $this->component( 'test/avatar', array( $this->el( 'img', array( 'src' => '{props.utente.avatar}', 'alt' => '' ) ) ), array( 'utente' => array( 'type' => 'object' ) ) ) );
		$html = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/avatar', 'props' => array( 'utente' => array( 'avatar' => 'https://secure.gravatar.com/avatar/x' ) ) ) ) ) );
		$this->assertStringContainsString( ' src="https://secure.gravatar.com/avatar/x"', $html );
	}

	/** A dynamic style keeps its WordPress behaviour (safecss_filter_attr()): the import refuses any `{` in a transferred style. */
	public function test_a_dynamic_style_of_a_managed_component_renders_as_before(): void {
		$uploads = untrailingslashit( (string) wp_upload_dir( null, false )['baseurl'] );
		$this->managed( $this->component( 'test/stile', array( $this->el( 'div', array( 'style' => 'background-image:url({props.bg})' ) ) ), array( 'bg' => array( 'type' => 'image', 'default' => '' ) ) ) );
		$html = $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/stile', 'props' => array( 'bg' => $uploads . '/2026/10/hero.jpg' ) ) ) ) );
		$this->assertStringContainsString( 'style="background-image:url(' . $uploads . '/2026/10/hero.jpg)"', $html );
	}

	/** A download gives native components a profile identity: that alone must not change how they render (re-review). */
	public function test_an_export_alone_leaves_native_components_rendering_as_before(): void {
		foreach ( array( 'xb_page_profile_project_id', 'xb_page_profile_project_name', 'xb_page_profile_styles_base_hash', 'xb_page_profile_styles_id', 'xb_page_profile_tombstones' ) as $option ) {
			$this->set_option( $option, get_option( $option, false ) );
		}
		$id   = $this->component( 'test/cdnlogo', array( $this->el( 'img', array( 'src' => '{props.logo}', 'alt' => '' ) ) ), array( 'logo' => array( 'type' => 'url', 'default' => '' ) ) );
		$page = $this->post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Logo', 'post_content' => $this->markup( array( $this->block( 'xb/component', array( 'ref' => 'test/cdnlogo', 'props' => array( 'logo' => 'https://cdn.example.com/brand/logo.svg' ) ) ) ) ) ) );
		$render = function (): string {
			\XB\Plugin::instance()->components->forget();
			\XB\Plugin::instance()->renderer->reset_budget();
			return $this->render( array( $this->block( 'xb/component', array( 'ref' => 'test/cdnlogo', 'props' => array( 'logo' => 'https://cdn.example.com/brand/logo.svg' ) ) ) ) );
		};
		$this->assertStringContainsString( ' src="https://cdn.example.com/brand/logo.svg"', $render() );
		\XB\Remote\PageProfileAdapter::export();
		$this->assertNotSame( '', (string) get_post_meta( $id, '_xb_page_profile_id', true ), 'The download gave the component a profile identity.' );
		$this->assertNotSame( '', (string) get_post_meta( $page, '_xb_page_profile_id', true ) );
		$this->assertFalse( \XB\Remote\PageProfileAdapter::is_transfer_imported( $id ) );
		$this->assertStringContainsString( ' src="https://cdn.example.com/brand/logo.svg"', $render() );
	}
}
