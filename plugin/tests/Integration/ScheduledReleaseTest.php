<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Plugin;
use XB\Versioning\Merge;
use XB\Versioning\Scheduled;

/**
 * The scheduled release (docs/spec/versioning.md §11, threat model T30). Time comes from Scheduled::set_clock(),
 * never from sleep(); the cron event is fired with do_action(), as WP-Cron does.
 */
final class ScheduledReleaseTest extends IntegrationTestCase {

	private int $now = 0;

	/** @var list<array<string, mixed>> */
	private array $mails = array();

	private ?\Closure $mail_catcher = null;

	protected function setUp(): void {
		parent::setUp();
		delete_option( Scheduled::OPTION );
		delete_option( Scheduled::LAST );
		wp_unschedule_hook( Scheduled::HOOK );
		$this->now = time();
		Scheduled::set_clock( fn (): int => $this->now );
		$this->mails        = array();
		$this->mail_catcher = function ( mixed $short, array $atts ): bool {
			$this->mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $this->mail_catcher, 10, 2 );
	}

	protected function tearDown(): void {
		if ( null !== $this->mail_catcher ) {
			remove_filter( 'pre_wp_mail', $this->mail_catcher, 10 );
		}
		Scheduled::set_clock( null );
		delete_option( Scheduled::OPTION );
		delete_option( Scheduled::LAST );
		wp_unschedule_hook( Scheduled::HOOK );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		parent::tearDown();
	}

	// ------------------------------------------------------------------ helpers

