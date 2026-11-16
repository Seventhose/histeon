# Prestazioni del builder

Misure su un sito di prova, una homepage con slider e componenti, Chromium headless, media di 3 aperture. Script di misura: aprire il builder, attendere il primo elemento nella tela, poi una modifica tramite `window.xb.setHtml` fino alla tela.

| Misura | Prima | Dopo |
|---|---|---|
| Apertura → tela disegnata | 1,08–1,26 s | **0,50–0,55 s** |
| Modifica → tela aggiornata | ~360 ms | **~265 ms** |
| Selezione di un elemento | ~30 ms | ~30 ms |

## Cosa è cambiato
- **Nessuna attesa all'apertura.** Il primo disegno aspettava la stessa pausa di 250 ms pensata per chi scrive. Ora il primo disegno parte subito.
- **La tela parte con il documento.** Il disegno della tela parte appena arriva il documento. Stili, componenti, framework ed estensioni servono ai pannelli e arrivano intanto. Prima il disegno aspettava tutti e cinque.
- **Nessun disegno doppio.** Il disegno ricorda da quale contenuto è fatto, e il controllo del contenuto non lo ripete. Cambi di componenti o di framework lo rifanno comunque. Se un disegno fallisce, il successivo riprova.
- **Pausa di scrittura più breve:** 150 ms invece di 250. Un disegno ancora in corso viene annullato dal successivo.
- **Le prime risposte arrivano con la pagina.** La pagina del builder porta in `window.xbBuilder.boot` documento, stili e componenti, letti dalle stesse rotte REST e con gli stessi permessi (`BuilderScreen::boot`). Un piccolo script con il nonce chiede il primo `/render` prima che il bundle venga scaricato ed eseguito. L'`Api` usa ciascuna risposta una volta sola, e il disegno solo se è di quello stesso contenuto. Le liste del framework restano una richiesta, perché con l'`ETag` tornano 304. All'apertura le richieste REST passano da 7 a 4; in locale, su 16 aperture, la mediana fino alla tela disegnata scende da 281 a 244 ms. Su un hosting, dove ogni richiesta avvia WordPress da capo, il guadagno è maggiore.

Test di regressione: `e2e/specs/load.spec.ts`, cioè un solo disegno all'apertura, chiesto dalla pagina prima che parta il bundle, e nessuna richiesta per ciò che la pagina porta già.

## Pagine molto grandi

Misure su una pagina di prova con 1000 sezioni, cioè circa 15.000 blocchi e 6000 righe aperte nella struttura. Chromium headless, tempi presi dentro la pagina fino al fotogramma dopo l'aggiornamento (`e2e/specs/big-page.spec.ts`).

| Misura | Prima | Dopo |
|---|---|---|
| Apertura → ultima sezione nella tela | 2,25 s | **0,84–0,86 s** |
| Selezione dalla struttura | 360 ms | **36–46 ms** |
| Selezione nella tela | 357 ms | **25 ms** |
| Passaggio del mouse su una riga | ~42 ms | **un fotogramma** |
| Testo scritto nella tela → nuovo disegno | 660 ms | **~400 ms**, di cui 150 di pausa voluta e ~90 del server |
| Memoria JavaScript | 342 MB | **~50 MB** |

Cosa è cambiato:
- **La struttura disegna solo le righe in vista.** Le righe hanno altezza fissa e la struttura è una lista piatta delle righe aperte (`structureRows.ts`). Oltre 300 righe si disegnano solo quelle visibili, più un margine. Restano disegnate anche la riga trascinata e quella con il focus, così il trascinamento finisce e la tastiera non perde il posto. Su una pagina normale si disegnano tutte, come prima. La tastiera segue lo schema WAI-ARIA anche sulle righe non disegnate: scorre fino alla riga e la mette a fuoco. Per le tecnologie assistive ogni riga dichiara livello e posizione (`aria-level`, `aria-posinset`, `aria-setsize`).
- **Selezione e passaggio del mouse toccano due righe, non tutte.** Ogni riga legge un proprio segnale (`keyedFlags.svelte.ts`) invece di confrontare il proprio uid con la selezione. I segnali li crea la struttura prima di disegnare le righe: un segnale creato dentro il calcolo che lo legge non verrebbe seguito da quel calcolo.
- **Indice dei blocchi.** Blocchi e genitori sono indicizzati per uid una volta per albero (`indexTree`). `app.find()` e `app.path()` non percorrono più la pagina a ogni selezione.
- **Il documento è immutabile.** L'albero è stato non profondo (`$state.raw`) e congelato. Ogni modifica fa un nuovo albero che condivide con il precedente le parti non toccate (`updateBlock` copia solo il percorso fino all'elemento). La storia di Annulla conserva questi alberi senza copiare la pagina a ogni passo. Una modifica sul posto per errore lancia un'eccezione invece di corrompere la storia.
- **Il pannello HTML copia solo l'elemento che mostra**, non la pagina intera, a ogni selezione.

