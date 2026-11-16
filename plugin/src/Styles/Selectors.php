<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Styles;

use XB\Blocks;
use XB\Plugin;
use XB\Rest\Controller;
use XB\Security\MarkupPolicy;
use XB\Versioning\Documents;
use XB\Versioning\Store;
use XB\Versioning\Versioning;
use XB\Versioning\VersioningException;

/**
 * Selector manager — docs/spec/styles.md §4, ADR 0007.
 *
 * - Lists every style entry with the documents whose classes and ids can use it (entry selector and
 *   BEM-hoisted rules alike), read from the dev heads without writing anything.
 * - Renames a class everywhere: the class and its BEM children (`card` → `box` also turns `card__title` and
 *   `card--dark` into `box__title`, `box--dark`) in style selectors, global sheets and the static class
 *   attributes of every document. Like a framework migration, the result is a set of **dev commits**:
 *   nothing goes live until a release is published.
 * - Deletes entries that nothing uses.
 *
 * Writes go through Versioning::save_dev(), so the restricted pipeline applies to authors without
 * unfiltered_html, and a commit replacing an untrusted dev head stays untrusted (ADR 0005 R1).
 *
 * @phpstan-type Scanned array{doc: string, editable: bool, trusted: bool, payload: array<string, mixed>}
 */
final class Selectors {

	private const MAX_DOCS  = 2000;
	/** Classes listed from the global sheets, and the CSS shown for each (the rules naming it). */
	private const MAX_SHEET_CLASSES = 3000;
	private const MAX_SHEET_CSS     = 8192;
	private const MAX_DEPTH = 128;
	public const NAME_PATTERN = '/^-?[_a-zA-Z][_a-zA-Z0-9-]{0,99}$/';

	public function __construct(
		private readonly Versioning $versioning,
		private readonly MarkupPolicy $policy,
	) {}

	public static function instance(): self {
		return new self( Plugin::instance()->versioning, new MarkupPolicy( array_values( wp_allowed_protocols() ) ) );
	}

	// ------------------------------------------------------------------ usage

	/**
	 * Every style entry with the documents that use it. Documents the user cannot edit are only counted
	 * (`locked`): they make an entry "used" but are never listed. Templates and template parts of the
	 * active theme count too (`theme`): the header and footer of a block theme are XB markup as well.
	 *
	 * @return array{selectors: list<array{id: string, selector: string, rename: ?string, bytes: int, docs: list<array{doc: string, post: int, label: string}>, locked: int, theme: list<string>, always: bool, safelisted: bool, unused: bool, sheet?: string, rules?: int, css?: string}>, scanned: int, truncated: bool}
	 */
	public function usage(): array {
		$scan     = $this->documents();
		$safelist = new Presence();
		$safelist->add_safelist( PageStyles::safelist() );
		$present = array();
		foreach ( $scan['docs'] as $doc ) {
			$present[] = array( $doc, self::presence( $doc['payload'] ) );
		}
		$templates = array();
		foreach ( self::templates() as $template ) {
			$templates[] = array( $template['label'], self::presence( array( 'content' => $template['content'] ) ) );
		}

		$rows = array();
		foreach ( self::entries( $this->styles()['payload'] ) as $entry ) {
			$alternatives = self::alternatives( $entry['selector'], $entry['css'] );
			$always       = in_array( array(), $alternatives, true );
			$docs         = array();
			$locked       = 0;
			$listed       = false;
			foreach ( $present as [ $doc, $presence ] ) {
				if ( $always || ! self::matches( $alternatives, $presence ) ) {
					continue;
				}
				if ( $doc['editable'] ) {
					$docs[] = array(
						'doc'   => $doc['doc'],
						'post'  => (int) Documents::post_id( $doc['doc'] ),
						'label' => Documents::label( $doc['doc'] ),
					);
				} else {
					++$locked;
				}
			}
			$theme = array();
			foreach ( $templates as [ $label, $presence ] ) {
				if ( ! $always && self::matches( $alternatives, $presence ) ) {
					$theme[] = $label;
				}
			}
			foreach ( $alternatives as $alternative ) {
				$listed = $listed || ( array() !== $alternative && $safelist->has_all( $alternative ) );
			}
			$rows[] = array(
				'id'         => $entry['id'],
				'selector'   => $entry['selector'],
				'rename'     => 1 === preg_match( '/^\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)$/', $entry['selector'], $m ) ? $m[1] : null,
				'bytes'      => strlen( $entry['css'] ),
				'docs'       => $docs,
				'locked'     => $locked,
				'theme'      => $theme,
				'always'     => $always,
				'safelisted' => $listed,
				'unused'     => ! $always && ! $listed && array() === $docs && 0 === $locked && array() === $theme,
			);
		}
		$own = array();
		foreach ( $rows as $row ) {
			if ( null !== $row['rename'] ) {
				$own[ $row['rename'] ] = true;
			}
		}
		$sheets = array();
		foreach ( self::sheet_classes( $this->styles()['payload'] ) as $name => $found ) {
			if ( isset( $own[ $name ] ) ) {
				continue;
			}
			$alternatives = array( array( 'c:' . $name ) );
			$docs         = array();
			$locked       = 0;
			foreach ( $present as [ $doc, $presence ] ) {
				if ( ! self::matches( $alternatives, $presence ) ) {
					continue;
				}
				if ( $doc['editable'] ) {
					$docs[] = array(
						'doc'   => $doc['doc'],
						'post'  => (int) Documents::post_id( $doc['doc'] ),
						'label' => Documents::label( $doc['doc'] ),
					);
				} else {
					++$locked;
				}
			}
			$theme = array();
			foreach ( $templates as [ $label, $presence ] ) {
				if ( self::matches( $alternatives, $presence ) ) {
					$theme[] = $label;
				}
			}
			$listed   = $safelist->has_all( array( 'c:' . $name ) );
			$sheets[] = array(
				'id'         => 'sheet:' . $name,
				'selector'   => '.' . $name,
				'rename'     => 1 === preg_match( self::NAME_PATTERN, $name ) ? $name : null,
				'bytes'      => $found['bytes'],
				'docs'       => $docs,
				'locked'     => $locked,
				'theme'      => $theme,
				'always'     => false,
				'safelisted' => $listed,
				'unused'     => ! $listed && array() === $docs && 0 === $locked && array() === $theme,
				'sheet'      => $found['sheet'],
				'rules'      => $found['rules'],
				'css'        => $found['css'],
			);
		}
		return array(
			'selectors' => array_merge( $rows, $sheets ),
			'scanned'   => count( $scan['docs'] ),
			'truncated' => $scan['truncated'],
		);
	}

