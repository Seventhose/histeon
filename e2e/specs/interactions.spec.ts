import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('interazioni senza codice: schede e menu mobile funzionano nella pagina vera, con tastiera', async ({ page, data }) => {
  // Its own page: the preview saves it.
  const builder = await Builder.open(page, data.pages.interactions.id);
  await builder.tab('HTML');
  await builder.write(builder.html, '<p>Inizio</p>');
  await page.keyboard.press('Escape');
  const add = async (label: string) => {
    await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
    await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill(label);
    await page.keyboard.press('Enter');
    await page.keyboard.press('Escape');
  };
  await add('Schede');
  await add('Menu mobile');
  await expect(builder.html).toContainText('data-xb-tabs');
  // In the canvas (no scripts) every panel is visible: what the page is without JavaScript.
  await expect(builder.canvas.getByText('Contenuto della seconda scheda.')).toBeVisible();
  await builder.tab('CSS');
  await builder.node('div.tabs').click();
  await expect(page.getByRole('textbox', { name: 'CSS di .tabs' })).toContainText('display: grid;');

  // The real page: the script makes them work.
  await page.getByRole('button', { name: 'Anteprima' }).click();
  const live = page.frameLocator('iframe[title="Anteprima della pagina in sviluppo"]');
  const second = live.getByRole('tab', { name: 'Seconda' });
  await expect(live.getByText('Contenuto della seconda scheda.')).toBeHidden();
  await second.click();
  await expect(second).toHaveAttribute('aria-selected', 'true');
  await expect(live.getByText('Contenuto della seconda scheda.')).toBeVisible();
  await expect(live.getByText('Contenuto della prima scheda.')).toBeHidden();
  await page.keyboard.press('ArrowLeft');
  await expect(live.getByRole('tab', { name: 'Prima' })).toBeFocused();
  await expect(live.getByText('Contenuto della prima scheda.')).toBeVisible();

  // Phone width: the menu folds behind its button; Esc closes it again.
  await page.getByRole('dialog', { name: 'Anteprima della pagina' }).getByRole('button', { name: 'Telefono' }).click();
  const toggle = live.locator('button.site-nav__toggle');
  await expect(toggle).toBeVisible();
  await expect(live.locator('.site-nav__menu')).toBeHidden();
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(live.locator('.site-nav__menu')).toBeVisible();
  // Esc closes the innermost thing first: the menu, then (a second Esc) the preview.
  await live.locator('body').press('Escape');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await live.locator('body').press('Escape');
  await expect(page.getByRole('dialog', { name: 'Anteprima della pagina' })).toHaveCount(0);
});

test('video YouTube senza richieste prima del clic, poi da youtube-nocookie.com (E9)', async ({ page, data, consoleErrors }) => {
  // Nothing in these tests may reach the real YouTube: every request to it is answered here and recorded.
  const youtube: string[] = [];
  await page.route(/youtube|ytimg|vimeo/, (route) => {
    youtube.push(route.request().url());
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>player</title>' });
  });
  const builder = await Builder.open(page, data.pages.interactions.id);
  const poster = new URL('/wp-content/plugins/xb/assets/samples/image.svg', page.url()).href;

  // The dialog of the + menu refuses what is not a video, without asking anyone.
  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('Video YouTube');
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Video YouTube o Vimeo' });
  await dialog.getByLabel('Indirizzo del video').fill('https://example.com/non-un-video');
  await dialog.getByRole('button', { name: 'Inserisci' }).click();
  await expect(dialog.getByRole('alert')).toContainText('YouTube o Vimeo');
  // The browser logs the refused request (400): expected here, and only this one.
  await expect.poll(() => consoleErrors.filter((e) => e.includes('status of 400')).length).toBe(1);
  consoleErrors.splice(consoleErrors.findIndex((e) => e.includes('status of 400')), 1);
  await dialog.getByRole('button', { name: 'Annulla' }).click();

  // The markup the dialog makes (videoBlocks, unit-tested), with a poster from the site.
  await builder.tab('HTML');
  await builder.write(builder.html, `<p>Il video</p><figure class="xb-video" data-xb-video="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"><a class="xb-video__play" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" aria-label="Riproduci il video: Prova"><img src="${poster}" alt="" loading="lazy"><span class="xb-video__icon" aria-hidden="true"></span></a></figure>`);
  await page.getByRole('button', { name: 'Anteprima' }).click();
  const live = page.frameLocator('iframe[title="Anteprima della pagina in sviluppo"]');
  const play = live.getByRole('link', { name: 'Riproduci il video: Prova' });
  await expect(play).toBeVisible();
  await expect(live.locator('[data-xb-video] img')).toBeVisible();
  expect(youtube, 'no request before the click').toEqual([]);

  await play.click();
  const player = live.locator('iframe.xb-video__frame');
  await expect(player).toHaveAttribute('src', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0');
  await expect(player).toHaveAttribute('title', 'Riproduci il video: Prova');
  await expect.poll(() => youtube.some((u) => u.startsWith('https://www.youtube-nocookie.com/embed/'))).toBe(true);
  expect(youtube.every((u) => !u.includes('www.youtube.com/watch'))).toBe(true);
});

test('mappa senza richieste prima del clic (E11)', async ({ page, data }) => {
  const outside: string[] = [];
  await page.route(/openstreetmap|google\.com\/maps|maps\.google/, (route) => {
    outside.push(route.request().url());
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>mappa</title>' });
  });
  const builder = await Builder.open(page, data.pages.interactions.id);
  await builder.tab('HTML');
  await builder.write(builder.html, '<p>Dove siamo</p>');
  await page.keyboard.press('Escape');
  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('Mappa');
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Mappa' });
  await dialog.getByLabel('Link della mappa').fill('https://example.com/non-una-mappa');
  await expect(dialog.getByRole('alert')).toBeVisible();
  await dialog.getByLabel('Link della mappa').fill('https://www.openstreetmap.org/#map=16/45.4642/9.1900');
  await dialog.getByLabel('Nome del luogo').fill('Libreria di prova');
  await expect(dialog.getByRole('status')).toHaveText('Mappa di OpenStreetMap');
  await dialog.getByRole('button', { name: 'Inserisci' }).click();
  await expect(builder.canvas.locator('figure.xb-map')).toContainText('Libreria di prova');

  await page.getByRole('button', { name: 'Anteprima' }).click();
  const live = page.frameLocator('iframe[title="Anteprima della pagina in sviluppo"]');
  const open = live.getByRole('link', { name: /Libreria di prova/ });
  await expect(open).toBeVisible();
  expect(outside, 'no request before the click').toEqual([]);
  await open.click();
  await expect(live.locator('iframe.xb-map__frame')).toHaveAttribute('src', /^https:\/\/www\.openstreetmap\.org\/export\/embed\.html\?bbox=/);
  await expect.poll(() => outside.length).toBeGreaterThan(0);
});
