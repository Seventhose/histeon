import { mkdirSync } from 'node:fs';
import path from 'node:path';
import type { Page } from '@playwright/test';
import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

// The page checks of the Publish window in a real browser (docs/spec/checks.md): the rules on the head of each
// page, the site hidden from search engines said once, broken links of the same site, the page weight (V1.4, V1.4 bis),
// the «Aspetto» line (V7.1) and the outcome written with the release in the Versioni panel (V1.5).

/** Screenshots for whoever checks these steps by eye, when XB_E2E_SHOTS names a folder; none otherwise. */
async function shot(page: Page, name: string): Promise<void> {
  const dir = process.env.XB_E2E_SHOTS;
  if (!dir) return;
  mkdirSync(dir, { recursive: true });
  await page.screenshot({ path: path.join(dir, name) });
}

/**
 * A must-use plugin of the test site that spoils the head of the pages carrying the meta `_xb_e2e_head` (comma list):
 * no <title>, no lang, no canonical, robots noindex, and 2,5 MB of random bytes in the footer. Removed after the file.
 */
const MU = String.raw`<?php
add_action( 'wp', static function (): void {
	$id    = get_queried_object_id();
	$flags = $id ? explode( ',', (string) get_post_meta( $id, '_xb_e2e_head', true ) ) : array();
	if ( in_array( 'notitle', $flags, true ) ) {
		// <title> </title>: empty once trimmed, whatever prints the tag.
		add_filter( 'pre_get_document_title', static fn (): string => ' ' );
	}
	if ( in_array( 'nolang', $flags, true ) ) {
		add_filter( 'language_attributes', static fn (): string => '' );
	}
	if ( in_array( 'nocanonical', $flags, true ) ) {
		remove_action( 'wp_head', 'rel_canonical' );
	}
	if ( in_array( 'noindex', $flags, true ) ) {
		add_filter( 'wp_robots', static fn ( array $r ): array => array_merge( $r, array( 'noindex' => true ) ) );
	}
	if ( in_array( 'heavy', $flags, true ) ) {
		add_action( 'wp_footer', static function (): void {
			echo '<!-- ' . base64_encode( random_bytes( 2500000 ) ) . ' -->';
		} );
	}
} );
`;

const run = Date.now().toString(36);
let made: number[] = [];

test.beforeAll(() => {
  const code = Buffer.from(MU).toString('base64');
  wpEval(`<?php wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/xb-e2e-head.php', base64_decode( '${code}' ) );`, {});
});

test.afterAll(() => {
  wpEval(`<?php @unlink( WPMU_PLUGIN_DIR . '/xb-e2e-head.php' );`, {});
});

test.afterEach(() => {
  if (made.length) wpEval(`<?php foreach ( array( ${made.join(', ')} ) as $id ) { wp_delete_post( $id, true ); }`, {});
  made = [];
});

/** A page of this file: published (or a draft), with `markup`, and the head flags of the must-use plugin. */
function makePage(title: string, markup: string, { status = 'publish', head = '' }: { status?: 'publish' | 'draft'; head?: string } = {}): { id: number; url: string } {
  const out = wpEval(
    String.raw`<?php
$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => getenv( 'E2E_STATUS' ), 'post_title' => getenv( 'E2E_TITLE' ), 'post_content' => wp_slash( (string) getenv( 'E2E_MARKUP' ) ) ) );
if ( '' !== getenv( 'E2E_HEAD' ) ) { update_post_meta( $id, '_xb_e2e_head', getenv( 'E2E_HEAD' ) ); }
echo "\nE2E=" . wp_json_encode( array( 'id' => $id, 'url' => get_permalink( $id ) ) ) . "\n";`,
    { E2E_STATUS: status, E2E_TITLE: title, E2E_MARKUP: markup, E2E_HEAD: head },
  );
  const page = JSON.parse(out.split('\n').find((l) => l.startsWith('E2E='))!.slice(4)) as { id: number; url: string };
  made.push(page.id);
  return page;
}

/** Nothing else waiting in development: the checks run on every page that changes, and these tests count them. */
async function noPending(builder: Builder): Promise<void> {
  const changes = await builder.api<Array<{ doc: string }>>('GET', 'changes');
  if (changes.length) await builder.publishAll('e2e: pubblicate le modifiche in sospeso prima dei controlli');
}

/**
 * Expected console lines of the checks: the HEAD requests that found nothing (the link check), and the scripts of the
 * pages refused in the sandboxed frames where the checks and «Aspetto» load them (no script of a page runs there).
 */
function dropExpected(errors: string[]): void {
  const expected = [/status of 404/, /^[^ ]+: Blocked script execution in 'https?:\/\/[^']*' because the document's frame is sandboxed/];
  for (let i = errors.length - 1; i >= 0; i--) if (expected.some((re) => re.test(errors[i]!))) errors.splice(i, 1);
}

