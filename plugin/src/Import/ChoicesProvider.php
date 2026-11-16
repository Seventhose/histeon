<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import;

use XB\Deploy\Credentials;

/**
 * Who answers the choices of an HTML import (doc 27 of Histeon Publish, G4), set in «Importa HTML» → «Modello per i
 * nomi»: Claude dal connettore (the Claude of the person's plan, in Claude Code or Claude Desktop with the Histeon
 * Connector: the page asks it, see ChoicesRest::check), Claude Haiku 5.5 with the site's Anthropic API key, or a server
 * compatible with OpenAI's chat API (llama-server, LM Studio, Ollama), for a site in Local next to a model on the same
 * computer. Nothing saved: Haiku when its key is there, otherwise the connector, which needs no key (the plugin cannot
 * know whether a connector runs: only the browser reaches it). The keys are stored encrypted like the Cloudflare token (Credentials, key derived from the salts of
 * wp-config.php), or the Anthropic one in XB_ANTHROPIC_API_KEY; never returned, logged or sent to the browser.
 *
 * Only the server asks the model, never the page; the page calls ChoicesRest. Raw HTTP with wp_remote_post, as every
 * outgoing call of the plugin: no SDK in a WordPress plugin. No retry, no redirect (the key would follow it).
 */
final class ChoicesProvider {

	public const OPTION     = 'xb_import_choices';
	public const HAIKU      = 'haiku';
	public const COMPATIBLE = 'compatibile';
	public const CONNECTOR  = 'connettore';
	public const PROVIDERS  = array( self::CONNECTOR, self::HAIKU, self::COMPATIBLE );
	/** What the table names as the model when Claude answers from the connector. */
	public const CONNECTOR_MODEL = 'Claude';

	public const HAIKU_MODEL       = 'claude-haiku-5-5';
	public const ANTHROPIC_URL     = 'https://api.anthropic.com/v1/messages';
	public const ANTHROPIC_VERSION = '2026-10-21';
	/** Seconds per batch, as in Publish. */
	public const TIMEOUT = 60;