	/** A page with a heading (uid sra) and a paragraph (uid srb), saved in development with a new heading. */
	private function changed_page( string $heading = 'Titolo nuovo' ): int {
		$id = $this->post(
			array(
				'post_type'    => 'page',
				'post_content' => $this->markup( $this->tree( 'Titolo', 'Testo' ) ),
			)
		);
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( $heading, 'Testo' ) ) )->get_status() );
		return $id;
	}

	/** @return list<array<string, mixed>> */
	private function tree( string $heading, string $text ): array {
		return array(
			$this->el(
				'section',
				array(),
				array(
					$this->el( 'h1', array(), array( $this->text( $heading, 'sratext1' ) ), 'srah1001' ),
					$this->el( 'p', array(), array( $this->text( $text, 'srbtext1' ) ), 'srbp0001' ),
				),
				'srsect01'
			),
		);
	}

	private function heading( int $id ): string {
		return Merge::texts( parse_blocks( $this->content( $id ) ) )['sratext1'] ?? '';
	}

	/**
	 * «Programma per…» an hour ahead (or $ahead seconds), through the REST route.
	 *
	 * @param list<string>         $docs
	 * @param array<string, mixed> $extra
	 */
	private function schedule( array $docs, int $ahead = HOUR_IN_SECONDS, array $extra = array() ): \WP_REST_Response {
		return $this->request(
			'POST',
			'/releases/schedule',
			array_merge(
				array(
					'at'    => wp_date( 'Y-m-d\TH:i', $this->now + $ahead ),
					'notes' => 'programmata',
					'docs'  => $docs,
				),
				$extra
			)
		);
	}

	/**
	 * @param list<string> $docs
	 * @return array<string, mixed>
	 */
	private function scheduled( array $docs ): array {
		$response = $this->schedule( $docs );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		return $data;
	}

	/**
	 * Fires the cron event at $at (+ $late seconds), as WP-Cron would.
	 *
	 * @param array<string, mixed> $view
	 */
	private function fire( array $view, int $late = 60 ): void {
		$this->now = (int) $view['at'] + $late;
		do_action( Scheduled::HOOK, $view['id'] );
	}

	/** @return array<string, mixed> */
	private function last(): array {
		$last = Scheduled::last_view();
		$this->assertNotNull( $last );
		return $last;
	}

	private function publisher(): int {
		$id = $this->user( 'administrator' );
		wp_update_user(
			array(
				'ID'         => $id,
				'user_email' => 'programma-' . $id . '@example.test',
			)
		);
		$this->mails = array(); // WordPress's own «email changed» notice.
		wp_set_current_user( $id );
		return $id;
	}

	// ------------------------------------------------------------------ tests

	public function test_publishes_when_everything_is_as_scheduled(): void {
		$user = $this->publisher();
		$id   = $this->changed_page( 'Annuncio' );
		$view = $this->scheduled( array( 'post:' . $id ) );
		$this->assertSame( $user, $view['user'] );
		$this->assertSame( array( 'post:' . $id ), $view['docs'] );
		$this->assertNotFalse( wp_next_scheduled( Scheduled::HOOK, array( $view['id'] ) ), 'the cron event carries the record id' );
		$record = get_option( Scheduled::OPTION );
		$this->assertIsArray( $record );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $record['mac'] );
		$this->assertSame( 'Titolo', $this->heading( $id ), 'nothing live before the hour' );

		wp_set_current_user( 0 );
		$this->fire( $view );
		$this->assertSame( 0, get_current_user_id(), 'the cron goes back to no user' );
		$this->assertSame( 'Annuncio', $this->heading( $id ) );
		$this->assertFalse( get_option( Scheduled::OPTION ) );
		$this->assertFalse( wp_next_scheduled( Scheduled::HOOK, array( $view['id'] ) ) );
		$this->assertSame( 'published', $this->last()['state'] );
		$release = Plugin::instance()->versioning->store()->get_release( (int) get_option( 'xb_current_release' ) );
		$this->assertSame( $view['version'], $release['version'] ?? null );
		$this->assertSame( $user, $release['author'] ?? null, 'published as whoever scheduled' );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'programma-' . $user . '@example.test', $this->mails[0]['to'] );
	}

	public function test_skips_when_a_document_changed_in_development(): void {
		$this->publisher();
		$id   = $this->changed_page();
		$view = $this->scheduled( array( 'post:' . $id ) );
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Cambiato dopo', 'Testo' ) ) )->get_status() );
		$this->fire( $view );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
		$this->assertSame( 'skipped', $this->last()['state'] );
		$this->assertSame( 'documento-cambiato', $this->last()['code'] );
		$this->assertSame( 'schedule_skip', Plugin::instance()->versioning->store()->audit_log( 1 )[0]['action'] );
		$this->assertCount( 1, $this->mails );
	}

	public function test_skips_when_the_user_lost_xb_publish(): void {
		$user = $this->publisher();
		$id   = $this->changed_page();
		$view = $this->scheduled( array( 'post:' . $id ) );
		( new \WP_User( $user ) )->add_cap( 'xb_publish', false );
		$this->fire( $view );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
		$this->assertSame( 'permesso-perso', $this->last()['code'] );
	}

	public function test_skips_when_the_user_was_deleted(): void {
		$user = $this->publisher();
		wp_set_current_user( 1 );
		$id = $this->changed_page();
		wp_set_current_user( $user );
		$view = $this->scheduled( array( 'post:' . $id ) );
		wp_set_current_user( 1 );
		wp_delete_user( $user );
		$this->fire( $view );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
		$this->assertSame( 'utente-assente', $this->last()['code'] );
		$this->assertSame( get_option( 'admin_email' ), $this->mails[0]['to'] ?? null, 'nobody else to tell' );
	}

	public function test_skips_when_the_record_was_tampered_with(): void {
		$this->publisher();
		$id     = $this->changed_page();
		$view   = $this->scheduled( array( 'post:' . $id ) );
		$record = get_option( Scheduled::OPTION );
		$this->assertIsArray( $record );
		$record['user'] = 1; // Someone who can write the database names another publisher.
		update_option( Scheduled::OPTION, $record, false );
		$this->fire( $view );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
		$this->assertSame( 'alterata', $this->last()['code'] );
		$this->assertSame( get_option( 'admin_email' ), $this->mails[0]['to'] ?? null, 'never to the user the record names' );

		// A format the code does not know never goes out either.
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'Ancora', 'Testo' ) ) )->get_status() );
		$view            = $this->scheduled( array( 'post:' . $id ) );
		$record          = get_option( Scheduled::OPTION );
		$record['v']     = 2;
		update_option( Scheduled::OPTION, $record, false );
		$this->fire( $view );
		$this->assertSame( 'alterata', $this->last()['code'] );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
	}

	public function test_skips_more_than_two_hours_late(): void {
		$this->publisher();
		$id   = $this->changed_page();
		$view = $this->scheduled( array( 'post:' . $id ) );
		$this->fire( $view, 2 * HOUR_IN_SECONDS + 60 );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
		$this->assertSame( 'troppo-tardi', $this->last()['code'] );

		// Within the tolerance it goes out.
		$this->assertSame( 200, $this->save_dev( $id, $this->markup( $this->tree( 'In ritardo', 'Testo' ) ) )->get_status() );
		$this->now = time();
		$view      = $this->scheduled( array( 'post:' . $id ) );
		$this->fire( $view, 2 * HOUR_IN_SECONDS - 60 );
		$this->assertSame( 'In ritardo', $this->heading( $id ) );
	}

	public function test_a_record_past_the_tolerance_is_closed_on_the_next_visit(): void {
		$this->publisher();
		$id   = $this->changed_page();
		$view = $this->scheduled( array( 'post:' . $id ) );
		$this->now = (int) $view['at'] + 3 * HOUR_IN_SECONDS;
		$this->request( 'GET', '/releases' );
		$this->assertFalse( get_option( Scheduled::OPTION ) );
		$this->assertSame( 'troppo-tardi', $this->last()['code'] );
	}

	public function test_does_not_run_before_its_time_nor_twice(): void {
		$this->publisher();
		$id   = $this->changed_page( 'Una volta' );
		$view = $this->scheduled( array( 'post:' . $id ) );
		$store = Plugin::instance()->versioning->store();

		// Fired by hand before the hour (WP-CLI, WP Crontrol), even twice: nothing, and one entry in the Registro.
		wp_unschedule_hook( Scheduled::HOOK ); // As WP-CLI unschedules an event it runs.
		$this->fire( $view, -600 );
		$this->fire( $view, -300 );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
		$this->assertIsArray( get_option( Scheduled::OPTION ), 'the record stays' );
		$this->assertNotFalse( wp_next_scheduled( Scheduled::HOOK, array( $view['id'] ) ), 'the event is put back' );
		$this->assertCount( 1, $store->audit_of( 'schedule_early', array( 'schedule:' . $view['id'] ) ) );
		$early = array_filter( $store->audit_log( 10 ), static fn ( array $row ): bool => 'schedule_early' === $row['action'] );
		$this->assertCount( 1, $early );

		// At the hour it goes out once; the same event again finds nothing.
		$releases = count( $store->releases() );
		$this->fire( $view );
		$this->fire( $view );
		$this->assertSame( 'Una volta', $this->heading( $id ) );
		$this->assertCount( $releases + 1, $store->releases() );
		$this->assertCount( 1, $this->mails );
		// An event with another id (left by a cancelled schedule) does nothing either.
		do_action( Scheduled::HOOK, 'ffffffffffffffffffffffffffffffff' );
		$this->assertCount( $releases + 1, $store->releases() );
	}

	public function test_skips_on_a_conflict_with_the_client_texts(): void {
		$this->publisher();
		$id   = $this->changed_page( 'Titolo del builder' );
		$view = $this->scheduled( array( 'post:' . $id ) );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $this->markup( $this->tree( 'Titolo del cliente', 'Testo' ) ) ),
			)
		);
		$this->fire( $view );
		$this->assertSame( 'Titolo del cliente', $this->heading( $id ), 'the client text stays online' );
		$this->assertSame( 'conflitti', $this->last()['code'] );
	}

	public function test_skips_when_new_contents_from_the_cms_arrived(): void {
		$this->publisher();
		$id   = $this->changed_page();
		$view = $this->scheduled( array( 'post:' . $id ) );
		update_option(
			'xb_cms_pending_contents',
			array(
				'entities' => array( 'record-1' => array( 'title' => 'Arrivato dopo' ) ),
				'live'     => array(),
				'media'    => array(),
				'locale'   => '',
				'layouts'  => null,
			),
			false
		);
		$this->fire( $view );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
		$this->assertSame( 'contenuti-nuovi', $this->last()['code'] );
	}

	public function test_a_release_by_hand_on_the_same_documents_cancels_it_on_others_does_not(): void {
		$this->publisher();
		$first  = $this->changed_page();
		$second = $this->changed_page();
		$view   = $this->scheduled( array( 'post:' . $first ) );

		$this->assertSame( 200, $this->publish( array( 'post:' . $second ) )->get_status() );
		$this->assertIsArray( get_option( Scheduled::OPTION ), 'another page: still scheduled' );
		$this->assertNotSame( $view['version'], Plugin::instance()->versioning->store()->latest_version(), 'the reserved version is skipped' );
		$this->assertSame( 409, $this->request( 'POST', '/releases', array( 'notes' => 'x', 'docs' => array( 'post:' . $first ), 'version' => $view['version'] ) )->get_status(), 'the reserved version cannot be taken by hand' );

		$this->assertSame( 200, $this->publish( array( 'post:' . $first ) )->get_status() );
		$this->assertFalse( get_option( Scheduled::OPTION ) );
		$this->assertFalse( wp_next_scheduled( Scheduled::HOOK, array( $view['id'] ) ) );
		$this->assertSame( 'cancelled', $this->last()['state'] );
		$this->assertSame( 'superata', $this->last()['code'] );
	}

	public function test_restoring_a_release_cancels_it(): void {
		$this->publisher();
		$other   = $this->changed_page();
		$release = (int) $this->publish( array( 'post:' . $other ) )->get_data()['id'];
		$id      = $this->changed_page();
		$view    = $this->scheduled( array( 'post:' . $id ) );
		$this->assertSame( 200, $this->request( 'POST', "/releases/$release/restore" )->get_status() );
		$this->assertFalse( get_option( Scheduled::OPTION ) );
		$this->assertFalse( wp_next_scheduled( Scheduled::HOOK, array( $view['id'] ) ) );
		$this->assertSame( 'ripristino', $this->last()['code'] );
	}

	public function test_cancelling_removes_event_and_record(): void {
		$this->publisher();
		$id   = $this->changed_page();
		$view = $this->scheduled( array( 'post:' . $id ) );
		$this->assertSame( 200, $this->request( 'DELETE', '/releases/schedule' )->get_status() );
		$this->assertFalse( get_option( Scheduled::OPTION ) );
		$this->assertFalse( wp_next_scheduled( Scheduled::HOOK, array( $view['id'] ) ) );
		$this->assertSame( 'cancelled', $this->last()['state'] );
		$this->assertSame( array(), $this->mails, 'whoever scheduled cancelled it: no email' );
		$this->assertSame( 404, $this->request( 'DELETE', '/releases/schedule' )->get_status() );
		$this->fire( $view );
		$this->assertSame( 'Titolo', $this->heading( $id ) );

		// Cancelled by someone else: whoever scheduled hears of it.
		$view = $this->scheduled( array( 'post:' . $id ) );
		wp_set_current_user( 1 );
		$this->assertSame( 200, $this->request( 'DELETE', '/releases/schedule' )->get_status() );
		$this->assertCount( 1, $this->mails );

		// Deactivating Histeon cancels it too.
		wp_set_current_user( $view['user'] );
		$view = $this->scheduled( array( 'post:' . $id ) );
		Scheduled::deactivated();
		$this->assertFalse( get_option( Scheduled::OPTION ) );
		$this->assertSame( 'disattivato', $this->last()['code'] );
	}

	public function test_a_second_schedule_is_refused_while_there_is_one(): void {
		$this->publisher();
		$first  = $this->changed_page();
		$second = $this->changed_page();
		$this->scheduled( array( 'post:' . $first ) );
		$this->assertSame( 409, $this->schedule( array( 'post:' . $second ) )->get_status() );
		$listed = $this->request( 'GET', '/releases' )->get_data();
		$this->assertSame( array( 'post:' . $first ), $listed['scheduled']['docs'] );
		$this->assertArrayHasKey( 'lines', $listed['cron'] );
	}

	public function test_the_route_refuses_past_far_and_application_passwords(): void {
		$this->publisher();
		$id = $this->changed_page();
		$this->assertSame( 400, $this->schedule( array( 'post:' . $id ), -HOUR_IN_SECONDS )->get_status(), 'in the past' );
		$this->assertSame( 400, $this->schedule( array( 'post:' . $id ), 2 * MINUTE_IN_SECONDS )->get_status(), 'less than 5 minutes ahead' );
		$this->assertSame( 400, $this->schedule( array( 'post:' . $id ), 61 * DAY_IN_SECONDS )->get_status(), 'more than 60 days ahead' );
		$this->assertSame( 400, $this->schedule( array( 'post:' . $id ), HOUR_IN_SECONDS, array( 'at' => '2026-13-40T25:00' ) )->get_status() );
		$GLOBALS['wp_rest_application_password_uuid'] = wp_generate_uuid4();
		$refused = $this->schedule( array( 'post:' . $id ) );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( 'xb_app_password', $refused->get_data()['code'] ?? null );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$this->assertFalse( get_option( Scheduled::OPTION ) );
		$this->assertSame( 200, $this->schedule( array( 'post:' . $id ) )->get_status() );

		// Without xb_publish, no scheduling.
		wp_set_current_user( $this->user( 'editor' ) );
		$this->assertSame( 403, $this->schedule( array( 'post:' . $id ) )->get_status() );
	}

	public function test_with_the_checks_block_on_it_needs_a_clean_round(): void {
		$this->publisher();
		$this->set_option( 'xb_checks_block', 1 );
		$id = $this->changed_page();
		$this->assertSame( 400, $this->schedule( array( 'post:' . $id ) )->get_status(), 'no outcome' );
		$errors = array(
			'errors'   => 1,
			'warnings' => 0,
			'rules'    => array( 'img-alt' => 1 ),
		);
		$this->assertSame( 400, $this->schedule( array( 'post:' . $id ), HOUR_IN_SECONDS, array( 'checks' => $errors ) )->get_status(), 'with errors' );
		$clean = array(
			'errors'   => 0,
			'warnings' => 2,
			'rules'    => array( 'noindex' => 2 ),
		);
		$this->assertSame( 200, $this->schedule( array( 'post:' . $id ), HOUR_IN_SECONDS, array( 'checks' => $clean ) )->get_status() );
	}

	public function test_the_current_user_is_restored_even_when_publish_fails(): void {
		$this->publisher();
		$id   = $this->changed_page();
		$view = $this->scheduled( array( 'post:' . $id ) );
		$fail = static function (): never {
			throw new \RuntimeException( 'errore di prova' );
		};
		add_filter( 'wp_insert_post_data', $fail, 1 );
		try {
			wp_set_current_user( 0 );
			$this->fire( $view );
		} finally {
			remove_filter( 'wp_insert_post_data', $fail, 1 );
		}
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 'errore', $this->last()['code'] );
		$this->assertSame( 'Titolo', $this->heading( $id ) );
	}
	public function test_the_release_screen_shows_the_line_the_cancel_button_and_the_cron_warning(): void {
		$this->publisher();
		$id = $this->changed_page();
		$this->scheduled( array( 'post:' . $id ) );
		ob_start();
		\XB\Admin\ReleasesScreen::render_scheduled();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-xb-scheduled', $html );
		$this->assertStringContainsString( 'Programmata per il', $html );
		$this->assertStringContainsString( 'xb_admin_schedule_cancel', $html );
		if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
			$this->assertStringContainsString( 'wp-cron.php?doing_wp_cron', $html, 'the line for the hosting' );
		}
		$this->assertSame( 200, $this->request( 'DELETE', '/releases/schedule' )->get_status() );
		ob_start();
		\XB\Admin\ReleasesScreen::render_scheduled();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Annullata la release programmata', $html );
		$this->assertSame( 200, $this->request( 'DELETE', '/releases/schedule', array( 'last' => true ) )->get_status() );
		$this->assertNull( Scheduled::last_view() );
	}
}
