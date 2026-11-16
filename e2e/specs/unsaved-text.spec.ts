import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// Text a panel refuses (a syntax error, unbalanced braces) is not saved, but it is the user's work: it counts as
// unsaved, saving says so, and nothing replaces it without asking (lista migliorie U1).
test('il testo rifiutato dal pannello HTML conta come non salvato e non sparisce senza chiedere', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.due').click();
  await expect(builder.html).toContainText('Secondo paragrafo');

  await builder.edit(builder.html, (code) => code.replace('Secondo paragrafo', 'Secondo {paragrafo'));
  await expect(builder.status).toHaveText('Non salvato: HTML non valido alla riga 1');
  await expect(builder.saveButton).toBeEnabled();

  // Ctrl+S says why nothing was saved.
  await page.keyboard.press('ControlOrMeta+s');
  await expect(builder.status).toHaveText('HTML non valido alla riga 1: non salvato');

  // Another selection would reprint the panel: it asks first; "no" keeps the text and the selection.
  const questions: string[] = [];
  page.once('dialog', (dialog) => {
    questions.push(dialog.message());
    void dialog.dismiss();
  });
  await builder.node('h1').click();
  await expect.poll(() => questions).toEqual(['HTML non valido alla riga 1: il testo non valido non è salvato e andrà perso. Continuare?']);
  await expect(builder.html).toContainText('Secondo {paragrafo');

  // Leaving the page warns.
  const leaving = new Promise<string>((resolve) => page.once('dialog', (dialog) => (resolve(dialog.type()), void dialog.dismiss())));
  await page.close({ runBeforeUnload: true });
  expect(await leaving).toBe('beforeunload');
});

test('dopo la conferma, il cambio di selezione mostra il nuovo elemento e non resta nulla di non salvato', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.node('p.due').click();
  await builder.edit(builder.html, (code) => code.replace('Secondo paragrafo', 'Secondo {paragrafo'));
  await expect(builder.status).toHaveText('Non salvato: HTML non valido alla riga 1');

  page.once('dialog', (dialog) => void dialog.accept());
  await builder.node('h1').click();
  await expect(builder.html).toContainText('Ciao e2e');
  await expect(builder.status).toHaveText('');
  await expect(builder.saveButton).toBeDisabled();
});

test('le graffe non bilanciate in una regola del foglio globale contano come non salvate', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  const block = `graffe${Date.now().toString(36)}`;
  await builder.write(builder.html, `<div class="${block}">Graffe</div>`);
  await page.keyboard.press('Escape');
  await builder.tab('CSS');
  const global = builder.editor('CSS globale');
  const before = await builder.source(global);
  await builder.write(global, `${before}\n.${block} { padding: 7px; }\n`);
  await expect(builder.canvas.locator(`div.${block}`)).toHaveCSS('padding-top', '7px');

  await builder.node(`div.${block}`).click();
  const rule = builder.editor(`Regola di .${block} nel foglio global`);
  await builder.write(rule, `.${block} { padding: 9px;`);
  await expect(builder.status).toHaveText(`Non salvato: CSS di .${block} con graffe non bilanciate`);

  // Closing the brace makes it a normal edit again.
  await rule.click();
  await page.keyboard.press('ControlOrMeta+End');
  await page.keyboard.insertText(' }');
  await expect(builder.status).toHaveText('Modifiche non salvate');
  await expect(builder.canvas.locator(`div.${block}`)).toHaveCSS('padding-top', '9px');
});

// A text written in the canvas that the canvas cannot take is no longer thrown away: it goes to the HTML panel,
// as typed (lista migliorie U2).
test('un testo che la tela rifiuta finisce nel pannello HTML invece di sparire', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.palette.id);
  await builder.canvas.getByText('Primo paragrafo').dblclick();
  const editing = builder.canvas.locator('[data-xb-editing]');
  await expect(editing).toHaveAttribute('contenteditable', 'true');
  // Typing cannot produce markup the canvas refuses (pasting gives plain text): set it as the browser's editing could.
  await builder.canvasFrame.evaluate((frame: HTMLIFrameElement) => {
    const el = frame.contentDocument?.querySelector('[data-xb-editing]');
    if (el) el.innerHTML = 'Primo <a href="{post.">collegamento scritto</a> qui';
  });
  await page.keyboard.press('Enter');
  await expect(editing).toHaveCount(0);

  // The canvas shows the text as it was; the code panel holds what was written, and it counts as unsaved.
  await expect(builder.canvas.getByText('Primo paragrafo', { exact: true })).toBeVisible();
  await expect(builder.status).toHaveText('Questo testo non si può scrivere nella pagina: è nel pannello HTML, da completare lì');
  await expect(builder.html).toContainText('<p class="uno">Primo <a href="{post.">collegamento scritto</a> qui</p>');
  await expect(builder.saveButton).toBeEnabled();
});
