import { execFile, spawn, type ChildProcess } from 'node:child_process';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

const SCRIPT = fileURLToPath(new URL('../../plugin/connector/histos-connector.mjs', import.meta.url));

/** The agent's side: the CLI, as an agent would run it. */
/** The pairing as the panel's instructions give it: printf '%s' '<secret>' | … pair --origin <origin>. */
async function pairing(page: import('@playwright/test').Page): Promise<{ origin: string; secret: string }> {
  const text = (await page.getByRole('dialog', { name: 'Connettore AI' }).locator('pre').first().textContent()) ?? '';
  const m = /printf '%s' '(\S+)' \| node histos-connector\.mjs pair --origin (\S+)/.exec(text);
  if (!m) throw new Error(`no pairing in: ${text}`);
  return { secret: m[1]!, origin: m[2]! };
}

/** Pairs through stdin, as the instructions do, then starts serve with no secret on its command line. */
async function serve(dir: string, pair: { origin: string; secret: string }): Promise<ChildProcess> {
  await new Promise<void>((resolve, reject) => {
    const child = execFile(process.execPath, [SCRIPT, 'pair', '--dir', dir, '--origin', pair.origin], (e) => (e ? reject(e) : resolve()));
    child.stdin!.end(pair.secret);
  });
  const server = spawn(process.execPath, [SCRIPT, 'serve', '--dir', dir], { stdio: 'pipe' });
  await new Promise<void>((resolve) => server.stderr!.on('data', (d: Buffer) => d.toString().includes('in ascolto') && resolve()));
  return server;
}

const agent = (dir: string, ...args: string[]) =>
  new Promise<{ code: number; out: string; err: string }>((resolve) =>
    execFile(process.execPath, [SCRIPT, ...args, '--dir', dir], { timeout: 30_000 }, (e, out, err) => resolve({ code: e ? Number(e.code ?? 1) : 0, out, err })),
  );

