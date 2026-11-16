# ADR 0003 — Il builder (Fase 2)

Data: 2026-10-19 · Stato: **accettato**

| # | Decisione | Motivazione |
|---|---|---|
| B1 | Il builder è una pagina a schermo intero su `admin.php?action=xb_builder&post=ID`, fuori dall'interfaccia di wp-admin | Ha tutto lo spazio a disposizione, nessun conflitto con il CSS dell'admin, e permessi e login restano quelli di WordPress. Header `X-Frame-Options: SAMEORIGIN` e `frame-ancestors 'self'` contro il clickjacking. |
| B2 | Il canvas mostra il **render del server** (`POST /render`), non una simulazione nel browser | L'anteprima corrisponde esattamente alla pagina pubblicata: loop, componenti, permessi, filtri |
| B3 | Il canvas è un iframe **sandbox senza script** | L'anteprima non esegue mai codice nell'origine dell'admin. Il prezzo: il JS della pagina non gira in anteprima. |
| B4 | Il CSS si compila **nel browser** (`compileCss`, gemello di `CssCompiler.php`, con vettori condivisi) | Mentre si scrive nel pannello CSS l'anteprima si aggiorna a ogni tasto, senza chiamate al server |
| B5 | Il pannello HTML **non riformatta mai mentre si scrive**: ristampa solo quando l'albero cambia altrove (caricamento, annulla, pannelli) | Il cursore e la formattazione dell'autore restano dove sono |
| B6 | Gli uid sopravvivono alle modifiche grazie a `reconcileUids` (LCS per fratelli, con firma tipo+tag/ref) | Selezione stabile, sanitizer "nodi invariati", merge del versioning |
| B7 | Cronologia propria del builder: niente passi per modifiche nulle; modifiche consecutive dalla stessa sorgente (HTML, stesso selettore CSS) entro 1,5 s formano un solo passo | Trovato nel browser: senza, l'annulla sembrava non fare nulla |
| B8 | La prop dei componenti Svelte si chiama `app`, non `state` | Una variabile `state` trasforma `$state(...)` in una sottoscrizione a uno store: un bug silenzioso trovato in compilazione |
| B9 | Gli asset compilati (`plugin/assets/builder/`) non sono nel repository; si generano con `npm run build -w editor` (in CI e nelle release) | Il repository contiene solo sorgenti |

## Completamento della Fase 2
| # | Decisione | Motivazione |
|---|---|---|
| B10 | Il **JavaScript** di un documento sta nel meta protetto `_xb_script`. Lo scrive solo chi ha `unfiltered_html`: lo verificano la REST, il meta (`auth_callback`) e `Scripts::set()`. Viene stampato in pagina come `<script type="module">` e mai eseguito nell'anteprima. | È il dato più pericoloso che un autore possa salvare: niente attributi di blocco, niente filtri da aggirare, tre punti di controllo indipendenti |
| B11 | Sincronizzazione codice ↔ selezione tramite `printWithMap` (marcatori Unicode privati rimossi dopo la stampa) | La mappa vale solo finché il testo coincide con la stampa canonica; in caso contrario resta vuota, mai sbagliata |
| B12 | Componenti creati dal builder (`POST /components`) con chiave univoca e schema delle props validato lato server (tipi chiusi, default solo scalari) | Lo schema guida l'editor delle props e i default del renderer |
| B13 | La modalità sola lettura dell'editor si può cambiare dopo la creazione (Compartment) | Trovato nel browser: i permessi arrivano dopo la creazione dell'editor |

## Rimandato
- Trascinamento nella struttura (oggi: Su/Giù, Alt+↑/↓) e command palette.
- Interfaccia per i preset di loop personalizzati (oggi: opzione `xb_loop_presets`).
- `wp.media` per scegliere le immagini.
- Autocompletamento di variabili e classi del framework: è la Fase 3.
