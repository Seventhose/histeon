<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Import;

use XB\Admin\CmsScreen;
use XB\Rest\Controller;

/**
 * `POST /xb/v1/import/scelte` (doc 27 of Histeon Publish, G4): «Importa HTML» sends the choices its conversion met
 * (at most 200, 64 kB) and gets back the names a model proposes, already checked with Publish's rules (Choices). Who
 * may convert a site may ask (CmsScreen::can_import(), with the REST nonce of the cookie). The provider and its key
 * are the site's (ChoicesProvider); the page never reaches the provider, never sees the key.
 *
 * The answer changes nothing: the page tries the names with the converter (a name already taken is left out), shows
 * them in a table «prima → dopo», and converts again only with the rows a person applies. Alternative texts come back
 * apart, in `suggestions`, to be accepted one by one: a text model does not see the image.
 *
 * `POST /xb/v1/import/scelte/risposta`, «Claude dal connettore»: a PHP request cannot wait the minutes a person takes
 * to ask Claude, so the page itself waits, linked to the Histeon Connector on its computer, and the agent answers it.
 * The page sends here the choices with the agent's answer, by groups of Choices::BATCH_SIZE, and gets the same sorting
 * as a provider's reply (Choices::parse_reply, Choices::sort_answers): the answer is checked with the shared rules.
 */
final class ChoicesRest {

	public const ROUTE      = '/import/scelte';
	public const ANSWER     = '/import/scelte/risposta';
	public const MAX        = 200;
	public const BODY_BYTES = 65536;

	public static function register(): void {
		register_rest_route(
			Controller::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'ask' ),
				'permission_callback' => static fn (): bool => CmsScreen::can_import(),
			)
		);
		register_rest_route(
			Controller::NAMESPACE,
			self::ANSWER,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'check' ),
				'permission_callback' => static fn (): bool => CmsScreen::can_import(),
			)
		);
	}

	public static function ask( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( $request->get_body() ) > self::BODY_BYTES ) {
			return new \WP_Error( 'xb_import_choices_too_big', __( 'La richiesta supera 64 kB: manda le scelte a lotti.', 'xb' ), array( 'status' => 413 ) );
		}
		$params = $request->get_json_params();
		try {
			$choices = Choices::read_choices( $params['choices'] ?? null, self::MAX );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'xb_import_choices_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		$missing = ChoicesProvider::not_ready();
		if ( null !== $missing ) {
			return new \WP_Error( 'xb_import_choices_key', $missing, array( 'status' => 409 ) );
		}
		$settings = ChoicesProvider::settings();
		if ( ChoicesProvider::CONNECTOR === $settings['provider'] ) {
			return new \WP_Error( 'xb_import_choices_connector', __( 'Con Claude dal connettore la pagina chiede a Claude sul tuo computer: ricarica «Importa HTML».', 'xb' ), array( 'status' => 409 ) );
		}
		$started  = microtime( true );
		$result   = self::ask_batches( $choices, array( ChoicesProvider::class, 'ask' ) );
		return new \WP_REST_Response(
			array(
				'provider'    => $settings['provider'],
				'model'       => ChoicesProvider::model_name( $settings ),
				'ms'          => (int) round( ( microtime( true ) - $started ) * 1000 ),
				// An id is a JSON object key: an empty map must stay an object for the page.
				'apply'       => (object) $result['apply'],
				'suggestions' => (object) $result['suggestions'],
			) + $result
		);
	}

	/**
	 * The agent's answer to the choices the page asked, sorted with the shared rules: `{apply, suggestions, rejected}`,
	 * as for one batch of a provider. 413 over 64 kB, 400 for choices or an answer that do not fit.
	 */
	public static function check( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( $request->get_body() ) > self::BODY_BYTES ) {
			return new \WP_Error( 'xb_import_choices_too_big', __( 'La richiesta supera 64 kB: manda le scelte a lotti.', 'xb' ), array( 'status' => 413 ) );
		}
		$params = $request->get_json_params();
		try {
			$choices = Choices::read_choices( $params['choices'] ?? null, self::MAX );
			$text    = $params['scelte'] ?? null;
			if ( ! is_string( $text ) ) {
				throw new \InvalidArgumentException( 'Manca la risposta: il testo JSON {"scelte":[…]}.' );
			}
			$answers = Choices::parse_reply( $text );
			if ( count( $answers ) > self::MAX ) {
				throw new \InvalidArgumentException( sprintf( 'Al massimo %d risposte.', self::MAX ) );
			}
		} catch ( \InvalidArgumentException | \UnexpectedValueException $e ) {
			return new \WP_Error( 'xb_import_choices_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		$sorted = Choices::sort_answers( $choices, $answers );
		return new \WP_REST_Response(
			array(
				'apply'       => (object) $sorted['apply'],
				'suggestions' => (object) $sorted['suggestions'],
				'rejected'    => array_slice( $sorted['rejected'], 0, 200 ),
			)
		);
	}

	/**
	 * The choices asked in batches of Choices::BATCH_SIZE, one request each, no retry; a batch that fails leaves its
	 * choices at their defaults, and the others still count. Each reply is sorted against its own batch.
	 *
	 * @param list<array{id: string, kind: string, value: string, context: string}> $choices
	 * @param callable(string, string, array<string, mixed>, array<string, mixed>): string $ask
	 * @return array{apply: array<string, string>, suggestions: array<string, string>, rejected: list<array{id: string, motivo: string}>, batches: list<array{size: int, ok: bool, error?: string, ms: int}>, skipped: int}
	 */
	public static function ask_batches( array $choices, callable $ask ): array {
		$batches = array_slice( array_chunk( $choices, Choices::BATCH_SIZE ), 0, Choices::MAX_BATCHES );
		$asked   = array_sum( array_map( 'count', $batches ) );
		$out     = array(
			'apply'       => array(),
			'suggestions' => array(),
			'rejected'    => array(),
			'batches'     => array(),
			'skipped'     => count( $choices ) - $asked,
		);
		$schema  = Choices::response_schema();
		foreach ( $batches as $batch ) {
			$started = microtime( true );
			try {
				$text   = $ask( Choices::SYSTEM_PROMPT, Choices::user_message( $batch ), $schema, Choices::response_schema( array_column( $batch, 'id' ) ) );
				$sorted = Choices::sort_answers( $batch, Choices::parse_reply( $text ) );
				$out['apply']       = array_merge( $out['apply'], $sorted['apply'] );
				$out['suggestions'] = array_merge( $out['suggestions'], $sorted['suggestions'] );
				$out['rejected']    = array_merge( $out['rejected'], $sorted['rejected'] );
				$out['batches'][]   = array(
					'size' => count( $batch ),
					'ok'   => true,
					'ms'   => (int) round( ( microtime( true ) - $started ) * 1000 ),
				);
			} catch ( \RuntimeException $e ) {
				$out['batches'][] = array(
					'size'  => count( $batch ),
					'ok'    => false,
					'error' => Choices::js_slice( $e->getMessage(), 200 ),
					'ms'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
				);
			}
		}
		$out['rejected'] = array_slice( $out['rejected'], 0, 200 );
		return $out;
	}
}