const option = (name: string, value?: string) =>
  wpEval(`<?php ${value === undefined ? '' : `update_option( '${name}', '${value}' );`} echo "\\nE2E=" . (string) get_option( '${name}', '' ) . "\\n";`, {})
    .split('\n')
    .find((l) => l.startsWith('E2E='))!
    .slice(4);

test('controlli della finestra Pubblica: regole della testa, link rotti, peso, «Aspetto» ed esito nelle Versioni', async ({ page, consoleErrors }) => {
  const draft = makePage(`Bozza e2e ${run}`, el('p', null, text('Solo in bozza')), { status: 'draft' });
  const markup = el(
    'section',
    { class: 'e2e-controlli' },
    el('h1', null, text('Controlli e2e')),
    el('p', { class: 'uno' }, text('Testo online')),
    el('p', { class: 'link' }, el('a', { href: `/e2e-sparita-${run}/` }, text('Pagina sparita')), el('a', { href: draft.url }, text('Pagina in bozza')), el('a', { href: '/' }, text('Inizio'))),
  );
  const doc = makePage(`Controlli e2e ${run}`, markup, { head: 'notitle,nolang,nocanonical,noindex,heavy' });
  expect(option('blog_public')).toBe('1');

  let builder = await Builder.open(page, doc.id);
  await noPending(builder);
  builder = await Builder.open(page, doc.id);
  await builder.edit(builder.html, (code) => code.replace('Testo online', 'Testo cambiato in sviluppo').replace('</section>', '  <p class="nuovo">Un paragrafo nuovo, solo in sviluppo, che sposta il resto della pagina.</p>\n</section>'));
  await builder.save();

  await page.getByRole('button', { name: 'Pubblica…' }).click();
  const dialog = page.getByRole('dialog', { name: 'Pubblica' });
  const checks = dialog.getByRole('region', { name: 'Controlli prima di pubblicare' });
  let errors = 0;
  let warnings = 0;

  await test.step('V1.4: le regole nuove sulla pagina in sviluppo', async () => {
    await checks.getByRole('button', { name: 'Controlla' }).click();
    // The links are tried after the pages: the line with the count says they are done.
    await expect(checks.getByText(/^\d+ link dello stesso sito provati \(1 rotto\)$/)).toBeVisible({ timeout: 45_000 });
    // The page open in the builder has its own label.
    const row = checks.locator('.page', { hasText: 'Pagina aperta nel builder' });
    const counts = row.getByRole('button');
    await expect(counts).toHaveText(/^\d+ error[ei], \d+ da rivedere$/);
    [errors, warnings] = ((await counts.textContent()) ?? '').match(/\d+/g)!.map(Number) as [number, number];
    await counts.click();
    const list = checks.getByRole('listitem');
    for (const message of [
      'La pagina non ha un titolo (<title>)',
      'La pagina non dichiara la lingua (lang su <html>)',
      'La pagina non ha una descrizione (meta description)',
      'La pagina non ha un indirizzo canonico',
      'La pagina chiede ai motori di ricerca di non mostrarla (robots noindex)',
      'La pagina pesa almeno',
      `Link a una pagina che non esiste (404): /e2e-sparita-${run}/`,
    ]) {
      await expect(list.filter({ hasText: message }), message).toHaveCount(1);
    }
    // The title and the language are errors; a link to a page that is only a draft is not broken.
    expect(errors).toBeGreaterThanOrEqual(2);
    await expect(list.filter({ hasText: draft.url })).toHaveCount(0);
    await expect(list.filter({ hasText: 'Scoraggia i motori di ricerca' })).toHaveCount(0);
    await checks.scrollIntoViewIfNeeded();
    await shot(page, 'v1-controlli.png');
  });

  await test.step('V7.1: la riga «Aspetto» con «Calcola» e «Guarda»', async () => {
    const look = checks.locator('.look');
    await expect(look).toContainText('Aspetto: quante pagine cambiano rispetto al sito online');
    await look.getByRole('button', { name: 'Calcola' }).click();
    await expect(look).toHaveText(/Aspetto: 1 pagina cambia a schermo grande, 1 al telefono/, { timeout: 45_000 });
    await look.scrollIntoViewIfNeeded();
    await shot(page, 'v7-aspetto.png');
    await look.getByRole('button', { name: 'Guarda' }).click();
    const visual = dialog.getByRole('region', { name: 'Confronto visivo' });
    await expect(visual.getByRole('status')).toHaveText(/confront[oi] mostr(a|ano) differenze/, { timeout: 45_000 });
    await expect(visual).toBeInViewport();
  });

  await test.step('V1.5: la release porta l’esito dei controlli nel pannello Versioni', async () => {
    const version = await dialog.getByLabel('Versione').inputValue();
    await dialog.getByRole('button', { name: 'Pubblica', exact: true }).click();
    await expect(dialog.getByRole('status').first()).toHaveText(new RegExp(`^Pubblicata la versione ${version.replace(/\./g, '\\.')}`));
    await dialog.getByRole('button', { name: 'Chiudi' }).click();
    await builder.tab('Versioni');
    const row = builder.release(version);
    await expect(row).toContainText(`Uscita con ${errors} errori e ${warnings} avvisi`);
    await row.scrollIntoViewIfNeeded();
    await shot(page, 'v1-versioni.png');
  });
  dropExpected(consoleErrors);
});

