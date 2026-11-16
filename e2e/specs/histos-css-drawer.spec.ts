import { readFileSync } from 'node:fs';
import type { Download } from '@playwright/test';
import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// The Histeon CSS drawer over a page of the site (docs/spec/histos-css.md §6): the panel of the builder's Design
// system (editor/src/histos-css), with the page as its preview. Nothing is saved here.
const framework = (id?: string) =>
  wpEval(`<?php ${id === undefined ? '' : id === '' ? "delete_option('xb_framework');" : `update_option('xb_framework', '${id.replace(/[^a-z-]/g, '')}');`} echo (string) get_option('xb_framework');`, {}).trim();

test('cassetto Histeon CSS: anteprima sulla pagina, ricerca in inglese, ↺, esporta e importa', async ({ page, consoleErrors }) => {
  const before = framework();
  try {
    framework('xb-tokens');
    await page.goto('/');
    await page.keyboard.press('Alt+Shift+H');
    const dialog = page.getByRole('dialog', { name: 'Histeon CSS' });
    await expect(dialog).toBeVisible();
    const token = (name: string) => page.evaluate((n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(), name);

    // A value: the page shows its tokens at once, the option gets its dot and its ↺.
    await dialog.getByRole('tab', { name: 'Forme' }).click();
    const radius = dialog.getByRole('spinbutton', { name: 'Raggio medio (px)' });
    await radius.fill('4');
    await radius.press('Enter');
    await expect.poll(() => token('--xb-radius-m')).toBe('0.25rem');
    const undo = dialog.getByRole('button', { name: 'Torna al predefinito: Raggio medio (px)' });
    await expect(undo).toBeEnabled();
    await expect(dialog.getByRole('button', { name: 'Salva in sviluppo' })).toBeEnabled();

    // The search finds an option by its English words too.
    const search = dialog.getByRole('searchbox', { name: 'Cerca un’opzione' });
    await search.fill('line length');
    await expect(dialog.getByRole('spinbutton', { name: 'Misura del testo (ch)' })).toBeVisible();
    await expect(dialog.getByRole('spinbutton', { name: 'Raggio medio (px)' })).toHaveCount(0);
    await search.fill('');

    // Export: only what differs from the defaults.
    const download = page.waitForEvent('download');
    await dialog.getByRole('button', { name: 'Esporta', exact: true }).click();
    const file = JSON.parse(readFileSync((await (await download).path())!, 'utf8'));
    expect(file).toMatchObject({ format: 'histos-css-design', version: 1, design: { radius: 4 } });
    expect(file.design).not.toHaveProperty('text_min');

    await undo.click();
    await expect.poll(() => token('--xb-radius-m')).toBe('0.5rem');

    // Import: validated by the server before the panel takes it; a file with an unknown name is refused.
    // The design file's input; the tokens' one («Importa i token (DTCG)») takes several files.
    const input = dialog.locator('input[type="file"]:not([multiple])');
    const upload = (design: unknown) => input.setInputFiles({ name: 'histos-css.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify({ format: 'histos-css-design', version: 1, design })) });
    await upload({ radius: 12 });
    await expect.poll(() => token('--xb-radius-m')).toBe('0.75rem');
    await upload({ evil: 1 });
    await expect(dialog.getByRole('alert')).toHaveText('Parametro sconosciuto: evil');
    await upload({ radius: 999 });
    await expect(dialog.getByRole('alert')).toContainText('radius');
    // The server's refusal (400) is the one expected error of the page.
    await expect.poll(() => consoleErrors.filter((e) => e.includes('status of 400')).length).toBe(1);
    consoleErrors.splice(consoleErrors.findIndex((e) => e.includes('status of 400')), 1);
    await expect.poll(() => token('--xb-radius-m')).toBe('0.75rem');

    // Defaults, after a confirmation.
    await dialog.getByRole('button', { name: 'Predefiniti' }).click();
    await dialog.getByRole('button', { name: 'Sì' }).click();
    await expect.poll(() => token('--xb-radius-m')).toBe('0.5rem');
    await expect(radius).toHaveValue('8');

    // The colours: one row per family, the shades on a click.
    await dialog.getByRole('tab', { name: 'Colori' }).click();
    await dialog.getByRole('button', { name: 'Sfumature e trasparenze di Primario' }).click();
    await expect(dialog.getByRole('button', { name: 'Copia var(--xb-primary-700)' })).toBeVisible();

    // The tertiary family from its switch; the dark theme for the whole site from one switch, readable (AA).
    await dialog.getByRole('checkbox', { name: 'Terziario: attivo' }).check();
    await expect.poll(() => token('--xb-tertiary-500')).toMatch(/^oklch\(/);
    await dialog.getByRole('checkbox', { name: 'Tema scuro' }).check();
    await dialog.getByRole('combobox', { name: 'Schema del sito' }).selectOption('dark');
    await expect.poll(() => token('--xb-bg')).toBe(await token('--xb-neutral-950'));
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).colorScheme)).toBe('dark');
    await expect(dialog.getByText('Testo su sfondo, tema scuro')).toBeVisible();
    await expect(dialog.getByText('tutto leggibile')).toBeVisible();

    // Typography: with the default heading styles, a level's weight changes every heading of that level.
    await dialog.getByRole('tab', { name: 'Testo' }).click();
    await dialog.getByRole('checkbox', { name: 'Stili predefiniti dei titoli (h1-h6)' }).check();
    const weight = dialog.getByRole('spinbutton', { name: 'H2: Peso' });
    await weight.fill('300');
    await weight.press('Enter');
    const weightOf = (tag: string) => page.evaluate((t) => {
      const el = document.body.appendChild(document.createElement(t));
      const w = getComputedStyle(el).fontWeight;
      el.remove();
      return w;
    }, tag);
    await expect.poll(() => weightOf('h2')).toBe('300');
    expect(await weightOf('h3')).toBe('700');
    // A level's own colour: every h2 takes it, h3 keeps the headings' one.
    await dialog.getByRole('combobox', { name: 'H2: Colore' }).selectOption('primary');
    const colourOf = (tag: string) => page.evaluate((t) => {
      const el = document.body.appendChild(document.createElement(t));
      const c = getComputedStyle(el).color;
      el.remove();
      return c;
    }, tag);
    await expect.poll(async () => (await colourOf('h2')) !== (await colourOf('h3'))).toBe(true);

    // Layout: a page with the content grid has a full, a wide and a content area. The page prints only the classes it
    // uses (CSS on demand): the whole sheet is loaded for this element added here.
    await page.addStyleTag({ url: '/wp-content/plugins/xb/assets/framework/histos.css' });
    const widths = await page.evaluate(() => {
      const grid = document.body.appendChild(document.createElement('div'));
      grid.className = 'xb-content-grid';
      const areas = ['', 'xb-wide', 'xb-full'].map((name) => {
        const child = grid.appendChild(document.createElement('div'));
        child.className = name;
        child.textContent = name || 'content';
        return child;
      });
      const out = areas.map((child) => child.getBoundingClientRect().width);
      grid.remove();
      return out;
    });
    expect(widths[0]).toBeLessThan(widths[1]!);
    expect(widths[1]).toBeLessThan(widths[2]!);
    expect(widths[2]).toBe(await page.evaluate(() => document.body.clientWidth));

    // The boxed layout: the page narrower than the window, centred.
    await dialog.getByRole('tab', { name: 'Spazi' }).click();
    await dialog.getByRole('checkbox', { name: 'Layout a riquadro' }).check();
    const riquadro = dialog.getByRole('spinbutton', { name: 'Riquadro: larghezza (rem)' });
    await riquadro.fill('40');
    await riquadro.press('Enter');
    await expect.poll(() => page.evaluate(() => document.body.getBoundingClientRect().width)).toBe(640);
    await dialog.getByRole('checkbox', { name: 'Layout a riquadro' }).uncheck();

    // Buttons: the radius chosen in the panel styles every button and every variant.
    await dialog.getByRole('tab', { name: 'Forme' }).click();
    await dialog.getByRole('combobox', { name: 'Pulsanti: raggio' }).selectOption('full');
    const radii = () => page.evaluate(() => ['xb-btn', 'xb-btn-secondary', 'xb-btn-danger-outline'].map((name) => {
      const el = document.body.appendChild(document.createElement('a'));
      el.className = name;
      el.textContent = 'x';
      const r = getComputedStyle(el).borderTopLeftRadius;
      el.remove();
      return r;
    }));
    await expect.poll(radii).toEqual(['9999px', '9999px', '9999px']);

    // Forms: with the default styles, a bare field and a Fluent Forms field take the panel's radius.
    await dialog.getByRole('tab', { name: 'Moduli e dettagli' }).click();
    await dialog.getByRole('checkbox', { name: 'Stili predefiniti dei moduli' }).check();
    await dialog.getByRole('combobox', { name: 'Campi: raggio' }).selectOption('full');
    const fields = () => page.evaluate(() => {
      const form = document.body.appendChild(document.createElement('form'));
      form.className = 'fluentform';
      form.innerHTML = '<input type="text"><input type="text" class="ff-el-form-control">';
      const out = [...form.querySelectorAll('input')].map((el) => getComputedStyle(el).borderTopLeftRadius);
      form.remove();
      return out;
    });
    await expect.poll(fields).toEqual(['9999px', '9999px']);
    // A checkbox as a switch, from one choice.
    await dialog.getByRole('combobox', { name: 'Caselle: stile' }).selectOption('switch');
    const box = () => page.evaluate(() => {
      const el = document.body.appendChild(document.createElement('input'));
      el.type = 'checkbox';
      const style = getComputedStyle(el);
      const out = [style.appearance, style.borderTopLeftRadius];
      el.remove();
      return out;
    });
    await expect.poll(box).toEqual(['none', '999px']);

    // Cards: the media at the card's edges, with the ratio chosen in the panel.
    await dialog.getByRole('tab', { name: 'Forme' }).click();
    await dialog.getByRole('combobox', { name: 'Card: proporzioni del media' }).selectOption('1/1');
    const media = () => page.evaluate(() => {
      const card = document.body.appendChild(document.createElement('div'));
      card.className = 'xb-card';
      card.style.inlineSize = '300px';
      const box = card.appendChild(document.createElement('div'));
      box.className = 'xb-card-media';
      const rect = box.getBoundingClientRect();
      const out = [Math.round(rect.width), Math.round(rect.height)];
      card.remove();
      return out;
    });
    // Square, and wider than the card's content: it reaches the card's edges over the padding.
    await expect.poll(async () => { const [w, h] = await media(); return w === h && w! > 300; }).toBe(true);

    // A surface of the site: its background from the panel, its text readable on it.
    await dialog.getByRole('combobox', { name: 'Superficie 1: Sfondo' }).selectOption('primary-900');
    const surface = () => page.evaluate(() => {
      const el = document.body.appendChild(document.createElement('div'));
      el.className = 'xb-surface-1';
      const style = getComputedStyle(el);
      const out = [style.backgroundColor, style.color];
      el.remove();
      return out;
    });
    await expect.poll(async () => { const [bg, fg] = await surface(); return bg !== fg && bg !== 'rgba(0, 0, 0, 0)'; }).toBe(true);

    // A shadow by step, as the panel previews it; a group switched off empties its tokens at once.
    await dialog.getByRole('spinbutton', { name: 'Ombra m: Distanza (px)' }).fill('9');
    await dialog.getByRole('spinbutton', { name: 'Ombra m: Distanza (px)' }).press('Tab');
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--xb-shadow-m').trim())).toContain('0 9px 12px');
    await dialog.getByRole('tab', { name: 'Moduli e dettagli' }).click();
    await dialog.getByRole('checkbox', { name: 'Variabili: movimento' }).uncheck();
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--xb-enter-range').trim())).toBe('');
    await dialog.getByRole('checkbox', { name: 'Variabili: movimento' }).check();

    // A group of classes switched off leaves the page at once, and comes back.
    await page.addStyleTag({ content: '.xb-btn-info { color: rgb(1, 2, 3) }' });
    const colour = () => page.evaluate(() => {
      const el = document.body.appendChild(document.createElement('a'));
      el.className = 'xb-btn-info';
      const value = getComputedStyle(el).color;
      el.remove();
      return value;
    });
    expect(await colour()).toBe('rgb(1, 2, 3)');
    await dialog.getByRole('checkbox', { name: 'Classi: pulsanti (xb-btn)' }).uncheck();
    await expect.poll(colour).not.toBe('rgb(1, 2, 3)');
    await dialog.getByRole('checkbox', { name: 'Classi: pulsanti (xb-btn)' }).check();
    await expect.poll(colour).toBe('rgb(1, 2, 3)');

    // Motion: an entering element is still for whoever asks for less motion.
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const animation = await page.evaluate(() => {
      const el = document.body.appendChild(document.createElement('div'));
      el.className = 'xb-enter-up';
      const name = getComputedStyle(el).animationName;
      el.remove();
      return name;
    });
    expect(animation).toBe('none');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  } finally {
    framework(before);
  }
});

// V8: «Esporta i token (DTCG)» saves the computed tokens in the Design Tokens Community Group format: the light theme,
// and the dark one too when it is on. Every token has its $value and a $type (its own or its group's).
test('cassetto Histeon CSS: «Esporta i token (DTCG)» scarica tokens.chiaro.json e, col tema scuro, tokens.scuro.json', async ({ page }) => {
  const before = framework();
  try {
    framework('xb-tokens');
    await page.goto('/');
    await page.keyboard.press('Alt+Shift+H');
    const dialog = page.getByRole('dialog', { name: 'Histeon CSS' });
    await expect(dialog).toBeVisible();
    const downloads: Download[] = [];
    page.on('download', (d) => downloads.push(d));
    const exportTokens = dialog.getByRole('button', { name: 'Esporta i token (DTCG)' });

    /** Every token of a DTCG file: [path, $value, $type inherited from the nearest group]. */
    const tokens = (node: Record<string, unknown>, type: string | undefined = undefined, at = ''): Array<[string, unknown, string | undefined]> => {
      const own = typeof node.$type === 'string' ? node.$type : type;
      if ('$value' in node) return [[at, node.$value, own]];
      return Object.entries(node)
        .filter(([key, child]) => !key.startsWith('$') && child !== null && typeof child === 'object')
        .flatMap(([key, child]) => tokens(child as Record<string, unknown>, own, at ? `${at}.${key}` : key));
    };
    const read = async (d: Download) => JSON.parse(readFileSync((await d.path())!, 'utf8')) as Record<string, unknown>;
    const valid = (file: Record<string, unknown>) => {
      const all = tokens(file);
      expect(all.length).toBeGreaterThan(50);
      for (const [name, value, type] of all) {
        expect(value, name).not.toBeUndefined();
        expect(type, `$type di ${name}`).toMatch(/^[a-zA-Z]+$/);
      }
    };

    // Dark theme off: one file.
    await dialog.getByRole('tab', { name: 'Colori' }).click();
    await expect(dialog.getByRole('checkbox', { name: 'Tema scuro' })).not.toBeChecked();
    await exportTokens.click();
    await expect(dialog.getByText(/^Token esportati: \d+; lasciati fuori \d+, elencati nel file\. Il tema scuro è spento/)).toBeVisible();
    await expect.poll(() => downloads.length).toBe(1);
    expect(downloads[0]!.suggestedFilename()).toBe('tokens.chiaro.json');
    valid(await read(downloads[0]!));

    // Dark theme on: two files, both valid, with different colours.
    // The page preview of the change answers first: its answer clears the panel's message.
    const previewed = page.waitForResponse((r) => r.url().includes('design/preview') && r.request().method() === 'POST');
    await dialog.getByRole('checkbox', { name: 'Tema scuro' }).check();
    await previewed;
    await exportTokens.click();
    await expect.poll(() => downloads.length).toBe(3);
    expect(downloads.slice(1).map((d) => d.suggestedFilename()).sort()).toEqual(['tokens.chiaro.json', 'tokens.scuro.json']);
    const light = await read(downloads.find((d, i) => i > 0 && d.suggestedFilename() === 'tokens.chiaro.json')!);
    const dark = await read(downloads.find((d) => d.suggestedFilename() === 'tokens.scuro.json')!);
    valid(light);
    valid(dark);
    expect(JSON.stringify(dark)).not.toBe(JSON.stringify(light));
    await expect(dialog.getByText(/^Token esportati: \d+; lasciati fuori \d+, elencati nel file\.$/)).toBeVisible();
    const shots = process.env.XB_E2E_SHOTS;
    if (shots) await page.screenshot({ path: `${shots}/v8-token.png` });
    await page.keyboard.press('Escape');
  } finally {
    framework(before);
  }
});
