import { randomBytes } from 'node:crypto';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('modalità cliente: il builder si apre solo per testi e immagini, e il server rifiuta il resto', async ({ page, browser, data }) => {
  const doc = data.pages.save;
  // A test client, created by the test administrator over REST with a password generated here (never reused).
  await Builder.open(page, doc.id);
  const login = `cliente_${Date.now()}`;
  const password = randomBytes(18).toString('base64url');
  const created = await page.evaluate(async ([username, pass]) => {
    const config = (window as unknown as { xbBuilder: { nonce: string } }).xbBuilder;
    const res = await fetch('/wp-json/wp/v2/users', {
      method: 'POST',
      headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password: pass, email: `${username}@example.test`, roles: ['xb_client'] }),
    });
    return { status: res.status, id: ((await res.json()) as { id?: number }).id ?? 0 };
  }, [login, password]);
  expect(created.status).toBe(201);

  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const client = await context.newPage();
  try {
    await client.goto('/wp-login.php');
    await expect(client.locator('#user_login')).toBeFocused();
    await client.waitForTimeout(300);
    await client.locator('#user_login').fill(login);
    await client.locator('#user_pass').fill(password);
    await client.locator('#wp-submit').click();
    await client.waitForURL(/wp-admin/);

    await client.goto(Builder.url(doc.id));
    await expect(client.getByRole('note').filter({ hasText: 'Modalità contenuti' })).toBeVisible();
    await expect(client.locator('header.topbar').getByRole('button', { name: 'Pubblica…' })).toHaveCount(0);
    await expect(client.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Stili e selettori' })).toHaveCount(0);
    await expect(client.getByRole('textbox', { name: 'Codice HTML' })).toHaveCount(0);

    // Whatever the browser sends, the server keeps the rule: a new element is refused.
    const status = await client.evaluate(async (id) => {
      const config = (window as unknown as { xbBuilder: { nonce: string } }).xbBuilder;
      const get = await fetch(`/wp-json/xb/v1/documents/${id}`, { headers: { 'X-WP-Nonce': config.nonce } });
      const d = (await get.json()) as { content: string; head: string; base: string };
      const res = await fetch(`/wp-json/xb/v1/documents/${id}`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify({ content: d.content + '<!-- wp:xb/element {"uid":"zz99zz99","tag":"script"} /-->', head: d.head, base: d.base }),
      });
      return res.status;
    }, doc.id);
    expect(status).toBe(403);
  } finally {
    await context.close();
    await page.evaluate(async (id) => {
      const config = (window as unknown as { xbBuilder: { nonce: string } }).xbBuilder;
      await fetch(`/wp-json/wp/v2/users/${id}?force=true&reassign=1`, { method: 'DELETE', headers: { 'X-WP-Nonce': config.nonce } });
    }, created.id);
  }
});
