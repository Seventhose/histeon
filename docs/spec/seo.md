# SEO — v0.1

Implementazione: `plugin/src/Seo/Seo.php`, `plugin/assets/editor/seo.js`. Test: `plugin/tests/Integration/SeoTest.php`. Decisione: ADR 0011.

## Il problema
Histeon salva i testi negli attributi dei blocchi, quindi chi legge `post_content` trova solo commenti. Senza integrazione:
- l'analisi dei contenuti è vuota (0 parole);
- non c'è descrizione automatica;
- le immagini delle pagine non entrano nelle sitemap.

## La pagina come la vede un visitatore
`Seo::page( $id )` renderizza la pagina dallo stato **online**, come la vede un **visitatore anonimo**:
- durante il render l'utente corrente è azzerato, quindi niente di personale o di non pubblicato entra in una descrizione o in una cache;
- le classi usate da questo render non contano per il CSS su richiesta della pagina in corso.
- `url.query` è vuoto e `url.path` è l'indirizzo della pagina; `page` e `props.page` sono **la pagina riassunta**, non l'archivio o la pagina della richiesta che ha calcolato la copia: un riassunto chiesto mentre si mostra una categoria legge comunque titolo e campi della sua pagina.

Restituisce il testo visibile (senza script, stili e SVG, con gli spazi tra elementi) e le immagini **di questo sito**, senza doppioni, con `alt` e `title`.
- **Cache:** un transient, invalidato quando cambiano la pagina, la release online o la versione di Histeon.
- **Esclusioni:** le pagine protette da password e quelle senza blocchi Histeon non producono nulla.

## Dove arriva
| Dove | Come |
|---|---|
| Riassunto automatico di WordPress (`get_the_excerpt`), usato da `%%excerpt%%` di Yoast, `%excerpt%` di Rank Math e dai feed | Se il riassunto generato è vuoto e non c'è un riassunto scritto, le prime parole della pagina |
| **Yoast SEO**, analisi nell'editor a blocchi | `YoastSEO.app.registerModification( 'content' )`: riceve l'HTML renderizzato (`POST /xb/v1/editor-render`, con i permessi di chi modifica), aggiornato poco dopo ogni modifica |
| **Rank Math**, analisi nell'editor a blocchi | Filtro JavaScript `rank_math_content`, poi `rankMathEditor.refresh( 'content' )` |
| Sitemap di **Yoast** e **Rank Math** | `wpseo_sitemap_urlimages`, `rank_math/sitemap/urlimages`: le immagini della pagina renderizzata si aggiungono alle loro (anche quelle dinamiche) |
| **Slim SEO** | `slim_seo_allowed_blocks` include i blocchi Histeon, quindi il suo parser renderizza la pagina per descrizione e titolo. `slim_seo_sitemap_post` aggiunge le immagini Histeon alla voce della sitemap |
| **The SEO Framework** | `the_seo_framework_get_excerpt` (descrizioni generate) e `the_seo_framework_image_generation_params` (immagini social dopo quella in evidenza). Con The SEO Framework Histeon non aggiunge immagini a una sitemap |

Il testo scritto a mano dall'autore (riassunto, descrizione) ha sempre la precedenza.
