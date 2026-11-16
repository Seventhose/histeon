import { execFile, spawn, type ChildProcess } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

/**
 * «Importa HTML» (doc 18 F6 of Histeon Publish): a page converted in the browser by Histeon Publish's own importer, on
 * SQLite for WebAssembly, becomes a `.xbsite` the server checks as any package; nothing is written before the plan's
 * confirmation, which this test does not give.
 */
const page = `<!doctype html><html lang="it"><head><title>Officina</title><style>.p-4{padding:1rem}.font-bold{font-weight:700}</style></head>
<body><main><section class="hero"><h1 class="font-bold">Ripara la tua bici</h1><p class="p-4">Attrezzi e consigli.</p></section></main></body></html>`;

test('Importa HTML: la pagina si converte nel browser e il sito mostra cosa scriverebbe', async ({ page: browser }) => {
  const previous = wpEval(`<?php echo (string) get_option( 'xb_page_profile_project_id', '' );`, {}).trim().split('\n').pop() ?? '';
  const projectId = previous || '7a1c2b9e-3d4a-4b5c-8d6e-7f8091a2b3c5';
  if (!previous) wpEval(`<?php update_option( 'xb_page_profile_project_id', '${projectId}' );`, {});
  try {
    await browser.goto('/wp-admin/admin.php?page=xb-releases');
    const panel = browser.locator('#xb-html-import');
    await expect(browser.getByRole('heading', { name: /Converti una pagina HTML/ })).toBeVisible();
    await panel.locator('[data-pages]').setInputFiles({ name: 'officina.html', mimeType: 'text/html', buffer: Buffer.from(page) });
    await panel.getByRole('button', { name: 'Converti' }).click();
    await expect(panel.locator('[data-measure]')).toContainText('Elementi selezionabili uno per uno nel builder: 100% (', { timeout: 60_000 });
    await expect(panel.locator('[data-notes]')).toContainText('classi utility convertite in');
    // One report by what happened (lista della spesa 163).
    await expect(panel.locator('[data-notes] > li > strong').first()).toHaveText(/^(Tolto|Portato nel JavaScript della pagina|Media query e breakpoint|Da controllare) \(\d+\)$/);
    const prototype = panel.getByRole('link', { name: 'Scarica il prototipo' });
    await expect(prototype).toHaveAttribute('download', 'officina-prototipo.html');
    await panel.getByRole('button', { name: 'Prosegui al controllo' }).click();
    // The plan opens at the top of the page (lista P10).
    await expect(browser.getByRole('heading', { name: 'Importare il progetto «officina»?' })).toBeVisible();
    await expect(browser.getByText('Dal file officina.xbsite. Ecco cosa cambierebbe; nulla è ancora stato scritto.')).toBeVisible();
    await expect(browser.getByRole('button', { name: 'Importa in sviluppo' })).toBeVisible();
    await browser.getByRole('button', { name: 'Annulla' }).click();
  } finally {
    if (!previous) wpEval(`<?php delete_option( 'xb_page_profile_project_id' );`, {});
  }
});

// Lista della spesa 167: a Histeon prototype in one file goes in as it is (templates, components, contents, loops),
// into the site's own project, never converted as a plain page.
test('Importa HTML: un prototipo Histeon in un file si importa così com’è', async ({ page: browser }) => {
  const previous = wpEval(`<?php echo (string) get_option( 'xb_page_profile_project_id', '' );`, {}).trim().split('\n').pop() ?? '';
  const projectId = previous || '7a1c2b9e-3d4a-4b5c-8d6e-7f8091a2b3c5';
  if (!previous) wpEval(`<?php update_option( 'xb_page_profile_project_id', '${projectId}' );`, {});
  try {
    await browser.goto('/wp-admin/admin.php?page=xb-releases');
    const panel = browser.locator('#xb-html-import');
    const prototype = readFileSync(new URL('../fixtures/prototipo-esempio.html', import.meta.url));
    await panel.locator('[data-pages]').setInputFiles({ name: 'galleria.html', mimeType: 'text/html', buffer: prototype });
    await panel.getByRole('button', { name: 'Converti' }).click();
    await expect(panel.locator('[data-notes]')).toContainText('Prototipo Histeon: importato così com', { timeout: 60_000 });
    await panel.getByRole('button', { name: 'Prosegui al controllo' }).click();
    await expect(browser.getByText(/Ecco cosa cambierebbe; nulla è ancora stato scritto/)).toBeVisible();
    await expect(browser.getByRole('button', { name: 'Importa in sviluppo' })).toBeVisible();
    await browser.getByRole('button', { name: 'Annulla' }).click();
  } finally {
    if (!previous) wpEval(`<?php delete_option( 'xb_page_profile_project_id' );`, {});
  }
});

