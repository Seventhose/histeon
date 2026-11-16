import type { Locator } from '@playwright/test';
import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

// The scheduled release (docs/spec/versioning.md §11): «Programma per…» in the Publish dialog, the line «Programmata
// per il … da …» in the dialog, in the builder and in wp-admin → Release, and «Annulla la programmazione».

let created = 0;
test.afterEach(() => {
  // Whatever happened, no release stays scheduled on the shared site, and the page goes.
  wpEval(`<?php \\XB\\Versioning\\Scheduled::cancel(); \\XB\\Versioning\\Scheduled::dismiss_last(); ${created ? `wp_delete_post( ${created}, true );` : ''}`, {});
  created = 0;
});

function page(): number {
  const content = Buffer.from(el('section', { class: 'e2e-programmata' }, el('h1', null, text('Annuncio')), el('p', { class: 'uno' }, text('Testo di oggi')))).toString('base64');
  return Number(wpEval(`<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Programmata e2e', 'post_content' => wp_slash( base64_decode( '${content}' ) ) ) );`, {}).trim().split('\n').pop());
}

/** Only this file's page goes in the release: other specs may leave their own work saved in development. */
async function onlyOurs(dialog: Locator): Promise<void> {
  const rows = dialog.locator('.change');
  await expect(rows.first()).toBeVisible();
  for (const row of await rows.all()) {
    const box = row.getByRole('checkbox');
    if ((await row.textContent())?.includes('Programmata e2e')) await box.check();
    else await box.uncheck();
  }
}

