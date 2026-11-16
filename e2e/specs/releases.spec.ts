import { readFile } from 'node:fs/promises';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('ripristino di una release precedente dal pannello Versioni', async ({ page, data }) => {
  const doc = data.pages.restore;
  const builder = await Builder.open(page, doc.id);

  // Release A: one paragraph changed. Release B: one more element.
  await builder.edit(builder.html, (code) => code.replace('Secondo paragrafo', 'Versione A'));
  await builder.save();
  const a = await builder.publishAll('e2e: release A');
  await builder.edit(builder.html, (code) => code.replace('</section>', '  <p class="solo-b">Solo in B</p>\n</section>'));
  await builder.save();
  const b = await builder.publishAll('e2e: release B');
  expect(await (await page.request.get(doc.url)).text()).toContain('Solo in B');

  await builder.tab('Versioni');
  const releaseA = builder.release(a.version);
  const releaseB = builder.release(b.version);
  await expect(releaseA).toContainText('e2e: release A');
  await expect(releaseB.locator('.badge')).toHaveText('online');
  // The online release has no restore button.
  await expect(releaseB.getByRole('button', { name: 'Ripristina' })).toHaveCount(0);

  page.once('dialog', (dialog) => {
    expect(dialog.message()).toContain(`Ripristinare la versione ${a.version}`);
    void dialog.accept();
  });
  await releaseA.getByRole('button', { name: 'Ripristina' }).click();
  const status = page.locator('.history [role="status"]');
  await expect(status).toHaveText(/^Ripristinata: ora online la versione \d+\.\d+\.\d+\.$/);
  const restored = /versione (\S+)\.$/.exec((await status.textContent()) ?? '')?.[1] ?? '';

  // A new release, not a rewrite of history: B is still listed, the restored copy is online.
  await expect(releaseB).toBeVisible();
  await expect(releaseB.locator('.badge')).toHaveCount(0);
  await expect(builder.release(restored)).toHaveClass(/current/);
  const live = await (await page.request.get(doc.url)).text();
  expect(live).toContain('Versione A');
  expect(live).not.toContain('Solo in B');
  // The builder reloaded what is now in dev.
  await builder.tab('HTML');
  await expect(builder.html).not.toContainText('Solo in B');
});

test('esportazione di una release e anteprima dell’importazione dal pannello Versioni', async ({ page, data }) => {
  const doc = data.pages.transfer;
  const builder = await Builder.open(page, doc.id);
  await builder.edit(builder.html, (code) => code.replace('Primo paragrafo', 'Da esportare'));
  await builder.save();
  const release = await builder.publishAll('e2e: da esportare');

  await builder.tab('Versioni');
  const row = builder.release(release.version);
  await expect(row).toContainText('e2e: da esportare');
  // Playwright's download API: the file is captured by the test, never written to a user folder.
  const [download] = await Promise.all([page.waitForEvent('download'), row.getByRole('button', { name: 'Esporta' }).click()]);
  expect(download.suggestedFilename()).toBe(`xb-release-${release.version}.xbrelease`);
  await expect(page.locator('.history [role="status"]')).toHaveText(`Versione ${release.version} esportata.`);
  const file = JSON.parse(await readFile(await download.path(), 'utf8')) as Record<string, unknown>;
  // Same content as the REST export, apart from when the export was made, which the signature covers too (P8).
  const { created: _file, signature: fileSignature, ...fromFile } = file as Record<string, unknown> & { signature: { key: string } };
  const { created: _api, signature: apiSignature, ...fromApi } = await builder.api<Record<string, unknown> & { signature: { key: string } }>('GET', `releases/${release.id}/export`);
  expect(fromFile).toEqual(fromApi);
  expect(fileSignature.key).toBe(apiSignature.key);
  expect(JSON.stringify(file)).toContain('Da esportare');

  const before = await builder.api('GET', 'changes');
  await page.locator('.history input[type="file"]').setInputFiles(await download.path());
  const report = page.locator('.history .report');
  await expect(report).toContainText(`Versione ${release.version}`);
  await expect(report).toContainText('— anteprima');
  await expect(report.getByRole('listitem').filter({ hasText: doc.title })).toBeVisible();
  await expect(report.getByRole('button', { name: 'Importa in sviluppo' })).toBeEnabled();
  await expect(report.locator('.signature')).toHaveText('Firmato da questo sito.');
  // A dry run writes nothing.
  expect(await builder.api('GET', 'changes')).toEqual(before);

  // The dry run through the API reports the same documents, and a non-release payload is refused.
  const dry = await builder.api<{ written: boolean; documents: Array<{ label: string }> }>('POST', 'releases/import', { data: file, write: false });
  expect(dry.written).toBe(false);
  expect(dry.documents.map((d) => d.label)).toContain(`page «${doc.title}»`);
  expect(await builder.apiStatus('POST', 'releases/import', { data: { nope: true }, write: false })).toBe(400);
});
