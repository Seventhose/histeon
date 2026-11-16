<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Versioning;

use XB\Framework\Frameworks;
use XB\Plugin;
use XB\Render\ComponentRegistry;
use XB\Rest\Controller;
use XB\Scripts\Scripts;
use XB\Spec;
use XB\Styles\StyleRegistry;

/**
 * Versioned documents and their payloads — docs/spec/versioning.md §2.
 *
 *   post:{id}  {content, script, ref?, schema?, styles?}  (pages, posts, components; styles: the page's own look)
 *   styles     {styles, globals}
 *   settings   {framework}
 *
 * read() returns the live (published) state; apply() writes a payload to the live site.
 */
final class Documents {

	public const STYLES   = 'styles';
	public const SETTINGS = 'settings';
	/** Not a document: the media changes from Histeon CMS waiting for a release, as one item of the release list (M6). */
	public const MEDIA = 'media';

	/**
	 * Not a document either: the records, terms and products a development send from Histeon CMS queued for the next
	 * release (ContentProfileAdapter::queue), as one item of the release list.
	 */
	public const CONTENTS = 'contents';

	/** How many media changes wait for a release (PageProfileAdapter::pending_media_count, through a filter). */
	public static function waiting_media(): int {
		return max( 0, (int) apply_filters( 'xb_release_waiting_media', 0 ) );
	}

	private static function media_label(): string {
		$count = self::waiting_media();
		/* translators: %d: number of media */
		return sprintf( _n( '%d media dal CMS in attesa (va online con qualsiasi release)', '%d media dal CMS in attesa (vanno online con qualsiasi release)', $count, 'xb' ), $count );
	}

	/** How many records, terms and products wait for a release (ContentProfileAdapter::queued_count, through a filter). */
	public static function waiting_contents(): int {
		return max( 0, (int) apply_filters( 'xb_release_waiting_contents', 0 ) );
	}

	private static function contents_label(): string {
		$count = self::waiting_contents();
		/* translators: %d: number of records, terms and products */
		return sprintf( _n( '%d contenuto dal CMS in attesa (va online con qualsiasi release)', '%d contenuti dal CMS in attesa (vanno online con qualsiasi release)', $count, 'xb' ), $count );
	}

	/** True while a payload is being applied, so the content hook does not record it as a client edit. */
	private static bool $applying = false;

	public static function applying(): bool {
		return self::$applying;
	}

	public static function for_post( int $post_id ): string {
		return 'post:' . $post_id;
	}

	public static function post_id( string $doc ): ?int {
		return 1 === preg_match( '/^post:(\d+)$/', $doc, $m ) ? (int) $m[1] : null;
	}

	/** Post types that can be versioned: the builder's documents plus FSE templates (ADR 0008, I8). */
	public const POST_TYPES = array( 'page', 'post', 'wp_block', 'wp_template', 'wp_template_part' );

	public static function valid( string $doc ): bool {
		if ( self::STYLES === $doc || self::SETTINGS === $doc ) {
			return true;
		}
		$id   = self::post_id( $doc );
		$post = null === $id ? null : get_post( $id );
		return $post instanceof \WP_Post && in_array( $post->post_type, self::POST_TYPES, true );
	}

	public static function label( string $doc ): string {
		return match ( true ) {
			self::STYLES === $doc => __( 'Stili', 'xb' ),
			self::SETTINGS === $doc => __( 'Impostazioni', 'xb' ),
			self::MEDIA === $doc => self::media_label(),
			self::CONTENTS === $doc => self::contents_label(),
			default => (string) get_the_title( (int) self::post_id( $doc ) ),
		};
	}

