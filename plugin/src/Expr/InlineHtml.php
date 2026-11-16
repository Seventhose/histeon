<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Expr;

/**
 * What `value.inline()` gives: a text with its inline formats, already reduced to them, so the renderer prints it as
 * it is where any other value is escaped. Only this class can do that, and only through the allowlist below.
 */
final class InlineHtml implements \Stringable {

	private const ALLOWED = array(
		'a'      => array( 'href' => true ),
		'br'     => array(),
		'code'   => array(),
		'em'     => array(),
		's'      => array(),
		'strong' => array(),
		'sub'    => array(),
		'sup'    => array(),
		'u'      => array(),
	);

	private function __construct( private readonly string $html ) {}

	public static function of( string $text ): self {
		return new self( function_exists( 'wp_kses' ) ? wp_kses( $text, self::ALLOWED, array( 'http', 'https', 'mailto', 'tel' ) ) : htmlspecialchars( strip_tags( $text ), ENT_NOQUOTES ) );
	}

	public function __toString(): string {
		return $this->html;
	}
}
