<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

use XB\Framework\SiteRecipes;
use XB\Framework\TokenOverrides;
use XB\Remote\TransferMarkup;
use XB\Security\MarkupPolicy;
use XB\Styles\CssAssets;
use XB\Versioning\Preview;

/**
 * Threat model T31, P1 of the ninth review, outside the filter of restricted CSS: what decides whether something
 * passes reads ASCII letters the same way whatever the PHP locale. The `i` flag of PCRE takes its letters from the
 * tables of the locale: in a Turkish one `I` is not the capital of `i`, so `JAVASCRIPT:` was no scheme, `@IMPORT` no
 * import and `<SCRIPT>` no script. Skipped where the machine has no Turkish locale: one is made with
 * `localedef -i tr_TR -f UTF-8 <folder>/tr_TR.UTF-8` and found through LOCPATH.
 */
final class TurkishLocaleTest extends IntegrationTestCase {

	/** @return array<string, mixed> What each check answers, by name. */
	private function answers(): array {
		$transfer = static function ( string $css ): string {
			try {
				CssAssets::validate_profile( $css, array(), array(), array() );
				return 'passes';
			} catch ( \DomainException $e ) {
				return $e->getMessage();
			}
		};
		wp_set_current_user( 1 );
		$verbatim = $this->block(
			'xb/text',
			array(
				'content'  => 'let a = "</SCRIPT><p>fuori</p>";',
				'verbatim' => true,
			)
		);
		return array(
			'form action, JAVASCRIPT:'      => MarkupPolicy::safe_form_action( 'JAVASCRIPT:void(0)' ),
			'form action, FILE:'            => MarkupPolicy::safe_form_action( 'FILE:///x' ),
			'form action, entity'           => MarkupPolicy::safe_form_action( 'JAVASCRIPT&colon;void(0)' ),
			'form action, HTTPS:'           => MarkupPolicy::safe_form_action( 'HTTPS://altro.example/invio' ),
			'form action, path'             => MarkupPolicy::safe_form_action( '/contatti/invio' ),
			'transfer, JAVASCRIPT:'         => TransferMarkup::form_action_is_safe( 'JAVASCRIPT:void(0)' ),
			'transfer, host with %'         => TransferMarkup::form_action_is_safe( 'HTTPS://altro.example%40sito/invio' ),
			'transfer, path'                => TransferMarkup::form_action_is_safe( '/contatti/invio' ),
			'preview without scripts'       => Preview::without_scripts( '<p>a</p><SCRIPT SRC="/x.js"></SCRIPT><LINK REL="modulepreload" HREF="/x.js"><Script>1</Script' . " \n" . '><link rel=stylesheet href="/a.css"><linked rel=modulepreload>' ),
			'recipe, @IMPORT'               => null === SiteRecipes::problem( 'ricetta', '@IMPORT "https://altro.example/a.css";' ),
			'recipe, -MOZ-BINDING'          => null === SiteRecipes::problem( 'ricetta', 'a{-MOZ-BINDING:none}' ),
			'recipe, BEHAVIOR'              => null === SiteRecipes::problem( 'ricetta', 'a{BEHAVIOR :none}' ),
			'recipe, plain'                 => null === SiteRecipes::problem( 'ricetta', 'padding:1rem;COLOR:RED' ),
			'token, EXPRESSION('            => null === TokenOverrides::problem( 'EXPRESSION(1)' ),
			'token, plain'                  => null === TokenOverrides::problem( 'CLAMP(1REM, 2VI, 3REM)' ),
			'profile, @IMPORT of a file'    => $transfer( '@IMPORT "foglio.css";' ),
			'profile, @Import of a site'    => $transfer( '@Import url(HTTPS://altro.example/a.css);' ),
			'profile, DATA:'                => $transfer( '.a{background:url(DATA:image/gif;base64,R0lGODlhAQABAAAAACw=)}' ),
			'profile, a file not linked'    => $transfer( '.a{background:url(IMMAGINI/a.png)}' ),
			'text of a <script>'            => $this->render( array( $this->el( 'script', array(), array( $verbatim ) ) ) ),
		);
	}

	public function test_what_decides_reads_ascii_letters_the_same_in_a_turkish_locale(): void {
		$plain = $this->answers();
		$this->assertSame(
			array(
				'form action, JAVASCRIPT:'   => false,
				'form action, FILE:'         => false,
				'form action, entity'        => false,
				'form action, HTTPS:'        => true,
				'form action, path'          => true,
				'transfer, JAVASCRIPT:'      => false,
				'transfer, host with %'      => false,
				'transfer, path'             => true,
				'preview without scripts'    => '<p>a</p><link rel=stylesheet href="/a.css"><linked rel=modulepreload>',
				'recipe, @IMPORT'            => false,
				'recipe, -MOZ-BINDING'       => false,
				'recipe, BEHAVIOR'           => false,
				'recipe, plain'              => true,
				'token, EXPRESSION('         => false,
				'token, plain'               => true,
				'profile, @IMPORT of a file' => 'Local CSS imports cannot be transferred with the Histeon page profile.',
				'profile, @Import of a site' => 'passes',
				'profile, DATA:'             => 'passes',
				'profile, a file not linked' => 'Every CSS url() must be linked to a media file before transfer.',
			),
			array_diff_key( $plain, array( 'text of a <script>' => true ) ),
			'no locale'
		);
		$this->assertStringContainsString( '"<\\/SCRIPT><p>fuori</p>";', (string) $plain['text of a <script>'] );
		$this->assertStringNotContainsString( '</SCRIPT><p>', (string) $plain['text of a <script>'] );
		$before  = (string) setlocale( LC_CTYPE, '0' );
		$turkish = array();
		foreach ( array( 'tr_TR.UTF-8', 'tr_TR.utf8', 'tr_TR.ISO-8859-9', 'tr_TR.iso88599', 'tr_TR' ) as $name ) {
			// A C library may take any name and keep its own tables (musl): Turkish is the locale where, for PCRE, `I`
			// and `i` are two letters.
			if ( false !== setlocale( LC_CTYPE, $name ) && 0 === preg_match( '/i/i', 'I' ) ) {
				$turkish[] = $name;
			}
		}
		setlocale( LC_CTYPE, $before );
		if ( array() === $turkish ) {
			$this->markTestSkipped( 'No Turkish locale on this machine (tr_TR.UTF-8, tr_TR.ISO-8859-9): read with no locale only.' );
		}
		try {
			foreach ( $turkish as $name ) {
				setlocale( LC_CTYPE, $name );
				$this->assertSame( $plain, $this->answers(), $name );
			}
		} finally {
			setlocale( LC_CTYPE, $before );
		}
	}
}
