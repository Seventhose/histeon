import { execFile, spawn, type ChildProcess } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

const framework = (id?: string) =>
  wpEval(`<?php ${id === undefined ? '' : id === '' ? "delete_option('xb_framework');" : `update_option('xb_framework', '${id.replace(/[^a-z-]/g, '')}');`} echo (string) get_option('xb_framework');`, {}).trim();

test('generatore di Histeon CSS nel builder: anteprima sulla tela, leggibilità, salvataggio in sviluppo', async ({ page, data }) => {
  const before = framework();
  try {
    framework('xb-tokens');
    const builder = await Builder.open(page, data.pages.palette.id);
    await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Design system' }).click();
    const panel = page.getByRole('dialog', { name: 'Design system' });
    await expect(panel.getByText('Generatore Histeon CSS')).toBeVisible();
    const root = page.frameLocator('iframe[title="Anteprima della pagina"]').locator('html');
    const token = (name: string) => root.evaluate((el, n) => getComputedStyle(el).getPropertyValue(n).trim(), name);
    const previewing = () => root.evaluate((el) => el.ownerDocument.getElementById('xb-design-live') !== null);

    // A colour: the canvas shows its tokens at once (a server-built preview, never saved yet).
    const primary = panel.getByLabel('Valore di Primario', { exact: true });
    await primary.fill('#e5533d');
    await primary.press('Enter');
    await expect.poll(() => token('--xb-primary')).toMatch(/^oklch\(63\.5% 0\.185 31\.2\)$/);
    await expect(panel.getByText('tutto leggibile')).toBeVisible();

    // A mid grey for the accent: neither white nor dark text reads on it.
    const accent = panel.getByLabel('Valore di Accento', { exact: true });
    await accent.fill('oklch(58% 0 0)');
    await accent.press('Enter');
    await expect(panel.getByText('1 da rivedere')).toBeVisible();
    await accent.fill('oklch(65% 0.2 300)');
    await accent.press('Enter');
    await expect(panel.getByText('tutto leggibile')).toBeVisible();

    // Saved in development with the token values, as one commit.
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('status')).toContainText('Salvato in sviluppo');
    const saved = await builder.api<{ design: Record<string, string> }>('GET', 'tokens');
    expect(saved.design.primary).toMatch(/^oklch\(/);
    expect(await previewing()).toBe(false);
    expect(await token('--xb-primary')).toMatch(/^oklch\(63\.5%/); // the canvas draws the saved value

    // Back to the defaults (after a confirmation), saved: the site as it was.
    await panel.getByRole('button', { name: 'Predefiniti' }).click();
    await panel.getByRole('button', { name: 'Sì' }).click();
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('status')).toContainText('Salvato in sviluppo');
  } finally {
    framework(before);
  }
});

const SCRIPT = fileURLToPath(new URL('../../plugin/connector/histos-connector.mjs', import.meta.url));
const agent = (dir: string, ...args: string[]) =>
  new Promise<{ code: number; out: string; err: string }>((resolve) =>
    execFile(process.execPath, [SCRIPT, ...args, '--dir', dir], { timeout: 60_000 }, (e, out, err) => resolve({ code: e ? Number(e.code ?? 1) : 0, out, err })),
  );

test("chiedi all'agente: la richiesta scritta nel builder arriva all'agente, che la esegue e la chiude con una nota", async ({ page, data }) => {
  const dir = mkdtempSync(join(tmpdir(), 'xbc-task-'));
  let server: ChildProcess | null = null;
  try {
    const builder = await Builder.open(page, data.pages.api.id);
    await page.getByRole('button', { name: 'Agente AI' }).click();
    const panel = page.getByRole('dialog', { name: 'Connettore AI' });
    const id = Date.now().toString(36);

    // The request waits in the tab until an agent asks for it.
    await panel.getByLabel('Descrivi la sezione da creare').fill(`Tre testimonianze ${id}`);
    await panel.getByRole('button', { name: 'Crea una sezione' }).click();
    await expect(panel.getByText('in attesa dell’agente')).toBeVisible();

    const text = (await panel.locator('pre').first().textContent()) ?? '';
    const m = /printf '%s' '(\S+)' \| node histos-connector\.mjs pair --origin (\S+)/.exec(text);
    expect(m).not.toBeNull();
    await new Promise<void>((resolve, reject) => {
      const child = execFile(process.execPath, [SCRIPT, 'pair', '--dir', dir, '--origin', m![2]!], (e) => (e ? reject(e) : resolve()));
      child.stdin!.end(m![1]!);
    });
    server = spawn(process.execPath, [SCRIPT, 'serve', '--dir', dir], { stdio: 'pipe' });
    await new Promise<void>((resolve) => server!.stderr!.on('data', (d: Buffer) => d.toString().includes('in ascolto') && resolve()));
    await panel.getByRole('button', { name: 'Collega questa scheda' }).click();
    await expect(panel.getByRole('status')).toHaveText('Collegato');

    // The agent picks it up: the user's words and the builder's instructions.
    const next = await agent(dir, 'next-task', '--wait', '20');
    expect(next.code, next.err).toBe(0);
    expect(next.out).toContain(`Tre testimonianze ${id}`);
    expect(next.out).toContain('Non salvare e non pubblicare');
    await expect(panel.getByText('l’agente ci lavora')).toBeVisible();

    // It does the work, then closes the request with a note the user reads in the panel.
    expect((await agent(dir, 'insert', `<section class="testimonianze-${id}"><h2>Dicono di noi</h2></section>`)).code).toBe(0);
    await expect(builder.canvas.locator(`section.testimonianze-${id} h2`)).toHaveText('Dicono di noi');
    expect((await agent(dir, 'done', 'r1', `Sezione testimonianze-${id} pronta`)).code).toBe(0);
    await expect(panel.getByText(`Sezione testimonianze-${id} pronta`)).toBeVisible();
    await expect(panel.getByText('fatta')).toBeVisible();
    expect((await agent(dir, 'next-task')).out).toContain('Nessuna richiesta in attesa');
    await panel.getByRole('button', { name: 'Scollega' }).click();
  } finally {
    server?.kill();
    rmSync(dir, { recursive: true, force: true });
  }
});
