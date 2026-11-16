<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Etch;

use XB\Import\Etch\Migration;
use XB\Plugin;
use XB\Render\ComponentRegistry;
use XB\Scripts\Scripts;
use XB\Spec;
use XB\Styles\CssCompiler;
use XB\Styles\StyleRegistry;
use XB\Versioning\Documents;

/**
 * Export of the Histeon project to Etch's data on this site — docs/spec/export-etch.md.
 *
 * build() converts the live (published) Histeon content in memory; plan() adds what would change on this site and
 * a fingerprint. apply() writes only what a plan with the same fingerprint showed, after keeping a backup of
 * everything it replaces; restore() puts the last backup back. Histeon's own data (versions, styles, settings,
 * component keys) is never removed: Histeon records the new content as an edit made outside the builder, so a
 * release can bring the Histeon content back too.
 */
final class Exporter {

	public const FORMAT  = 'histos-etch-export';
	public const VERSION = 1;
	/** Backups of what apply() replaced, newest last (not autoloaded). */
	public const BACKUP = 'xb_etch_export_backup';

	private const MAX_BACKUPS   = 3;
	private const MAX_DOCS      = 2000;
	private const STATUSES      = array( 'publish', 'draft', 'private', 'future', 'pending' );
	private const COMPONENT_KEY = 'etch_component_html_key';
	private const SHEETS        = 'etch_global_stylesheets';
	private const STYLES        = 'etch_styles';
	private const LOOPS         = 'etch_loops';
	/** Templates Histeon CMS manages (a role or a private slug): no Etch equivalent (Templates::MANAGED_META). */
	private const MANAGED_TEMPLATE = '_xb_histos_managed_template';
	/** Marks an option that did not exist in a backup. */
	private const ABSENT = '__xb_absent__';

	public function __construct( private readonly string $tokens = Tokens::HISTOS ) {
		if ( ! in_array( $tokens, array( Tokens::HISTOS, Tokens::ACSS ), true ) ) {
			throw new \InvalidArgumentException( __( 'Modalità dei token non valida: histos-css oppure acss.', 'xb' ) );
		}
	}

