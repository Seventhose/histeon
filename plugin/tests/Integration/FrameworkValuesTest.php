<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** GET /framework gives agents the value of each variable, so they can choose a step of the scale (I5). */
final class FrameworkValuesTest extends IntegrationTestCase {

	/** V13: the lists are built once per version and revalidated with an ETag. */
	public function test_framework_lists_are_cached_and_revalidated(): void {
		$this->set_option( \XB\Framework\Frameworks::OPTION, 'xb-tokens' );
		$first = $this->request( 'GET', '/framework' );
		$this->assertSame( 200, $first->get_status() );
		$etag = (string) ( $first->get_headers()['ETag'] ?? '' );
		$this->assertMatchesRegularExpression( '/^"[0-9a-f]{32}"$/', $etag );
		$this->assertSame( 'private, no-cache', $first->get_headers()['Cache-Control'] ?? null );
		$this->assertInstanceOf( \stdClass::class, $first->get_data()['recipes'], 'recipes stay a JSON object' );

		$request = new \WP_REST_Request( 'GET', '/xb/v1/framework' );
		$request->set_header( 'If-None-Match', $etag );
		$again = rest_do_request( $request );
		$this->assertSame( 304, $again->get_status(), 'nothing changed: no body' );
		$this->assertNull( $again->get_data() );

		// New recipes (an import from Automatic.css) make a new version.
		$this->set_option( \XB\Framework\AcssToCore::RECIPES, array( 'centra-zq' => 'display:grid;place-items:center' ) );
		$changed = rest_do_request( $request );
		$this->assertSame( 200, $changed->get_status() );
		$this->assertNotSame( $etag, $changed->get_headers()['ETag'] ?? '' );
		$this->assertArrayHasKey( 'centra-zq', (array) $changed->get_data()['recipes'] );
	}

	public function test_variables_come_with_their_resolved_values(): void {
		$this->set_option( \XB\Framework\Frameworks::OPTION, 'xb-tokens' );
		$variables = array_column( (array) $this->request( 'GET', '/framework' )->get_data()['variables'], null, 'name' );

		$this->assertSame( 'clamp(1rem, 0.9583rem + 0.1852vw, 1.125rem)', $variables['xb-text-m']['value'] ?? null );
		// `--xb-text-xxl: var(--xb-text-2xl)` reads as the value of --xb-text-2xl.
		$this->assertSame( $variables['xb-text-2xl']['value'], $variables['xb-text-xxl']['value'] ?? null );
		$this->assertStringStartsWith( 'clamp(', (string) $variables['xb-text-xxl']['value'] );
		foreach ( $variables as $variable ) {
			$this->assertLessThanOrEqual( 120, mb_strlen( (string) ( $variable['value'] ?? '' ) ) );
		}
	}
}
