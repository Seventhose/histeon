import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The site's fonts in the builder's design system (E2). No request leaves for Google here: the font is uploaded
// as a file from Settings, and the Google list is only searched.
test('font del sito nel Design system: anteprima, elenco Google cercabile, scelta per il generatore (E2)', async ({ page, data }) => {
  const id = Date.now().toString(36).replace(/[0-9]/g, 'x');
  const family = `Xbt Font ${id}`;
  await page.goto('/wp-admin/admin.php?page=xb-settings');
  const upload = page.locator('form').filter({ has: page.locator('input[name="op"][value="upload"]') });
  await upload.locator('input[type="file"]').setInputFiles({ name: 'font.woff2', mimeType: 'font/woff2', buffer: Buffer.concat([Buffer.from('wOF2'), Buffer.alloc(256, 1)]) });
  await upload.getByLabel('Famiglia').fill(family);
  await upload.getByRole('button', { name: 'Carica' }).click();
  await expect(page.getByText(family).first()).toBeVisible();

  await Builder.open(page, data.pages.palette.id);
  await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Design system' }).click();
  const fonts = page.getByRole('region', { name: 'Font del sito' });
  const row = fonts.getByRole('listitem').filter({ hasText: family });
  await expect(row).toContainText('400');
  // The sample is written in the family itself, served by the site.
  await expect(row.locator('.sample')).toHaveCSS('font-family', new RegExp(`"?${family}"?`));

  // The Google list is bundled: searching it asks nobody.
  const outside: string[] = [];
  page.on('request', (r) => {
    if (/fonts\.(googleapis|gstatic)\.com/.test(r.url())) outside.push(r.url());
  });
  await fonts.getByRole('searchbox').fill('lora');
  await fonts.getByRole('button', { name: /^Lora/ }).click();
  await expect(fonts.getByRole('group', { name: 'Lora' }).getByRole('checkbox', { name: '700' })).toBeChecked();
  await expect(fonts.getByRole('button', { name: 'Scarica sul sito' })).toBeEnabled();
  expect(outside).toEqual([]);

  // With Histeon CSS, one click makes it the headings' typeface in the generator.
  const heading = row.getByRole('button', { name: 'Titoli' });
  if (await heading.count()) {
    await heading.click();
    // The generator is the Histeon CSS panel: its Fonts tab shows the stack.
    const generator = page.getByRole('dialog', { name: 'Design system' });
    await generator.getByRole('tab', { name: 'Caratteri' }).click();
    await expect(generator.getByRole('textbox', { name: 'Titoli', exact: true })).toHaveValue(`"${family}", sans-serif`);
  }

  // Removed from the site.
  page.once('dialog', (dialog) => void dialog.accept());
  await row.getByRole('button', { name: `Togli ${family}` }).click();
  await expect(row).toHaveCount(0);
  await expect(fonts.getByRole('status')).toContainText('tolto dal sito');
});
