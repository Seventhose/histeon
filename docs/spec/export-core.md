# Uscita verso Gutenberg: rapporto, conversione, tema del sito, campi, form, uscita e prova — v0.7

Implementazione: `plugin/src/Export/Core/` (`Analyzer`, `Report`, `Converter`, `Prova`, `SiteTheme`, `Command`); i blocchi di arrivo sono in `theme/fondamenta/` (`fondamenta.md` §5). Test: `plugin/tests/Integration/ExportCoreTest.php`, `plugin/tests/Integration/ConvertCoreTest.php`, `plugin/tests/Integration/SiteThemeTest.php`, `e2e/specs/export-core.spec.ts`.

Un sito Histeon deve poter lasciare Histeon: le pagine diventano blocchi di Fondamenta per la struttura e blocchi core per il testo. Tre strumenti, nessuno scrive nulla (né contenuti, né opzioni, né cache):
- **il rapporto** (§1-§10): pagina per pagina e pezzo per pezzo, cosa diventerebbe un blocco, cosa si congelerebbe in `core/html` e cosa si perderebbe, e perché. È il piano, ed è la prima cosa da leggere su un sito;
- **la conversione** (§11): i blocchi di Fondamenta e del core di ogni documento, in memoria;
- **la prova** (§12): ogni documento disegnato da Histeon e dai suoi blocchi convertiti, confrontati;
- **il tema del sito** (§13): lo ZIP di `<sito>-tema`, figlio di Fondamenta, con il foglio, i template e le parti del sito convertiti.

Insieme permettono la prova dell'uscita vera, con Histeon spento (§14).

La scrittura nei contenuti è l'uscita vera e propria (§17).

## 1. Cosa legge

- Ogni contenuto con blocchi `xb/*` negli stati pubblicato, bozza, privato, programmato e in revisione, di **qualsiasi tipo** (pagine, articoli, record, componenti `wp_block`, template, parti, menu), tranne i tipi interni di WordPress e i messaggi dei form. Al massimo 2000; prima i componenti, poi parti e template.
- La versione **online** (il `post_content`): le modifiche in sviluppo non pubblicate sono contate nel rapporto del sito.
- Il contenuto di un autore senza `unfiltered_html` ripassa dal filtro ristretto di Histeon, come nell'esportazione verso Etch: il rapporto parla di ciò che quel contenuto può davvero contenere.
- Un contenuto che l'utente non può modificare è saltato, con un avviso.

Il **tipo di documento** decide il significato di `this`:

| Tipo | `this` |
|---|---|
| `contenuto` (pagina, articolo, record) | quel contenuto: le espressioni si scrivono alla conversione |
| `template`, `parte` | il contenuto mostrato, diverso a ogni indirizzo; `page` (`props.page`) è la pagina mostrata |
| `componente` | il contenuto della pagina in cui il componente è usato; `props` cambiano a ogni uso |

## 2. Destinazioni degli elementi

Ogni elemento (`xb/element`) e ogni blocco di Histeon che non è testo riceve una destinazione.

