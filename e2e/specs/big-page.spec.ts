import { Builder } from '../lib/builder.ts';
import { el, text } from '../lib/blocks.ts';
import { wpEval } from '../lib/wp.ts';
import { expect, test } from '../lib/test.ts';

// A page of thousands of elements (docs/spec/performance.md, large pages): the structure draws only the rows in
// view, and selecting or hovering stays quick. Times are taken inside the page (Playwright's own lookups over
// thousands of nodes would dominate) and bounds are loose: they catch going back to work proportional to the page.
const SECTIONS = 300;

function bigPage(): number {
  const parts: string[] = [];
  for (let i = 0; i < SECTIONS; i++) {
    parts.push(
      el(
        'section',
        { class: 'big' },
        el(
          'div',
          { class: 'big__card' },
          el('h2', null, text(`Titolo ${i}`)),
          el('p', null, text(`Paragrafo ${i} della pagina grande.`)),
          el('ul', null, el('li', null, text('Uno')), el('li', null, text('Due')), el('li', null, text('Tre'))),
        ),
      ),
    );
  }
  const content = Buffer.from(parts.join('')).toString('base64');
  const php = `<?php echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Pagina grande e2e', 'post_content' => wp_slash( base64_decode( '${content}' ) ) ) );`;
  return Number(wpEval(php, {}).trim().split('\n').pop());
}

// The dev site is shared with the other specs: the large page does not stay.
let created = 0;
test.afterEach(() => {
  if (created) wpEval(`<?php wp_delete_post( ${created}, true );`, {});
  created = 0;
});

test('pagina con migliaia di elementi: struttura disegnata in parte, selezione rapida, tastiera fino in fondo', async ({ page }) => {
  test.setTimeout(120_000);
  created = bigPage();
  const builder = await Builder.open(page, created);
  await expect(builder.canvas.getByRole('heading', { name: `Titolo ${SECTIONS - 1}`, exact: true })).toBeAttached({ timeout: 60_000 });

  // 300 sections × 5 open rows (section, card, h2, p, ul): far more than are drawn.
  const tree = page.getByRole('tree');
  const drawn = await tree.getByRole('treeitem').count();
  expect(drawn).toBeGreaterThan(10);
  expect(drawn).toBeLessThan(200);
  expect(await tree.evaluate((ul) => ul.getBoundingClientRect().height)).toBeGreaterThan(SECTIONS * 5 * 20);

  // Selecting in the canvas, near the end of the page: the row is drawn, selected and in view.
  const ms = await page.evaluate(async (n) => {
    const settle = () => new Promise<void>((r) => requestAnimationFrame(() => setTimeout(r)));
    const doc = document.querySelector<HTMLIFrameElement>('iframe[data-xb-canvas]')!.contentDocument!;
    await settle();
    const start = performance.now();
    doc.querySelectorAll('h2')[n]!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    await settle();
    return performance.now() - start;
  }, SECTIONS - 2);
  expect(ms).toBeLessThan(400);
  const selected = tree.getByRole('treeitem', { selected: true });
  // The selected heading is open: its text is the row right below it.
  const below = Number(await selected.getAttribute('data-index')) + 1;
  await expect(tree.locator(`li[data-index="${below}"]`)).toContainText(`Titolo ${SECTIONS - 2}`);
  await expect(selected).toBeInViewport();
  await expect(selected).toHaveAttribute('aria-level', '3');

  // Hovering a row updates that row and the canvas, not the whole tree.
  const hover = await page.evaluate(async () => {
    const settle = () => new Promise<void>((r) => requestAnimationFrame(() => setTimeout(r)));
    const names = document.querySelectorAll<HTMLElement>('nav.structure button.name');
    await settle();
    const start = performance.now();
    for (let i = 0; i < 10; i++) {
      names[i]!.dispatchEvent(new MouseEvent('mouseenter'));
      await settle();
    }
    return (performance.now() - start) / 10;
  });
  expect(hover).toBeLessThan(120);

  // The keyboard reaches rows that were not drawn: Home, End, then left to the parent. The selected heading is
  // open, so its text adds one row.
  const focused = page.locator('nav.structure li:has(> .row > button.name:focus)');
  await selected.locator(':scope > .row > button.name').focus();
  await page.keyboard.press('Home');
  await expect(focused).toHaveAttribute('data-index', '0');
  await page.keyboard.press('End');
  await expect(focused).toHaveAttribute('data-index', String(SECTIONS * 5));
  await expect(focused).toBeInViewport();
  await page.keyboard.press('ArrowLeft');
  await expect(focused).toHaveAttribute('data-index', String(SECTIONS * 5 - 3));
  await expect(focused).toHaveAttribute('aria-level', '2');

  // Removing an element and undoing it keep the rest of the page.
  await page.keyboard.press('Home');
  await expect(focused).toHaveAttribute('data-index', '0');
  await page.getByRole('button', { name: 'section.big', exact: true }).first().click();
  await page.keyboard.press('Delete');
  await expect(builder.canvas.getByRole('heading', { name: 'Titolo 0', exact: true })).toHaveCount(0);
  await expect(builder.canvas.getByRole('heading', { name: `Titolo ${SECTIONS - 1}`, exact: true })).toBeAttached();
  await page.getByRole('button', { name: "Annulla l'ultima modifica" }).click();
  await expect(builder.canvas.getByRole('heading', { name: 'Titolo 0', exact: true })).toBeAttached();
});
