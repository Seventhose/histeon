import { expect, test } from '../lib/test.ts';

// The Histeon code under Gutenberg's canvas loads CodeMirror only when a code editor is shown (lista migliorie V8):
// the inspector script itself is small, and a post without Histeon blocks never asks for the editor.
test('Gutenberg: l’inspector è leggero e CodeMirror arriva solo quando serve un editor di codice', async ({ page, data }) => {
  // Nothing leaves this machine: what the block editor asks of other sites (fonts, patterns…) gets an empty answer.
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const scripts: string[] = [];
  page.on('request', (r) => {
    if (/assets\/gutenberg\/inspector(-code)?\.js/.test(r.url())) scripts.push(new URL(r.url()).pathname.split('/').pop()!);
  });
  const quiet = async () => {
    // The block editor's welcome guide, when it shows: closed, it stays closed for this user.
    const guide = page.getByRole('dialog', { name: /Benvenut|Welcome/ });
    if (await guide.isVisible().catch(() => false)) await page.keyboard.press('Escape');
  };

  // A post without Histeon blocks: the inspector loads, the code editor does not.
  await page.goto(`/wp-admin/post.php?post=${data.posts.post1.id}&action=edit`);
  await expect(page.locator('.editor-styles-wrapper, iframe[name="editor-canvas"]').first()).toBeVisible({ timeout: 30_000 });
  await quiet();
  await page.waitForTimeout(1500);
  expect(scripts).toContain('inspector.js');
  expect(scripts).not.toContain('inspector-code.js');

  // A page of Histeon blocks: the code dock shows CodeMirror, fetched as a module next to the inspector.
  scripts.length = 0;
  await page.goto(`/wp-admin/post.php?post=${data.pages.palette.id}&action=edit`);
  await quiet();
  const dock = page.locator('.xb-dock');
  await expect(dock).toBeVisible({ timeout: 30_000 });
  if ((await dock.getByRole('button', { name: /Histeon · Codice/ }).getAttribute('aria-expanded')) !== 'true') {
    await dock.getByRole('button', { name: /Histeon · Codice/ }).click();
  }
  await expect(dock.locator('.cm-editor').first()).toBeVisible({ timeout: 30_000 });
  expect(scripts).toContain('inspector-code.js');
});