| Destinazione | Quando | Blocco |
|---|---|---|
| `core` | `p`, `h1`…`h6` con solo testo ed elementi in linea (`strong`, `em`, `a`, `span`…), senza valori che cambiano e al massimo con `class` e `id`; `ul`/`ol` di soli `li` così | `core/paragraph`, `core/heading`, `core/list`, `core/list-item` |
| `fondamenta` | ogni altro elemento: tag, classi e attributi come Histeon li stampa, quindi il CSS BEM resta valido | `fondamenta/elemento` |
| `fondamenta-dinamico` | un elemento con valori che cambiano (legami, §3); un loop su una query, sui termini di un contenuto, sui valori di un campo o sulle pagine di un archivio (§4); il contenuto del post; un elenco filtrato (facet, §11.7) | `fondamenta/elemento` con legami, `fondamenta/loop`, `fondamenta/contenuto`, `fondamenta/testo` |
| `pattern` | un'istanza di componente che può essere un pattern sincronizzato (§5) | `core/block` |
| `gutenberg` | un blocco di Gutenberg già presente (`xb/passthrough`, o un blocco di un altro plugin lasciato fuori da un'importazione): resta com'è | il blocco stesso |
| `srotolato` | un loop su una lista scritta o su una sorgente di dati, un componente espanso: una copia per elemento, valori scritti. Gli elementi dentro hanno la loro destinazione | — |
| `congelato` | `script`, `style`, `noscript`, `template`, `iframe`, `object`, `embed`, `canvas`, `svg`, `math`, `video`, `audio` (con tutto il loro contenuto); `xb/raw`; HTML fuori dai blocchi; loop su termini, utenti o a gruppi (`groupBy`) | `core/html` com'è oggi: uguale, ma non modificabile a vista e non segue i cambi |
| `perso` | i form di Histeon (azione `/xb-form` o campo `xb_form`), da rifare nel plugin dei form scelto; un loop su un valore che cambia senza un legame o con argomenti calcolati in un template; un componente senza definizione; un blocco `xb/*` di una versione più recente | — |

Il conteggio di un elemento congelato o perso include gli elementi dentro di lui (`elements`).

Le **condizioni** (`xb/if`) non sono elementi: si contano a parte.
- `risolte`: decise alla conversione, quando la risposta è nota (in un contenuto, su `this`, `site`, `data`);
- `se`: decise a ogni visita da `fondamenta/se`, quando i test sono quelli che sa fare (§11.4);
- `perse`: le altre (su `user`, con operatori o modificatori che `fondamenta/se` non ha). Resta il ramo `{:else}`, se c'è, altrimenti nulla.

Gli elementi con interazioni (`data-xb-*`) restano elementi; il rapporto del sito li conta, perché serviranno `behaviors.js` e `behaviors.css` in Fondamenta.

## 3. Espressioni

Ogni espressione `{…}` in un testo, in un attributo o in una prop ha un destino:

| Destino | Quando |
|---|---|
| `scritta` | il valore si conosce alla conversione: in un contenuto `this`, `site`, `data`, `page`; in ogni documento `site` e `data`; le props di un componente espanso. Il valore non segue i cambi successivi (un `{site.name}` cambiato dopo resta vecchio) |
| `legata` | il valore cambia (il contenuto di un loop, la pagina di un template, un elenco filtrato) e `fondamenta_valore()` ha un nome per lui: un **legame** (§11.3), anche dentro un testo (`Prezzo: {x.fields.prezzo} €`) o in una classe (`scheda--{x.type}`); `props.x` nella definizione di un componente → override del pattern |
| `persa` | dipende da chi visita (`user`, `url.query`); proprietà senza un nome (`x.meta.y`); modificatori senza formato (`number()`, `first()`…); operatori fuori da una condizione (`??`, e `?:` salvo `x ? "testo" : null`); espressioni non valide |

Il confine resta: Fondamenta non ha un motore delle espressioni. Un legame è un nome fisso con pochi formati; ciò che non ha un nome si scrive, si congela o si perde, e il rapporto lo dice.

## 4. Loop

| Sorgente | Destinazione |
|---|---|
| query `posts`, `main` o un preset salvato di quei tipi | `fondamenta-dinamico` (`fondamenta/loop`, fonte `query` o `principale`): gli argomenti viaggiano come dati, anche `meta_query`, `$today` e `$now` |
| …con argomenti calcolati da `this`, da un loop o da `props` in un template o componente | l'argomento è ignorato, e il rapporto lo dice |
| i termini di un contenuto o della pagina (`x.terms.category`, `x.categories`, `props.page.terms.post_tag`) | `fondamenta/loop`, fonte `termini` |
| i valori di un campo con più valori (`x.fields.anni`, campo `multiple` di tipo semplice) | `fondamenta/loop`, fonte `campo` |
| i link alle pagine di un archivio (`props.page.pageLinks`) in un template o in una parte | `fondamenta/loop`, fonte `pagine` |
| un elenco filtrato: `facets.<nome>.items`, `.filters.<chiave>.options`, `.active`, `.pageLinks`, `.hidden` | `fondamenta/loop`, fonte `filtri` (§11.7) |
| query `terms`, `users`, o un loop a gruppi (`groupBy`) | `congelato` (con valori che cambiano: `perso`) |
| una lista scritta, una sorgente di dati (`data.*`), `props.items` di un componente espanso | `srotolato` |
| le righe di un ripetitore (`this.fields.orari`, `props.page.fields.orari`), e di un ripetitore nella riga (`r.turni`) | `fondamenta/loop`, fonte `righe` (§15) |
| un preset che non esiste più | `perso` |

L'indice di un loop (`index`) non ha un legame: dove è usato si perde. Il ramo `{:else}` di un loop diventa `fondamenta/altrimenti`.

## 5. Componenti

Pattern sincronizzati dove possibile. Un'istanza diventa `core/block` (lo stesso post `wp_block`, le props come override) se il componente:
- non è un blocco per chi scrive (`articolo/…`);
- non ha uno slot e l'istanza non ha contenuto negli slot;
- non usa `props` in una condizione, in un loop, in `class` o in `style`.

Un'istanza con props calcolate da valori che cambiano a ogni uso (dentro un loop o un template: `titolo={x.title}`) è sempre espansa: un override vale per tutta la pagina, non per ogni elemento. Nella definizione espansa `{props.titolo}` si legge come `{x.title}`, quindi diventa un legame come se fosse scritto lì.

Altrimenti è **espanso** (`srotolato`): il suo contenuto si legge al posto dell'istanza con le props come valori scritti, e la definizione unica si perde. Ogni definizione di componente ha anche la sua riga (`pattern.synced`, `pattern.reason`).

La conversione scrive i pattern (§11.6). Due regole in più, che il rapporto applica come la conversione: la definizione è un post `wp_block` (un componente scritto in una parte di template resta espanso), e le props sono usate solo dove un override sostituisce il valore per intero: l'intero testo di un elemento (`{props.titolo}` da solo) o l'intero `href`, `src`, `alt`, `title`; e la definizione non legge la pagina in cui è (`this`, `page`, `url`, `user`).

## 6. Il rapporto del sito

- tipi di contenuto, tassonomie, numero di campi e di ripetitori (`ContentTypes::live()`): andranno nel plugin di campi scelto;
- query salvate e quante hanno condizioni sui campi; facet; form di Histeon trovati; elementi con interazioni;
- JavaScript dei documenti e script del sito, da portare nel tema del sito;
- framework e peso del CSS del sito (fogli globali e stili per selettore), che fuori da Histeon va in ogni pagina invece che su richiesta;
- moduli attivi (Lingua Bridge, Static Bridge), da installare come plugin separati prima dell'uscita;
- tema attivo e documenti con modifiche in sviluppo non pubblicate.

In cima, una cifra: la quota di elementi che diventano **blocchi veri** (`core`, `fondamenta`, `fondamenta-dinamico`, `pattern`, `gutenberg`, `srotolato`) sul totale; congelati e persi restano fuori.

## 7. Uso

- **WP-CLI:** `wp xb export-core --user=<amministratore>` stampa una tabella per documento (quota di blocchi veri, elementi per destinazione, espressioni scritte/legate/perse), poi le righe dei pezzi congelati e persi e delle espressioni perse con il motivo (le prime 30 per documento; `--all` le mostra tutte), il rapporto del sito, la cifra in cima e l'impronta.
  - `--doc=<id>`: un solo documento (il rapporto del sito resta intero);
  - `--file=<percorso>`: il rapporto intero in JSON, ogni elemento compreso;
  - `--format=json`: il JSON sullo schermo;
  - `--convert --doc=<id>`: il documento convertito (§11), con le note su ciò che è congelato o perso;
  - `--compare [--doc=<id>] [--file=<percorso>]`: la prova (§12);
  - `--theme-zip=<percorso>`: il tema del sito (§13).
- **Permessi:** `manage_options` e `xb_build`, come l'anteprima verso Etch; `edit_post` su ogni contenuto letto.

## 8. Il file JSON

`{"format": "xb-core-export-report", "version": 1, "summary": {documents, elements, real, share, counts, expressions, loops, components, conditions}, "site": {…}, "documents": [{id, type, kind, slug, title, status, trusted, cms, share, counts, expressions, loops, components, conditions, script, items, notes, pattern?}], "issues", "fingerprint", "generated"}`.
- `items`: `{uid, path, node, dest, target, elements, reason}` per elemento (al massimo 2000 per documento); `path` sono gli ultimi quattro antenati, con la prima classe; per un elemento con legami `reason` li elenca.
- `notes`: `{uid, path, expr, fate, target, reason}` per espressione; per un'espressione legata `target` è il nome del legame.
- `fingerprint`: SHA-256 di `summary`, `site` e `documents`. Due letture dello stesso sito danno la stessa impronta; serve alla scrittura (§17).
- Niente autori, utenti, email né percorsi del server.

## 9. Prove

- `ExportCoreTest`: una pagina con sezione, titolo con `{this.title}`, paragrafo con testo in linea, immagine, elenco, SVG, `xb/raw`, form di Histeon, loop sugli articoli (titolo con link, data, campo in un testo misto, link attorno a una scheda, immagine), loop sui termini, condizione su `user`, un componente sincronizzato, uno con slot e uno con props calcolate in un loop, un blocco di Gutenberg; un template con titolo, campo, `site.name`, classe calcolata, `upper()`, termini, pagine e contenuto del post; le definizioni di componente; impronta stabile, permessi, documento senza blocchi di Histeon. Ogni prova controlla anche che nulla sia stato scritto.
- `ConvertCoreTest` e la prova e2e: §11 e §12.

## 10. Il sito di prova

L'uscita si prova su una copia di un sito di prova, rimessa com'era dopo ogni prova (§14, §17).

## 11. La conversione

`Converter` trasforma i blocchi di un documento nei blocchi di Fondamenta e del core, nella forma di `parse_blocks()`. Lavora nel contesto in cui Histeon disegna il documento (`Prova::convert()`): un contenuto con sé stesso come post globale, un template o una parte con il campione che userebbe il builder (`Templates::with_context()`), sempre **come un visitatore anonimo**.

### 11.1 Ciò che non cambia lo disegna Histeon

Ogni pezzo che non legge valori che cambiano (§11.2) è disegnato ora dal renderer di Histeon (`Renderer::render_block()`), e il suo HTML diventa l'HTML salvato del blocco:
- **l'involucro di un elemento**: Histeon disegna l'elemento con i suoi attributi fissi e senza figli (`<section class="hero">…</section>`, un `<img>` con misure e `srcset` aggiunti da Histeon); l'apertura diventa l'inizio dell'HTML salvato e i suoi attributi, in ordine, `attributi` di `fondamenta/elemento`;
- **il testo** di un paragrafo, di un titolo, di una voce di elenco, di un elemento che contiene solo testo ed elementi in linea;
- **le condizioni** decise ora, gli **elenchi srotolati** (ogni voce con la sua variabile, `Context::with()`), i **componenti espansi** (props e slot di quell'istanza, `Renderer::component_scope()`), i **pezzi congelati** in `core/html`.

Così l'HTML che il visitatore riceve è quello di Histeon per costruzione: stessi tag, attributi, testi, entità.

### 11.2 Cosa cambia

Cambiano: la variabile di un loop su una query (il post di ogni voce), di un loop su termini, pagine o valori di un campo; in un template o in una parte `this` (il post mostrato) e `page`/`props.page` (la pagina mostrata); sempre `url` (l'indirizzo richiesto); le props calcolate di un componente espanso dentro un loop o un template, che si leggono come l'espressione dell'istanza (`{props.titolo}` → `{x.title}`).

### 11.3 I legami

Un valore che cambia diventa un **legame** di `fondamenta/elemento` (attributo `legami`): per ogni attributo, e per il testo (`#testo`), un elenco di parti, testo scritto (come lo stampa Histeon) o `{"chiave": …, "modi": […]}`. Le chiavi sono i nomi del builder:

| Di cosa | Chiavi |
|---|---|
| il post di un loop o di un template | `id`, `title`, `url`, `slug`, `type`, `excerpt`, `date`, `modified` (anche `publishedAt`, `updatedAt`), `author.name`, `author.url`, `image.url`, `image.alt`, `image.width`, `image.height`, `image.<misura>`, `categories`, `tags`, `terms.<tassonomia>`, `fields.<campo>`, `fields.<immagine>.url` (e `.alt`, `.width`…) |
| la pagina mostrata | `page.title`, `page.page`, `page.totalPages`, `page.totalRecords`, `page.prevUrl`, `page.nextUrl`, e `page.<chiave>` per le chiavi del post sopra (i campi, lo slug, l'indirizzo della pagina o del termine dell'archivio) |
| un termine | `term.name`, `term.slug`, `term.url`, `term.count`, `term.description`, `term.id` |
| una pagina dell'archivio | `link.url`, `link.number`, `link.current` |
| un valore di un campo multiplo | `item` |
| un elenco filtrato (§11.7) | `facet.<nome>.total`, `.page`, `.pages`, `.perPage`, `.pageKey`, `.action`, `.resetUrl`, `.prevUrl`, `.nextUrl`, `.hasFilters`, `.values.<chiave>`, `.filters.<chiave>.label` (e `.name`, `.value`, `.min`, `.max`, `.kind`, `.key`, `.multiple`) |
| una riga di un ripetitore (§15) | `riga.<campo>`; un'immagine della riga `riga.<campo>.url` (e `.alt`, `.width`…) |
| una voce di un elenco filtrato (opzione, filtro attivo, pagina, campo nascosto) | `voce.<chiave>` (`voce.value`, `voce.label`, `voce.count`, `voce.selected`, `voce.url`, `voce.number`, `voce.current`…) |
| la richiesta | `url.path` |

I formati (`modi`) sono i modificatori che hanno un corrispettivo semplice: `date:<formato>`, `words:<n>` (`truncateWords(n)`), `slice:<inizio>[:<lunghezza>]`, `upper`, `lower`, `join:<separatore>`, e `se:<testo>` per `x ? "testo" : null` (`aria-current={p.current ? "page" : null}`: il testo quando il valore c'è, altrimenti niente attributo). Un sì/no che è tutto il valore di un attributo (`checked={o.selected}`) mette l'attributo senza valore o lo toglie, come Histeon. Un'immagine legata a `src` riceve misure, `srcset` e `sizes` come quelle che Histeon aggiunge. Un valore vuoto toglie un attributo legato a un valore solo, come Histeon toglie `src={x}` quando `x` è vuoto.

Una variabile di un loop esterno dentro un loop interno non ha un legame: `fondamenta/loop` passa ai suoi blocchi solo la voce corrente.

### 11.4 Condizioni

Una condizione che cambia diventa `fondamenta/se` se i suoi test sono:
- un valore con un legame (`x.excerpt`) o la sua negazione (`!x.image`);
- un confronto con un valore scritto (`props.page.totalPages > 1`, `this.type == "post"`);
- `.includes("testo")` di un valore (`url.path.includes("/scrittore/")`);
- più test uniti da `||` e `&&` (la voce del menu della sezione in cui si è), senza parentesi miste ad altro.

Il ramo `{:else}` diventa un secondo `fondamenta/se` con il test rovesciato (`vuoto`). Le altre condizioni si perdono: resta il ramo `{:else}`, se c'è, altrimenti nulla (§2).

### 11.5 Il resto

| Histeon | Convertito |
|---|---|
| `xb/post-content` | `fondamenta/contenuto` (il contenuto del post, senza involucro; vuoto in un elenco, come in Histeon) |
| `xb/passthrough` | il blocco che contiene; i blocchi di Histeon al suo interno sono convertiti |
| testo con valori che cambiano senza un elemento attorno, fra altri elementi (` {o.label} ` fra la casella e il contatore) | `fondamenta/testo` con i suoi legami |
| `xb/raw`, testo fisso senza un elemento attorno, i tag di §2 «congelato», un form di Histeon | `core/html` con l'HTML di Histeon (il form resta ma non invia più) |
| un form di Histeon già creato nel plugin dei form scelto (§16) | il blocco di quel plugin |
| componente | pattern sincronizzato o espanso (§5, §11.6) |
| loop su query, termini, campi, pagine, elenchi filtrati | `fondamenta/loop` con `fondamenta/altrimenti` per `{:else}` |

### 11.6 Pattern sincronizzati

Un componente che può essere un pattern (§5) si converte una volta per richiesta (`Converter::pattern()`): il post `wp_block` che lo definisce riceve i blocchi convertiti, e ogni istanza diventa `core/block` con `ref` a quel post e in `content` i valori delle props scritte nell'istanza; una prop non scritta lascia il valore predefinito, che è nei blocchi del pattern.

Nel pattern, ogni elemento che mostra una prop ha un nome (`metadata.name`, il nome della prop, `titolo · url` se più props) e il legame `core/pattern-overrides`:
- un testo che è tutta una prop: un titolo, un paragrafo o una voce di elenco del core (override su `content`), oppure `fondamenta/elemento` in modo `testo` (override su `contenuto`); un elemento in linea con una prop dentro un testo più ampio diventa un blocco a sé, così l'override lo raggiunge;
- `href`, `src`, `alt`, `title` di `fondamenta/elemento` sono attributi del blocco (legabili dal filtro `block_bindings_supported_attributes` di WP 6.9) che il blocco scrive sull'HTML salvato quando un'istanza li sostituisce (`esc_url` per gli indirizzi, `wp_kses_post` per il testo).

`Prova::convert()` di un post `wp_block` che diventa pattern restituisce il pattern convertito; `Prova::compare()` disegna i `core/block` di una pagina con i pattern già convertiti (nel database sono ancora componenti di Histeon) e gli override dell'istanza.

### 11.7 Elenchi filtrati (facet)

Il tema porta i filtri. Una pagina con `facets.<nome>` (`docs/spec/facets.md`: ricerca, termini, campi, intervalli, ordine, pagine) si converte così:
- i loop su risultati, opzioni di un filtro, filtri attivi, pagine e campi nascosti diventano `fondamenta/loop` con fonte `filtri`, `facet` (il nome), `elenco` (`risultati`, `opzioni`, `attivi`, `pagine`, `nascosti`) e `filtro` (la chiave, per le opzioni). Un risultato è un post, come in un loop su una query; le altre voci si leggono come `voce.<chiave>`;
- i conteggi, i link, i valori scelti e le etichette diventano legami `facet.<nome>.…` (§11.3); `{#if facets.x.hasFilters}` e `{#if facets.x.pages > 1}` diventano `fondamenta/se`;
- form, campi e attributi `data-xb-facet` restano come Histeon li stampa.

**Il motore** è in Fondamenta (`inc/filtri.php`): legge i parametri dell'indirizzo con le regole di Histeon (parametri fuori dalla definizione ignorati e mai ripetuti, termini solo esistenti, ordini solo quelli definiti, pagine limitate e oltre l'ultima l'ultima, testi ripuliti e accorciati, conteggi esatti fino a 2000 risultati), con la query di `fondamenta_query()` (pubblicati, leggibili, senza password, tipi ammessi). Lo script che aggiorna i risultati senza ricaricare è quello di Histeon (`assets/facets.js`, copiato da `theme/build.sh`); senza, form e link funzionano lo stesso. Il token di un'anteprima firmata non c'è: fuori da Histeon non ci sono anteprime.

**Le definizioni** sono dati: `Facets::portable()` dà, per ogni facet, la query di base come argomenti di `WP_Query` (`base`), `perPage`, `pageKey` e i filtri con la chiave meta di ogni campo già risolta (`meta`, vuota se il campo non è pubblico: il filtro non fa nulla, come in Histeon; per un ordine per campo anche `numeric`). Fondamenta le riceve dal filtro `fondamenta_facets`: il tema del sito le porta in `facets.json` (§13), la prova (§12) le passa mentre disegna.

## 12. La prova

`Prova::compare()` disegna un documento due volte nello stesso contesto (§11): da Histeon, dai suoi blocchi, e da WordPress, dai blocchi convertiti (`do_blocks()`, quindi con i blocchi di Fondamenta registrati: serve Fondamenta 0.2 o successiva attiva). Poi confronta i due HTML come DOM: una riga per elemento o testo, attributi e classi in ordine, spazi ridotti, commenti tolti.
- `uguale`: lo stesso DOM;
- `classi del core`: lo stesso DOM a parte le classi che WordPress aggiunge ai blocchi di testo (`wp-block-heading`, `wp-block-list`, `wp-block-paragraph`);
- `diverso`: con le prime righe che cambiano.

I contatori e gli identificativi casuali che un plugin di form riscrive a ogni disegno (Fluent Forms) non contano; il suo campo-trappola antispam, che cambia etichetta a ogni disegno, invece sì. I componenti (`wp_block`) non si provano da soli: si provano dove sono usati.

`wp xb export-core --compare` prova ogni documento; con `--file` scrive l'esito, le differenze e le note di ciascuno.

**La prova per immagini.** Con Histeon attivo, una copia della pagina con i blocchi convertiti riceve lo stesso CSS (Histeon legge le classi dall'HTML finale), quindi le due pagine si confrontano con `dev/visual-diff` (catture a 1366 e 390 px). Sul WordPress di sviluppo, una pagina con sezione, testo in linea, pulsante, SVG, una griglia di schede da un loop di articoli con titolo, data, riassunto e condizione, ed elenchi: 0,00% di pixel diversi a 1366 e a 390 px. Fuori da Histeon il CSS del sito lo porta il tema del sito (§13).

**In Gutenberg.** `e2e/specs/export-core.spec.ts`: una pagina convertita si apre nell'editor con ogni blocco valido (i titoli e gli elenchi senza le classi del core passano dalle `deprecated` del core) e niente da salvare. Una seconda pagina con due istanze di un componente: due `core/block` dello stesso pattern, i valori di ciascuna mostrati nella tela, ogni blocco valido.

## 13. Il tema del sito

`SiteTheme` scrive lo ZIP di un tema figlio di Fondamenta, `<sito>-tema` (dal nome del sito), fatto da questo sito e solo per lui. Nel sito non scrive nulla: lo ZIP si installa a mano (Aspetto → Temi → Carica), come ogni tema; Histeon non scrive file PHP sul server.

| File | Cosa contiene |
|---|---|
| `style.css` | nome del sito, `Template: fondamenta`, versione `1.0.<data>` |
| `functions.php` | il foglio del sito nelle pagine e nell'editor (`add_editor_style`); gli script dei documenti, come moduli dopo il contenuto, dove il documento è usato (una pagina sulla sua pagina; un template, una parte o un componente dove sono usati, anche attraverso altri componenti); gli script del sito con le loro regole (ovunque o per un tipo di contenuto, in testa o in fondo, `defer`, `async`, `module`, `integrity`) |
| `theme.json` | quello di Fondamenta, con le preimpostazioni del sistema di design di Histeon CSS nei menu dell'editor (colori, dimensioni del testo, spazi, ombre: stessi slug `xb-*`, che le classi dei contenuti nominano, e nomi senza «Histeon»), i font (le pile del testo, dei titoli e del codice del sistema di design, una voce sola «Testo e titoli» se coincidono, poi ogni famiglia locale che non ne apre una: le regole `@font-face` sono nel foglio, che va anche nell'editor), le parti e i template propri (`customTemplates`) |
| `assets/sito.css` | il foglio come Histeon lo stampa, intero: reset (se acceso), token e classi di Histeon CSS con i valori del sito, i font locali (`@font-face` verso `uploads/xb-fonts/`), i fogli globali e gli stili per selettore compilati, con le media query con nome risolte e gli indirizzi dei media scritti. Un altro framework (Automatic.css, Core Framework) è un plugin a parte e resta |
| `assets/js/documento-<id>.js` | lo script di ogni documento che ne ha uno |
| `facets.json` | gli elenchi filtrati del sito come dati (§11.7), passati a Fondamenta da `functions.php` con il filtro `fondamenta_facets`; solo se il sito ne ha |
| `templates/`, `parts/` | ogni template e parte del tema attivo (anche quelli che sono solo file di Fondamenta), gli alias `single-<tipo>` e `archive-<tipo>` di Histeon CMS, i template propri di Histeon CMS, tutti convertiti come nel §11, ciascuno disegnato con il suo campione |
| `README.md` | cosa contiene, le pagine che usano un template proprio (la conversione dei contenuti scriverà `_wp_page_template`), cosa non è stato portato |

Il tema non contiene il nome di Histeon nei suoi testi, salvo l'intestazione di licenza del codice di Histeon CSS nel foglio. Dopo la generazione il tema è del sito: si modifica nel Site Editor e nei suoi file.

**Le parti.** Il convertitore toglie da ogni `core/template-part` il tema da cui WordPress l'aveva letta (`"theme": "fondamenta"`): dopo il cambio di tema si trova la parte del tema nuovo.

I componenti che possono esserlo diventano pattern sincronizzati (§11.6) nei contenuti; il tema non ne contiene, perché vivono nel database come i componenti.

## 14. La prova dell'uscita

L'uscita fatta per davvero su una copia di un sito di prova, poi rimessa com'era: prima una copia di sicurezza (database, plugin, HTML delle pagine), poi catture «prima» con Histeon attivo (`dev/visual-diff`, 1366 e 390 px, pagina intera); poi, con uno script di prova:
1. il tema del sito generato (§13);
2. ogni documento con blocchi di Histeon convertito al suo posto (§11), template e parti compresi; i componenti che diventano pattern (§11.6) convertiti nel loro post `wp_block`, gli altri restano, non più usati;
3. il tema del sito installato e attivato (ThemeCarry porta i template del database da Fondamenta al tema del sito, già convertiti);
4. **Histeon disattivato**;
5. catture «dopo» e confronto; infine la copia rimessa com'era e il tema del sito tolto.

Ciò che a Histeon spento regge la pagina è tutto in Fondamenta e nel tema del sito: i blocchi, i legami, i tipi e le tassonomie dichiarati (`fondamenta.md` §1), le interazioni, il foglio, gli script.

## 15. I campi nel plugin scelto

Fuori da Histeon i campi del sito (`xb_content`, `content.md`) si modificano con un plugin di campi che chi gestisce il sito sceglie e installa a parte. L'uscita fa due cose, con un **adattatore** per plugin (`Export\Core\Fields\Adapter`):
- **i gruppi di campi** nel formato del plugin, come file del tema del sito (`--theme-zip=<file> --campi=<plugin>`), uno per tipo di contenuto, tassonomia o utenti;
- **i valori** nel formato del plugin. I valori semplici sono post meta per tutti e restano dove sono; cambiano le date, i sì/no e i ripetitori. `wp xb export-core --campi=<plugin> --valori --doc=<id>` mostra cosa si scriverebbe per un contenuto; la scrittura vera la fa l'uscita (§17), con `Fields\Adapters::write_post()`, direttamente nella tabella dei meta (i filtri di pulizia che Histeon registra per i suoi campi riscriverebbero le righe nel suo formato).

**Fondamenta legge i campi come Histeon** (`fondamenta.md` §5.5): con le definizioni di `xb_content` riporta ogni valore alla forma che Histeon gli dà (numeri, sì/no, date `AAAA-MM-GG` anche da `Ymd`, data e ora `AAAA-MM-GGThh:mm:ss`, indirizzi solo http(s), testo ricco senza markup), e le righe di un ripetitore a una lista, sia nel formato di Histeon e di Meta Box (un valore solo con la lista) sia in quello di ACF (una riga di meta per valore). Così la stessa pagina si disegna uguale prima e dopo la conversione dei valori, qualunque plugin si scelga.

**Ripetitori.** Il convertitore fa di un loop sulle righe di un ripetitore `fondamenta/loop` con fonte `righe` (`campo`, `pagina` per quello della pagina, `dentro` per un ripetitore nella riga del loop attorno); i valori della riga sono legami `riga.<campo>` (§11.3).

**Una correzione di Histeon trovata qui.** Histeon salva un campo data e ora come ISO 8601 con il fuso (`2026-03-01T18:30:00+01:00`) ma, leggendolo, lo scartava: il valore non si vedeva mai. Ora `Fields\Values::date()` lo legge nell'ora locale del sito (`2026-03-01T17:30:00` con il sito in UTC), e Fondamenta fa lo stesso.

### 15.1 ACF e Secure Custom Fields (`--campi=scf`, `--campi=acf`)

Un formato per due plugin (`Fields\Acf`).

| Histeon | ACF / SCF | Valore |
|---|---|---|
| testo, testo lungo, testo ricco, numero, indirizzo, email | `text`, `textarea`, `wysiwyg`, `number`, `url`, `email` | uguale |
| data | `date_picker` (restituisce `Y-m-d`) | `Ymd` |
| data e ora | `date_time_picker` | `AAAA-MM-GG hh:mm:ss` nell'ora del sito |
| sì/no | `true_false` | `1` / `0` |
| scelta (anche multipla) | `select` (`multiple`) | uguale |
| immagine; più immagini | `image` (ID); `gallery` | uguale |
| contenuto collegato (anche più) | `post_object` (`multiple`) | uguale |
| ripetitore (anche dentro una riga) | `repeater` con `sub_fields` | `campo` = numero di righe (vuoto se nessuna), `campo_0_sotto` per ogni valore |
| più valori semplici (anni, nomi) | `repeater` con un campo `valore` (ACF non ha liste di valori semplici) | come un ripetitore |

Ogni valore ha il suo riferimento `_campo` alla chiave del campo. Le chiavi (`group_…`, `field_…`) vengono dal tipo e dal percorso del campo: uguali a ogni esportazione, quindi i gruppi del tema e i riferimenti dei valori combaciano. I gruppi vanno in `acf-json/` del tema del sito, che ACF e SCF leggono da soli dal tema attivo; un nuovo salvataggio nell'editor dei gruppi li aggiorna lì.

Ripetitori e gallerie non ci sono in ogni versione di ACF: il rapporto e il comando nominano i campi che li chiedono.

**Prove.** `FieldsExitTest`: un template di loop con data, data e ora, sì/no, numero, scelta, più numeri e un ripetitore con un ripetitore nella riga, disegnato da Histeon e da Fondamenta (stesso DOM); i valori convertiti nel formato di ACF (righe, riferimenti, date, sì/no, righe vecchie da togliere a una nuova scrittura); Fondamenta disegna la stessa pagina dai valori convertiti; i gruppi del tema, con chiavi uguali a quelle dei riferimenti. `e2e/specs/export-fields.spec.ts`, con Secure Custom Fields 6.9 vero nel WordPress di sviluppo (`dev/install-third-party.sh`; senza, la prova si salta): il gruppo importato in SCF, i valori convertiti, `get_field()` restituisce data, sì/no, valori e ripetitore annidato come Histeon li aveva, e l'editor mostra le righe nei campi di SCF.

### 15.2 Meta Box (`--campi=metabox`)

`Fields\MetaBox`. I meta box sono `campi/meta-box.json` nel tema del sito, passati a Meta Box da `functions.php` (filtro `rwmb_meta_boxes`). Date con `save_format` `Y-m-d` (restano come sono), data e ora `Y-m-d H:i:s` nell'ora del sito, sì/no `switch` (`1`/`0`), scelte, contenuti collegati e immagini multiple (`select`/`post` con `multiple`, `image_advanced`) **una riga di meta per valore** come le salva Meta Box, più valori semplici come campo `clone` (una lista sola, come Histeon), ripetitore come `group` clonabile (una lista di righe, come Histeon). Il ripetitore chiede l'estensione Meta Box Group, i campi di termini e utenti MB Term Meta e MB User Meta: il rapporto lo dice.

### 15.3 Pods (`--campi=pods`)

`Fields\Pods`. Un `pods.json` nella radice del tema del sito, che Pods legge da solo dal tema attivo: ogni tipo, tassonomia o gli utenti «estesi» con un gruppo di campi. Date e sì/no come Histeon (`0` per il no), data e ora `Y-m-d H:i:s`, valori multipli una riga di meta per valore (`repeatable` per i valori semplici), come li salva Pods (provato facendo salvare a Pods stesso gli stessi valori). Pods non ha ripetitori con campi propri: un ripetitore resta fuori dai gruppi, i suoi valori restano come sono (Fondamenta li mostra) e il rapporto lo dice.

### 15.4 JetEngine (`--campi=jetengine`)

`Fields\JetEngine`. I meta box sono `campi/jet-engine.json` nel tema del sito, aggiunti da `functions.php` a quelli che JetEngine tiene nelle sue impostazioni; salvati una volta nel suo pannello diventano suoi. Valori come li salva JetEngine (lo stesso formato che `Fields\JetEngineFields` di Histeon sa leggere): date `Y-m-d`, data e ora `Y-m-dTH:i`, sì/no `true`/`false`, più immagini una galleria di ID separati da virgole, ripetitore righe `item-0`, `item-1`…, più valori semplici un ripetitore di un campo `valore`. JetEngine non ha ripetitori dentro le righe: quel campo resta nella riga com'è (Fondamenta lo mostra) e il rapporto lo dice.

### 15.5 Codeideal Open Fields (`--campi=openfields`)

Provato su un WordPress di prova (versione 0.4.3). I valori stanno nei meta, con le righe come ACF (`campo_0_sotto`), date `Y-m-d`, data e ora `Y-m-dTH:i`, sì/no `1`/`0`: l'adattatore è una variante di `Fields\Acf` senza i riferimenti. I gruppi però stanno in tabelle sue: il tema del sito porta un file per gruppo (`campi/openfields/<tipo>.json`) da importare nel suo pannello, e la sua importazione non porta né dove si mostra il gruppo né i campi dentro un ripetitore. Il rapporto lo dice.

### 15.6 Modern Fields: provato, adattatore non fatto

Provato su un WordPress di prova (versione 1.5.3). I gruppi di campi sono fatti dei suoi blocchi e si importano dal suo pannello: scriverli vorrebbe dire riprodurre il markup di quei blocchi, che cambia da una versione all'altra. Per ora resta fuori.

### 15.7 Prove

- `FieldsExitTest`: per **ogni** adattatore la stessa pagina (data, data e ora, sì/no, numero, scelta, scelta multipla, più numeri, ripetitore con ripetitore nella riga) disegnata da Fondamenta prima e dopo la conversione dei valori: stesso HTML; i dettagli di ACF (righe, riferimenti, righe vecchie da togliere) e di Meta Box (date, liste, scelte una riga per valore, gruppi).
- `e2e/specs/export-fields.spec.ts`, con i plugin veri nel WordPress di sviluppo (`dev/install-third-party.sh`; ogni prova si salta se il suo plugin non c'è, e lo spegne alla fine): Secure Custom Fields 6.9 (`get_field()` e l'editor), Meta Box 5.15 (`rwmb_meta()` e l'editor), Pods 3.3 (`pods()->field()` e il suo riquadro), JetEngine 3.8 (il suo riquadro con data e righe), Open Fields 0.4 (`cofld_get_field()`, `cofld_get_rows()` e l'importazione del gruppo).
- La conversione dei valori si fa una volta sola, nell'uscita (§17): l'adattatore legge i valori nella forma di Histeon.

## 16. I form nel plugin scelto

I form di Histeon sono quelli del sito statico: markup scritto nella pagina che invia a `/xb-form` (Worker, Turnstile, `docs/spec/static.md` §4), con i messaggi in «Messaggi dai form». Fuori da Histeon non c'è il Worker: ogni form diventa un form del plugin scelto, con un **adattatore** per plugin (`Export\Core\Forms\Adapter`).

**Lettura** (`Forms\Reader`): un form di Histeon è un `form` che invia a `/xb-form` o che ha il campo `xb_form`. Dal suo markup si leggono il nome (`xb_form`, `contatti` se manca), la pagina dopo l'invio (`xb_redirect`, solo un percorso del sito), il testo del pulsante, se c'è Turnstile, e i campi: nome, tipo (`text`, `email`, `tel`, `url`, `number`, `date`, `textarea`, `select`, gruppi di `radio` e di `checkbox` con lo stesso nome), etichetta (il `label` attorno, quello con `for`, o la `legend` del `fieldset` per un gruppo), obbligatorio, segnaposto, opzioni. Lo stesso nome in più pagine è un form solo. `wp xb export-core --forms [--form=<plugin>]` mostra i form trovati, i documenti, i messaggi ricevuti e il form come il plugin lo importerebbe; non scrive nulla.

**Adattatore:** `export()` (il form come il plugin lo importa), `create()` (lo crea: scrive), `block()` (il blocco del plugin che prende il posto del form), `messages()` (i messaggi già ricevuti come risposte del plugin: scrive). Il convertitore mette il blocco al posto di un form già creato (`Converter::$forms`, nome → blocco); altrimenti il form resta `core/html` e il rapporto dice che non invierà. La creazione e i messaggi li fa l'uscita (§17).

### 16.1 Fluent Forms (`--form=fluentforms`)

`Forms\FluentForms`. Ogni campo è fatto dal modello dell'elemento di Fluent Forms con nome, etichetta, opzioni e obbligatorio del campo di Histeon (`input_text`, `input_email`, `input_url`, `input_number`, `input_date`, `textarea`, `select`, `input_radio`, `input_checkbox`); il form si crea come lo crea la sua importazione. Dopo l'invio: la pagina di `xb_redirect`, oppure un messaggio di ringraziamento. Una notifica all'amministratore del sito con tutti i campi, come da Histeon. Turnstile si accende nelle impostazioni di Fluent Forms, con le chiavi del sito (il rapporto lo dice). I messaggi ricevuti diventano risposte del form, con la loro data, già lette. Il blocco è `fluentfom/guten-block` con `formId`.

### 16.2 Contact Form 7 (`--form=cf7`)

`Forms\ContactForm7`. Il form si scrive come lo scrive Contact Form 7, testo con i tag (`<label> Nome [text* nome] </label>`, `[select tema "Libri|libri"]` con le sue «pipe»: la pagina mostra l'etichetta, l'email riceve il valore), e si crea come lo crea il suo pannello; l'email va all'amministratore del sito con tutti i campi. Il blocco è quello che salva il suo editor: lo shortcode con l'hash del form. Contact Form 7 **non archivia i messaggi** (serve Flamingo) e **resta sulla pagina** dopo l'invio: il rapporto lo dice, e i messaggi già ricevuti non si portano.

### 16.3 Forminator (`--form=forminator`)

`Forms\Forminator`. Il form si crea con l'API di Forminator, un campo per riga con gli id di Forminator (`text-1`, `email-1`…) e l'etichetta, le opzioni e l'obbligatorio di Histeon; la notifica all'amministratore la aggiunge Forminator. Dopo l'invio: la pagina di `xb_redirect` o un messaggio. I messaggi ricevuti diventano sue risposte, con la loro data. Il blocco è il suo shortcode in un blocco Shortcode del core. I nomi dei campi nella pagina diventano `text-1`, `email-1`…: se ne accorgerebbe solo uno script del sito che li leggesse.

### 16.4 JetFormBuilder (`--form=jetformbuilder`)

`Forms\JetFormBuilder`. Un form è un contenuto del suo tipo fatto dei suoi blocchi di campo (`jet-forms/text-field` con `field_type` per email, telefono e indirizzo; `textarea-field`, `number-field`, `date-field`, `select-field`, `radio-field`, `checkbox-field` con le opzioni in `field_options`; `submit-field`), con le azioni dopo l'invio: email all'amministratore con tutti i campi (`%nome%`), «Save Form Record», e la pagina di `xb_redirect`. I messaggi ricevuti diventano suoi record, con la loro data. Il blocco è `jet-forms/form-block`.

### 16.5 Ninja Forms (`--form=ninjaforms`)

`Forms\NinjaForms`. Il form si crea come lo crea la sua importazione, con le impostazioni di ogni campo salvate subito: i campi con i nomi di Histeon come chiavi, poi le azioni «salva la risposta», email all'amministratore (`{all_fields_table}`) e la pagina di `xb_redirect` o un messaggio. I messaggi ricevuti diventano sue risposte, con la loro data. Il blocco è `ninja-forms/form` con l'attributo `formID`.

### 16.6 SureForms (`--form=sureforms`)

`Forms\SureForms`. Un form è un contenuto del suo tipo fatto dei suoi blocchi (`srfm/input`, `srfm/email`, `srfm/textarea`, `srfm/dropdown`, `srfm/multi-choice`…), con le impostazioni nei meta: testo del pulsante, dopo l'invio la pagina di `xb_redirect` (`custom url`) o un messaggio, email all'amministratore con `{all_data}`. I messaggi ricevuti diventano sue voci, con i nomi che SureForms dà ai campi, salvati alla creazione. SureForms tiene solo le etichette delle scelte: una scelta invia l'etichetta, e il rapporto lo dice. Il blocco è `srfm/form`.

### 16.7 Bit Form: provato, adattatore non fatto

Provato su un WordPress di prova (versione 3.5). Un form di Bit Form vuole la griglia di impaginazione per tre larghezze e gli stili che il suo editor genera nel browser; la sua importazione accetta solo le sue esportazioni, con tutto questo dentro. Un form fatto da fuori sarebbe da rifare nel suo editor. Per ora resta fuori.

### 16.8 Prove

- `FormsExitTest`: il form del manuale con una scelta, un gruppo di opzioni in un `fieldset` e una casella, letto dal markup (nome, pagina dopo l'invio, pulsante, Turnstile, campi con tipi, etichette, opzioni, obbligatori); il form come Fluent Forms lo importa; i messaggi di Histeon riletti campo per campo; la pagina convertita con il blocco di Fluent Forms al posto del form, e senza `/xb-form`.
- `e2e/specs/export-forms.spec.ts`, con i plugin veri nel WordPress di sviluppo (ogni prova si salta se il suo plugin manca, e lo spegne alla fine): lo stesso form di Histeon creato nel plugin, i due messaggi ricevuti portati fra le risposte, la pagina convertita mostra il form del plugin, un visitatore lo compila e lo invia. Fluent Forms 6.2, Forminator 1.58, JetFormBuilder 3.6, Ninja Forms 3.16 e SureForms 2.13: si arriva alla pagina di ringraziamento e la risposta segue le due portate (per SureForms con gli stessi nomi di campo). Contact Form 7 6.2: il form passa allo stato «inviato» e parte un'email (un mu-plugin finge il server di posta durante la prova).

## 17. L'uscita

`Export\Core\Departure` scrive l'uscita con lo stesso schema dell'esportazione verso Etch: anteprima con impronta, scrittura solo con quell'impronta, copia di sicurezza, ripristino.

**Anteprima** (`wp xb export-core --uscita [--campi=<plugin>] [--form=<plugin>]`, o il pannello): i documenti con blocchi di Histeon (quelli del rapporto, componenti compresi), le pagine con un template proprio di Histeon CMS, i contenuti con i campi da portare nel formato del plugin dei campi, i form di Histeon con i loro messaggi, le note dei plugin scelti; l'impronta copre l'impronta del rapporto e tutto questo, quindi cambia se cambia il sito o la scelta dei plugin. Non scrive nulla.

**Scrittura** (`--uscita --apply --confirm=<impronta>`, con le stesse `--campi` e `--form`): serve un amministratore con `xb_build` e `unfiltered_html`, Fondamenta attiva e, se ci sono form, il plugin dei form attivo. Prima la copia di sicurezza (opzione `xb_core_export_backup`, non caricata in automatico, ultime tre): di ogni documento contenuto e date di modifica, di ogni pagina il `_wp_page_template`, di ogni contenuto con i campi tutti i suoi meta, il tema attivo. Poi, in ordine: i form creati nel plugin con i loro messaggi (§16), ogni documento convertito **nel suo post** (stessi ID, indirizzi, autori e date: si scrive solo `post_content`), il `_wp_page_template` delle pagine, i valori dei campi nel formato del plugin (§15). Nel registro di Histeon: `export`. **Dopo la scrittura non si pubblica una release di Histeon**: riporterebbe le pagine ai suoi blocchi.

**Controllo** (`--check`, «Controlla cosa resta»): i documenti che hanno ancora blocchi di Histeon (nessuno dopo la scrittura). Il confronto per immagini si fa con `dev/visual-diff`, prima (Histeon attivo) e dopo (`--finish`).

**Conclusione** (`--finish --yes`, «Concludi l'uscita»): il tema del sito, installato a mano (§13), diventa attivo (ThemeCarry porta i template del database da Fondamenta al tema del sito) e Histeon si spegne, **non si cancella**. Nel registro: `export_finish`.

**Ritorno** (`--restore --yes`, «Ripristina», dopo aver riattivato Histeon se era spento): contenuti e date, template delle pagine, meta dei contenuti con i campi e tema attivo tornano come erano; i form creati nel plugin restano lì, non usati, e il comando lo dice. Nel registro: `export_restore`. Cancellare Histeon esegue `uninstall.php`: da lì non si torna.

**Il pannello** (Histeon → Impostazioni → «Uscita verso Gutenberg», `Export\Core\Admin`): scelta del plugin dei campi e di quello dei form, anteprima, tema del sito da scaricare, scrittura con l'impronta dell'anteprima appena letta e una casella di conferma, controllo, ripristino, conclusione. Un'azione `admin-post` con nonce; i permessi sono quelli di `Departure`. Dopo la conclusione Histeon è spento e il pannello non c'è più: si torna riattivandolo.

**Prove.** `DepartureTest`: impronta stabile e diversa con un'altra scelta di plugin; scrittura rifiutata con un'impronta sbagliata e senza `unfiltered_html`; scrittura vera (pagina convertita, date invariate, valori dei campi nel formato di SCF, nulla rimasto); Fondamenta disegna la pagina convertita con il campo come prima; ripristino che rimette pagina, date e meta identici. `e2e/specs/export-departure.spec.ts`: dal pannello anteprima, scrittura e ripristino, con la pagina tornata identica.

**Su un sito di prova** (su una copia, poi rimessa com'era): tema del sito con `--campi=scf` installato; `--uscita --campi=scf`, `--apply` con l'impronta, `--check` (nessun documento con blocchi di Histeon), `--finish --yes` (tema del sito attivo, Histeon spento): 40 pagine a 1366 e 390 px, **80 catture con stessa geometria e stessi stili calcolati** di quelle con Histeon; 73 sotto l'1% di pixel diversi, le altre per immagini caricate in ritardo (una al 10,96% ricatturata scende sotto l'1%). Poi Histeon riattivato e `--restore --yes`: i contenuti di nuovo con i blocchi di Histeon, tema Fondamenta, valori dei campi nella forma di Histeon, prova DOM 224 su 225 come prima.

