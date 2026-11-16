<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

use XB\Plugin;

/**
 * The exit itself — docs/spec/export-core.md §17, doc 25 of Histeon Publish §5 (F1-F4) and §11 step 7.
 *
 * plan() says what the exit would write, with a fingerprint, and writes nothing. apply() writes only what a plan with
 * the same fingerprint showed, after keeping a backup of everything it replaces: the forms made in the chosen form
 * plugin (with the messages received), every document with Histeon blocks converted in its own post (same IDs,
 * addresses, authors and dates), the template of each page that had one in Histeon CMS, and the values of the
 * fields in the chosen field plugin's form. restore() puts the last backup back. check() looks for what is left;
 * finish() switches to the site theme and turns Histeon off. As with the export to Etch, a release of Histeon must not
 * be published after apply(): it would put the pages back to Histeon's blocks.
 */
final class Departure {

	public const BACKUP      = 'xb_core_export_backup';
	private const MAX_BACKUPS = 3;
	/** Marks a meta that did not exist in a backup. */
	private const ABSENT = '__xb_absent__';

	public function __construct( private readonly ?Fields\Adapter $fields = null, private readonly ?Forms\Adapter $forms = null ) {
	}

	/** Writing the exit: an administrator who may also write any HTML (the converted blocks keep the site's markup). */
	public static function can_write(): bool {
		return Report::can_read() && current_user_can( 'unfiltered_html' );
	}

