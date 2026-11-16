<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Admin\BuilderScreen;

/** The connector's pairing secret is derived, never stored, and leaves nothing behind (S2, threat model T20). */
final class ConnectorSecretTest extends IntegrationTestCase {

	public function test_the_secret_is_derived_and_not_in_the_database(): void {
		$user = $this->user( 'editor' );
		update_user_meta( $user, BuilderScreen::CONNECTOR_SECRET, str_repeat( 'a', 43 ) );

		$secret = BuilderScreen::connector_secret( $user );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $secret, '256 bits, base64url' );
		$this->assertSame( $secret, BuilderScreen::connector_secret( $user ), 'the same on every use' );
		$this->assertSame( '', get_user_meta( $user, BuilderScreen::CONNECTOR_SECRET, true ), 'the old secret in clear is gone' );
		$this->assertNotSame( str_repeat( 'a', 43 ), $secret );

		global $wpdb;
		$stored = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $user ) );
		foreach ( $stored as $value ) {
			$this->assertStringNotContainsString( $secret, (string) $value );
		}
		$this->assertNotSame( $secret, BuilderScreen::connector_secret( $this->user( 'editor' ) ), 'each user has their own' );

		// Rotating replaces the seed, so the secret.
		delete_user_meta( $user, BuilderScreen::CONNECTOR_SEED );
		$this->assertNotSame( $secret, BuilderScreen::connector_secret( $user ) );
	}

	public function test_uninstall_removes_the_seed_and_the_old_secret(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( "'" . BuilderScreen::CONNECTOR_SEED . "'", $source );
		$this->assertStringContainsString( "'" . BuilderScreen::CONNECTOR_SECRET . "'", $source );
	}
}
