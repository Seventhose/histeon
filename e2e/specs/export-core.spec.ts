import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// The conversion to Fondamenta (docs/spec/export-core.md §11-§12, doc 25 of Histeon Publish §11 step 3): a page
// converted from Histeon blocks opens in Gutenberg with every block valid — Fondamenta's elements, loops and
// conditions, and the core text blocks.
const MAKE = String.raw`<?php
$run = getenv( 'E2E_RUN' );
$n   = 0;
$uid = static function () use ( $run, &$n ): string { return 'ec' . substr( md5( $run . ( $n++ ) ), 0, 10 ); };
$b   = static function ( string $name, array $attrs, array $inner = array() ) use ( $uid ): array {
	return array( 'blockName' => $name, 'attrs' => array( 'uid' => $uid() ) + $attrs, 'innerBlocks' => $inner, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $inner ), null ) );
};
$el  = static fn ( string $tag, array $attributes, array $inner = array() ): array => $b( 'xb/element', array( 'tag' => $tag ) + ( array() === $attributes ? array() : array( 'attributes' => $attributes ) ), $inner );
$t   = static fn ( string $content ): array => $b( 'xb/text', array( 'content' => $content ) );
$blocks = array(
	$el( 'section', array( 'class' => 'e2e-uscita', 'data-xb-reveal' => 'up' ), array(
		$el( 'h2', array( 'class' => 'e2e-uscita__titolo' ), array( $t( 'Uscita verso Gutenberg' ) ) ),
		$el( 'p', array(), array( $t( 'Testo con ' ), $el( 'strong', array(), array( $t( 'forza' ) ) ) ) ),
		$el( 'ul', array( 'class' => 'e2e-elenco' ), array( $el( 'li', array(), array( $t( 'uno' ) ) ), $el( 'li', array(), array( $t( 'due' ) ) ) ) ),
		$el( 'a', array( 'class' => 'e2e-pulsante', 'href' => '/contatti/' ), array( $t( 'Scrivici' ) ) ),
		$el( 'img', array( 'src' => '/wp-includes/images/w-logo-blue.png', 'alt' => 'Logo' ) ),
		$el( 'div', array( 'style' => 'gap:1rem', 'aria-label' => 'riquadro' ), array( $el( 'span', array(), array( $t( 'dentro' ) ) ) ) ),
	) ),
	$b( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'limit' => '2' ) ), 'as' => 'x' ), array(
		$el( 'article', array( 'class' => 'e2e-scheda e2e-scheda--{x.type}' ), array(
			$el( 'h3', array(), array( $el( 'a', array( 'href' => '{x.url}' ), array( $t( '{x.title}' ) ) ) ) ),
			$el( 'time', array( 'datetime' => '{x.date}' ), array( $t( '{x.date.date("j F Y")}' ) ) ),
			$b( 'xb/if', array(), array( $b( 'xb/branch', array( 'test' => 'x.excerpt' ), array( $el( 'p', array(), array( $t( '{x.excerpt.truncateWords(5)}' ) ) ) ) ) ) ),
			$el( 'div', array( 'class' => 'e2e-riga' ), array( $el( 'a', array( 'href' => '{x.url}' ), array( $t( 'Vai' ) ) ), $t( ' a {x.title} ' ), $el( 'span', array(), array( $t( 'fine' ) ) ) ) ),
		) ),
	) ),
);
$source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Uscita sorgente ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( $blocks ) ) ) );
wp_set_current_user( 1 );
$done = XB\Export\Core\Prova::convert( get_post( $source ) );
$id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Uscita convertita ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( $done['blocks'] ) ) ) );
echo "\nE2E=" . $id . "\n";
`;

test('una pagina convertita a Fondamenta si apre in Gutenberg con tutti i blocchi validi', async ({ page }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  const id = Number(wpEval(MAKE, { E2E_RUN: run }).split('\n').find((l) => l.startsWith('E2E='))!.slice(4));
  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await expect(canvas.getByText('Uscita verso Gutenberg')).toBeVisible({ timeout: 30_000 });
  const blocks = await page.evaluate(() => {
    const wp = (window as unknown as { wp: any }).wp;
    const out: Array<{ name: string; valid: boolean }> = [];
    const walk = (list: any[]) => list.forEach((b) => { out.push({ name: b.name, valid: b.isValid }); walk(b.innerBlocks ?? []); });
    walk(wp.data.select('core/block-editor').getBlocks());
    return out;
  });
  const names = blocks.map((b) => b.name);
  for (const name of ['fondamenta/elemento', 'fondamenta/loop', 'fondamenta/se', 'fondamenta/testo', 'core/heading', 'core/paragraph', 'core/list', 'core/list-item']) {
    expect(names).toContain(name);
  }
  expect(names).not.toContain('core/missing');
  expect(blocks.filter((b) => !b.valid)).toEqual([]);
  // Nothing to save: the editor reads the blocks back as they were written.
  const dirty = await page.evaluate(() => (window as unknown as { wp: any }).wp.data.select('core/editor').isEditedPostDirty());
  expect(dirty).toBe(false);
});

