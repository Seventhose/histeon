# Agenti AI — v0.1

Implementazione: `plugin/src/Ai/`. Test: `plugin/tests/Integration/AbilitiesTest.php`.

Histeon espone le sue funzioni come **Abilities di WordPress** (WordPress 6.9+, categoria `xb`, meta `public: true`). Un agente remoto legge prima `xb/manual`, poi `xb/brief`, come un agente locale fa con `manual` e `brief` del connettore. Manuale e documentazione vengono dal file del connettore incluso nel plugin (`connector/histos-connector.mjs`), dove `scripts/sync-manual.mjs` li incorpora: le fonti restano una. Un MCP adapter le rende disponibili ad agenti come Claude Code e Codex. L'agente si autentica come un utente WordPress, per esempio con una password per le applicazioni, e ha **esattamente i suoi permessi**.

Provato con l'adattatore ufficiale `WordPress/mcp-adapter` **0.7.1** (release GitHub, WordPress 7.1.3): `meta.public: true` basta, senza `meta.mcp`. Il server predefinito mostra in `tools/list` i suoi tre strumenti (`mcp-adapter-discover-abilities`, `-get-ability-info`, `-execute-ability`) e le 22 `xb/*` compaiono tutte in `discover-abilities` come tipo `tool` (per l'adattatore vale `meta.mcp.public` se c'è, altrimenti `meta.public`; il tipo è `tool` se manca). Permessi di ogni ability e aree `Scopes` restano in vigore anche passando dall'adattatore.