	private const KEY_INFO      = 'xb-import-choices-key-v1';
	private const URL_MAX       = 200;
	private const MODEL_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9._:/-]{0,79}$~D';
	private const KEY_PATTERN   = '~^\S{8,400}$~D';
	private const RESPONSE_MAX  = 1048576;

	/** @return array{provider: string, url: string, model: string} */
	public static function defaults(): array {
		return array(
			'provider' => '' !== self::key( self::HAIKU ) ? self::HAIKU : self::CONNECTOR,
			'url'      => 'http://127.0.0.1:18080',
			'model'    => 'qwen3-8b',
		);
	}

	/** @return array<string, mixed> */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The settings as saved, with the defaults for anything missing or no longer valid.
	 *
	 * @return array{provider: string, url: string, model: string}
	 */
	public static function settings(): array {
		$stored   = self::stored();
		$defaults = self::defaults();
		$url      = is_string( $stored['url'] ?? null ) && null === self::url_problem( $stored['url'] ) ? $stored['url'] : $defaults['url'];
		$model    = is_string( $stored['model'] ?? null ) && 1 === preg_match( self::MODEL_PATTERN, $stored['model'] ) ? $stored['model'] : $defaults['model'];
		return array(
			'provider' => in_array( $stored['provider'] ?? null, self::PROVIDERS, true ) ? (string) $stored['provider'] : $defaults['provider'],
			'url'      => $url,
			'model'    => $model,
		);
	}

	public static function haiku_key_from_config(): bool {
		return defined( 'XB_ANTHROPIC_API_KEY' ) && is_string( constant( 'XB_ANTHROPIC_API_KEY' ) ) && '' !== constant( 'XB_ANTHROPIC_API_KEY' );
	}

	/** The key of a provider, decrypted; '' when there is none (or it can no longer be decrypted). */
	public static function key( string $provider ): string {
		if ( self::HAIKU === $provider && self::haiku_key_from_config() ) {
			return (string) constant( 'XB_ANTHROPIC_API_KEY' );
		}
		$stored = self::stored();
		$cipher = $stored[ 'key_' . $provider ] ?? '';
		return is_string( $cipher ) && '' !== $cipher ? Credentials::decrypt_with( $cipher, self::KEY_INFO ) : '';
	}

	/**
	 * The model the table names.
	 *
	 * @param array{provider: string, url: string, model: string}|null $settings
	 */
	public static function model_name( ?array $settings = null ): string {
		$settings ??= self::settings();
		return match ( $settings['provider'] ) {
			self::HAIKU     => self::HAIKU_MODEL,
			self::CONNECTOR => self::CONNECTOR_MODEL,
			default         => $settings['model'],
		};
	}

	/** Why the provider cannot be asked now, or null when it can. */
	public static function not_ready(): ?string {
		$settings = self::settings();
		if ( self::HAIKU === $settings['provider'] && '' === self::key( self::HAIKU ) ) {
			return __( 'Manca la chiave API di Anthropic: aggiungila in «Modello per i nomi», sotto «Converti una pagina HTML».', 'xb' );
		}
		return null;
	}

	/**
	 * What the page may see: never a key.
	 *
	 * @return array{provider: string, url: string, model: string, modelName: string, haikuKeySet: bool, haikuFromConfig: bool, compatibleKeySet: bool, unreadable: bool, salts: bool, ready: bool}
	 */
	public static function public_view(): array {
		$settings   = self::settings();
		$stored     = self::stored();
		$unreadable = false;
		foreach ( array( self::HAIKU, self::COMPATIBLE ) as $provider ) {
			$cipher = $stored[ 'key_' . $provider ] ?? '';
			if ( is_string( $cipher ) && '' !== $cipher && '' === Credentials::decrypt_with( $cipher, self::KEY_INFO ) && ! ( self::HAIKU === $provider && self::haiku_key_from_config() ) ) {
				$unreadable = true;
			}
		}
		return array(
			'provider'         => $settings['provider'],
			'url'              => $settings['url'],
			'model'            => $settings['model'],
			'modelName'        => self::model_name( $settings ),
			'haikuKeySet'      => '' !== self::key( self::HAIKU ),
			'haikuFromConfig'  => self::haiku_key_from_config(),
			'compatibleKeySet' => '' !== self::key( self::COMPATIBLE ),
			'unreadable'       => $unreadable,
			'salts'            => Credentials::salts_in_config(),
			'ready'            => null === self::not_ready(),
		);
	}

	/** The address of a compatible server: http or https, with no user, password, query or fragment. */
	public static function url_problem( string $value ): ?string {
		if ( strlen( $value ) > self::URL_MAX ) {
			return __( 'l’indirizzo è troppo lungo', 'xb' );
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			return __( 'non è un indirizzo', 'xb' );
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return __( 'l’indirizzo deve cominciare con http:// o https://', 'xb' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return __( 'l’indirizzo non può contenere nome utente o password: la chiave va nel suo campo', 'xb' );
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || str_contains( $value, '?' ) || str_contains( $value, '#' ) ) {
			return __( 'l’indirizzo non può avere ? o #', 'xb' );
		}
		return null;
	}

	/**
	 * Saves the settings. A key: a string saves it (encrypted), '' keeps the current one; `$forget` lists the providers
	 * whose key goes. Anything that does not fit is refused with the reason; nothing is half saved.
	 *
	 * @param list<string> $forget
	 * @throws \InvalidArgumentException With what is wrong.
	 */
	public static function save( string $provider, string $url, string $model, string $haiku_key, string $compatible_key, array $forget = array() ): void {
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			throw new \InvalidArgumentException( __( 'Il fornitore è Claude dal connettore, Claude Haiku oppure un server compatibile con OpenAI.', 'xb' ) );
		}
		$url     = untrailingslashit( trim( $url ) );
		$problem = self::url_problem( $url );
		if ( null !== $problem ) {
			/* translators: %s: what is wrong with the address */
			throw new \InvalidArgumentException( sprintf( __( 'Indirizzo del server: %s.', 'xb' ), $problem ) );
		}
		$model = trim( $model );
		if ( 1 !== preg_match( self::MODEL_PATTERN, $model ) ) {
			throw new \InvalidArgumentException( __( 'Il nome del modello ha lettere, cifre e . _ : / - (al massimo 80).', 'xb' ) );
		}
		$current = self::stored();
		$next    = array(
			'provider' => $provider,
			'url'      => $url,
			'model'    => $model,
		);
		foreach ( array(
			self::HAIKU      => trim( $haiku_key ),
			self::COMPATIBLE => trim( $compatible_key ),
		) as $name => $key ) {
			$field = 'key_' . $name;
			if ( '' !== $key ) {
				if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
					throw new \InvalidArgumentException( __( 'La chiave non è valida: da 8 a 400 caratteri, senza spazi.', 'xb' ) );
				}
				if ( ! Credentials::salts_in_config() ) {
					throw new \InvalidArgumentException( __( 'Le chiavi segrete di wp-config.php (AUTH_KEY, AUTH_SALT) mancano o sono quelle di esempio: senza, la chiave cifrata sarebbe leggibile da chi ha il database. Definiscile, oppure metti la chiave di Anthropic in XB_ANTHROPIC_API_KEY.', 'xb' ) );
				}
				$next[ $field ] = Credentials::encrypt_with( $key, self::KEY_INFO );
			} elseif ( ! in_array( $name, $forget, true ) && is_string( $current[ $field ] ?? null ) && '' !== $current[ $field ] ) {
				$next[ $field ] = $current[ $field ];
			}
		}
		update_option( self::OPTION, $next, false );
	}

	/**
	 * Sends one batch to the provider and returns the model's text. The messages never carry the key.
	 *
	 * @param array<string, mixed> $schema    The same for every batch (the Claude API).
	 * @param array<string, mixed> $id_schema With the batch's ids as an enum (a local server's grammar).
	 * @throws \RuntimeException With why the batch gave nothing.
	 */
	public static function ask( string $system, string $user, array $schema, array $id_schema ): string {
		$settings = self::settings();
		if ( self::CONNECTOR === $settings['provider'] ) {
			// Never from the server: the page asks the connector on the person's computer, and only the answer comes here.
			throw new \RuntimeException( __( 'Claude dal connettore risponde alla pagina «Importa HTML», non al server.', 'xb' ) );
		}
		return self::HAIKU === $settings['provider']
			? self::ask_anthropic( $system, $user, $schema )
			: self::ask_compatible( $settings, $system, $user, $id_schema );
	}

	/** @param array<string, mixed> $schema */
	private static function ask_anthropic( string $system, string $user, array $schema ): string {
		$key = self::key( self::HAIKU );
		if ( '' === $key ) {
			throw new \RuntimeException( (string) self::not_ready() );
		}
		$response = wp_remote_post(
			self::ANTHROPIC_URL,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::RESPONSE_MAX,
				'headers'             => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
				),
				'body'                => (string) wp_json_encode(
					array(
						'model'         => self::HAIKU_MODEL,
						'max_tokens'    => 4096,
						'system'        => $system,
						'messages'      => array(
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
						'output_config' => array(
							'effort' => 'low',
							'format' => array(
								'type'   => 'json_schema',
								'schema' => $schema,
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( self::timed_out( $response ) ? 'tempo scaduto' : 'Anthropic non si raggiunge' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			throw new \RuntimeException( 'la chiave API di Anthropic non è valida' );
		}
		if ( 429 === $status ) {
			throw new \RuntimeException( 'Anthropic chiede di aspettare (429)' );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException( sprintf( 'Anthropic risponde %d', $status ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$stop = is_array( $body ) ? ( $body['stop_reason'] ?? null ) : null;
		if ( 'refusal' === $stop ) {
			throw new \RuntimeException( 'il modello ha rifiutato il lotto' );
		}
		if ( 'max_tokens' === $stop ) {
			throw new \RuntimeException( 'la risposta del modello è stata tagliata' );
		}
		$text = '';
		foreach ( is_array( $body ) && is_array( $body['content'] ?? null ) ? $body['content'] : array() as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? null ) && is_string( $block['text'] ?? null ) ) {
				$text .= $block['text'];
			}
		}
		return $text;
	}

	/**
	 * @param array{provider: string, url: string, model: string} $settings
	 * @param array<string, mixed>                                $id_schema
	 */
	private static function ask_compatible( array $settings, string $system, string $user, array $id_schema ): string {
		$key      = self::key( self::COMPATIBLE );
		$host     = (string) wp_parse_url( $settings['url'], PHP_URL_HOST ) . ( null !== wp_parse_url( $settings['url'], PHP_URL_PORT ) ? ':' . (string) wp_parse_url( $settings['url'], PHP_URL_PORT ) : '' );
		$endpoint = (string) preg_replace( '~/v1$~D', '', untrailingslashit( $settings['url'] ) ) . '/v1/chat/completions';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::RESPONSE_MAX,
				'headers'             => array( 'content-type' => 'application/json' ) + ( '' !== $key ? array( 'authorization' => 'Bearer ' . $key ) : array() ),
				'body'                => (string) wp_json_encode(
					array(
						'model'               => $settings['model'],
						'messages'            => array(
							array(
								'role'    => 'system',
								'content' => $system,
							),
							array(
								'role'    => 'user',
								'content' => $user,
							),
						),
						'temperature'         => 0,
						'max_tokens'          => 2048,
						'response_format'     => array(
							'type'        => 'json_schema',
							'json_schema' => array(
								'name'   => 'scelte',
								'strict' => true,
								'schema' => $id_schema,
							),
						),
						'chat_template_kwargs' => array( 'enable_thinking' => false ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* The address is the administrator's own setting: saying it helps, it holds no secret. */
			throw new \RuntimeException( self::timed_out( $response ) ? 'tempo scaduto' : sprintf( 'il modello su %s non risponde: è acceso?', $host ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			throw new \RuntimeException( sprintf( 'il modello su %s rifiuta la chiave (401)', $host ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new \RuntimeException( sprintf( 'il server del modello risponde %d', $status ) );
		}
		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$content = is_array( $body ) ? ( $body['choices'][0]['message']['content'] ?? null ) : null;
		if ( ! is_string( $content ) ) {
			throw new \RuntimeException( 'il server del modello non ha dato un testo' );
		}
		return $content;
	}

	private static function timed_out( \WP_Error $error ): bool {
		return 1 === preg_match( '~timed out|timeout~i', $error->get_error_message() );
	}
}
