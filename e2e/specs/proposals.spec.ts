import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// Lista della spesa 160: two proposals of a page, each with its signed link, side by side, and the chosen one back
// in development. The page is the spec's own, so the proposals hold only it.
const SAVE = String.raw`<?php
$user = get_user_by( 'login', getenv( 'XB_E2E_USER' ) );
wp_set_current_user( $user->ID );
$id = (int) getenv( 'E2E_PAGE' );
if ( ! $id ) {
	delete_option( \XB\Versioning\Proposals::OPTION );
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Proposte ' . getenv( 'E2E_RUN' ), 'post_content' => wp_slash( '<!-- wp:xb/element {"uid":"pr' . getenv( 'E2E_RUN' ) . '","tag":"p"} --><!-- wp:xb/text {"uid":"pt' . getenv( 'E2E_RUN' ) . '","content":"Online"} /--><!-- /wp:xb/element -->' ) ) );
}
$v   = \XB\Plugin::instance()->versioning;
$doc = \XB\Versioning\Documents::for_post( $id );
$copy = $v->working_copy( $doc );
$payload = $copy['payload'];
$payload['content'] = preg_replace( '/"content":"[^"]*"/', '"content":"' . getenv( 'E2E_TEXT' ) . '"', (string) $payload['content'] );
$v->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], 'e2e' );
echo "\nE2E=" . $id . "\n";
`;

test('proposte per il cliente: due link firmati, il confronto affiancato e la scelta', async ({ page }) => {
  const run = Date.now().toString(36);
  const env = { XB_E2E_USER: process.env.XB_E2E_USER ?? 'xb-e2e', E2E_RUN: run };
  const id = wpEval(SAVE, { ...env, E2E_TEXT: 'Versione A' }).split('\n').find((l) => l.startsWith('E2E='))!.slice(4);
  await page.goto('/wp-admin/admin.php?page=xb-releases');
  const section = page.locator('#xb-proposte + .xb-panel');
  await section.getByLabel('Nome').fill('Proposta A');
  await section.getByLabel('Si apre su').selectOption(id);
  await section.getByRole('button', { name: 'Salva lo sviluppo come proposta' }).click();
  await expect(page.locator('.notice-success')).toContainText('Proposta A');

  wpEval(SAVE, { ...env, E2E_PAGE: id, E2E_TEXT: 'Versione B' });
  await page.reload();
  await section.getByLabel('Nome').fill('Proposta B');
  await section.getByLabel('Si apre su').selectOption(id);
  await section.getByRole('button', { name: 'Salva lo sviluppo come proposta' }).click();

  const linkA = await section.getByLabel('Link di anteprima di Proposta A').inputValue();
  const visitor = await page.context().browser()!.newContext();
  const guest = await visitor.newPage();
  await guest.goto(linkA);
  await expect(guest.getByText('Versione A')).toBeVisible();
  const compare = await section.getByRole('link', { name: 'Confronta con Proposta B' }).getAttribute('href');
  await guest.goto(compare!);
  await expect(guest.getByRole('heading', { name: 'Proposta A' })).toBeVisible();
  await expect(guest.frameLocator('iframe[title="Proposta A"]').getByText('Versione A')).toBeVisible();
  await expect(guest.frameLocator('iframe[title="Proposta B"]').getByText('Versione B')).toBeVisible();
  await visitor.close();

  page.once('dialog', (d) => void d.accept());
  await section.locator('tr').filter({ has: page.locator('strong', { hasText: 'Proposta A' }) }).getByRole('button', { name: 'Scegli questa' }).click();
  await expect(page.locator('.notice-success')).toContainText('Proposta scelta');
  const chosen = wpEval(String.raw`<?php echo "\nE2E=" . (string) \XB\Plugin::instance()->versioning->working_copy( 'post:' . getenv( 'E2E_PAGE' ) )['payload']['content'] . "\n";`, { E2E_PAGE: id });
  expect(chosen).toContain('Versione A');
  wpEval(String.raw`<?php delete_option( \XB\Versioning\Proposals::OPTION ); wp_delete_post( (int) getenv( 'E2E_PAGE' ), true );`, { E2E_PAGE: id });
});
