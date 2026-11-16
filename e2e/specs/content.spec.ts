import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('tipi e campi: un tipo di contenuto nuovo con i suoi campi si salva in sviluppo', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('Campi');
  const id = Date.now().toString(36);

  await page.getByRole('button', { name: '+ Tipo di contenuto' }).click();
  await page.getByLabel('Nome singolare').fill(`Corso ${id}`);
  await page.getByLabel('Plurale').fill(`Corsi ${id}`);
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  const type = page.getByRole('region', { name: `Tipo Corsi ${id}` });
  await expect(type).toBeVisible();

  // A reserved name never gets this far: WordPress's own types and query variables are refused while typing.
  await page.getByRole('button', { name: '+ Tassonomia' }).click();
  await page.getByLabel('Nome singolare').fill('Year');
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  await expect(page.getByRole('status').filter({ hasText: 'nome riservato' })).toBeVisible();
  await page.getByRole('button', { name: 'Annulla', exact: true }).click();

  await type.getByLabel('Etichetta del nuovo campo').fill('Durata in ore');
  await type.getByLabel('Tipo del nuovo campo').selectOption('number');
  await type.getByRole('button', { name: '+ Campo' }).click();
  await expect(type.getByLabel('Nome del campo Durata in ore')).toHaveValue('durata_in_ore');
  await expect(page.getByText('{this.fields.durata_in_ore}', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: /^Salva tipi e campi/ }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Salvato in sviluppo' })).toBeVisible();

  const saved = await builder.api<{ content: { types: Record<string, unknown>; fields: Record<string, Array<{ name: string; type: string }>> }; live: { types: Record<string, unknown> } }>('GET', 'content');
  expect(saved.content.types).toHaveProperty(`corso_${id}`);
  expect(saved.content.fields[`corso_${id}`]).toEqual([expect.objectContaining({ name: 'durata_in_ore', type: 'number' })]);
  expect(saved.live.types, 'nothing registered before a release').not.toHaveProperty(`corso_${id}`);
});

