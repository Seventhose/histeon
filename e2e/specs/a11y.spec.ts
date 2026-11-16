import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

// Accessibility of Histeon's own interface (docs/qa/accessibilita.md): axe-core, WCAG 2.2 A and AA rules, on the
// builder (each section, the dialogs) and on the Histeon screens in wp-admin. The canvas is the user's page, not
// Histeon's interface, and WordPress's own admin chrome is WordPress's: both stay out.
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

type Finding = { where: string; id: string; impact: string; help: string; nodes: string[] };

async function audit(page: Page, where: string, include?: string): Promise<Finding[]> {
  let axe = new AxeBuilder({ page }).withTags(TAGS).exclude('iframe[data-xb-canvas]');
  if (include) axe = axe.include(include);
  const result = await axe.analyze();
  return result.violations.map((v) => ({ where, id: v.id, impact: v.impact ?? '', help: v.help, nodes: v.nodes.slice(0, 6).map((n) => `${n.target.join(' ')} — ${n.failureSummary?.split('\n')[1]?.trim() ?? ''}`) }));
}

/** A page of this file, published, so that a change saved on it is something to publish. */
function ownPage(): number {
  const content = Buffer.from(el('section', { class: 'e2e-accessibilita' }, el('p', null, text('Testo da pubblicare')))).toString('base64');
  return Number(wpEval(`<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Accessibilità e2e', 'post_content' => wp_slash( base64_decode( '${content}' ) ) ) );`, {}).trim().split('\n').pop());
}

function report(findings: Finding[]): string {
  return findings.map((f) => `[${f.impact}] ${f.where} · ${f.id}: ${f.help}\n    ${f.nodes.join('\n    ')}`).join('\n');
}

test('accessibilità del builder: ogni sezione e i dialoghi principali', async ({ page, data }) => {
  test.setTimeout(180_000);
  const builder = await Builder.open(page, data.pages.api.id);
  const findings: Finding[] = [];
  findings.push(...(await audit(page, 'builder')));

  const sections = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button');
  const names = await sections.evaluateAll((buttons) => buttons.map((b) => b.getAttribute('aria-label') || b.textContent?.trim() || ''));
  for (const name of names) {
    const button = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name, exact: true });
    // Buttons that open a dialog (the command palette) are audited with it, below.
    if (!(await button.getAttribute('aria-pressed')) || (await button.getAttribute('aria-pressed')) === 'true') continue;
    await button.click();
    findings.push(...(await audit(page, `sezione ${name}`)));
  }
  await builder.tab('HTML');

  await page.keyboard.press('ControlOrMeta+k');
  await expect(page.getByRole('combobox')).toBeVisible();
  findings.push(...(await audit(page, 'palette dei comandi')));
  await page.getByRole('combobox').fill('scorciatoie');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('dialog', { name: 'Scorciatoie da tastiera' })).toBeVisible();
  findings.push(...(await audit(page, 'scorciatoie')));
  await page.keyboard.press('Escape');

  console.log(report(findings));
  expect(findings.filter((f) => f.impact === 'critical' || f.impact === 'serious').map((f) => `${f.where} · ${f.id}`)).toEqual([]);
});

// The publish dialog, in the state where it shows everything, «Quando» with its two radios included: something to
// publish. On the shared page of the run that depends on the specs run before (with nothing to publish the dialog
// shows neither the list nor «Quando»), so the dialog opens on a page of its own, with a change saved in development,
// and is audited only once it has loaded (the list and the radios come after the dialog opens).
test('accessibilità del dialogo Pubblica, con «Pubblica ora» e «Programma per…»', async ({ page }) => {
  const created = ownPage();
  let builder: Builder | undefined;
  try {
    builder = await Builder.open(page, created);
    await builder.edit(builder.html, (code) => code.replace('Testo da pubblicare', 'Testo da pubblicare, cambiato'));
    await builder.save();
    await page.locator('header.topbar').getByRole('button', { name: 'Pubblica…' }).click();
    const dialog = page.getByRole('dialog', { name: 'Pubblica' });
    await expect(dialog.locator('.change', { hasText: 'Accessibilità e2e' })).toBeVisible();
    await expect(dialog.getByRole('radio', { name: 'Pubblica ora' })).toBeVisible();
    await expect(dialog.getByRole('radio', { name: 'Programma per…' })).toBeVisible();
    const findings = await audit(page, 'pubblica');
    await page.keyboard.press('Escape');
    console.log(report(findings));
    expect(findings.filter((f) => f.impact === 'critical' || f.impact === 'serious').map((f) => `${f.where} · ${f.id}`)).toEqual([]);
  } finally {
    // The change goes online before the page goes: a deleted page's change would stay in the list of every later
    // release on this site for good.
    if (builder) {
      const changes = await builder.api<Array<{ doc: string }>>('GET', 'changes');
      if (changes.some((c) => c.doc === `post:${created}`)) {
        const { next } = await builder.api<{ next: string }>('GET', 'releases');
        await builder.api('POST', 'releases', { version: next, notes: 'e2e: accessibilità del dialogo Pubblica', docs: [`post:${created}`] });
      }
    }
    wpEval(`<?php wp_delete_post( ${created}, true );`, {});
  }
});

