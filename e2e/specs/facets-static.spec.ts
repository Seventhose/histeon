import { readFileSync } from 'node:fs';
import { expect, test } from '../lib/test.ts';

// P9: in the static ZIP the page carries every result and an index; facets-static.js filters in the browser.
// The page is served here, as the ZIP would serve it: no WordPress behind it.
const script = readFileSync(new URL('../../plugin/assets/front/facets-static.js', import.meta.url), 'utf8');
const index = {
  name: 'eventi',
  perPage: 2,
  filters: [
    { kind: 'search', key: 'q' },
    { kind: 'taxonomy', key: 'tema', multiple: true, match: 'any' },
    { kind: 'range', key: 'prezzo', type: 'number' },
    { kind: 'sort', key: 'ordine' },
  ],
  sorts: { titolo: 'asc', prezzo: 'desc' },
  items: [
    { id: 1, v: { tema: ['musica'], prezzo: '10' }, s: { titolo: 'concerto', prezzo: 10 }, t: 'concerto in piazza' },
    { id: 2, v: { tema: ['teatro'], prezzo: '30' }, s: { titolo: 'amleto', prezzo: 30 }, t: 'amleto al teatro' },
    { id: 3, v: { tema: ['musica', 'teatro'], prezzo: '20' }, s: { titolo: 'brecht', prezzo: 20 }, t: 'brecht e musica' },
  ],
};
const page = `<!doctype html><html><body>
<div><form data-xb-facet="eventi"><input type="search" name="q" aria-label="Cerca">
<label><input type="checkbox" name="tema[]" value="musica"> Musica</label><label><input type="checkbox" name="tema[]" value="teatro"> Teatro</label>
<input name="prezzo_min" aria-label="Prezzo minimo"><input name="prezzo_max" aria-label="Prezzo massimo">
<select name="ordine" aria-label="Ordina"><option value="">—</option><option value="titolo">Titolo</option><option value="prezzo">Prezzo</option></select>
<button>Filtra</button></form>
<p><span data-xb-facet-total="eventi">3</span> risultati</p>
<ul><li data-xb-facet-item="eventi:1">Concerto</li><li data-xb-facet-item="eventi:2">Amleto</li><li data-xb-facet-item="eventi:3">Brecht</li></ul>
<a href="/eventi/?tema=teatro">Solo teatro</a></div><!-- the form is the region itself, as «Inserisci nella pagina» writes it -->
<script type="application/json" id="xb-facets-index-eventi">${JSON.stringify(index)}</script>
<script src="/facets-static.js" defer></script></body></html>`;

test('filtri nello ZIP statico: si filtra, si ordina e si conta nel browser, anche dai link (P9)', async ({ page: browser }) => {
  await browser.route('http://statico.test/**', (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/facets-static.js') return route.fulfill({ contentType: 'text/javascript', body: script });
    return route.fulfill({ contentType: 'text/html', body: page });
  });
  await browser.goto('http://statico.test/eventi/');
  const visible = () => browser.locator('li:not([hidden])').allTextContents();
  await expect.poll(visible).toEqual(['Concerto', 'Amleto', 'Brecht']);

  await browser.getByLabel('Musica').check();
  await expect.poll(visible).toEqual(['Concerto', 'Brecht']);
  await expect(browser.locator('[data-xb-facet-total]')).toHaveText('2');
  expect(new URL(browser.url()).search).toBe('?tema%5B%5D=musica');

  await browser.getByLabel('Ordina').selectOption('prezzo');
  await expect.poll(visible).toEqual(['Brecht', 'Concerto']);
  await browser.getByLabel('Prezzo minimo').fill('15');
  await browser.getByRole('button', { name: 'Filtra' }).click();
  await expect.poll(visible).toEqual(['Brecht']);

  await browser.getByLabel('Prezzo minimo').fill('');
  await browser.getByLabel('Musica').uncheck();
  await browser.getByLabel('Cerca').fill('teatro');
  await expect.poll(visible).toEqual(['Amleto']);

  // A link with parameters loads the same static page: the script reads the address.
  await browser.getByRole('link', { name: 'Solo teatro' }).click();
  await expect.poll(visible).toEqual(['Amleto', 'Brecht']);
  await expect(browser.getByLabel('Teatro')).toBeChecked();
});