	/**
	 * The classes named by the rules of the global sheets (written by hand or migrated), as the style manager
	 * of code-first builders lists them: where each is defined, how many rules name it, and those rules.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, array{sheet: string, rules: int, bytes: int, css: string}>
	 */
	public static function sheet_classes( array $payload ): array {
		$out = array();
		foreach ( is_array( $payload['globals'] ?? null ) ? $payload['globals'] : array() as $sheet ) {
			if ( is_array( $sheet ) && is_string( $sheet['id'] ?? null ) && is_string( $sheet['css'] ?? null ) ) {
				self::sheet_rules( CssRules::parse( $sheet['css'] ), $sheet['id'], '', $out, 0 );
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * @param array<mixed>                                                            $nodes
	 * @param array<string, array{sheet: string, rules: int, bytes: int, css: string}> $out
	 */
	private static function sheet_rules( array $nodes, string $sheet, string $prelude, array &$out, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( 'g' === ( $node[0] ?? '' ) ) {
				self::sheet_rules( (array) $node[2], $sheet, trim( (string) $node[1] ), $out, $depth + 1 );
				continue;
			}
			if ( 'r' !== ( $node[0] ?? '' ) ) {
				continue;
			}
			$rule  = trim( (string) $node[1] ) . ' {' . (string) $node[2] . '}';
			$rule  = '' === $prelude ? $rule : $prelude . ' { ' . $rule . ' }';
			$names = array();
			foreach ( (array) $node[3] as $alternative ) {
				foreach ( (array) $alternative as $token ) {
					if ( is_string( $token ) && str_starts_with( $token, 'c:' ) ) {
						$names[ substr( $token, 2 ) ] = true;
					}
				}
			}
			foreach ( array_keys( $names ) as $name ) {
				$name = (string) $name;
				if ( ! isset( $out[ $name ] ) ) {
					if ( count( $out ) >= self::MAX_SHEET_CLASSES ) {
						continue;
					}
					$out[ $name ] = array(
						'sheet' => $sheet,
						'rules' => 0,
						'bytes' => 0,
						'css'   => '',
					);
				}
				++$out[ $name ]['rules'];
				$out[ $name ]['bytes'] += strlen( $rule );
				if ( strlen( $out[ $name ]['css'] ) + strlen( $rule ) <= self::MAX_SHEET_CSS ) {
					$out[ $name ]['css'] .= ( '' === $out[ $name ]['css'] ? '' : "\n" ) . $rule;
				}
			}
		}
	}

	// ------------------------------------------------------------------ rename

	/**
	 * Renames class $from (and its BEM children) to $to. With $write false it is a dry run.
	 *
	 * - `docs`: documents whose class attributes change, with the number of classes rewritten;
	 * - `manual`: documents that use the class only in free HTML ({#raw}, core blocks), left as they are;
	 * - `locked`: documents that use it but the user cannot edit — the rename is refused, it would break them;
	 * - `theme`: theme templates that use it — refused as well: theme files are not the builder's to rewrite;
	 * - `blocked`: documents where the restricted pipeline would strip a renamed element (the commit would be
	 *   untrusted and the element needs unfiltered_html) — refused too, nothing is ever silently lost;
	 * - `conflicts`: target names already in use;
	 * - `html`: each class attribute in free HTML that would change (lista migliorie C4), with its text before and
	 *   after; `$html` chooses which of them the rename rewrites too (none by default), each checked again against
	 *   the text it had in the preview. Only for who may write HTML without filters (`htmlAllowed`).
	 *
	 * @param list<array{id: string, before: string}> $html Free-HTML occurrences to rewrite, from a preview.
	 * @return array{from: string, to: string, styles: int, docs: list<array{doc: string, label: string, classes: int}>, manual: list<array{doc: string, label: string}>, html: list<array{id: string, doc: string, label: string, before: string, after: string}>, htmlAllowed: bool, locked: int, theme: list<string>, blocked: list<array{doc: string, label: string}>, conflicts: list<string>, ok: bool}
	 */
	public function rename( string $from, string $to, bool $write, array $html = array() ): array {
		$from = trim( $from );
		$to   = trim( $to );
		if ( 1 !== preg_match( self::NAME_PATTERN, $from ) || 1 !== preg_match( self::NAME_PATTERN, $to ) ) {
			throw new VersioningException( __( 'Nome di classe non valido: usa lettere, cifre, trattini e trattini bassi.', 'xb' ), 400 );
		}
		if ( $from === $to || null !== self::renamed( $to, $from, $to ) ) {
			throw new VersioningException( __( 'Il nuovo nome deve essere diverso e non può essere un figlio BEM del vecchio.', 'xb' ), 400 );
		}

		$existing = array();
		$collect  = static function ( string $name ) use ( &$existing ): ?string {
			$existing[ $name ] = true;
			return null;
		};
		$styles      = $this->styles();
		$styles_hits = 0;
		self::rename_styles( $styles['payload'], $from, $to, $styles_hits, $collect );
		$renamed     = self::rename_styles( $styles['payload'], $from, $to, $styles_hits, null );

		$report = array(
			'from'      => $from,
			'to'        => $to,
			'styles'    => $styles_hits,
			'docs'      => array(),
			'manual'    => array(),
			'html'      => array(),
			'htmlAllowed' => current_user_can( 'unfiltered_html' ),
			'locked'    => 0,
			'theme'     => array(),
			'blocked'   => array(),
			'conflicts' => array(),
			'ok'        => false,
		);
		$untrusted_user = ! current_user_can( 'unfiltered_html' );
		$changed_docs   = array();
		$chosen         = array();
		foreach ( $html as $pick ) {
			$chosen[ (string) $pick['id'] ] = (string) $pick['before'];
		}
		if ( array() !== $chosen && $untrusted_user ) {
			throw new VersioningException( __( 'Per riscrivere l’HTML libero serve il permesso di scrivere HTML senza filtri.', 'xb' ), 403 );
		}
		$html_docs = array();
		$scan      = $this->documents();
		foreach ( $scan['docs'] as $doc ) {
			$blocks = parse_blocks( (string) ( $doc['payload']['content'] ?? '' ) );
			self::walk_classes( $blocks, $collect, 0 );
			$hits    = 0;
			$changed = array();
			self::rename_blocks( $blocks, $from, $to, $hits, $changed, 0 );
			$label = $doc['editable'] ? Documents::label( $doc['doc'] ) : '';
			if ( $doc['editable'] ) {
				$found = array();
				self::free_html( $blocks, $from, $to, $found, array(), 0 );
				foreach ( $found as $occurrence ) {
					$id               = $doc['doc'] . '|' . $occurrence['n'];
					$report['html'][] = array(
						'id'     => $id,
						'doc'    => $doc['doc'],
						'label'  => $label,
						'before' => $occurrence['before'],
						'after'  => $occurrence['after'],
					);
					if ( isset( $chosen[ $id ] ) ) {
						if ( $chosen[ $id ] !== $occurrence['before'] ) {
							throw new VersioningException( __( 'Un documento è cambiato dopo l’anteprima: riapri l’anteprima della rinomina.', 'xb' ), 409 );
						}
						$html_docs[ $doc['doc'] ][ $occurrence['n'] ] = true;
						unset( $chosen[ $id ] );
					}
				}
			}
			if ( $hits > 0 ) {
				if ( ! $doc['editable'] ) {
					++$report['locked'];
					continue;
				}
				$report['docs'][] = array(
					'doc'     => $doc['doc'],
					'label'   => $label,
					'classes' => $hits,
				);
				$changed_docs[]   = $doc['doc'];
				if ( ( $untrusted_user || ! $doc['trusted'] ) && ! $this->restricted_safe( $changed ) ) {
					$report['blocked'][] = array(
						'doc'   => $doc['doc'],
						'label' => $label,
					);
				}
			} elseif ( $doc['editable'] && self::html_uses( $doc['payload'], $from ) ) {
				$report['manual'][] = array(
					'doc'   => $doc['doc'],
					'label' => $label,
				);
			}
		}
		foreach ( self::templates() as $template ) {
			$blocks = parse_blocks( $template['content'] );
			self::walk_classes( $blocks, $collect, 0 );
			$hits    = 0;
			$changed = array();
			self::rename_blocks( $blocks, $from, $to, $hits, $changed, 0 );
			if ( $hits > 0 || self::html_uses( array( 'content' => $template['content'] ), $from ) ) {
				$report['theme'][] = $template['label'];
			}
		}
		if ( $styles_hits > 0 && $untrusted_user ) {
			if ( Documents::restricted_styles( $renamed ) !== $renamed ) {
				$report['blocked'][] = array(
					'doc'   => Documents::STYLES,
					'label' => Documents::label( Documents::STYLES ),
				);
			}
		}

		foreach ( array_keys( $existing ) as $name ) {
			$new = self::renamed( $name, $from, $to );
			if ( null !== $new && isset( $existing[ $new ] ) ) {
				$report['conflicts'][] = $new;
			}
		}
		if ( isset( $existing[ $to ] ) ) {
			$report['conflicts'][] = $to;
		}
		$report['conflicts'] = array_values( array_unique( $report['conflicts'] ) );
		if ( array() !== $chosen ) {
			throw new VersioningException( __( 'Un documento è cambiato dopo l’anteprima: riapri l’anteprima della rinomina.', 'xb' ), 409 );
		}
		$found               = $styles_hits > 0 || array() !== $report['docs'] || 0 !== $report['locked'];
		$report['ok']        = $found && ! $scan['truncated'] && 0 === $report['locked'] && array() === $report['theme'] && array() === $report['blocked'] && array() === $report['conflicts'];

		if ( ! $write ) {
			return $report;
		}
		if ( ! $report['ok'] ) {
			throw new VersioningException( self::refusal( $report, $found, $scan['truncated'] ), $found ? 409 : 404 );
		}
		$message = sprintf( /* translators: 1: old class name, 2: new class name */ __( 'Rinomina .%s → .%s', 'xb' ), $from, $to );
		$this->versioning->store()->transaction(
			function () use ( $changed_docs, $html_docs, $styles_hits, $from, $to, $message, $untrusted_user ): void {
				foreach ( array_unique( array_merge( $changed_docs, array_keys( $html_docs ) ) ) as $doc ) {
					$copy    = $this->versioning->working_copy( $doc );
					$payload = $copy['payload'];
					$commit  = $this->versioning->store()->get( $copy['head'] );
					$blocks  = parse_blocks( (string) ( $payload['content'] ?? '' ) );
					// The free HTML first, on the blocks as previewed: the occurrence numbers are theirs.
					if ( isset( $html_docs[ $doc ] ) ) {
						$found  = array();
						$blocks = self::free_html( $blocks, $from, $to, $found, $html_docs[ $doc ], 0 );
					}
					$hits    = 0;
					$changed = array();
					$blocks  = self::rename_blocks( $blocks, $from, $to, $hits, $changed, 0 );
					if ( ( $untrusted_user || null === $commit || ! $commit['trusted'] ) && ! $this->restricted_safe( $changed ) ) {
						throw new VersioningException( __( 'Un documento è cambiato durante la rinomina: riprova.', 'xb' ), 409 );
					}
					$payload['content'] = Blocks::serialize( $blocks );
					$this->versioning->save_dev( $doc, $payload, $copy['head'], $copy['base'], $message, true );
				}
				if ( $styles_hits > 0 ) {
					$copy  = $this->versioning->working_copy( Documents::STYLES );
					$count = 0;
					$this->versioning->save_dev( Documents::STYLES, self::rename_styles( $copy['payload'], $from, $to, $count, null ), $copy['head'], $copy['base'], $message, true );
				}
				$this->versioning->store()->audit(
					'rename_selector',
					Documents::STYLES,
					array(
						'from' => $from,
						'to'   => $to,
						'docs' => $changed_docs,
						'html' => array_map( 'array_keys', $html_docs ),
					)
				);
			}
		);
		return $report;
	}

	/** @param array{docs: list<mixed>, locked: int, theme: list<string>, blocked: list<mixed>, conflicts: list<string>} $report */
	private static function refusal( array $report, bool $found, bool $truncated ): string {
		return match ( true ) {
			! $found => __( 'Nessuno stile o documento usa questa classe.', 'xb' ),
			$truncated => __( 'Ci sono troppi documenti per verificarli tutti: rinomina non eseguita.', 'xb' ),
			array() !== $report['conflicts'] => sprintf( __( 'Il nome è già in uso: %s.', 'xb' ), implode( ', ', $report['conflicts'] ) ),
			$report['locked'] > 0 => sprintf( __( 'La classe è usata in %d documenti che non puoi modificare.', 'xb' ), $report['locked'] ),
			array() !== $report['theme'] => sprintf( __( 'La classe è usata nel tema (%s): rinominala prima nei file del tema.', 'xb' ), implode( ', ', $report['theme'] ) ),
			default => __( 'Alcuni elementi richiedono il permesso unfiltered_html: chiedi a un amministratore di rinominare la classe.', 'xb' ),
		};
	}

	// ------------------------------------------------------------------ delete

	/**
	 * Deletes style entries that nothing uses, as one dev commit of the styles document.
	 *
	 * @param list<string> $ids
	 * @return array{deleted: list<string>}
	 */
	public function delete( array $ids ): array {
		$ids = array_values( array_unique( array_filter( $ids, 'is_string' ) ) );
		if ( array() === $ids || count( $ids ) > 500 ) {
			throw new VersioningException( __( 'Nessun selettore da eliminare.', 'xb' ), 400 );
		}
		$usage = $this->usage();
		if ( $usage['truncated'] ) {
			throw new VersioningException( __( 'Ci sono troppi documenti per verificarli tutti: eliminazione non eseguita.', 'xb' ), 409 );
		}
		$rows = array_column( $usage['selectors'], null, 'id' );
		foreach ( $ids as $id ) {
			if ( ! isset( $rows[ $id ] ) ) {
				throw new VersioningException( __( 'Selettore inesistente: ricarica l\'elenco.', 'xb' ), 404 );
			}
			if ( isset( $rows[ $id ]['sheet'] ) ) {
				throw new VersioningException( sprintf( __( 'Il selettore %s è definito in un foglio globale: si elimina modificando il foglio.', 'xb' ), $rows[ $id ]['selector'] ), 409 );
			}
			if ( ! $rows[ $id ]['unused'] ) {
				throw new VersioningException( sprintf( __( 'Il selettore %s è in uso e non può essere eliminato.', 'xb' ), $rows[ $id ]['selector'] ), 409 );
			}
		}
		$copy    = $this->versioning->working_copy( Documents::STYLES );
		$payload = $copy['payload'];
		$deleted = array();
		$kept    = array();
		foreach ( is_array( $payload['styles'] ?? null ) ? $payload['styles'] : array() as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) && in_array( $entry['id'], $ids, true ) ) {
				$deleted[] = (string) ( $entry['selector'] ?? '' );
				continue;
			}
			$kept[] = $entry;
		}
		$payload['styles'] = $kept;
		if ( ! current_user_can( 'unfiltered_html' ) && Documents::restricted_styles( $payload ) !== $payload ) {
			throw new VersioningException( __( 'Gli stili contengono CSS che solo un amministratore può salvare: chiedi a lui di eliminare i selettori.', 'xb' ), 409 );
		}
		$this->versioning->save_dev( Documents::STYLES, $payload, $copy['head'], $copy['base'], sprintf( /* translators: %s: comma-separated selectors */ __( 'Eliminati i selettori %s', 'xb' ), implode( ', ', $deleted ) ), true );
		$this->versioning->store()->audit( 'delete_selectors', Documents::STYLES, array( 'selectors' => $deleted ) );
		return array( 'deleted' => $deleted );
	}

