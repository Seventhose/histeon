<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Ai;

use XB\Plugin;
use XB\Render\Context;
use XB\Rest\Controller;
use XB\Versioning\Documents;
use XB\Versioning\Preview;
use XB\Versioning\ReleaseChecks;
use XB\Versioning\VersioningException;

/**
 * XB for AI agents — WordPress Abilities API, exposed over MCP by an MCP adapter — docs/spec/ai.md.
 *
 * Threat model T5/T6:
 * - every ability runs as the authenticated WordPress user and checks the same capabilities as the builder;
 * - agents write only to the dev branch, through the same sanitizer and with the user's own permissions;
 * - agents never publish: xb/publish-release (xb_build + xb_publish) is read-only and not destructive, it lists the
 *   changes and returns the Release screen, where a person checks and publishes (its `confirm` is no human decision);
 * - every write is recorded in the audit log;
 * - no ability executes code: operations are data, validated server-side.
 */
final class Abilities {

	public const CATEGORY = 'xb';

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'categories' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'abilities' ) );
	}

	public static function categories(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Histeon',
				'description' => __( 'Costruire pagine con Histeon: leggere, modificare in sviluppo, vedere l\'anteprima, pubblicare.', 'xb' ),
			)
		);
	}

	public static function abilities(): void {
		$can_build = static fn (): bool => current_user_can( 'xb_build' );
		$can_doc   = static function ( mixed $input ) use ( $can_build ): bool {
			$id = is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0;
			return $can_build() && self::editable( $id );
		};
		$id_schema = array(
			'type'                 => 'object',
			'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);

		self::add(
			'xb/list-documents',
			__( 'Elenca i documenti Histeon', 'xb' ),
			__( 'Pagine, articoli e componenti modificabili con Histeon, con lo stato di pubblicazione.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'type'   => array( 'type' => 'string', 'enum' => Controller::DOCUMENT_TYPES ),
					'search' => array( 'type' => 'string', 'maxLength' => 100 ),
				),
				'additionalProperties' => false,
			),
			$can_build,
			array( self::class, 'list_documents' ),
			true
		);
		self::add(
			'xb/get-document',
			__( 'Leggi un documento Histeon', 'xb' ),
			__( 'Albero della versione di sviluppo con gli uid da usare in xb/apply-operations.', 'xb' ),
			$id_schema,
			$can_doc,
			array( self::class, 'get_document' ),
			true
		);
		self::add(
			'xb/render-document',
			__( 'Renderizza un documento Histeon', 'xb' ),
			__( 'HTML della versione di sviluppo, come lo vedrà un visitatore.', 'xb' ),
			$id_schema,
			$can_doc,
			array( self::class, 'render_document' ),
			true
		);
		self::add(
			'xb/apply-operations',
			__( 'Modifica un documento Histeon in sviluppo', 'xb' ),
			__( 'Applica operazioni strutturate (set_text, set_attribute, set_tag, set_label, insert_html, remove, move) e salva una nuova versione di sviluppo. Il sito pubblicato non cambia.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array( 'type' => 'integer' ),
					'head'       => array( 'type' => 'string', 'pattern' => '^[0-9a-f]{32}$' ),
					'message'    => array( 'type' => 'string', 'maxLength' => 200 ),
					'operations' => array(
						'type'     => 'array',
						'maxItems' => Operations::MAX,
						'items'    => array( 'type' => 'object' ),
					),
				),
				'required'             => array( 'id', 'operations' ),
				'additionalProperties' => false,
			),
			$can_doc,
			array( self::class, 'apply_operations' ),
			false
		);
		self::add(
			'xb/set-style',
			__( 'Scrivi lo stile di una classe', 'xb' ),
			__( 'Imposta il CSS di un selettore di classe (nesting nativo e &__ / &-- BEM) nella versione di sviluppo.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'selector' => array( 'type' => 'string', 'pattern' => '^\\.[A-Za-z_-][A-Za-z0-9_-]*$' ),
					'css'      => array( 'type' => 'string', 'maxLength' => 20000 ),
				),
				'required'             => array( 'selector', 'css' ),
				'additionalProperties' => false,
			),
			$can_build,
			array( self::class, 'set_style' ),
			false
		);
		self::add(
			'xb/get-framework',
			__( 'Variabili e classi del framework CSS', 'xb' ),
			__( 'Nomi da usare negli stili: token --xb-* e nomi del framework attivo.', 'xb' ),
			array( 'type' => 'object', 'additionalProperties' => false ),
			$can_build,
			array( self::class, 'get_framework' ),
			true
		);
		// The same reading as the local connector (`manual`, `docs`, `brief`): the rules first, then the site.
		self::add(
			'xb/manual',
			__( 'Manuale di Histeon per gli agenti', 'xb' ),
			__( 'Le regole per costruire pagine con Histeon: leggilo tutto prima di modificare.', 'xb' ),
			array( 'type' => 'object', 'additionalProperties' => false ),
			$can_build,
			static fn (): array => array( 'text' => Brief::manual() ),
			true
		);
		self::add(
			'xb/docs',
			__( 'Documentazione per gli agenti', 'xb' ),
			__( 'Un argomento della documentazione; senza argomento, l\'indice. Modello piccolo o poco contesto: «rapido» e basta. Gli altri cominciano da «ecosistema», «mestiere» e «progettare».', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array( 'topic' => array( 'type' => 'string', 'maxLength' => 40 ) ),
				'additionalProperties' => false,
			),
			$can_build,
			static fn ( array $input ): array => Brief::doc( (string) ( $input['topic'] ?? '' ) ),
			true
		);
		self::add(
			'xb/brief',
			__( 'Brief del sito', 'xb' ),
			__( 'Cosa ha il sito: framework e valori delle variabili, media query con nome, query dei loop e altri dati leggibili, blocchi BEM, componenti. È la fonte dei nomi da usare.', 'xb' ),
			array( 'type' => 'object', 'additionalProperties' => false ),
			$can_build,
			static fn (): array => Brief::site(),
			true
		);
		self::add(
			'xb/list-changes',
			__( 'Modifiche non pubblicate', 'xb' ),
			__( 'Documenti la cui versione di sviluppo differisce da quella pubblicata.', 'xb' ),
			array( 'type' => 'object', 'additionalProperties' => false ),
			$can_build,
			static fn (): array => Controller::visible_changes(),
			true
		);
		self::add(
			'xb/last-checks',
			__( 'Esito degli ultimi controlli', 'xb' ),
			__( 'Quanti errori e avvisi avevano trovato i controlli delle pagine con cui è uscita l\'ultima release dal builder, regola per regola, con versione, data e, se c\'è, quante pagine erano state controllate su quelle della release (pages: checked di total; con meno pagine i numeri non dicono nulla delle altre); null se non c\'è. È un promemoria, non una prova: leggilo prima di chiedere la pubblicazione.', 'xb' ),
			array( 'type' => 'object', 'additionalProperties' => false ),
			$can_build,
			static fn (): array => array( 'checks' => ReleaseChecks::last() ),
			true
		);
		self::add(
			'xb/preview-url',
			__( 'Link di anteprima', 'xb' ),
			__( 'Link firmato e a scadenza alla versione di sviluppo di un documento.', 'xb' ),
			$id_schema,
			$can_doc,
			static fn ( array $input ): array => array( 'url' => Preview::url( (int) $input['id'] ) ),
			true
		);
		$can_upload = static fn (): bool => current_user_can( 'xb_build' ) && current_user_can( 'upload_files' );
		$can_media  = static fn ( mixed $input ): bool => $can_upload() && current_user_can( 'edit_post', is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0 );
		self::add(
			'xb/list-media',
			__( 'Elenca i file della libreria dei media', 'xb' ),
			__( 'Immagini e altri file con indirizzo, testo alternativo, misure, peso e raccolte. Filtri: search, type (image, video, audio, pdf), collection (0 = senza raccolta), missing_alt (solo immagini senza testo alternativo).', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'search'      => array( 'type' => 'string', 'maxLength' => 100 ),
					'type'        => array( 'type' => 'string', 'enum' => array( 'image', 'video', 'audio', 'pdf' ) ),
					'collection'  => array( 'type' => 'integer', 'minimum' => 0 ),
					'missing_alt' => array( 'type' => 'boolean' ),
					'page'        => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'additionalProperties' => false,
			),
			$can_upload,
			array( MediaTools::class, 'list' ),
			true
		);
		self::add(
			'xb/list-collections',
			__( 'Elenca le raccolte dei media', 'xb' ),
			__( 'Le cartelle della libreria dei media, con il genitore e il numero di file.', 'xb' ),
			array( 'type' => 'object', 'additionalProperties' => false ),
			$can_upload,
			static fn (): array => MediaTools::collections(),
			true
		);
		self::add(
			'xb/set-media-alt',
			__( 'Scrivi il testo alternativo di un\'immagine', 'xb' ),
			__( 'Il testo alternativo salvato sull\'immagine della libreria: vale subito dove l\'immagine prende il suo testo dalla libreria.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'  => array( 'type' => 'integer' ),
					'alt' => array( 'type' => 'string', 'maxLength' => 500 ),
				),
				'required'             => array( 'id', 'alt' ),
				'additionalProperties' => false,
			),
			$can_media,
			array( MediaTools::class, 'set_alt' ),
			false
		);
		self::add(
			'xb/set-media-collections',
			__( 'Metti un file nelle sue raccolte', 'xb' ),
			__( 'Tutte le raccolte a cui il file appartiene (una lista vuota lo toglie da tutte).', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array( 'type' => 'integer' ),
					'collections' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'maxItems' => 50,
					),
				),
				'required'             => array( 'id', 'collections' ),
				'additionalProperties' => false,
			),
			$can_media,
			array( MediaTools::class, 'set_collections' ),
			false
		);
		$can_content = static fn ( mixed $input ): bool => $can_build() && current_user_can( 'edit_post', is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0 );
		self::add(
			'xb/search-content',
			__( 'Cerca fra i contenuti', 'xb' ),
			__( 'Articoli, pagine e record di ogni tipo che l\'utente può modificare, in ogni stato tranne il cestino.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'search' => array( 'type' => 'string', 'maxLength' => 100 ),
					'type'   => array( 'type' => 'string', 'maxLength' => 40 ),
					'page'   => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'additionalProperties' => false,
			),
			$can_build,
			static fn ( array $input ): array => Contents::search( (string) ( $input['search'] ?? '' ), (string) ( $input['type'] ?? '' ), max( 1, (int) ( $input['page'] ?? 1 ) ) ),
			true
		);
		self::add(
			'xb/get-content',
			__( 'Leggi un contenuto con i suoi campi', 'xb' ),
			__( 'Titolo e riassunto come scritti, stato, indirizzo e campi.', 'xb' ),
			$id_schema,
			$can_content,
			static fn ( array $input ): array|\WP_Error => Contents::get( (int) $input['id'] ),
			true
		);
		self::add(
			'xb/update-content',
			__( 'Cambia titolo, riassunto o campi di un contenuto', 'xb' ),
			__( 'Cambia solo ciò che indichi; lo stato non cambia mai (una bozza resta bozza). La modifica vale subito sul sito: i valori di prima restano nel punto di ripristino della sessione dell\'agente.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string', 'maxLength' => 500 ),
					'excerpt' => array( 'type' => 'string', 'maxLength' => 5000 ),
					'fields'  => array( 'type' => 'object' ),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			$can_content,
			static fn ( array $input ): array|\WP_Error => Contents::update( (int) $input['id'], array_diff_key( $input, array( 'id' => true ) ) ),
			false
		);
		self::add(
			'xb/set-query',
			__( 'Scrivi o togli una query dei loop', 'xb' ),
			__( 'La query con quel nome nella versione di sviluppo: {type, args} come nel pannello Query; null la toglie. Stessi controlli del pannello.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'name'  => array( 'type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]{0,40}$' ),
					'query' => array( 'type' => array( 'object', 'null' ) ),
				),
				'required'             => array( 'name', 'query' ),
				'additionalProperties' => false,
			),
			$can_build,
			static fn ( array $input ): array => self::set_settings_entry( 'presets', (string) $input['name'], $input['query'] ),
			false
		);
		self::add(
			'xb/set-source',
			__( 'Scrivi o togli una sorgente JSON', 'xb' ),
			__( 'I dati della sorgente con quel nome (data.nome) nella versione di sviluppo; null la toglie.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'name' => array( 'type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]{0,40}$' ),
					'data' => array( 'type' => array( 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' ) ),
				),
				'required'             => array( 'name', 'data' ),
				'additionalProperties' => false,
			),
			$can_build,
			static fn ( array $input ): array => self::set_settings_entry( 'sources', (string) $input['name'], null === $input['data'] ? null : array( 'type' => 'json', 'data' => $input['data'] ) ),
			false
		);
		self::add(
			'xb/publish-release',
			__( 'Chiedi di pubblicare una release', 'xb' ),
			__( 'Prepara la pubblicazione delle modifiche di sviluppo: elenca cosa andrebbe online e restituisce la pagina Release, dove una persona controlla e pubblica. Un agente non pubblica da solo.', 'xb' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'notes'   => array( 'type' => 'string', 'maxLength' => 2000 ),
					'version' => array( 'type' => 'string', 'pattern' => '^\\d{1,4}\\.\\d{1,4}\\.\\d{1,4}$' ),
					'confirm' => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'notes', 'confirm' ),
				'additionalProperties' => false,
			),
			static fn (): bool => current_user_can( 'xb_build' ) && current_user_can( 'xb_publish' ),
			array( self::class, 'publish' ),
			true
		);
	}

	/** Areas of the writing abilities (Scopes): the administrator's choice applies to remote agents too. */
	private const SCOPES = array(
		'xb/set-style'             => 'stile',
		'xb/set-media-alt'         => 'media',
		'xb/set-media-collections' => 'media',
		'xb/update-content'        => 'contenuti',
		'xb/set-query'             => 'dati',
		'xb/set-source'            => 'dati',
	);

	/**
	 * The area an ability call changes: for operations, texts when they only set texts, the structure otherwise.
	 *
	 * @param array<string, mixed> $input
	 */
	private static function scope( string $name, array $input ): ?string {
		if ( 'xb/apply-operations' === $name ) {
			$ops = is_array( $input['operations'] ?? null ) ? $input['operations'] : array();
			foreach ( $ops as $op ) {
				if ( ! is_array( $op ) || 'set_text' !== ( $op['op'] ?? null ) ) {
					return 'struttura';
				}
			}
			return array() === $ops ? null : 'testi';
		}
		return self::SCOPES[ $name ] ?? null;
	}

	/**
	 * One loop query or JSON source of the settings document set (or removed with null), with the checks of the
	 * Queries panel, as a new development version.
	 *
	 * @return array{head: string}
	 */
	public static function set_settings_entry( string $key, string $name, mixed $value ): array {
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::SETTINGS );
		$payload    = $copy['payload'];
		$presets    = is_array( $payload['presets'] ?? null ) ? $payload['presets'] : array();
		$sources    = is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array();
		$list       = 'presets' === $key ? $presets : $sources;
		if ( null === $value ) {
			unset( $list[ $name ] );
		} else {
			$list[ $name ] = $value;
		}
		if ( 'presets' === $key ) {
			$presets = $list;
		} else {
			$sources = $list;
		}
		$payload['presets'] = \XB\Render\Queries::validate( $presets );
		$payload['sources'] = \XB\Render\Sources::validate( $sources, array_keys( $payload['presets'] ) );
		/* translators: %s: query or source name */
		$message = sprintf( 'presets' === $key ? __( 'Query %s da agente AI', 'xb' ) : __( 'Sorgente %s da agente AI', 'xb' ), $name );
		$commit  = $versioning->save_dev( Documents::SETTINGS, $payload, $copy['head'], $copy['base'], $message, agent: true );
		return array( 'head' => $commit['id'] );
	}

	/**
	 * @param lowercase-string&non-falsy-string $name
	 * @param array<string, mixed>              $input_schema
	 * @param callable                          $permission
	 * @param callable                          $execute
	 */
	private static function add( string $name, string $label, string $description, array $input_schema, callable $permission, callable $execute, bool $readonly, bool $destructive = false ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'permission_callback' => $permission,
				'execute_callback'    => static function ( mixed $input = null ) use ( $execute, $name, $readonly ): mixed {
					$scope = self::scope( $name, is_array( $input ) ? $input : array() );
					if ( null !== $scope && ! Scopes::allowed( $scope ) ) {
						return Scopes::refusal( $scope );
					}
					try {
						$result = $execute( is_array( $input ) ? $input : array() );
					} catch ( VersioningException | \InvalidArgumentException $e ) {
						return new \WP_Error( 'xb_ability', $e->getMessage(), array( 'status' => $e instanceof VersioningException ? $e->status : 400 ) );
					}
					if ( ! $readonly ) {
						Plugin::instance()->versioning->store()->audit( 'ability', $name, array( 'ok' => ! is_wp_error( $result ) ) );
					}
					return $result;
				},
				'meta'                => array(
					'public'      => true,
					'annotations' => array(
						'readonly'    => $readonly,
						'destructive' => $destructive,
						'idempotent'  => $readonly,
					),
				),
			)
		);
	}

	private static function editable( int $id ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && in_array( $post->post_type, Controller::DOCUMENT_TYPES, true ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return list<array<string, mixed>>
	 */
	public static function list_documents( array $input ): array {
		$posts = get_posts(
			array(
				'post_type'   => is_string( $input['type'] ?? null ) ? $input['type'] : Controller::DOCUMENT_TYPES,
				'post_status' => array( 'publish', 'draft', 'private', 'future' ),
				's'           => is_string( $input['search'] ?? null ) ? $input['search'] : '',
				'numberposts' => 100,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$out[] = array(
					'id'     => $post->ID,
					'type'   => $post->post_type,
					'title'  => $post->post_title,
					'status' => $post->post_status,
					'xb'     => \XB\Spec::has_xb_blocks( $post->post_content ),
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function get_document( array $input ): array {
		$id   = (int) $input['id'];
		$copy = Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) );
		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'head'      => $copy['head'],
			'published' => $copy['head'] === $copy['base'],
			'tree'      => Operations::outline( parse_blocks( (string) ( $copy['payload']['content'] ?? '' ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{html: string}
	 */
	public static function render_document( array $input ): array {
		$id   = (int) $input['id'];
		$copy = Plugin::instance()->versioning->working_copy( Documents::for_post( $id ) );
		// The development view lasts for this rendering only: what the request does next (another ability, a page) is
		// not rendered from development because of it.
		$was_dev = Preview::active();
		Preview::use_dev();
		global $post;
		$previous = $post;
		$post     = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
		try {
			$html = Plugin::instance()->renderer->render_blocks( parse_blocks( (string) ( $copy['payload']['content'] ?? '' ) ), Context::from_globals() );
		} finally {
			$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			if ( ! $was_dev ) {
				Preview::reset();
			}
		}
		return array( 'html' => mb_substr( $html, 0, 200000 ) );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function apply_operations( array $input ): array {
		$id         = (int) $input['id'];
		$doc        = Documents::for_post( $id );
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( $doc );
		if ( is_string( $input['head'] ?? null ) && $input['head'] !== $copy['head'] ) {
			throw new VersioningException( __( 'Il documento è cambiato: rileggilo con xb/get-document.', 'xb' ), 409 );
		}
		$operations = is_array( $input['operations'] ?? null ) ? array_values( $input['operations'] ) : array();
		$blocks     = Operations::apply( parse_blocks( (string) ( $copy['payload']['content'] ?? '' ) ), $operations );
		$payload    = array( 'content' => Operations::serialize( $blocks ) ) + $copy['payload'];
		$message    = is_string( $input['message'] ?? null ) ? sanitize_text_field( $input['message'] ) : '';
		$commit     = $versioning->save_dev( $doc, $payload, $copy['head'], $copy['base'], '' === $message ? __( 'Modifica da agente AI', 'xb' ) : $message, true, agent: true );
		return array(
			'head'    => $commit['id'],
			'applied' => count( $operations ),
			'tree'    => Operations::outline( parse_blocks( (string) ( $commit['payload']['content'] ?? '' ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function set_style( array $input ): array {
		$selector = (string) $input['selector'];
		$css      = (string) $input['css'];
		if ( 1 !== preg_match( '/^\.[A-Za-z_-][A-Za-z0-9_-]*$/', $selector ) ) {
			throw new \InvalidArgumentException( __( 'Selettore non valido: una sola classe.', 'xb' ) );
		}
		$versioning = Plugin::instance()->versioning;
		$copy       = $versioning->working_copy( Documents::STYLES );
		$styles     = is_array( $copy['payload']['styles'] ?? null ) ? array_values( $copy['payload']['styles'] ) : array();
		$found      = false;
		foreach ( $styles as $i => $entry ) {
			if ( is_array( $entry ) && ( $entry['selector'] ?? null ) === $selector ) {
				$styles[ $i ]['css'] = $css;
				$found               = true;
			}
		}
		if ( ! $found ) {
			$styles[] = array(
				'id'       => substr( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( ltrim( $selector, '.' ) ) ), 0, 56 ) . '-' . substr( Uids::fresh(), 0, 6 ),
				'selector' => $selector,
				'css'      => $css,
			);
		}
		// The same ceiling as the builder's own save (Rest\Controller::save_styles): every rule and global sheet together.
		$bytes = 0;
		foreach ( array_merge( $styles, is_array( $copy['payload']['globals'] ?? null ) ? array_values( $copy['payload']['globals'] ) : array() ) as $entry ) {
			$bytes += is_array( $entry ) && is_string( $entry['css'] ?? null ) ? strlen( $entry['css'] ) : 0;
		}
		if ( $bytes > \XB\Rest\Controller::MAX_CSS_BYTES ) {
			throw new \InvalidArgumentException( __( 'Gli stili superano la dimensione massima.', 'xb' ) );
		}
		$commit = $versioning->save_dev( Documents::STYLES, array( 'styles' => $styles ) + $copy['payload'], $copy['head'], $copy['base'], sprintf( /* translators: %s: CSS class selector */ __( 'Stile %s da agente AI', 'xb' ), $selector ), true, agent: true );
		return array( 'head' => $commit['id'] );
	}

	/** @return array<string, mixed> */
	public static function get_framework(): array {
		$active = Plugin::instance()->frameworks->active();
		return array(
			'framework' => $active->id(),
			'tokens'    => array_map( static fn ( string $t ): string => '--' . $t, array_keys( $active->aliases() ) ?: ( $active->variables()['xb'] ?? array() ) ),
			'variables' => array_map( static fn ( array $names ): array => array_slice( $names, 0, 300 ), $active->variables() ),
			'classes'   => array_map( static fn ( array $names ): array => array_slice( $names, 0, 300 ), $active->classes() ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function publish( array $input ): array {
		// The agent reads site content written by others, and a flag in its own call is no human decision: the
		// release is approved and published by a person on the Release screen (threat model T6).
		// Only the changes this user may see, as xb/list-changes and the Release screen list them.
		$changes = Controller::visible_changes();
		return array(
			'status'  => 'approval-required',
			'message' => __( 'Le modifiche sono in sviluppo. Per pubblicarle, una persona apre la pagina Release, le controlla e pubblica.', 'xb' ),
			'url'     => \XB\Admin\Menu::url( \XB\Admin\ReleasesScreen::SLUG ),
			'notes'   => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
			'changes' => $changes,
		);
	}
}
