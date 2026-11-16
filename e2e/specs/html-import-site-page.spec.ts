import { expect, test } from '../lib/test.ts';
import { wpEval } from '../lib/wp.ts';

/**
 * V5.2, «Importa una pagina di questo sito». Two pages of the site made of WordPress blocks, one after the other: each
 * is downloaded by the browser as a visitor, converted with the site's base («pagina aggiunta»), checked and imported in
 * development. At the end the site has two new pages in development, and the styles it had before are still there,
 * unchanged and first.
 *
 * V5.6: the page's styles hold only on its page (Publish V5.5), and its colours are a choice (Publish V5.7): «pagina»,
 * «sito», or «sostituisci» where the site uses Histeon CSS, shown «prima → dopo» and confirmed before the check.
 */
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

/** A page of blocks, with an image of the media library, a group with its own class and a column layout. */
const blocks = (title: string, image: string) => `<!-- wp:group {"className":"bottega-apertura","layout":{"type":"constrained"}} -->
<div class="wp-block-group bottega-apertura"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">${title}</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"bottega-apertura__testo"} -->
<p class="bottega-apertura__testo">Impara a costruire un tavolo con le tue mani.</p>
<!-- /wp:paragraph -->
<!-- wp:image {"sizeSlug":"full"} -->
<figure class="wp-block-image size-full"><img src="${image}" alt="Il banco da lavoro"/></figure>
<!-- /wp:image -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Sabato mattina</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>Martedì sera</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->`;

const read = (php: string) => wpEval(php, {}).trim().split('\n').pop() ?? '';
/**
 * wp-cli runs with no user: a version saved that way is not trusted, and an untrusted development head of the styles or
 * the settings turns off the scripts of every preview (Preview::has_untrusted_dev_content). Restores save as an administrator.
 */
const AS_ADMIN = `wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );`;
/** The shared documents whose development head is not trusted: none, after a restore. */
const untrustedHeads = () =>
  JSON.parse(
    read(`<?php
      $store = \\XB\\Plugin::instance()->versioning->store();
      $out   = array();
      foreach ( array( \\XB\\Versioning\\Documents::STYLES, \\XB\\Versioning\\Documents::SETTINGS ) as $doc ) {
        $head = $store->get( (string) $store->head( $doc, \\XB\\Versioning\\Store::DEV ) );
        if ( null !== $head && ! $head['trusted'] ) { $out[] = $doc; }
      }
      echo wp_json_encode( $out );`),
  ) as string[];

