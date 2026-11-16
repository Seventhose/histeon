import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';
import { ownPage } from '../lib/page.ts';
import { wpEval } from '../lib/wp.ts';

// Lista della spesa 158: what an AI agent changed shows in a bar, and «Annulla tutto» brings the page back to how it
// was before the agent's first write. The agent writes as the e2e administrator, as it does through the abilities.
const AGENT = String.raw`<?php
$user = get_user_by( 'login', getenv( 'XB_E2E_USER' ) );
wp_set_current_user( $user->ID );
delete_option( \XB\Ai\AgentSession::OPTION );
$v   = \XB\Plugin::instance()->versioning;
$doc = \XB\Versioning\Documents::for_post( (int) getenv( 'E2E_PAGE' ) );
foreach ( array( 'Agente uno', 'Agente due' ) as $text ) {
	$copy = $v->working_copy( $doc );
	$payload = $copy['payload'];
	$payload['content'] = str_replace( 'Ciao e2e', $text, (string) $payload['content'] );
	$payload['content'] = preg_replace( '/Agente \w+/', $text, $payload['content'] );
	$v->save_dev( $doc, $payload, (string) $copy['head'], (string) $copy['base'], 'agente', true, agent: true );
}
echo "\nE2E=ok\n";
`;

test('agente AI: una barra dice cosa ha cambiato, e «Annulla tutto» torna a prima', async ({ page }) => {
  const run = Date.now().toString(36);
  const id = ownPage(`Agente ${run}`, `<!-- wp:xb/element {"uid":"ag${run}","tag":"h2"} --><!-- wp:xb/text {"uid":"at${run}","content":"Ciao e2e"} /--><!-- /wp:xb/element -->`);
  const builder = await Builder.open(page, id);
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).toBeVisible();
  wpEval(AGENT, { E2E_PAGE: String(id), XB_E2E_USER: process.env.XB_E2E_USER ?? 'xb-e2e' });
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  const bar = page.getByRole('region', { name: 'Modifiche dell’agente AI' });
  await expect(bar).toContainText('ha cambiato 1 documento');
  await page.reload();
  await builder.ready();
  await expect(builder.canvas.getByRole('heading', { name: 'Agente due' })).toBeVisible();

  await bar.getByRole('button', { name: 'Annulla tutto' }).click();
  await expect(page.getByRole('status').filter({ hasText: 'com’era prima' })).toBeVisible();
  await expect(bar).toHaveCount(0);
  await expect(builder.canvas.getByRole('heading', { name: 'Ciao e2e' })).toBeVisible();
});
