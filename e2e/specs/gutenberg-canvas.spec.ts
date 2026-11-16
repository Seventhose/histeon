import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// Gutenberg's canvas shows a Histeon page with the site's own token values, as the site does: a customer who edits
// the page there must not think it is broken (Histeon 0.3.7: a second copy of the Histeon CSS defaults came after the
// site's values in the canvas, and every text fell back to the system font).
test('Gutenberg: la pagina ha nel riquadro dell’editor lo stesso font del sito', async ({ page, data }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  wpEval(`<?php update_option( XB\\Framework\\TokenOverrides::OPTION, array( 'xb-font-body' => 'Gentium, serif', 'xb-font-heading' => 'Garamond, serif' ) );`, {});
  try {
    // The values the page's text receives (a site's own rules use them: body, h1…h6).
    const fonts = () => {
      const style = getComputedStyle(document.querySelector('.e2e-hero .uno') as Element);
      return { text: style.getPropertyValue('--xb-font-body').trim(), heading: style.getPropertyValue('--xb-font-heading').trim() };
    };
    await page.goto(`/?page_id=${data.pages.palette.id}`);
    const site = await page.evaluate(fonts);
    expect(site).toEqual({ text: 'Gentium, serif', heading: 'Garamond, serif' });

    await page.goto(`/wp-admin/post.php?post=${data.pages.palette.id}&action=edit`);
    const canvas = page.frameLocator('iframe[name="editor-canvas"]');
    await expect(canvas.locator('.e2e-hero .uno')).toBeVisible({ timeout: 30_000 });
    expect(await canvas.locator('body').evaluate(fonts)).toEqual(site);
  } finally {
    wpEval(`<?php delete_option( XB\\Framework\\TokenOverrides::OPTION );`, {});
  }
});