/**
 * «Rifinisci i nomi con un modello» (doc 27 of Histeon Publish, G4). The provider is faked by a mu-plugin that answers
 * the Claude API's address with `pre_http_request`: a name for every class and component of the batch, an alternative
 * text for every image. The names are shown «prima → dopo» and applied only on request; alternative texts never by
 * themselves.
 */
const fakeProvider = `<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( 'https://api.anthropic.com/v1/messages' !== $url ) {
		return $pre;
	}
	$body = json_decode( (string) $args['body'], true );
	$text = (string) ( $body['messages'][0]['content'] ?? '' );
	$data = preg_match( '~<<<DATI\\n([\\s\\S]*)\\nDATI>>>~', $text, $m ) ? json_decode( $m[1], true ) : array();
	$out  = array();
	foreach ( (array) $data as $i => $choice ) {
		$kind = (string) ( $choice['tipo'] ?? '' );
		if ( 'classe' === $kind ) {
			$out[] = array( 'id' => $choice['id'], 'valore' => 'rifinito-' . ( $i + 1 ) . '__parte' );
		} elseif ( 'componente' === $kind ) {
			$out[] = array( 'id' => $choice['id'], 'valore' => 'scheda-rifinita-' . ( $i + 1 ) );
		} elseif ( 'alt' === $kind ) {
			$out[] = array( 'id' => $choice['id'], 'valore' => 'Una bicicletta in officina' );
		}
	}
	$reply = array( 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => wp_json_encode( array( 'scelte' => $out ) ) ) ) );
	return array( 'headers' => array(), 'body' => wp_json_encode( $reply ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );
`;

const generic = `<!doctype html><html lang="it"><head><title>Ciclofficina</title><style>.wrap{max-width:60rem}.wrap div{padding:1rem}</style></head>
<body><main><section class="wrap"><div><h2>Prossimi corsi</h2><div><p>Impara a cambiare una camera d’aria.</p><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" width="40" height="40"></div></div>
<div><h2>Orari</h2><div><p>Aperti il sabato mattina.</p></div></div></section></main></body></html>`;

test('Importa HTML: senza chiave la casella del modello è spenta e dice perché', async ({ page: browser }) => {
  wpEval(`<?php \\XB\\Import\\ChoicesProvider::save( 'haiku', 'http://127.0.0.1:18080', 'qwen3-8b', '', '', array( 'haiku' ) );`, {});
  await browser.goto('/wp-admin/admin.php?page=xb-releases');
  const panel = browser.locator('#xb-html-import');
  await expect(panel.getByLabel('Rifinisci i nomi con un modello')).toBeDisabled();
  await expect(panel).toContainText('Manca la chiave API di Anthropic');
  wpEval(`<?php delete_option( 'xb_import_choices' );`, {});
});

