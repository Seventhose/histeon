import { Builder } from '../lib/builder.ts';
import { expect, test } from '../lib/test.ts';
import { wpEval, wpEvalAsync } from '../lib/wp.ts';

// Doc 26 of Histeon Publish, voce 222: two situations of real sites Etch fixed in its releases.

test('il builder lavora anche con la REST chiusa agli anonimi, senza chiedere un accesso', async ({ page, data }) => {
  // A must-use plugin as security plugins do it: 401 with a Basic challenge to whoever is not logged in.
  wpEval(
    `<?php
    wp_mkdir_p( WPMU_PLUGIN_DIR );
    file_put_contents( WPMU_PLUGIN_DIR . '/xb-e2e-rest-chiusa.php', '<?php
    add_filter( "rest_authentication_errors", function ( $r ) {
      if ( ! empty( $r ) || is_user_logged_in() ) { return $r; }
      header( "WWW-Authenticate: Basic realm=\\\\"e2e\\\\"" );
      return new WP_Error( "rest_not_logged_in", "Solo per utenti", array( "status" => 401 ) );
    } );' );`,
    {},
  );
  try {
    const dialogs: string[] = [];
    page.on('dialog', (d) => {
      dialogs.push(d.message());
      void d.dismiss();
    });
    const builder = await Builder.open(page, data.pages.api.id);
    await page.evaluate(async () => {
      const xb = (window as unknown as { xb: { insert(h: string): string[]; save(): Promise<void> } }).xb;
      xb.insert('<p class="rest-chiusa">Salvato con la REST chiusa</p>');
      await xb.save();
    });
    await expect(builder.canvas.getByText('Salvato con la REST chiusa')).toBeVisible();
    await page.getByRole('navigation', { name: 'Sezioni' }).getByRole('button', { name: 'Libreria dei media' }).click();
    await expect(page.getByRole('dialog', { name: 'Gestore dei media' })).toBeVisible();
    await page.keyboard.press('Escape');
    await builder.tab('Campi');
    await expect(page.getByRole('button', { name: /^Categorie/ })).toBeVisible();
    expect(dialogs).toEqual([]);
    // The anonymous REST is really closed.
    const anonymous = await fetch(`${new URL(page.url()).origin}/wp-json/wp/v2/types`);
    expect(anonymous.status).toBe(401);
  } finally {
    wpEval(`<?php @unlink( WPMU_PLUGIN_DIR . '/xb-e2e-rest-chiusa.php' );`, {});
  }
});

test.describe('una tassonomia con 5000 termini', () => {
  // The 5000 terms are made and removed outside the test's own time (D3, 13 November 2026): wp_insert_term takes
  // about three minutes on a loaded machine, and the 180 seconds below are for the builder alone.
  const run = Date.now().toString(36);

  test.beforeAll(async () => {
    test.setTimeout(600_000);
    await wpEvalAsync(
      `<?php
      wp_defer_term_counting( true );
      for ( $i = 1; $i <= 5000; $i++ ) {
        wp_insert_term( sprintf( 'Massa ${run} %04d', $i ), 'category' );
      }
      wp_defer_term_counting( false );`,
    );
  });

  test.afterAll(async () => {
    test.setTimeout(600_000);
    await wpEvalAsync(
      `<?php
      foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'search' => '${run}', 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
        wp_delete_term( (int) $id, 'category' );
      }`,
    );
  });

  test('tabella dei termini e filtro delle query complete e veloci', async ({ page, data }) => {
    test.setTimeout(180_000);
    const builder = await Builder.open(page, data.pages.palette.id);
    // The terms table: the last one is found and the pages are counted.
    await builder.tab('Campi');
    await page.getByRole('button', { name: /^Categorie/ }).click();
    await page.getByRole('tab', { name: 'Termini' }).click();
    const table = page.getByRole('region', { name: 'Termini di Categorie' });
    await expect(table.getByText(/\d{4,} termini/)).toBeVisible();
    const started = Date.now();
    await table.getByLabel('Cerca in Categorie').fill(`${run} 4999`);
    await expect(table.getByLabel(`Nome di Massa ${run} 4999`)).toBeVisible();
    expect(Date.now() - started).toBeLessThan(5000);

    // A loop filtered by a term beyond the first 200: found by its name, kept with its name.
    await builder.tab('Query');
    const name = `massa_${run}`;
    await page.getByLabel('Nome della nuova query').fill(name);
    await page.getByRole('button', { name: 'Nuova query' }).click();
    const query = page.getByRole('region', { name: `Query ${name}` });
    await query.getByRole('button', { name: '+ Filtra per categoria, tag o tassonomia' }).click();
    await query.getByLabel(/Cerca un termine di/).fill(`${run} 4321`);
    const add = query.getByLabel('Aggiungi un termine');
    await expect(add.locator('option', { hasText: `Massa ${run} 4321` })).toHaveCount(1);
    const value = await add.locator('option', { hasText: `Massa ${run} 4321` }).getAttribute('value');
    await add.selectOption(value!);
    await expect(query.locator('.chip', { hasText: `Massa ${run} 4321` })).toBeVisible();
  });
});
