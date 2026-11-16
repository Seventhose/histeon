import { wpEval } from './wp.ts';

/**
 * A page of the spec's own, published, with `markup` written in Histeon's block markup: specs that save or publish
 * work there and leave the shared fixture pages (`data.pages`) as the other specs expect them.
 */
export function ownPage(title: string, markup = '<!-- wp:xb/element {"uid":"own' + Date.now().toString(36) + '","tag":"p"} --><!-- wp:xb/text {"uid":"ownt' + Date.now().toString(36) + '","content":"Pagina di prova"} /--><!-- /wp:xb/element -->'): number {
  const out = wpEval(
    String.raw`<?php
$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => getenv( 'E2E_TITLE' ), 'post_content' => wp_slash( (string) getenv( 'E2E_MARKUP' ) ) ) );
echo "\nE2E=" . $id . "\n";`,
    { E2E_TITLE: title, E2E_MARKUP: markup },
  );
  return Number(out.split('\n').find((l) => l.startsWith('E2E='))!.slice(4));
}
