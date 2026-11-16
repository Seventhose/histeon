import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// doc 23, voce 168: what the server renders for a block (raw HTML, a component, a dynamic text) reaches Gutenberg's
// canvas through a closed list of elements and attributes, as nodes built in the canvas, never as HTML parsed a
// second time. The editor-render answers are replaced here with hostile markup: nothing in it may run, and what a
// reader sees stays (text, classes, styles, static SVG, custom elements, embedded pages as an empty box).
const MAKE = String.raw`<?php
$run = getenv( 'E2E_RUN' );
$uid = static fn ( string $s ): string => 'cl' . substr( md5( $s . $run ), 0, 10 );
$content = '<!-- wp:xb/raw {"uid":"' . $uid( 'r' ) . '","content":"<p>grezzo</p>"} /-->'
	. '<!-- wp:xb/element {"uid":"' . $uid( 'e' ) . '","tag":"p","attributes":{"class":"e2e-cl-text"}} -->'
	. '<!-- wp:xb/text {"uid":"' . $uid( 't' ) . '","content":"Titolo {this.title}"} /-->'
	. '<!-- /wp:xb/element -->';
$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Pulizia ' . $run, 'post_content' => wp_slash( $content ) ) );
echo "\nE2E=" . $id . "\n";
`;

const RAW = [
  `<p class="e2e-cl-p" data-x="1" style="color: rgb(1, 2, 3)" onclick="top.__xbPwned='onclick'">Testo <strong>forte</strong></p>`,
  `<img class="e2e-cl-img" src="data:image/png;base64,AA" onerror="top.__xbPwned='onerror'" name="getElementById" alt="immagine">`,
  `<svg class="e2e-cl-svg" viewBox="0 0 10 10" width="10" height="10"><a href="javascript:top.__xbPwned='svg-a'"><path d="M0 0h10v10z" fill="red"></path></a>`,
  `<set attributeName="href" to="javascript:top.__xbPwned='set'"></set><animate attributeName="fill" values="red;blue" dur="1s"></animate></svg>`,
  `<custom-card class="e2e-cl-card">Scheda</custom-card>`,
  `<iframe class="e2e-cl-frame" width="320" src="https://example.com/"></iframe>`,
  `<script>top.__xbPwned='script'</script>`,
  `<a class="e2e-cl-link" href="java&#9;script:top.__xbPwned='link'">collegamento</a>`,
  `<form class="e2e-cl-form" name="forms"><input name="campo" value="1"></form>`,
  // Mutation XSS (MathML around raw text): last, it leaves its elements open.
  `<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;/mglyph&gt;&lt;img&Tab;src=1&Tab;onerror=top.__xbPwned='mxss'&gt;">`,
].join('');
const TEXT = `<em class="e2e-cl-em">Ciao</em><img src="data:image/png;base64,AB" onerror="top.__xbPwned='text'">`;

test('Gutenberg: l’HTML reso dal server entra nel riquadro come nodi controllati, senza nulla che giri', async ({ page }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  const id = Number(wpEval(MAKE, { E2E_RUN: run }).split('\n').find((l) => l.startsWith('E2E='))!.slice(4));
  await page.route(/editor-render/, async (route) => {
    const asked = (route.request().postDataJSON() ?? {}) as { items?: Record<string, string> };
    const response = await route.fetch();
    const json = (await response.json()) as { items?: Record<string, string> };
    for (const [key, markup] of Object.entries(asked.items ?? {})) {
      json.items = { ...(json.items ?? {}), [key]: markup.includes('wp:xb/text') ? TEXT : RAW };
    }
    await route.fulfill({ response, json });
  });
  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const rendered = canvas.locator('div.xb-editor-rendered div.xb-editor-rendered');

  // What a reader sees stays.
  const p = rendered.locator('p.e2e-cl-p');
  await expect(p).toHaveText('Testo forte', { timeout: 30_000 });
  await expect(p).toHaveAttribute('data-x', '1');
  await expect(p).toHaveCSS('color', 'rgb(1, 2, 3)');
  await expect(rendered.locator('custom-card.e2e-cl-card')).toHaveText('Scheda');
  await expect(rendered.locator('div.xb-editor-embed.e2e-cl-frame')).toHaveAttribute('width', '320');
  await expect(rendered.locator('img.e2e-cl-img')).toHaveAttribute('alt', 'immagine');
  await expect(rendered.locator('form.e2e-cl-form input')).toHaveAttribute('name', 'campo');
  const svg = await rendered.locator('svg.e2e-cl-svg').evaluate((node) => ({
    ns: node.namespaceURI,
    viewBox: node.getAttribute('viewBox'),
    paths: node.querySelectorAll('path').length,
    pathNs: node.querySelector('path')?.namespaceURI,
    animations: node.querySelectorAll('set, animate').length,
    link: node.querySelector('a')?.getAttribute('href') ?? null,
  }));
  expect(svg).toEqual({ ns: 'http://www.w3.org/2000/svg', viewBox: '0 0 10 10', paths: 1, pathNs: 'http://www.w3.org/2000/svg', animations: 0, link: null });
  await expect(canvas.locator('p.e2e-cl-text em.e2e-cl-em')).toHaveText('Ciao');

  // Nothing that runs or loads another page, no handler, no script address, no name that shadows the document.
  const left = await rendered.evaluate((node) => ({
    scripts: node.querySelectorAll('script, iframe, object, embed').length,
    handlers: [...node.querySelectorAll('*')].flatMap((n) => [...n.attributes].map((a) => a.name)).filter((a) => a.startsWith('on')).length,
    link: node.querySelector('a.e2e-cl-link')?.getAttribute('href') ?? null,
    imgName: node.querySelector('img.e2e-cl-img')?.getAttribute('name') ?? null,
    formName: node.querySelector('form.e2e-cl-form')?.getAttribute('name') ?? null,
    mathImgs: [...node.querySelectorAll('img')].filter((i) => i.getAttribute('src') === '1').length,
    shadowed: typeof (node.ownerDocument as unknown as Record<string, unknown>).forms !== 'object' || typeof node.ownerDocument.getElementById !== 'function',
  }));
  expect(left).toEqual({ scripts: 0, handlers: 0, link: null, imgName: null, formName: null, mathImgs: 0, shadowed: false });
  await expect(canvas.locator('p.e2e-cl-text img')).toHaveCount(1);
  expect(await canvas.locator('p.e2e-cl-text img').getAttribute('onerror')).toBeNull();
  await page.waitForTimeout(1_000);
  expect(await page.evaluate(() => (window as unknown as { __xbPwned?: string }).__xbPwned ?? null)).toBeNull();

  wpEval(`<?php wp_delete_post( ${id}, true );`, {});
});