// A component becomes a synced pattern (D3): the component's post holds the converted pattern, each instance is a
// core/block with its values as overrides, shown in the canvas.
const PATTERN = String.raw`<?php
$run = getenv( 'E2E_RUN' );
$n   = 0;
$uid = static function () use ( $run, &$n ): string { return 'ep' . substr( md5( $run . ( $n++ ) ), 0, 10 ); };
$b   = static function ( string $name, array $attrs, array $inner = array() ) use ( $uid ): array {
	return array( 'blockName' => $name, 'attrs' => array( 'uid' => $uid() ) + $attrs, 'innerBlocks' => $inner, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $inner ), null ) );
};
$el  = static fn ( string $tag, array $attributes, array $inner = array() ): array => $b( 'xb/element', array( 'tag' => $tag ) + ( array() === $attributes ? array() : array( 'attributes' => $attributes ) ), $inner );
$t   = static fn ( string $content ): array => $b( 'xb/text', array( 'content' => $content ) );
$key = 'e2e/carta-' . $run;
$def = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Carta ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( array( $el( 'article', array( 'class' => 'e2e-carta' ), array( $el( 'h3', array(), array( $t( '{props.titolo}' ) ) ), $el( 'a', array( 'href' => '{props.url}' ), array( $t( '{props.etichetta}' ) ) ) ) ) ) ) ) ) );
update_post_meta( $def, 'xb_component_key', $key );
update_post_meta( $def, 'xb_component_schema', wp_slash( wp_json_encode( array( 'props' => array( 'titolo' => array( 'type' => 'string', 'default' => 'Titolo' ), 'url' => array( 'type' => 'url', 'default' => '/' ), 'etichetta' => array( 'type' => 'string', 'default' => 'Leggi' ) ) ) ) ) );
XB\Plugin::instance()->components->forget();
$source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pattern sorgente ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( array(
	$b( 'xb/component', array( 'ref' => $key, 'props' => array( 'titolo' => 'Carta uno ' . $run, 'url' => '/uno/', 'etichetta' => 'Vai' ) ) ),
	$b( 'xb/component', array( 'ref' => $key, 'props' => array( 'titolo' => 'Carta due ' . $run ) ) ),
) ) ) ) );
wp_set_current_user( 1 );
$page    = XB\Export\Core\Prova::convert( get_post( $source ) );
$pattern = XB\Export\Core\Prova::convert( get_post( $def ) );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_content' => XB\Blocks::serialize( $pattern['blocks'] ) ), array( 'ID' => $def ) );
clean_post_cache( $def );
$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Pattern convertito ' . $run, 'post_content' => wp_slash( XB\Blocks::serialize( $page['blocks'] ) ) ) );
echo "\nE2E=" . $id . "\n";
`;

test('un componente convertito è un pattern sincronizzato: blocchi validi e override mostrati', async ({ page }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  const id = Number(wpEval(PATTERN, { E2E_RUN: run }).split('\n').find((l) => l.startsWith('E2E='))!.slice(4));
  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await expect(canvas.getByText(`Carta uno ${run}`)).toBeVisible({ timeout: 30_000 });
  await expect(canvas.getByText(`Carta due ${run}`)).toBeVisible();
  const blocks = await page.evaluate(() => {
    const wp = (window as unknown as { wp: any }).wp;
    const out: Array<{ name: string; valid: boolean }> = [];
    const walk = (list: any[]) => list.forEach((b) => { out.push({ name: b.name, valid: b.isValid }); walk(wp.data.select('core/block-editor').getBlocks(b.clientId)); });
    walk(wp.data.select('core/block-editor').getBlocks());
    return out;
  });
  expect(blocks.filter((b) => b.name === 'core/block')).toHaveLength(2);
  expect(blocks.map((b) => b.name)).toContain('fondamenta/elemento');
  expect(blocks.filter((b) => !b.valid)).toEqual([]);
});
