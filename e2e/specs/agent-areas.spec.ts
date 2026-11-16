import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Doc 26 of Histeon Publish, voci 209-221: the builder's public API for tools and agents, the media manager's
// compression in place and presets, what the agent may do on the site.

type Xb = {
  environment: { type: string; histeon: string; wordpress: string; version: string; framework: string };
  insert(html: string): string[];
  copy(uids: string[]): string;
  paste(clip: string, options?: { target?: string | null; classes?: 'rename' | 'keep' }): Promise<string[]>;
  getElementHtml(uid: string): string | null;
  setQuery(name: string, query: unknown): Promise<void>;
  getQuery(name: string): Promise<unknown>;
  setSource(name: string, data: unknown): Promise<void>;
  getSource(name: string): Promise<unknown>;
  uploadMedia(file: File, options?: { preset?: string }): Promise<{ id: number; url: string; alt: string; mime: string; bytes: number }>;
  setMediaAlt(id: number, alt: string): Promise<{ alt: string }>;
  listMedia(filter: { missingAlt?: boolean; search?: string }): Promise<{ items: Array<{ id: number }> }>;
  compressMedia(id: number, preset?: string): Promise<{ before: number; after: number; saved: boolean }>;
  getMedia(id: number): Promise<{ url: string; compressed: { before: number; after: number } | null }>;
};

test('API: ambiente, copia e incolla, query e sorgenti dell’agente', async ({ page, data }) => {
  await Builder.open(page, data.pages.api.id);
  const result = await page.evaluate(async () => {
    const xb = (window as unknown as { xb: Xb }).xb;
    const [uid] = xb.insert('<p class="copia-nota">Da copiare</p>');
    const clip = xb.copy([uid!]);
    const [pasted] = await xb.paste(clip, { target: uid! });
    const name = `prova_${Date.now().toString(36)}`;
    await xb.setQuery(name, { type: 'posts', args: { post_type: 'post', posts_per_page: '3' } });
    await xb.setSource(`${name}_dati`, [{ giorno: 'lunedì' }]);
    const query = await xb.getQuery(name);
    const source = await xb.getSource(`${name}_dati`);
    await xb.setQuery(name, null);
    await xb.setSource(`${name}_dati`, null);
    return { env: xb.environment, copied: JSON.parse(clip).markup as string, pasted: pasted ? xb.getElementHtml(pasted) : null, query, source, gone: await xb.getQuery(name) };
  });
  expect(result.env.version).toBe('dev');
  expect(['local', 'development', 'staging', 'production']).toContain(result.env.type);
  expect(result.env.histeon).not.toBe('');
  expect(result.copied).toContain('Da copiare');
  expect(result.pasted).toContain('Da copiare');
  expect(result.query).toMatchObject({ type: 'posts' });
  expect(result.source).toEqual([{ giorno: 'lunedì' }]);
  // A forbidden argument is refused by the server (AgentAreasTest): here it would only log a 400 in the console.
  expect(result.gone).toBeNull();
});

test('media: caricare dall’API, testo alternativo, comprimere sul posto e ripristinare', async ({ page, data }) => {
  await Builder.open(page, data.pages.api.id);
  const id = Date.now().toString(36);
  const result = await page.evaluate(async (id) => {
    const xb = (window as unknown as { xb: Xb }).xb;
    // A noisy 1200×800 JPEG at high quality: there is weight to gain.
    const canvas = document.createElement('canvas');
    canvas.width = 1200;
    canvas.height = 800;
    const ctx = canvas.getContext('2d')!;
    for (let i = 0; i < 3000; i++) {
      ctx.fillStyle = `rgb(${(i * 37) % 255},${(i * 91) % 255},${(i * 53) % 255})`;
      ctx.fillRect((i * 97) % 1200, (i * 61) % 800, 18, 18);
    }
    const blob = await new Promise<Blob>((done) => canvas.toBlob((b) => done(b!), 'image/jpeg', 0.98));
    const item = await xb.uploadMedia(new File([blob], `agente-${id}.jpg`, { type: 'image/jpeg' }), { preset: 'original' });
    const missing = (await xb.listMedia({ missingAlt: true, search: `agente-${id}` })).items.map((i) => i.id);
    const alt = (await xb.setMediaAlt(item.id, 'Quadratini colorati')).alt;
    const done = await xb.compressMedia(item.id, 'light');
    const after = await xb.getMedia(item.id);
    return { item, missing, alt, done, after };
  }, id);
  expect(result.item.mime).toBe('image/jpeg');
  expect(result.missing).toContain(result.item.id);
  expect(result.alt).toBe('Quadratini colorati');
  expect(result.done.saved).toBe(true);
  expect(result.done.after).toBeLessThan(result.done.before);
  expect(result.after.url).toBe(result.item.url);
  expect(result.after.compressed?.after).toBe(result.done.after);

  // In the media manager: the compression is shown, and the original comes back.
  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Libreria dei media' }).click();
  const manager = page.getByRole('dialog', { name: 'Gestore dei media' });
  await manager.getByLabel('Cerca nella libreria').fill(`agente-${id}`);
  const tile = manager.locator('ul.grid > li').first();
  await expect(tile).toBeVisible({ timeout: 15_000 });
  await tile.getByRole('button').first().click();
  const details = manager.getByRole('complementary', { name: /Dettagli/ });
  await expect(details.getByText(/Compressa: da/)).toBeVisible();
  await details.getByRole('button', { name: 'Ripristina l’originale' }).click();
  await expect(details.getByText('Rimesso l’originale.')).toBeVisible();
  await expect(details.getByText(/Compressa: da/)).toHaveCount(0);
});