	/**
	 * CSS written by authors without unfiltered_html: no @import and no url() to other sites, so styles
	 * cannot load remote resources (tracking beacons, attribute-selector exfiltration from admin pages), and no
	 * @namespace, which printed first in a page would leave the rules after it without effect on HTML elements.
	 * The one data:
	 * URL kept is an inert SVG drawing in url() (V5.8, Security\InlineSvg): it loads nothing either. url() and
	 * image-set() are read as the browser reads them, left open at the end of the sheet too (restricted_urls()).
	 * A name written with an escape (`\75rl(`, `image-s\65t(`, `@\69mport`) is read written out (css_letters()), and
	 * what comes back was read again after every removal, until nothing more goes (threat model T31).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function restricted_styles( array $payload ): array {
		// What stands for the address of a linked media when the CSS is judged as printed: one of this site, as the
		// media library gives it.
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return self::restricted_styles_with( $payload, ( '' !== $home ? 'https://' . $home : '' ) . '/wp-content/uploads/xb-media' );
	}

	/**
	 * restricted_styles() with the address that stands for a linked media handed in: receives the payload and that
	 * address, gives back the payload filtered. Apart only so that a test can see the judgement of the CSS as printed
	 * at work, with an address that is not of this site (SanitizerTest).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function restricted_styles_with( array $payload, string $media ): array {
		$home      = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$allowed   = static function ( string $url ) use ( $home ): bool {
			// As the browser's URL parser reads it: leading and trailing controls and spaces trimmed, tabs and
			// newlines dropped anywhere, a backslash read as a slash ("/\host" and "\\host" are another site).
			$url = str_replace( '\\', '/', (string) preg_replace( '/[\t\n\r]/', '', trim( $url, "\x00..\x20" ) ) );
			// A second line behind next_function(): no address of a real sheet holds a url(, an image-set( or a src(;
			// one that does is the text of a misread string, with a resource hidden in it (the reading that searches
			// the whole text relies on this).
			if ( 1 === preg_match( '/(?:url|image-set|src)' . self::SPACE . '*\(/', strtolower( $url ) ) ) {
				return false;
			}
			if ( 1 === preg_match( '/^([A-Za-z][A-Za-z0-9+.\-]*):(.*)$/s', $url, $m ) ) {
				if ( ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
					return false;
				}
				// http: and https: take their host even without slashes ("https:host/x"), after any user info.
				$authority = (string) preg_replace( '#[/?\#].*$#s', '', ltrim( $m[2], '/' ) );
				$host      = strtolower( (string) preg_replace( '/:[0-9]*$/', '', substr( (string) strrchr( '@' . $authority, '@' ), 1 ) ) );
				return '' !== $home && $host === $home;
			}
			return ! str_starts_with( $url, '//' );
		};
		// $source is the CSS as written: a drawing (below) stays only when its whole url() is written there as it is read.
		$resources = static function ( string $css, string $source ) use ( $allowed ): string {
			$css = (string) preg_replace( '/@[iI][mM][pP][oO][rR][tT]' . self::NAME_END . '[^;]*;?/', '', $css );
			// @namespace loads nothing, and goes the same way: a rule with no prefix is valid only before every other
			// rule, and there it leaves the rules after it without effect on HTML elements; the page prints only the
			// rules it uses, so a rule that comes late in a sheet can be the first one printed (threat model T31). With a
			// prefix or without, from its `@` to the next `;`, that `;` included, or to the end of the sheet, wherever
			// it is written: in a comment or a string too, as for @import, so that no other reading of the sheet finds
			// one. `@namespaces` is another rule and stays.
			$css = (string) preg_replace( '/@[nN][aA][mM][eE][sS][pP][aA][cC][eE]' . self::NAME_END . '[^;]*;?/', '', $css );
			// Two readings, one after the other, and what either takes out stays out: the one of the CSS tokenizer
			// (strings and comments skipped, a name only where a name starts), and the old one that finds every url( and
			// image-set( in the whole text, strings included, whose misread «addresses» the second line above refuses.
			// So no reading that goes wrong can hide a url() that the other judged (threat model T31).
			foreach ( array( false, true ) as $anywhere ) {
				// image-set() also takes plain strings as URLs.
				$css = self::restricted_image_sets( $css, $allowed, $anywhere );
				// V5.8: an inert SVG drawing written in the sheet, `url("data:image/svg+xml;charset=utf-8,…")`, loads
				// nothing (T31, InlineSvg). Only in url(): image-set() and @import keep their rule; only written plainly (no
				// escape or comment that the browser would read away), closed; at most InlineSvg::MAX_PER_SHEET per sheet.
				$drawings = 0;
				$css      = self::restricted_urls(
					$css,
					static function ( string $whole, string $url, string $state ) use ( $allowed, $source, &$drawings ): string {
						// Left open at the end it never stays open: whatever is printed after this CSS would go on as its
						// address (a second global sheet that starts with «https://other.example/x)»). restricted_urls()
						// closes it where it opens when its address so far is one of this site.
						if ( 'bad' !== $state && $allowed( $url ) ) {
							return $whole;
						}
						if ( 'closed' === $state && ! str_contains( $whole, '\\' ) && str_contains( $source, $whole ) && \XB\Security\InlineSvg::inert_data_url( $url ) && ++$drawings <= \XB\Security\InlineSvg::MAX_PER_SHEET ) {
							return $whole;
						}
						return 'url()';
					},
					$anywhere
				);
			}
			return $css;
		};
		$filter = static function ( string $css ) use ( $resources ): string {
			// A name is a name for the browser however its letters are written: `\75rl(` is url(, `image-s\65t(` is
			// image-set(, `@\69mport` is @import. Every escape that stands for a letter is written out first, so the two
			// readings find each name where it is; the other escapes stay, and each address is judged with its own decoded.
			// Nor does CSS end in an escape: the new line printed after it would end the escape (`\75` and, in the next
			// sheet, `rl(`; `image\2d` and `set(`) or carry a string on into the next sheet. Such an end is cut: from the
			// CSS as written and, after every reading, from what the reading left (what it takes out can leave an escape
			// last: `image\2d@import x`).
			$whole = self::css_closed_end( $css );
			$start = self::css_letters( $whole );
			$text  = $start;
			// What a reading takes out can put a name together (`@imp@import x;ort`, `\5c 75rl(` after a comment): the
			// text is read again until nothing more goes, and nothing that was not read again comes back.
			for ( $round = 0; $round < 5; ++$round ) {
				$next = self::css_closed_end( $resources( $text, $css ) );
				if ( $next === $text ) {
					// Without comments too: the compiler drops them, which joins "u/**/rl(" into "url(". CSS whose
					// resources only show up that way is filtered in that form; any other CSS keeps its comments.
					$plain = self::css_letters( self::css_without_comments( $text ) );
					$next  = self::css_closed_end( $resources( $plain, $css ) );
					if ( $next === $plain ) {
						if ( $text === $start ) {
							// Nothing to take out: the CSS comes back as it was written, byte for byte.
							return $whole;
						}
						// CSS that held a resource comes back changed, and is held to more: with every escape decoded, once
						// and again, it must still hold none (`\5c 75rl(` is no url() for the browser, and one decoding
						// away from it). Otherwise no CSS at all.
						for ( $depth = 0, $decoded = $text; $depth < 8; ++$depth ) {
							$before  = $decoded;
							$decoded = self::css_unescape( $decoded );
							if ( $decoded === $before ) {
								return $text;
							}
							$view = self::css_letters( $decoded );
							if ( $resources( $view, $css ) !== $view ) {
								return '';
							}
						}
						return '';
					}
				}
				$text = self::css_closed_end( self::css_letters( $next ) );
			}
			return '';
		};
		foreach ( array( 'styles', 'globals' ) as $key ) {
			$list = is_array( $payload[ $key ] ?? null ) ? $payload[ $key ] : array();
			foreach ( $list as $i => $entry ) {
				if ( is_array( $entry ) && is_string( $entry['css'] ?? null ) ) {
					// Text that is not valid UTF-8 is not judged at all, and the entry keeps no CSS: what writes or prints
					// the document after this filter may read those bytes otherwise than it did (a JSON encoder that
					// «repairs» a string drops or exchanges them, and the two halves around a dropped byte make a name:
					// `@imp<FF>ort`, `u<FF>rl(`), so no judgement of them would hold. The same for the other texts that go
					// into the CSS judged and printed: the selector and the linked media (threat model T31, P2). What a
					// repair would do to the valid text of a document that holds such bytes elsewhere is not for this
					// filter to know: Store::commit() repairs no version that is not trusted.
					if ( ! self::utf8( array( $entry['css'], $entry['selector'] ?? null, $entry['assets'] ?? null ) ) ) {
						$list[ $i ]['css'] = '';
						continue;
					}
					$css = $filter( $entry['css'] );
					// The media linked to a rule (`assets`) are written into its CSS after this filter, each time the page
					// is printed: CssAssets::rewrite() puts `url("<address of the media><?… or #… of the reference>")`, or
					// `url()` when the media is gone. It writes only into a url() that this filter reads as one closed
					// address in the same place (closed_urls()), and the address and the `?…` or `#…` go in with nothing
					// that CSS reads: which url() are exchanged does not depend on the media found, and what comes after
					// one of them is read in the same state after `url("…")` as after `url()`. Not so the declaration
					// that holds it, when a reader is inside a string there (the browser does not end every string where
					// this filter does, and CssCompiler trims a new line that ended one: V1 of the fifth review): `url()`
					// stays a piece of that string, the first quote of `url("…")` ends it and the address is read as
					// CSS, inert as it is written.
					// As one more defence the CSS is judged printed too: with every linked media found (an address of this
					// site stands for the one of the media library) and with none found, since CssCompiler may read the
					// address as written otherwise than the one printed (a brace or a `;` in an address without quotes).
					// A form that would still lose something leaves the rule with no CSS at all. The forms with some media
					// found and some gone are not judged one by one: they differ from these two only in which of those
					// url() holds a quoted address and which is empty and, where a reader is inside a string, in that
					// inert address being read outside it (threat model T31, T1 of the third review, Q1 of the fourth,
					// V1 of the fifth).
					$forms = array( $css );
					if ( '' !== $css && is_array( $entry['assets'] ?? null ) && array() !== $entry['assets'] ) {
						$assets = \XB\Styles\StyleRegistry::asset_mappings( $entry['assets'] );
						foreach ( array() === $assets ? array() : array( $media, null ) as $address ) {
							$form = \XB\Styles\CssAssets::rewrite( $css, $assets, static fn (): ?string => $address );
							if ( in_array( $form, $forms, true ) ) {
								continue;
							}
							if ( $filter( $form ) !== $form ) {
								$css = '';
								break;
							}
							$forms[] = $form;
						}
					}
					// A style is printed through CssCompiler, which reads strings and comments its own way (a string does
					// not end at a new line there, a comment it drops can join "u" and "rl("): the text it prints is judged
					// too, and a style whose printed text would still lose something keeps no CSS at all. With its own
					// selector as well: the selector is printed inside that text, twice with a BEM part, and a string left
					// open before it takes it into an address (`[x='@other.example/']`). Globals are printed as they are
					// written.
					if ( 'styles' === $key && '' !== $css ) {
						$selectors = array( '.xb-restricted' );
						if ( is_string( $entry['selector'] ?? null ) && '' !== trim( $entry['selector'] ) ) {
							$selectors[] = $entry['selector'];
						}
						foreach ( $forms as $form ) {
							foreach ( $selectors as $selector ) {
								$printed = \XB\Styles\CssCompiler::compile( $selector, $form );
								if ( $filter( $printed ) !== $printed ) {
									$css = '';
									break 2;
								}
							}
						}
					}
					// A global sheet is printed through CssCompiler::sanitize(), which writes `</` as `\3C /`: after a
					// backslash that makes another address (`"/\<//other.example/x"` becomes `/\3C //other.example/x`, and
					// the browser reads a backslash in an address as a slash). The sheet is judged in that form too.
					if ( 'globals' === $key && '' !== $css ) {
						foreach ( $forms as $form ) {
							$printed = \XB\Styles\CssCompiler::sanitize( $form );
							if ( $printed !== $form && $filter( $printed ) !== $printed ) {
								$css = '';
								break;
							}
						}
					}
					$list[ $i ]['css'] = $css;
				}
			}
			$payload[ $key ] = $list;
		}
		return $payload;
	}

	/** Whether every string in $value is valid UTF-8, at any depth, the keys of its arrays too. */
	private static function utf8( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return mb_check_encoding( $value, 'UTF-8' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( ( is_string( $key ) && ! mb_check_encoding( $key, 'UTF-8' ) ) || ! self::utf8( $item ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Every url( of the sheet read as the CSS tokenizer reads it (CSS Syntax §4.3.4-§4.3.6), handed to $decide with its
	 * whole text, its address and its state, and replaced with what $decide gives back:
	 * - `closed`: one address, quoted or not, and the `)` that closes it;
	 * - `open`: the same left open at the end of the sheet, which the browser closes there and loads (D4): a string
	 *   runs to its quote or to the end, an address without quotes to the end, through `;` and braces;
	 * - `bad`: what loads nothing in a browser (a quote, `(` or a control in an address without quotes, a string
	 *   broken by a new line, anything after the address), up to where the browser stops reading it.
	 * Strings and escapes are read whole, so `url("/a\\")")` is one address. CSS that $decide keeps comes back byte for byte.
	 *
	 * @param callable(string, string, string, int): string $decide Whole text, address, state, and where the whole text
	 *                                                           starts in $css: what to write instead.
	 */
	private static function restricted_urls( string $css, callable $decide, bool $anywhere = false ): string {
		$out    = '';
		$offset = 0;
		$length = strlen( $css );
		$space  = " \t\n\r\f";
		while ( $offset < $length && null !== ( $hit = self::next_function( $css, $offset, false, $anywhere ) ) ) {
			[ $start, $i ] = $hit;
			$opened        = $i;
			$i            += strspn( $css, $space, $i );
			$url   = '';
			$state = 'open';
			$quote = $css[ $i ] ?? '';
			if ( '"' === $quote || "'" === $quote ) {
				// A string ends at the same quote not escaped, at a new line (a broken string) or where the sheet ends.
				for ( ++$i; $i < $length && $css[ $i ] !== $quote; ++$i ) {
					if ( str_contains( "\n\r\f", $css[ $i ] ) ) {
						$state = 'bad';
						break;
					}
					if ( '\\' === $css[ $i ] && $i + 1 < $length ) {
						$url .= $css[ $i++ ];
					}
					$url .= $css[ $i ];
				}
				if ( 'bad' !== $state ) {
					$i += $i < $length ? 1 : 0;
					$i += strspn( $css, $space, $i );
					if ( $i < $length ) {
						$state = ')' === $css[ $i ] ? 'closed' : 'bad';
						$i    += 'closed' === $state ? 1 : 0;
					}
				}
			} else {
				for ( ; $i < $length; ++$i ) {
					$char = $css[ $i ];
					if ( ')' === $char ) {
						$state = 'closed';
						++$i;
						break;
					}
					if ( str_contains( $space, $char ) ) {
						$i += strspn( $css, $space, $i );
						if ( $i < $length ) {
							$state = ')' === $css[ $i ] ? 'closed' : 'bad';
							$i    += 'closed' === $state ? 1 : 0;
						}
						break;
					}
					// Whitespace was read above: any other control is a bad url, and so is an escape before a new line.
					$escape_broken = '\\' === $char && ( $i + 1 >= $length || str_contains( "\n\r\f", $css[ $i + 1 ] ) );
					if ( '"' === $char || "'" === $char || '(' === $char || ord( $char ) < 0x20 || "\x7F" === $char || $escape_broken ) {
						$state = 'bad';
						break;
					}
					if ( '\\' === $char && $i + 1 < $length ) {
						// A hex escape takes up to six hex digits and one white space after them ("\2c b" is ",b").
						$hex = strspn( $css, '0123456789abcdefABCDEF', $i + 1, 6 );
						if ( $hex > 0 ) {
							$end  = $i + 1 + $hex;
							$end += "\r\n" === substr( $css, $end, 2 ) ? 2 : ( str_contains( $space, $css[ $end ] ?? 'x' ) ? 1 : 0 );
							$url .= substr( $css, $i, $end - $i );
							$i    = $end - 1;
							continue;
						}
						$url .= $css[ $i++ ];
					}
					$url .= $css[ $i ];
				}
			}
			if ( 'bad' === $state && ( '"' !== $quote && "'" !== $quote ) ) {
				// A bad url without quotes: the browser reads on to the `)` (escapes included) or to the end of the sheet.
				for ( ; $i < $length && ')' !== $css[ $i ]; ++$i ) {
					$i += '\\' === $css[ $i ] ? 1 : 0;
				}
				$i = min( $i + 1, $length );
			}
			// The address as the URL parser receives it: the CSS escapes in it decoded ("\\)" is a ")" of the address).
			$whole   = substr( $css, $start, $i - $start );
			$decided = $decide( $whole, self::css_unescape( $url ), $state, $start );
			if ( 'closed' !== $state && ( 'bad' === $state || $decided === $whole ) ) {
				if ( self::names_no_address( $whole ) ) {
					// Left open, or read by the browser as no url at all, and with nothing like an address in it: closed
					// right where it opens, `url()`, and what follows is read on as CSS. Cutting it away would take with it
					// the quote, the comment closer or the brace that the rest of the sheet needs (`content:"url("`,
					// `/* url( to do */`): the second reading finds a url( in strings and comments too.
					$decided = substr( $css, $start, $opened - $start ) . ')';
					$i       = $opened;
				} else {
					$decided = 'url()';
				}
			}
			$out   .= substr( $css, $offset, $start - $offset ) . $decided;
			$offset = $i;
		}
		return $out . substr( $css, $offset );
	}

	/**
	 * The url() of a sheet that this filter reads as one closed address: receives the CSS as it is stored, gives back
	 * where each one starts and ends in it (start => end, in bytes). CssAssets::rewrite() writes the address of a linked
	 * media only into these, so the writer of the media and the filter cannot disagree about where a url() is (threat
	 * model T31, T1). Read with the tokenizer reading of restricted_urls(). Whatever is not named here stays as written,
	 * which is always safe; so every doubt names less:
	 * - a letter written with an escape anywhere in the sheet (css_letters() would change it): the filter and the browser
	 *   read that text with the letter written out, and the places of the text as written are not theirs. Nothing is named;
	 * - a url( that is not closed (left open at the end, or one the browser reads as no url at all): the filter reads on
	 *   from just after its parenthesis, the browser from where it stops discarding, and from there on the two need not
	 *   meet again. Nothing is named from that url( on, itself included;
	 * - a `url(` written right after an escape, an `@` or a `#` (`\!url(`, `\2d url(`, `@url(`, `#url(`): next_function()
	 *   reads a url( there, the browser reads a longer name, an at-keyword or a hash and then a plain parenthesis, where
	 *   strings and comments count. Nothing is named from there on either.
	 * A sheet that restricted_styles() gave back holds no url( of the second kind: there the filter would have changed it.
	 *
	 * @return array<int, int>
	 */
	public static function closed_urls( string $css ): array {
		if ( ! str_contains( $css, '(' ) || self::css_letters( $css ) !== $css ) {
			return array();
		}
		$closed = array();
		$sound  = true;
		self::restricted_urls(
			$css,
			static function ( string $whole, string $url, string $state, int $start ) use ( $css, &$closed, &$sound ): string {
				unset( $url );
				// The longest escape is a backslash, six hex digits and a CR LF: nine bytes.
				$before = substr( $css, max( 0, $start - 9 ), min( 9, $start ) );
				if ( 'closed' !== $state || 1 === preg_match( '/(?:[@#]|\\\\(?:[0-9a-fA-F]{1,6}(?:\r\n|[ \t\n\r\f])?|[^\n\r\f]))\z/', $before ) ) {
					$sound = false;
				}
				if ( $sound ) {
					$closed[ $start ] = $start + strlen( $whole );
				}
				return $whole;
			}
		);
		return $closed;
	}

	/** `image-set(` as a regular expression: its hyphen written as it is or with an escape (`\2d`, `\00002D `, `\-`). */
	private const IMAGE_SET = '[iI][mM][aA][gG][eE](?:-|\\\\0{0,4}2[dD](?:\r\n|[ \t\n\r\f])?|\\\\-)[sS][eE][tT]' . self::SPACE . '*\(';

	/** The `-webkit-` that may come before image-set(, and `src(`, as regular expressions in any case. */
	private const WEBKIT = '(?:-[wW][eE][bB][kK][iI][tT]-)?';
	private const SRC    = '[sS][rR][cC]' . self::SPACE . '*\(';

	/**
	 * White space and the end of a name as PCRE reads `\s` and `\b` when PHP has no locale set, written out, and the
	 * ASCII letters and digits. PCRE takes the case of a letter (the `i` flag) and what `\s`, `\b`, `\w` and `\d`
	 * mean from the tables of the PHP locale, and so do ctype_*() and a substr_compare() without case: in a Turkish
	 * locale `I` is not the capital of `i`, and `@IMPORT` or `IMAGE-SET(` of another site passed. Nothing in this
	 * filter uses them: each letter is written in its two cases, or the text is read through strtolower(), which
	 * changes ASCII letters alone whatever the locale (PHP 8.2 on). Threat model T31, P1 of the ninth review.
	 */
	private const SPACE    = '[ \t\n\x0B\f\r]';
	private const NAME_END = '(?![A-Za-z0-9_])';
	private const LETTERS  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
	private const ALNUM    = '0123456789' . self::LETTERS;

	/** What may be written as an option of an image-set(), besides strings: no function that stands for other text. */
	private const IMAGE_SET_OPTIONS = '/^(?:url|type|calc|min|max|clamp|(?:-webkit-|-moz-|-o-)?(?:repeating-)?(?:linear|radial|conic)-gradient)$/';

	/**
	 * image-set() takes plain strings as URLs, and so does src(): one with a string that is not an address of this site
	 * becomes `none`. Read as the CSS tokenizer reads it, to the `)` that closes it: a `;`, a brace or a parenthesis
	 * inside a string hides nothing ("https://other.example/a;b"), and neither does a `;` or a comment opener inside an
	 * address without quotes (`url(/a;b)`, `url(/a/*b)`). It becomes `none` as well when:
	 * - one of its options is a function that stands for other text (`var(--x)`, `attr()`, `env()`, a custom
	 *   function): the string would come from a custom property that is no address where it is written;
	 * - it is left open at the end with a string of another site.
	 * Left open with none, it is closed where it opens (`image-set()`): whatever is printed after this CSS would go on as
	 * its options, and the rest of the sheet is no option of it.
	 * CSS without any of these comes back byte for byte.
	 *
	 * @param callable(string): bool $allowed Whether a URL is an address of this site.
	 */
	private static function restricted_image_sets( string $css, callable $allowed, bool $anywhere = false ): string {
		$out    = '';
		$offset = 0;
		$length = strlen( $css );
		while ( $offset < $length && null !== ( $hit = self::next_function( $css, $offset, true, $anywhere ) ) ) {
			[ $start, $i ] = $hit;
			$opened        = $i;
			// One entry for each parenthesis left open: true where what is written is an option of an image-set() or src().
			$frames  = array( true );
			$keep    = true;
			$options = true;
			while ( $i < $length && array() !== $frames ) {
				$char = $css[ $i ];
				if ( '"' === $char || "'" === $char ) {
					// A string ends at the same quote not escaped, or where the sheet ends.
					$string = '';
					for ( ++$i; $i < $length && $css[ $i ] !== $char; ++$i ) {
						if ( '\\' === $css[ $i ] && $i + 1 < $length ) {
							$string .= $css[ $i++ ];
						}
						$string .= $css[ $i ];
					}
					++$i;
					$keep = $allowed( self::css_unescape( $string ) ) && $keep;
					continue;
				}
				if ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
					$end = strpos( $css, '*/', $i + 2 );
					$i   = false === $end ? $length : $end + 2;
					continue;
				}
				if ( '\\' === $char ) {
					$i += 2;
					continue;
				}
				if ( ')' === $char ) {
					array_pop( $frames );
					++$i;
					continue;
				}
				if ( '(' !== $char ) {
					++$i;
					continue;
				}
				// The name this parenthesis belongs to, if any (its letters are written out: css_letters() ran before).
				$from = $i;
				while ( $from > 0 && ( str_contains( self::ALNUM, $css[ $from - 1 ] ) || str_contains( '_-\\', $css[ $from - 1 ] ) || ord( $css[ $from - 1 ] ) >= 0x80 ) ) {
					--$from;
				}
				$name = strtolower( substr( $css, $from, $i - $from ) );
				++$i;
				if ( 'url' === $name ) {
					$address = $i + strspn( $css, " \t\n\r\f", $i );
					if ( $address >= $length || ! str_contains( "\"'", $css[ $address ] ) ) {
						// An address without quotes runs to its `)`: no string and no comment starts inside it. It is judged
						// by restricted_urls(); here it is only stepped over, or a `/*` in it would hide the options after it.
						for ( $i = $address; $i < $length && ')' !== $css[ $i ]; ++$i ) {
							$i += '\\' === $css[ $i ] ? 1 : 0;
						}
						if ( $i >= $length ) {
							$frames[] = false;
							break;
						}
						++$i;
						continue;
					}
				}
				if ( true === end( $frames ) && '' !== $name && 1 !== preg_match( self::IMAGE_SET_OPTIONS, $name ) ) {
					$options = false;
				}
				$frames[] = in_array( $name, array( 'image-set', '-webkit-image-set', 'src' ), true );
			}
			$i = min( $i, $length );
			if ( array() === $frames ) {
				$written = $keep && $options ? substr( $css, $start, $i - $start ) : 'none';
			} elseif ( $keep && self::names_no_address( substr( $css, $start, $i - $start ) ) ) {
				// Left open, with nothing like an address in it: closed right where it opens, `image-set()`, and what
				// follows is read on as CSS. What it «holds» is the rest of the sheet, not its options: cutting that away
				// would take the comment closer or the quote with it (`/* src ( to do */`, `content:"image-set("`).
				$written = substr( $css, $start, $opened - $start ) . ')';
				$i       = $opened;
			} else {
				$written = 'none';
			}
			$out   .= substr( $css, $offset, $start - $offset ) . $written;
			$offset = $i;
		}
		return $out . substr( $css, $offset );
	}

	/**
	 * The next `url(` (or, with $image_set, the next `image-set(` / `-webkit-image-set(` / `src(`) from $offset that the
	 * CSS tokenizer reads as that function (CSS Syntax §4.3): never inside a string or a comment, and only where a name
	 * starts, so `content:"url("` or `myurl(` are not one. The letters of the name are written out (css_letters() ran
	 * before); the hyphen of image-set may still be an escape (`image\2d set(`), and is read so. A string ends at its quote not escaped, at a new line (left
	 * there, as the tokenizer reconsumes it) or at the end of the sheet; a backslash takes the next byte with it.
	 * Searching the whole text instead let a string that holds `url(` turn its closing quote into an opening one, and
	 * the rest of the sheet into an «address» that hid a real url() (threat model T31).
	 *
	 * @return array{0: int, 1: int}|null The start of the name and the offset just after its `(`, or null.
	 */
	private static function next_function( string $css, int $offset, bool $image_set, bool $anywhere = false ): ?array {
		if ( $anywhere ) {
			// The second reading: every occurrence, in strings and comments too.
			if ( 1 !== preg_match( $image_set ? '/' . self::WEBKIT . self::IMAGE_SET . '|' . self::SRC . '/' : '/[uU][rR][lL]\(/', $css, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				return null;
			}
			return array( (int) $m[0][1], (int) $m[0][1] + strlen( (string) $m[0][0] ) );
		}
		$length = strlen( $css );
		$stops  = $image_set ? "/\"'\\-iIsS" : "/\"'\\uU";
		for ( $i = $offset; $i < $length; ) {
			$i += strcspn( $css, $stops, $i );
			if ( $i >= $length ) {
				return null;
			}
			$char = $css[ $i ];
			if ( '/' === $char ) {
				if ( '*' === ( $css[ $i + 1 ] ?? '' ) ) {
					$end = strpos( $css, '*/', $i + 2 );
					$i   = false === $end ? $length : $end + 2;
				} else {
					++$i;
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				for ( ++$i; $i < $length && $css[ $i ] !== $char && ! str_contains( "\n\r\f", $css[ $i ] ); ++$i ) {
					$i += '\\' === $css[ $i ] ? 1 : 0;
				}
				$i += $i < $length && $css[ $i ] === $char ? 1 : 0;
				continue;
			}
			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}
			// A name starts here only after a byte that cannot continue one (letters, digits, `_`, `-`, non-ASCII).
			$before = $i > 0 ? ord( $css[ $i - 1 ] ) : 0x20;
			$inside = $before >= 0x80 || str_contains( self::ALNUM, chr( $before ) ) || 0x5F === $before || 0x2D === $before;
			if ( ! $inside ) {
				if ( $image_set && 1 === preg_match( '/\G(?:' . self::WEBKIT . self::IMAGE_SET . '|' . self::SRC . ')/', $css, $m, 0, $i ) ) {
					return array( $i, $i + strlen( $m[0] ) );
				}
				if ( ! $image_set && 'url(' === strtolower( substr( $css, $i, 4 ) ) ) {
					return array( $i, $i + 4 );
				}
			}
			++$i;
		}
		return null;
	}

	/**
	 * CSS escapes decoded as the tokenizer decodes them (CSS Syntax §4.3.7): hex with one optional white space (a CR LF
	 * is one), or the next character. Read byte by byte, so text that is not UTF-8 is decoded all the same.
	 */
	private static function css_unescape( string $css ): string {
		return (string) preg_replace_callback(
			'/\\\\(?:([0-9a-fA-F]{1,6})(?:\r\n|[ \t\n\r\f])?|(.))/s',
			static function ( array $m ): string {
				if ( '' !== $m[1] ) {
					$code = hexdec( $m[1] );
					return 0 < $code && $code <= 0x10FFFF && ( $code < 0xD800 || $code > 0xDFFF ) ? (string) mb_chr( (int) $code, 'UTF-8' ) : "\u{FFFD}";
				}
				return $m[2];
			},
			$css
		);
	}

	/**
	 * Every CSS escape that stands for an ASCII letter written out as that letter (`\75`, `\75 `, `\000075`, `\u`),
	 * as the tokenizer decodes it (CSS Syntax §4.3.7): the browser reads `\75rl(` as url(. Read from left to right, so
	 * `\\` stays a pair. Every other escape stays as written (`.md\:hidden`, `.\31 0`, `\2c`), and so does a backslash
	 * before a new line or at the end. No real sheet needs a letter written with an escape.
	 */
	private static function css_letters( string $css ): string {
		if ( ! str_contains( $css, '\\' ) ) {
			return $css;
		}
		$out    = '';
		$length = strlen( $css );
		// True while the last thing written is a hex escape kept with less than six digits and no white space after it:
		// a hex digit written right after would be read as one more of its digits ("\2d\65" is "-e", not "\2de").
		$open = false;
		for ( $i = 0; $i < $length; ) {
			$slash = strpos( $css, '\\', $i );
			if ( false === $slash ) {
				$out .= substr( $css, $i );
				break;
			}
			if ( $slash > $i ) {
				$out .= substr( $css, $i, $slash - $i );
				$open = false;
			}
			$i     = $slash;
			$after = $css[ $i + 1 ] ?? '';
			if ( '' === $after || "\n" === $after || "\r" === $after || "\f" === $after ) {
				// No escape: a line continued in a string, or a stray backslash.
				$out .= '\\';
				$open = false;
				++$i;
				continue;
			}
			$digits = strspn( $css, '0123456789abcdefABCDEF', $i + 1, 6 );
			if ( 0 === $digits ) {
				// A backslash and one character: that character (letters from g to z only: a to f are hex digits).
				$out .= str_contains( self::LETTERS, $after ) ? $after : '\\' . $after;
				$open = false;
				$i   += 2;
				continue;
			}
			$end   = $i + 1 + $digits;
			$space = "\r\n" === substr( $css, $end, 2 ) ? 2 : ( $end < $length && str_contains( " \t\n\r\f", $css[ $end ] ) ? 1 : 0 );
			$code  = (int) hexdec( substr( $css, $i + 1, $digits ) );
			if ( ( $code >= 0x41 && $code <= 0x5A ) || ( $code >= 0x61 && $code <= 0x7A ) ) {
				$letter = chr( $code );
				$out   .= ( $open && str_contains( 'ABCDEFabcdef', $letter ) ? ' ' : '' ) . $letter;
				$open   = false;
			} else {
				$out .= substr( $css, $i, $end + $space - $i );
				$open = $digits < 6 && 0 === $space;
			}
			$i = $end + $space;
		}
		return $out;
	}

	/**
	 * The CSS without an escape at its very end (`\`, `\2d`, `\75` and a carriage return): receives the CSS, gives it back
	 * cut before that backslash, as many times as it takes; CSS that ends in anything else comes back as it is.
	 */
	private static function css_closed_end( string $css ): string {
		while ( 1 === preg_match( '/\\\\[0-9a-fA-F]{0,6}\r?\z/', $css ) ) {
			$css = substr( $css, 0, (int) strrpos( $css, '\\' ) );
		}
		return $css;
	}

	/**
	 * Whether a piece of CSS holds nothing that reads like an address of another site: no `//`, no backslash (an
	 * escape could spell one) and no scheme. Receives the text of a url( or image-set( that is not closed; decides only
	 * how it is taken out, closed where it opens or cut to its end: it is never kept open.
	 */
	private static function names_no_address( string $css ): bool {
		return 1 !== preg_match( '#//|\\\\|(?<![a-z0-9_])(?:https?|ftp|data|blob|file|javascript|wss?)' . self::SPACE . '*:#', strtolower( $css ) );
	}

	/** The CSS without its comments, each from its opener to its closer or to the end, wherever it is written. */
	private static function css_without_comments( string $css ): string {
		$out    = '';
		$offset = 0;
		while ( false !== ( $open = strpos( $css, '/*', $offset ) ) ) {
			$out  .= substr( $css, $offset, $open - $offset );
			$close = strpos( $css, '*/', $open + 2 );
			if ( false === $close ) {
				return $out;
			}
			$offset = $close + 2;
		}
		return $out . substr( $css, $offset );
	}

	/**
	 * Content types, fields and which fields pages may read decide what the site stores and shows: only an
	 * administrator changes them (docs/spec/content.md §5). Unchanged values pass for anyone.
	 */
	public static function admin_only( string $key, mixed $next, mixed $current ): void {
		if ( ! current_user_can( 'manage_options' ) && wp_json_encode( $next ) !== wp_json_encode( $current ) ) {
			throw new VersioningException(
				'content' === $key
					? __( 'Solo un amministratore può modificare i tipi di contenuto e i campi.', 'xb' )
					: __( 'Solo un amministratore può decidere quali campi personalizzati leggono le pagine.', 'xb' ),
				403
			);
		}
	}

	/** @return array<string, mixed> */
	public static function read( string $doc ): array {
		if ( self::STYLES === $doc ) {
			return array(
				'styles'  => array_map(
					static function ( array $entry ): array {
						$style = array(
							'id'       => $entry['id'],
							'selector' => $entry['selector'],
							'css'      => $entry['css'],
						);
						if ( is_array( $entry['assets'] ?? null ) && array() !== $entry['assets'] ) {
							$style['assets'] = $entry['assets'];
						}
						return $style;
					},
					( new StyleRegistry() )->entries( true )
				),
				'globals' => ( new StyleRegistry() )->globals( true ),
			);
		}
		if ( self::SETTINGS === $doc ) {
			$framework = get_option( Frameworks::OPTION );
			return array(
				'framework' => is_string( $framework ) ? $framework : null,
				'reset'     => (bool) get_option( \XB\Framework\Reset::OPTION, false ),
				'presets'   => (array) get_option( \XB\Render\LoopPresets::OPTION, array() ),
				'loopTypes' => array_values( (array) get_option( \XB\Render\LoopPresets::TYPES, array() ) ),
				'loopTaxonomies' => array_values( (array) get_option( \XB\Render\LoopPresets::TAXONOMIES, array() ) ),
				'sources'   => (array) get_option( \XB\Render\Sources::OPTION, array() ),
				'content'   => \XB\Content\ContentTypes::live(),
				'queries'   => is_array( get_option( \XB\Styles\NamedQueries::OPTION, null ) ) ? (array) get_option( \XB\Styles\NamedQueries::OPTION ) : \XB\Styles\NamedQueries::DEFAULTS,
				'publicFields' => array_values( (array) get_option( \XB\Fields\Fields::OPTION, array() ) ),
				'facets'    => (array) get_option( \XB\Render\Facets::OPTION, array() ),
				'tokens'    => (array) get_option( \XB\Framework\TokenOverrides::OPTION, array() ),
				'siteTokens' => (array) get_option( \XB\Framework\SiteTokens::OPTION, array() ),
				'recipes'   => (array) get_option( \XB\Framework\SiteRecipes::OPTION, array() ),
				'design'    => (array) get_option( \XB\Framework\HistosCss\Design::OPTION, array() ),
				// Lista della spesa 148: the site's own scripts, published with the release like the rest.
				\XB\Scripts\SiteScripts::KEY => array_values( (array) get_option( \XB\Scripts\SiteScripts::OPTION, array() ) ),
			);
		}
		$post = get_post( (int) self::post_id( $doc ) );
		if ( ! $post instanceof \WP_Post ) {
			throw new VersioningException( sprintf( /* translators: %s: document key */ __( 'Documento inesistente: %s', 'xb' ), $doc ), 404 );
		}
		$payload = array(
			'content' => $post->post_content,
			'script'  => Scripts::get( $post->ID ),
		);
		// The page's own look (voce 186): only when it has one, so pages without it keep the payload they had.
		if ( in_array( $post->post_type, \XB\Styles\PageLook::TYPES, true ) ) {
			$look = \XB\Styles\PageLook::stored( $post->ID );
			if ( array() !== $look ) {
				$payload[ \XB\Styles\PageLook::KEY ] = $look;
			}
		}
		if ( 'wp_block' === $post->post_type ) {
			$schema            = json_decode( (string) get_post_meta( $post->ID, ComponentRegistry::META_SCHEMA, true ), true );
			$payload['ref']    = (string) get_post_meta( $post->ID, ComponentRegistry::META_KEY, true );
			$payload['schema'] = is_array( $schema ) ? $schema : array( 'props' => array() );
		}
		return $payload;
	}

	/**
	 * Writes a payload to the live site with the current user's permissions (the sanitizer runs as for
	 * any save). Throws, leaving the caller's transaction to roll back, if anything is refused.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function apply( string $doc, array $payload, bool $trusted = true ): void {
		self::$applying = true;
		try {
			if ( self::STYLES === $doc && ! $trusted ) {
				$payload = self::restricted_styles( $payload );
			}
			if ( self::STYLES === $doc ) {
				update_option( StyleRegistry::OPTION_STYLES, is_array( $payload['styles'] ?? null ) ? $payload['styles'] : array() );
				update_option( StyleRegistry::OPTION_GLOBAL, is_array( $payload['globals'] ?? null ) ? $payload['globals'] : array() );
				return;
			}
			if ( self::SETTINGS === $doc ) {
				$framework = $payload['framework'] ?? null;
				if ( is_string( $framework ) && ! Plugin::instance()->frameworks->select( $framework ) ) {
					throw new VersioningException( sprintf( /* translators: %s: framework name */ __( 'Framework non disponibile su questo sito: %s', 'xb' ), $framework ), 409 );
				}
				// A version from before any choice: going back to it undoes the choice.
				if ( array_key_exists( 'framework', $payload ) && null === $framework ) {
					delete_option( Frameworks::OPTION );
				}
				if ( array_key_exists( 'reset', $payload ) ) {
					update_option( \XB\Framework\Reset::OPTION, (bool) $payload['reset'] );
				}
				if ( array_key_exists( \XB\Scripts\SiteScripts::KEY, $payload ) ) {
					try {
						$scripts = \XB\Scripts\SiteScripts::validate( $payload[ \XB\Scripts\SiteScripts::KEY ] );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 422 );
					}
					// Like a document's JavaScript: a change goes live only when written and published with unfiltered_html.
					if ( $scripts !== \XB\Scripts\SiteScripts::validate_stored() && ( ! $trusted || ! current_user_can( 'unfiltered_html' ) ) ) {
						throw new VersioningException( __( 'Gli script del sito possono essere pubblicati solo da chi ha il permesso unfiltered_html.', 'xb' ), 403 );
					}
					try {
						update_option( \XB\Scripts\SiteScripts::OPTION, $scripts );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 422 );
					}
				}
				if ( array_key_exists( 'presets', $payload ) ) {
					try {
						update_option( \XB\Render\LoopPresets::OPTION, \XB\Render\Queries::validate( $payload['presets'] ) );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'sources', $payload ) ) {
					try {
						$taken = array_keys( is_array( $payload['presets'] ?? null ) ? $payload['presets'] : (array) get_option( \XB\Render\LoopPresets::OPTION, array() ) );
						update_option( \XB\Render\Sources::OPTION, \XB\Render\Sources::validate( $payload['sources'], array_map( 'strval', $taken ) ), false );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'content', $payload ) ) {
					self::admin_only( 'content', $payload['content'], \XB\Content\ContentTypes::live() );
					try {
						$content = \XB\Content\ContentTypes::validate( $payload['content'] );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
					update_option( \XB\Content\ContentTypes::OPTION, $content, true );
					\XB\Content\ContentTypes::forget();
				}
				if ( array_key_exists( 'publicFields', $payload ) ) {
					self::admin_only( 'publicFields', $payload['publicFields'], (array) get_option( \XB\Fields\Fields::OPTION, array() ) );
					try {
						update_option( \XB\Fields\Fields::OPTION, \XB\Fields\Fields::validate( $payload['publicFields'] ), true );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'queries', $payload ) ) {
					try {
						update_option( \XB\Styles\NamedQueries::OPTION, \XB\Styles\NamedQueries::validate( $payload['queries'] ), true );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'facets', $payload ) ) {
					try {
						$taken = array_keys( is_array( $payload['presets'] ?? null ) ? $payload['presets'] : (array) get_option( \XB\Render\LoopPresets::OPTION, array() ) );
						update_option( \XB\Render\Facets::OPTION, \XB\Render\Facets::validate( $payload['facets'], array_map( 'strval', $taken ) ), true );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'tokens', $payload ) ) {
					try {
						update_option( \XB\Framework\TokenOverrides::OPTION, \XB\Framework\TokenOverrides::validate( $payload['tokens'] ), true );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'siteTokens', $payload ) ) {
					try {
						update_option( \XB\Framework\SiteTokens::OPTION, \XB\Framework\SiteTokens::validate( $payload['siteTokens'] ), true );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'recipes', $payload ) ) {
					try {
						update_option( \XB\Framework\SiteRecipes::OPTION, \XB\Framework\SiteRecipes::validate( $payload['recipes'] ), false );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'design', $payload ) ) {
					try {
						// Stored as what differs from the defaults: a site keeps following improved defaults.
						update_option( \XB\Framework\HistosCss\Design::OPTION, \XB\Framework\HistosCss\Design::changes( \XB\Framework\HistosCss\Design::validate( $payload['design'] ) ), true );
					} catch ( \InvalidArgumentException $e ) {
						throw new VersioningException( $e->getMessage(), 400 );
					}
				}
				if ( array_key_exists( 'loopTypes', $payload ) ) {
					$types   = \XB\Render\Queries::validate_types( $payload['loopTypes'] );
					$current = \XB\Render\Queries::validate_types( get_option( \XB\Render\LoopPresets::TYPES, array() ) );
					if ( array() !== array_diff( $types, $current ) && ! current_user_can( 'manage_options' ) ) {
						// Allowing more content types in loops widens what pages can list: administrators only.
						throw new VersioningException( __( 'Solo un amministratore può ammettere nuovi tipi di contenuto nei loop.', 'xb' ), 403 );
					}
					update_option( \XB\Render\LoopPresets::TYPES, $types );
				}
				if ( array_key_exists( 'loopTaxonomies', $payload ) ) {
					$taxonomies = \XB\Render\Queries::validate_taxonomies( $payload['loopTaxonomies'] );
					$current    = \XB\Render\Queries::validate_taxonomies( get_option( \XB\Render\LoopPresets::TAXONOMIES, array() ) );
					if ( array() !== array_diff( $taxonomies, $current ) && ! current_user_can( 'manage_options' ) ) {
						// As for content types: term loops listing more taxonomies widen what pages show.
						throw new VersioningException( __( 'Solo un amministratore può ammettere nuove tassonomie nei loop.', 'xb' ), 403 );
					}
					update_option( \XB\Render\LoopPresets::TAXONOMIES, $taxonomies );
				}
				return;
			}
			$id = (int) self::post_id( $doc );
			if ( ! current_user_can( 'edit_post', $id ) ) {
				throw new VersioningException( sprintf( /* translators: %s: document key */ __( 'Non puoi modificare %s', 'xb' ), $doc ), 403 );
			}
			$content = is_string( $payload['content'] ?? null ) ? $payload['content'] : '';
			if ( ! $trusted ) {
				// Written by someone without unfiltered_html: filter as such, whoever is publishing.
				$live    = get_post_field( 'post_content', $id, 'raw' );
				$content = Plugin::instance()->sanitizer->restricted_content( $content, is_string( $live ) ? $live : '' );
				if ( ( $payload['script'] ?? '' ) !== Scripts::get( $id ) ) {
					throw new VersioningException( __( 'Una modifica al JavaScript non firmata da un autore autorizzato non può essere pubblicata.', 'xb' ), 403 );
				}
			}
			$result  = wp_update_post(
				array(
					'ID'            => $id,
					'post_content'  => wp_slash( $content ),
					// Only the content changes. Without this, wp_update_post() re-validates the stored page template
					// against the theme and refuses a page that uses a Histeon template not published yet.
					'page_template' => '',
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				throw new VersioningException( $result->get_error_message() );
			}
			$script = is_string( $payload['script'] ?? null ) ? $payload['script'] : '';
			if ( $script !== Scripts::get( $id ) && ! Scripts::set( $id, $script ) ) {
				throw new VersioningException( __( 'Il JavaScript può essere pubblicato solo da chi ha il permesso unfiltered_html.', 'xb' ), 403 );
			}
			if ( \XB\Styles\PageLook::supports( $id ) ) {
				try {
					// The same closed grammar whoever wrote it, trusted or not (docs/spec/client-mode.md).
					\XB\Styles\PageLook::set( $id, $payload[ \XB\Styles\PageLook::KEY ] ?? array() );
				} catch ( \InvalidArgumentException $e ) {
					throw new VersioningException( $e->getMessage(), 422 );
				}
			}
			if ( 'wp_block' === get_post_type( $id ) ) {
				$ref = $payload['ref'] ?? null;
				if ( is_string( $ref ) && 1 === preg_match( Spec::COMPONENT_REF_PATTERN, $ref ) ) {
					$owner = ComponentRegistry::owner( $ref );
					if ( null !== $owner && $owner !== $id ) {
						throw new VersioningException( sprintf( __( 'La chiave "%s" appartiene già a un altro componente.', 'xb' ), $ref ), 409 );
					}
					update_post_meta( $id, ComponentRegistry::META_KEY, $ref );
				}
				if ( is_array( $payload['schema'] ?? null ) ) {
					update_post_meta( $id, ComponentRegistry::META_SCHEMA, wp_slash( (string) wp_json_encode( $payload['schema'] ) ) );
				}
				Plugin::instance()->components->forget();
			}
		} finally {
			self::$applying = false;
		}
	}
}
