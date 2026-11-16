# Fondamenta, il tema padre — v0.2

Implementazione: `theme/fondamenta/` (blocchi in `inc/blocchi.php`, `blocks/`, `assets/editor.js`), `theme/xb-blank/` (tema di compatibilità), `plugin/src/Templates/ThemeCarry.php`. Test: `plugin/tests/Integration/ThemeTest.php`, `plugin/tests/Integration/ThemeCarryTest.php`, `plugin/tests/Integration/ConvertCoreTest.php`, `e2e/specs/export-core.spec.ts`.

Fondamenta è il tema a blocchi che prende il posto di XB Blank. È la base dell'uscita verso Gutenberg (`export-core.md`): un tema padre uguale per tutti i siti, con i blocchi propri (§5), i comportamenti e la registrazione dei tipi da una dichiarazione, perché un sito convertito si possa mostrare anche con Histeon spento. Ogni sito convertito ha poi il suo tema figlio, `<sito>-tema`, generato dall'uscita (`export-core.md` §13).

## 1. Due modi

Il modo dipende da `XB_VERSION`, la costante che Histeon definisce al caricamento. I plugin si caricano prima del tema, quindi `functions.php` lo sa subito.

| Modo | Quando | Cosa fa |
|---|---|---|
| **Con Histeon** | `XB_VERSION` definita | Quello che faceva XB Blank, più i suoi blocchi (§5): niente pattern di WordPress né pattern remoti, `theme.json` senza preset e senza controlli visivi, traduzioni del tema. Non registra tipi, query, script o stili: disegna Histeon. I blocchi ci sono anche qui perché una pagina convertita si possa provare e modificare prima di spegnere Histeon |
| **Fermo** | Histeon spento o non installato | I suoi blocchi (§5); i tipi di contenuto e le tassonomie che il sito dichiara (opzione `xb_content`, che Histeon scrive e lascia), con gli stessi argomenti e quindi gli stessi indirizzi (`inc/tipi.php`); le interazioni di Histeon (`behaviors.js`, `behaviors.css`, copiati dal plugin da `theme/build.sh`) sulle pagine i cui elementi hanno `data-xb-*` (`inc/comportamenti.php`). In amministrazione, a chi può attivare i plugin, un avviso che dice cosa manca (§2). I campi restano nel post meta, dove li leggono i legami: li modifica il plugin di campi scelto (`export-core.md` §15); foglio, template e parti del sito li porta il tema del sito (`export-core.md` §13) |

In entrambi i modi Fondamenta toglie le regole di base di WP (`wp-block-library`) dalle pagine fatte solo dei suoi blocchi, dei blocchi di testo del core e di quelli di Histeon, come fa Histeon con le sue (`inc/stili.php`; filtro `fondamenta_senza_stili_di_base`), e toglie se stessa dalla richiesta degli aggiornamenti dei temi a WordPress.org (`http_request_args`): non è pubblicata lì, e un tema con lo stesso nome pubblicato da altri non deve mai essere proposto come suo aggiornamento.

## 2. L'avviso del modo fermo

Il titolo dice quale plugin manca. Il nome si legge dall'intestazione del plugin installato nella cartella `xb` (`get_plugins()`), così il testo del tema non contiene il nome di Histeon (`TRADEMARKS.md`). Se la cartella non c'è, l'avviso dice che il plugin che disegna le pagine (cartella «xb») non è installato.

Poi un elenco:
- quanti contenuti (pagine, articoli, record, componenti, menu; non template e parti) sono ancora fatti con i blocchi `xb/*` e non mostrano nulla, e che per convertirli serve il plugin attivo;
- senza un tema del sito sopra Fondamenta: che template e parti di Fondamenta usano gli stessi blocchi, quindi il sito pubblico resta senza testata, piè di pagina e contenuto, e che gli stili del sito non vengono stampati;
- quali tipi di contenuto dichiarati non sono registrati (per esempio perché un altro plugin ne ha preso il nome).

Con un tema del sito sopra Fondamenta e nessun contenuto ancora da convertire, l'avviso non compare.

Con il permesso di attivare quel plugin c'è il pulsante **Vai ai plugin**. Il conteggio è una sola query `LIKE` sui contenuti, tenuta per dieci minuti nel transitorio `fondamenta_fermo`.

## 3. Template, XB Blank e il passaggio