// The dark theme too (lista migliorie A1): colours come from the theme tokens, none fixed for the light one.
test('accessibilità del builder in tema scuro: ogni sezione', async ({ page, data }) => {
  test.setTimeout(180_000);
  await page.addInitScript(() => localStorage.setItem('xb-theme', 'dark'));
  await Builder.open(page, data.pages.api.id);
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  const findings: Finding[] = [];
  findings.push(...(await audit(page, 'builder scuro')));
  const sections = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button');
  const names = await sections.evaluateAll((buttons) => buttons.map((b) => b.getAttribute('aria-label') || b.textContent?.trim() || ''));
  for (const name of names) {
    const button = page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name, exact: true });
    if (!(await button.getAttribute('aria-pressed')) || (await button.getAttribute('aria-pressed')) === 'true') continue;
    await button.click();
    findings.push(...(await audit(page, `sezione ${name} (scuro)`)));
  }
  console.log(report(findings));
  expect(findings.filter((f) => f.impact === 'critical' || f.impact === 'serious').map((f) => `${f.where} · ${f.id}`)).toEqual([]);
});

// Without a choice of its own, the builder follows the system, also when the system changes (A2).
test('tema come il sistema: segue il cambio del sistema finché non si sceglie, e la scelta resta', async ({ page, data }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await page.addInitScript(() => {
    if (!sessionStorage.getItem('xb-e2e-theme')) {
      localStorage.removeItem('xb-theme');
      sessionStorage.setItem('xb-e2e-theme', '1');
    }
  });
  await Builder.open(page, data.pages.api.id);
  const html = page.locator('html');
  await expect(html).toHaveAttribute('data-theme', 'light');
  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(html).toHaveAttribute('data-theme', 'dark');
  expect(await page.evaluate(() => localStorage.getItem('xb-theme'))).toBeNull();

  // system → light → dark → system
  const toggle = page.getByRole('button', { name: /^Cambia tema/ });
  await expect(toggle).toHaveAccessibleName('Cambia tema: ora Tema come il sistema');
  await toggle.click();
  await expect(html).toHaveAttribute('data-theme', 'light');
  expect(await page.evaluate(() => localStorage.getItem('xb-theme'))).toBe('light');
  await page.reload();
  await expect(html).toHaveAttribute('data-theme', 'light');
  await page.getByRole('button', { name: /^Cambia tema/ }).click();
  await expect(html).toHaveAttribute('data-theme', 'dark');
  await page.getByRole('button', { name: /^Cambia tema/ }).click();
  await expect(page.getByRole('button', { name: /^Cambia tema/ })).toHaveAccessibleName('Cambia tema: ora Tema come il sistema');
  expect(await page.evaluate(() => localStorage.getItem('xb-theme'))).toBeNull();
  await page.emulateMedia({ colorScheme: 'light' });
  await expect(html).toHaveAttribute('data-theme', 'light');
});

test('accessibilità delle schermate di Histeon in wp-admin', async ({ page }) => {
  const findings: Finding[] = [];
  for (const slug of ['xb', 'xb-releases', 'xb-history', 'xb-audit', 'xb-templates', 'xb-settings', 'xb-modules', 'xb-setup']) {
    await page.goto(`/wp-admin/admin.php?page=${slug}`);
    findings.push(...(await audit(page, slug, '#wpbody-content')));
  }
  console.log(report(findings));
  expect(findings.filter((f) => f.impact === 'critical' || f.impact === 'serious').map((f) => `${f.where} · ${f.id}`)).toEqual([]);
});

test('tastiera: albero della struttura con le frecce, palette che si chiude con Esc, anello di focus visibile', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.api.id);
  const tree = page.getByRole('tree');
  const names = tree.locator('button.name');
  await names.first().focus();
  await page.keyboard.press('ArrowDown');
  await expect(names.nth(1)).toBeFocused();
  await page.keyboard.press('End');
  await expect(names.last()).toBeFocused();
  await page.keyboard.press('Home');
  await expect(names.first()).toBeFocused();
  // A branch opens and closes with right and left; left from a child goes back to its parent.
  const branch = tree.locator('[role="treeitem"][aria-expanded]').first();
  const name = branch.locator(':scope > .row > button.name');
  await name.focus();
  const wasOpen = (await branch.getAttribute('aria-expanded')) === 'true';
  await page.keyboard.press(wasOpen ? 'ArrowLeft' : 'ArrowRight');
  await expect(branch).toHaveAttribute('aria-expanded', wasOpen ? 'false' : 'true');
  if (!wasOpen) {
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowLeft');
    await expect(name).toBeFocused();
  }

  // The focus ring shows on keyboard focus.
  const ring = await name.evaluate((el) => getComputedStyle(el).boxShadow);
  expect(ring).not.toBe('none');

  // The command palette: opened from the keyboard, closed with Esc, focus back where it was.
  await page.keyboard.press('ControlOrMeta+k');
  await expect(page.getByRole('combobox')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('combobox')).toBeHidden();
  await expect(name).toBeFocused();
  void builder;
});

test('accessibilità del pannello Histeon CSS sul sito', async ({ page }) => {
  await page.goto('/');
  const open = page.locator('#wp-admin-bar-xb-histos-css a');
  test.skip((await open.count()) === 0, 'Histeon CSS non è il framework attivo su questo sito di prova');
  await open.click();
  await expect(page.getByRole('dialog', { name: 'Histeon CSS' })).toBeVisible();
  const findings: Finding[] = [];
  for (const tab of ['Colori', 'Testo', 'Spazi', 'Forme', 'Moduli e dettagli', 'Caratteri', 'Ricette']) {
    await page.getByRole('dialog', { name: 'Histeon CSS' }).getByRole('tab', { name: tab }).click();
    findings.push(...(await audit(page, `pannello ${tab}`, '#xb-histos-css')));
  }
  console.log(report(findings));
  expect(findings.filter((f) => f.impact === 'critical' || f.impact === 'serious').map((f) => `${f.where} · ${f.id}`)).toEqual([]);
});