test('ripetitore: i campi di ogni riga nel builder, le righe nel pannello «Campi» dell\'editor a blocchi', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('Campi');
  const id = Date.now().toString(36);
  const slug = `tappa_${id}`;

  await page.getByRole('button', { name: '+ Tipo di contenuto' }).click();
  await page.getByLabel('Nome singolare').fill(`Tappa ${id}`);
  await page.getByLabel('Plurale').fill(`Tappe ${id}`);
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  const type = page.getByRole('region', { name: `Tipo Tappe ${id}` });
  await type.getByLabel('Etichetta del nuovo campo').fill('Programma');
  await type.getByLabel('Tipo del nuovo campo').selectOption('repeater');
  await type.getByRole('button', { name: '+ Campo' }).click();

  // A repeater starts with one text field per row; more are added here, with types a row can hold.
  const rows = type.getByRole('group', { name: 'Campi di ogni riga di Programma' });
  await expect(rows.getByLabel('Nome di Testo in Programma')).toHaveValue('testo');
  await expect(rows.getByLabel('Tipo del nuovo campo di Programma').locator('option')).toHaveText(['Testo', 'Testo lungo', 'Numero', 'Data', 'Ora', 'Sì / no', 'Scelta', 'Immagine', 'File', 'Indirizzo web', 'Ripetitore']);
  await rows.getByLabel('Etichetta del nuovo campo di Programma').fill('Ora');
  await rows.getByLabel('Tipo del nuovo campo di Programma').selectOption('number');
  await rows.getByRole('button', { name: '+ Campo della riga' }).click();
  await expect(rows.getByLabel('Nome di Ora in Programma')).toHaveValue('ora');
  // A repeater inside the row (D6): its own fields, without a third level.
  await rows.getByLabel('Etichetta del nuovo campo di Programma').fill('Soste');
  await rows.getByLabel('Tipo del nuovo campo di Programma').selectOption('repeater');
  await rows.getByRole('button', { name: '+ Campo della riga' }).click();
  const soste = rows.getByRole('group', { name: 'Campi di ogni riga di Soste' });
  await expect(soste.getByLabel('Nome di Testo in Soste')).toHaveValue('testo');
  await expect(soste.getByLabel('Tipo di Testo in Soste').locator('option')).not.toContainText(['Ripetitore']);
  await expect(page.getByText('{#loop this.fields.programma as r}{r.testo}{/loop}', { exact: true }).first()).toBeVisible();

  await page.getByRole('button', { name: /^Salva tipi e campi/ }).click();
  await expect(page.getByRole('status').filter({ hasText: 'Salvato in sviluppo' })).toBeVisible();
  const saved = await builder.api<{ content: { fields: Record<string, Array<{ name: string; sub: Array<{ name: string; type: string }> }>> } }>('GET', 'content');
  expect(saved.content.fields[slug]?.[0]?.sub).toEqual([
    expect.objectContaining({ name: 'testo', type: 'text' }),
    expect.objectContaining({ name: 'ora', type: 'number' }),
    expect.objectContaining({ name: 'soste', type: 'repeater', sub: [expect.objectContaining({ name: 'testo', type: 'text' })] }),
  ]);
  await builder.publishAll(`Ripetitore ${id}`);

  // The type exists in WordPress after the release: its posts have the "Campi" panel with the rows.
  await page.goto(`/wp-admin/post-new.php?post_type=${slug}`);
  await page.waitForFunction(() => {
    const wp = (window as unknown as { wp?: { data?: { select: (s: string) => { getCurrentPostId?: () => number } } } }).wp;
    return !!wp?.data?.select('core/editor').getCurrentPostId?.();
  });
  await page.evaluate(() => {
    const wp = (window as unknown as { wp: { data: { dispatch: (s: string) => Record<string, (...a: unknown[]) => unknown> } } }).wp;
    wp.data.dispatch('core/preferences').set?.('core/edit-post', 'welcomeGuide', false);
    wp.data.dispatch('core/editor').editPost?.({ title: 'Con programma', status: 'draft' });
    wp.data.dispatch('core/edit-post').openGeneralSidebar?.('edit-post/document');
  });
  const toggle = page.locator('.xb-fields-panel .components-panel__body-toggle');
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
  const panel = page.locator('.xb-fields-panel');
  // Each row has its own «Aggiungi riga» for the repeater inside it: the outer one comes last.
  const add = panel.getByRole('button', { name: 'Aggiungi riga' }).last();
  for (const [testo, ora] of [['Partenza', '9'], ['Arrivo', '18'], ['Da togliere', '1']] as const) {
    await add.click();
    const row = panel.locator('.xb-field-rows > .xb-field-row').last();
    await row.getByLabel('Testo').first().fill(testo);
    await row.getByLabel('Ora').fill(ora);
  }
  // A stop inside the first row (D6).
  const first = panel.locator('.xb-field-rows > .xb-field-row').first();
  await first.getByRole('button', { name: 'Aggiungi riga' }).click();
  await first.getByRole('group', { name: 'Soste 1' }).getByLabel('Testo').fill('Sosta a metà');
  await panel.getByRole('group', { name: 'Programma 3' }).getByRole('button', { name: 'Rimuovi la riga' }).click();
  await panel.getByRole('group', { name: 'Programma 2' }).getByRole('button', { name: 'Sposta su' }).click();
  await expect(panel.getByRole('group', { name: 'Programma 1' }).getByLabel('Testo').first()).toHaveValue('Arrivo');

  const meta = await page.evaluate(async () => {
    const wp = (window as unknown as { wp: { data: { select: (s: string) => Record<string, () => unknown>; dispatch: (s: string) => Record<string, () => Promise<unknown>> }; apiFetch: (o: { path: string }) => Promise<{ meta: Record<string, unknown> }> } }).wp;
    await wp.data.dispatch('core/editor').savePost?.();
    const post = wp.data.select('core/editor').getCurrentPost?.() as { id: number; type: string };
    return (await wp.apiFetch({ path: `/wp/v2/${post.type}/${post.id}?context=edit` })).meta; // XB types: rest_base is the name.
  });
  expect(meta.programma, 'rows saved in their order, each with exactly its sub fields').toEqual([
    { testo: 'Arrivo', ora: 18, soste: [] },
    { testo: 'Partenza', ora: 9, soste: [{ testo: 'Sosta a metà' }] },
  ]);

  // The same rows from the builder's table of contents (D6): edited in the row's details, saved with one button.
  const again = await Builder.open(page, data.pages.palette.id);
  await again.tab('Campi');
  await page.getByRole('button', { name: new RegExp(`^Tappe ${id}`) }).click();
  await page.getByRole('tab', { name: 'Contenuti' }).click();
  const line = page.getByRole('row').filter({ has: page.getByLabel('Titolo di Con programma') });
  await line.locator('td.others button').click();
  const detail = page.locator('tr.detail');
  const outer = detail.locator('.rows:not(.nested) > .row');
  await expect(outer).toHaveCount(2);
  await outer.first().getByLabel('Testo').first().fill('Arrivo in piazza');
  await outer.first().getByLabel('Testo').first().blur();
  await outer.nth(1).locator('.rows.nested > .bar').getByRole('button', { name: '+ Riga' }).click();
  await outer.nth(1).locator('.rows.nested > .row').last().getByLabel('Testo').fill('Seconda sosta');
  await outer.nth(1).locator('.rows.nested > .row').last().getByLabel('Testo').blur();
  await detail.getByRole('button', { name: 'Salva le righe' }).click();
  await expect(detail.getByRole('button', { name: 'Salva le righe' })).toHaveCount(0);
  const programma = () => page.evaluate(async (route) => {
    const nonce = (window as unknown as { xbBuilder?: { nonce?: string } }).xbBuilder?.nonce ?? '';
    const response = await fetch(`/wp-json/wp/v2/${route}?context=edit&search=Con%20programma&status=draft&_fields=meta`, { headers: { 'X-WP-Nonce': nonce } });
    return ((await response.json()) as Array<{ meta: Record<string, unknown> }>)[0]?.meta.programma;
  }, slug);
  // The button goes at the click, and the row is written just after it.
  await expect.poll(programma, { message: 'the table writes the rows through WordPress, nested rows included' }).toEqual([
    { testo: 'Arrivo in piazza', ora: 18, soste: [] },
    { testo: 'Partenza', ora: 9, soste: [{ testo: 'Sosta a metà' }, { testo: 'Seconda sosta' }] },
  ]);
});
