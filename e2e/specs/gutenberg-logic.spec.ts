import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// Loops and conditions in words in Gutenberg (lista della spesa 137): the loop reads as a sentence and «Quanti» writes
// its limit; a condition is composed as field, operator and value, and both write what the code shows.
const MAKE = String.raw`<?php
$run  = getenv( 'E2E_RUN' );
$u    = static fn ( string $s ): string => 'lg' . substr( md5( $s . $run ), 0, 10 );
$page = '<!-- wp:xb/loop {"uid":"' . $u( 'loop' ) . '","source":{"kind":"preset","id":"posts"},"as":"post"} -->'
	. '<!-- wp:xb/element {"uid":"' . $u( 'li' ) . '","tag":"p","attributes":{"class":"e2e-loop-item"}} --><!-- wp:xb/text {"uid":"' . $u( 'lt' ) . '","content":"{post.title}"} /--><!-- /wp:xb/element -->'
	. '<!-- /wp:xb/loop -->'
	. '<!-- wp:xb/if {"uid":"' . $u( 'if' ) . '"} --><!-- wp:xb/branch {"uid":"' . $u( 'br' ) . '","test":"true"} -->'
	. '<!-- wp:xb/element {"uid":"' . $u( 'p' ) . '","tag":"p"} --><!-- wp:xb/text {"uid":"' . $u( 'pt' ) . '","content":"Ramo visibile"} /--><!-- /wp:xb/element -->'
	. '<!-- /wp:xb/branch --><!-- /wp:xb/if -->';
echo "\nE2E=" . wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Logica ' . $run, 'post_content' => wp_slash( $page ) ) ) . "\n";
`;

test('Gutenberg: un loop si legge come una frase e una condizione si compone a parole', async ({ page }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  const id = Number(wpEval(MAKE, { E2E_RUN: run }).split('\n').find((l) => l.startsWith('E2E='))!.slice(4));
  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  await expect(canvas.getByText('Ramo visibile')).toBeVisible({ timeout: 30_000 });
  const guide = page.getByRole('dialog', { name: /Benvenut|Welcome/ });
  if (await guide.isVisible().catch(() => false)) await page.keyboard.press('Escape');
  const select = (name: string) =>
    page.evaluate((blockName) => {
      const wp = (window as unknown as { wp: any }).wp;
      const walk = (blocks: any[]): any => blocks.map((b) => (b.name === blockName ? b : walk(b.innerBlocks ?? []))).find(Boolean);
      const block = walk(wp.data.select('core/block-editor').getBlocks());
      wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
      wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block');
    }, name);
  const attrs = (name: string) =>
    page.evaluate((blockName) => {
      const wp = (window as unknown as { wp: any }).wp;
      const walk = (blocks: any[]): any => blocks.map((b) => (b.name === blockName ? b : walk(b.innerBlocks ?? []))).find(Boolean);
      return walk(wp.data.select('core/block-editor').getBlocks()).attributes;
    }, name);

  // The loop: a sentence, and «Quanti» writes the loop's own limit.
  await select('xb/loop');
  await expect(page.locator('.xb-logic-sentence')).toContainText(/query di serie «posts»|built-in query “posts”/);
  await page.getByLabel(/^(Quanti|How many)$/).fill('2');
  await expect.poll(async () => (await attrs('xb/loop')).source).toEqual({ kind: 'preset', id: 'posts', args: { limit: 2 } });
  await expect(page.locator('.xb-logic-sentence')).toContainText(/^2 /);

  // The condition: composed as field, operator and value; the sentence and the code follow.
  await select('xb/branch');
  await page.getByLabel(/^(Campo|Field)$/).fill('page.type');
  await page.getByLabel(/^(Valore|Value)$/).fill('evento');
  await page.getByRole('button', { name: /Usa questa condizione|Use this condition/ }).click();
  await expect.poll(async () => (await attrs('xb/branch')).test).toBe('page.type == "evento"');
  await expect(page.locator('.xb-logic-sentence')).toContainText('page.type');
  // A test that does not parse is not written.
  await page.getByLabel(/^(Come si scrive nel codice|How it is written in code)$/).fill('page.type == ');
  await expect(page.getByRole('button', { name: /Applica il codice|Apply the code/ })).toBeDisabled();
});