test('connettore AI: un agente locale lavora nella scheda aperta, dal vivo, solo dopo "Collega"', async ({ page, data }) => {
  // Many CLI round trips (a process each): slower than the other specs.
  test.slow();
  const dir = mkdtempSync(join(tmpdir(), 'xbc-e2e-'));
  let server: ChildProcess | null = null;
  try {
    const builder = await Builder.open(page, data.pages.api.id);
    await page.getByRole('button', { name: 'Agente AI' }).click();
    const panel = page.getByRole('dialog', { name: 'Connettore AI' });
    await expect(panel.locator('pre').first()).toContainText('histos-connector.mjs pair --origin');

    // A program on the port that does not know the pairing: the builder refuses it.
    const { createServer } = await import('node:http');
    const impostor = createServer((req, res) => {
      res.writeHead(200, { 'Access-Control-Allow-Origin': req.headers.origin ?? '*', 'Access-Control-Allow-Headers': 'Content-Type', 'Access-Control-Allow-Private-Network': 'true', 'Content-Type': 'application/json' });
      res.end(req.method === 'OPTIONS' ? '' : JSON.stringify({ tab: 't1', key: 'k', proof: 'falso' }));
    });
    await new Promise<void>((r) => impostor.listen(7341, '127.0.0.1', r));
    await panel.getByRole('button', { name: 'Collega questa scheda' }).click();
    await expect(panel.getByRole('alert')).toContainText('non conosce l’accoppiamento');
    await new Promise((r) => impostor.close(r));

    server = await serve(dir, await pairing(page));
    // Not connected until the user says so.
    expect((await agent(dir, 'html')).err).toContain('Collega');
    await panel.getByRole('button', { name: 'Collega questa scheda' }).click();
    await expect(panel.getByRole('status')).toHaveText('Collegato');

    const tabs = JSON.parse((await agent(dir, 'tabs')).out) as Array<{ title: string; doc: number }>;
    expect(tabs).toEqual([expect.objectContaining({ doc: data.pages.api.id })]);
    const id = Date.now().toString(36);
    const inserted = await agent(dir, 'insert', `<p class="agente-${id}">Scritto dall'agente</p>`);
    expect(inserted.code, inserted.err).toBe(0);
    await expect(builder.canvas.locator(`p.agente-${id}`)).toHaveText("Scritto dall'agente");
    expect((await agent(dir, 'set-css', `.agente-${id}`, 'color: rgb(200, 0, 0);')).code).toBe(0);
    await expect(builder.canvas.locator(`p.agente-${id}`)).toHaveCSS('color', 'rgb(200, 0, 0)');
    expect((await agent(dir, 'html')).out).toContain(`agente-${id}`);
    // The answer carries the CSS panel's lint (I1); the Checks panel is a tool too (I2).
    const warned = JSON.parse((await agent(dir, 'set-css', `.agente-${id}`, 'color: var(--inventata);')).out) as { warnings: Array<{ code: string }> };
    expect(warned.warnings.map((w) => w.code)).toContain('unknown-variable');
    expect(JSON.parse((await agent(dir, 'set-css', `.agente-${id}`, 'color: rgb(200, 0, 0);')).out).warnings).toEqual([]);
    const checks = JSON.parse((await agent(dir, 'checks')).out) as Array<{ code: string; severity: string }>;
    expect(Array.isArray(checks)).toBe(true);
    // What the person sees (lista della spesa 159): the selected element, its selectors, CSS and computed styles.
    // Selected through the agent: a click in the canvas would close the connector panel.
    const [newUid] = (JSON.parse(inserted.out) as { uids: string[] }).uids;
    expect((await agent(dir, 'select', newUid!)).code).toBe(0);
    const view = JSON.parse((await agent(dir, 'view')).out) as { canvas: { width: number }; selection: { selectors: string[]; styles: Array<{ css: string }>; computed: Record<string, string>; box: { width: number } } };
    expect(view.selection.selectors).toEqual([`.agente-${id}`]);
    expect(view.selection.styles[0]!.css).toContain('rgb(200, 0, 0)');
    expect(view.selection.computed.color).toBe('rgb(200, 0, 0)');
    expect(view.selection.box.width).toBeGreaterThan(0);
    expect(view.canvas.width).toBeGreaterThan(0);
    // The agent learns the site and the method: framework variables, BEM blocks in use, the rules.
    const brief = await agent(dir, 'brief');
    expect(brief.out).toContain('## Framework CSS');
    // The names loops can read (I3), apart from the media queries (I4).
    expect(brief.out).toContain('## Query dei loop');
    expect(brief.out).toContain('## Media query con nome (per @media, non per i loop)');
    expect(brief.out).toContain(`.agente-${id}`);
    expect((await agent(dir, 'guide', 'metodo')).out).toContain('BEM');
    expect((await agent(dir, 'manual')).out).toContain('Una classe per blocco');
    await expect(panel.getByText('insert').first()).toBeVisible();

    // Builder errors come back as exit code 2; undo works like a hand edit; save goes to dev.
    expect((await agent(dir, 'remove', 'inesistente')).code).toBe(2);
    expect((await agent(dir, 'call', 'eval', '{"code":"alert(1)"}')).code).toBe(1);
    expect((await agent(dir, 'undo')).code).toBe(0);
    await expect(builder.canvas.locator(`p.agente-${id}`)).toHaveCSS('color', /^(?!rgb\(200, 0, 0\))/);
    expect((await agent(dir, 'save')).code).toBe(0);
    await expect(builder.status).toHaveText(/^Salvato/);

    // Fine operations (I6): one text, one attribute, without sending the subtree again.
    const uid = (JSON.parse(inserted.out) as { uids: string[] }).uids[0]!;
    expect((await agent(dir, 'text', uid, 'Ritoccato dall\'agente')).code).toBe(0);
    expect((await agent(dir, 'attr', uid, 'title', 'Nota')).code).toBe(0);
    expect((await agent(dir, 'element', uid)).out).toContain('title="Nota"');
    expect((await agent(dir, 'element', uid)).out).toContain('Ritoccato');

    // Building with method: BEM classes, then a component from the element.
    const made = JSON.parse((await agent(dir, 'insert', `<div class="pack-${id}"><h2>Titolo</h2><p>Testo</p></div>`)).out) as { uids: string[] };
    const bem = JSON.parse((await agent(dir, 'bem', made.uids[0]!)).out) as { added: string[] };
    expect(bem.added).toEqual([`pack-${id}__title`, `pack-${id}__text`]);
    expect((await agent(dir, 'component', made.uids[0]!, `pack-${id}`, `Pacchetto ${id}`)).code).toBe(0);
    const components = await builder.api<Array<{ ref: string }>>('GET', 'components');
    expect(components.map((c) => c.ref)).toContain(`pack-${id}`);
    await panel.getByRole('button', { name: 'Scollega' }).click();
    await expect(panel.getByRole('status')).toHaveText('Scollegato');
    await expect.poll(async () => (await agent(dir, 'tabs')).out.trim()).toBe('[]');
  } finally {
    server?.kill();
    rmSync(dir, { recursive: true, force: true });
  }
});

