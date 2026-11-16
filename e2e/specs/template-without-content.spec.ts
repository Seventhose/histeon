import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Doc 23 of Histeon Publish, item 204: a template without the block of the page's content does not travel to Publish
// (item 190). The builder says so where the template is edited, without blocking anything, and adds the block.
test('template senza il Contenuto del post: il builder avvisa e lo aggiunge con un clic', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=xb-templates');
  const card = page.locator('.xb-card').filter({ has: page.locator('code', { hasText: /^singular$/ }) });
  await card.getByRole('link', { name: 'Apri in Histeon' }).or(card.getByRole('button', { name: 'Modifica con Histeon' })).click();
  await expect(page).toHaveURL(/action=xb_builder/);
  const builder = await Builder.open(page, Number(new URL(page.url()).searchParams.get('post')));
  const note = page.locator('[data-xb-template-without-content]');

  // The theme's template has it: no warning.
  await builder.tab('HTML');
  await expect(builder.html).toContainText('{@content}');
  await expect(note).toHaveCount(0);

  // Taken out (not saved): the warning says what happens and what to do.
  await builder.edit(builder.html, (text) => text.replace('{@content}', ''));
  await expect(note).toBeVisible();
  await expect(note).toHaveAttribute('role', 'note');
  await expect(note).toContainText('A questo template manca il blocco «Contenuto del post».');
  await expect(note).toContainText('Histeon Publish');
  await expect(note).not.toContainText('WordPress');
  // Not blocking: there is something to save, and saving stays possible.
  await expect(builder.saveButton).toBeEnabled();

  await note.getByRole('button', { name: 'Aggiungi il contenuto del post' }).click();
  await expect(note).toHaveCount(0);
  await expect(builder.html).toContainText('{@content}');
  await expect(builder.status).toContainText('Contenuto del post aggiunto');
});

test('una parte di template non ha bisogno del Contenuto del post: nessun avviso', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=xb-templates');
  const card = page.locator('.xb-card').filter({ has: page.locator('code', { hasText: /^header$/ }) });
  test.skip((await card.count()) === 0, 'il tema non ha una parte header');
  await card.getByRole('link', { name: 'Apri in Histeon' }).or(card.getByRole('button', { name: 'Modifica con Histeon' })).click();
  await expect(page).toHaveURL(/action=xb_builder/);
  await Builder.open(page, Number(new URL(page.url()).searchParams.get('post')));
  await expect(page.locator('header.topbar .kind')).toHaveText('Parte di template');
  await expect(page.locator('[data-xb-template-without-content]')).toHaveCount(0);
});