## Stili mentre si scrive
- **Stili e fogli globali congelati e condivisi** come l'albero: una modifica fa un nuovo elenco che riusa le voci non toccate. Un punto di Annulla tiene gli elenchi come sono, senza copiarli; «non salvato» confronta il JSON di ogni voce, calcolato una volta (`stylesJson`).
- **Il CSS dal vivo compila solo le voci cambiate** (`compiled`, per selettore e testo).
- Misura (vitest, 400 selettori con annidamenti, `@media` e stati; una modifica = `upsertStyle` + `liveCss` + `dirty`, mediana di 30): da ~6,7 ms a ~0,9 ms.

## Peso degli script
- **Bundle del builder minificato per intero** (`rolldownOptions.output.minify`): la modalità *lib* in formato `es` lasciava gli spazi. `builder.js` 1.195 kB grezzi, 388 kB gzip.
- **Catalogo inglese in un file a sé** (`strings-en_US.js`), in cache come il bundle, invece che dentro la pagina del builder che non va in cache.
- **Inspector di Gutenberg leggero:** `inspector.js` (le schede Histeon nell'editor a blocchi) è 65 kB, 22 kB gzip, invece di 627 kB. CodeMirror è in `inspector-code.js`, un modulo ES caricato con `import()` la prima volta che serve un editor di codice (`gutenberg/lazyCode.ts`), accanto all'inspector e con la stessa `?ver`. L'editor a blocchi non ha la CSP del builder, quindi `import()` non ha bisogno di regole nuove. Un articolo senza blocchi Histeon non lo scarica mai. Test: `e2e/specs/gutenberg-inspector.spec.ts`.

## Editor a blocchi
- **Una richiesta per pagina.** Ciò che solo il server sa disegnare (loop, componenti, HTML grezzo, condizioni) si chiede a `/editor-render` a lotti: le richieste di 120 ms si uniscono in una, fino a 300 pezzi. Ogni risposta resta in cache per impronta del markup. Test: `e2e/specs/gutenberg-render-version.spec.ts`, dodici componenti in una richiesta.
- **Cache che segue il sito.** Ogni risposta porta `version` (`ComponentRegistry::render_version()`): numero e ultima modifica dei componenti pubblicati, più la release corrente, in una query sull'indice dei post. Tornando sulla scheda (al più ogni 5 s) l'editor manda una richiesta vuota; se la versione è cambiata, ridisegna ciò che ha in cache. Un componente cambiato in un'altra scheda si vede senza ricaricare.

## Budget di avvio
`e2e/specs/startup-budget.spec.ts` apre tre volte il builder e tre volte l'editor a blocchi con una pagina Histeon, e prende la mediana. Fallisce oltre le soglie:

| Misura | Misurato (in locale) | Soglia |
|---|---|---|
| Builder: apertura → tela disegnata | 280–370 ms | 1500 ms |
| Editor a blocchi con Histeon → primo blocco Histeon | 1,1–1,4 s | 4000 ms |
| Script del plugin caricati dal builder all'avvio | 1.121.794 byte | 1.250.000 byte |

Le soglie dei tempi lasciano spazio a una macchina carica e si possono cambiare con `XB_BUDGET_BUILDER_MS` e `XB_BUDGET_GUTENBERG_MS`; quella dei byte non dipende dalla macchina e scatta già con un 11% in più.

## Database
Misure su un sito di prova grande, in un ambiente a parte: 3000 pagine e articoli con 8 sezioni e un componente ciascuno, 150 componenti, 12.080 commit (58 MB di payload, 106 MB di tabella con gli indici), 205.000 metadati di cui 3000 identità di Publish. Script di misura con `SAVEQUERIES` ed `EXPLAIN` sulle operazioni di tutti i giorni.

| Operazione | Prima | Dopo |
|---|---|---|
| Modifiche da pubblicare (`/changes`, Panoramica, Release) | 1544 ms, 15.102 query | **163 ms, 4 query** |
| Documento nel builder | 9 ms, 8 query | invariato |
| Salvataggio in sviluppo | 8 ms, 22 query | invariato |
| Elenco delle release | 1 ms | invariato |
| Resa a freddo di una pagina con 8 componenti | 2 ms, 2 query | invariato |
| Una identità di Publish (meta `_xb_page_profile_id`) | 0,9 ms; 200 insieme 32 ms | invariato |

- **Una query per le modifiche.** `changes()` leggeva per ogni documento le due teste e i due commit interi: 4–5 query a documento. Ora `Store::heads()` unisce teste e impronte dei commit in una query, senza leggere i payload; i payload si confrontano solo se le impronte coincidono (l'impronta non conta l'host degli URL). I titoli delle pagine arrivano con `_prime_post_caches`. Schema 4: indici `branch_doc` e `commit_id` su `xb_refs`, `commit_id` su `xb_release_items`.
- **Nessun documento in memoria per le modifiche.** Dove le impronte coincidono, i payload li confronta il database, byte per byte (`CAST(… AS BINARY)`: la collation della colonna chiamerebbe uguali «é» ed «e»), nella stessa query di `Store::heads()` (`same_bytes`). Solo due commit con la stessa impronta e byte diversi (per esempio la parte d'indirizzo di un URL scritta con le barre protette) si leggono ancora e si confrontano per ciò che contengono, come prima. Su SQLite i due payload di ogni coppia si leggono uno alla volta come testo, senza decodifica né cache (`Store::same_bytes()`). Il risultato non cambia, compreso il caso della modifica annullata online (export verso Etch e ripristino: due commit, lo stesso documento, niente da pubblicare). Misura nello stack di sviluppo (`ChangesMemoryTest`, 150 documenti da 78 KB con le due teste uguali): il picco di memoria di `changes()` scende da +51,3 MB a +2,1 MB. Prima ogni coppia passava da `Store::get()`, che decodifica il JSON e ne tiene fino a 64 nella cache di lettura (su un sito grande WP-CLI poteva fermarsi lì, a 128 MB).
- **Identità in una tabella: non ora.** Con 3000 identità tra 205.000 metadati una ricerca costa meno di 1 ms grazie all'indice su `meta_key`. Cresce con il numero di identità, non con il resto: da ripensare oltre le 20.000.
- **Pulizia.** La Panoramica mostra quante versioni ci sono, lo spazio (da `information_schema`, senza leggere i payload) e la più vecchia. Un amministratore può togliere quelle più vecchie di 6, 12 o 24 mesi (`Store::prune`): restano le teste di ogni documento, la loro base (serve all'unione a tre vie) e i commit di ogni release. Nessun codice segue la catena dei genitori, quindi un commit tenuto resta integro. I resoconti degli invii da Publish (`xb_remote_operation_*`, uno arrivava a 333 KB) si cancellano da soli 30 giorni dopo la fine, con la pulizia quotidiana; aspettano la migrazione dei marcatori che li legge ancora.
- **Caricamento automatico.** Sui siti di prova misurati, le opzioni Histeon grandi sono già fuori dal caricamento automatico (`xb_css_recipes`, i resoconti di Publish, le basi relazionali). Resta `xb_styles` (66 KB su un sito), che serve a ogni pagina pubblica: con `auto` WP lo toglie da solo oltre i 150 KB. Nessuna modifica.
- **Cache degli oggetti: non ora.** La resa di una pagina costa 2 ms e 2 query; la pagina pubblica intera 0,3 s, quasi tutti di avvio di WP e del tema. Una cache della resa non cambierebbe ciò che si vede: lo fa meglio la cache delle pagine (Static Bridge).

## Valutato e non fatto (per ora)
**Bundle suddiviso, in parte:** le sezioni sopra la tela (contenuti, template, selettori, query, filtri, tipi e campi, libreria, versioni, file unico, design system, commenti, testi alternativi, props) sono moduli ES caricati la prima volta che si aprono (`SECTION_MODULES` in `App.svelte`). All'avvio si caricano 1.050 kB invece di 1.271 kB (354 kB gzip invece di 411), in 11 file; 214 kB arrivano solo aprendo le sezioni. CodeMirror resta all'avvio: serve subito, perché i pannelli in basso sono aperti.
- **La CSP non cambia:** i moduli importati da `builder.js`, con `import` statici e `import()`, ricevono il nonce dello script che li importa (HTML, «descendant script fetch options»), quindi `script-src` resta solo il nonce, senza `'strict-dynamic'`. Test: `e2e/specs/lazy-sections.spec.ts` (nessuna violazione CSP, moduli scaricati all'apertura).

**Aggiornare la tela a pezzi** invece di sostituirne il corpo a ogni disegno. Sulla pagina di prova il disegno arriva dal server in circa 90 ms e si applica in circa 85 ms: non è più il collo di bottiglia, e un aggiornamento parziale dovrebbe gestire stili, script e componenti che dipendono dal resto della pagina.