**Template e parti.** Si scrivono nel linguaggio di Histeon in `theme/fondamenta/src/` e si compilano con `theme/build.sh` (uid deterministici). Sono quelli di XB Blank, con una differenza: `index` e `404` contengono il blocco «Contenuto del post» (`{@content}`). In un elenco e nella 404 il blocco resta vuoto, ma senza di esso un template salvato nel database resterebbe fuori dalla rilettura di Histeon Publish (`templates.md` §1a).

**XB Blank resta come tema di compatibilità**, alla 0.4.0:
- ha gli stessi file di template e parti di Fondamenta, copiati da `theme/build.sh`: un sorgente solo, due temi che si vedono uguali;
- con Histeon attivo, nelle schermate Temi e Bacheca, a chi può cambiare tema un avviso dice che Fondamenta ne prende il posto, con il pulsante **Attiva Fondamenta** se è installata, altrimenti come caricarla;
- senza Histeon mostra l'avviso di sempre.

**Perché non un tema figlio.** XB Blank non può diventare figlio di Fondamenta con un aggiornamento: WordPress legge `Template:` solo quando un tema si attiva (`switch_theme` scrive le opzioni `template` e `stylesheet`), non quando i file di un tema attivo cambiano. Un XB Blank aggiornato con `Template: fondamenta` continuerebbe a girare da solo, e se Fondamenta mancasse la schermata Temi (`validate_current_theme()`) passerebbe il sito a un tema predefinito. Il passaggio è quindi un cambio di tema, una volta, come per ogni tema.

**I template del sito seguono il cambio di tema.** WordPress lega al tema attivo, con il termine `wp_theme`, ogni template, parte e stile globale salvato nel database, e lega il CSS aggiuntivo al nome del foglio di stile. Dopo un cambio di tema restano al tema di prima e il sito perde il suo aspetto. `ThemeCarry` (agganciato a `switch_theme`) li sposta invece al tema nuovo quando entrambi i temi sono della stessa famiglia: XB Blank, Fondamenta, o un tema figlio di Fondamenta.
- Si spostano `wp_template`, `wp_template_part` e `wp_global_styles` in ogni stato tranne il cestino, e il post `custom_css`. Restano gli stessi post: stessi ID, quindi stessi documenti di Histeon e stessa storia; per Histeon CMS restano i suoi meta (template gestiti, alias, template assegnati alle pagine).
- Gli stili globali cambiano anche nome (`wp-global-styles-<tema>`); il CSS aggiuntivo diventa quello del tema nuovo (`custom_css_post_id`).
- Un post che il tema nuovo ha già con lo stesso nome resta al tema vecchio: vince quello del tema nuovo. Fa eccezione lo stile globale vuoto che l'Editor del sito crea al primo sguardo: si scambia di posto con quello del tema vecchio.
- Il Registro scrive `theme_carry` con il tema di partenza, i post spostati e quelli lasciati.
- Si torna indietro allo stesso modo: riattivare XB Blank riporta i template a XB Blank.
- Con un tema fuori dalla famiglia non succede nulla, come prima.

Su un sito esistente la prima attivazione di Fondamenta va fatta dopo una copia di sicurezza, e il risultato va confrontato con le pagine di prima.

## 4. Nomi, lingue, pacchetti

