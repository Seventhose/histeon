import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('struttura: etichette BEM automatiche e rinomina con doppio clic', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<article class="card${id}"><h2>Titolo</h2><p class="lead">x</p></article>`);

  // The h2 has no class of its own: it reads as its BEM name; the paragraph with a class does not.
  const h2 = builder.node('h2');
  await expect(h2.locator('.auto')).toHaveText(`card${id}__title`);
  await expect(builder.node('p.lead').locator('.auto')).toHaveCount(0);

  // Double click renames: the label is saved as xb:label and replaces the automatic one.
  await h2.dblclick();
  await page.getByLabel('Etichetta di h2').fill('Titolo della scheda');
  await page.keyboard.press('Enter');
  await expect(builder.node('Titolo della scheda')).toBeVisible();
  await expect(builder.html).toContainText('<h2 xb:label="Titolo della scheda">');

  // Empty label: back to the automatic one.
  await builder.node('Titolo della scheda').click();
  await page.keyboard.press('F2');
  await page.getByLabel('Etichetta di Titolo della scheda').fill('');
  await page.keyboard.press('Enter');
  await expect(builder.node('h2').locator('.auto')).toHaveText(`card${id}__title`);
});

test('struttura: accanto al nome, poche parole del testo più importante che il nodo contiene', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('HTML');
  await builder.write(builder.html, '<section class="hero"><div class="wrap"><h2>Il titolo che conta davvero in questa sezione</h2><p>Testo minore</p></div></section><img alt="Foto di gruppo"/>');
  const row = (label: string) => builder.node(label);
  // A section is named by its first heading, open or closed (E4); the heading is its name, not a quoted hint.
  await expect(row('section.hero').locator('.heading')).toHaveText('Il titolo che conta davvero…');
  await expect(row('section.hero')).not.toContainText('“');
  await row('section.hero').locator('..').getByRole('button', { name: 'Comprimi' }).click();
  await expect(row('section.hero').locator('.heading')).toHaveText('Il titolo che conta davvero…');
  await expect(row('section.hero')).not.toContainText('“');
  await row('section.hero').locator('..').getByRole('button', { name: 'Espandi' }).click();
  await expect(row('img')).toContainText('“Foto di gruppo”');
  // Closed, a row shows its text; open, with the text right below it, it does not repeat it.
  await expect(row('h2')).toContainText('“Il titolo che conta davvero…”');
  await row('h2').locator('..').getByRole('button', { name: 'Espandi' }).click();
  await expect(row('h2')).not.toContainText('“');
  await row('section.hero').locator('..').getByRole('button', { name: 'Comprimi' }).click();
  await expect(row('section.hero')).toHaveAttribute('title', /^Il titolo che conta davvero… — Hero · section\.hero/);
});

test('struttura: ogni riga dice cosa è il nodo, in parole', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.tab('HTML');
  await builder.write(builder.html, '<header class="vl-page__hd"><span class="vl-page__k">Ricerca</span><h1 class="vl-page__t">Risultati della ricerca</h1></header><a class="vl-tile" href="/x"><span class="vl-tile__img"></span></a>');
  await expect(builder.node('header.vl-page__hd').locator('.role')).toHaveText('Intestazione');
  // Only text inside: closed, the text on the same row.
  await expect(builder.node('h1.vl-page__t').locator('.role')).toHaveText('Titolo H1');
  await expect(builder.node('h1.vl-page__t')).toContainText('“Risultati della ricerca”');
  await expect(builder.node('span.vl-page__k').locator('.role')).toHaveText('Occhiello');
  await expect(builder.node('a.vl-tile').locator('.role')).toHaveText('Link');
  await expect(builder.node('span.vl-tile__img').locator('.role')).toHaveText('Immagine');
  // The accessible name stays the selector; the role is its description.
  await expect(builder.node('h1.vl-page__t')).toHaveAccessibleDescription('Titolo H1, Risultati della ricerca');
});

test('struttura: la formattazione in riga non è un nodo, e sopra la tela il percorso della selezione (E4)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  // Bold and line breaks with attributes, as an imported page has them: elements, folded into the paragraph's row.
  await builder.write(builder.html, `<section class="s${id}"><h2>I giusti</h2><div class="s${id}__body"><p class="p${id}">Il <strong class="b">giusto</strong> legge,<br class="r"/>sempre</p></div></section>`);
  const p = builder.node(`p.p${id}`);
  await expect(p).toContainText('“Il giusto legge, sempre”');
  // The words come first, the kind of node after them.
  const order = await p.locator('.text-hint, .role').evaluateAll((els) => els.map((el) => el.className.split(' ')[0]));
  expect(order).toEqual(['text-hint', 'role']);
  await expect(builder.structure.getByRole('button', { name: 'strong.b' })).toHaveCount(0);
  await expect(builder.structure.getByRole('button', { name: 'br.r' })).toHaveCount(0);

  // The path above the canvas: the page, the section by its heading, the body, the paragraph by its words.
  await builder.canvas.locator(`p.p${id}`).click();
  const path = page.getByRole('navigation', { name: 'Percorso della selezione' });
  await expect(path.getByRole('button')).toHaveText(['Pagina', 'I giusti', 'Corpo', 'Il giusto legge, sempre']);
  await expect(path.getByRole('button', { name: 'Il giusto legge, sempre' })).toHaveAttribute('aria-current', 'location');
  // A step selects its node.
  await path.getByRole('button', { name: 'I giusti' }).click();
  await expect(builder.structure.getByRole('treeitem', { selected: true }).getByRole('button', { name: `section.s${id}` })).toBeVisible();
});

test('struttura: si cerca per testo, classe o id, e Invio va da un risultato all’altro (E8)', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<section class="a${id}"><div><div><h2>Trova ${id} qui</h2></div></div></section><section id="s${id}"><div><div><p>Anche trova ${id}</p></div></div></section>`);
  await page.keyboard.press('Escape');
  const search = page.getByRole('searchbox', { name: 'Cerca nella struttura' });
  await search.fill(`trova ${id}`);
  // Both results are marked and their branches open, even deep.
  await expect(builder.structure.locator('.row.matched')).toHaveCount(2);
  await expect(page.getByRole('search').getByRole('status')).toHaveText('– di 2');
  await search.press('Enter');
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toContainText('Titolo H2');
  await expect(page.getByRole('search').getByRole('status')).toHaveText('1 di 2');
  await search.press('Enter');
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toContainText('Paragrafo');
  await search.press('Shift+Enter');
  await expect(page.getByRole('search').getByRole('status')).toHaveText('1 di 2');
  // By id and by class.
  await search.fill(`#s${id}`);
  await expect(builder.structure.locator('.row.matched')).toHaveCount(1);
  await search.fill(`a${id}`);
  await expect(builder.structure.locator('.row.matched')).toHaveCount(1);
  await search.fill('parola-che-non-ce');
  await expect(page.getByRole('search').getByRole('status')).toHaveText('nessuno');
  await search.press('Escape');
  await expect(search).toHaveValue('');
  await expect(builder.structure.locator('.row.matched')).toHaveCount(0);
});