	/**
	 * What the exit would write, and its fingerprint. Nothing is written.
	 *
	 * @return array{fingerprint: string, documents: list<array{id: int, type: string, title: string}>, pages: list<array{id: int, title: string, template: string}>, fields: array{plugin: string, posts: list<int>}, forms: array{plugin: string, names: list<string>, messages: int}, notes: list<string>}
	 */
	public function plan(): array {
		$report    = ( new Report() )->build();
		$documents = array();
		foreach ( $report['documents'] as $entry ) {
			$documents[] = array(
				'id'    => (int) $entry['id'],
				'type'  => (string) $entry['type'],
				'title' => (string) $entry['title'],
			);
		}
		$pages  = ( new SiteTheme( $this->fields ) )->page_templates();
		$posts  = array();
		$notes  = array();
		$fields = \XB\Content\ContentTypes::live()['fields'];
		if ( null !== $this->fields ) {
			foreach ( array_keys( $fields ) as $type ) {
				if ( str_contains( (string) $type, ':' ) || 'user' === $type || array() === $fields[ $type ] ) {
					continue;
				}
				array_push( $posts, ...array_map( 'intval', get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) ) );
			}
			array_push( $notes, ...$this->fields->notes( \XB\Content\ContentTypes::live() ) );
		} elseif ( array() !== array_filter( $fields ) ) {
			$notes[] = __( 'Nessun plugin dei campi scelto: i valori restano come sono (Fondamenta li mostra), ma nessun editor li modifica.', 'xb' );
		}
		$forms    = Forms\Reader::all();
		$messages = 0;
		foreach ( $forms as $name => $form ) {
			$messages += count( Forms\Adapters::messages_of( $name ) );
			if ( null !== $this->forms ) {
				array_push( $notes, ...$this->forms->notes( $form ) );
			}
		}
		if ( array() !== $forms && null === $this->forms ) {
			$notes[] = __( 'Nessun plugin dei form scelto: i form di Histeon resteranno nelle pagine ma non invieranno.', 'xb' );
		}
		$plan = array(
			'documents' => $documents,
			'pages'     => $pages,
			'fields'    => array(
				'plugin' => null !== $this->fields ? $this->fields->id() : '',
				'posts'  => $posts,
			),
			'forms'     => array(
				'plugin'   => null !== $this->forms ? $this->forms->id() : '',
				'names'    => array_keys( $forms ),
				'messages' => $messages,
			),
			'notes'     => array_values( array_unique( $notes ) ),
		);
		$plan = array( 'fingerprint' => hash( 'sha256', (string) wp_json_encode( array( $report['fingerprint'], $plan ) ) ) ) + $plan;
		return $plan;
	}

	/**
	 * Writes the exit, only if it is exactly what the plan with this fingerprint showed, after a backup.
	 *
	 * @return array{fingerprint: string, documents: int, pages: int, fields: int, forms: int, messages: int}
	 */
	public function apply( string $confirm ): array {
		if ( ! self::can_write() ) {
			throw new \RuntimeException( __( 'Per scrivere l\'uscita serve un amministratore con i permessi xb_build e unfiltered_html.', 'xb' ) );
		}
		if ( ! Prova::ready() ) {
			throw new \RuntimeException( __( 'Per convertire servono i blocchi di Fondamenta: attiva Fondamenta 0.2 o successiva.', 'xb' ) );
		}
		$plan = $this->plan();
		if ( '' === $confirm || ! hash_equals( $plan['fingerprint'], $confirm ) ) {
			throw new \RuntimeException( __( 'L\'impronta non corrisponde all\'anteprima: il sito è cambiato, oppure l\'anteprima non è stata fatta. Rifai l\'anteprima e controllala.', 'xb' ) );
		}
		if ( array() !== $plan['forms']['names'] && null !== $this->forms && ! $this->forms->available() ) {
			/* translators: %s: plugin name */
			throw new \RuntimeException( sprintf( __( 'Attiva %s prima di scrivere: i form si creano lì.', 'xb' ), $this->forms->name() ) );
		}

		// The backup: every content, its dates and page template, every field value, as they are now.
		global $wpdb;
		$backup = array(
			'at'          => gmdate( 'c' ),
			'fingerprint' => $plan['fingerprint'],
			'theme'       => get_stylesheet(),
			'posts'       => array(),
			'templates'   => array(),
			'meta'        => array(),
			'forms'       => array(),
		);
		foreach ( $plan['documents'] as $document ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $document['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( is_array( $row ) ) {
				$backup['posts'][ $document['id'] ] = $row;
			}
		}
		foreach ( $plan['pages'] as $page ) {
			$backup['templates'][ $page['id'] ] = metadata_exists( 'post', $page['id'], '_wp_page_template' ) ? (string) get_post_meta( $page['id'], '_wp_page_template', true ) : self::ABSENT;
		}
		foreach ( $plan['fields']['posts'] as $id ) {
			$rows                  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$backup['meta'][ $id ] = array_map( static fn ( array $r ): array => array( (string) $r['meta_key'], $r['meta_value'] ), is_array( $rows ) ? $rows : array() );
		}
		$backups   = self::backups();
		$backups[] = $backup;
		update_option( self::BACKUP, array_slice( $backups, -self::MAX_BACKUPS ), false );

		// 1. The forms, made in the form plugin with the messages received: their blocks take the forms' places.
		$messages = 0;
		Converter::$forms = array();
		if ( null !== $this->forms ) {
			foreach ( Forms\Reader::all() as $name => $form ) {
				$id                        = $this->forms->create( $form );
				$messages                 += $this->forms->messages( $id, Forms\Adapters::messages_of( $name ) );
				Converter::$forms[ $name ] = $this->forms->block( $id );
				$backup['forms'][]         = array( $this->forms->id(), $id, $name );
			}
		}
		// 2. Every document converted in its own post; its dates stay.
		Converter::forget_patterns();
		$done = 0;
		foreach ( $plan['documents'] as $document ) {
			$post = get_post( $document['id'] );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$converted = Prova::convert( $post );
			$wpdb->update( $wpdb->posts, array( 'post_content' => \XB\Blocks::serialize( $converted['blocks'] ) ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the post's own row; its dates stay as they are.
			clean_post_cache( $post->ID );
			++$done;
		}
		Converter::$forms = array();
		// 3. The template of each page that had one in Histeon CMS.
		foreach ( $plan['pages'] as $page ) {
			update_post_meta( $page['id'], '_wp_page_template', $page['template'] );
		}
		// 4. The values of the fields, in the field plugin's form.
		if ( null !== $this->fields ) {
			foreach ( $plan['fields']['posts'] as $id ) {
				$post = get_post( $id );
				if ( $post instanceof \WP_Post ) {
					Fields\Adapters::write_post( $this->fields, $post );
				}
			}
		}
		$backups                    = self::backups();
		$backups[ count( $backups ) - 1 ] = $backup;
		update_option( self::BACKUP, $backups, false );
		Plugin::instance()->versioning->store()->audit(
			'export',
			'core',
			array(
				'fingerprint' => $plan['fingerprint'],
				'documents'   => $done,
				'fields'      => $plan['fields']['plugin'],
				'forms'       => $plan['forms']['plugin'],
			)
		);
		return array(
			'fingerprint' => $plan['fingerprint'],
			'documents'   => $done,
			'pages'       => count( $plan['pages'] ),
			'fields'      => null !== $this->fields ? count( $plan['fields']['posts'] ) : 0,
			'forms'       => count( $backup['forms'] ),
			'messages'    => $messages,
		);
	}

	/**
	 * Puts back what the last apply() replaced, switches back to the theme there was then, and forgets that backup.
	 * The forms made in the form plugin stay there, unused.
	 *
	 * @return array{at: string, posts: int, forms: list<array{0: string, 1: int, 2: string}>}
	 */
	public static function restore(): array {
		if ( ! self::can_write() ) {
			throw new \RuntimeException( __( 'Per ripristinare serve un amministratore con i permessi xb_build e unfiltered_html.', 'xb' ) );
		}
		$backups = self::backups();
		$backup  = array_pop( $backups );
		if ( null === $backup ) {
			throw new \RuntimeException( __( 'Nessuna copia di sicurezza dell\'uscita da ripristinare.', 'xb' ) );
		}
		global $wpdb;
		foreach ( is_array( $backup['posts'] ?? null ) ? $backup['posts'] : array() as $id => $row ) {
			$wpdb->update( $wpdb->posts, $row, array( 'ID' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( (int) $id );
		}
		foreach ( is_array( $backup['templates'] ?? null ) ? $backup['templates'] : array() as $id => $template ) {
			if ( self::ABSENT === $template ) {
				delete_post_meta( (int) $id, '_wp_page_template' );
			} else {
				update_post_meta( (int) $id, '_wp_page_template', $template );
			}
		}
		foreach ( is_array( $backup['meta'] ?? null ) ? $backup['meta'] : array() as $id => $rows ) {
			$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->postmeta,
					array(
						'post_id'    => (int) $id,
						'meta_key'   => (string) $row[0], // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => $row[1], // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
			}
			wp_cache_delete( (int) $id, 'post_meta' );
		}
		$theme = (string) ( $backup['theme'] ?? '' );
		if ( '' !== $theme && get_stylesheet() !== $theme && wp_get_theme( $theme )->exists() ) {
			switch_theme( $theme );
		}
		update_option( self::BACKUP, $backups, false );
		Plugin::instance()->versioning->store()->audit( 'export_restore', 'core', array( 'at' => (string) ( $backup['at'] ?? '' ) ) );
		return array(
			'at'    => (string) ( $backup['at'] ?? '' ),
			'posts' => count( (array) ( $backup['posts'] ?? array() ) ),
			'forms' => array_values( (array) ( $backup['forms'] ?? array() ) ),
		);
	}

	/**
	 * What is left of Histeon in the contents: documents still holding its blocks (none after apply()).
	 *
	 * @return array{left: list<array{id: int, type: string, title: string}>}
	 */
	public static function check(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_type, post_title FROM {$wpdb->posts} WHERE post_type NOT IN ('revision') AND post_status NOT IN ('auto-draft','trash','inherit') AND post_content LIKE '%<!-- wp:xb/%' ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$left = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$left[] = array(
				'id'    => (int) $r['ID'],
				'type'  => (string) $r['post_type'],
				'title' => (string) $r['post_title'],
			);
		}
		return array( 'left' => $left );
	}

	/**
	 * F3: the site theme active (it must be installed: the theme ZIP is installed by hand), Histeon turned off — not
	 * deleted: reactivating it and restore() bring the site back while the backup is there.
	 */
	public static function finish( string $theme ): void {
		if ( ! self::can_write() ) {
			throw new \RuntimeException( __( 'Per concludere l\'uscita serve un amministratore con i permessi xb_build e unfiltered_html.', 'xb' ) );
		}
		if ( array() === self::backups() ) {
			throw new \RuntimeException( __( 'L\'uscita non è stata scritta: prima l\'anteprima e la scrittura.', 'xb' ) );
		}
		$site = wp_get_theme( $theme );
		if ( ! $site->exists() || SiteTheme::PARENT !== $site->get_template() ) {
			/* translators: %s: theme slug */
			throw new \RuntimeException( sprintf( __( 'Il tema del sito «%s» non è installato: caricalo da Aspetto → Temi → Aggiungi nuovo tema.', 'xb' ), $theme ) );
		}
		if ( get_stylesheet() !== $theme ) {
			switch_theme( $theme );
		}
		Plugin::instance()->versioning->store()->audit( 'export_finish', 'core', array( 'theme' => $theme ) );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( plugin_basename( XB_FILE ) );
	}

	/** @return list<array<string, mixed>> */
	public static function backups(): array {
		$stored = get_option( self::BACKUP, array() );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/** The slug the site theme gets (SiteTheme::build()). */
	public static function theme_slug(): string {
		$name = trim( html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$base = sanitize_title( '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return ( '' !== $base ? $base : 'sito' ) . '-tema';
	}
}