test('connettore: la pagina come file, sincronizzata nei due sensi con il builder (VS Code)', async ({ page, data }) => {
  const dir = mkdtempSync(join(tmpdir(), 'xbc-sync-'));
  const folder = mkdtempSync(join(tmpdir(), 'xbc-files-'));
  let server: ChildProcess | null = null;
  let sync: ChildProcess | null = null;
  try {
    const builder = await Builder.open(page, data.pages.api.id);
    await page.getByRole('button', { name: 'Agente AI' }).click();
    const panel = page.getByRole('dialog', { name: 'Connettore AI' });
    server = await serve(dir, await pairing(page));
    await panel.getByRole('button', { name: 'Collega questa scheda' }).click();
    await expect(panel.getByRole('status')).toHaveText('Collegato');
    const id = Date.now().toString(36);
    expect((await agent(dir, 'set-html', `<section class="file-${id}"><h2>Dal builder</h2></section>`)).code).toBe(0);
    expect((await agent(dir, 'set-css', `.file-${id}`, 'color: rgb(0, 0, 200);')).code).toBe(0);

    sync = spawn(process.execPath, [SCRIPT, 'sync', folder, '--dir', dir], { stdio: 'pipe' });
    await expect.poll(() => readdirSync(folder).find((f) => f.startsWith('page-')) ?? '', { timeout: 15_000 }).not.toBe('');
    const docDir = join(folder, readdirSync(folder).find((f) => f.startsWith('page-'))!);
    await expect.poll(() => existsSync(join(docDir, `file-${id}.css`))).toBe(true);
    expect(readFileSync(join(docDir, 'page.html'), 'utf8')).toContain('Dal builder');
    expect(readFileSync(join(docDir, `file-${id}.css`), 'utf8')).toBe(`.file-${id} {\n  color: rgb(0, 0, 200);\n}\n`);

    // Saved in the editor: the builder follows at once.
    writeFileSync(join(docDir, `file-${id}.css`), `.file-${id} {\n  color: rgb(0, 150, 0);\n}\n`);
    await expect(builder.canvas.locator(`section.file-${id}`)).toHaveCSS('color', 'rgb(0, 150, 0)');
    writeFileSync(join(docDir, 'page.html'), `<section class="file-${id}"><h2>Dal file</h2></section>`);
    await expect(builder.canvas.getByRole('heading', { name: 'Dal file' })).toBeVisible();

    // Changed in the builder: the file follows.
    expect((await agent(dir, 'insert', '<p>Aggiunto nel builder</p>')).code).toBe(0);
    await expect.poll(() => readFileSync(join(docDir, 'page.html'), 'utf8'), { timeout: 10_000 }).toContain('Aggiunto nel builder');
    await panel.getByRole('button', { name: 'Scollega' }).click();
    await expect(panel.getByRole('status')).toHaveText('Scollegato');
  } finally {
    sync?.kill();
    server?.kill();
    rmSync(dir, { recursive: true, force: true });
    rmSync(folder, { recursive: true, force: true });
  }
});
