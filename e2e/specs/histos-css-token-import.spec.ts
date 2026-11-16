import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// «Importa i token (DTCG)» of the Histeon CSS panel and «Importa variabili» of the Design system
// (docs/ricostruire/schede/442-token-importati.md): files of design tokens as Figma and Penpot export them.
// Nothing is saved here: the preview on the page and the lists of the panel are what is checked.
const framework = (id?: string) =>
  wpEval(`<?php ${id === undefined ? '' : id === '' ? "delete_option('xb_framework');" : `update_option('xb_framework', '${id.replace(/[^a-z-]/g, '')}');`} echo (string) get_option('xb_framework');`, {}).trim();

const srgb = (hex: string) => ({ colorSpace: 'srgb', components: [1, 3, 5].map((at) => Number.parseInt(hex.slice(at, at + 2), 16) / 255), alpha: 1, hex });
const json = (name: string, data: unknown) => ({ name, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(data)) });
/** One mode of a Figma variable collection («Export modes»), and its dark mode. */
const light = {
  color: { $type: 'color', brand: { primary: { $value: srgb('#1D4ED8') } }, sabbia: { $value: srgb('#F5EFE6') } },
  radius: { md: { $type: 'number', $value: 12 } },
  font: { size: { caption: { $type: 'number', $value: 13 } } },
};
const dark = { color: { $type: 'color', brand: { primary: { $value: srgb('#93C5FD') } } } };
/** A Penpot export: token sets, numbers as texts, a formula. */
const penpot = {
  Globale: {
    accent: { $value: '#0ea5a0', $type: 'color' },
    raggio: { card: { $value: '{base} * 2', $type: 'borderRadius' } },
    base: { $value: '8', $type: 'spacing' },
  },
  $themes: [],
  $metadata: { tokenSetOrder: ['Globale'] },
};

test('cassetto Histeon CSS: «Importa i token (DTCG)» legge Figma e Penpot, anteprima sulla pagina, rapporto', async ({ page }) => {
  const before = framework();
  try {
    framework('xb-tokens');
    await page.goto('/');
    await page.keyboard.press('Alt+Shift+H');
    const dialog = page.getByRole('dialog', { name: 'Histeon CSS' });
    await expect(dialog).toBeVisible();
    const token = (name: string) => page.evaluate((n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);
    const primaryBefore = await token('--xb-primary');
    await expect(dialog.getByRole('button', { name: 'Importa i token (DTCG)' })).toBeVisible();
    const input = dialog.locator('input[type="file"][multiple]');
    // The drawer has a status of its own (the preview note): the panel's message is the paragraph.
    const status = dialog.locator('p.msg[role="status"]');

    // Figma: the light mode and the dark one, told by the file name.
    await input.setInputFiles([json('Light.tokens.json', light), json('Dark.tokens.json', dark)]);
    await expect(status).toContainText('Token importati: ');
    // Over a page there is no list of site variables: the panel says where the other tokens go.
    await expect(status).toContainText('«Importa variabili»');
    await expect.poll(() => token('--xb-radius-m')).toBe('0.75rem');
    expect(await token('--xb-primary')).not.toBe(primaryBefore);
    // The text size became a step, never a size in px.
    const report = dialog.locator('details.report');
    await report.locator('summary').click();
    await expect(report).toContainText('font.size.caption');
    await expect(report).toContainText('xb-text-');
    await expect(dialog.getByRole('button', { name: 'Salva in sviluppo' })).toBeEnabled();
    await dialog.getByRole('tab', { name: 'Colori' }).click();
    await expect(dialog.getByRole('checkbox', { name: 'Tema scuro' })).toBeChecked();

    // Penpot: sets, numbers as texts, a formula with a reference.
    await input.setInputFiles([json('tokens.json', penpot)]);
    await expect(status).toContainText('Token importati: ');
    await expect.poll(() => token('--xb-radius-m')).toBe('1rem');

    // A file that is not JSON is refused with a message, and the values stay.
    await input.setInputFiles([{ name: 'rotto.json', mimeType: 'application/json', buffer: Buffer.from('{') }]);
    await expect(dialog.getByRole('alert')).toHaveText('Il file non è JSON.');
    await expect.poll(() => token('--xb-radius-m')).toBe('1rem');
  } finally {
    framework(before);
  }
});

test('design system: i token importati aggiungono le variabili del sito; «Importa variabili» legge un file', async ({ page, data }) => {
  const before = framework();
  try {
    framework('xb-tokens');
    await Builder.open(page, data.pages.palette.id);
    await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Design system' }).click();
    const panel = page.getByRole('dialog', { name: 'Design system' });
    const site = panel.getByRole('region', { name: 'Variabili del sito' });
    await expect(site).toBeVisible();
    const root = page.frameLocator('iframe[title="Anteprima della pagina"]').locator('html');

    // In the builder the tokens without a parameter of the design become variables of the site at once.
    await panel.locator('.hcp input[type="file"][multiple]').setInputFiles([json('Light.tokens.json', light)]);
    await expect(panel.locator('.hcp').getByRole('status')).toContainText('Variabili del sito aggiunte: 1.');
    await expect(site.getByLabel('Valore di --xb-site-color-sabbia', { exact: true })).toHaveValue('#f5efe6');
    await expect(root).toHaveAttribute('style', /--xb-site-color-sabbia: #f5efe6/);

    // «Importa variabili»: the file Histeon Publish writes; a value that could break the sheet is left out and counted.
    await expect(site.getByRole('button', { name: 'Importa variabili' })).toBeVisible();
    await site.locator('input[type="file"]').setInputFiles([json('variabili-sito.json', { format: 'histeon-variabili-sito', version: 1, variabili: { 'spazio-card': '1.5rem', cattiva: 'red;} body{display:none' } })]);
    await expect(panel.getByText('Variabili del sito importate: 1; lasciate fuori 1.')).toBeVisible();
    await expect(site.getByLabel('Valore di --xb-site-spazio-card', { exact: true })).toHaveValue('1.5rem');
    await expect(site.getByLabel('Valore di --xb-site-cattiva', { exact: true })).toHaveCount(0);
    await expect(root).toHaveAttribute('style', /--xb-site-spazio-card: 1\.5rem/);
    await expect(panel.getByRole('button', { name: 'Salva in sviluppo' })).toBeEnabled();
  } finally {
    framework(before);
  }
});
