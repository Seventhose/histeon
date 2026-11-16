import { Builder } from '../lib/builder.ts';
import { AUTH_FILE } from '../lib/paths.ts';
import { expect, SANDBOX_BLOCKED, test } from '../lib/test.ts';

// No trace recorder here: its snapshot scripts are refused by the canvas sandbox and would hide whether
// anything else ever tries to run a script there (lib/test.ts).
test.use({ trace: 'off' });

const directives = (csp: string) => new Map(csp.split(';').map((d) => d.trim().split(/\s+/)).map(([name, ...values]) => [name as string, values]));

test('la pagina del builder: CSP con nonce, niente incorniciamento, niente cache', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.security.id);
  const headers = builder.response?.headers() ?? {};
  expect(headers['x-frame-options']).toBe('DENY');
  expect(headers['x-content-type-options']).toBe('nosniff');
  expect(headers['referrer-policy']).toBe('same-origin');
  expect(headers['cache-control']).toContain('no-store');

  const csp = directives(headers['content-security-policy'] ?? '');
  const scriptSrc = csp.get('script-src') ?? [];
  expect(scriptSrc).toHaveLength(1);
  const nonce = /^'nonce-([A-Za-z0-9_-]{24})'$/.exec(scriptSrc[0] ?? '')?.[1];
  expect(nonce, 'script-src is only a nonce').toBeTruthy();
  expect(csp.get('default-src')).toEqual(["'self'"]);
  for (const name of ['frame-ancestors', 'object-src', 'base-uri']) expect(csp.get(name), name).toEqual(["'none'"]);
  // One worker, the image encoder, by its exact path (lista migliorie M5); never any form of eval.
  expect(csp.get('worker-src')).toHaveLength(1);
  expect(csp.get('worker-src')?.[0]).toMatch(/^https?:\/\/[^/]+\/.*\/assets\/builder\/media\/encoder\.js$/);
  expect(headers['content-security-policy']).not.toMatch(/unsafe-eval/);
  expect(csp.get('frame-src')).toEqual(["'self'"]);

  // The only scripts are the two that carry this response's nonce, and every load gets a new one.
  expect(await page.evaluate(() => [...document.scripts].map((s) => s.nonce))).toEqual([nonce, nonce]);
  const again = await page.request.get(Builder.url(data.pages.security.id));
  expect(again.headers()['content-security-policy']).not.toContain(`'nonce-${nonce}'`);
});

test('il builder non si apre dentro un frame di un altro sito', async ({ browser, baseURL, data }) => {
  // Own context: the refusal is logged as a console error there, on purpose.
  const context = await browser.newContext({ storageState: AUTH_FILE });
  const page = await context.newPage();
  await page.setContent(`<iframe src="${baseURL}${Builder.url(data.pages.security.id)}"></iframe>`);
  const frame = page.frames()[1];
  await expect.poll(() => frame?.url()).toMatch(/^chrome-error:/);
  await context.close();
});

test('la tela è sandboxed senza script e senza nessun tentativo di eseguirne', async ({ page, data, consoleErrors }) => {
  const builder = await Builder.open(page, data.pages.security.id);
  await expect(builder.canvasFrame).toHaveAttribute('sandbox', 'allow-same-origin');
  expect(await builder.canvasFrame.evaluate((f: HTMLIFrameElement) => [...f.sandbox])).not.toContain('allow-scripts');
  const shellCsp = directives((await builder.canvas.locator('meta[http-equiv="Content-Security-Policy"]').getAttribute('content')) ?? '');
  expect(shellCsp.get('script-src')).toEqual(["'none'"]);
  expect(shellCsp.get('default-src')).toEqual(["'none'"]);
  await expect(builder.canvas.locator('script')).toHaveCount(0);

  // Normal use: every panel, a selection from the canvas and one from the tree.
  for (const tab of ['CSS', 'Selettori', 'Query', 'JS', 'Elemento', 'Versioni', 'HTML'] as const) await builder.tab(tab);
  await builder.canvas.getByText('Primo paragrafo').click();
  await expect(builder.structure.getByRole('treeitem', { selected: true })).toContainText('p.uno');
  await builder.node('h1').click();
  await expect(builder.canvas.locator('h1')).toHaveCSS('outline-style', 'solid');
  expect(consoleErrors.filter((e) => SANDBOX_BLOCKED.test(e.replace(/^.*?: /, '')))).toEqual([]);
});

