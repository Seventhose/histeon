<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\Frameworks;
use XB\Framework\Migration;
use XB\Plugin;
use XB\Versioning\Documents;

/**
 * A framework migration is all or nothing (S8): when a write fails half way, no document keeps the new names and
 * the framework choice does not change. Needs no commercial plugin: the Automatic.css sheets it starts from are
 * a small file written by the test (the migration reads only the files of a framework that is off).
 */
final class MigrationAtomicTest extends IntegrationTestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = wp_upload_dir()['basedir'] . '/automatic-css';
		if ( file_exists( $this->dir . '/automatic-variables.css' ) ) {
			$this->markTestSkipped( 'Automatic.css ha già i suoi fogli in questo sito: il test non li tocca.' );
		}
		wp_mkdir_p( $this->dir );
		file_put_contents( $this->dir . '/automatic-variables.css', ':root{--space-m:1.5rem;--text-m:1rem}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->set_option( Frameworks::OPTION, 'xb-tokens' );
		Plugin::instance()->versioning->ensure( Documents::SETTINGS );
	}

	protected function tearDown(): void {
		remove_all_filters( 'query' );
		if ( '' !== $this->dir && file_exists( $this->dir . '/automatic-variables.css' ) ) {
			unlink( $this->dir . '/automatic-variables.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only if empty.
		}
		parent::tearDown();
	}

	private function page( string $uid ): int {
		return $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( array( $this->el( 'p', array( 'class' => 'hidden-accessible' ), array( $this->text( 'Nascosto' ) ), $uid ) ) ),
			)
		);
	}

	private function dev_content( int $post_id ): string {
		return (string) ( Plugin::instance()->versioning->working_copy( Documents::for_post( $post_id ) )['payload']['content'] ?? '' );
	}

	public function test_a_failure_half_way_leaves_every_document_and_the_choice_as_they_were(): void {
		$first  = $this->page( 'migat001' );
		$second = $this->page( 'migat002' );
		$migration = new Migration( Plugin::instance()->frameworks, Plugin::instance()->versioning );
		$plan      = $migration->plan( 'xb-tokens', 'acss' );
		$this->assertContains( Documents::for_post( $first ), array_column( $plan['docs'], 'doc' ) );
		$this->assertContains( Documents::for_post( $second ), array_column( $plan['docs'], 'doc' ) );
		$settings = Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['head'];

		// The database refuses the commit of the second of the two pages, after the first one went through.
		global $wpdb;
		$written = array();
		add_filter(
			'query',
			static function ( string $query ) use ( $wpdb, &$written ): string {
				if ( ! str_contains( $query, $wpdb->prefix . 'xb_commits' ) || ! str_starts_with( ltrim( $query ), 'INSERT' ) ) {
					return $query;
				}
				foreach ( array( 'migat001', 'migat002' ) as $uid ) {
					if ( str_contains( $query, $uid ) && str_contains( $query, 'xb-visually-hidden' ) ) {
						$written[ $uid ] = true;
						if ( count( $written ) === 2 ) {
							return 'INSERT INTO xb_commits_that_do_not_exist VALUES (1)';
						}
					}
				}
				return $query;
			}
		);
		$failed = false;
		try {
			$migration->prepare( 'xb-tokens', false, 'acss' );
		} catch ( \Throwable ) {
			$failed = true;
		}
		remove_all_filters( 'query' );
		$this->assertTrue( $failed, 'the failed write is reported' );
		$this->assertCount( 2, $written, 'the failure came half way, after one page was written' );
		foreach ( array( $first, $second ) as $id ) {
			$this->assertStringContainsString( 'hidden-accessible', $this->dev_content( $id ) );
			$this->assertStringNotContainsString( 'xb-visually-hidden', $this->dev_content( $id ) );
		}
		$this->assertSame( $settings, Plugin::instance()->versioning->working_copy( Documents::SETTINGS )['head'], 'the framework choice did not move' );

		// Without the failure it goes through, all of it.
		$migration->prepare( 'xb-tokens', false, 'acss' );
		$this->assertStringContainsString( 'xb-visually-hidden', $this->dev_content( $first ) );
		$this->assertStringContainsString( 'xb-visually-hidden', $this->dev_content( $second ) );
	}
}
