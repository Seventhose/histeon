<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Plugin;

/**
 * The scheduled release (docs/spec/versioning.md §11, threat model T30): one release per site, chosen in the
 * builder's Publish dialog for a day and a time, that goes out only if everything is still exactly as it was
 * when it was scheduled, with the permissions of whoever scheduled it.
 *
 * - The record is one option, not autoloaded, signed with an HMAC whose key comes from wp-config.php salts.
 * - The cron event carries the record's random id; the handler takes the release lock, reads the record again and
 *   removes it before anything else, so a second run (the WP-Cron lock is not atomic) finds nothing.
 * - Whoever scheduled is the current user only for the call to publish(), and never afterwards.
 * - No ability, no WP-CLI command and no route of the links schedules: only the builder, with its cookie.
 */
final class Scheduled {

	/** The scheduled release (not autoloaded): at most one per site. */
	public const OPTION = 'xb_scheduled_release';

	/** What happened to the last one (published, skipped, cancelled), for the line in the dialog and in wp-admin. */
	public const LAST = 'xb_scheduled_release_last';

	/** The cron event, with the record's id as its only argument. */
	public const HOOK = 'xb_scheduled_release';

	/** Format of the record: one the code does not know never goes out. */
	public const FORMAT = 1;

	/** «Programma per…» starts at least this far ahead: for now there is «Pubblica ora». */
	public const MIN_AHEAD = 5 * MINUTE_IN_SECONDS;

	/** And at most this far. */
	public const MAX_AHEAD = 60 * DAY_IN_SECONDS;

	/** Late by more than this (WP-Cron runs only on a visit), the release does not go out (decided, §11.5). */
	public const TOLERANCE = 2 * HOUR_IN_SECONDS;

	/** How long the line with the outcome stays, unless someone closes it. */
	private const LAST_DAYS = 7;

	/** @var (callable(): int)|null The clock, replaced by tests: never sleep() in a test. */
	private static $clock = null;

