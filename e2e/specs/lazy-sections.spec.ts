import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The sections over the canvas are ES modules loaded when they open (lista migliorie V7). The builder's CSP allows
// only its nonce: the modules imported by builder.js must load under it, with no violation.
test('le sezioni si caricano quando si aprono, sotto la CSP con nonce, senza violazioni', async ({ page, data }) => {
  await page.addInitScript(() => {
    const w = window as unknown as { cspViolations: string[] };
    w.cspViolations = [];
    document.addEventListener('securitypolicyviolation', (e) => w.cspViolations.push(`${e.violatedDirective} ${e.blockedURI}`));
  });
  const modules: string[] = [];
  page.on('request', (r) => {
    const m = /assets\/builder\/([A-Za-z]+)-[\w-]+\.js/.exec(r.url());
    if (m) modules.push(m[1]!);
  });
  await Builder.open(page, data.pages.palette.id);
  expect(modules).not.toContain('SelectorsPanel');
  expect(modules).not.toContain('HistoryPanel');

  const rail = page.getByRole('navigation', { name: 'Sezioni' });
  await rail.getByRole('button', { name: 'Stili e selettori', exact: true }).click();
  await expect(page.getByRole('searchbox', { name: 'Cerca un selettore' })).toBeVisible();
  expect(modules).toContain('SelectorsPanel');
  await rail.getByRole('button', { name: 'Versioni', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Versioni' })).toBeVisible();
  expect(modules).toContain('HistoryPanel');

  expect(await page.evaluate(() => (window as unknown as { cspViolations: string[] }).cspViolations)).toEqual([]);
});