	/** Reading the project and planning: an administrator who may build. */
	public static function can_export(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'xb_build' );
	}

	/** Writing into this site needs unfiltered_html as well. */
	public static function can_write(): bool {
		return self::can_export() && current_user_can( 'unfiltered_html' );
	}

	/** Whether the ACSS names can be used: only on a site where the owner installed Automatic.css. */
	public static function acss_available(): bool {
		$provider = Plugin::instance()->frameworks->all()['acss'] ?? null;
		return null !== $provider && $provider->available();
	}

	/**
	 * The export, in memory. Nothing is written.
	 *
	 * @return array<string, mixed>
	 */
	public function build(): array {
		if ( ! self::can_export() ) {
			throw new \RuntimeException( __( 'Serve un amministratore con il permesso xb_build per esportare.', 'xb' ) );
		}
		if ( Tokens::ACSS === $this->tokens && ! self::acss_available() ) {
			throw new \RuntimeException( __( 'I nomi di Automatic.css si usano solo su un sito dove Automatic.css è installato e attivo.', 'xb' ) );
		}
		$queries = new Queries();
		$tokens  = new Tokens( $this->tokens );
		$report  = array(
			'documents' => array(),
			'issues'    => array(),
			'skipped'   => array(),
		);
		[ $posts, $templates ] = $this->posts();
		$components            = $this->components( $posts );

		$out      = array();
		$exported = '';
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				$report['issues'][] = sprintf( /* translators: 1: post title, 2: post ID */ __( 'Non puoi modificare «%1$s» (#%2$d): saltato', 'xb' ), get_the_title( $post ), $post->ID );
				continue;
			}
			$trusted = Migration::author_trusted( $post );
			$content = $post->post_content;
			if ( ! $trusted ) {
				// What Histeon would let this author save: the same filter again, whatever reached the database.
				$content = Plugin::instance()->sanitizer->restricted_content( $content );
			}
			$converter = new Converter( $components, $queries, $tokens, $trusted );
			$blocks    = $converter->convert( parse_blocks( $content ) );
			$scripts   = $converter->place_script( $blocks, Scripts::get( $post->ID ) );
			$etch      = \XB\Blocks::serialize( $blocks );
			$exported .= $etch;
			$entry     = array(
				'id'      => $post->ID,
				'type'    => $post->post_type,
				'slug'    => $post->post_name,
				'title'   => get_the_title( $post ),
				'status'  => $post->post_status,
				'content' => $etch,
				'meta'    => array(),
				'scripts' => $scripts,
			);
			$issues  = $converter->report();
			$changes = $converter->changes();
			$key     = 'wp_block' === $post->post_type ? (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true ) : '';
			if ( '' !== $key ) {
				$current                                = (string) get_post_meta( $post->ID, self::COMPONENT_KEY, true );
				$entry['meta'][ self::COMPONENT_KEY ] = '' !== $current ? $current : self::pascal( $key );
				if ( $entry['meta'][ self::COMPONENT_KEY ] !== $key ) {
					array_unshift( $changes, sprintf( /* translators: 1: Histeon component key, 2: Etch component key */ __( 'Chiave del componente «%1$s» → «%2$s»', 'xb' ), $key, $entry['meta'][ self::COMPONENT_KEY ] ) );
				}
				$schema                                 = json_decode( (string) get_post_meta( $post->ID, ComponentRegistry::META_SCHEMA, true ), true );
				if ( is_array( $schema ) && ! empty( $schema['props'] ) ) {
					$issues[] = __( 'Schema delle props non esportato: nei dati di Etch osservati è quasi sempre vuoto e il suo formato non è noto; le istanze passano comunque i valori', 'xb' );
				}
			}
			$out[]                 = $entry;
			$report['documents'][] = array(
				'id'      => $post->ID,
				'type'    => $post->post_type,
				'title'   => $entry['title'],
				'trusted' => $trusted,
				'blocks'  => $converter->counts['blocks'],
				'omitted' => $converter->counts['omitted'],
				'scripts' => $scripts,
				'issues'  => $issues,
				'changes' => self::capped( $changes ),
			);
		}

		[ $sheets, $styles, $css ] = $this->styles( $tokens );
		$exported                  .= $css;
		foreach ( $tokens->sheets( $exported, $report['issues'] ) as $key => $sheet ) {
			$sheets[ $key ] = array(
				'name' => Tokens::SHEET_TOKENS === $key ? 'Histeon CSS – token' : 'Histeon CSS – classi',
				'css'  => CssCompiler::sanitize( $sheet ),
			);
		}
		$report['skipped'] = $this->skipped( $templates );
		if ( Tokens::ACSS === $this->tokens ) {
			$report['issues'][] = sprintf( /* translators: 1: variables, 2: classes */ __( 'Nomi di Automatic.css: %1$d usi di token e %2$d classi riscritti; i token senza un nome ACSS restano definiti nel foglio Histeon CSS', 'xb' ), $tokens->counts['variables'], $tokens->counts['classes'] );
		}

		$bundle = array(
			'format'  => self::FORMAT,
			'version' => self::VERSION,
			'tokens'  => $this->tokens,
			'posts'   => $out,
			'options' => array(
				self::LOOPS  => $queries->entries,
				self::SHEETS => $this->merge_existing( self::SHEETS, $sheets ),
				self::STYLES => $this->merge_existing( self::STYLES, $styles ),
			),
		);
		$bundle['options'][ self::LOOPS ] = $this->merge_existing( self::LOOPS, $bundle['options'][ self::LOOPS ] );
		$bundle['fingerprint']            = self::fingerprint( $bundle );
		$bundle['report']                 = $report;
		return $bundle;
	}

	/**
	 * The export plus what it would change on this site: posts whose content changes (and whether they already hold
	 * Etch blocks), component keys, and every option entry added, replaced or unchanged.
	 *
	 * @return array<string, mixed>
	 */
	public function plan(): array {
		$bundle = $this->build();
		$diff   = array(
			'posts'   => array(),
			'options' => array(),
		);
		foreach ( $bundle['posts'] as $post ) {
			$current           = self::stored( (int) $post['id'] );
			$diff['posts'][] = array(
				'id'      => $post['id'],
				'title'   => $post['title'],
				'changes' => $current !== $post['content'],
				'etch'    => 1 === preg_match( '/<!--\s+wp:etch\//', $current ),
				'key'     => array_key_exists( self::COMPONENT_KEY, $post['meta'] ) && (string) get_post_meta( (int) $post['id'], self::COMPONENT_KEY, true ) !== $post['meta'][ self::COMPONENT_KEY ] ? $post['meta'][ self::COMPONENT_KEY ] : null,
			);
		}
		foreach ( $bundle['options'] as $option => $entries ) {
			$current = get_option( $option, array() );
			$current = is_array( $current ) ? $current : array();
			foreach ( $entries as $key => $entry ) {
				$diff['options'][] = array(
					'option' => $option,
					'key'    => (string) $key,
					'action' => ! array_key_exists( $key, $current ) ? 'add' : ( wp_json_encode( $current[ $key ] ) === wp_json_encode( $entry ) ? 'same' : 'replace' ),
				);
			}
		}
		$bundle['diff']    = $diff;
		$bundle['written'] = false;
		return $bundle;
	}

	/**
	 * Writes the export into this site, only if it is exactly what the plan with this fingerprint showed. Everything
	 * replaced (post content, component keys, scripts moved into Etch elements, the three options) is kept in a
	 * backup first.
	 *
	 * @return array<string, mixed>
	 */
	public function apply( string $confirm ): array {
		if ( ! self::can_write() ) {
			throw new \RuntimeException( __( 'Per scrivere nei dati di Etch serve un amministratore con i permessi xb_build e unfiltered_html.', 'xb' ) );
		}
		$plan = $this->plan();
		if ( '' === $confirm || ! hash_equals( (string) $plan['fingerprint'], $confirm ) ) {
			throw new \RuntimeException( __( 'L\'impronta non corrisponde all\'anteprima: il progetto o i dati di Etch sono cambiati, oppure l\'anteprima non è stata fatta. Rifai l\'anteprima e controllala.', 'xb' ) );
		}
		$backup = array(
			'at'          => gmdate( 'c' ),
			'fingerprint' => $plan['fingerprint'],
			'posts'       => array(),
			'options'     => array(),
		);
		foreach ( $plan['posts'] as $post ) {
			$id                     = (int) $post['id'];
			$key                    = get_post_meta( $id, self::COMPONENT_KEY, true );
			$backup['posts'][ $id ] = array(
				'content'  => self::stored( $id ),
				'key'      => metadata_exists( 'post', $id, self::COMPONENT_KEY ) ? (string) $key : null,
				'script'   => Scripts::get( $id ),
				'modified' => array( self::field( 'post_modified', $id ), self::field( 'post_modified_gmt', $id ) ),
			);
		}
		foreach ( array_keys( $plan['options'] ) as $option ) {
			$backup['options'][ $option ] = get_option( (string) $option, self::ABSENT );
		}
		$backups   = self::backups();
		$backups[] = $backup;
		update_option( self::BACKUP, array_slice( $backups, -self::MAX_BACKUPS ), false );

		$written = array();
		foreach ( $plan['posts'] as $post ) {
			$id = (int) $post['id'];
			if ( $post['scripts'] > 0 ) {
				// The script now lives on an Etch element: printing it from Histeon too would run it twice.
				delete_post_meta( $id, Scripts::META );
			}
			$result = wp_update_post(
				array(
					'ID'            => $id,
					'post_content'  => wp_slash( (string) $post['content'] ),
					// Only the content changes. Without this, wp_update_post() checks the stored page template against
					// the theme, and refuses a page shown with a Histeon CMS template.
					'page_template' => '',
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( sprintf( /* translators: 1: post ID, 2: error */ __( 'Scrittura interrotta al contenuto #%1$d (%2$s): ripristina la copia di sicurezza appena fatta.', 'xb' ), $id, $result->get_error_message() ) );
			}
			foreach ( $post['meta'] as $meta => $value ) {
				update_post_meta( $id, $meta, wp_slash( (string) $value ) );
			}
			$written[] = $id;
		}
		foreach ( $plan['options'] as $option => $entries ) {
			if ( array() === $entries ) {
				continue;
			}
			$current = get_option( $option, array() );
			$current = is_array( $current ) ? $current : array();
			foreach ( $entries as $key => $entry ) {
				$current[ $key ] = $entry;
			}
			update_option( $option, $current );
		}
		Plugin::instance()->versioning->store()->audit(
			'export',
			'etch',
			array(
				'docs'        => $written,
				'fingerprint' => $plan['fingerprint'],
				'tokens'      => $this->tokens,
			)
		);
		$plan['written'] = true;
		return $plan;
	}

	/**
	 * Puts back what the last apply() replaced, and forgets that backup.
	 *
	 * @return array{at: string, posts: int, options: int}
	 */
	public static function restore(): array {
		if ( ! self::can_write() ) {
			throw new \RuntimeException( __( 'Per ripristinare serve un amministratore con i permessi xb_build e unfiltered_html.', 'xb' ) );
		}
		$backups = self::backups();
		$backup  = array_pop( $backups );
		if ( null === $backup ) {
			throw new \RuntimeException( __( 'Non c\'è nessuna copia di sicurezza di un\'esportazione verso Etch.', 'xb' ) );
		}
		foreach ( is_array( $backup['posts'] ?? null ) ? $backup['posts'] : array() as $id => $post ) {
			$id = (int) $id;
			if ( ! is_array( $post ) || ! get_post( $id ) instanceof \WP_Post ) {
				continue;
			}
			if ( '' !== (string) ( $post['script'] ?? '' ) ) {
				Scripts::set( $id, (string) $post['script'] );
			}
			wp_update_post(
				array(
					'ID'            => $id,
					'post_content'  => wp_slash( (string) ( $post['content'] ?? '' ) ),
					// As in apply(): here wp_update_post() would also reset a Histeon CMS template to the default one.
					'page_template' => '',
				)
			);
			self::put_back_dates( $id, $post['modified'] ?? null );
			if ( null === ( $post['key'] ?? null ) ) {
				delete_post_meta( $id, self::COMPONENT_KEY );
			} else {
				update_post_meta( $id, self::COMPONENT_KEY, wp_slash( (string) $post['key'] ) );
			}
		}
		foreach ( is_array( $backup['options'] ?? null ) ? $backup['options'] : array() as $option => $value ) {
			if ( in_array( $option, array( self::LOOPS, self::SHEETS, self::STYLES ), true ) ) {
				self::ABSENT === $value ? delete_option( $option ) : update_option( $option, $value );
			}
		}
		update_option( self::BACKUP, $backups, false );
		Plugin::instance()->versioning->store()->audit( 'export_restore', 'etch', array( 'at' => (string) ( $backup['at'] ?? '' ) ) );
		return array(
			'at'      => (string) ( $backup['at'] ?? '' ),
			'posts'   => count( (array) ( $backup['posts'] ?? array() ) ),
			'options' => count( (array) ( $backup['options'] ?? array() ) ),
		);
	}

	/**
	 * A restore puts the content back as it was, so the dates say when it was last really changed. Backups made
	 * before the dates were kept have none, and keep the date of the restore.
	 */
	private static function put_back_dates( int $id, mixed $dates ): void {
		global $wpdb;
		if ( ! is_array( $dates ) || 2 !== count( $dates ) ) {
			return;
		}
		[ $local, $gmt ] = array_map( 'strval', array_values( $dates ) );
		$format          = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
		if ( 1 !== preg_match( $format, $local ) || 1 !== preg_match( $format, $gmt ) ) {
			return;
		}
		$wpdb->update( $wpdb->posts, array( 'post_modified' => $local, 'post_modified_gmt' => $gmt ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- wp_update_post always sets the date to now.
		clean_post_cache( $id );
	}

	/**
	 * At most 30 lines for one document, then how many more: a page with hundreds of expressions stays readable.
	 *
	 * @param list<string> $lines
	 * @return list<string>
	 */
	private static function capped( array $lines ): array {
		if ( count( $lines ) <= 30 ) {
			return $lines;
		}
		$more = count( $lines ) - 29;
		return array_merge( array_slice( $lines, 0, 29 ), array( sprintf( /* translators: %d: number of further changes */ _n( '… e un\'altra trasformazione', '… e altre %d trasformazioni', $more, 'xb' ), $more ) ) );
	}

	/** @return list<array<string, mixed>> */
	public static function backups(): array {
		$stored = get_option( self::BACKUP, array() );
		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
	}

	/**
	 * The file a download or `--file` writes: the bundle without anything about people or the server (no authors,
	 * no user IDs, no paths). Our own format, documented in docs/spec/export-etch.md §5: Etch does not read it.
	 *
	 * @param array<string, mixed> $bundle
	 */
	public static function file( array $bundle ): string {
		$file = array(
			'format'      => self::FORMAT,
			'version'     => self::VERSION,
			'generated'   => gmdate( 'c' ),
			'tokens'      => $bundle['tokens'],
			'fingerprint' => $bundle['fingerprint'],
			'posts'       => array_map( static fn ( array $p ): array => array_diff_key( $p, array( 'scripts' => true ) ), $bundle['posts'] ),
			'options'     => $bundle['options'],
			'report'      => $bundle['report'],
		);
		return (string) wp_json_encode( $file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** `col-contatti` → `ColContatti`, `ui/card-hero` → `UiCardHero` (the importer reads it back as a key). */
	public static function pascal( string $key ): string {
		return implode( '', array_map( 'ucfirst', preg_split( '/[-\/]+/', $key ) ?: array() ) );
	}

	private static function stored( int $id ): string {
		return self::field( 'post_content', $id );
	}

	private static function field( string $field, int $id ): string {
		$value = get_post_field( $field, $id, 'raw' );
		return is_string( $value ) ? $value : '';
	}

	/** @param array<string, mixed> $bundle */
	private static function fingerprint( array $bundle ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $bundle['tokens'], $bundle['posts'], $bundle['options'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Documents with Histeon blocks (live versions), components first; and the Histeon CMS templates left out.
	 *
	 * @return array{0: list<\WP_Post>, 1: list<\WP_Post>}
	 */
	private function posts(): array {
		global $wpdb;
		$types    = implode( ',', array_fill( 0, count( Documents::POST_TYPES ), '%s' ) );
		$statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) AND post_status IN ($statuses) AND post_content LIKE %s ORDER BY FIELD(post_type, 'wp_block') DESC, ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
				array_merge( Documents::POST_TYPES, self::STATUSES, array( '%' . $wpdb->esc_like( 'wp:xb/' ) . '%', self::MAX_DOCS ) )
			)
		);
		$posts     = array();
		$templates = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			// Only Histeon content: a document already written in Etch (an earlier export) is left alone.
			if ( ! Spec::has_xb_blocks( $post->post_content ) ) {
				continue;
			}
			if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) && '' !== (string) get_post_meta( $id, self::MANAGED_TEMPLATE, true ) ) {
				$templates[] = $post;
				continue;
			}
			$posts[] = $post;
		}
		return array( $posts, $templates );
	}

	/**
	 * Component key → the wp_block that defines it, for the components exported with the project.
	 *
	 * @param list<\WP_Post> $posts
	 * @return array<string, int>
	 */
	private function components( array $posts ): array {
		$out = array();
		foreach ( $posts as $post ) {
			$key = 'wp_block' === $post->post_type ? (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true ) : '';
			if ( '' !== $key && ComponentRegistry::owner( $key ) === $post->ID ) {
				$out[ $key ] = $post->ID;
			}
		}
		return $out;
	}

	/**
	 * Histeon global sheets and selector styles → Etch's (live versions, media references resolved).
	 *
	 * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: string}
	 */
	private function styles( Tokens $tokens ): array {
		$registry = new StyleRegistry();
		$sheets   = array();
		$styles   = array();
		$css      = '';
		foreach ( $registry->globals( true, true ) as $sheet ) {
			$body = trim( (string) preg_replace( '#/\*.*?\*/#s', '', (string) $sheet['css'] ) );
			if ( '' === $body ) {
				continue;
			}
			$key            = $this->key( self::SHEETS, (string) $sheet['id'], 0 );
			$text           = CssCompiler::sanitize( $tokens->css( (string) $sheet['css'] ) );
			$sheets[ $key ] = array(
				'name' => (string) $sheet['id'],
				'css'  => $text,
			);
			$css           .= $text;
		}
		foreach ( $registry->entries( true, true ) as $entry ) {
			if ( '' === trim( (string) $entry['css'] ) ) {
				continue;
			}
			$key            = $this->key( self::STYLES, (string) $entry['id'], 64 );
			$text           = CssCompiler::sanitize( $tokens->css( (string) $entry['css'] ) );
			$styles[ $key ] = array(
				'selector' => CssCompiler::sanitize( (string) $entry['selector'] ),
				'css'      => $text,
				'readonly' => false,
			);
			$css           .= $text;
		}
		return array( $sheets, $styles, $css );
	}

	/**
	 * The Etch key for a Histeon sheet or style: the one the importer came from (`etch-<key>`), else `histos-<id>`.
	 */
	private function key( string $option, string $id, int $limit ): string {
		if ( str_starts_with( $id, 'etch-' ) ) {
			$existing = get_option( $option, array() );
			foreach ( is_array( $existing ) ? array_keys( $existing ) : array() as $key ) {
				$imported = 'etch-' . sanitize_key( (string) $key );
				if ( ( $limit > 0 ? substr( $imported, 0, $limit ) : $imported ) === $id ) {
					return (string) $key;
				}
			}
			return substr( $id, 5 );
		}
		return 'histos-' . sanitize_key( $id );
	}

	/**
	 * An existing entry keeps the fields Histeon does not write (Etch's own, never guessed for new entries).
	 *
	 * @param array<string, array<string, mixed>> $entries
	 * @return array<string, array<string, mixed>>
	 */
	private function merge_existing( string $option, array $entries ): array {
		$existing = get_option( $option, array() );
		$existing = is_array( $existing ) ? $existing : array();
		foreach ( $entries as $key => $entry ) {
			if ( is_array( $existing[ $key ] ?? null ) ) {
				$entries[ $key ] = array_merge( $existing[ $key ], $entry );
				if ( isset( $existing[ $key ]['name'] ) ) {
					$entries[ $key ]['name'] = $existing[ $key ]['name'];
				}
			}
		}
		ksort( $entries );
		return $entries;
	}

	/**
	 * What the export leaves out for the whole project (per-document details are in each document's issues).
	 *
	 * @param list<\WP_Post> $templates
	 * @return list<string>
	 */
	private function skipped( array $templates ): array {
		$out     = array();
		$content = \XB\Content\ContentTypes::live();
		if ( array() !== $content['types'] || array() !== $content['taxonomies'] ) {
			$out[] = sprintf( /* translators: 1: content types, 2: taxonomies */ __( 'Tipi di contenuto (%1$d), tassonomie (%2$d), campi e record di Histeon: non esportati. In un sito Etch sono post type di altri plugin, e i loop sui record non hanno una query equivalente.', 'xb' ), count( $content['types'] ), count( $content['taxonomies'] ) );
		}
		if ( array() !== $templates ) {
			$out[] = sprintf( /* translators: %s: template titles */ __( 'Template di Histeon CMS (con ruolo o slug privato) non esportati: %s. In Etch i template sono quelli del tema.', 'xb' ), implode( ', ', array_map( static fn ( \WP_Post $t ): string => get_the_title( $t ) . ' (' . $t->post_name . ')', $templates ) ) );
		}
		$pending = array_filter( Plugin::instance()->versioning->changes(), static fn ( array $c ): bool => null !== Documents::post_id( (string) $c['doc'] ) );
		if ( array() !== $pending ) {
			$out[] = sprintf( /* translators: %d: number of documents */ _n( '%d documento ha modifiche in sviluppo non pubblicate: si esporta la versione online.', '%d documenti hanno modifiche in sviluppo non pubblicate: si esporta la versione online.', count( $pending ), 'xb' ), count( $pending ) );
		}
		$out[] = __( 'Impostazioni di Histeon senza un corrispettivo in Etch (faccette, sorgenti di dati, query con nome, campi pubblici, font locali, reset CSS): non esportate.', 'xb' );
		return $out;
	}
}