## Abilities
| Nome | Tipo | Permessi | Cosa fa |
|---|---|---|---|
| `xb/manual` | lettura | `xb_build` | Il manuale per gli agenti (`plugin/connector/HISTOS-AI.md`), lo stesso del connettore |
| `xb/docs` | lettura | `xb_build` | Un argomento della documentazione per gli agenti (`topic`), o l'indice |
| `xb/brief` | lettura | `xb_build` | Il brief del sito, come `brief` del connettore ma senza documento aperto: framework con i valori, media query con nome, query dei loop e dati, blocchi BEM, componenti. Si costruisce dalle rotte del builder, quindi con la versione di sviluppo e i permessi dell'utente |
| `xb/list-documents` | lettura | `xb_build` | Documenti modificabili dall'utente |
| `xb/get-document` | lettura | `xb_build` + `edit_post` | Albero di sviluppo con uid, più `head` |
| `xb/render-document` | lettura | `xb_build` + `edit_post` | HTML della versione di sviluppo |
| `xb/get-framework` | lettura | `xb_build` | Token `--xb-*`, variabili e classi del framework |
| `xb/list-changes` | lettura | `xb_build` | Documenti con modifiche non pubblicate |
| `xb/preview-url` | lettura | `xb_build` + `edit_post` | Link di anteprima firmato |
| `xb/apply-operations` | scrittura su **dev** | `xb_build` + `edit_post` | Operazioni strutturate (sotto) |
| `xb/set-style` | scrittura su **dev** | `xb_build` | CSS di un selettore di classe |
| `xb/list-media` | lettura | `xb_build` + `upload_files` | I file della libreria con indirizzo, testo alternativo, misure, peso e raccolte; filtri `search`, `type`, `collection` (0 = senza raccolta), `missing_alt`, `page` |
| `xb/list-collections` | lettura | `xb_build` + `upload_files` | Le raccolte dei media |
| `xb/set-media-alt` | scrittura, area **media** | `upload_files` + `edit_post` sul file | Il testo alternativo di un'immagine |
| `xb/set-media-collections` | scrittura, area **media** | `upload_files` + `edit_post` sul file | Tutte le raccolte di un file |
| `xb/search-content` | lettura | `xb_build` | Contenuti di ogni tipo che l'utente può modificare |
| `xb/get-content` | lettura | `xb_build` + `edit_post` | Un contenuto con titolo, riassunto, stato, indirizzo e campi |
| `xb/update-content` | scrittura, area **contenuti** | `xb_build` + `edit_post` | Titolo, riassunto e campi (sotto, «Contenuti») |
| `xb/set-query`, `xb/set-source` | scrittura su **dev**, area **dati** | `xb_build` | Una query dei loop o una sorgente JSON del documento `settings` (null la toglie), con i controlli del pannello Query |
| `xb/last-checks` | lettura | `xb_build` | `{ checks }`: null, o `version`, `at`, `errors`, `warnings` e `rules` (e, se c'è, `pages`: `checked` di `total`) dell'esito dei controlli con cui è uscita l'ultima release dal builder (`xb_last_checks()`, docs/spec/checks.md). Un promemoria, non una prova: il manuale chiede di leggerlo prima di chiedere la pubblicazione |
| `xb/publish-release` | lettura | `xb_build` + `xb_publish` | Elenca le modifiche da pubblicare e rimanda alla pagina Release: pubblica una persona |

## Operazioni
`set_text`, `set_attribute` (`null` rimuove, `true` = booleano), `set_tag`, `set_label`, `insert_html` (HTML semplice convertito con l'HTML API di WordPress; le espressioni `{…}` restano valide), `remove`, `move`.

- Al massimo 100 operazioni per chiamata, validate una alla volta. Basta un errore e **non viene salvato nulla**.
- Con `head` si evita di sovrascrivere modifiche altrui: se il documento è cambiato, la chiamata risponde **409**.

## Aree
Le Abilities che scrivono seguono la scelta dell'amministratore nel pannello ✦ del builder, «Cosa può fare l'agente» (`plugin/src/Ai/Scopes.php`, `docs/spec/connector.md` §4): `xb/set-style` area **stile**, `xb/apply-operations` area **testi** se fa solo `set_text` e **struttura** altrimenti, le altre come nella tabella. Un'area spenta risponde `xb_agent_scope` (403) e dice dove si accende.

## Contenuti
`plugin/src/Ai/Contents.php`: rotte `GET xb/v1/agent/contents` (ricerca), `GET` e `POST xb/v1/agent/contents/{id}`, usate dall'API del builder (`searchContent`, `getContent`, `updateContent`) e dalle Abilities.
- Si leggono e si scrivono attraverso le rotte REST di WP del tipo (`wp/v2/<tipo>/<id>`), come l'utente: stessi permessi, schema e sanitizzazione dei campi registrati dell'editor a blocchi. I campi sono i meta che il tipo mostra in REST (quelli di Histeon e di chi li registra così).
- Si cambiano solo `title`, `excerpt` e `fields`; un nome di campo che il contenuto non ha è un errore. **Lo stato non cambia mai**: una bozza resta bozza, niente si pubblica.
- Prima della prima modifica di ogni chiave, i valori di prima entrano nella sessione dell'agente (`AgentSession::record_content`); «Annulla tutto» li riscrive con la stessa rotta, e la sessione mostra «Contenuto «titolo»» fra i documenti toccati.
- Solo con l'area **contenuti** accesa: è spenta finché un amministratore non la accende, perché la modifica vale subito sul sito.

## Punto di ripristino

`Versioning::save_dev(..., agent: true)` chiama `Ai\AgentSession::record($doc, $head)` dopo il commit. Le scritture dello stesso utente con pause sotto `GAP` (30 minuti) formano una sessione, salvata nell'opzione `xb_agent_sessions` (senza caricamento automatico): per ogni documento la testa di sviluppo prima della prima scrittura, che è il punto di ripristino, senza copie. `GET /agent-sessions` (xb_build) dà le sessioni proprie, e a un amministratore quelle di tutti; `POST /agent-sessions/{user}` con `action` `keep` chiude la sessione, `undo` riporta ogni documento al suo punto con `Versioning::revert()` (stessa fiducia e stessi permessi di ogni ripristino; un documento non modificabile da chi annulla resta e viene nominato). Entrambe finiscono nel registro (`agent-keep`, `agent-undo`). Nel builder: `AgentBanner.svelte`, letto all'apertura, al ritorno sulla scheda e ogni 30 s.

## Sicurezza
- Nessuna ability esegue codice: le operazioni sono dati validati sul server. Anche il connettore locale (`docs/spec/connector.md`) non esegue script: il pattern «JavaScript arbitrario nella scheda» resta escluso per scelta.
- Le scritture vanno solo su `dev` e passano dallo stesso sanitizer del builder, con i permessi dell'utente. Per un agente che agisce come Autore il filtro toglie script, handler `on*` e URL `javascript:`.
- Un agente non pubblica. `xb/publish-release` elenca le modifiche di sviluppo e restituisce la pagina **Release**, dove una persona le controlla e pubblica: un campo `confirm` scritto dall'agente stesso non è una decisione umana, e l'agente legge contenuti scritti da altri (un testo può chiedergli di pubblicare). Ogni scrittura finisce nell'audit (`ability`).
- I contenuti letti dal sito sono dati, non istruzioni: l'agente resta responsabile delle sue decisioni.

**Fiducia.** Un agente scrive come un autore senza `unfiltered_html`, anche quando lavora per un amministratore: quello che scrive può venire da istruzioni nascoste nei contenuti che ha letto. I nodi fidati già nel documento restano; il commit dell'agente è non fidato, quindi l'anteprima blocca gli script e la release lo filtra di nuovo. `xb/publish-release` elenca solo le modifiche che l'utente può vedere, come `xb/list-changes`.
