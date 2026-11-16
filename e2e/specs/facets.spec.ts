import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('filtri dei loop: si creano, si provano come un visitatore e si inseriscono nella pagina', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.facets.id);
  const name = `e2e_${Date.now().toString(36)}`;
  await page.getByRole('button', { name: 'Filtri dei loop' }).click();
  await page.getByLabel('Nome dei nuovi filtri').fill(name);
  await page.getByRole('button', { name: 'Crea', exact: true }).click();
  const facet = page.getByRole('region', { name: `Filtri ${name}` });
  await facet.getByRole('combobox', { name: 'Tipo', exact: true }).selectOption('post');
  await facet.getByRole('button', { name: 'Ordinamento' }).click();

  // A parameter WordPress reads itself is refused before saving.
  await facet.getByRole('textbox', { name: 'Parametro', exact: true }).first().fill('s');
  await expect(facet.getByRole('alert')).toContainText('WordPress');
  await expect(page.getByRole('button', { name: 'Salva i filtri' })).toBeDisabled();
  await facet.getByRole('textbox', { name: 'Parametro', exact: true }).first().fill('cerca');

  // Tried as a visitor: this run's posts, found by title.
  const title = Object.values(data.posts)[0]!.title;
  // The exact phrase: WordPress search matches words, and the run's other posts share most of them.
  await facet.getByLabel('Prova cerca').fill(`"${title}"`);
  await facet.getByRole('button', { name: 'Prova', exact: true }).click();
  await expect(facet.getByRole('status')).toHaveText(/^1 risultati/);
  await expect(facet.getByText(title, { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Salva i filtri' }).click();
  await expect(page.getByText('Filtri salvati in sviluppo')).toBeVisible();
  const saved = await builder.api<{ facets: Record<string, { filters: Array<{ key: string }> }> }>('GET', 'facets');
  expect(saved.facets[name]?.filters.map((f) => f.key)).toEqual(['cerca', 'ordine']);

  // Inserted: a GET form and the results, rendered in the canvas with the saved (dev) definition.
  await facet.getByRole('button', { name: 'Inserisci nella pagina' }).click();
  await page.getByRole('button', { name: /Chiudi e torna/ }).click();
  const form = builder.canvas.locator(`form[data-xb-facet="${name}"]`);
  await expect(form).toHaveAttribute('method', 'get');
  await expect(form.getByRole('searchbox')).toHaveAttribute('name', 'cerca');
  await expect(form.getByRole('combobox')).toContainText('Più recenti');
  await expect(builder.canvas.locator('.xb-facets__summary').last()).toContainText('risultati');
  await builder.tab('HTML');
  await expect(builder.html).toContainText(`{#loop facets.${name}.items as item}`);

  // On the page (signed dev preview): typing filters in place, without reloading; the address follows, back
  // goes back, and the preview token is kept by the links and the form.
  await builder.save();
  const link = await builder.api<{ url: string }>('GET', `preview-link?post=${data.pages.facets.id}`);
  const front = await page.context().newPage();
  await front.goto(link.url);
  const summary = front.locator(`[data-xb-facet="${name}"] .xb-facets__summary`);
  const before = (await summary.textContent()) ?? '';
  await front.evaluate(() => ((window as unknown as { xbMarker: number }).xbMarker = 1));
  await front.locator(`form[data-xb-facet="${name}"] input[name="cerca"]`).fill(`"${title}"`);
  await expect(summary).toHaveText(/^1 risultati/);
  await expect(front.locator(`[data-xb-facet="${name}"] .xb-facets__list li`)).toHaveText([title]);
  expect(await front.evaluate(() => (window as unknown as { xbMarker?: number }).xbMarker)).toBe(1);
  expect(front.url()).toContain('cerca=');
  expect(front.url()).toContain('xb_preview=');
  await expect(front.locator(`form[data-xb-facet="${name}"] input[name="cerca"]`)).toBeFocused();
  await front.goBack();
  await expect(summary).toHaveText(before);

  // A form changed to point at another site (by a script, an extension): changing a field neither fetches it
  // nor takes the visitor there.
  const elsewhere: string[] = [];
  await front.route('https://evil.example/**', (route) => {
    elsewhere.push(route.request().url());
    return route.abort();
  });
  const address = front.url();
  await front.locator(`form[data-xb-facet="${name}"]`).evaluate((f) => f.setAttribute('action', 'https://evil.example/raccolta'));
  await front.locator(`form[data-xb-facet="${name}"] select`).first().selectOption({ index: 1 });
  await front.locator(`form[data-xb-facet="${name}"] input[name="cerca"]`).fill('altro');
  await front.waitForTimeout(1000);
  expect(elsewhere).toEqual([]);
  expect(front.url()).toBe(address);
  await front.close();
});
