import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('testo nella tela: doppio clic, si scrive, Invio conferma, Esc annulla', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const heading = builder.canvas.getByRole('heading', { name: 'Ciao e2e' });

  // Double click: the heading becomes editable in place.
  await heading.dblclick();
  const editing = builder.canvas.locator('[data-xb-editing]');
  await expect(editing).toHaveAttribute('contenteditable', 'true');
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.type('Titolo {scritto} qui');
  await page.keyboard.press('Enter');
  await expect(editing).toHaveCount(0);
  // Typed braces are text, never an expression.
  await expect(builder.html).toContainText('<h1>Titolo \\{scritto\\} qui</h1>');
  await expect(builder.canvas.getByRole('heading', { name: 'Titolo {scritto} qui' })).toBeVisible();
  await expect(builder.status).toHaveText('Modifiche non salvate');

  // Formatting the DSL can represent is kept.
  await builder.canvas.getByText('Primo paragrafo').dblclick();
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.press('ControlOrMeta+b');
  await page.keyboard.press('Enter');
  await expect(builder.html).toContainText('<strong>Primo paragrafo</strong>');

  // Esc puts the text back.
  await builder.canvas.getByText('Secondo paragrafo').dblclick();
  await page.keyboard.type(' da buttare');
  await page.keyboard.press('Escape');
  await expect(builder.canvas.getByText('Secondo paragrafo', { exact: true })).toBeVisible();
  await expect(builder.html).not.toContainText('da buttare');

  // One undo step per edit.
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).toBeVisible();

  // From the structure's context menu too.
  await builder.node('p.due').click({ button: 'right' });
  await page.getByRole('menuitem', { name: 'Scrivi il testo nella pagina' }).click();
  await expect(builder.canvas.locator('[data-xb-editing]')).toHaveText('Secondo paragrafo');
  await page.keyboard.press('Escape');
});
