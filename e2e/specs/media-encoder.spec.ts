import { crc32 } from 'node:zlib';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The image encoder (lista migliorie M5): jSquash in a worker served by the plugin, allowed by the page's CSP for
// that one path, with no 'unsafe-eval' and no 'wasm-unsafe-eval' on the page.

async function openManager(page: import('@playwright/test').Page) {
  await page.locator('header.topbar').getByRole('button', { name: 'Aggiungi un elemento' }).click();
  await page.getByRole('searchbox', { name: 'Cerca', exact: true }).fill('immagine dalla');
  await page.keyboard.press('Enter');
  const manager = page.getByRole('dialog', { name: 'Gestore dei media' });
  await expect(manager).toBeVisible();
  return manager;
}

type Media = { mime_type: string; source_url: string; media_details: { width?: number; filesize?: number } };

test('una foto diventa AVIF o WebP, il più leggero, nel worker con i codec del plugin, sotto la CSP', async ({ page, data }) => {
  const violations: string[] = [];
  await page.exposeFunction('xbViolation', (text: string) => violations.push(text));
  await page.addInitScript(() => document.addEventListener('securitypolicyviolation', (e) => (window as unknown as { xbViolation(t: string): void }).xbViolation(`${e.violatedDirective} ${e.blockedURI}`)));
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);

  const csp = builder.response?.headers()['content-security-policy'] ?? '';
  expect(csp).not.toContain('unsafe-eval');
  expect(csp).toMatch(/worker-src https?:\/\/[^ ;]+\/assets\/builder\/media\/encoder\.js(;|$)/);

  // A 3000×2000 photo-like JPEG (gradients and noise) made in the page.
  const jpeg = await page.evaluate(() => {
    const c = document.createElement('canvas');
    c.width = 3000;
    c.height = 2000;
    const g = c.getContext('2d')!;
    const gradient = g.createLinearGradient(0, 0, 3000, 2000);
    gradient.addColorStop(0, '#2b5876');
    gradient.addColorStop(1, '#f4a261');
    g.fillStyle = gradient;
    g.fillRect(0, 0, 3000, 2000);
    let seed = 7;
    const random = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
    for (let i = 0; i < 4000; i++) {
      g.fillStyle = `hsla(${random() * 360}, 60%, ${30 + random() * 40}%, 0.35)`;
      g.beginPath();
      g.arc(random() * 3000, random() * 2000, 4 + random() * 60, 0, Math.PI * 2);
      g.fill();
    }
    return c.toDataURL('image/jpeg', 0.92).split(',')[1]!;
  });
  const original = Buffer.from(jpeg, 'base64');

  const codecs: string[] = [];
  page.on('requestfinished', (request) => {
    if (/\/assets\/builder\/media\//.test(request.url())) codecs.push(new URL(request.url()).pathname.split('/').pop()!);
  });
  const manager = await openManager(page);
  const started = Date.now();
  await manager.locator('input[type="file"]').setInputFiles({ name: `foto-${id}.jpg`, mimeType: 'image/jpeg', buffer: original });
  await expect(manager.getByRole('button', { name: new RegExp(`Dettagli di foto-${id}`) })).toBeVisible({ timeout: 60_000 });
  const elapsed = Date.now() - started;

  // The worker and both codecs came from the plugin, nothing from anywhere else.
  expect(codecs.some((name) => name === 'encoder.js')).toBe(true);
  expect(codecs.some((name) => /^avif_enc-.*\.wasm$/.test(name))).toBe(true);
  expect(codecs.some((name) => /^webp_enc(_simd)?-.*\.wasm$/.test(name))).toBe(true);
  expect(codecs.some((name) => /^squoosh_resize_bg-.*\.wasm$/.test(name))).toBe(true);
  expect(violations).toEqual([]);

  const media = await builder.api<Media[]>('GET', `../../wp/v2/media?search=foto-${id}&_fields=mime_type,source_url,media_details`);
  expect(['image/avif', 'image/webp']).toContain(media[0]?.mime_type);
  expect(media[0]?.source_url).toMatch(new RegExp(`foto-${id}(-\\d+)?\\.(avif|webp)$`));
  const sent = (await page.request.get(media[0]!.source_url)).body();
  const bytes = (await sent).length;
  expect(bytes).toBeLessThan(original.length);
  console.log(`M5: JPEG ${original.length} B → ${media[0]?.mime_type} ${bytes} B, ${elapsed} ms (codec: ${codecs.join(', ')})`);
});

test('un PNG animato e un PNG senza perdita: il primo resta com\'è, il secondo non diventa mai AVIF', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  const png = Buffer.from(
    await page.evaluate(() => {
      const c = document.createElement('canvas');
      c.width = 1200;
      c.height = 800;
      const g = c.getContext('2d')!;
      for (let i = 0; i < 60; i++) {
        g.fillStyle = `hsl(${i * 6}, 70%, 50%)`;
        g.fillRect((i * 97) % 1200, (i * 61) % 800, 200, 120);
      }
      return c.toDataURL('image/png').split(',')[1]!;
    }),
    'base64',
  );
  // The same PNG with an acTL chunk (one frame, played once) before its image data: an animated PNG to browsers.
  const actl = Buffer.alloc(20);
  actl.writeUInt32BE(8, 0);
  actl.write('acTL', 4, 'latin1');
  actl.writeUInt32BE(1, 8);
  actl.writeUInt32BE(0, 12);
  actl.writeUInt32BE(crc32(actl.subarray(4, 16)), 16);
  const animated = Buffer.concat([png.subarray(0, 33), actl, png.subarray(33)]);

  const manager = await openManager(page);
  await manager.locator('input[type="file"]').setInputFiles([
    { name: `animato-${id}.png`, mimeType: 'image/png', buffer: animated },
    { name: `grafica-${id}.png`, mimeType: 'image/png', buffer: png },
  ]);
  await expect(manager.getByRole('button', { name: new RegExp(`Dettagli di grafica-${id}`) })).toBeVisible({ timeout: 60_000 });
  await expect(manager.getByRole('button', { name: new RegExp(`Dettagli di animato-${id}`) })).toBeVisible();

  const animatedMedia = await builder.api<Media[]>('GET', `../../wp/v2/media?search=animato-${id}&_fields=mime_type,source_url,media_details`);
  expect(animatedMedia[0]?.mime_type).toBe('image/png');
  expect((await (await page.request.get(animatedMedia[0]!.source_url)).body()).equals(animated)).toBe(true);

  const graphic = await builder.api<Media[]>('GET', `../../wp/v2/media?search=grafica-${id}&_fields=mime_type,source_url`);
  expect(['image/png', 'image/webp']).toContain(graphic[0]?.mime_type);
});
