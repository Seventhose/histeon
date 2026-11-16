import type { Locator, Page } from '@playwright/test';
import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';

// The edges between the canvas and the panel areas (App.svelte, lib/pointerHold.ts): they resize only while the
// button is held on them, never hand the pointer to the panel header that ends up under the press point, and
// Esc puts back the size they started from.

async function box(locator: Locator): Promise<{ x: number; y: number; width: number; height: number }> {
  const b = await locator.boundingBox();
  if (!b) throw new Error('elemento non visibile');
  return b;
}
const height = async (locator: Locator) => Math.round((await box(locator)).height);
const width = async (locator: Locator) => Math.round((await box(locator)).width);

/** Nothing left of a panel drag: no ghost panel, no drop zones, no cursor forced by a resize. */
async function idle(page: Page): Promise<void> {
  await expect(page.locator('.slot.ghost')).toHaveCount(0);
  await expect(page.getByText('Rilascia qui')).toHaveCount(0);
  await expect(page.locator('.xb.is-dragging')).toHaveCount(0);
  await expect(page.locator('html.xb-holding')).toHaveCount(0);
}

/** Pointer moves of a few pixels each, the way a hand drags. */
async function glide(page: Page, from: { x: number; y: number }, dx: number, dy: number): Promise<void> {
  const steps = Math.max(Math.abs(dx), Math.abs(dy)) / 4;
  for (let i = 1; i <= steps; i++) await page.mouse.move(from.x + (dx * i) / steps, from.y + (dy * i) / steps);
}

for (const panel of ['html', 'css', 'js'] as const) {
test(`il bordo dei pannelli in basso ridimensiona e non stacca il pannello sotto il puntatore (sopra ${panel})`, async ({ page, data }) => {
  await Builder.open(page, data.pages.html.id);
  const bottom = page.getByRole('group', { name: 'Pannelli in basso' });
  const edge = page.getByRole('separator', { name: 'Altezza dei pannelli in basso' });
  const start = await height(bottom);

  // Grabbed above a panel's header and pulled up: the panels grow under the point where the button went down and
  // that header ends up there. Before the fix the browser started dragging the panel and the edge, never told,
  // kept following the pointer with the button up.
  const e = await box(edge);
  const h = await box(page.locator(`[data-panel="${panel}"] header`));
  const at = { x: h.x + h.width / 2, y: e.y + e.height / 2 };
  await page.mouse.move(at.x, at.y);
  await page.mouse.down();
  await glide(page, at, 0, -200);
  await expect.poll(() => height(bottom)).toBe(start + 200);
  await expect(page.locator('html.xb-holding')).toHaveCount(1);
  await expect(page.locator('.slot.ghost')).toHaveCount(0);
  await page.mouse.up();
  await idle(page);
  const grown = await height(bottom);
  expect(grown).toBe(start + 200);
  await expect(edge).toHaveAttribute('aria-valuenow', String(grown));

  // Button up: passing over the edge and the headers, up and down, changes nothing.
  const after = await box(edge);
  const over = { x: after.x + after.width / 2, y: after.y + after.height / 2 };
  await page.mouse.move(over.x, over.y + 12);
  await glide(page, { x: over.x, y: over.y + 12 }, 0, -150);
  await glide(page, { x: over.x, y: over.y - 138 }, 0, 160);
  expect(await height(bottom)).toBe(grown);
  await idle(page);

  // A press a couple of pixels below the line, on the header's top edge, still resizes.
  await page.mouse.move(over.x - 200, after.y + after.height + 2);
  await page.mouse.down();
  await glide(page, { x: over.x - 200, y: after.y + after.height + 2 }, 0, 60);
  await page.mouse.up();
  await expect.poll(() => height(bottom)).toBe(grown - 60);
  await idle(page);
});
}

test('Esc durante il ridimensionamento rimette le dimensioni di partenza', async ({ page, data }) => {
  await Builder.open(page, data.pages.html.id);
  const bottom = page.getByRole('group', { name: 'Pannelli in basso' });
  const edge = page.getByRole('separator', { name: 'Altezza dei pannelli in basso' });
  const start = await height(bottom);
  const e = await box(edge);
  const at = { x: e.x + e.width / 2, y: e.y + e.height / 2 };
  await page.mouse.move(at.x, at.y);
  await page.mouse.down();
  await glide(page, at, 0, -120);
  await expect.poll(() => height(bottom)).toBe(start + 120);
  await page.keyboard.press('Escape');
  await expect.poll(() => height(bottom)).toBe(start);
  await idle(page);
  // The button is still down: the edge no longer follows it, nor after it is released.
  await glide(page, { x: at.x, y: at.y - 120 }, 0, -60);
  await page.mouse.up();
  await glide(page, { x: at.x, y: e.y + 3 }, 0, -80);
  expect(await height(bottom)).toBe(start);
  await idle(page);
});