	// ------------------------------------------------------------------ documents

	/**
	 * Tracked documents plus every page, post and component with XB blocks, read from their dev heads
	 * (the live state when untracked). Reading has no side effects: nothing starts being tracked.
	 *
	 * @return array{docs: list<Scanned>, truncated: bool}
	 */
	private function documents(): array {
		global $wpdb;
		$docs = array();
		foreach ( $this->versioning->store()->docs( Store::DEV ) as $doc ) {
			if ( null !== Documents::post_id( $doc ) ) {
				$docs[ $doc ] = true;
			}
		}
		$types = implode( ',', array_fill( 0, count( \XB\Versioning\Documents::POST_TYPES ), '%s' ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($types) AND post_status NOT IN ('auto-draft', 'trash', 'inherit') AND post_content LIKE %s ORDER BY ID LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
				array_merge( \XB\Versioning\Documents::POST_TYPES, array( '%' . $wpdb->esc_like( 'wp:xb/' ) . '%', self::MAX_DOCS + 1 ) )
			)
		);
		$truncated = count( $ids ) > self::MAX_DOCS || count( $docs ) > self::MAX_DOCS;
		foreach ( array_slice( $ids, 0, self::MAX_DOCS ) as $id ) {
			$docs[ Documents::for_post( (int) $id ) ] = true;
		}
		$out = array();
		foreach ( array_slice( array_keys( $docs ), 0, self::MAX_DOCS ) as $doc ) {
			if ( ! Documents::valid( $doc ) ) {
				continue;
			}
			$read = $this->dev( $doc );
			if ( null === $read ) {
				continue;
			}
			$out[] = array(
				'doc'      => $doc,
				'editable' => current_user_can( 'edit_post', (int) Documents::post_id( $doc ) ),
				'trusted'  => $read['trusted'],
				'payload'  => $read['payload'],
			);
		}
		return array(
			'docs'      => $out,
			'truncated' => $truncated,
		);
	}

