<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** Images from the content get width, height, srcset and sizes when the author did not write them — docs/spec/front-performance.md. */
final class ImageExtrasTest extends IntegrationTestCase {

	private function image(): int {
		$upload = wp_upload_bits( 'xb-extra-' . wp_generate_password( 6, false ) . '.gif', null, (string) base64_decode( 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) );
		$this->assertEmpty( $upload['error'] );
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/gif', 'post_title' => 'foto', 'post_status' => 'inherit' ), $upload['file'] );
		$this->track( $id );
		$file = basename( $upload['file'], '.gif' );
		// Sizes as WordPress would have made them for a large photo.
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 1200,
				'height' => 600,
				'file'   => _wp_relative_upload_path( $upload['file'] ),
				'sizes'  => array(
					'medium' => array( 'file' => "$file-300x150.gif", 'width' => 300, 'height' => 150, 'mime-type' => 'image/gif' ),
					'large'  => array( 'file' => "$file-1024x512.gif", 'width' => 1024, 'height' => 512, 'mime-type' => 'image/gif' ),
				),
			)
		);
		return $id;
	}

	/** @param array<string, string|true> $attributes */
	private function img( array $attributes ): string {
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Con foto' ) );
		set_post_thumbnail( $page, $this->image() );
		$post = get_post( $page );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		try {
			return $this->render( array( $this->el( 'img', $attributes, array() ) ) );
		} finally {
			wp_reset_postdata();
		}
	}

	public function test_an_image_from_the_content_gets_dimensions_srcset_and_sizes(): void {
		$html = $this->img( array( 'src' => '{this.image.url}', 'alt' => '{this.image.alt}', 'loading' => 'lazy' ) );
		$this->assertMatchesRegularExpression( '/ width="1200"/', $html );
		$this->assertMatchesRegularExpression( '/ height="600"/', $html );
		$this->assertMatchesRegularExpression( '/ srcset="[^"]*-300x150\.gif 300w/', $html );
		$this->assertMatchesRegularExpression( '/ sizes="auto, \(max-width: 1200px\) 100vw, 1200px"/', $html, 'lazy: the browser may use the laid-out width' );
		$this->assertSame( 1, substr_count( $html, ' src=' ) );
	}

	public function test_a_registered_size_gets_its_own_dimensions(): void {
		$html = $this->img( array( 'src' => '{this.image.medium}', 'alt' => '' ) );
		$this->assertStringContainsString( ' width="300"', $html );
		$this->assertStringContainsString( ' height="150"', $html );
		$this->assertMatchesRegularExpression( '/ srcset="[^"]*-300x150\.gif 300w"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/ srcset="[^"]*(1024w|1200w)/', $html, 'no file wider than the size chosen (doc 26 voce 223)' );
		$this->assertStringContainsString( '1200w', $this->img( array( 'src' => '{this.image.url}', 'alt' => '' ) ), 'the original keeps every size' );
	}

	public function test_srcset_up_to_a_width(): void {
		$srcset = 'a.jpg 300w, b.jpg 768w, c.jpg 1024w, d.jpg 2x';
		$this->assertSame( 'a.jpg 300w, b.jpg 768w, d.jpg 2x', \XB\Render\Renderer::srcset_up_to( $srcset, 768 ) );
		$this->assertSame( $srcset, \XB\Render\Renderer::srcset_up_to( $srcset, 0 ) );
	}

	public function test_the_shape_of_etch_data_works_too(): void {
		// `image.sizes.medium_large.url`, as pages imported from Etch write it: the size, with its dimensions.
		$html = $this->img( array( 'src' => '{this.image.sizes.medium.url}', 'alt' => '' ) );
		$this->assertMatchesRegularExpression( '/ src="[^"]*-300x150\.gif"/', $html );
		$this->assertStringContainsString( ' width="300"', $html );
		$this->assertStringContainsString( ' height="150"', $html );
		// As text, `image.sizes` is still the sizes attribute it always was.
		$page = $this->post( array( 'post_type' => 'page', 'post_title' => 'Testo' ) );
		set_post_thumbnail( $page, $this->image() );
		$post = get_post( $page );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		try {
			$text = $this->render( array( $this->el( 'p', array(), array( $this->text( '{this.image.sizes}|{this.image.sizes.large.width}' ) ) ) ) );
		} finally {
			wp_reset_postdata();
		}
		$this->assertStringContainsString( '(max-width: 1200px) 100vw, 1200px|1024', $text );
	}

	public function test_what_the_author_wrote_is_kept(): void {
		$html = $this->img( array( 'src' => '{this.image}', 'width' => '640', 'srcset' => '{this.image.srcset}', 'sizes' => '50vw' ) );
		$this->assertSame( 1, substr_count( $html, ' width=' ) );
		$this->assertStringContainsString( ' width="640"', $html );
		$this->assertStringContainsString( ' sizes="50vw"', $html );
		$this->assertSame( 1, substr_count( $html, ' srcset=' ) );
		$this->assertStringContainsString( ' height="600"', $html, 'what was missing is still added' );
	}

	public function test_other_images_are_left_alone(): void {
		$static = $this->render( array( $this->el( 'img', array( 'src' => '/x.png', 'alt' => '' ), array() ) ) );
		$this->assertStringNotContainsString( 'width=', $static );
		$this->assertStringNotContainsString( 'srcset=', $static );
		$plain = $this->img( array( 'src' => '{this.url}' ) );
		$this->assertStringNotContainsString( 'srcset=', $plain, 'a URL that is not an image' );
	}
}