test('programma una release, vede la riga e la annulla', async ({ page: tab }) => {
  created = page();
  const builder = await Builder.open(tab, created);

  await test.step('una modifica salvata in sviluppo', async () => {
    await builder.edit(builder.html, (code) => code.replace('Testo di oggi', 'Testo di domani'));
    await builder.save();
  });

  await test.step('«Programma per…» con l’ora proposta', async () => {
    await tab.getByRole('button', { name: 'Pubblica…' }).click();
    const dialog = tab.getByRole('dialog', { name: 'Pubblica' });
    await expect(dialog.locator('.change', { hasText: 'Programmata e2e' }).getByRole('checkbox')).toBeChecked();
    await onlyOurs(dialog);
    await dialog.getByLabel('Programma per…').check();
    const when = dialog.locator('[data-xb-schedule-at]');
    await expect(when).toHaveValue(/^\d{4}-\d{2}-\d{2}T\d{2}:00$/);
    await expect(dialog.getByText(/ora del sito, /)).toBeVisible();
    await dialog.getByRole('button', { name: 'Programma', exact: true }).click();
    await expect(dialog.getByRole('status')).toHaveText(/^Programmata per il \d{2}\/\d{2}\/\d{4} alle \d{2}:00/);
    await dialog.getByRole('button', { name: 'Chiudi' }).click();
    // Nothing went online.
    const live = await tab.request.get(`/?page_id=${created}`);
    expect(await live.text()).toContain('Testo di oggi');
  });

  await test.step('la riga nel dialogo, nel builder e in wp-admin', async () => {
    await tab.getByRole('button', { name: 'Pubblica…' }).click();
    const dialog = tab.getByRole('dialog', { name: 'Pubblica' });
    await expect(dialog.locator('[data-xb-scheduled-line]')).toHaveText(/^Programmata per il .+ · versione \d+\.\d+\.\d+ · 1 documento$/);
    await expect(dialog.getByLabel('Programma per…')).toBeDisabled();
    await expect(dialog.getByText(/pubblicando ora, quella viene annullata/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Annulla', exact: true }).click();

    await Builder.open(tab, created);
    await expect(tab.locator('[data-xb-scheduled-here]')).toContainText('Questa pagina è nella release programmata');

    await tab.goto('/wp-admin/admin.php?page=xb-releases');
    await expect(tab.locator('[data-xb-scheduled]')).toContainText('Programmata per il');
    await expect(tab.getByRole('button', { name: 'Annulla la programmazione' })).toBeVisible();
  });

  await test.step('«Annulla la programmazione» dal dialogo', async () => {
    await Builder.open(tab, created);
    await tab.getByRole('button', { name: 'Pubblica…' }).click();
    const dialog = tab.getByRole('dialog', { name: 'Pubblica' });
    tab.once('dialog', (confirm) => void confirm.accept());
    await dialog.getByRole('button', { name: 'Annulla la programmazione' }).click();
    await expect(dialog.locator('[data-xb-scheduled-line]')).toHaveCount(0);
    await expect(dialog.locator('[data-xb-scheduled-last]')).toContainText('Annullata la release programmata');
    await expect(dialog.getByLabel('Programma per…')).toBeEnabled();
  });
});

// §11.4 d: with a release scheduled, «Pubblica ora» on the same documents says first that it cancels it, and does.
test('«Pubblica ora» sugli stessi documenti di una release programmata avvisa e la annulla', async ({ page: tab }) => {
  created = page();
  const builder = await Builder.open(tab, created);
  await builder.edit(builder.html, (code) => code.replace('Testo di oggi', 'Testo programmato'));
  await builder.save();

  await tab.getByRole('button', { name: 'Pubblica…' }).click();
  let dialog = tab.getByRole('dialog', { name: 'Pubblica' });
  await onlyOurs(dialog);
  await dialog.getByLabel('Programma per…').check();
  await dialog.getByRole('button', { name: 'Programma', exact: true }).click();
  await expect(dialog.getByRole('status')).toHaveText(/^Programmata per il /);
  await dialog.getByRole('button', { name: 'Chiudi' }).click();

  await tab.getByRole('button', { name: 'Pubblica…' }).click();
  dialog = tab.getByRole('dialog', { name: 'Pubblica' });
  await expect(dialog.locator('[data-xb-scheduled-line]')).toBeVisible();
  await expect(dialog.getByLabel('Pubblica ora')).toBeChecked();
  await onlyOurs(dialog);
  const warning = dialog.getByRole('note');
  await expect(warning).toHaveText(/^Questa release tocca documenti della release programmata per il \d{2}\/\d{2}\/\d{4} alle \d{2}:00: pubblicando ora, quella viene annullata\.$/);
  // Leaving the page out of this release takes the warning away; choosing it again brings it back.
  const row = dialog.locator('.change', { hasText: 'Programmata e2e' }).getByRole('checkbox');
  await row.uncheck();
  await expect(warning).toHaveCount(0);
  await row.check();
  await expect(warning).toBeVisible();
  const shots = process.env.XB_E2E_SHOTS;
  if (shots) {
    await warning.scrollIntoViewIfNeeded();
    await tab.screenshot({ path: `${shots}/v2-programmata.png` });
  }

  const version = await dialog.getByLabel('Versione').inputValue();
  await dialog.getByRole('button', { name: 'Pubblica', exact: true }).click();
  await expect(dialog.getByRole('status')).toHaveText(new RegExp(`^Pubblicata la versione ${version.replace(/\./g, '\\.')}`));
  await dialog.getByRole('button', { name: 'Chiudi' }).click();
  expect(await (await tab.request.get(`/?page_id=${created}`)).text()).toContain('Testo programmato');

  // The scheduled release is gone, and wp-admin → Release says why (the builder has nothing left to publish here).
  await tab.goto('/wp-admin/admin.php?page=xb-releases');
  const panel = tab.locator('[data-xb-scheduled]');
  await expect(panel.locator('.xb-scheduled__line')).toHaveCount(0);
  await expect(panel.locator('.xb-scheduled__last')).toContainText('Annullata la release programmata per il');
  await expect(panel.locator('.xb-scheduled__last')).toContainText(`superata dalla release ${version}, che ha pubblicato gli stessi documenti.`);
});
