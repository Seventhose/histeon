import { randomBytes } from 'node:crypto';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Under 1024 px the side columns are drawers and the top bar keeps Salva and Pubblica in view (lista migliorie L1).
test('tablet: colonne a cassetto, Salva e Pubblica sempre visibili, le altre azioni in «⋯»', async ({ page, data }) => {
  await page.setViewportSize({ width: 768, height: 1024 });
  const builder = await Builder.open(page, data.pages.palette.id);
  const bar = page.locator('header.topbar');
  for (const name of [/^Salva$/, 'Pubblica…']) {
    const box = await bar.getByRole('button', { name }).boundingBox();
    expect(box && box.x + box.width).toBeLessThanOrEqual(768);
  }
  expect(await builder.canvasFrame.evaluate((f) => f.getBoundingClientRect().width)).toBeGreaterThan(600);
  // Closed drawer: off screen and inert.
  await expect(builder.structure).not.toBeInViewport();
  await expect(page.locator('#xb-drawer-right')).toHaveAttribute('inert', '');

  const drawer = page.getByRole('button', { name: 'Pannelli laterali' });
  await drawer.click();
  await expect(builder.structure).toBeInViewport();
  await builder.node('h1').click();
  await drawer.click();
  await expect(builder.structure).not.toBeInViewport();

  await bar.getByRole('button', { name: 'Altre azioni' }).click();
  await expect(page.getByRole('menu', { name: 'Altre azioni' }).getByRole('menuitem').first()).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('menu', { name: 'Altre azioni' })).toHaveCount(0);
  await expect(bar.getByRole('button', { name: 'Altre azioni' })).toBeFocused();
});

test('telefono: il builder dice che serve uno schermo più largo, ma si può continuare', async ({ page, data }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(Builder.url(data.pages.palette.id));
  const notice = page.getByRole('alertdialog');
  await expect(notice).toContainText('almeno 768 px');
  await notice.getByRole('button', { name: 'Continua comunque' }).click();
  await expect(notice).toHaveCount(0);
  for (const name of [/^Salva$/, 'Pubblica…']) {
    const box = await page.locator('header.topbar').getByRole('button', { name }).boundingBox();
    expect(box && box.x + box.width).toBeLessThanOrEqual(390);
  }
});

// Client mode on a phone (L2): no notice, the canvas has the whole width, one tap writes in a text.
test('modalità cliente sul telefono: tela intera e un tocco per scrivere', async ({ page, browser, data }) => {
  const doc = data.pages.review;
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

  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: { width: 390, height: 844 } });
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
    await expect(client.getByRole('alertdialog')).toHaveCount(0);
    const frame = client.locator('iframe[data-xb-canvas]');
    // The whole width but the rail and the stage's margin (the right column used to cover most of it).
    expect(await frame.evaluate((f) => f.getBoundingClientRect().width)).toBeGreaterThan(280);
    const canvas = client.frameLocator('iframe[data-xb-canvas]');
    await canvas.getByText('Secondo paragrafo').click();
    await expect(canvas.locator('[data-xb-editing]')).toHaveAttribute('contenteditable', 'true');
    await client.keyboard.press('End');
    await client.keyboard.type(' dal telefono');
    await client.keyboard.press('Enter');
    await expect(canvas.getByText('Secondo paragrafo dal telefono')).toBeVisible();
  } finally {
    await context.close();
    await page.evaluate(async (id) => {
      const config = (window as unknown as { xbBuilder: { nonce: string } }).xbBuilder;
      await fetch(`/wp-json/wp/v2/users/${id}?force=true&reassign=1`, { method: 'DELETE', headers: { 'X-WP-Nonce': config.nonce } });
    }, created.id);
  }
});
