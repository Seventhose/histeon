import { randomBytes } from 'node:crypto';
import { el, ifBlock, text } from './lib/blocks.ts';
import type { Fixtures } from './lib/fixtures.ts';
import { wpEval } from './lib/wp.ts';

/** Pages every spec starts from; the same small section, so each spec edits its own copy. */
const section = () =>
  el('section', { class: 'e2e-hero' }, el('h1', null, text('Ciao e2e')), el('p', { class: 'uno' }, text('Primo paragrafo')), el('p', { class: 'due' }, text('Secondo paragrafo')));

const PAGES = {
  html: section,
  css: section,
  palette: section,
  aspetto: section,
  dnd: section,
  restore: section,
  transfer: section,
  security: section,
  save: section,
  api: section,
  facets: section,
  interactions: section,
  review: section,
  // Written outside XB (Gutenberg, REST): a condition whose printed form would read back as different blocks.
  readonly: () =>
    el('section', { class: 'e2e-odd' }, el('p', null, text('Scritto fuori da XB')), ifBlock(['true}<p>iniettato</p>{#if true', el('p', null, text('Ramo'))])),
};

/**
 * The dedicated test admin (created or reset with a fresh password) and the fixture content, made with
 * wp-cli inside the dev stack. Fresh pages and posts on every run: nothing depends on what earlier runs left.
 */
const PHP = String.raw`<?php
$login = getenv( 'XB_E2E_USER' );
$pass  = getenv( 'XB_E2E_PASSWORD' );
$user  = get_user_by( 'login', $login );
if ( $user ) {
	$id = $user->ID;
	wp_set_password( $pass, $id );
} else {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'user_email' => $login . '@example.test', 'role' => 'administrator' ) );
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, $id->get_error_message() . "\n" );
		exit( 1 );
	}
}
( new WP_User( $id ) )->set_role( 'administrator' );
wp_set_current_user( $id );

// Definitions earlier runs left in the site settings (content types, queries, facets): the settings have
// limits (30 types, 20 facets…), so they are pruned here, in dev and live alike, before every run.
$e2e   = static fn ( $key ): bool => 1 === preg_match( '/^(corso_|tappa_|e2e_|stretta)|^term:(corso_|tappa_|e2e_)/', (string) $key );
$prune = static function ( array $settings ) use ( $e2e ): array {
	foreach ( array( 'types', 'taxonomies', 'fields' ) as $part ) {
		if ( is_array( $settings['content'][ $part ] ?? null ) ) {
			$settings['content'][ $part ] = array_filter( $settings['content'][ $part ], static fn ( $k ): bool => ! $e2e( $k ), ARRAY_FILTER_USE_KEY );
		}
	}
	foreach ( array( 'presets', 'facets', 'queries' ) as $part ) {
		if ( is_array( $settings[ $part ] ?? null ) ) {
			$settings[ $part ] = array_filter( $settings[ $part ], static fn ( $k ): bool => ! $e2e( $k ), ARRAY_FILTER_USE_KEY );
		}
	}
	return $settings;
};
$versioning = \XB\Plugin::instance()->versioning;
$copy       = $versioning->working_copy( \XB\Versioning\Documents::SETTINGS );
$pruned     = $prune( $copy['payload'] );
if ( $pruned !== $copy['payload'] ) {
	$versioning->save_dev( \XB\Versioning\Documents::SETTINGS, $pruned, (string) $copy['head'], (string) $copy['base'], 'e2e: pulizia delle definizioni di prova' );
}
// A development head of the styles or the settings saved without a user (a restore of an earlier run) is not trusted, and
// it turns off the scripts of every preview: saved again here, as the administrator, as it is.
$store = $versioning->store();
foreach ( array( \XB\Versioning\Documents::STYLES, \XB\Versioning\Documents::SETTINGS ) as $doc ) {
	$head = $store->get( (string) $store->head( $doc, \XB\Versioning\Store::DEV ) );
	if ( null !== $head && ! $head['trusted'] ) {
		$copy = $versioning->working_copy( $doc );
		$versioning->save_dev( $doc, $copy['payload'], (string) $copy['head'], (string) $copy['base'], 'e2e: di nuovo fidata' );
	}
}
$live = $prune(
	array(
		'content' => get_option( \XB\Content\ContentTypes::OPTION, array() ),
		'presets' => get_option( \XB\Render\LoopPresets::OPTION, array() ),
		'facets'  => get_option( \XB\Render\Facets::OPTION, array() ),
		'queries' => get_option( \XB\Styles\NamedQueries::OPTION, array() ),
	)
);
update_option( \XB\Content\ContentTypes::OPTION, $live['content'] );
update_option( \XB\Render\LoopPresets::OPTION, $live['presets'] );
update_option( \XB\Render\Facets::OPTION, $live['facets'] );
if ( is_array( get_option( \XB\Styles\NamedQueries::OPTION, null ) ) ) {
	update_option( \XB\Styles\NamedQueries::OPTION, $live['queries'] );
}
$spec = json_decode( getenv( 'XB_E2E_SPEC' ), true );
$out  = array( 'user' => $login, 'pages' => array(), 'posts' => array() );
foreach ( array( 'pages' => 'page', 'posts' => 'post' ) as $group => $type ) {
	foreach ( $spec[ $group ] as $key => $item ) {
		$post = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_author' => $id, 'post_title' => $item['title'], 'post_name' => $item['slug'], 'post_content' => wp_slash( $item['content'] ) ), true );
		if ( is_wp_error( $post ) ) {
			fwrite( STDERR, $post->get_error_message() . "\n" );
			exit( 1 );
		}
		$out[ $group ][ $key ] = array( 'id' => $post, 'title' => $item['title'], 'url' => get_permalink( $post ) );
	}
}
echo "\nXB_E2E_FIXTURES=" . wp_json_encode( $out ) . "\n";
`;

export default function globalSetup(): void {
  process.env.XB_E2E_USER ??= 'xb-e2e';
  // dev/e2e.sh generates it; running playwright directly gets one here.
  process.env.XB_E2E_PASSWORD ??= randomBytes(18).toString('base64url');
  const run = Date.now().toString(36);
  const spec = {
    pages: Object.fromEntries(Object.entries(PAGES).map(([key, content]) => [key, { title: `E2E ${key} ${run}`, slug: `e2e-${key}-${run}`, content: content() }])),
    posts: Object.fromEntries(
      [1, 2, 3].map((n) => [
        `post${n}`,
        { title: `Articolo e2e ${n} ${run}`, slug: `articolo-e2e-${n}-${run}`, content: `<!-- wp:paragraph --><p>Testo dell'articolo e2e ${n}.</p><!-- /wp:paragraph -->` },
      ]),
    ),
  };
  const output = wpEval(PHP, { XB_E2E_USER: process.env.XB_E2E_USER, XB_E2E_PASSWORD: process.env.XB_E2E_PASSWORD, XB_E2E_SPEC: JSON.stringify(spec) });
  const line = output.split('\n').find((l) => l.startsWith('XB_E2E_FIXTURES='));
  if (!line) throw new Error(`Fixture setup produced no result:\n${output}`);
  const fixtures = JSON.parse(line.slice('XB_E2E_FIXTURES='.length)) as Fixtures;
  // Workers inherit the environment of the runner.
  process.env.XB_E2E_FIXTURES = JSON.stringify(fixtures);
}
