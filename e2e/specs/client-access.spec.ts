import { randomBytes } from 'node:crypto';
import { expect, test } from '../lib/test.ts';
import { Builder } from '../lib/builder.ts';
import { wpEval } from '../lib/wp.ts';

// Voce 186 (docs/spec/client-mode.md, «Permessi del cliente» and «Aspetto della pagina»): the owner gives a client
// the look of one page in one group from Histeon → Clienti; the client changes it in the builder, on that page only,
// and the server refuses the rest.
const MAKE = String.raw`<?php
$run   = getenv( 'E2E_RUN' );
$page  = static fn ( string $title ): int => wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => wp_slash(
	'<!-- wp:xb/element {"uid":"e2e' . substr( md5( $title ), 0, 10 ) . '","tag":"h1","attributes":{"class":"e2e-look-' . $run . '"}} -->'
	. '<!-- wp:xb/text {"uid":"e2t' . substr( md5( $title ), 0, 10 ) . '","content":"Titolo"} /--><!-- /wp:xb/element -->'
) ) );
$mine  = $page( 'Aspetto ' . $run );
$other = $page( 'Altra ' . $run );
$login = 'e2e-aspetto-' . $run;
$user  = wp_insert_user( array( 'user_login' => $login, 'user_pass' => getenv( 'E2E_CLIENT_PASS' ), 'user_email' => $login . '@example.test', 'role' => \XB\Capabilities::CLIENT_ROLE ) );
echo "\nE2E=" . wp_json_encode( array( 'page' => $mine, 'other' => $other, 'user' => $user, 'login' => $login ) ) . "\n";
`;

const CLEAN = String.raw`<?php
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( 'E2E_PAGE', 'E2E_OTHER' ) as $key ) {
	wp_delete_post( (int) getenv( $key ), true );
}
wp_delete_user( (int) getenv( 'E2E_USER' ) );
`;