test('V1.5: una release pubblicata senza controlli finiti non porta un esito', async ({ page }) => {
  const doc = makePage(`Senza controlli e2e ${run}`, el('section', { class: 'e2e-senza' }, el('h1', null, text('Senza controlli')), el('p', null, text('Prima'))));
  let builder = await Builder.open(page, doc.id);
  await noPending(builder);
  builder = await Builder.open(page, doc.id);
  await builder.edit(builder.html, (code) => code.replace('Prima', 'Dopo'));
  await builder.save();
  await page.getByRole('button', { name: 'Pubblica…' }).click();
  const dialog = page.getByRole('dialog', { name: 'Pubblica' });
  const version = await dialog.getByLabel('Versione').inputValue();
  // «Controlla» not pressed: nothing ran.
  await expect(dialog.getByRole('button', { name: 'Controlla' })).toBeVisible();
  await dialog.getByRole('button', { name: 'Pubblica', exact: true }).click();
  await expect(dialog.getByRole('status').first()).toHaveText(/^Pubblicata la versione/);
  await dialog.getByRole('button', { name: 'Chiudi' }).click();
  await builder.tab('Versioni');
  const row = builder.release(version);
  await expect(row).toBeVisible();
  await expect(row).not.toContainText('Uscita con');
});

test('V1.4 bis: sito nascosto ai motori di ricerca, detto una volta, non spegne «Pubblica» con i controlli obbligatori', async ({ page, consoleErrors }) => {
  const before = { blog: option('blog_public'), block: option('xb_checks_block') };
  try {
    const one = makePage(`Nascosto uno e2e ${run}`, el('section', { class: 'e2e-nascosto' }, el('h1', null, text('Pagina uno')), el('p', null, text('Uno'))));
    const two = makePage(`Nascosto due e2e ${run}`, el('section', { class: 'e2e-nascosto' }, el('h1', null, text('Pagina due')), el('p', null, text('Due'))));
    let builder = await Builder.open(page, one.id);
    await noPending(builder);
    option('blog_public', '0');
    option('xb_checks_block', '1');
    builder = await Builder.open(page, two.id);
    await builder.edit(builder.html, (code) => code.replace('Due', 'Due, cambiata'));
    await builder.save();
    builder = await Builder.open(page, one.id);
    await builder.edit(builder.html, (code) => code.replace('Uno', 'Uno, cambiata'));
    await builder.save();

    await page.getByRole('button', { name: 'Pubblica…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Pubblica' });
    const checks = dialog.getByRole('region', { name: 'Controlli prima di pubblicare' });
    await expect(checks).toContainText('obbligatori: con errori non si pubblica');
    // They run by themselves: two pages, each with its row.
    await expect(checks.locator('.page', { hasText: 'Pagina aperta nel builder' })).toBeVisible({ timeout: 45_000 });
    await expect(checks.locator('.page', { hasText: 'Nascosto due e2e' })).toBeVisible({ timeout: 45_000 });
    await expect(checks.getByRole('button', { name: /^(Rifai i controlli|Controllo dei link…)$/ })).toBeVisible({ timeout: 45_000 });
    // Said once for the whole release, and the pages do not repeat it as their own noindex.
    await expect(checks.getByText(/Scoraggia i motori di ricerca/)).toHaveCount(1);
    for (const row of await checks.locator('.page').getByRole('button').all()) await row.click();
    await expect(checks.getByText(/robots noindex/)).toHaveCount(0);
    await expect(checks.getByRole('alert')).toHaveCount(0);
    const publish = dialog.getByRole('button', { name: 'Pubblica', exact: true });
    await expect(publish).toBeEnabled();
    await shot(page, 'v1-sito-nascosto.png');
    await publish.click();
    await expect(dialog.getByRole('status').first()).toHaveText(/^Pubblicata la versione/);
    dropExpected(consoleErrors);
  } finally {
    option('blog_public', before.blog);
    option('xb_checks_block', before.block);
  }
});