	/** The id of the scheduled release being published now: its own release does not cancel it. */
	private static ?string $running = null;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
		// A visit to wp-admin after the tolerance closes a record whose event never ran (§11.5).
		add_action( 'admin_init', array( self::class, 'expire_overdue' ) );
	}

	// ------------------------------------------------------------------ time

	public static function now(): int {
		return null === self::$clock ? time() : (int) ( self::$clock )();
	}

	/** @param (callable(): int)|null $clock Null: the real time. */
	public static function set_clock( ?callable $clock ): void {
		self::$clock = $clock;
	}

	/**
	 * The instant (UTC seconds) of a date and time written in the site's time zone, as `<input type="datetime-local">`
	 * sends it (2026-11-20T09:00). A time that does not exist there (the day the clocks go forward) is refused; one that
	 * exists twice (the day they go back) is the first.
	 */
	public static function parse_local( string $local ): int {
		if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?::00)?$/', $local, $m ) ) {
			throw new VersioningException( __( 'Data e ora non valide.', 'xb' ), 400 );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $m[1], wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d\TH:i' ) !== $m[1] ) {
			throw new VersioningException( __( 'Quest\'ora non esiste nel fuso del sito (il giorno del passaggio all\'ora legale) o la data non è valida.', 'xb' ), 400 );
		}
		return $date->getTimestamp();
	}

	// ------------------------------------------------------------------ scheduling

	/**
	 * Schedules a release of $docs (null: every change) for $local, in the site's time zone. The caller has already
	 * kept only the documents the current user may see (Rest\Controller::visible_changes()).
	 *
	 * @param list<string>|null $docs
	 * @return array<string, mixed> The view of the scheduled release (view()).
	 */
	public static function schedule( string $local, ?string $version, string $notes, ?array $docs, mixed $checks ): array {
		if ( ! current_user_can( 'xb_build' ) || ! current_user_can( 'xb_publish' ) ) {
			throw new VersioningException( __( 'Serve il permesso xb_publish per programmare una release.', 'xb' ), 403 );
		}
		self::expire_overdue();
		$at  = self::parse_local( $local );
		$now = self::now();
		if ( $at < $now + self::MIN_AHEAD ) {
			throw new VersioningException( __( 'Scegli un\'ora almeno 5 minuti avanti: per pubblicare subito c\'è «Pubblica ora».', 'xb' ), 400 );
		}
		if ( $at > $now + self::MAX_AHEAD ) {
			throw new VersioningException( __( 'Si programma al più 60 giorni avanti.', 'xb' ), 400 );
		}
		// «Blocca la pubblicazione se i controlli trovano errori» (§11.4 e): only after a whole round without errors.
		$clean = ReleaseChecks::clean( $checks );
		if ( \XB\Admin\ReleasesScreen::checks_block() && ( null === $clean || $clean['errors'] > 0 ) ) {
			throw new VersioningException( __( 'Con «Blocca la pubblicazione se i controlli trovano errori» attiva, si programma solo dopo un giro dei controlli finito senza errori.', 'xb' ), 400 );
		}
		$versioning = Plugin::instance()->versioning;
		$store      = $versioning->store();
		return $store->locked(
			'release',
			static function () use ( $at, $version, $notes, $docs, $clean, $versioning, $store ): array {
				wp_cache_delete( self::OPTION, 'options' );
				if ( false !== get_option( self::OPTION, false ) ) {
					throw new VersioningException( __( 'C\'è già una release programmata: annullala prima di programmarne un\'altra.', 'xb' ), 409 );
				}
				$version   = $versioning->next_version( $version );
				$selection = $versioning->selection( $docs );
				if ( array() === $selection['docs'] && ! $selection['media'] && ! $selection['contents'] ) {
					throw new VersioningException( __( 'Non ci sono modifiche da pubblicare.', 'xb' ), 400 );
				}
				$heads = array();
				$prod  = array();
				foreach ( $selection['docs'] as $doc ) {
					$heads[ $doc ] = (string) $store->head( $doc, Store::DEV );
					$prod[ $doc ]  = (string) $store->head( $doc, Store::PROD );
				}
				foreach ( array( Documents::MEDIA, Documents::CONTENTS ) as $extra ) {
					if ( $selection[ $extra ] ) {
						$heads[ $extra ] = '';
					}
				}
				$approval = array();
				foreach ( $selection['docs'] as $doc ) {
					$post = Documents::post_id( $doc );
					$seen = null === $post ? null : \XB\Review\Comments::approval( $post );
					if ( null !== $seen ) {
						$approval[ $doc ] = array(
							'name' => $seen['name'],
							'head' => $seen['head'],
						);
					}
				}
				$latest = $store->releases( 1 );
				$record = array(
					'v'          => self::FORMAT,
					'id'         => bin2hex( random_bytes( 16 ) ),
					'at'         => $at,
					'tz'         => wp_timezone_string(),
					'local'      => wp_date( 'Y-m-d H:i', $at ),
					'docs'       => $heads,
					'prod'       => $prod,
					'release'    => (int) ( $latest[0]['id'] ?? 0 ),
					'waiting'    => self::waiting(),
					'version'    => $version,
					'notes'      => $notes,
					'user'       => get_current_user_id(),
					'unfiltered' => current_user_can( 'unfiltered_html' ),
					'created'    => self::now(),
					'checks'     => $clean,
					'approval'   => $approval,
				);
				$record['mac'] = self::mac( $record );
				add_option( self::OPTION, $record, '', false );
				$scheduled = wp_schedule_single_event( $at, self::HOOK, array( $record['id'] ), true );
				if ( true !== $scheduled ) {
					delete_option( self::OPTION );
					throw new VersioningException( __( 'WordPress non ha accettato l\'evento del cron: riprova.', 'xb' ), 500 );
				}
				delete_option( self::LAST );
				$store->audit(
					'schedule',
					'schedule:' . $record['id'],
					array(
						'at'      => gmdate( 'c', $at ),
						'version' => $version,
						'docs'    => array_keys( $heads ),
					)
				);
				return (array) self::view();
			}
		);
	}

	/**
	 * Cancels the scheduled release, if there is one. $code says why (a-mano, superata, ripristino, disattivato);
	 * whoever had scheduled gets an email when someone else, or something else, cancelled it.
	 */
	public static function cancel( string $code = 'a-mano', string $reason = '' ): bool {
		$store = Plugin::instance()->versioning->store();
		return (bool) $store->locked(
			'release',
			static function () use ( $code, $reason, $store ): bool {
				wp_cache_delete( self::OPTION, 'options' );
				$record = get_option( self::OPTION, false );
				wp_unschedule_hook( self::HOOK );
				if ( ! is_array( $record ) ) {
					return false;
				}
				delete_option( self::OPTION );
				$reason = '' === $reason ? __( 'Annullata a mano.', 'xb' ) : $reason;
				$id     = is_string( $record['id'] ?? null ) ? $record['id'] : '';
				$store->audit(
					'schedule_cancel',
					'schedule:' . $id,
					array(
						'code'    => $code,
						'reason'  => $reason,
						'version' => (string) ( $record['version'] ?? '' ),
					)
				);
				self::remember( 'cancelled', $code, $reason, $record );
				$user = (int) ( $record['user'] ?? 0 );
				if ( 'a-mano' !== $code || get_current_user_id() !== $user ) {
					self::mail(
						$record,
						self::valid( $record ),
						/* translators: %s: site name */
						sprintf( __( '[%s] Release programmata annullata', 'xb' ), self::site_name() ),
						/* translators: 1: version, 2: date and time, 3: reason */
						sprintf( __( 'La release %1$s programmata per il %2$s è stata annullata: %3$s', 'xb' ), (string) ( $record['version'] ?? '' ), self::local( (int) ( $record['at'] ?? 0 ) ), $reason )
					);
				}
				return true;
			}
		);
	}

	/**
	 * A release went out (Versioning::after_release): one by hand, from Histeon Publish or a file that touches the
	 * documents of the scheduled release cancels it, as does any restore (§11.4 d, g). One on other documents leaves it.
	 *
	 * @param list<string> $docs
	 */
	public static function superseded( int $release, array $docs, string $action ): void {
		if ( null !== self::$running ) {
			return;
		}
		$record = get_option( self::OPTION, false );
		if ( ! is_array( $record ) ) {
			return;
		}
		$version = (string) ( Plugin::instance()->versioning->store()->get_release( $release )['version'] ?? '' );
		if ( 'restore' === $action ) {
			/* translators: %s: version of the release that restored an earlier one */
			self::cancel( 'ripristino', sprintf( __( 'superata dal ripristino (release %s).', 'xb' ), $version ) );
			return;
		}
		$scheduled = is_array( $record['docs'] ?? null ) ? array_map( 'strval', array_keys( $record['docs'] ) ) : array();
		if ( array() !== array_intersect( $docs, $scheduled ) ) {
			/* translators: %s: version of the release that went out first */
			self::cancel( 'superata', sprintf( __( 'superata dalla release %s, che ha pubblicato gli stessi documenti.', 'xb' ), $version ) );
		}
	}

	/** Histeon deactivated: the event goes, the scheduled release is cancelled and nothing starts again on reactivation. */
	public static function deactivated(): void {
		try {
			self::cancel( 'disattivato', __( 'Histeon è stato disattivato.', 'xb' ) );
		} catch ( \Throwable $e ) {
			wp_unschedule_hook( self::HOOK ); // Never block a deactivation.
		}
	}

	/** A record past its tolerance, whose event never ran, is closed as skipped. */
	public static function expire_overdue(): void {
		$record = get_option( self::OPTION, false );
		if ( is_array( $record ) && is_string( $record['id'] ?? null ) && self::now() > (int) ( $record['at'] ?? 0 ) + self::TOLERANCE ) {
			self::run( $record['id'] );
		}
	}

	/** The version reserved for the scheduled release, which no other release may take (§11.4 d). */
	public static function reserved(): ?string {
		if ( null !== self::$running ) {
			return null;
		}
		$record = get_option( self::OPTION, false );
		return is_array( $record ) && is_string( $record['version'] ?? null ) ? $record['version'] : null;
	}

	// ------------------------------------------------------------------ the event

	/** The cron event: publishes the scheduled release $id, or skips it saying why. */
	public static function run( mixed $id ): void {
		$id     = is_string( $id ) ? $id : '';
		$record = get_option( self::OPTION, false );
		if ( '' === $id || ! is_array( $record ) || ( $record['id'] ?? null ) !== $id ) {
			return; // An event left by a cancelled schedule.
		}
		if ( self::valid( $record ) && self::now() < (int) $record['at'] ) {
			self::early( $record );
			return;
		}
		$store = Plugin::instance()->versioning->store();
		try {
			$store->locked( 'release', static fn () => self::run_locked( $id ) );
		} catch ( VersioningException $e ) {
			// Another release holds the lock: try again in a minute (the record is still there).
			if ( false === wp_next_scheduled( self::HOOK, array( $id ) ) ) {
				wp_schedule_single_event( self::now() + MINUTE_IN_SECONDS, self::HOOK, array( $id ) );
			}
		}
	}

	/**
	 * Run before its time (WP-CLI, WP Crontrol): nothing, record and event stay; one entry in the Registro.
	 *
	 * @param array<string, mixed> $record
	 */
	private static function early( array $record ): void {
		$store  = Plugin::instance()->versioning->store();
		$object = 'schedule:' . $record['id'];
		if ( array() === $store->audit_of( 'schedule_early', array( $object ) ) ) {
			$store->audit( 'schedule_early', $object, array( 'at' => gmdate( 'c', (int) $record['at'] ) ) );
		}
		if ( false === wp_next_scheduled( self::HOOK, array( $record['id'] ) ) ) {
			wp_schedule_single_event( (int) $record['at'], self::HOOK, array( $record['id'] ) );
		}
	}

	private static function run_locked( string $id ): void {
		wp_cache_delete( self::OPTION, 'options' );
		$record = get_option( self::OPTION, false );
		if ( ! is_array( $record ) || ( $record['id'] ?? null ) !== $id ) {
			return; // A second run of the same event: the first one took the record.
		}
		delete_option( self::OPTION );
		wp_unschedule_hook( self::HOOK );
		if ( ! self::valid( $record ) ) {
			$known = self::FORMAT === ( $record['v'] ?? null );
			self::skip( $record, 'alterata', $known ? __( 'la programmazione è stata alterata.', 'xb' ) : __( 'formato della programmazione non riconosciuto.', 'xb' ), false );
			return;
		}
		$late = self::now() - (int) $record['at'];
		if ( $late > self::TOLERANCE ) {
			self::skip( $record, 'troppo-tardi', __( 'troppo in ritardo: il cron di WordPress è partito più di 2 ore dopo l\'ora scelta.', 'xb' ) );
			return;
		}
		$why = self::verify( $record );
		if ( null !== $why ) {
			self::skip( $record, $why[0], $why[1] );
			return;
		}
		$versioning = Plugin::instance()->versioning;
		$before     = get_current_user_id();
		self::$running = $id;
		wp_set_current_user( (int) $record['user'] );
		try {
			$done = $versioning->publish( (string) $record['version'], (string) $record['notes'], array_map( 'strval', array_keys( $record['docs'] ) ), $record['checks'] );
		} catch ( \Throwable $e ) {
			wp_set_current_user( $before );
			self::$running = null;
			/* translators: %s: error message */
			self::skip( $record, 'errore', sprintf( __( 'la pubblicazione non è riuscita: %s', 'xb' ), $e->getMessage() ) );
			return;
		} finally {
			wp_set_current_user( $before );
			self::$running = null;
		}
		$versioning->store()->audit(
			'schedule_done',
			'schedule:' . $id,
			array(
				'release' => $done['id'],
				'version' => $done['version'],
				'late'    => max( 0, $late ),
			)
		);
		self::remember( 'published', 'pubblicata', '', $record, $done['id'] );
		/* translators: 1: version, 2: date and time */
		$body = sprintf( __( 'È uscita la release %1$s programmata per il %2$s.', 'xb' ), $done['version'], self::local( (int) $record['at'] ) );
		if ( $late >= 5 * MINUTE_IN_SECONDS ) {
			/* translators: %d: minutes */
			$body .= "\n" . sprintf( __( 'È partita con %d minuti di ritardo: il cron di WordPress parte solo quando qualcuno visita il sito.', 'xb' ), (int) floor( $late / 60 ) );
		}
		$changed = self::approval_changed( $record );
		if ( array() !== $changed ) {
			/* translators: %s: list of pages */
			$body .= "\n" . sprintf( __( 'L\'approvazione del cliente non è più quella vista alla programmazione per: %s.', 'xb' ), implode( ', ', $changed ) );
		}
		$body .= "\n" . __( 'Se il sito ha una copia statica, si aggiorna nei minuti seguenti; l\'esito è nel Registro.', 'xb' );
		/* translators: %s: site name */
		self::mail( $record, true, sprintf( __( '[%s] Pubblicata la release programmata', 'xb' ), self::site_name() ), $body );
	}

	/**
	 * Every condition of §11.3, point 5: null when the release can go out, or [code, reason].
	 *
	 * @param array<string, mixed> $record
	 * @return array{0: string, 1: string}|null
	 */
	private static function verify( array $record ): ?array {
		$user = get_userdata( (int) $record['user'] );
		if ( ! $user instanceof \WP_User ) {
			return array( 'utente-assente', __( 'chi aveva programmato non esiste più.', 'xb' ) );
		}
		if ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) {
			return array( 'permessi-cambiati', __( 'chi aveva programmato non fa più parte di questo sito.', 'xb' ) );
		}
		if ( ! user_can( $user, 'xb_build' ) || ! user_can( $user, 'xb_publish' ) ) {
			return array( 'permesso-perso', __( 'chi aveva programmato non ha più il permesso di pubblicare.', 'xb' ) );
		}
		if ( user_can( $user, 'unfiltered_html' ) !== (bool) $record['unfiltered'] ) {
			return array( 'permessi-cambiati', __( 'il permesso unfiltered_html di chi aveva programmato è cambiato.', 'xb' ) );
		}
		$store = Plugin::instance()->versioning->store();
		$docs  = self::documents( $record );
		foreach ( $docs as $doc ) {
			$post = Documents::post_id( $doc );
			if ( null !== $post && ! user_can( $user, 'edit_post', $post ) ) {
				return array( 'permessi-cambiati', __( 'chi aveva programmato non può più modificare una delle pagine.', 'xb' ) );
			}
		}
		foreach ( $docs as $doc ) {
			if ( ! Documents::valid( $doc ) || $store->head( $doc, Store::DEV ) !== $record['docs'][ $doc ] ) {
				/* translators: %s: document name */
				return array( 'documento-cambiato', sprintf( __( '«%s» è cambiato in sviluppo dopo la programmazione.', 'xb' ), Documents::valid( $doc ) ? Documents::label( $doc ) : $doc ) );
			}
		}
		if ( self::release_since( $record ) ) {
			return array( 'release-intermedia', __( 'un\'altra release ha toccato gli stessi documenti dopo la programmazione.', 'xb' ) );
		}
		$now = Plugin::instance()->versioning->selection( array_map( 'strval', array_keys( $record['docs'] ) ) )['docs'];
		sort( $now );
		$then = $docs;
		sort( $then );
		if ( $now !== $then ) {
			return array( 'componente-nuovo', __( 'i componenti nuovi usati dalle pagine non sono più gli stessi.', 'xb' ) );
		}
		if ( $store->version_exists( (string) $record['version'] ) ) {
			return array( 'versione-occupata', __( 'la versione riservata è già stata usata.', 'xb' ) );
		}
		$waiting = self::waiting();
		if ( ( $waiting['media'] > 0 || $waiting['contents'] > 0 ) && $waiting['hash'] !== ( $record['waiting']['hash'] ?? null ) ) {
			return array( 'contenuti-nuovi', __( 'sono arrivati contenuti nuovi da Histeon CMS dopo la programmazione.', 'xb' ) );
		}
		if ( Plugin::instance()->versioning->conflicts( $docs ) > 0 ) {
			return array( 'conflitti', __( 'il cliente ha cambiato un testo che cambia anche questa release.', 'xb' ) );
		}
		return null;
	}

	/**
	 * Whether a release (publish or restore) touched the documents after the scheduling: a second, cheap defence
	 * (every release also moves the dev heads, caught before).
	 *
	 * @param array<string, mixed> $record
	 */
	private static function release_since( array $record ): bool {
		$store   = Plugin::instance()->versioning->store();
		$objects = array();
		foreach ( $store->releases( 50 ) as $release ) {
			if ( $release['id'] > (int) $record['release'] ) {
				$objects[] = 'release:' . $release['id'];
			}
		}
		$docs = self::documents( $record );
		foreach ( array( 'publish', 'restore' ) as $action ) {
			foreach ( $store->audit_of( $action, $objects ) as $details ) {
				$touched = json_decode( $details, true );
				if ( 'restore' === $action || ( is_array( $touched['docs'] ?? null ) && array() !== array_intersect( $docs, $touched['docs'] ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// ------------------------------------------------------------------ record

	/**
	 * The documents of the record (no media, no contents).
	 *
	 * @param array<string, mixed> $record
	 * @return list<string>
	 */
	private static function documents( array $record ): array {
		$docs = is_array( $record['docs'] ?? null ) ? array_map( 'strval', array_keys( $record['docs'] ) ) : array();
		return array_values( array_diff( $docs, array( Documents::MEDIA, Documents::CONTENTS ) ) );
	}

	/**
	 * The HMAC of every field but `mac`, with a key derived from the salts in wp-config.php (not in the database).
	 *
	 * @param array<string, mixed> $record
	 */
	private static function mac( array $record ): string {
		unset( $record['mac'] );
		return hash_hmac( 'sha256', Store::encode( self::canonical( $record ) ), hash_hmac( 'sha256', 'xb-scheduled-release', wp_salt( 'auth' ) ) );
	}

	/**
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private static function canonical( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::canonical( $item );
			}
		}
		return $value;
	}

	/** @param array<string, mixed> $record */
	private static function valid( array $record ): bool {
		if ( self::FORMAT !== ( $record['v'] ?? null ) || ! is_string( $record['mac'] ?? null ) || ! hash_equals( self::mac( $record ), $record['mac'] ) ) {
			return false;
		}
		return is_string( $record['id'] ?? null ) && is_int( $record['at'] ?? null ) && is_array( $record['docs'] ?? null ) && is_int( $record['user'] ?? null )
			&& is_string( $record['version'] ?? null ) && is_string( $record['notes'] ?? null ) && is_int( $record['release'] ?? null );
	}

	/**
	 * Media and contents from Histeon CMS waiting for a release, with a fingerprint of what they are (§11.4 a).
	 *
	 * @return array{media: int, contents: int, hash: string}
	 */
	private static function waiting(): array {
		$media    = \XB\Remote\PageProfileAdapter::pending_media_marks();
		$contents = \XB\Remote\ContentProfileAdapter::queue();
		return array(
			'media'    => count( $media ),
			'contents' => count( $contents['entities'] ),
			'hash'     => hash( 'sha256', Store::encode( array( $media, self::canonical( $contents ) ) ) ),
		);
	}

	/**
	 * Pages whose client approval is not the one seen when scheduling (an information, §11.4 c).
	 *
	 * @param array<string, mixed> $record
	 * @return list<string>
	 */
	private static function approval_changed( array $record ): array {
		$out = array();
		foreach ( (array) ( $record['approval'] ?? array() ) as $doc => $then ) {
			$post = Documents::post_id( (string) $doc );
			$now  = null === $post ? null : \XB\Review\Comments::approval( $post );
			if ( null === $now || ! is_array( $then ) || $now['name'] !== ( $then['name'] ?? null ) || $now['head'] !== ( $then['head'] ?? null ) ) {
				$out[] = Documents::label( (string) $doc );
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------ outcome

	/** @param array<string, mixed> $record */
	private static function skip( array $record, string $code, string $reason, bool $trusted = true ): void {
		$id = is_string( $record['id'] ?? null ) ? $record['id'] : '';
		Plugin::instance()->versioning->store()->audit(
			'schedule_skip',
			'schedule:' . $id,
			array(
				'code'    => $code,
				'reason'  => $reason,
				'version' => (string) ( $record['version'] ?? '' ),
			)
		);
		self::remember( 'skipped', $code, $reason, $record );
		self::mail(
			$record,
			$trusted,
			/* translators: %s: site name */
			sprintf( __( '[%s] Release programmata saltata', 'xb' ), self::site_name() ),
			/* translators: 1: version, 2: date and time, 3: reason */
			sprintf( __( 'La release %1$s programmata per il %2$s non è stata pubblicata: %3$s', 'xb' ), (string) ( $record['version'] ?? '' ), self::local( (int) ( $record['at'] ?? 0 ) ), $reason ) . "\n" . __( 'Non è andato online nulla. Controlla e pubblica a mano, oppure programma di nuovo.', 'xb' )
		);
	}

	/** @param array<string, mixed> $record */
	private static function remember( string $state, string $code, string $reason, array $record, int $release = 0 ): void {
		update_option(
			self::LAST,
			array(
				'state'   => $state,
				'code'    => $code,
				'reason'  => $reason,
				'version' => (string) ( $record['version'] ?? '' ),
				'at'      => (int) ( $record['at'] ?? 0 ),
				'user'    => (int) ( $record['user'] ?? 0 ),
				'release' => $release,
				'done'    => self::now(),
			),
			false
		);
	}

	/**
	 * Plain text, no link with a token: to whoever scheduled, or to the site's address when the user is gone or the
	 * record is not trusted (a forged record can name anyone).
	 *
	 * @param array<string, mixed> $record
	 */
	private static function mail( array $record, bool $trusted, string $subject, string $body ): void {
		$user = $trusted ? get_userdata( (int) ( $record['user'] ?? 0 ) ) : false;
		$to   = $user instanceof \WP_User && is_email( $user->user_email ) ? $user->user_email : (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}
		$body .= "\n\n" . __( 'Release del sito:', 'xb' ) . ' ' . \XB\Admin\Menu::url( \XB\Admin\ReleasesScreen::SLUG );
		wp_mail( $to, $subject, $body );
	}

	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/** An instant as the site writes it: date, time and offset in the site's time zone. */
	public static function local( int $at ): string {
		return (string) wp_date( 'd/m/Y H:i (P)', $at );
	}

	// ------------------------------------------------------------------ views

	/**
	 * The scheduled release as the builder and wp-admin show it, or null. Raw values (UTC instant, time zone names):
	 * the builder formats them (editor/src/lib/schedule.ts).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function view(): ?array {
		$record = get_option( self::OPTION, false );
		if ( ! is_array( $record ) ) {
			return null;
		}
		$valid = self::valid( $record );
		$user  = $valid ? get_userdata( (int) $record['user'] ) : false;
		$docs  = $valid ? self::documents( $record ) : array();
		return array(
			'id'        => (string) ( $record['id'] ?? '' ),
			'valid'     => $valid,
			'at'        => (int) ( $record['at'] ?? 0 ),
			'local'     => self::local( (int) ( $record['at'] ?? 0 ) ),
			'timezone'  => wp_timezone_string(),
			'tzThen'    => (string) ( $record['tz'] ?? '' ),
			'localThen' => (string) ( $record['local'] ?? '' ),
			'tzChanged' => (string) ( $record['tz'] ?? '' ) !== wp_timezone_string(),
			'version'   => $valid ? (string) $record['version'] : '',
			'notes'     => $valid ? (string) $record['notes'] : '',
			'user'      => $valid ? (int) $record['user'] : 0,
			'userName'  => $user instanceof \WP_User ? $user->display_name : '',
			'docs'      => $docs,
			'labels'    => array_map( static fn ( string $doc ): string => Documents::valid( $doc ) ? Documents::label( $doc ) : $doc, $docs ),
			'media'     => $valid && array_key_exists( Documents::MEDIA, $record['docs'] ),
			'contents'  => $valid && array_key_exists( Documents::CONTENTS, $record['docs'] ),
			'mine'      => $valid && get_current_user_id() === (int) $record['user'],
		);
	}

	/**
	 * What happened to the last scheduled release, for a few days or until someone closes it, or null.
	 *
	 * @return array{state: string, code: string, reason: string, version: string, at: int, local: string, done: int, doneLocal: string, userName: string, release: int}|null
	 */
	public static function last_view(): ?array {
		$last = get_option( self::LAST, false );
		if ( ! is_array( $last ) || ! in_array( $last['state'] ?? null, array( 'published', 'skipped', 'cancelled' ), true ) ) {
			return null;
		}
		$done = (int) ( $last['done'] ?? 0 );
		if ( self::now() > $done + self::LAST_DAYS * DAY_IN_SECONDS ) {
			return null;
		}
		$user = get_userdata( (int) ( $last['user'] ?? 0 ) );
		return array(
			'state'     => (string) $last['state'],
			'code'      => (string) ( $last['code'] ?? '' ),
			'reason'    => (string) ( $last['reason'] ?? '' ),
			'version'   => (string) ( $last['version'] ?? '' ),
			'at'        => (int) ( $last['at'] ?? 0 ),
			'local'     => self::local( (int) ( $last['at'] ?? 0 ) ),
			'done'      => $done,
			'doneLocal' => self::local( $done ),
			'userName'  => $user instanceof \WP_User ? $user->display_name : '',
			'release'   => (int) ( $last['release'] ?? 0 ),
		);
	}

	/** Closes the line with the outcome of the last scheduled release. */
	public static function dismiss_last(): void {
		delete_option( self::LAST );
	}

	/**
	 * Whether the site has a system cron, and the lines to give the hosting when it has not (§11.5).
	 *
	 * @return array{system: bool, lines: list<string>, config: string}
	 */
	public static function cron(): array {
		$sites = array( site_url( 'wp-cron.php?doing_wp_cron' ) );
		$urls  = array( home_url( '/' ) );
		if ( is_multisite() ) {
			$sites = array();
			$urls  = array();
			foreach ( get_sites( array( 'number' => 50 ) ) as $site ) {
				$sites[] = get_site_url( (int) $site->blog_id, 'wp-cron.php?doing_wp_cron' );
				$urls[]  = get_home_url( (int) $site->blog_id, '/' );
			}
		}
		$lines = array();
		foreach ( $sites as $url ) {
			$lines[] = '*/5 * * * * curl -fsS "' . $url . '" >/dev/null 2>&1';
		}
		foreach ( $urls as $url ) {
			$lines[] = '*/5 * * * * cd ' . escapeshellarg( untrailingslashit( ABSPATH ) ) . ' && wp cron event run --due-now --quiet' . ( is_multisite() ? ' --url=' . escapeshellarg( $url ) : '' );
		}
		return array(
			'system' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'lines'  => $lines,
			'config' => "define( 'DISABLE_WP_CRON', true );",
		);
	}
}