test('il bordo della colonna destra ridimensiona anche accanto alle intestazioni dei pannelli', async ({ page, data }) => {
  await Builder.open(page, data.pages.html.id);
  const right = page.locator('aside.column.right');
  const edge = page.getByRole('separator', { name: 'Larghezza dei pannelli a destra' });
  const start = await width(right);
  // Level with the STRUTTURA header: pulled left, the header slides under the press point.
  const header = await box(page.locator('[data-panel="structure"] header'));
  const e = await box(edge);
  const at = { x: e.x + e.width / 2, y: header.y + header.height / 2 };
  await page.mouse.move(at.x, at.y);
  await page.mouse.down();
  await glide(page, at, -120, 0);
  await page.mouse.up();
  await expect.poll(() => width(right)).toBe(start + 120);
  await idle(page);
  await glide(page, { x: at.x - 140, y: at.y }, 200, 0);
  expect(await width(right)).toBe(start + 120);
});

test('il bordo della colonna sinistra ridimensiona anche accanto alle intestazioni dei pannelli', async ({ page, data }) => {
  await Builder.open(page, data.pages.html.id);
  await page.getByRole('button', { name: 'Sposta Struttura', exact: true }).click();
  await page.getByRole('menuitem', { name: 'Sposta a sinistra' }).click();
  const left = page.locator('aside.column.left');
  const edge = page.getByRole('separator', { name: 'Larghezza dei pannelli a sinistra' });
  const start = await width(left);
  const header = await box(page.locator('[data-panel="structure"] header'));
  const e = await box(edge);
  const at = { x: e.x + e.width / 2, y: header.y + header.height / 2 };
  await page.mouse.move(at.x, at.y);
  await page.mouse.down();
  await glide(page, at, 120, 0);
  await expect.poll(() => width(left)).toBe(start + 120);
  // Esc puts the width back, and the edge lets go of the pointer.
  await page.keyboard.press('Escape');
  await expect.poll(() => width(left)).toBe(start);
  await glide(page, { x: at.x + 120, y: at.y }, 40, 0);
  await page.mouse.up();
  expect(await width(left)).toBe(start);
  await idle(page);
  // The rail's buttons are still where they were: Esc did not reach the builder (no section closed, nothing lost).
  await expect(page.locator('[data-panel="structure"]')).toBeVisible();
});

test("Esc rimette la larghezza dell'anteprima mentre se ne trascina il bordo", async ({ page, data }) => {
  await Builder.open(page, data.pages.html.id);
  const frame = page.locator('iframe[title="Anteprima della pagina"]');
  const handle = page.getByRole('slider', { name: "Larghezza dell'anteprima", exact: true });
  const before = await width(frame);
  const b = await box(handle);
  const at = { x: b.x + b.width / 2, y: b.y + b.height / 2 };
  await page.mouse.move(at.x, at.y);
  await page.mouse.down();
  // Narrower: wider than the stage it does not go.
  await glide(page, at, -100, 0);
  await expect.poll(() => width(frame)).toBe(before - 200);
  await page.keyboard.press('Escape');
  await expect.poll(() => width(frame)).toBe(before);
  await glide(page, { x: at.x - 100, y: at.y }, -60, 0);
  await page.mouse.up();
  expect(await width(frame)).toBe(before);
  await idle(page);
});

test('i bordi si ridimensionano anche da tastiera', async ({ page, data }) => {
  await Builder.open(page, data.pages.html.id);
  const bottom = page.getByRole('group', { name: 'Pannelli in basso' });
  const edge = page.getByRole('separator', { name: 'Altezza dei pannelli in basso' });
  const start = await height(bottom);
  await edge.focus();
  await page.keyboard.press('ArrowUp');
  await expect.poll(() => height(bottom)).toBe(start + 10);
  await page.keyboard.press('Shift+ArrowDown');
  await expect.poll(() => height(bottom)).toBe(start - 40);
  await expect(edge).toHaveAttribute('aria-valuenow', String(start - 40));
  // Home: as small as it goes, still visible (hiding the panels is the rail's button).
  await page.keyboard.press('Home');
  await expect(edge).toHaveAttribute('aria-valuenow', '60');
  await expect(edge).toBeFocused();

  const right = page.getByRole('separator', { name: 'Larghezza dei pannelli a destra' });
  const column = page.locator('aside.column.right');
  const wide = await width(column);
  await right.focus();
  await page.keyboard.press('ArrowLeft');
  await expect.poll(() => width(column)).toBe(wide + 10);
  await page.keyboard.press('ArrowRight');
  await expect.poll(() => width(column)).toBe(wide);
});

test("un pannello si sposta trascinandone l'intestazione", async ({ page, data }) => {
  await Builder.open(page, data.pages.html.id);
  const right = page.getByRole('group', { name: 'Pannelli a destra' });
  await page.locator('[data-panel="css"] header h2').dragTo(right.locator('[data-panel="properties"]'));
  await expect(right.locator('[data-panel="css"]')).toBeVisible();
  await expect(page.getByRole('group', { name: 'Pannelli in basso' }).locator('[data-panel="css"]')).toHaveCount(0);
  await idle(page);
  // Back where it was, from the menu.
  await right.getByRole('button', { name: 'Sposta CSS', exact: true }).click();
  await page.getByRole('menuitem', { name: 'Sposta in basso' }).click();
  await expect(page.getByRole('group', { name: 'Pannelli in basso' }).locator('[data-panel="css"]')).toBeVisible();
});
