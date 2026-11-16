# ADR 0013: Multilingua

Data: 2026-10-21 · Stato: **decisa: strada A, con Lingua Bridge come motore** (vedi «Decisione» in fondo)

## Il punto di partenza
Come Histos tiene le cose oggi, e dove il multilingua le tocca:

| Parte | Dove sta | Cosa serve per le lingue |
|---|---|---|
| Pagine, articoli | `post_content` (blocchi `xb/*`, testo negli attributi) | una copia per lingua, oppure i testi tradotti dentro i blocchi |
| Componenti | `wp_block` con la chiave `xb_component_key`; si risolvono con il più vecchio che ha la chiave (`ComponentRegistry::owner`, `suppress_filters`) | oggi ignorano la lingua: due traduzioni con la stessa chiave, vince sempre l'originale |
| Template e parti (header, footer) | `wp_template`, `wp_template_part` | i testi fissi (menu, piè di pagina) vanno tradotti |
| Stili, impostazioni, query, filtri | documenti `styles` e `settings`, uno per sito | uguali per tutte le lingue: va bene così |
| Loop e query | `WP_Query` e `get_terms` | devono mostrare i contenuti della lingua della pagina |
| SEO, sitemap, `hreflang` | plugin SEO e plugin delle lingue | Histos passa già il contenuto disegnato (ADR 0011) |
| Sito statico (Static Bridge, zip, Cloudflare) | percorsi pubblici | una cartella per lingua; nessun reindirizzamento in base al browser |

## Le strade possibili

### A. Compatibilità con un plugin delle lingue: una pagina per lingua (consigliata)
Polylang o WPML gestiscono le lingue. Ogni traduzione è un post collegato all'originale, e Histos modifica ogni lingua come una pagina normale.
- **Pro:**
  - il modello di dati di Histos non cambia;
  - SEO, URL (`/en/…`), `hreflang`, selettore di lingua e sitemap li fa il plugin, che è maturo;
  - le release, le versioni e la revisione (D3) valgono per ogni lingua;
  - ogni lingua può avere differenze vere: un'immagine o un blocco in più.
- **Contro:**
  - una struttura cambiata in una lingua non si propaga da sola alle altre;
  - servono alcune integrazioni (sotto).

### B. Lingue dentro la pagina: un testo per lingua in ogni blocco
`xb/text` diventerebbe `{ it: "…", en: "…" }`.
- **Pro:** una sola struttura per tutte le lingue.
- **Contro:**
  - cambia il formato dei dati di tutto, quindi DSL, pannelli, Gutenberg, SEO, importazione da Etch, connettore e release;
  - i plugin SEO e di traduzione non lo capirebbero;
  - gli URL per lingua andrebbero rifatti da zero.

È molto lavoro e molto rischio. **Sconsigliata.**

### C. Un sito per lingua (multisite o siti separati)
Il design si porta da un sito all'altro con l'esportazione e l'importazione delle release, che Histos ha già.
- **Pro:** isolamento totale e domini diversi senza difficoltà; adatta a siti grandi o a squadre diverse per paese.
- **Contro:**
  - più siti da mantenere;
  - i contenuti condivisi vanno sincronizzati a mano.

È un complemento di A, non un'alternativa per i siti normali.

## Proposta: A, con Polylang per primo
Polylang per primo, WPML subito dopo, perché è molto diffuso. Il lavoro, per fasi:

| # | Cosa | Dimensione |
|---|---|---|
| L1 | **Componenti per lingua:** la chiave si risolve sulla traduzione nella lingua della pagina, se c'è, altrimenti sull'originale. Le sessioni componente (aprire, salvare) lavorano sulla traduzione giusta. | M |
| L2 | **«Traduci questa pagina» nel builder:** crea la traduzione collegata (API di Polylang/WPML) copiando il contenuto con gli stessi uid e la apre nel builder. Si traduce scrivendo direttamente nella tela (A1), e il pannello Struttura mostra cosa manca. | M |
| L3 | **Testi dei template:** i testi fissi di header e footer diventano props di componenti tradotti (L1). In più una radice `lang` (`{lang.code}`, `{lang.name}`, `{lang.switcher}`) e un componente «Selettore di lingua» accessibile (`hreflang`, `aria-current`). | M |
| L4 | **Editor di traduzione e traduzione automatica:** `wpml-config.xml` e filtri di Polylang che dicono quali attributi si traducono: `xb/text`, `alt`, `title`, `aria-label`, `placeholder`, le props di testo. Così funzionano l'Advanced Translation Editor di WPML, l'esportazione XLIFF e la traduzione automatica (DeepL). | S |
| L5 | **Loop e query:** verifica che restino nella lingua della pagina, con test (Polylang filtra `WP_Query` se il tipo è traducibile); le query con nome e i filtri dei loop compresi. | S |
| L6 | **Statico e anteprima:** Static Bridge e zip per lingua, il reindirizzamento in base al browser spento per le copie statiche, i link di anteprima e i commenti per traduzione. | S |
| L7 | **Controlli (C3):** avviso per i testi rimasti nella lingua originale e per gli `alt` non tradotti. | S |

Ordine consigliato: **L5 → L1 → L2 → L4 → L3 → L6 → L7**. Prima la verifica che i contenuti restino nella lingua giusta, poi ciò che oggi sarebbe sbagliato (i componenti), poi il flusso di traduzione.

## Domande per decidere
1. **Plugin:** Polylang, WPML, o si parte da zero (allora Polylang)?
2. **URL:** sottocartella (`/en/`), sottodominio o dominio per lingua?
3. **Chi traduce:** chi costruisce il sito o i clienti nel builder (allora contano L2 e la scrittura nella tela), oppure traduttori o agenzie con file XLIFF o traduzione automatica (allora conta L4)?
4. **Lingue e siti:** quante lingue, e ci sono siti grandi da tenere separati per paese (strada C)?

## Decisione
Strada **A**, con **Lingua Bridge** al posto di Polylang. Lingua Bridge è il plugin multilingua GPL che avevamo già sviluppato: una pagina per lingua, URL `/en/…`, `hreflang`, traduzione tramite connettore e IA solo come bozza. Polylang e WPML restano possibili con gli stessi hook.

Histos non dipende da nessun plugin delle lingue. Offre hook neutri (spec `multilingual.md`):
- `xb_component_post`;
- `xb_render_document` e `xb_render_document_done`;
- `xb_builder_languages`;
- i dati `xb_data_providers` (`data.lingua`).

Le fasi, come sono state fatte:

| # | Stato |
|---|---|
| L1 componenti per lingua | fatto: `xb_component_post`, sul sito e nella tela |
| L2 «Traduci questa pagina» | fatto: barra delle lingue nel builder, il **+XX** crea la traduzione (bozza) e la apre in Histos |
| L3 selettore di lingua | fatto: snippet «Selettore di lingua» su `data.lingua` |
| L4 editor di traduzione, XLIFF, traduzione automatica | da fare, con la traduzione tramite IA di Lingua Bridge |
| L5 loop e query | fatto e provato su WordPress vero (routing di Lingua Bridge, anche nella tela) |
| L6 statico | fatto per Static Bridge: una cartella per lingua; un contenuto tradotto rigenera gli elenchi della sua lingua (filtro `static_bridge_archive_urls_for_post`), la sitemap `lingua` li nomina per l'export completo. Provato con il codice vero di Static Bridge. Lo zip e il deploy Cloudflare di Histos: da provare |
| L7 controlli | fatto: regola `untranslated` |

Risposte alle domande:
1. **Plugin:** Lingua Bridge.
2. **URL:** sottocartella (`/en/`).
3. **Chi traduce:** nel builder, con la tela, e con l'IA tramite il connettore (solo bozze).
4. **Lingue e siti:** la strada C resta per i casi particolari.