test('Importa HTML: rifinisci i nomi con un modello', async ({ page: browser }) => {
  const previous = wpEval(`<?php echo (string) get_option( 'xb_page_profile_project_id', '' );`, {}).trim().split('\n').pop() ?? '';
  const projectId = previous || '7a1c2b9e-3d4a-4b5c-8d6e-7f8091a2b3c5';
  if (!previous) wpEval(`<?php update_option( 'xb_page_profile_project_id', '${projectId}' );`, {});
  const encoded = Buffer.from(fakeProvider).toString('base64');
  wpEval(`<?php wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/xb-e2e-fornitore-finto.php', base64_decode( '${encoded}' ) );`, {});
  wpEval(`<?php \\XB\\Import\\ChoicesProvider::save( 'haiku', 'http://127.0.0.1:18080', 'qwen3-8b', 'sk-ant-finta-123456', '' );`, {});
  try {
    await browser.goto('/wp-admin/admin.php?page=xb-releases');
    const panel = browser.locator('#xb-html-import');
    const toggle = panel.getByLabel('Rifinisci i nomi con un modello');
    await expect(toggle).toBeEnabled();
    await toggle.check();
    await expect(panel.locator('[data-refine-cancel]')).toBeHidden();
    await panel.locator('[data-pages]').setInputFiles({ name: 'ciclofficina.html', mimeType: 'text/html', buffer: Buffer.from(generic) });
    await panel.getByRole('button', { name: 'Converti' }).click();
    const view = panel.locator('[data-refine-view]');
    await expect(panel.locator('[data-error]')).toBeHidden();
    const rows = view.locator('[data-refine-table] tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 60_000 });
    await expect(view).toContainText('Proposte di claude-haiku-5-5');
    await expect(rows.first().locator('td').nth(2)).toContainText(/^(rifinito-\d+__parte|scheda-rifinita-\d+)$/);
    // An alternative text is a suggestion: offered, not ticked.
    const alts = view.locator('[data-refine-alts] input[type="checkbox"]');
    await expect(alts.first()).not.toBeChecked();
    // On a phone the table stays on the screen: the box neither leaves it nor scrolls sideways. Measured once wp-admin
    // has laid the page out again after the resize. (Other notices of the screen are not this test's.)
    await browser.setViewportSize({ width: 390, height: 844 });
    await expect
      .poll(
        () =>
          view.evaluate((box) => {
            const scroll = box.querySelector<HTMLElement>('.xb-refine__scroll');
            return { right: Math.round(box.getBoundingClientRect().right) <= window.innerWidth, table: !scroll || scroll.scrollWidth <= scroll.clientWidth };
          }),
        { timeout: 5_000 },
      )
      .toEqual({ right: true, table: true });
    await browser.setViewportSize({ width: 1440, height: 900 });
    await view.getByRole('button', { name: 'Applica le scelte segnate' }).click();
    await expect(view.locator('[data-refine-applied]')).toContainText('Nomi scelti nel prototipo:', { timeout: 60_000 });
    await expect(alts.first()).not.toBeChecked();
    const prototype = panel.getByRole('link', { name: 'Scarica il prototipo' });
    await expect(prototype).toHaveAttribute('download', 'ciclofficina-prototipo.html');
    const href = await prototype.getAttribute('href');
    const html = await browser.evaluate(async (url) => (await fetch(url ?? '')).text(), href);
    expect(html).toMatch(/rifinito-\d+__parte|scheda-rifinita-\d+/);
    expect(html).not.toContain('Una bicicletta in officina');
    await panel.getByRole('button', { name: 'Prosegui al controllo' }).click();
    await expect(browser.getByText(/Ecco cosa cambierebbe; nulla è ancora stato scritto/)).toBeVisible();
    await browser.getByRole('button', { name: 'Annulla' }).click();
  } finally {
    wpEval(`<?php @unlink( WPMU_PLUGIN_DIR . '/xb-e2e-fornitore-finto.php' ); delete_option( 'xb_import_choices' );`, {});
    if (!previous) wpEval(`<?php delete_option( 'xb_page_profile_project_id' );`, {});
  }
});

/**
 * «Claude dal connettore»: the page waits for the agent through the Histeon Connector (the real program, paired as the
 * builder's instructions do), and the agent reads the question and answers with the connector's own commands. The
 * plugin checks the answer with the shared rules; nothing is asked by the server.
 */
const CONNECTOR = fileURLToPath(new URL('../../plugin/connector/histos-connector.mjs', import.meta.url));
const connector = (dir: string, ...args: string[]) =>
  new Promise<{ code: number; out: string; err: string }>((resolve) =>
    execFile(process.execPath, [CONNECTOR, ...args, '--dir', dir], { timeout: 60_000 }, (e, out, err) => resolve({ code: e ? Number(e.code ?? 1) : 0, out, err })),
  );

test('Importa HTML: Claude dal connettore sceglie i nomi, la pagina aspetta e il plugin controlla', async ({ page: browser, consoleErrors }) => {
  test.slow();
  const previous = wpEval(`<?php echo (string) get_option( 'xb_page_profile_project_id', '' );`, {}).trim().split('\n').pop() ?? '';
  const projectId = previous || '7a1c2b9e-3d4a-4b5c-8d6e-7f8091a2b3c5';
  if (!previous) wpEval(`<?php update_option( 'xb_page_profile_project_id', '${projectId}' );`, {});
  // Nothing saved and no key: the connector is the default.
  wpEval(`<?php delete_option( 'xb_import_choices' );`, {});
  const dir = mkdtempSync(join(tmpdir(), 'xbc-import-'));
  let server: ChildProcess | null = null;
  try {
    await browser.goto('/wp-admin/admin.php?page=xb-releases');
    const panel = browser.locator('#xb-html-import');
    await expect(panel.locator('input[name="provider"][value="connettore"]')).toBeChecked();
    const toggle = panel.getByLabel('Rifinisci i nomi con un modello');
    await expect(toggle).toBeEnabled();
    await toggle.check();
    await panel.locator('[data-pages]').setInputFiles({ name: 'ciclofficina.html', mimeType: 'text/html', buffer: Buffer.from(generic) });
    const view = panel.locator('[data-refine-view]');

    // The connector is not running: said at once, and the names stay the converter's.
    await panel.getByRole('button', { name: 'Converti' }).click();
    await expect(view).toContainText('Il connettore di Histeon non risponde su questo computer', { timeout: 60_000 });
    await expect(panel.locator('[data-continue]')).toBeVisible();
    // Chrome reports the refused connection to 127.0.0.1 in the console: expected here, and only here.
    const refused = consoleErrors.filter((line) => line.includes('net::ERR_CONNECTION_REFUSED'));
    expect(refused.length).toBeGreaterThan(0);
    consoleErrors.splice(0, consoleErrors.length, ...consoleErrors.filter((line) => !refused.includes(line)));

    // Paired as the builder's instructions do (secret on stdin), then started.
    const config = JSON.parse((await panel.getAttribute('data-config')) ?? '{}') as { refine: { connector: { secret: string } } };
    const origin = await browser.evaluate(() => window.location.origin);
    await new Promise<void>((resolve, reject) => {
      const child = execFile(process.execPath, [CONNECTOR, 'pair', '--dir', dir, '--origin', origin], (e) => (e ? reject(e) : resolve()));
      child.stdin!.end(config.refine.connector.secret);
    });
    server = spawn(process.execPath, [CONNECTOR, 'serve', '--dir', dir], { stdio: 'pipe' });
    await new Promise<void>((resolve) => server!.stderr!.on('data', (d: Buffer) => d.toString().includes('in ascolto') && resolve()));

    // «Annulla» while the page waits.
    await panel.getByRole('button', { name: 'Converti' }).click();
    await expect(panel.locator('[data-status]')).toContainText('Rispondi alle scelte dei nomi di Histeon', { timeout: 60_000 });
    await panel.locator('[data-refine-cancel]').click();
    await expect(view).toContainText('fermato');
    await expect.poll(async () => (JSON.parse((await connector(dir, 'import-choices')).out) as { question: unknown }).question, { timeout: 10_000 }).toBeNull();

    // The agent reads the question and answers it.
    await panel.getByRole('button', { name: 'Converti' }).click();
    await expect(panel.locator('[data-status]')).toContainText('Rispondi alle scelte dei nomi di Histeon', { timeout: 60_000 });
    const read = await connector(dir, 'import-choices', '--wait', '20');
    expect(read.code, read.err).toBe(0);
    const { question } = JSON.parse(read.out) as { question: { requestId: string; count: number; system: string; choices: string } };
    expect(question.system).toContain('Dai nomi agli elementi');
    const data = JSON.parse(/<<<DATI\n([\s\S]*)\nDATI>>>/.exec(question.choices)![1]!) as Array<{ id: string; tipo: string; valore: string }>;
    expect(data.length).toBe(question.count);
    const scelte = data.map((choice, index) => ({
      id: choice.id,
      valore: choice.tipo === 'classe' ? `rifinito-${index + 1}__parte` : choice.tipo === 'componente' ? `scheda-rifinita-${index + 1}` : choice.tipo === 'alt' ? 'Una bicicletta in officina' : choice.valore,
    }));
    // A wrong id is refused by the page, and the question still waits.
    const wrong = await connector(dir, 'import-choices-answer', '00000000-0000-4000-8000-000000000000', JSON.stringify({ scelte }));
    expect(wrong.code).toBe(2);
    expect(wrong.err).toContain('Nessuna domanda in attesa con questo id');
    const answered = await connector(dir, 'import-choices-answer', question.requestId, JSON.stringify({ scelte }));
    expect(answered.code, answered.err).toBe(0);
    expect(JSON.parse(answered.out)).toMatchObject({ received: question.count });

    const rows = view.locator('[data-refine-table] tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 60_000 });
    await expect(view).toContainText('Proposte di Claude (dal connettore)');
    await expect(rows.first().locator('td').nth(2)).toContainText(/^(rifinito-\d+__parte|scheda-rifinita-\d+)$/);
    await expect(view.locator('[data-refine-alts] input[type="checkbox"]').first()).not.toBeChecked();
    // The page has left the connector.
    await expect.poll(async () => JSON.parse((await connector(dir, 'tabs')).out), { timeout: 10_000 }).toEqual([]);
    await view.getByRole('button', { name: 'Applica le scelte segnate' }).click();
    await expect(view.locator('[data-refine-applied]')).toContainText('Nomi scelti nel prototipo:', { timeout: 60_000 });
  } finally {
    server?.kill();
    rmSync(dir, { recursive: true, force: true });
    wpEval(`<?php delete_option( 'xb_import_choices' );`, {});
    if (!previous) wpEval(`<?php delete_option( 'xb_page_profile_project_id' );`, {});
  }
});
