import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

/** Sets the test user's own language over REST (the builder's nonce); '' is the site's language (Italian here). */
async function setLocale(page: import('@playwright/test').Page, locale: string): Promise<void> {
  const status = await page.evaluate(async (value) => {
    const config = (window as unknown as { xbBuilder: { nonce: string } }).xbBuilder;
    const res = await fetch('/wp-json/wp/v2/users/me', {
      method: 'POST',
      headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
      body: JSON.stringify({ locale: value }),
    });
    return res.status;
  }, locale);
  expect(status).toBe(200);
}

test('interfaccia in inglese per chi non usa l’italiano: builder e schermate di Histeon', async ({ page, data }) => {
  const doc = data.pages.save;
  await Builder.open(page, doc.id);
  await setLocale(page, 'en_US');
  try {
    // The builder: the same page, now in English, still working (the canvas is found by its attribute).
    const response = await page.goto(Builder.url(doc.id));
    // The catalog is a cached file of its own, not 95 kB inside a page that is never cached (V5).
    const html = (await response?.text()) ?? '';
    expect(html).toMatch(/<script nonce="[^"]+" src="[^"]+\/assets\/builder\/strings-en_US\.js\?ver=\d+"><\/script>/);
    expect(html).not.toContain('"strings"');
    expect(html.length).toBeLessThan(20_000);
    await expect(page.locator('header.topbar').getByRole('button', { name: 'Publish…' })).toBeVisible();
    await expect(page.locator('iframe[data-xb-canvas]')).toHaveAttribute('title', 'Page preview');
    await expect(page.locator('iframe[data-xb-canvas]')).toHaveAttribute('srcdoc', /xb-live/);
    await expect(page.getByRole('navigation', { name: 'Structure' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en-US');

    // An admin screen, from the .mo file.
    await page.goto('/wp-admin/admin.php?page=xb-releases');
    await expect(page.getByRole('heading', { name: 'Releases', level: 1 })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Export the whole site' })).toBeVisible();
  } finally {
    await page.goto(Builder.url(doc.id));
    await setLocale(page, '');
  }
  const italian = await page.goto(Builder.url(doc.id));
  await expect(page.locator('header.topbar').getByRole('button', { name: 'Pubblica…' })).toBeVisible();
  expect(await italian?.text()).not.toContain('strings-en_US');
});
