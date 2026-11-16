# Multilingua — v0.1

Decisione: ADR 0013. Hook: `plugin/src/Render/ComponentRegistry.php`, `plugin/src/Rest/Controller.php` (render della tela), `plugin/src/Admin/BuilderScreen.php` (barra delle lingue). Test: `plugin/tests/Integration/MultilingualHooksTest.php`. Motore delle lingue: **Lingua Bridge** (GPL, `docs/HISTOS.md` nel suo repository), con i test end-to-end su WordPress vero in `tests/wp/histos-*.php`.

## Il modello
Ogni lingua è un **post WordPress normale**, collegato agli altri dal plugin delle lingue. Histeon modifica ogni traduzione come una pagina qualsiasi: release, versioni, revisione e anteprima valgono per ogni lingua. Il formato dei blocchi non cambia.

Histeon non conosce nessun plugin delle lingue: offre quattro **hook neutri**, e il plugin delle lingue li usa.

## Gli hook
| Hook | Tipo | Cosa fa |
|---|---|---|
| `xb_component_post( int $owner, string $ref )` | filtro | Il post che definisce il componente `$ref` qui. Il plugin risponde con la traduzione nella lingua della pagina. Conta solo un `wp_block` **pubblicato**: qualsiasi altra risposta (bozza, altro tipo, valore non intero) viene ignorata e resta l'originale. |
| `xb_render_document( ?WP_Post $post )` | azione | Prima che la tela disegni un documento: il plugin si imposta sulla lingua del documento (elenchi, componenti, `data.*`). La cache dei componenti si svuota prima e dopo. |
| `xb_render_document_done( ?WP_Post $post )` | azione | Dopo il render della tela, anche se è fallito (`finally`): il plugin torna allo stato normale. |
| `xb_builder_languages( null, int $post_id )` | filtro | La barra delle lingue del builder. Vedi sotto. |

In più il plugin può offrire una radice di dati con `xb_data_providers` (spec `data.md`); Lingua Bridge offre `data.lingua`.

## La barra delle lingue
Il filtro risponde con:
```php
array(
	'current' => 'en',
	'items'   => array(
		array( 'code' => 'it', 'label' => 'Italiano', 'state' => 'publish', 'url' => admin_url( '…' ) ),
		array( 'code' => 'en', 'label' => 'English',  'state' => 'current', 'url' => '' ),
		array( 'code' => 'de', 'label' => 'Deutsch',  'state' => 'missing', 'url' => admin_url( '…' ) ),
	),
	'source'  => array( 'I testi visibili dell\'originale', '…' ),
)
```
`BuilderScreen::languages()` tiene solo ciò che serve, dei tipi attesi:
- **codici:** solo lettere, cifre e trattini, al massimo 35 caratteri;
- **stati:** `current`, `publish`, `draft`, `missing`; le voci con stato sconosciuto si scartano;
- **etichette:** testo semplice, al massimo 40 caratteri;
- **link:** solo verso l'amministrazione di questo sito (`admin_url()`), altrimenti vuoti; l'editor li accetta solo dalla stessa origine (`languagesOf()` in `editor/src/lib/config.ts`);
- **testi dell'originale:** testo semplice, al massimo 300 testi da 300 caratteri.

Nel builder (`Topbar.svelte`) la barra mostra la lingua corrente, i link alle traduzioni da aprire e un **+XX** per le lingue mancanti, che crea la traduzione e la apre in Histeon. Nelle sessioni componente la barra non c'è.

## Controlli
Con i testi dell'originale, i controlli (spec `checks.md`) segnalano con la regola `untranslated` i testi di almeno 12 caratteri rimasti identici all'originale.

## Selettore di lingua
Lo snippet «Selettore di lingua» del menu Inserisci usa `data.lingua.alternates`:
- appare solo se ci sono almeno due lingue pubblicate;
- è una `nav` con `hreflang`, `lang` e `aria-current="page"` sulla lingua corrente;
- le classi seguono il BEM `lang-switch`, e gli stili sono modificabili come quelli di qualsiasi elemento.

## Sito statico
Con Static Bridge ogni lingua è una cartella della copia statica (`en/…`), senza reindirizzamenti in base al browser. Lingua Bridge nomina gli elenchi di ogni lingua (sitemap `lingua`, filtro `static_bridge_archive_urls_for_post`), quindi anche gli archivi `/en/` restano aggiornati. Dettagli in `docs/STATIC-BRIDGE.md` di Lingua Bridge.

## Da Histeon Publish
Un progetto CMS multilingua arriva in WordPress come contenuti normali: la traduzione di una pagina ha lo slug `<slug>-<lingua>`, perché qui uno slug è unico nel sito. Finito l'invio, Publish manda `POST /cms/v1/languages` con i legami (`links`: `translationId`, `sourceId`, `language`, `mode`), firmato come ogni richiesta del collegamento. Histeon trova i due post e, per ogni legame, chiama:

| Hook | Tipo | Cosa fa |
|---|---|---|
| `xb_remote_language_link( int $translation, int $source, string $language, string $mode )` | azione | Il plugin delle lingue collega il contenuto. `$mode` è `follow` o `independent`. |

Un legame a un contenuto che ancora non c'è aspetta nell'opzione `xb_cms_waiting_language_links`: i record inviati senza pubblicare nascono con la release, e l'azione parte allora. Ogni invio riscrive l'elenco. Senza plugin delle lingue la risposta è `taken: 0`, e Publish lo dice.

## Cosa fa il plugin delle lingue, non Histeon
- URL per lingua (`/en/…`), `hreflang`, sitemap;
- elenchi e ricerche nella lingua della pagina;
- creazione delle traduzioni e traduzione automatica (solo bozze, mai sovrascrivere ciò che è pubblicato).
