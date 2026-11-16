<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core;

/**
 * `wp xb export-core` — docs/spec/export-core.md §5. Run it as an administrator: `--user=<admin>`.
 */
final class Command {

	/** Lines per document for frozen and lost pieces, without --all. */
	private const LINES = 30;

	/**
	 * Trial report of the way out to Gutenberg: what would become a block, what would be frozen, what would be lost.
	 * It writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--doc=<id>]
	 * : One document only.
	 *
	 * [--file=<path>]
	 * : Also saves the whole report as JSON, every element included.
	 *
	 * [--all]
	 * : Every frozen and lost piece, not the first 30 per document.
	 *
	 * [--convert]
	 * : With --doc: prints the document converted to Fondamenta's blocks and core blocks, and what was frozen or
	 * lost. Writes nothing.
	 *
	 * [--compare]
	 * : Draws each document (or --doc) with Histeon and from its conversion, as an anonymous visitor, and compares
	 * the two HTML. Needs Fondamenta 0.2 or later active. Writes nothing.
	 *
	 * [--theme-zip=<path>]
	 * : Writes the site's own theme (a child of Fondamenta: its sheet, theme.json, templates and parts converted)
	 * as a ZIP at that path, to install by hand. Nothing is written into WordPress.
	 *
	 * [--campi=<plugin>]
	 * : With --theme-zip: the field plugin the site's fields go to (scf, acf, metabox, pods, jetengine, openfields): its
	 * field groups go into the theme.
	 * With --doc and --valori: prints the meta that converting that content's values would write. Writes nothing.
	 *
	 * [--valori]
	 * : With --campi and --doc: the values of that content in the field plugin's form.
	 *
	 * [--form=<plugin>]
	 * : With --forms: the form plugin Histeon's forms go to (fluentforms, cf7, forminator, jetformbuilder,
	 * ninjaforms, sureforms).
	 *
	 * [--uscita]
	 * : The exit (§17): what it would write — documents converted in their own posts, page templates, field values
	 * in the --campi plugin's form, forms made in the --form plugin — and its fingerprint. Writes nothing.
	 *
	 * [--apply]
	 * : With --uscita: writes the exit, after a backup of everything it replaces. Needs --confirm.
	 *
	 * [--confirm=<fingerprint>]
	 * : The fingerprint of the preview you read.
	 *
	 * [--restore]
	 * : Puts back what the last --apply replaced, and the theme there was. Needs --yes.
	 *
	 * [--check]
	 * : The documents still holding Histeon's blocks.
	 *
	 * [--finish]
	 * : Switches to the site theme (installed by hand) and turns Histeon off, not deleted. Needs --yes.
	 *
	 * [--yes]
	 * : Confirms --restore or --finish.
	 *
	 * [--forms]
	 * : Histeon's own forms (the static site's, to /xb-form): what each holds, the pages that show it, the messages
	 * received, and the form as the plugin of --form would import it. Writes nothing.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param list<string>          $args
	 * @param array<string, string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		unset( $args );
		if ( ! Report::can_read() ) {
			\WP_CLI::error( __( 'Serve un amministratore con il permesso xb_build: aggiungi --user=<amministratore>.', 'xb' ) );
		}
		$doc    = isset( $assoc['doc'] ) ? (int) $assoc['doc'] : null;
		$fields = null;
		if ( isset( $assoc['campi'] ) ) {
			$fields = Fields\Adapters::get( (string) $assoc['campi'] );
			if ( null === $fields ) {
				/* translators: %s: plugin ids */
				\WP_CLI::error( sprintf( __( 'Plugin dei campi sconosciuto. Scegli fra: %s.', 'xb' ), implode( ', ', array_keys( Fields\Adapters::all() ) ) ) );
				return;
			}
		}
		$forms = isset( $assoc['form'] ) ? Forms\Adapters::get( (string) $assoc['form'] ) : null;
		if ( isset( $assoc['form'] ) && null === $forms ) {
			/* translators: %s: plugin ids */
			\WP_CLI::error( sprintf( __( 'Plugin dei form sconosciuto. Scegli fra: %s.', 'xb' ), implode( ', ', array_keys( Forms\Adapters::all() ) ) ) );
			return;
		}
		if ( isset( $assoc['uscita'] ) || isset( $assoc['restore'] ) || isset( $assoc['check'] ) || isset( $assoc['finish'] ) ) {
			self::departure( new Departure( $fields, $forms ), $assoc );
			return;
		}
		if ( isset( $assoc['forms'] ) ) {
			self::forms( $forms, false );
			return;
		}
		if ( null !== $fields && isset( $assoc['valori'] ) ) {
			$post = null !== $doc ? get_post( $doc ) : null;
			if ( ! $post instanceof \WP_Post ) {
				\WP_CLI::error( __( 'Indica il contenuto con --doc=<id>.', 'xb' ) );
				return;
			}
			\WP_CLI::line( (string) wp_json_encode( Fields\Adapters::post_values( $fields, $post ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			\WP_CLI::success( __( 'Valori nel formato del plugin dei campi: nulla è stato modificato.', 'xb' ) );
			return;
		}
		if ( isset( $assoc['theme-zip'] ) && '' !== $assoc['theme-zip'] ) {
			try {
				$generator = new SiteTheme( $fields );
				$theme     = $generator->zip( $assoc['theme-zip'] );
			} catch ( \Throwable $e ) {
				\WP_CLI::error( $e->getMessage() );
				return;
			}
			$count = static fn ( string $folder ): int => count( array_filter( array_keys( $theme['files'] ), static fn ( string $f ): bool => str_starts_with( $f, $folder . '/' ) ) );
			foreach ( $generator->pages as $page ) {
				/* translators: 1: page title, 2: template */
				\WP_CLI::log( sprintf( __( 'Pagina «%1$s»: template «%2$s» (la conversione dei contenuti lo scriverà)', 'xb' ), $page['title'], $page['template'] ) );
			}
			foreach ( array_unique( $generator->notes ) as $note ) {
				\WP_CLI::warning( $note );
			}
			/* translators: 1: theme slug, 2: templates, 3: parts, 4: kilobytes of CSS */
			\WP_CLI::success( sprintf( __( 'Tema «%1$s» scritto: %2$d template, %3$d parti, foglio del sito di %4$s KB. Nulla è stato modificato nel sito.', 'xb' ), $theme['slug'], $count( 'templates' ), $count( 'parts' ), number_format_i18n( strlen( $theme['files']['assets/sito.css'] ) / 1024, 1 ) ) );
			return;
		}
		if ( isset( $assoc['convert'] ) || isset( $assoc['compare'] ) ) {
			self::proof( $doc, $assoc );
			return;
		}
		try {
			$report = ( new Report() )->build( null !== $doc && $doc > 0 ? $doc : null );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( isset( $assoc['file'] ) && '' !== $assoc['file'] ) {
			if ( false === file_put_contents( $assoc['file'], Report::file( $report ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a path the CLI user chose.
				\WP_CLI::error( __( 'Impossibile scrivere il file.', 'xb' ) );
			}
		}
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line( Report::file( $report ) );
			return;
		}
		$rows = array();
		foreach ( $report['documents'] as $entry ) {
			$c      = $entry['counts'];
			$e      = $entry['expressions'];
			$rows[] = array(
				'id'                       => $entry['id'],
				__( 'tipo', 'xb' )         => $entry['type'],
				__( 'titolo', 'xb' )       => mb_substr( (string) $entry['title'], 0, 32 ),
				__( 'blocchi veri', 'xb' ) => self::percent( (float) $entry['share'] ),
				'core'                     => $c[ Analyzer::CORE ],
				'fondamenta'               => $c[ Analyzer::FONDAMENTA ] + $c[ Analyzer::DYNAMIC ],
				'pattern'                  => $c[ Analyzer::PATTERN ],
				'gutenberg'                => $c[ Analyzer::GUTENBERG ],
				__( 'srotolati', 'xb' )    => $c[ Analyzer::UNROLLED ],
				__( 'congelati', 'xb' )    => $c[ Analyzer::FROZEN ],
				__( 'persi', 'xb' )        => $c[ Analyzer::LOST ],
				__( 'espressioni', 'xb' )  => sprintf( '%d/%d/%d', $e[ Analyzer::WRITTEN ], $e[ Analyzer::BOUND ], $e[ Analyzer::DROPPED ] ),
			);
		}
		if ( array() !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
			\WP_CLI::log( __( 'Espressioni: scritte alla conversione / legate a un blocco dinamico / perse.', 'xb' ) );
		}
		$all = isset( $assoc['all'] );
		foreach ( $report['documents'] as $entry ) {
			$lines = array();
			foreach ( $entry['items'] as $item ) {
				if ( in_array( $item['dest'], array( Analyzer::FROZEN, Analyzer::LOST ), true ) ) {
					$size    = $item['elements'] > 1 ? sprintf( /* translators: %d: elements */ __( ' (%d elementi)', 'xb' ), $item['elements'] ) : '';
					$lines[] = sprintf( '%s %s%s: %s', Analyzer::FROZEN === $item['dest'] ? __( 'congelato', 'xb' ) : __( 'perso', 'xb' ), self::where( $item['path'], $item['node'] ), $size, $item['reason'] );
				}
			}
			foreach ( $entry['notes'] as $note ) {
				if ( Analyzer::DROPPED === $note['fate'] ) {
					$lines[] = sprintf( '%s {%s} %s: %s', __( 'persa', 'xb' ), $note['expr'], '' !== $note['path'] ? '(' . $note['path'] . ')' : '', $note['reason'] );
				}
			}
			$total = count( $lines );
			foreach ( $all ? $lines : array_slice( $lines, 0, self::LINES ) as $line ) {
				\WP_CLI::log( sprintf( '#%d %s: %s', $entry['id'], mb_substr( (string) $entry['title'], 0, 30 ), $line ) );
			}
			if ( ! $all && $total > self::LINES ) {
				/* translators: 1: post ID, 2: lines not shown */
				\WP_CLI::log( sprintf( __( '#%1$d: altre %2$d righe con --all o nel file.', 'xb' ), $entry['id'], $total - self::LINES ) );
			}
		}
		foreach ( self::site_lines( $report['site'] ) as $line ) {
			\WP_CLI::log( $line );
		}
		foreach ( $report['issues'] as $issue ) {
			\WP_CLI::warning( $issue );
		}
		$s = $report['summary'];
		\WP_CLI::log(
			sprintf(
				/* translators: 1: percent, 2: elements that become blocks, 3: all elements, 4: documents, 5: frozen, 6: lost */
				__( 'Diventano blocchi veri %1$s degli elementi (%2$d su %3$d, in %4$d documenti); congelati %5$d, persi %6$d.', 'xb' ),
				self::percent( (float) $s['share'] ),
				$s['real'],
				$s['elements'],
				$s['documents'],
				$s['counts'][ Analyzer::FROZEN ] ?? 0,
				$s['counts'][ Analyzer::LOST ] ?? 0
			)
		);
		\WP_CLI::log( sprintf( /* translators: %s: fingerprint */ __( 'Impronta: %s', 'xb' ), $report['fingerprint'] ) );
		\WP_CLI::success( __( 'Rapporto: nulla è stato modificato.', 'xb' ) );
	}

	/**
	 * The exit (§17): preview, write, restore, check, finish.
	 *
	 * @param array<string, string> $assoc
	 */
	private static function departure( Departure $departure, array $assoc ): void {
		try {
			if ( isset( $assoc['restore'] ) ) {
				if ( ! isset( $assoc['yes'] ) ) {
					\WP_CLI::error( __( 'Il ripristino rimette i contenuti com\'erano prima della scrittura: conferma con --yes.', 'xb' ) );
				}
				$done = Departure::restore();
				/* translators: 1: date, 2: contents */
				\WP_CLI::success( sprintf( __( 'Ripristinato lo stato del %1$s: %2$d contenuti.', 'xb' ), $done['at'], $done['posts'] ) );
				foreach ( $done['forms'] as $form ) {
					/* translators: 1: plugin, 2: form id */
					\WP_CLI::warning( sprintf( __( 'Il form %2$d creato in %1$s resta lì, non usato: cancellalo da lì se non serve.', 'xb' ), $form[0], $form[1] ) );
				}
				return;
			}
			if ( isset( $assoc['check'] ) ) {
				$left = Departure::check()['left'];
				foreach ( $left as $doc ) {
					\WP_CLI::warning( sprintf( '#%d %s «%s»', $doc['id'], $doc['type'], $doc['title'] ) );
				}
				/* translators: %d: documents */
				\WP_CLI::success( sprintf( __( 'Documenti con blocchi di Histeon: %d.', 'xb' ), count( $left ) ) );
				return;
			}
			if ( isset( $assoc['finish'] ) ) {
				if ( ! isset( $assoc['yes'] ) ) {
					\WP_CLI::error( __( 'Concludere attiva il tema del sito e spegne Histeon: conferma con --yes.', 'xb' ) );
				}
				Departure::finish( Departure::theme_slug() );
				/* translators: %s: theme */
				\WP_CLI::success( sprintf( __( 'Tema «%s» attivo, Histeon spento (non cancellato). Per tornare: riattiva Histeon e usa --restore --yes.', 'xb' ), Departure::theme_slug() ) );
				return;
			}
			if ( isset( $assoc['apply'] ) ) {
				$done = $departure->apply( (string) ( $assoc['confirm'] ?? '' ) );
				/* translators: 1: documents, 2: pages, 3: contents with fields, 4: forms, 5: messages */
				\WP_CLI::success( sprintf( __( 'Uscita scritta: %1$d documenti convertiti, %2$d template di pagina, %3$d contenuti con i campi nel nuovo formato, %4$d form creati con %5$d messaggi. Copia di sicurezza fatta: per tornare indietro --restore --yes. Non pubblicare una release di Histeon.', 'xb' ), $done['documents'], $done['pages'], $done['fields'], $done['forms'], $done['messages'] ) );
				return;
			}
			$plan = $departure->plan();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		/* translators: 1: documents, 2: pages */
		\WP_CLI::log( sprintf( __( 'Documenti da convertire: %1$d. Pagine con un template proprio: %2$d.', 'xb' ), count( $plan['documents'] ), count( $plan['pages'] ) ) );
		if ( '' !== $plan['fields']['plugin'] ) {
			/* translators: 1: contents, 2: plugin */
			\WP_CLI::log( sprintf( __( 'Contenuti con i campi da portare nel formato di %2$s: %1$d.', 'xb' ), count( $plan['fields']['posts'] ), $plan['fields']['plugin'] ) );
		}
		if ( array() !== $plan['forms']['names'] ) {
			/* translators: 1: forms, 2: messages, 3: plugin */
			\WP_CLI::log( sprintf( __( 'Form di Histeon: %1$d, con %2$d messaggi, da creare in %3$s.', 'xb' ), count( $plan['forms']['names'] ), $plan['forms']['messages'], '' !== $plan['forms']['plugin'] ? $plan['forms']['plugin'] : '—' ) );
		}
		foreach ( $plan['notes'] as $note ) {
			\WP_CLI::warning( $note );
		}
		\WP_CLI::log( sprintf( __( 'Impronta: %s', 'xb' ), $plan['fingerprint'] ) );
		\WP_CLI::success( __( 'Anteprima: nulla è stato modificato. Per scrivere: --uscita --apply --confirm=<impronta>, con le stesse --campi e --form.', 'xb' ) );
	}

	/** Histeon's forms, and what the chosen form plugin would make of them (§16). */
	private static function forms( ?Forms\Adapter $adapter, bool $asked ): void {
		if ( $asked && null === $adapter ) {
			/* translators: %s: plugin ids */
			\WP_CLI::error( sprintf( __( 'Plugin dei form sconosciuto. Scegli fra: %s.', 'xb' ), implode( ', ', array_keys( Forms\Adapters::all() ) ) ) );
			return;
		}
		$forms = Forms\Reader::all();
		foreach ( $forms as $form ) {
			/* translators: 1: form name, 2: fields, 3: documents, 4: messages */
			\WP_CLI::log( sprintf( __( 'Form «%1$s»: %2$d campi, nei documenti %3$s, %4$d messaggi ricevuti.', 'xb' ), $form['name'], count( $form['fields'] ), implode( ', ', $form['documents'] ), count( Forms\Adapters::messages_of( $form['name'] ) ) ) );
			if ( null !== $adapter ) {
				foreach ( $adapter->notes( $form ) as $note ) {
					\WP_CLI::warning( $note );
				}
				\WP_CLI::line( (string) wp_json_encode( $adapter->export( $form ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			}
		}
		/* translators: %d: forms */
		\WP_CLI::success( sprintf( __( 'Form di Histeon: %d. Nulla è stato modificato.', 'xb' ), count( $forms ) ) );
	}

	/**
	 * `--convert` and `--compare`: the conversion of step 3 of doc 25, shown or proved, never written.
	 *
	 * @param array<string, string> $assoc
	 */
	private static function proof( ?int $doc, array $assoc ): void {
		$ids = null !== $doc && $doc > 0 ? array( $doc ) : array_column( ( new Report() )->build()['documents'], 'id' );
		if ( isset( $assoc['convert'] ) ) {
			$post = null !== $doc ? get_post( $doc ) : null;
			if ( ! $post instanceof \WP_Post ) {
				\WP_CLI::error( __( '--convert vuole --doc=<id> di un documento con blocchi di Histeon.', 'xb' ) );
				return;
			}
			$done = Prova::convert( $post );
			\WP_CLI::line( \XB\Blocks::serialize( $done['blocks'] ) );
			foreach ( $done['notes'] as $note ) {
				\WP_CLI::warning( sprintf( '%s <%s>: %s', $note['cosa'], $note['node'], $note['reason'] ) );
			}
			return;
		}
		if ( ! Prova::ready() ) {
			\WP_CLI::error( __( 'Per la prova servono i blocchi di Fondamenta: attiva Fondamenta 0.2 o successiva.', 'xb' ) );
		}
		$rows  = array();
		$all   = array();
		$tally = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post || 'wp_block' === $post->post_type ) {
				continue;
			}
			try {
				$result = Prova::compare( $post );
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( sprintf( '#%d: %s', $post->ID, $e->getMessage() ) );
				continue;
			}
			$tally[ $result['esito'] ] = ( $tally[ $result['esito'] ] ?? 0 ) + 1;
			$html                      = $result['counts']['core/html'] ?? 0;
			$rows[]                    = array(
				'id'                     => $post->ID,
				__( 'tipo', 'xb' )       => $post->post_type,
				__( 'titolo', 'xb' )     => mb_substr( get_the_title( $post ), 0, 32 ),
				__( 'esito', 'xb' )      => $result['esito'],
				__( 'blocchi', 'xb' )    => array_sum( $result['counts'] ) - $html,
				'core/html'              => $html,
				__( 'note', 'xb' )       => count( $result['notes'] ),
			);
			$all[] = array(
				'id'         => $post->ID,
				'type'       => $post->post_type,
				'title'      => get_the_title( $post ),
				'esito'      => $result['esito'],
				'differenze' => $result['differenze'],
				'counts'     => $result['counts'],
				'notes'      => $result['notes'],
			);
		}
		if ( isset( $assoc['file'] ) && '' !== $assoc['file'] ) {
			file_put_contents( $assoc['file'], (string) wp_json_encode( array( 'tally' => $tally, 'documents' => $all ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a path the CLI user chose.
		}
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::line( (string) wp_json_encode( array( 'tally' => $tally, 'documents' => $all ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		if ( array() !== $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}
		foreach ( $all as $entry ) {
			foreach ( 'diverso' === $entry['esito'] ? $entry['differenze'] : array() as $line ) {
				\WP_CLI::log( sprintf( '#%d %s', $entry['id'], $line ) );
			}
		}
		/* translators: 1: same, 2: only core classes, 3: different */
		\WP_CLI::log( sprintf( __( 'Uguali %1$d, uguali a parte le classi del core %2$d, diversi %3$d.', 'xb' ), $tally['uguale'] ?? 0, $tally['classi del core'] ?? 0, $tally['diverso'] ?? 0 ) );
		\WP_CLI::success( __( 'Prova: nulla è stato modificato.', 'xb' ) );
	}

	/**
	 * @param array<string, mixed> $site
	 * @return list<string>
	 */
	private static function site_lines( array $site ): array {
		$out = array();
		if ( array() !== $site['types'] || $site['fields'] > 0 ) {
			/* translators: 1: content types, 2: taxonomies, 3: fields, 4: repeaters */
			$out[] = sprintf( __( 'Sito: tipi di contenuto %1$s, tassonomie %2$s, %3$d campi di cui %4$d ripetitori: andranno nel plugin di campi scelto (ACF, SCF, Meta Box…).', 'xb' ), self::names( $site['types'] ), self::names( $site['taxonomies'] ), $site['fields'], $site['repeaters'] );
		}
		if ( $site['queries']['metaQuery'] > 0 ) {
			/* translators: %d: queries */
			$out[] = sprintf( __( 'Sito: %d query con condizioni sui campi: la query di Fondamenta le applicherà come dati.', 'xb' ), $site['queries']['metaQuery'] );
		}
		if ( $site['facets'] > 0 ) {
			/* translators: %d: facets */
			$out[] = sprintf( __( 'Sito: %d facet: i filtri di Fondamenta, con le definizioni nel tema del sito (facets.json).', 'xb' ), $site['facets'] );
		}
		if ( $site['forms'] > 0 ) {
			/* translators: %d: forms */
			$out[] = sprintf( __( 'Sito: %d form di Histeon da rifare nel plugin dei form scelto.', 'xb' ), $site['forms'] );
		}
		if ( $site['behaviors'] > 0 ) {
			/* translators: %d: elements */
			$out[] = sprintf( __( 'Sito: %d elementi con interazioni (data-xb-…): serviranno behaviors.js e behaviors.css in Fondamenta.', 'xb' ), $site['behaviors'] );
		}
		if ( $site['scripts']['documents'] > 0 || $site['scripts']['site'] > 0 ) {
			/* translators: 1: documents, 2: site scripts */
			$out[] = sprintf( __( 'Sito: JavaScript in %1$d documenti e %2$d script del sito, da portare nel tema del sito.', 'xb' ), $site['scripts']['documents'], $site['scripts']['site'] );
		}
		/* translators: 1: framework, 2: kilobytes */
		$out[] = sprintf( __( 'Sito: framework %1$s, CSS del sito %2$s KB, in ogni pagina invece che su richiesta.', 'xb' ), $site['css']['framework'], number_format_i18n( $site['css']['bytes'] / 1024, 1 ) );
		$on = array_keys( array_filter( $site['modules'] ) );
		if ( array() !== $on ) {
			/* translators: %s: modules */
			$out[] = sprintf( __( 'Sito: %s da installare come plugin separati prima dell\'uscita.', 'xb' ), implode( ', ', $on ) );
		}
		if ( $site['pending'] > 0 ) {
			/* translators: %d: documents */
			$out[] = sprintf( _n( 'Sito: %d documento ha modifiche in sviluppo non pubblicate: il rapporto legge la versione online.', 'Sito: %d documenti hanno modifiche in sviluppo non pubblicate: il rapporto legge la versione online.', $site['pending'], 'xb' ), $site['pending'] );
		}
		return $out;
	}

	/** @param list<string> $names */
	private static function names( array $names ): string {
		return array() === $names ? '—' : implode( ', ', $names );
	}

	private static function where( string $path, string $node ): string {
		return '' === $path ? '<' . $node . '>' : $path . ' > <' . $node . '>';
	}

	private static function percent( float $share ): string {
		return number_format_i18n( $share * 100, 1 ) . '%';
	}
}