test('markup ostile nella tela resta inerte', async ({ page, data, consoleErrors }) => {
  const builder = await Builder.open(page, data.pages.security.id);
  await builder.edit(builder.html, (code) =>
    code.replace('</section>', '  <img src="data:," onerror="parent.xbPwned = 1" alt="">\n  <p onclick="parent.xbPwned = 2">Cliccami</p>\n</section>'),
  );
  await expect(builder.canvas.getByText('Cliccami')).toBeVisible();
  // An admin may write handlers (unfiltered_html): they reach the canvas, and the sandbox is what stops them.
  await expect(builder.canvas.locator('p[onclick]')).toHaveCount(1);
  await builder.canvas.getByText('Cliccami').click();
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => (window as unknown as { xbPwned?: number }).xbPwned)).toBeUndefined();
  await expect(builder.canvas.locator('script, iframe, object, embed, base, meta:not([http-equiv="Content-Security-Policy"]):not([charset])')).toHaveCount(0);
  // The browser reports the handlers it refused: that is the sandbox at work, not an error of the builder.
  const refused = consoleErrors.filter((e) => SANDBOX_BLOCKED.test(e.replace(/^.*?: /, '')));
  expect(refused.length, 'handler rifiutati dalla sandbox').toBeGreaterThan(0);
  consoleErrors.splice(0, consoleErrors.length, ...consoleErrors.filter((e) => !refused.includes(e)));
});

test('contenuto che non fa il giro completo: il pannello HTML è in sola lettura', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.readonly.id);
  await expect(page.getByRole('status').filter({ hasText: 'il codice è in sola lettura' })).toBeVisible();
  const before = await builder.source(builder.html);
  await builder.html.click();
  await page.keyboard.press('ControlOrMeta+End');
  await page.keyboard.type('<p>no</p>');
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.press('Delete');
  await page.waitForTimeout(500);
  expect(await builder.source(builder.html)).toBe(before);
  await expect(builder.saveButton).toBeDisabled();
});

test('le API del builder rifiutano i cookie senza nonce', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.security.id);
  const id = data.pages.security.id;
  expect(await builder.apiStatus('GET', `documents/${id}`)).toBe(200);
  expect([401, 403]).toContain(await builder.apiStatus('GET', `documents/${id}`, undefined, false));
  expect([401, 403]).toContain(await builder.apiStatus('POST', 'releases', { version: '99.0.0', notes: '', docs: [] }, false));
});

test('la tela carica media solo dal sito e dalle origini ammesse (S5)', async ({ page, data }) => {
  const outside: string[] = [];
  await page.route(/esterno\.example/, (route) => {
    outside.push(route.request().url());
    return route.fulfill({ status: 200, contentType: 'image/png', body: '' });
  });
  const builder = await Builder.open(page, data.pages.palette.id);
  const csp = directives((await builder.canvas.locator('meta[http-equiv="Content-Security-Policy"]').getAttribute('content')) ?? '');
  for (const name of ['img-src', 'font-src', 'media-src', 'style-src']) {
    expect(csp.get(name)?.[0], name).toBe("'self'");
    expect(csp.get(name), name).not.toContain('*');
  }
  await builder.tab('HTML');
  const site = new URL(page.url()).origin;
  await builder.write(builder.html, `<p>Immagini</p><img class="fuori" src="https://esterno.example/foto.jpg" alt="Fuori"><img class="dentro" src="${site}/wp-content/plugins/xb/assets/samples/image.svg" alt="Dentro">`);
  const fuori = builder.canvas.locator('img.fuori');
  await expect(fuori).toHaveAttribute('data-xb-blocked', 'https://esterno.example');
  await expect(fuori).toHaveAttribute('src', /^data:image\/svg\+xml,/);
  await expect(builder.canvas.locator('img.dentro')).toHaveAttribute('src', /assets\/samples\/image\.svg$/);
  // The page itself keeps the address: only the canvas shows a placeholder.
  await expect(builder.html).toContainText('https://esterno.example/foto.jpg');
  expect(outside).toEqual([]);
});
