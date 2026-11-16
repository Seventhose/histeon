# Query dei loop — v0.1

Implementazione: `plugin/src/Render/Queries.php`, `plugin/src/Render/LoopPresets.php`, `editor/src/components/QueryPanel.svelte`. Test: `plugin/tests/Integration/QueriesTest.php`, `editor/test/queries.test.ts`.

## 1. Cosa sono
Una query è un preset con nome, usato dai loop come `{#loop nome(param: valore) as item}`.
- Tipi:
  - `posts`: `WP_Query`;
  - `terms`: `get_terms`;
  - `main`: la query principale della pagina, con `posts_per_page`, `offset`, `order` e `orderby` sostituiti;
  - `users`: autori con contenuti pubblicati, solo profilo pubblico (`docs/spec/data.md` §4).
- I nomi `posts`, `terms`, `main` e `users` sono riservati ai preset predefiniti.
- **Ordine stabile.** Ordinata per data (anche quando l'ordine non è detto), modifica, titolo, ordine di menu o numero di commenti, una lista di post usa l'ID come secondo criterio, nello stesso verso: post con la stessa data (prodotti importati insieme) escono sempre nello stesso ordine, anche quando la query cambia piano (un'altra pagina, un filtro). Un ordine casuale, per campo o già scritto come elenco resta com'è. Il tema Fondamenta fa lo stesso, così la conversione disegna la lista come Histeon.
- Negli argomenti, `"$count"` prende il parametro del loop e `"$count ?? 6"` ha un valore predefinito. `-1` vale il massimo ammesso (100).
- `"$today"` (`2026-10-28`) e `"$now"` (`2026-10-28 14:30:00`) valgono la data e l'ora del fuso del sito, nel formato dei campi data, a meno che il loop passi un parametro con lo stesso nome. Per i prossimi eventi, per esempio: `"meta_query": [{"key": "data", "value": "$today", "compare": ">=", "type": "DATE"}]`. Una pagina statica (Static Bridge, export Cloudflare) resta com'era il giorno in cui è stata generata: per una query con `$today` serve una rigenerazione quotidiana, da pianificare a parte (per esempio con l'export completo pianificato di Static Bridge o con un cron).

## 2. Versioning
Query e tipi ammessi fanno parte del documento `settings`, insieme a framework e reset.
- **Salvare:** dal pannello Query è un commit di sviluppo.
- **Vedere:** l'anteprima firmata e il canvas usano la versione di sviluppo (`Queries::presets()`).
- **Pubblicare:** il sito pubblico riceve le query con la release, e un ripristino le riporta indietro.

## 3. Sicurezza
- **Visibilità:** un preset non può impostare `post_status`, `perm`, `has_password`, `post_password`, `suppress_filters`, `fields`, `cache_results`, `lazy_load_term_meta`. Il salvataggio li rifiuta e l'esecuzione li toglie comunque, anche da preset già salvati. Histeon mostra solo contenuti pubblicati, senza password e leggibili da chi visita.
- **Limiti:** argomenti annidati al massimo 4 livelli e 20 KB per preset; al massimo 200 preset.
- **Tipi di contenuto:** solo quelli visibili pubblicamente, più quelli che un amministratore ammette in Impostazioni → Histeon (`xb_loop_types`). I tipi interni non sono mai ammessi. Allargare l'elenco tramite una release è riservato a chi ha `manage_options`.
- **Tassonomie dei loop di termini:** solo quelle pubbliche, più quelle che un amministratore ammette in Impostazioni → Histeon (`xb_loop_taxonomies`); mai quelle interne di WordPress. Anche qui allargare l'elenco con una release vuole `manage_options`.
- **"Prova":** esegue la query con le stesse regole e restituisce i primi 10 titoli.

## 4. Builder
Il costruttore di query (sezione **Query** del builder) ha tre colonne:
- **le query** del sito, ognuna con una riga che dice cosa restituisce;
- **la query**, in vista **Visuale** o **Codice**, che modificano gli stessi argomenti:
  - la vista visuale copre tipo di contenuto, filtri per categorie, tag e tassonomie (`tax_query` con id dei termini, condizione "è uno di / non è / ha tutti", combinazione "tutti / almeno uno"), autori, testo, ordine (anche per campo personalizzato), quantità, salto, contenuti precisi e da escludere, e per i termini la tassonomia, i vuoti e il primo livello;
  - un valore che il loop può cambiare (`"$limit ?? 10"`, `"$orderby ?? \"date\""`) resta legato al suo parametro: la vista visuale ne modifica il valore predefinito;
  - gli argomenti che la vista visuale non conosce non vengono toccati e sono elencati, da modificare nella vista Codice;
- **i dati**: mentre si modifica, la query viene eseguita come la vedrebbe chi visita il sito (pubblicati e leggibili, gli stessi limiti dei loop). I primi 20 record compaiono come schede; uno alla volta ("Record 1 di N") si vedono le chiavi con il loro valore e l'espressione pronta da copiare o da inserire (`{item.title}`, `{item.image.url}`, `{item.author.name}`…, anche i campi aggiunti dai plugin). I parametri di prova vuoti non vengono passati, così valgono i predefiniti.

Per usarla nella pagina:
- **Avvolgi in un loop:** l'elemento selezionato (una scheda, una riga) diventa il modello ripetuto per ogni risultato.
- **Inserisci un loop nuovo:** un loop già pronto.
- **In gruppi**, solo per le query di contenuti e per `main`: **Per anno di pubblicazione** (`{field: "date", how: "Y"}`), **Per mese di pubblicazione** (`"F Y"`), **Per iniziale del titolo** (`{field: "title", how: "letter"}`). È il `source.groupBy` del loop a gruppi, lo stesso che scrive il pannello del loop in Gutenberg (le scelte stanno in `editor/src/lib/loopGroups.ts`, condivise): nessun formato in più. Con un raggruppamento, **Avvolgi in un loop** (`wrapInGroups`) fa un loop a gruppi `as gruppo` con un `<h2>{gruppo.key}</h2>` e, dentro, un loop su `gruppo.items` con l'elemento selezionato; **Inserisci un loop nuovo** (`loopSnippet(nome, preset, groupBy)`) scrive `{#loop nome(…).groupBy("date", "Y") as gruppo}<section><h2>{gruppo.key}</h2>{#loop gruppo.items as item}…{/loop}</section>{/loop}`. L'anteprima chiede gli stessi dati con `groupBy` e mostra il numero dei gruppi, la chiave di ciascuno e quanti contenuti ha, contati su tutto il risultato della query (non solo sui primi 20 record mostrati), al massimo 100 gruppi.

`POST /queries/test` accetta un `groupBy` facoltativo `{field, how?}` con le regole del DSL (`Spec::GROUP_BY_FIELD_PATTERN`, `Spec::GROUP_BY_HOW_PATTERN`; altro → 400 `xb_invalid_group_by`); la risposta aggiunge `groups: [{key, count}]`, fatti dal modificatore `groupBy` come nel Renderer (`Queries::test`). Test: `QueriesTest::test_preview_of_a_grouped_loop_counts_its_groups`, `editor/test/queries.test.ts`, `editor/test/loopGroups.test.ts`, `e2e/specs/query-groups.spec.ts`.

Le scelte della vista visuale vengono da `GET /queries/options?type=`: tassonomie pubbliche con i loro termini (al massimo 200) e autori con contenuti pubblicati di quel tipo, solo nome e id. Servono `xb_build` e un tipo ammesso nei loop.

## In Gutenberg, a parole

Selezionando un loop, il pannello **Histeon · Ripeti** dice che cosa mostra con una frase («6 Eventi, per data, saltando i primi 2 — query «eventi»», dal `summary()` del costruttore di query) e permette di scegliere la query, **Quanti** (l'argomento `limit` del loop) e **Salta i primi** (`offset`). Un loop su un'espressione si cambia nel builder. Selezionando un ramo di una condizione, **Histeon · Condizione** la dice a parole («Si mostra se page.type è uguale a "evento"») e la compone come campo, condizione (è uguale a, è diverso da, c'è, non c'è, maggiore, minore) e valore; sotto, il testo esatto del codice, modificabile e controllato dal parser delle espressioni prima di essere scritto. Tutti e due scrivono gli stessi attributi che il codice mostra (`source`, `test`). Il cliente vede, senza modificare.

Implementazione: `editor/src/gutenberg/logic.ts`. Test: `editor/test/gutenberg-appearance.test.ts`, `e2e/specs/gutenberg-logic.spec.ts`.

## Galleria di query pronte

`GALLERY` in `editor/src/lib/queries.ts`: quattro preset (`ultimiArticoli`, `articoliCorrelati`, `prodottiInEvidenza`, `eventiFuturi`) aggiunti dal pannello con un nome libero (`galleryName`). I correlati leggono `$categoria` e `$escludi`, che `loopSnippet` riempie con `this.categories[0].id` e `this.id`; gli eventi confrontano il campo `data` con `$today`, riempito dal server. I prodotti compaiono solo se `product` è fra i tipi ammessi nei loop. `QueryGalleryTest` controlla che `Queries::validate` li accetti come sono scritti.

## Tassonomie con molti termini
`GET queries/options` elenca al più 200 termini per tassonomia, con `more: true` quando ce ne sono di più. Allora il filtro per termine del costruttore ha anche **Cerca fra tutti i termini**: `GET queries/terms?taxonomy=…&search=…` (`xb_build`, tassonomie pubbliche con interfaccia, 50 risultati) e, per dare un nome ai termini già scelti fuori dai 200, `include=id,…`. Prova: `e2e/specs/robustness.spec.ts`, 5000 categorie.