test('permessi del cliente: aspetto di una sola pagina, nei gruppi dati, e il server rifiuta il resto', async ({ page, browser }) => {
  const run = Date.now().toString(36);
  const password = randomBytes(18).toString('base64url');
  const out = wpEval(MAKE, { E2E_RUN: run, E2E_CLIENT_PASS: password });
  const made = JSON.parse(out.split('\n').find((l) => l.startsWith('E2E='))!.slice(4)) as { page: number; other: number; user: number; login: string };
  const selector = `.e2e-look-${run}`;

  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const client = await context.newPage();
  try {
    // The owner: Histeon → Clienti, this client: look of the page, group «Testo», only this page.
    await page.goto(`/wp-admin/admin.php?page=xb-clients&user=${made.user}`);
    await page.getByRole('checkbox', { name: /^Aspetto della pagina/ }).check();
    await page.getByRole('checkbox', { name: 'Testo', exact: true }).check();
    await page.getByRole('radio', { name: /^Solo quelle scelte/ }).check();
    await page.getByLabel(`Aspetto ${run}`, { exact: true }).selectOption('look');
    // Before a list, every page shows the level it has now (texts and pictures): switching takes nothing away by itself.
    await expect(page.getByLabel(`Altra ${run}`, { exact: true })).toHaveValue('content');
    await page.getByLabel(`Altra ${run}`, { exact: true }).selectOption('none');
    await page.getByRole('button', { name: 'Salva i permessi' }).click();
    await expect(page.getByText('Permessi salvati.')).toBeVisible();

    await client.goto('/wp-login.php');
    await expect(client.locator('#user_login')).toBeFocused();
    await client.waitForTimeout(300);
    await client.locator('#user_login').fill(made.login);
    await client.locator('#user_pass').fill(password);
    await client.locator('#wp-submit').click();
    await client.waitForURL(/wp-admin/);

    const b = await Builder.open(client, made.page);
    await b.node(`h1${selector}`).click();
    const look = client.getByRole('region', { name: `Mini GUI di ${selector}` });
    await expect(look).toBeVisible();
    // Only the group given.
    await expect(look.getByRole('tab')).toHaveText(['Testo']);
    await look.getByRole('tab', { name: 'Testo' }).click();
    await expect(look.getByRole('tab', { name: 'Testo' })).toHaveAttribute('aria-selected', 'true');
    await look.getByRole('button', { name: '700', exact: true }).click();
    await expect.poll(() => b.canvas.locator('h1').evaluate((el) => getComputedStyle(el).fontWeight)).toBe('700');
    await b.save();
    const doc = await b.api<{ pageStyles: Array<{ selector: string; css: string }> }>('GET', `documents/${made.page}`);
    expect(doc.pageStyles).toEqual([{ selector, css: expect.stringContaining('font-weight: 700') }]);

    // Whatever the browser sends: a group not given, the site's styles, another page.
    const saved = await b.api<{ content: string; head: string; base: string }>('GET', `documents/${made.page}`);
    expect(await b.apiStatus('POST', `documents/${made.page}`, { content: saved.content, head: saved.head, base: saved.base, styles: [{ selector, css: 'padding: var(--xb-space-m);' }] })).toBe(403);
    expect(await b.apiStatus('POST', `documents/${made.page}`, { content: saved.content, head: saved.head, base: saved.base, styles: [{ selector: `${selector} a`, css: 'font-weight: 700;' }] })).toBe(400);
    expect(await b.apiStatus('POST', 'styles', { styles: [], globals: [], head: 'x', base: 'x' })).toBe(403);
    expect(await b.apiStatus('GET', `documents/${made.other}`)).toBe(403);

    // In Gutenberg the Aspetto panel writes the same page look: it shows what the builder saved, and saves in development.
    await client.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
    await client.goto(`/wp-admin/post.php?post=${made.page}&action=edit`);
    // The canvas is an iframe, or the page itself when WordPress draws the editor without one (meta boxes).
    await expect(client.locator('.editor-styles-wrapper').or(client.locator('iframe[name="editor-canvas"]')).first()).toBeVisible({ timeout: 30_000 });
    const canvas = (await client.locator('iframe[name="editor-canvas"]').count()) > 0 ? client.frameLocator('iframe[name="editor-canvas"]') : client;
    const heading = canvas.locator(`h1${selector}`);
    await expect(heading).toBeVisible({ timeout: 30_000 });
    const guide = client.getByRole('dialog', { name: /Benvenut|Welcome/ });
    if (await guide.isVisible().catch(() => false)) await client.keyboard.press('Escape');
    await expect.poll(() => heading.evaluate((el) => getComputedStyle(el).fontWeight), { timeout: 15_000 }).toBe('700');
    await client.evaluate((cls) => {
      const wp = (window as unknown as { wp: any }).wp;
      const block = wp.data.select('core/block-editor').getBlocks().find((b: any) => b.attributes?.attributes?.class === cls);
      wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
      wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block');
    }, selector.slice(1));
    const panel = client.locator('.xb-look');
    await expect(panel).toBeVisible({ timeout: 30_000 });
    await expect(panel.getByRole('tab')).toHaveText(['Testo']);
    const weight = panel.getByRole('group', { name: 'Spessore del testo' });
    await expect(weight.getByRole('button', { name: '700', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await weight.getByRole('button', { name: '400', exact: true }).click();
    await expect.poll(() => heading.evaluate((el) => getComputedStyle(el).fontWeight)).toBe('400');
    await panel.getByRole('button', { name: 'Salva in sviluppo' }).click();
    await expect(panel.getByRole('button', { name: 'Salva in sviluppo' })).toBeDisabled();
    const stored = () =>
      client.evaluate(async (id) => {
        const config = (window as unknown as { xbInspector: { restUrl: string; nonce: string } }).xbInspector;
        const res = await fetch(`${config.restUrl}documents/${id}`, { headers: { 'X-WP-Nonce': config.nonce } });
        return ((await res.json()) as { pageStyles: Array<{ css: string }> }).pageStyles[0]?.css ?? '';
      }, made.page);
    await expect.poll(stored).toContain('font-weight: 400');
  } finally {
    await context.close();
    wpEval(CLEAN, { E2E_PAGE: String(made.page), E2E_OTHER: String(made.other), E2E_USER: String(made.user) });
  }
});
