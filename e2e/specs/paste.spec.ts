import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('copia e incolla tra siti: classi nuove, uguali e in conflitto; HTML esterno ripulito', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const id = Date.now().toString(36);
  await builder.tab('HTML');
  await builder.write(builder.html, `<article class="box${id}"><h2 class="box${id}__t">T</h2></article>`);

  // A clip as another XB site would put it in the clipboard.
  const clip = JSON.stringify({
    xb: 'clip',
    version: 1,
    origin: 'altro.example',
    markup: `<!-- wp:xb/element {"uid":"aa11bb22cc","tag":"section","attributes":{"class":"box${id} nuova${id}"}} --><!-- wp:xb/text {"uid":"dd33ee44ff","content":"Incollato"} /--><!-- /wp:xb/element -->`,
    styles: [
      { selector: `.box${id}`, css: 'border: 4px solid rgb(1, 2, 3);' },
      { selector: `.nuova${id}`, css: 'color: rgb(4, 5, 6);' },
    ],
  });
  // This site already has .box<id> with other styles.
  await builder.node(`article.box${id}`).click();
  await builder.tab('CSS');
  await builder.write(builder.editor(`CSS di .box${id}`), 'padding: 9px;');
  await expect(builder.canvas.locator(`article.box${id}`)).toHaveCSS('padding-top', '9px');
  await builder.node(`article.box${id}`).click();

  const paste = async (type: string, value: string) =>
    page.evaluate(
      ([t, v]) => {
        const dt = new DataTransfer();
        dt.setData(t!, v!);
        (document.activeElement && document.activeElement !== document.body ? document.activeElement : document.body).dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true }));
      },
      [type, value],
    );
  await page.locator('body').click({ position: { x: 1, y: 1 } }).catch(() => {});
  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
  await paste('text/plain', clip);

  const dialog = page.getByRole('dialog', { name: /Incolla da altro.example/ });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('nuova: viene aggiunta')).toBeVisible();
  await expect(dialog.getByLabel('importa con un nome nuovo')).toBeChecked();
  await dialog.getByRole('button', { name: 'Incolla' }).click();
  await builder.tab('HTML');
  await expect(builder.html).toContainText(`class="box${id}-2 nuova${id}"`);
  await expect(builder.canvas.getByText('Incollato')).toHaveCSS('color', 'rgb(4, 5, 6)');
  await expect(builder.canvas.locator(`.box${id}-2`)).toHaveCSS('border-top-width', '4px');
  await expect(builder.canvas.locator(`article.box${id}`)).toHaveCSS('padding-top', '9px');

  // HTML from elsewhere: scripts and handlers never reach the page, braces stay text.
  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
  await paste('text/html', '<div class="esterno" onclick="alert(1)"><script>alert(2)</script><a href="javascript:alert(3)">link</a><p>{non espressione}</p></div>');
  await expect(builder.status).toContainText('HTML incollato');
  await expect(builder.html).toContainText('<div class="esterno">');
  await expect(builder.html).not.toContainText('alert');
  await expect(builder.html).toContainText('\\{non espressione\\}');
});