test('preset del sito: si aggiunge, resta scelto e si toglie', async ({ page, data }) => {
  await Builder.open(page, data.pages.api.id);
  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Libreria dei media' }).click();
  const manager = page.getByRole('dialog', { name: 'Gestore dei media' });
  await manager.getByRole('button', { name: 'Preset…' }).click();
  const presets = manager.getByRole('region', { name: 'Preset del sito' });
  await presets.getByLabel('Nome').fill('Blog e2e');
  await presets.getByLabel('Lato più lungo').fill('1200');
  await presets.getByRole('button', { name: 'Aggiungi il preset' }).click();
  await expect(presets.getByText('Blog e2e')).toBeVisible();
  await expect(manager.getByLabel('Compressione prima del caricamento')).toHaveValue('blog-e2e');
  await page.reload();
  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Libreria dei media' }).click();
  await expect(manager.getByLabel('Compressione prima del caricamento')).toHaveValue('blog-e2e');
  await manager.getByRole('button', { name: 'Preset…' }).click();
  await presets.getByRole('button', { name: 'Togli il preset Blog e2e' }).click();
  await expect(presets.getByText('Blog e2e')).toHaveCount(0);
});

test('cosa può fare l’agente: un amministratore accende e spegne le aree', async ({ page, data }) => {
  await Builder.open(page, data.pages.api.id);
  await page.getByRole('button', { name: 'Agente AI', exact: true }).click();
  const panel = page.getByRole('dialog', { name: 'Connettore AI' });
  await panel.getByText('Cosa può fare l\'agente').click();
  const contents = panel.getByLabel(/Contenuti e campi/);
  await expect(contents).not.toBeChecked();
  await contents.check();
  await expect(panel.getByText('✓ Salvato')).toBeVisible();
  const saved = await page.evaluate(async () => {
    const config = (window as unknown as { xbBuilder: { restUrl: string; nonce: string } }).xbBuilder;
    const r = await fetch(`${config.restUrl}agent-scopes`, { headers: { 'X-WP-Nonce': config.nonce }, credentials: 'same-origin' });
    return ((await r.json()) as { enabled: string[] }).enabled;
  });
  expect(saved).toContain('contenuti');
  await contents.uncheck();
  await expect(contents).not.toBeChecked();
});

test('i termini di una tassonomia si gestiscono nel builder (voce 212)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('Campi');
  const id = Date.now().toString(36);
  await page.getByRole('button', { name: /^Categorie/ }).click();
  await page.getByRole('tab', { name: 'Termini' }).click();
  const table = page.getByRole('region', { name: 'Termini di Categorie' });
  await table.getByLabel('Nome del nuovo termine').fill(`Padre ${id}`);
  await table.getByRole('button', { name: '+ Aggiungi' }).click();
  await expect(table.getByLabel(`Nome di Padre ${id}`)).toBeVisible();
  await table.getByLabel('Nome del nuovo termine').fill(`Figlio ${id}`);
  await table.getByLabel('Genitore del nuovo termine').selectOption({ label: `Padre ${id}` });
  await table.getByRole('button', { name: '+ Aggiungi' }).click();
  await table.getByLabel('Cerca in Categorie').fill(id);
  const child = table.getByLabel(`Nome di Figlio ${id}`);
  await expect(child).toBeVisible();
  await expect(table.getByLabel(`Genitore di Figlio ${id}`)).toHaveValue(/\d+/);
  await child.fill(`Figlia ${id}`);
  await child.press('Tab');
  await expect(table.getByLabel(`Nome di Figlia ${id}`)).toBeVisible();
  // A term never becomes the parent of its own parent.
  await expect(table.getByLabel(`Genitore di Padre ${id}`).locator('option', { hasText: `Figlia ${id}` })).toHaveCount(0);
  for (const name of [`Figlia ${id}`, `Padre ${id}`]) {
    page.once('dialog', (d) => void d.accept());
    await table.getByRole('row').filter({ has: page.getByLabel(`Nome di ${name}`) }).getByRole('button', { name: 'Elimina' }).click();
    await expect(table.getByLabel(`Nome di ${name}`)).toHaveCount(0);
  }
});

test('un gruppo di campi va nei tipi scelti (voce 213)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('Campi');
  const id = Date.now().toString(36);
  await page.getByLabel('Nome del nuovo gruppo di campi').fill(`Contatti ${id}`);
  await page.getByLabel('Nome del nuovo gruppo di campi').press('Enter');
  const group = page.getByRole('region', { name: `Gruppo di campi Contatti ${id}` });
  await group.getByRole('group', { name: 'Tipi del gruppo' }).getByLabel('Articoli').check();
  await group.getByLabel('Etichetta del nuovo campo').fill('Email di contatto');
  await group.getByLabel('Tipo del nuovo campo').selectOption('email');
  await group.getByRole('button', { name: '+ Campo' }).click();
  await page.getByRole('button', { name: /^Salva tipi e campi/ }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Salvato in sviluppo' })).toBeVisible();
  const saved = await builder.api<{ content: { groups: Record<string, { types: string[] }>; fields: Record<string, Array<{ name: string; group?: string }>> } }>('GET', 'content');
  const name = Object.keys(saved.content.groups).find((g) => g.startsWith('contatti'))!;
  expect(saved.content.groups[name]!.types).toEqual(['post']);
  expect(saved.content.fields.post).toEqual(expect.arrayContaining([expect.objectContaining({ name: 'email_di_contatto', group: name })]));
  // Taken out again, so the dev site stays as it was.
  await page.getByRole('button', { name: new RegExp(`^Contatti ${id}`) }).click();
  await group.getByRole('button', { name: 'Elimina il gruppo' }).click();
  await page.getByRole('button', { name: /^Salva tipi e campi/ }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Salvato in sviluppo' })).toBeVisible();
});