	/**
	 * Templates and template parts of the active block theme (customized copies included).
	 *
	 * @return list<array{label: string, content: string}>
	 */
	private static function templates(): array {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return array();
		}
		$out = array();
		foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
			foreach ( get_block_templates( array(), $type ) as $template ) {
				if ( str_contains( $template->content, 'wp:xb/' ) ) {
					$out[] = array(
						'label'   => '' !== $template->title ? $template->title : $template->slug,
						'content' => $template->content,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * The dev payload of a document and whether its head is trusted.
	 *
	 * @return array{payload: array<string, mixed>, trusted: bool}|null
	 */
	private function dev( string $doc ): ?array {
		$store = $this->versioning->store();
		$head  = $store->get( $store->head( $doc, Store::DEV ) );
		if ( null !== $head ) {
			return array(
				'payload' => $head['payload'],
				'trusted' => $head['trusted'],
			);
		}
		try {
			return array(
				'payload' => Documents::read( $doc ),
				'trusted' => true,
			);
		} catch ( VersioningException ) {
			return null;
		}
	}

	/** @return array{payload: array<string, mixed>, trusted: bool} */
	private function styles(): array {
		return $this->dev( Documents::STYLES ) ?? array(
			'payload' => array(),
			'trusted' => true,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<array{id: string, selector: string, css: string}>
	 */
	private static function entries( array $payload ): array {
		$out = array();
		foreach ( is_array( $payload['styles'] ?? null ) ? $payload['styles'] : array() as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) && is_string( $entry['selector'] ?? null ) && is_string( $entry['css'] ?? null ) ) {
				$out[] = array(
					'id'       => $entry['id'],
					'selector' => $entry['selector'],
					'css'      => $entry['css'],
				);
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------ matching

	/**
	 * What a page needs for an entry to apply: its own selector or any rule its CSS produces (BEM-hoisted
	 * rules included). An empty alternative means the entry always applies (`h1`, `:root`).
	 *
	 * @return list<list<string>>
	 */
	public static function alternatives( string $selector, string $css ): array {
		$out = CssRules::requirements( $selector );
		self::rule_requirements( CssRules::parse( CssCompiler::compile( $selector, $css ) ), $out );
		return array_values( array_unique( $out, SORT_REGULAR ) );
	}

	/**
	 * @param array<mixed>       $nodes
	 * @param list<list<string>> $out
	 */
	private static function rule_requirements( array $nodes, array &$out ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( 'r' === ( $node[0] ?? '' ) ) {
				foreach ( (array) $node[3] as $alternative ) {
					$out[] = array_values( array_map( 'strval', (array) $alternative ) );
				}
			} elseif ( 'g' === ( $node[0] ?? '' ) ) {
				self::rule_requirements( (array) $node[2], $out );
			}
		}
	}

	/**
	 * @param list<list<string>>              $alternatives
	 * @param array{0: Presence, 1: Presence} $presence
	 */
	private static function matches( array $alternatives, array $presence ): bool {
		foreach ( $alternatives as $alternative ) {
			if ( array() !== $alternative && ( $presence[0]->has_all( $alternative ) || $presence[1]->has_all( $alternative ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classes and ids of a document: static element attributes, and free HTML ({#raw}, core blocks).
	 * A class completed by an interpolation (`card--{variant}`) counts as a prefix.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{0: Presence, 1: Presence}
	 */
	private static function presence( array $payload ): array {
		$static = new Presence();
		$html   = new Presence();
		self::collect( parse_blocks( (string) ( $payload['content'] ?? '' ) ), $static, $html, 0 );
		return array( $static, $html );
	}

	/** @param array<array<string, mixed>> $blocks */
	private static function collect( array $blocks, Presence $static, Presence $html, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? null;
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( 'xb/element' === $name && is_array( $attrs['attributes'] ?? null ) ) {
				$class = $attrs['attributes']['class'] ?? null;
				if ( is_string( $class ) ) {
					self::class_attribute(
						$class,
						static function ( string $token, bool $open ) use ( $static ): ?string {
							$open ? $static->add_prefix( 'c:' . $token ) : $static->add_token( 'c:' . $token );
							return null;
						}
					);
				}
				$id = $attrs['attributes']['id'] ?? null;
				if ( is_string( $id ) && '' !== trim( $id ) && ! str_contains( $id, '{' ) ) {
					$static->add_token( 'i:' . trim( $id ) );
				}
			} elseif ( 'xb/raw' === $name && is_string( $attrs['content'] ?? null ) ) {
				$html->add_html( $attrs['content'] );
			} elseif ( ! is_string( $name ) || ! str_starts_with( $name, 'xb/' ) ) {
				$html->add_html( implode( '', array_filter( is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array(), 'is_string' ) ) );
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				self::collect( $block['innerBlocks'], $static, $html, $depth + 1 );
			}
		}
	}

	/**
	 * Class attributes in free HTML that renaming $from to $to would change, in a fixed order (C4): each class
	 * attribute of a {#raw} block, and each core or third-party block as a whole (its `className` and the class
	 * attributes of its markup change together, or the block editor would find it invalid). The occurrences found go
	 * in $found; the numbers in $apply are rewritten in the blocks returned, the others are left as written.
	 *
	 * @param array<array<string, mixed>>                     $blocks
	 * @param list<array{n: int, before: string, after: string}> $found
	 * @param array<int, true>                                $apply
	 * @return array<array<string, mixed>>
	 */
	private static function free_html( array $blocks, string $from, string $to, array &$found, array $apply, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			return $blocks;
		}
		$rename = static fn ( string $value ): string => self::class_attribute( $value, static fn ( string $token ): ?string => self::renamed( $token, $from, $to ) );
		foreach ( $blocks as $k => $block ) {
			$name = $block['blockName'] ?? null;
			if ( 'xb/raw' === $name && is_string( $block['attrs']['content'] ?? null ) ) {
				$block['attrs']['content'] = self::html_classes(
					$block['attrs']['content'],
					$rename,
					static function ( string $before, string $after ) use ( &$found, $apply ): bool {
						$n       = count( $found );
						$found[] = array(
							'n'      => $n,
							'before' => $before,
							'after'  => $after,
						);
						return isset( $apply[ $n ] );
					}
				);
			} elseif ( ! is_string( $name ) || ! str_starts_with( $name, 'xb/' ) ) {
				$attrs     = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$class     = is_string( $attrs['className'] ?? null ) ? $attrs['className'] : null;
				$parts     = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array();
				$shown     = array();
				$collect   = static function ( string $before, string $after ) use ( &$shown ): bool {
					$shown[] = array( $before, $after );
					return true;
				};
				$new_class = null === $class ? null : $rename( $class );
				$new_parts = array_map( static fn ( mixed $part ): mixed => is_string( $part ) ? self::html_classes( $part, $rename, $collect ) : $part, $parts );
				if ( ( null !== $new_class && $new_class !== $class ) || array() !== $shown ) {
					$n       = count( $found );
					$found[] = array(
						'n'      => $n,
						'before' => null !== $class && $new_class !== $class ? 'className="' . $class . '"' : $shown[0][0],
						'after'  => null !== $class && $new_class !== $class ? 'className="' . $new_class . '"' : $shown[0][1],
					);
					if ( isset( $apply[ $n ] ) ) {
						if ( null !== $new_class ) {
							$block['attrs']['className'] = $new_class;
						}
						$block['innerContent'] = $new_parts;
						$block['innerHTML']    = implode( '', array_filter( $new_parts, 'is_string' ) );
					}
				}
			}
			if ( is_array( $block['innerBlocks'] ?? null ) && array() !== $block['innerBlocks'] ) {
				$block['innerBlocks'] = self::free_html( $block['innerBlocks'], $from, $to, $found, $apply, $depth + 1 );
			}
			$blocks[ $k ] = $block;
		}
		return $blocks;
	}

	/**
	 * $html with each class attribute renamed where $keep(before, after) says so; untouched otherwise.
	 *
	 * @param callable(string): string        $rename
	 * @param callable(string, string): bool $keep
	 */
	private static function html_classes( string $html, callable $rename, callable $keep ): string {
		return (string) preg_replace_callback(
			'/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i',
			static function ( array $m ) use ( $rename, $keep ): string {
				// Group 2 (single quotes) only exists when it matched.
				$double = ! isset( $m[2] );
				$value  = $double ? $m[1] : $m[2];
				$new    = $rename( $value );
				if ( $new === $value ) {
					return $m[0];
				}
				$after = $double ? 'class="' . $new . '"' : "class='" . $new . "'";
				return $keep( $m[0], $after ) ? $after : $m[0];
			},
			$html
		);
	}

	/** @param array<string, mixed> $payload */
	private static function html_uses( array $payload, string $from ): bool {
		$presence = self::presence( $payload )[1];
		foreach ( $presence->tokens() as $token ) {
			if ( str_starts_with( $token, 'c:' ) && null !== self::renamed( substr( $token, 2 ), $from, 'x' ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------ rewriting

	/** The new name of class $name when $from becomes $to: the class itself and its BEM children. */
	public static function renamed( string $name, string $from, string $to ): ?string {
		if ( $name === $from ) {
			return $to;
		}
		foreach ( array( '__', '--' ) as $separator ) {
			if ( str_starts_with( $name, $from . $separator ) ) {
				return $to . substr( $name, strlen( $from ) );
			}
		}
		return null;
	}

	/**
	 * Visits the static class tokens of a class attribute; interpolations (`{…}`) are left untouched, and so is
	 * a token glued after one (`{x}-card`: the start of the name is not known). A token glued before one
	 * (`card--{variant}`) is passed with $open true: only its BEM prefix can be renamed.
	 *
	 * @param callable(string, bool): ?string $visit Returns the replacement, or null to keep the token.
	 */
	public static function class_attribute( string $value, callable $visit ): string {
		$parts = preg_split( '/(\{[^}]*\})/', $value, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $value );
		$last  = count( $parts ) - 1;
		foreach ( $parts as $i => $part ) {
			if ( str_starts_with( $part, '{' ) ) {
				continue;
			}
			$parts[ $i ] = (string) preg_replace_callback(
				'/[^ \t\n\f\r]+/',
				static function ( array $m ) use ( $visit, $i, $last, $part ): string {
					[ $token, $offset ] = $m[0];
					$glued_after        = 0 === $offset && $i > 0;
					$glued_before       = $offset + strlen( $token ) === strlen( $part ) && $i < $last;
					if ( $glued_after ) {
						return $token;
					}
					return $visit( $token, $glued_before ) ?? $token;
				},
				$part,
				-1,
				$count,
				PREG_OFFSET_CAPTURE
			);
		}
		return implode( '', $parts );
	}

	/**
	 * @param array<array<string, mixed>>                                        $blocks
	 * @param list<array{tag: string, attributes: array<string, string|true>}> $changed Elements whose class changed.
	 * @return array<array<string, mixed>>
	 */
	private static function rename_blocks( array $blocks, string $from, string $to, int &$hits, array &$changed, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) {
			return $blocks;
		}
		foreach ( $blocks as $i => $block ) {
			$attributes = $block['attrs']['attributes'] ?? null;
			if ( 'xb/element' === ( $block['blockName'] ?? null ) && is_array( $attributes ) && is_string( $attributes['class'] ?? null ) ) {
				$before              = $hits;
				$attributes['class'] = self::class_attribute(
					$attributes['class'],
					static function ( string $token, bool $open ) use ( $from, $to, &$hits ): ?string {
						$new = $open && $token === $from ? null : self::renamed( $token, $from, $to );
						if ( null !== $new ) {
							++$hits;
						}
						return $new;
					}
				);
				if ( $hits > $before ) {
					$blocks[ $i ]['attrs']['attributes'] = $attributes;
					$element                             = array();
					foreach ( $attributes as $name => $value ) {
						if ( is_string( $value ) || true === $value ) {
							$element[ (string) $name ] = $value;
						}
					}
					$changed[] = array(
						'tag'        => is_string( $block['attrs']['tag'] ?? null ) ? $block['attrs']['tag'] : '',
						'attributes' => $element,
					);
				}
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$blocks[ $i ]['innerBlocks'] = self::rename_blocks( $block['innerBlocks'], $from, $to, $hits, $changed, $depth + 1 );
			}
		}
		return $blocks;
	}

	/** @param array<array<string, mixed>> $blocks */
	private static function walk_classes( array $blocks, callable $visit, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $blocks as $block ) {
			$class = $block['attrs']['attributes']['class'] ?? null;
			if ( 'xb/element' === ( $block['blockName'] ?? null ) && is_string( $class ) ) {
				self::class_attribute( $class, static fn ( string $token, bool $open ): ?string => $open ? null : $visit( $token ) );
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				self::walk_classes( $block['innerBlocks'], $visit, $depth + 1 );
			}
		}
	}

	/**
	 * Renamed elements would be filtered again when the commit is untrusted (ContentSanitizer recognizes known
	 * nodes by their exact attributes): they must pass the restricted tier unchanged.
	 *
	 * @param list<array{tag: string, attributes: array<string, string|true>}> $changed
	 */
	private function restricted_safe( array $changed ): bool {
		foreach ( $changed as $element ) {
			if ( array() !== $this->policy->check_element( $element['tag'], $element['attributes'], MarkupPolicy::TIER_RESTRICTED ) ) {
				return false;
			}
			$style = $element['attributes']['style'] ?? null;
			if ( is_string( $style ) && self::declarations( safecss_filter_attr( $style ) ) !== self::declarations( $style ) ) {
				return false;
			}
		}
		return true;
	}

	private static function declarations( string $css ): int {
		return count( array_filter( array_map( 'trim', explode( ';', $css ) ), static fn ( string $d ): bool => '' !== $d ) );
	}

	/**
	 * Renames the class in entry selectors, in the selectors nested in entry bodies and in the global sheets.
	 * With $visit set, nothing is renamed: every class name met is passed to it instead.
	 *
	 * @param array<string, mixed>          $payload
	 * @param (callable(string): ?string)|null $visit
	 * @return array<string, mixed>
	 */
	private static function rename_styles( array $payload, string $from, string $to, int &$hits, ?callable $visit ): array {
		$hits   = 0;
		$rename = $visit ?? static function ( string $raw ) use ( $from, $to, &$hits ): ?string {
			$new = self::renamed( $raw, $from, $to );
			if ( null !== $new ) {
				++$hits;
			}
			return $new;
		};
		$styles = is_array( $payload['styles'] ?? null ) ? $payload['styles'] : array();
		foreach ( $styles as $i => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( is_string( $entry['selector'] ?? null ) ) {
				$styles[ $i ]['selector'] = self::rename_in_selector( $entry['selector'], $rename );
			}
			if ( is_string( $entry['css'] ?? null ) ) {
				$styles[ $i ]['css'] = self::rename_in_sheet( $entry['css'], $rename );
			}
		}
		$payload['styles'] = $styles;
		$globals           = is_array( $payload['globals'] ?? null ) ? $payload['globals'] : array();
		foreach ( $globals as $i => $sheet ) {
			if ( is_array( $sheet ) && is_string( $sheet['css'] ?? null ) ) {
				$globals[ $i ]['css'] = self::rename_in_sheet( $sheet['css'], $rename );
			}
		}
		$payload['globals'] = $globals;
		return $payload;
	}

	/**
	 * Applies $rename to the classes of every rule prelude in a sheet or rule body. Declarations, strings,
	 * comments and at-rule preludes are kept byte for byte.
	 *
	 * @param callable(string): ?string $rename Raw class name → new name, or null to keep it.
	 */
	public static function rename_in_sheet( string $css, callable $rename ): string {
		$out   = '';
		$start = 0;
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				++$i;
			} elseif ( '"' === $c || "'" === $c ) {
				$i = self::string_end( $css, $i );
			} elseif ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 1;
			} elseif ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ( ')' === $c || ']' === $c ) && $depth > 0 ) {
				--$depth;
			} elseif ( 0 === $depth && '{' === $c ) {
				$prelude = substr( $css, $start, $i - $start );
				$is_at   = str_starts_with( ltrim( (string) preg_replace( '#/\*.*?\*/#s', '', $prelude ) ), '@' );
				$out    .= ( $is_at ? $prelude : self::rename_in_selector( $prelude, $rename ) ) . '{';
				$start   = $i + 1;
			} elseif ( 0 === $depth && ( ';' === $c || '}' === $c ) ) {
				$out  .= substr( $css, $start, $i - $start + 1 );
				$start = $i + 1;
			}
		}
		return $out . substr( $css, $start );
	}

	/**
	 * Applies $rename to every class (`.name`) of a selector list, inside :is()/:not() too. Attribute
	 * selectors, strings and comments are kept. A name with escapes is passed raw (`card--md\:x`).
	 *
	 * @param callable(string): ?string $rename
	 */
	public static function rename_in_selector( string $selector, callable $rename ): string {
		$out = '';
		$len = strlen( $selector );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $selector[ $i ];
			if ( '\\' === $c ) {
				$out .= substr( $selector, $i, 2 );
				++$i;
			} elseif ( '"' === $c || "'" === $c ) {
				$end  = self::string_end( $selector, $i );
				$out .= substr( $selector, $i, $end - $i + 1 );
				$i    = $end;
			} elseif ( '/' === $c && '*' === ( $selector[ $i + 1 ] ?? '' ) ) {
				$end  = strpos( $selector, '*/', $i + 2 );
				$end  = false === $end ? $len - 1 : $end + 1;
				$out .= substr( $selector, $i, $end - $i + 1 );
				$i    = $end;
			} elseif ( '[' === $c ) {
				$end  = self::bracket_end( $selector, $i );
				$out .= substr( $selector, $i, $end - $i + 1 );
				$i    = $end;
			} elseif ( '.' === $c ) {
				$j = $i + 1;
				while ( $j < $len ) {
					$d = $selector[ $j ];
					if ( '\\' === $d && $j + 1 < $len ) {
						$j += 2;
					} elseif ( ctype_alnum( $d ) || '-' === $d || '_' === $d || ord( $d ) >= 0x80 ) {
						++$j;
					} else {
						break;
					}
				}
				$raw  = substr( $selector, $i + 1, $j - $i - 1 );
				$new  = '' === $raw ? null : $rename( $raw );
				$out .= '.' . ( $new ?? $raw );
				$i    = $j - 1;
			} else {
				$out .= $c;
			}
		}
		return $out;
	}

	private static function string_end( string $s, int $open ): int {
		$quote = $s[ $open ];
		$len   = strlen( $s );
		for ( $i = $open + 1; $i < $len; $i++ ) {
			if ( '\\' === $s[ $i ] ) {
				++$i;
			} elseif ( $s[ $i ] === $quote || "\n" === $s[ $i ] ) {
				return $i;
			}
		}
		return $len - 1;
	}

	private static function bracket_end( string $s, int $open ): int {
		$len = strlen( $s );
		for ( $i = $open + 1; $i < $len; $i++ ) {
			if ( '\\' === $s[ $i ] ) {
				++$i;
			} elseif ( '"' === $s[ $i ] || "'" === $s[ $i ] ) {
				$i = self::string_end( $s, $i );
			} elseif ( ']' === $s[ $i ] ) {
				return $i;
			}
		}
		return $len - 1;
	}
}
