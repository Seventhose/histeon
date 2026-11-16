<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Export\Core\Forms;

use XB\Forms\Forms;

/** The form plugins Histeon's forms can go to (doc 25 §11), and the messages Histeon kept. */
final class Adapters {

	/** @return array<string, Adapter> By id. */
	public static function all(): array {
		$out = array();
		foreach ( array( new FluentForms(), new ContactForm7(), new Forminator(), new JetFormBuilder(), new NinjaForms(), new SureForms() ) as $adapter ) {
			$out[ $adapter->id() ] = $adapter;
		}
		return $out;
	}

	public static function get( string $id ): ?Adapter {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * The messages Histeon received for one form (its private «Messaggi dai form»), oldest first: each one's fields
	 * as the form sent them, and its date.
	 *
	 * @return list<array{fields: array<string, string>, date: string}>
	 */
	public static function messages_of( string $form ): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => Forms::POST_TYPE, 'post_status' => 'private', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'ASC' ) ) as $post ) {
			// The title is «<form> — <date>».
			if ( strtok( $post->post_title, ' ' ) !== $form ) {
				continue;
			}
			$fields = array();
			foreach ( explode( "\n", html_entity_decode( $post->post_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $line ) {
				$at = strpos( $line, ': ' );
				if ( false !== $at && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', substr( $line, 0, $at ) ) ) {
					$fields[ substr( $line, 0, $at ) ] = substr( $line, $at + 2 );
				}
			}
			$out[] = array(
				'fields' => $fields,
				'date'   => $post->post_date,
			);
		}
		return $out;
	}
}