- **Nomi.** Né il nome del tema né i suoi testi contengono «Histeon»: Fondamenta resta anche dopo Histeon. I prefissi tecnici `xb/` dei blocchi e la cartella `xb` del plugin restano, perché sono i dati.
- **Lingue.** Testi in italiano; traduzione inglese da `theme/fondamenta/languages/en/fondamenta.json` con `scripts/i18n.py` (`docs/spec/i18n.md`). Ogni lingua senza un file suo prende l'inglese, come Histeon.
- **Pacchetti.** `scripts/package.sh` produce `fondamenta-<versione>-<commit>.zip` accanto a `xb-…zip` e `xb-blank-…zip`, nello stesso `SHA256SUMS` (e firmato con gli altri se c'è la chiave). Come XB Blank, il tema si installa a mano: Aspetto → Temi → Aggiungi tema → Carica tema.
- **Requisiti:** WordPress 6.9, PHP 8.3, come Histeon.

## 5. Prove

- `ThemeTest`: una pagina servita da Fondamenta con Histeon attivo (stili nella testa, testata, contenuto, piè di pagina); i file di template e parti di XB Blank uguali a quelli di Fondamenta e i quattro template con `xb/post-content`; nessun «Histeon» nei file del tema; con `wp --skip-plugins` il modo fermo dice quale plugin manca e quanti contenuti restano senza pagina.
- `ThemeCarryTest`: da XB Blank a Fondamenta e ritorno con template, parte, stili globali e un template già presente nel tema nuovo; lo stile globale vuoto che si scambia; un tema fuori dalla famiglia che non prende nulla.
- Il WordPress di sviluppo (`dev/up.sh`, `dev/sqlite.sh`) monta i due temi e attiva Fondamenta.

## 5. I blocchi (0.2)

I blocchi tengono il markup di una pagina com'era quando è stata costruita, quindi il suo CSS vale ancora, e funzionano con Histeon e senza. La conversione che li scrive è in `export-core.md` §11.

| Blocco | Cosa fa |
|---|---|
| `fondamenta/elemento` | Un elemento HTML qualunque, **statico**: tag (`tagName`), attributi in ordine (`attributi`, coppie `[nome, valore]`), e dentro altri blocchi (`modo` `blocchi`), un testo modificabile (`testo`, attributo `contenuto` letto dall'HTML salvato) o niente (`vuoto`, come `img`). L'HTML salvato è quello che il visitatore riceve: nessuna classe `wp-block-*` aggiunta. Con `legami` i valori del contenuto mostrato riempiono, a ogni visita, gli attributi e il testo indicati (§5.1). In un pattern sincronizzato, un'istanza può sostituire il testo (`contenuto`) e `href`, `src`, `alt`, `title` (override del pattern, `export-core.md` §11.6) |
| `fondamenta/loop` | Ripete i suoi blocchi per ogni voce. `fonte`: `query` (argomenti di `WP_Query` salvati come dati, `$today` e `$now` risolti a ogni visita), `principale` (la query della pagina, con `posts_per_page`, `offset`, `order`, `orderby` propri), `termini` (di una `tassonomia`, del post della voce o, con `pagina`, della pagina), `campo` (i valori di un campo con più valori), `righe` (le righe di un ripetitore, §5.5), `pagine` (i link alle pagine dell'archivio), `filtri` (un elenco filtrato, `facet` ed `elenco`: i risultati, le opzioni di un `filtro`, i filtri attivi, le pagine, i campi nascosti; §5.4). Ogni voce passa ai blocchi il suo contesto (`postId`, `postType`, `fondamenta/voce`). Senza involucro, come il loop di Histeon |
| `fondamenta/altrimenti` | Ultimo blocco di un loop: mostrato quando il loop non ha voci (`{:else}`) |
| `fondamenta/se` | Mostra i suoi blocchi quando un valore nominato c'è; o quando un confronto con un valore scritto è vero (`confronto`: `==`, `!=`, `>`, `>=`, `<`, `<=`, `contiene`; `valore`); o quando uno dei gruppi di confronti di `oppure` è vero per intero. `vuoto` rovescia la risposta |
| `fondamenta/contenuto` | Il contenuto del post mostrato (o della voce di un loop), senza involucro; vuoto in un elenco, come il blocco «Contenuto del post» di Histeon |
| `fondamenta/testo` | Un testo con legami senza un elemento attorno, fra due elementi della stessa riga (` {o.label} ` fra una casella e il suo contatore); dinamico, escapato |

**Sicurezza delle query.** `fondamenta/loop` accetta solo gli argomenti di un elenco chiuso (tipo, quantità, ordine, categorie e tag, autore, inclusi ed esclusi, `tax_query`, `meta_query`, date, ricerca) e aggiunge sempre: solo contenuti pubblicati e leggibili, senza password, al massimo 100. I tipi sono quelli visibili ai visitatori, più quelli che il sito dichiara (i tipi di contenuto in `xb_content` e quelli elencati in `xb_loop_types`); mai i tipi interni né i messaggi dei form. Un autore che scrive un loop a mano non può elencare ciò che un visitatore non vedrebbe.

### 5.1 I legami

Un legame è un elenco di parti: testo scritto, oppure un valore con un nome (`chiave`) e i suoi formati (`modi`). I nomi sono quelli del builder (`title`, `fields.prezzo`, `term.name`, `page.totalPages`, `url.path`…; l'elenco completo è in `export-core.md` §11.3); i formati sono `date:<formato>`, `words:<n>`, `slice:<inizio>[:<lunghezza>]`, `upper`, `lower`, `join:<separatore>`, `se:<testo>` (il testo quando il valore c'è). Un sì/no che è tutto il valore di un attributo mette l'attributo senza valore o lo toglie (`checked`, `selected`). `fondamenta_valore()` li legge dal contesto del blocco: il post della voce (`postId`) o il post globale, il termine, la pagina dell'archivio o il valore della voce, la pagina mostrata (`get_queried_object()`, come `props.page` di Histeon), la richiesta. Un campo si legge dal post meta, come lo salvano tutti i plugin di campi per i valori semplici; un campo immagine è l'ID di un allegato. Valori di testo escapati con `esc_html`, attributi come li stamperebbe Histeon (`esc_url` per gli indirizzi, `safecss_filter_attr` per lo stile, le entità già scritte lette una volta).

**Il confine** resta: niente linguaggio `{…}`, niente operatori dentro un valore, solo nomi fissi e formati fissi. Un test di `fondamenta/se` è un nome, un confronto con un valore scritto o `contiene`, uniti al più in «oppure» di gruppi in «e».

### 5.2 Nell'editor

`assets/editor.js` registra i blocchi senza un passo di build (`wp.element`). `save()` riscrive esattamente l'HTML salvato dalla conversione, quindi Gutenberg apre una pagina convertita senza blocchi non validi e senza nulla da salvare (`e2e/specs/export-core.spec.ts`). Nel pannello laterale di un elemento si cambiano tag e classi e si vedono gli altri attributi e i legami; un elemento di testo si scrive nella tela; loop e condizioni sono una cornice con quello che fanno scritto sopra. Varianti nell'inseritore: Sezione, Contenitore, Immagine, Collegamento, Pulsante.

### 5.3 Prova

Su un sito di prova, con Histeon attivo e Fondamenta 0.2: 225 documenti (pagine, articoli, template, parti) disegnati da Histeon e dai blocchi convertiti, 224 con lo stesso DOM a parte le classi che WordPress aggiunge ai blocchi di testo; l'ultimo differisce solo per il campo antispam di un modulo di contatto, che cambia a ogni caricamento. Dettagli in `export-core.md` §12.

### 5.4 Elenchi filtrati (0.2)

`inc/filtri.php` porta i filtri dei loop di Histeon (facet: ricerca, termini, campi, intervalli, ordine, pagine) fuori da Histeon. Le definizioni arrivano dal filtro `fondamenta_facets` come dati (la query di base come argomenti di `WP_Query`, i filtri con le chiavi meta già risolte): le scrive il tema del sito (`facets.json`). Le regole sono quelle di Histeon: parametri fuori dalla definizione ignorati e mai ripetuti nei link, termini solo esistenti, ordini solo quelli definiti, pagine limitate, testi ripuliti e accorciati, conteggi esatti fino a 2000 risultati; la query è quella dei loop (`fondamenta_query()`). I valori si leggono come legami `facet.<nome>.…` e `voce.<chiave>` (`export-core.md` §11.3, §11.7). Lo script che aggiorna i risultati senza ricaricare è quello di Histeon (`assets/facets.js`, copiato da `theme/build.sh`), solo nelle pagine che mostrano un elenco filtrato e solo a Histeon spento.

### 5.5 I campi (0.2)

`inc/campi.php` legge i campi di un contenuto come Histeon li legge, qualunque plugin li salvi (`export-core.md` §15). Le definizioni sono quelle che il sito dichiara (`xb_content`); un valore si riporta alla forma di Histeon per tipo: numeri come numeri, sì/no come sì o no, date `AAAA-MM-GG` (anche da `Ymd` di ACF), data e ora `AAAA-MM-GGThh:mm:ss` (anche da ISO 8601 con il fuso, nell'ora del sito), indirizzi solo http(s), testo ricco senza markup, vuoto come niente. Le righe di un ripetitore sono una lista, sia da un valore solo (Histeon, Meta Box) sia da una riga di meta per valore (ACF, SCF: `campo_0_sotto`); più valori semplici salvati come righe di un campo `valore` tornano una lista di valori. Un campo che il sito non dichiara si legge com'è. `fondamenta/loop` con fonte `righe` ripete i suoi blocchi per ogni riga (di un ripetitore del contenuto, della pagina con `pagina`, o della riga attorno con `dentro`); i valori della riga sono legami `riga.<campo>`.

