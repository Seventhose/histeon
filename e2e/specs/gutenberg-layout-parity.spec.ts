import type { Page } from '@playwright/test';
import { ownPage } from '../lib/page.ts';
import { expect, test } from '../lib/test.ts';

// Doc 23 item 129: the block editor shows a Histeon page as the site does, layout included, not only the fonts
// (gutenberg-canvas.spec.ts). A section with a grid and large headings, at a fixed width so the viewport does not
// matter, is photographed on the site and in the editor's canvas; the two pictures must be nearly the same.
const run = Date.now().toString(36);
const el = (uid: string, tag: string, style: string, inner: string) =>
  `<!-- wp:xb/element {"uid":"${uid}${run}","tag":"${tag}","attributes":{"class":"pz-${uid}","style":"${style}"}} -->${inner}<!-- /wp:xb/element -->`;
const text = (uid: string, content: string) => `<!-- wp:xb/text {"uid":"t${uid}${run}","content":"${content}"} /-->`;
const card = (n: number) => el(`c${n}`, 'article', 'background:#e8e1d5;padding:24px;border-radius:12px', el(`h${n}`, 'h3', 'margin:0 0 8px;font:700 28px/1.2 sans-serif;color:#171a33', text(`h${n}`, `Scheda ${n}`)) + el(`p${n}`, 'p', 'margin:0;font:16px/1.5 sans-serif;color:#333', text(`p${n}`, 'Un testo di prova che va a capo su più righe nella colonna.')));
const MARKUP = el('s', 'section', 'width:900px;padding:32px;background:#fff;box-sizing:border-box', el('t', 'h1', 'margin:0 0 24px;font:800 64px/1.05 sans-serif;color:#c83c28', text('t', 'Titolo grande')) + el('g', 'div', 'display:grid;grid-template-columns:repeat(3,1fr);gap:24px', card(1) + card(2) + card(3)));

/** Share of pixels that differ by more than a small tolerance, between two PNG pictures of the same size. */
async function difference(page: Page, a: Buffer, b: Buffer): Promise<{ share: number; sizes: [number, number, number, number] }> {
  return page.evaluate(
    async ([one, two]) => {
      const load = async (data: string) => {
        const image = new Image();
        image.src = `data:image/png;base64,${data}`;
        await image.decode();
        const canvas = document.createElement('canvas');
        canvas.width = image.width;
        canvas.height = image.height;
        const context = canvas.getContext('2d')!;
        context.drawImage(image, 0, 0);
        return { w: image.width, h: image.height, data: context.getImageData(0, 0, image.width, image.height).data };
      };
      const [x, y] = [await load(one), await load(two)];
      const w = Math.min(x.w, y.w);
      const h = Math.min(x.h, y.h);
      let differ = 0;
      for (let row = 0; row < h; row++) {
        for (let col = 0; col < w; col++) {
          const i = (row * x.w + col) * 4;
          const j = (row * y.w + col) * 4;
          if (Math.abs(x.data[i]! - y.data[j]!) + Math.abs(x.data[i + 1]! - y.data[j + 1]!) + Math.abs(x.data[i + 2]! - y.data[j + 2]!) > 48) differ++;
        }
      }
      return { share: differ / (Math.max(x.w, y.w) * Math.max(x.h, y.h)), sizes: [x.w, x.h, y.w, y.h] as [number, number, number, number] };
    },
    [a.toString('base64'), b.toString('base64')] as const,
  );
}

test('Gutenberg: una sezione con griglia e titoli grandi è disegnata come sul sito', async ({ page }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  await page.setViewportSize({ width: 1600, height: 1000 });
  const id = ownPage(`Impaginazione ${run}`, MARKUP);
  await page.goto(`/?page_id=${id}`);
  const site = await page.locator(`section.pz-s`).screenshot({ animations: 'disabled' });

  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
  const guide = page.getByRole('dialog', { name: /Benvenut|Welcome/ });
  if (await guide.isVisible().catch(() => false)) await page.keyboard.press('Escape');
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const section = canvas.locator('section.pz-s');
  await expect(section).toBeVisible({ timeout: 30_000 });
  await page.evaluate(() => (window as unknown as { wp: { data: { dispatch: (s: string) => { clearSelectedBlock: () => void } } } }).wp.data.dispatch('core/block-editor').clearSelectedBlock());
  await page.mouse.move(1, 1);
  const editor = await section.screenshot({ animations: 'disabled' });

  const { share, sizes } = await difference(page, site, editor);
  // Same size (a grid or a margin that differs moves everything) and nearly the same pixels (text anti-aliasing).
  expect(Math.abs(sizes[0] - sizes[2]), `larghezze ${sizes[0]} e ${sizes[2]}`).toBeLessThanOrEqual(2);
  expect(Math.abs(sizes[1] - sizes[3]), `altezze ${sizes[1]} e ${sizes[3]}`).toBeLessThanOrEqual(2);
  expect(share, `pixel diversi: ${(share * 100).toFixed(2)}%`).toBeLessThan(0.02);
});
