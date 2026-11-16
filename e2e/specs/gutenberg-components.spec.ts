import { randomBytes } from 'node:crypto';
import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

// Components in Gutenberg (lista della spesa 133-135): props as friendly fields, the slots as editable areas under
// the component, and for a client (role «Cliente Histeon») everything visible and read-only.
const MAKE = String.raw`<?php
$run  = getenv( 'E2E_RUN' );
$ref  = 'e2e/scheda-' . $run;
$uid  = static fn ( string $s ): string => 'e2e' . substr( md5( $s . $run ), 0, 10 );
$def  = '<!-- wp:xb/element {"uid":"' . $uid( 'card' ) . '","tag":"div","attributes":{"class":"e2e-card-' . $run . '"}} -->'
	. '<!-- wp:xb/element {"uid":"' . $uid( 'h' ) . '","tag":"h3"} --><!-- wp:xb/text {"uid":"' . $uid( 't' ) . '","content":"{props.titolo}"} /--><!-- /wp:xb/element -->'
	. '<!-- wp:xb/slot {"uid":"' . $uid( 'slot' ) . '","name":"default"} /-->'
	. '<!-- /wp:xb/element -->';
$component = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Scheda ' . $run, 'post_content' => wp_slash( $def ) ) );
update_post_meta( $component, \XB\Render\ComponentRegistry::META_KEY, $ref );
update_post_meta( $component, \XB\Render\ComponentRegistry::META_SCHEMA, wp_json_encode( array( 'props' => array(
	'titolo' => array( 'type' => 'string', 'default' => 'Titolo', 'label' => 'Titolo' ),
	'tono'   => array( 'type' => 'enum', 'options' => array( 'chiaro', 'scuro' ), 'default' => 'chiaro', 'label' => 'Tono' ),
	'link'   => array( 'type' => 'url', 'label' => 'Collegamento' ),
) ) ) );
$page = '<!-- wp:xb/component {"uid":"' . $uid( 'use' ) . '","ref":"' . $ref . '","props":{"titolo":"Ciao componente"}} -->'
	. '<!-- wp:xb/slot-fill {"name":"default"} --><!-- wp:xb/element {"uid":"' . $uid( 'p' ) . '","tag":"p"} --><!-- wp:xb/text {"uid":"' . $uid( 'pt' ) . '","content":"Dentro lo slot"} /--><!-- /wp:xb/element --><!-- /wp:xb/slot-fill -->'
	. '<!-- /wp:xb/component -->';
$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Componenti ' . $run, 'post_content' => wp_slash( $page ) ) );
$login = 'e2e-cliente-' . $run;
$client = wp_insert_user( array( 'user_login' => $login, 'user_pass' => getenv( 'E2E_CLIENT_PASS' ), 'user_email' => $login . '@example.test', 'role' => \XB\Capabilities::CLIENT_ROLE ) );
echo "\nE2E=" . wp_json_encode( array( 'page' => $id, 'client' => $login, 'component' => $component ) ) . "\n";
`;

test('Gutenberg: props comode, slot modificabili sotto il componente, e il cliente vede tutto in sola lettura', async ({ page, browser }) => {
  await page.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  const run = Date.now().toString(36);
  const clientPass = randomBytes(18).toString('base64url');
  const out = wpEval(MAKE, { E2E_RUN: run, E2E_CLIENT_PASS: clientPass });
  const made = JSON.parse(out.split('\n').find((l) => l.startsWith('E2E='))!.slice(4)) as { page: number; client: string };

  const openAs = async (p: typeof page) => {
    await p.goto(`/wp-admin/post.php?post=${made.page}&action=edit`);
    const canvas = p.frameLocator('iframe[name="editor-canvas"]');
    await expect(canvas.getByText('Ciao componente')).toBeVisible({ timeout: 30_000 });
    const guide = p.getByRole('dialog', { name: /Benvenut|Welcome/ });
    if (await guide.isVisible().catch(() => false)) await p.keyboard.press('Escape');
    return canvas;
  };
  const selectComponent = async (p: typeof page) =>
    p.evaluate(() => {
      const wp = (window as unknown as { wp: any }).wp;
      const block = wp.data.select('core/block-editor').getBlocks().find((b: { name: string }) => b.name === 'xb/component');
      wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
      wp.data.dispatch('core/edit-post').openGeneralSidebar('edit-post/block');
    });

  // 134: the slot is an area under the component, named, with its blocks editable.
  const canvas = await openAs(page);
  const area = canvas.locator('.xb-editor-slot-fill');
  await expect(area).toBeVisible();
  await expect(area.locator('.xb-editor-slot-label')).toHaveText(/default/);
  await expect(area.getByText('Dentro lo slot')).toBeVisible();

  // 133: a short choice is buttons, a link has the site's suggestions, a changed value goes back to the default.
  await selectComponent(page);
  const tono = page.getByRole('group', { name: 'Tono' });
  await expect(tono.getByRole('button', { name: 'chiaro' })).toHaveAttribute('aria-pressed', 'true');
  await tono.getByRole('button', { name: 'scuro' }).click();
  await expect(tono.getByRole('button', { name: 'scuro' })).toHaveAttribute('aria-pressed', 'true');
  const props = await page.evaluate(() => {
    const wp = (window as unknown as { wp: any }).wp;
    return wp.data.select('core/block-editor').getBlocks().find((b: { name: string }) => b.name === 'xb/component').attributes.props;
  });
  expect(props.tono).toBe('scuro');
  await expect(page.locator('.block-editor-url-input input').first()).toBeVisible();
  const reset = page.locator('.xb-prop').filter({ has: page.getByRole('group', { name: 'Tono' }) }).getByRole('button', { name: /Valore predefinito|Default value/ });
  await reset.click();
  await expect(tono.getByRole('button', { name: 'chiaro' })).toHaveAttribute('aria-pressed', 'true');

  // 135: the client sees the same panels and the code, and can change nothing from here. The admin leaves the post
  // first, so WordPress's post lock does not cover the client's editor with its dialog.
  await page.goto('/wp-admin/');
  wpEval(`<?php delete_post_meta( ${made.page}, '_edit_lock' );`, {});
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: { width: 1440, height: 900 } });
  const client = await context.newPage();
  await client.route(/^https?:\/\/(?!localhost[:/]|127\.0\.0\.1[:/])/, (route) => route.fulfill({ status: 204, body: '' }));
  await client.goto('/wp-login.php');
  await expect(client.locator('#user_login')).toBeFocused();
  await client.locator('#user_login').fill(made.client);
  await client.locator('#user_pass').fill(clientPass);
  await client.locator('#wp-submit').click();
  await client.waitForURL(/wp-admin/);
  const clientCanvas = await openAs(client);
  await expect(clientCanvas.locator('.xb-editor-slot-fill').getByText('Dentro lo slot')).toBeVisible();
  await selectComponent(client);
  await expect(client.getByText(/In sola lettura|Read-only/).first()).toBeVisible();
  await expect(client.getByRole('group', { name: 'Tono' }).getByRole('button', { name: 'scuro' })).toBeDisabled();
  await expect(client.locator('.xb-prop-slots')).toHaveCount(0);
  await context.close();
});
