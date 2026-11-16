# REST API del builder — `xb/v1`

Implementazione: `plugin/src/Rest/Controller.php`. Test: `plugin/tests/Integration/RestTest.php`.

## Principi
- **Ogni rotta richiede `xb_build`.** Le rotte su un documento richiedono anche `edit_post` su quel documento: `xb_build` da solo non dà accesso alle pagine altrui.
- Autenticazione: cookie di WordPress e nonce `wp_rest` (header `X-WP-Nonce`), come il resto dell'admin.
- Tutte le risposte hanno `Cache-Control: no-store, private`.
- Le scritture passano da `wp_update_post()`, quindi il `ContentSanitizer` si applica come per qualsiasi altro salvataggio.

## Rotte
| Metodo | Rotta | Corpo | Risposta |
|---|---|---|---|
| GET | `/site-scripts` | — | `{scripts, canWrite, types, head, base}`: script del sito in sviluppo (`xb_build`) |
| POST | `/site-scripts` | `{scripts, head, base}` | Salva gli script del sito in sviluppo (documento `settings`; `xb_build` + `unfiltered_html`) |
| GET | `/queries` | — | `{presets, types, head, base}`: query in sviluppo e tipi ammessi nei loop (`xb_build`) |
| POST | `/queries` | `{presets, head, base}` | Salva le query in sviluppo (documento `settings`) |
| POST | `/queries/test` | `{id, preset, params, groupBy?}` | `{items: [{title, url, fields}], groups?: [{key, count}]}`: primi 20 risultati per chi visita; con `groupBy` `{field, how?}` (loop a gruppi) anche i gruppi con quanti contenuti hanno |
| GET | `/documents/{id}` | — | `{id, type, title, status, content, modified, link}`. `content` è grammatica a blocchi, che il client legge con `parseXb`. |
| POST | `/documents/{id}` | `{content, modified}` | Il documento salvato. **409** se `modified` non coincide con quello attuale (qualcuno ha salvato nel frattempo). Tipi ammessi: `page`, `post`, `wp_block` (componenti). |

Modalità cliente ([client-mode.md](client-mode.md)): `GET /documents/{id}` risponde anche con `pageStyles` (l'aspetto della pagina in sviluppo) e `access: {level, groups}` (che cosa può fare chi chiede); `POST /documents/{id}` accetta `styles`, l'aspetto della pagina, controllato con la grammatica chiusa di `PageLook` (`400`) e, per un cliente, con il suo livello e i suoi gruppi (`403`). Un cliente legge un documento con almeno il livello «Solo vedere» e lo salva con almeno «Testi e immagini».
| POST | `/render` | `{content, post_id}` | `{html, css, globalCss, stylesheets}` per il canvas. Ogni elemento ha `data-xb-uid`; dentro un componente gli elementi riportano l'uid **dell'istanza**, mentre il contenuto degli slot tiene i propri. Per chi non ha `unfiltered_html`, il contenuto passa dallo stesso filtro di un salvataggio prima del render. |
| GET | `/styles` | — | `{styles: [{id, selector, css}], globals: [{id, css}]}` |
| POST | `/styles` | `{styles, globals?}` | Sostituisce gli stili. Vincoli: id `^[a-z0-9_-]{1,64}$`; selettore non vuoto, al massimo 500 caratteri, senza `{ } < ;`; totale entro 500 KB. In caso di violazione risponde **400** (o **413** per la dimensione). |
| GET | `/selectors` | — | `{selectors: [{id, selector, rename, bytes, docs: [{doc, post, label}], locked, theme, always, safelisted, unused}], scanned, truncated}`. Gestore dei selettori: `styles.md` §4. Si leggono le teste dev; `docs` elenca solo i documenti con `edit_post`, gli altri sono contati in `locked`. |
| POST | `/selectors/rename` | `{from, to, dryRun?}` | Rinomina una classe e i suoi figli BEM in stili e documenti, come commit dev. Risponde con l'anteprima `{from, to, styles, docs, manual, locked, theme, blocked, conflicts, ok}`; senza `dryRun` scrive, oppure **409** se `ok` è falso, **404** se nessuno usa la classe, **400** per un nome non valido (`^-?[_a-zA-Z][_a-zA-Z0-9-]{0,99}$`). |
| POST | `/selectors/delete` | `{ids}` | Elimina voci inutilizzate in un commit dev degli stili: `{deleted: [selector]}`. **409** se una è in uso (nessuna viene eliminata), **404** se non esiste. |
| GET | `/components` | — | `[{id, ref, title, props}]` per l'autocompletamento |
| POST | `/import/scelte` | `{choices: [{id, kind, value, context}]}` | «Rifinisci i nomi con un modello» di «Importa HTML» (`plugin/src/Import/ChoicesRest.php`). Solo `manage_options` + `xb_build`. Il server chiede il modello del sito a lotti di 20 (al più 10, nessun nuovo tentativo) e controlla le risposte: `{provider, model, ms, apply, suggestions, rejected, batches, skipped}`. `apply` sono i nomi da proporre, `suggestions` i testi alternativi (mai applicati da soli). **413** oltre 64 kB, **400** per scelte non valide o più di 200, **409** senza chiave, o con Claude dal connettore (lo chiede la pagina). La chiave non esce mai dal server. |
| POST | `/import/scelte/risposta` | `{choices: [{id, kind, value, context}], scelte: "<testo JSON>"}` | «Claude dal connettore»: la pagina «Importa HTML» ha aspettato la risposta dell'agente attraverso il connettore e la manda qui, 20 scelte alla volta, per il controllo con le regole condivise (`ChoicesRest::check`). Stesso permesso della rotta sopra. Risponde `{apply, suggestions, rejected}`. **413** oltre 64 kB, **400** per scelte non valide, una risposta che non è `{"scelte":[…]}` o più di 200 risposte. Il server non chiama nessuno. |

## Canvas
L'anteprima è un `iframe` con `sandbox="allow-same-origin"`: il builder legge il DOM per la selezione, ma **nessuno script della pagina viene eseguito** nell'origine dell'admin.
