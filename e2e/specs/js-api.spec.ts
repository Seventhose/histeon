import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

test('API JavaScript del builder: leggere, inserire, stili, eventi, salvare', async ({ page, data }) => {
  const builder = await Builder.open(page, data.pages.api.id);
  const result = await page.evaluate(async () => {
    type Api = {
      version: number;
      on(e: string, f: () => void): () => void;
      insert(html: string): string[];
      select(uid: string | undefined): void;
      setCss(s: string, c: string): void;
      setHtml(h: string): void;
      save(): Promise<void>;
      getElementHtml(uid: string | undefined): string | null;
      getCss(s: string): string;
      isDirty(): boolean;
    };
    const xb = (window as unknown as { xb: Api }).xb;
    const events: string[] = [];
    xb.on('change', () => events.push('change'));
    xb.on('select', () => events.push('select'));
    xb.on('save', () => events.push('save'));
    const [uid] = xb.insert('<p class="api-nota">Da API</p>');
    xb.select(uid);
    xb.setCss('.api-nota', 'color: rgb(7, 8, 9);');
    let error = '';
    try {
      xb.setHtml('<p>non chiuso');
    } catch (e) {
      error = String(e);
    }
    await xb.save();
    return { version: xb.version, html: xb.getElementHtml(uid), css: xb.getCss('.api-nota'), dirty: xb.isDirty(), error, events, frozen: Object.isFrozen(xb) };
  });
  expect(result.version).toBe(1);
  expect(result.html).toContain('<p class="api-nota">Da API</p>');
  expect(result.css).toBe('color: rgb(7, 8, 9);');
  expect(result.error).not.toBe('');
  expect(result.dirty).toBe(false);
  expect(result.frozen).toBe(true);
  expect(result.events).toEqual(expect.arrayContaining(['change', 'select', 'save']));
  await expect(builder.canvas.getByText('Da API')).toHaveCSS('color', 'rgb(7, 8, 9)');
});
