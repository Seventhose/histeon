# Integrazione con Static Bridge

Static Bridge (SB) è un progetto separato, con un proprio repository, una propria QA e propri rilasci. Histeon non ne modifica il codice: questo documento definisce il **contratto** tra i due e le modifiche richieste a SB, da fare nel suo repository.

La versione inclusa in Histeon è quella fissata in `modules.json` (`docs/spec/modules.md`); le note di versione dicono quale. Le sezioni qui sotto dicono in quale versione è arrivata ciascuna parte del contratto.

## Che cosa funziona già senza modifiche
- **Pubblicare una release Histeon aggiorna `post_content` tramite `wp_update_post()`.** Scatta quindi `wp_after_insert_post`, che fa partire l'export incrementale di SB per le pagine pubblicate e per gli archivi che ne dipendono.
- **I componenti Histeon sono post `wp_block`.** SB tratta `wp_block` come contenuto condiviso, quindi la modifica di un componente rigenera tutte le rotte.
- **Le anteprime Histeon (`?xb_preview=`) hanno una query string.** SB le instrada sempre a WordPress e non finiscono mai nella copia statica.

## Cosa Histeon offre a SB
| Hook | Quando | Argomenti |
|---|---|---|
| `do_action( 'xb_release_published', int $release_id, list<string> $docs )` | Dopo una pubblicazione o un ripristino riusciti, a transazione chiusa | `$docs`: `post:{id}`, `styles` o `settings` |
| `apply_filters( 'xb_static_paths', array $paths )` | Raccolta degli URL dell'export Histeon | Mappa percorso → `true` |
| Classe `XB\StaticSite\ExportMode` | Richieste di export di Histeon | — |
| `do_action( 'static_bridge_queue_paths', list<string> $urls, 'histos-dates' )` | Una volta al giorno, poco dopo mezzanotte (ora del sito), con l'evento `xb_static_dates_daily`, programmato solo se Static Bridge ascolta quell'azione | Gli indirizzi delle pagine pubblicate (al più 500) che mostrano un loop su un preset di query con `"$today"` o `"$now"`, anche dentro un componente: senza, «Prossimi eventi» resterebbe fermo al giorno dell'export |

## Modifiche a Static Bridge: fatte nella 2.0.23
Sono nel repository di Static Bridge (`Seventhose/histeon-static-bridge`, versione 2.0.23):
1. **Rigenerazione totale su `styles` e `settings`:** Static Bridge ascolta `xb_release_published`.
2. **Rotte precise per i componenti:** filtro `static_bridge_dependents_for_post`. Histeon risponde con `XB\StaticSite\StaticBridge::dependents`:
   - restituisce le pagine pubblicate e visibili che usano il componente, anche attraverso altri componenti;
   - non risponde (e quindi si rigenera tutto) se il componente compare in un template, una parte di template o una navigazione, se un template stampa il contenuto dei post dentro un elenco, oppure se le pagine sono più di 500.
3. **Loop Histeon come pagine di query:** Static Bridge riconosce `xb/loop`. Il filtro `static_bridge_post_has_query_loop` trova anche i loop dentro i componenti, le radici `data` e `facets`, i loop di Etch (`etch/loop`, siti importati da Etch) e il Query Loop del core (`core/query`, non le sue parti come `query-title`), anche dentro blocchi riutilizzabili e componenti Etch.
4. **Action pubbliche:** `static_bridge_queue_paths` (rigenerazione mirata) e `static_bridge_release_published`.

Prova su un sito di prova (Apache, 71 pagine statiche):
- un componente modificato ha rigenerato solo le sue 3 pagine;
- una release di impostazioni ha rigenerato tutto;
- un articolo modificato ha rigenerato anche le 16 pagine con loop Histeon.

Test lato Histeon: `plugin/tests/Integration/StaticBridgeTest.php`.

## Static Bridge come motore statico di Histeon (2.0.25)
Static Bridge espone il filtro di sola lettura `static_bridge_status`. Histeon lo usa in due punti:
- **Histeon → Release, Sito statico:** una scheda mostra:
  - se la copia statica è servita;
  - quante pagine ha e quando è stata generata;
  - se si aggiorna da sola;
  - le pagine in coda e l'ultima attività;
  - il link a Static Bridge.

  Senza Static Bridge la scheda spiega a cosa serve.
- **Builder:** dopo una pubblicazione, se Static Bridge serve la copia e si aggiorna da solo, il messaggio dice che la copia statica delle pagine coinvolte si aggiorna a breve (`config.staticBridge`).

`XB\StaticSite\StaticBridge::status()` non si fida della risposta: ricontrolla tipi, testo senza markup, stato in un elenco chiuso e link solo verso la propria amministrazione. Senza Static Bridge restituisce `null`. Provato su un sito di prova.

## Due pubblicazioni statiche, due scopi
- **SB:** mirror sullo **stesso server**. Solo HTML, gli asset restano serviti da WordPress; ottimo quando il sito resta sul suo hosting.
- **Histeon → Cloudflare:** bundle **autosufficiente** (HTML + asset) pubblicato su Workers Static Assets, con versioni e rollback istantaneo; adatto quando il sito pubblico vive su Cloudflare.

Possono convivere: entrambi reagiscono alla stessa release Histeon.