test('Importa una pagina di questo sito: due pagine di blocchi, una dopo l’altra, senza toccare gli stili di prima', async ({ page: browser }) => {
  test.slow();
  const stamp = Date.now().toString(36);
  // The site's project: an id and the id of its styles (as a download of the project leaves them), when it has none.
  const previous = read(`<?php echo wp_json_encode( array( get_option( 'xb_page_profile_project_id', '' ), get_option( 'xb_page_profile_styles_id', '' ), get_option( 'xb_page_profile_styles_base_hash', '' ) ) );`);
  const [projectBefore, stylesIdBefore, baseHashBefore] = JSON.parse(previous) as [string, string, string];
  if (!projectBefore) read(`<?php update_option( 'xb_page_profile_project_id', '7a1c2b9e-3d4a-4b5c-8d6e-7f8091a2b3c5', false );`);
  if (!stylesIdBefore) read(`<?php update_option( 'xb_page_profile_styles_id', '8b2d3c0f-4e5b-4c6d-9e7f-8091a2b3c4d5', false );`);
  const styles = () => JSON.parse(read(`<?php echo wp_json_encode( \\XB\\Remote\\PageProfileAdapter::dev_payload( \\XB\\Versioning\\Documents::STYLES ) );`)) as { styles: Array<{ id: string; selector: string; css: string }>; globals: Array<{ id: string; css: string }> };
  const before = styles();
  const made = JSON.parse(
    read(`<?php
      $file = wp_upload_dir()['path'] . '/banco-${stamp}.png';
      file_put_contents( $file, base64_decode( '${PNG}' ) );
      $media = wp_insert_attachment( array( 'post_title' => 'banco-${stamp}', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $file );
      $url   = wp_get_attachment_url( $media );
      $make  = static fn ( string $title, string $slug, string $content ): int => wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => wp_slash( $content ) ) );
      echo wp_json_encode( array(
        'media' => $media,
        'first' => $make( 'Corsi ${stamp}', 'corsi-${stamp}', str_replace( '__URL__', $url, base64_decode( '${Buffer.from(blocks('Corsi di falegnameria', '__URL__')).toString('base64')}' ) ) ),
        'second' => $make( 'Orari ${stamp}', 'orari-${stamp}', str_replace( '__URL__', $url, base64_decode( '${Buffer.from(blocks('Orari della bottega', '__URL__')).toString('base64')}' ) ) ),
      ) );`),
  ) as { media: number; first: number; second: number };
  const imported: number[] = [];
  // Every request the screen makes with fetch: only this site, and the page and its files without the cookies.
  const fetched: Array<{ url: string; cookie: boolean }> = [];
  browser.on('request', (request) => {
    if (request.resourceType() !== 'fetch') return;
    void request.allHeaders().then((headers) => fetched.push({ url: request.url(), cookie: 'cookie' in headers }));
  });
  try {
    for (const [title, slug] of [[`Corsi ${stamp}`, `corsi-${stamp}`], [`Orari ${stamp}`, `orari-${stamp}`]] as const) {
      await browser.goto('/wp-admin/admin.php?page=xb-releases');
      const panel = browser.locator('#xb-html-import');
      const site = panel.locator('[data-site]');
      await expect(site).toContainText('parti e componenti non si creano');
      await site.getByRole('button', { name: 'Importa una pagina di questo sito' }).click();
      await site.getByLabel('Cerca una pagina').fill(title);
      // Pages already of Histeon are not offered, and said so.
      await expect(site.locator('[data-site-left]')).toContainText('sono già pagine di Histeon');
      await expect(site.getByLabel('Pagina da importare').locator('option')).toHaveCount(1);
      await site.getByRole('button', { name: 'Importa questa pagina' }).click();
      const report = panel.locator('[data-added]');
      await expect(report).toContainText(`La pagina nuova avrà l’indirizzo /${slug}-2/`, { timeout: 60_000 });
      await expect(report).toContainText(`Nel progetto la pagina si chiama «${slug}-2»`);
      await expect(report).toContainText('Entrano:');
      // Since V5.5 the page's styles hold only on its page: said before the check, with the limit.
      await expect(report).toContainText('Gli stili della pagina valgono solo su questa pagina');
      await expect(report).toContainText('non prende gli stili della pagina');
      await expect(panel.locator('[data-error]')).toBeHidden();
      await panel.getByRole('button', { name: 'Prosegui al controllo' }).click();
      await expect(browser.getByText(/Ecco cosa cambierebbe; nulla è ancora stato scritto/)).toBeVisible();
      await browser.getByRole('button', { name: 'Importa in sviluppo' }).click();
      await expect(browser.getByText(/Pacchetto importato in sviluppo: \d+ elementi cambiati/)).toBeVisible();
      const id = Number(read(`<?php $p = get_page_by_path( '${slug}-2', OBJECT, 'page' ); echo $p ? $p->ID : 0;`));
      expect(id, `la pagina ${slug}-2`).toBeGreaterThan(0);
      imported.push(id);
    }
    const origin = new URL(browser.url()).origin;
    expect(fetched.every((item) => item.url.startsWith(`${origin}/`)), JSON.stringify(fetched)).toBe(true);
    // The screen's own requests (the REST readings, SQLite's WebAssembly) are the administrator's; the rest is the visitor's.
    const visitor = fetched.filter((item) => !/\/wp-json\/|[?&]rest_route=|\/assets\/html-import\//.test(item.url));
    expect(visitor.some((item) => item.url.includes(`/corsi-${stamp}/`))).toBe(true);
    expect(visitor.some((item) => /\/wp-content\/uploads\/.*banco-/.test(item.url))).toBe(true);
    expect(visitor.filter((item) => item.cookie), 'la pagina e i suoi file si scaricano senza i cookie').toEqual([]);
    expect(visitor.some((item) => /\/wp-(admin|json)\/|wp-login\.php/.test(item.url))).toBe(false);
    // Two new pages, in development; the pages of origin as they were.
    const state = JSON.parse(
      read(`<?php echo wp_json_encode( array_map( static fn ( int $id ): array => array( get_post_status( $id ), (string) get_post_meta( $id, '_xb_page_profile_id', true ) ), array( ${imported.join(', ')} ) ) );`),
    ) as Array<[string, string]>;
    expect(state.map(([status]) => status)).toEqual(['draft', 'draft']);
    expect(state.every(([, profile]) => /^[0-9a-f-]{36}$/.test(profile))).toBe(true);
    expect(read(`<?php echo get_post_status( ${made.first} ) . ',' . get_post_status( ${made.second} );`)).toBe('publish,publish');
    // The styles of before: all still there, unchanged, and the site's sheets first.
    const after = styles();
    for (const style of before.styles) expect(after.styles.find((item) => item.id === style.id), style.selector).toEqual(style);
    expect(after.globals.slice(0, before.globals.length)).toEqual(before.globals);
    expect(after.styles.length).toBeGreaterThan(before.styles.length);
  } finally {
    // Imported pages, their media and the pages of origin; the styles as they were; the project as it was.
    read(`<?php
      foreach ( array( ${[...imported, made.first, made.second].join(', ')} ) as $id ) { delete_post_meta( $id, '_xb_page_profile_id' ); wp_delete_post( $id, true ); }
      foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 's' => 'banco-${stamp}' ) ) as $id ) { delete_post_meta( $id, '_xb_page_profile_id' ); wp_delete_attachment( $id, true ); }
      wp_delete_attachment( ${made.media}, true );`);
    read(`<?php
      ${AS_ADMIN}
      $versioning = \\XB\\Plugin::instance()->versioning;
      $copy = $versioning->working_copy( \\XB\\Versioning\\Documents::STYLES );
      $before = json_decode( base64_decode( '${Buffer.from(JSON.stringify(before)).toString('base64')}' ), true );
      $versioning->save_dev( \\XB\\Versioning\\Documents::STYLES, $before + $copy['payload'], (string) $copy['head'], (string) $copy['base'], 'e2e' );`);
    if (!projectBefore) read(`<?php delete_option( 'xb_page_profile_project_id' );`);
    if (!stylesIdBefore) read(`<?php delete_option( 'xb_page_profile_styles_id' );`);
    read(baseHashBefore ? `<?php update_option( 'xb_page_profile_styles_base_hash', '${baseHashBefore}', false );` : `<?php delete_option( 'xb_page_profile_styles_base_hash' );`);
  }
  expect(untrustedHeads(), 'styles e settings fidati dopo il ripristino').toEqual([]);
});

/** The look the comparisons read: the computed styles of `body` and of the first `h1`. */
const LOOK = ['background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'margin-top', 'padding-top', 'border-top-width', 'border-top-color'];
/** The page of origin's own sheet, printed in its head by a must-use plugin of the test: rules on body, h1 and a button. */
const SHEET = 'body{background:#f3e9d2}h1{color:#5b2c06;letter-spacing:.05em}.bottega-banda{border-top:6px solid #2e7d32;padding:16px}.bottega-banda__nota{color:#2e7d32;border-left:4px solid #2e7d32}.bottega-invito{display:inline-block;background:#2e7d32;color:#fff;padding:8px 16px;border-radius:6px}.bottega-banda__riga{border-bottom:2px solid #c62828}.bottega-banda__piede{color:#c62828}';
const banda = (title: string) => `<!-- wp:group {"className":"bottega-banda","layout":{"type":"constrained"}} -->
<div class="wp-block-group bottega-banda"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">${title}</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"bottega-banda__nota"} -->
<p class="bottega-banda__nota">Dal 1950, in via del Legno.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"bottega-banda__riga"} -->
<p class="bottega-banda__riga"><a class="bottega-invito" href="#contatti">Scrivici</a></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"bottega-banda__piede"} -->
<p class="bottega-banda__piede">Aperto il sabato.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->`;

test('Importa una pagina di questo sito: gli stili restano sulla pagina, e i colori si scelgono', async ({ page: browser }) => {
  test.slow();
  const stamp = Date.now().toString(36);
  const shot = (name: string, tab = browser) => tab.screenshot({ path: test.info().outputPath(name), fullPage: true });
  // The previews are read without JavaScript, as a look: a copied page also carries the theme's navigation markup, whose
  // WordPress script fails on a copy without its state (a finding of V5.6, out of this test). A preview link needs no login.
  const visitor = await browser.context().browser()!.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
  const tab = await visitor.newPage();
  const previous = read(`<?php echo wp_json_encode( array( get_option( 'xb_page_profile_project_id', '' ), get_option( 'xb_page_profile_styles_id', '' ), get_option( 'xb_page_profile_styles_base_hash', '' ), get_option( 'xb_modules', null ) ) );`);
  const [projectBefore, stylesIdBefore, baseHashBefore, modulesBefore] = JSON.parse(previous) as [string, string, string, unknown];
  if (!projectBefore) read(`<?php update_option( 'xb_page_profile_project_id', '7a1c2b9e-3d4a-4b5c-8d6e-7f8091a2b3c5', false );`);
  if (!stylesIdBefore) read(`<?php update_option( 'xb_page_profile_styles_id', '8b2d3c0f-4e5b-4c6d-9e7f-8091a2b3c4d5', false );`);
  const copies = () => read(`<?php $v = \\XB\\Plugin::instance()->versioning; echo base64_encode( wp_json_encode( array( $v->working_copy( \\XB\\Versioning\\Documents::STYLES )['payload'], $v->working_copy( \\XB\\Versioning\\Documents::SETTINGS )['payload'] ) ) );`);
  const before = copies();
  const made = JSON.parse(
    read(`<?php
      $mu = WPMU_PLUGIN_DIR;
      wp_mkdir_p( $mu );
      file_put_contents( $mu . '/xb-e2e-v56.php', "<?php\\nadd_action( 'wp_head', static function (): void { \\$css = is_singular() ? (string) get_post_meta( get_queried_object_id(), '_xb_e2e_v56_css', true ) : ''; if ( '' !== \\$css ) { echo '<style id=\\"bottega-v56\\">' . wp_strip_all_tags( \\$css ) . '</style>'; } }, 99 );\\n" );
      $make = static function ( string $title, string $slug, string $content, bool $css ): int {
        $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => wp_slash( $content ) ) );
        if ( $css ) { update_post_meta( $id, '_xb_e2e_v56_css', wp_slash( base64_decode( '${Buffer.from(SHEET).toString('base64')}' ) ) ); }
        return $id;
      };
      $other = '<!-- wp:xb/element {"uid":"v56h${stamp}","tag":"h1"} --><!-- wp:xb/text {"uid":"v56t${stamp}","content":"Un’altra pagina"} /--><!-- /wp:xb/element --><!-- wp:xb/element {"uid":"v56p${stamp}","tag":"p"} --><!-- wp:xb/text {"uid":"v56q${stamp}","content":"Fatta con Histeon."} /--><!-- /wp:xb/element -->';
      echo wp_json_encode( array(
        'keep'    => $make( 'Bottega ${stamp}', 'bottega-${stamp}', base64_decode( '${Buffer.from(banda('La bottega')).toString('base64')}' ), true ),
        'replace' => $make( 'Laboratorio ${stamp}', 'laboratorio-${stamp}', base64_decode( '${Buffer.from(banda('Il laboratorio')).toString('base64')}' ), true ),
        'other'   => $make( 'Altra ${stamp}', 'altra-${stamp}', $other, false ),
      ) );`),
  ) as { keep: number; replace: number; other: number };
  const imported: number[] = [];
  // A development preview link, issued by an administrator (a link stops working when its issuer cannot edit the page).
  const previewOf = (id: number) => read(`<?php wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ); echo \\XB\\Versioning\\Preview::url( ${id} );`);
  const look = async (url: string) => {
    await tab.goto(url);
    return tab.evaluate((props) => {
      const pick = (node: Element | null) => (node ? Object.fromEntries(props.map((prop) => [prop, getComputedStyle(node).getPropertyValue(prop)])) : null);
      return { body: pick(document.body), h1: pick(document.querySelector('h1')) };
    }, LOOK);
  };
  const inPreview = async () => expect(tab.locator('[data-xb-preview-badge]'), 'anteprima di sviluppo').toHaveCount(1);
  const button = async (where = browser) => where.getByText('Scrivici', { exact: true }).evaluate((node) => getComputedStyle(node).backgroundColor);
  /** «Importa una pagina di questo sito» for one page, with one choice of colours; returns the panel. */
  const choose = async (title: string, colors: 'pagina' | 'sito' | 'sostituisci') => {
    await browser.goto('/wp-admin/admin.php?page=xb-releases');
    const panel = browser.locator('#xb-html-import');
    const site = panel.locator('[data-site]');
    await site.getByRole('button', { name: 'Importa una pagina di questo sito' }).click();
    await site.getByLabel('Cerca una pagina').fill(title);
    await expect(site.getByLabel('Pagina da importare').locator('option')).toHaveCount(1);
    const question = site.locator('[data-site-colors]');
    // Nothing chosen for the person: «La pagina tiene i suoi colori» is checked on opening, as it changes nothing of the site.
    await expect(question.getByLabel('La pagina tiene i suoi colori')).toBeChecked();
    await question.getByRole('radio', { name: { pagina: 'La pagina tiene i suoi colori', sito: 'La pagina prende i colori del sito', sostituisci: 'I colori del sito diventano quelli della pagina' }[colors] }).check();
    return { panel, site };
  };
  /** «Prosegui al controllo» and «Importa in sviluppo»; the id of the page made. */
  const importIt = async (panel: ReturnType<typeof browser.locator>, slug: string) => {
    await panel.getByRole('button', { name: 'Prosegui al controllo' }).click();
    await expect(browser.getByText(/Ecco cosa cambierebbe; nulla è ancora stato scritto/)).toBeVisible();
    await browser.getByRole('button', { name: 'Importa in sviluppo' }).click();
    await expect(browser.getByText(/Pacchetto importato in sviluppo: \d+ elementi cambiati/)).toBeVisible();
    const id = Number(read(`<?php $p = get_page_by_path( '${slug}-2', OBJECT, 'page' ); echo $p ? $p->ID : 0;`));
    expect(id, `la pagina ${slug}-2`).toBeGreaterThan(0);
    imported.push(id);
    return id;
  };
  try {
    // The page of origin as a visitor sees it, and another page of Histeon in the development preview, before.
    await browser.goto(`/bottega-${stamp}/`);
    const original = { button: await button(), body: await browser.evaluate(() => getComputedStyle(document.body).backgroundColor) };
    expect(original.body).toBe('rgb(243, 233, 210)');
    expect(original.button).toBe('rgb(46, 125, 50)');
    const otherPreview = previewOf(made.other);
    const otherBefore = await look(otherPreview);
    await inPreview();
    expect(otherBefore.h1, 'l’altra pagina ha un titolo').not.toBeNull();
    await shot('altra-pagina-prima.png', tab);

    // 1. «La pagina tiene i suoi colori».
    {
      const { panel, site } = await choose(`Bottega ${stamp}`, 'pagina');
      await site.locator('[data-site-colors]').screenshot({ path: test.info().outputPath('domanda-colori.png') });
      await site.getByRole('button', { name: 'Importa questa pagina' }).click();
      const report = panel.locator('[data-added]');
      await expect(report).toContainText('Gli stili della pagina valgono solo su questa pagina', { timeout: 60_000 });
      await expect(report).toContainText('Colori: la pagina tiene i suoi colori');
      await expect(report).not.toContainText('fogli globali');
      await expect(panel.locator('[data-colors-confirm]')).toBeHidden();
      const id = await importIt(panel, `bottega-${stamp}`);
      // The other page: the same as before. The imported page: the background and the button of the original.
      const otherAfter = await look(otherPreview);
      await inPreview();
      await shot('altra-pagina-dopo.png', tab);
      expect(otherAfter).toEqual(otherBefore);
      await tab.goto(previewOf(id));
      await inPreview();
      expect(await tab.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(original.body);
      expect(await button(tab)).toBe(original.button);
      await shot('importata.png', tab);
    }

    // 2. «I colori del sito diventano quelli della pagina»: «prima → dopo» first, nothing goes on without a confirmation.
    {
      const { panel, site } = await choose(`Laboratorio ${stamp}`, 'sostituisci');
      await site.getByRole('button', { name: 'Importa questa pagina' }).click();
      const confirm = panel.locator('[data-colors-confirm]');
      await expect(confirm).toContainText('Cambia l’aspetto di tutto il sito, solo nei colori', { timeout: 60_000 });
      const light = confirm.locator('[data-colors-theme="chiaro"] > li');
      await expect(light.first()).toBeVisible();
      await expect(confirm.locator('.xb-site-colors__sample').first()).toBeVisible();
      await expect(panel.locator('[data-result]')).toBeHidden();
      expect(await panel.locator('[data-package]').evaluate((input) => (input as HTMLInputElement).files?.length ?? 0), 'nessun pacchetto prima della conferma').toBe(0);
      await confirm.screenshot({ path: test.info().outputPath('prima-dopo.png') });
      // «Annulla» goes back to the choice.
      await confirm.getByRole('button', { name: 'Annulla' }).click();
      await expect(confirm).toBeHidden();
      await expect(panel.locator('[data-status]')).toContainText('Annullato: i colori del sito non cambiano');
      await expect(panel.locator('[data-result]')).toBeHidden();
      // Again, and confirmed.
      await site.getByRole('button', { name: 'Importa questa pagina' }).click();
      await expect(light.first()).toBeVisible({ timeout: 60_000 });
      const changes = await light.evaluateAll((items) => items.map((item) => [...item.querySelectorAll(':scope > code, :scope > .xb-site-colors__value > code')].map((code) => code.textContent ?? '')));
      expect(changes.length).toBeGreaterThan(0);
      expect(changes.some(([name]) => name === '--xb-primary')).toBe(true);
      await confirm.getByRole('button', { name: 'Sì, cambia i colori del sito' }).click();
      await expect(panel.locator('[data-added]')).toContainText('Colori: i colori del sito diventano quelli della pagina');
      await importIt(panel, `laboratorio-${stamp}`);
      // The site's variables in development have the values «dopo».
      const printed = JSON.parse(read(`<?php
        $d = \\XB\\Framework\\HistosCss\\SiteDesign::current( true );
        $t = \\XB\\Framework\\HistosCss\\Tokens::printed( \\XB\\Framework\\HistosCss\\Tokens::compute( $d ), $d );
        foreach ( \\XB\\Framework\\TokenOverrides::current( true ) as $k => $v ) { $t[ ltrim( $k, '-' ) ] = $v; }
        echo wp_json_encode( $t );`)) as Record<string, string>;
      for (const [name, , after] of changes) expect(printed[name!.replace(/^--/, '')], name).toBe(after);
    }

    // 3. A site without Histeon CSS: the third choice is not there, and a line says why.
    read(`<?php $m = get_option( 'xb_modules', array() ); $m = is_array( $m ) ? $m : array(); $m['histos-css'] = false; update_option( 'xb_modules', $m );`);
    {
      const { site } = await choose(`Bottega ${stamp}`, 'sito');
      await expect(site.getByRole('radio', { name: 'I colori del sito diventano quelli della pagina' })).toHaveCount(0);
      await expect(site.locator('[data-site-colors-why]')).toContainText('il sito non usa Histeon CSS');
    }
  } finally {
    await visitor.close();
    read(`<?php
      foreach ( array( ${[...imported, made.keep, made.replace, made.other].join(', ')} ) as $id ) { delete_post_meta( $id, '_xb_page_profile_id' ); wp_delete_post( $id, true ); }
      @unlink( WPMU_PLUGIN_DIR . '/xb-e2e-v56.php' );`);
    read(`<?php
      ${AS_ADMIN}
      $v = \\XB\\Plugin::instance()->versioning;
      [ $styles, $settings ] = json_decode( base64_decode( '${before}' ), true );
      foreach ( array( \\XB\\Versioning\\Documents::STYLES => $styles, \\XB\\Versioning\\Documents::SETTINGS => $settings ) as $doc => $payload ) {
        $copy = $v->working_copy( $doc );
        $v->save_dev( $doc, $payload + $copy['payload'], (string) $copy['head'], (string) $copy['base'], 'e2e' );
      }`);
    read(null === modulesBefore ? `<?php delete_option( 'xb_modules' );` : `<?php update_option( 'xb_modules', json_decode( base64_decode( '${Buffer.from(JSON.stringify(modulesBefore)).toString('base64')}' ), true ) );`);
    if (!projectBefore) read(`<?php delete_option( 'xb_page_profile_project_id' );`);
    if (!stylesIdBefore) read(`<?php delete_option( 'xb_page_profile_styles_id' );`);
    read(baseHashBefore ? `<?php update_option( 'xb_page_profile_styles_base_hash', '${baseHashBefore}', false );` : `<?php delete_option( 'xb_page_profile_styles_base_hash' );`);
  }
  expect(untrustedHeads(), 'styles e settings fidati dopo il ripristino').toEqual([]);
});
