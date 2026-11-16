import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('Histeon → Template apre il template nel builder e il selettore cambia articolo di esempio', async ({ page, data }) => {
  await page.goto('/wp-admin/admin.php?page=xb');
  await page.locator('#toplevel_page_xb').getByRole('link', { name: 'Template', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Template', level: 1 })).toBeVisible();

  // A theme file is first written to the database (POST with nonce), then the builder opens it.
  const card = page.locator('.xb-card').filter({ has: page.locator('code', { hasText: /^singular$/ }) });
  await card.getByRole('link', { name: 'Apri in Histeon' }).or(card.getByRole('button', { name: 'Modifica con Histeon' })).click();
  await expect(page).toHaveURL(/action=xb_builder/);
  await expect(page.locator('header.topbar .kind')).toHaveText('Template');
  const builder = await Builder.open(page, Number(new URL(page.url()).searchParams.get('post')));

  const picker = page.getByLabel('Anteprima con');
  const titles = await picker.locator('option').allInnerTexts();
  for (const post of Object.values(data.posts)) expect(titles).toContain(post.title);

  const title = builder.canvas.locator('h1.entry__title');
  for (const post of [data.posts.post1, data.posts.post3]) {
    await picker.selectOption({ label: post.title });
    await expect(title).toHaveText(post.title);
    // The post content, rendered by WordPress (texturized apostrophe).
    await expect(builder.canvas.locator('.entry__content')).toHaveText(new RegExp(`^Testo dell.articolo e2e ${post.title.split(' ')[2]}\\.$`));
  }

  // The signed preview of a template opens over the builder, on the chosen sample post.
  await page.getByRole('button', { name: 'Anteprima' }).click();
  const preview = page.frameLocator('iframe[title="Anteprima della pagina in sviluppo"]');
  await expect(preview.getByRole('heading', { name: data.posts.post3.title })).toBeVisible();
  await expect(preview.getByText('Anteprima di sviluppo Histeon: non è il sito pubblicato')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog', { name: 'Anteprima della pagina' })).toHaveCount(0);
});
