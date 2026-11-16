# Modalità cliente

Implementazione:
- server: `plugin/src/Security/ContentOnly.php`, `plugin/src/Security/ClientGuard.php`, `Capabilities::content_only()`, i controlli in `Rest\Controller` e `Admin\BuilderScreen`;
- builder: `editor/src/lib/contentOnly.ts`, `BuilderState` (`contentOnly`, `baseline`), `App.svelte` e `InfoPanel.svelte`.

Test: `plugin/tests/Integration/ClientModeTest.php`, `editor/test/contentOnly.test.ts`, `e2e/specs/client-mode.spec.ts`; per i permessi di ogni cliente e l'aspetto della pagina `plugin/tests/Integration/ClientAccessTest.php`, `editor/test/pageLook.test.ts`.

Il cliente cambia testi e immagini delle pagine che può modificare. Non tocca struttura, stili, codice e dati, e non pubblica.

## Permessi

- **`xb_edit_content`** apre il builder in modalità contenuti, sulle pagine per cui l'utente ha `edit_post`. Gli amministratori la ricevono con gli altri permessi di Histeon.
- **`xb_edit_props`**, spento di serie: con `xb_edit_content` lascia cambiare anche le props letterali dei componenti (sotto, «Props dei componenti»).
- Il ruolo **Cliente Histeon** (`xb_client`) ha:
  - `read` e `upload_files`;
  - modifica, ma non pubblicazione né eliminazione, di pagine e articoli (`edit_*`);
  - `xb_edit_content`.

  Non ha `xb_build`, `xb_publish` né `unfiltered_html`.
- Si è in modalità contenuti con `xb_edit_content` senza `xb_build` (`Capabilities::content_only()`).
- Che cosa può fare ogni cliente, e dove, si sceglie per utente in **Histeon → Permessi dei clienti** (sotto, «Permessi del cliente»). Senza una scelta vale quanto detto qui.

## Cosa può cambiare

`ContentOnly::check()` confronta il documento prima e dopo. I blocchi devono essere gli stessi, nello stesso ordine e con gli stessi attributi. Possono cambiare solo:
- il testo di `xb/text`, con le **stesse** espressioni `{…}`, nello stesso ordine;
- su un `<img>`: `src`, `srcset`, `sizes`, `width`, `height` e `alt`, a meno che il valore di prima fosse un'espressione (un'immagine dinamica resta dinamica), e senza aggiungere espressioni.

Tutto il resto è rifiutato:
- un blocco aggiunto, tolto o spostato;
- un tag, una classe, uno stile, un link;
- l'HTML di un blocco WordPress o grezzo;
- lo script della pagina, le props di un componente.

## Dove si controlla

- **Salvataggio** (`POST /documents/{id}`): il controllo avviene sulla copia di sviluppo, e se fallisce la risposta è `403`. Anche lo script e lo schema del componente devono restare uguali. Si salva in sviluppo; pubblica chi ha `xb_publish`.
- **Anteprima** (`POST /render`): per un cliente vale solo per il suo documento e con la stessa regola. Così non può usarla per far comparire dati con espressioni nuove.
- **Letture**: `GET /styles`, `GET /components` e `GET /framework` sono aperte anche a `xb_edit_content`, per disegnare la pagina. Ogni scrittura resta riservata a `xb_build`.
- **Fuori dal builder** (`ClientGuard`): un cliente non cambia il contenuto di una pagina Histeon dall'editor a blocchi (REST, risposta `403`), dall'editor classico, dalla modifica rapida o da `wp_insert_post`, dove il contenuto salvato resta quello di prima. Titolo e riassunto cambiano normalmente. Il contenuto si confronta come blocchi, quindi un salvataggio del solo titolo passa.
- **Builder**: `BuilderState.setTree()` rifiuta subito, con un messaggio, ciò che il server rifiuterebbe. Lo stesso vale per stili, fogli e props. L'interfaccia mostra:
  - la tela con la modifica dei testi: in modalità contenuti basta un clic (un tocco) su un testo per aprirlo in modifica, come dice l'invito; sotto i 1024 px la colonna destra è un cassetto e la tela prende tutta la larghezza, e l'avviso «serve uno schermo più largo» non compare;
  - la struttura, in sola lettura;
  - un pannello Proprietà ridotto: scegli immagine e testo alternativo;
  - i Controlli.

  Spariscono i pannelli HTML, CSS e JS, l'inserimento, le altre sezioni, il connettore IA e la pubblicazione. La disposizione dei pannelli del cliente non si salva.

Il server è il guardiano: l'interfaccia evita solo di offrire ciò che verrebbe rifiutato.

## In Gutenberg

Nell'editor a blocchi il cliente vede gli stessi pannelli di chi costruisce, in sola lettura: «Histeon · Aspetto» con i valori attivi evidenziati e gli altri pulsanti spenti, le classi senza pulsanti, le props dei componenti disattivate, le aree degli slot senza inseritore, e sotto la pagina HTML, CSS e JavaScript senza «Applica» né «Salva in sviluppo». Una nota dice che le modifiche le fa chi gestisce il sito. Serve a mostrare al cliente che cosa cambia e dove. Il server rifiuta comunque ogni scrittura del cliente (sopra, «Fuori dal builder»).

Il flag arriva all'editor in `window.xbInspector.client` (`EditorLaunch`, `Capabilities::content_only()`); `blocks.js` lo legge quando disegna, perché si carica prima dell'ispettore. Test: `e2e/specs/gutenberg-components.spec.ts` (accesso come utente «Cliente Histeon»).

Gli stili di una classe valgono per tutto il sito: per il cliente il pannello Aspetto scrive solo nell'aspetto della pagina, se glielo hanno dato (sotto, «Aspetto della pagina»); altrimenti resta in sola lettura. Le props restano in sola lettura in Gutenberg, perché l'editor a blocchi scrive il contenuto online e il cliente non pubblica.

## Props dei componenti

Il permesso **`xb_edit_props`** (`Capabilities::EDIT_PROPS`) lascia cambiare al cliente, nel builder, anche le props **letterali** delle istanze di componente sulle pagine che può modificare. È **spento di serie**: nessun ruolo lo riceve, nemmeno «Cliente Histeon»; il proprietario lo dà con un editor dei ruoli o con `wp cap add xb_client xb_edit_props`. Senza `xb_edit_content` non vale.

`ContentOnly::check( $prima, $dopo, true )` accetta allora, su un `xb/component` con lo stesso `ref`, una prop cambiata solo se:
- il componente la dichiara con un tipo fra `string`, `number`, `boolean`, `url`, `image`, `enum` (`CLIENT_PROP_TYPES`): mai testo ricco, liste od oggetti, che si stampano diversamente;
- il valore di prima non era un'espressione (una prop dinamica resta dinamica);
- il valore nuovo è del suo tipo: un booleano, un numero finito, una delle `options` di un `enum`, un testo di al massimo 2000 caratteri senza graffe né caratteri di controllo; un `url` relativo, `#…`, `http(s)`, `mailto` o `tel`; un'`image` vuota o nella cartella degli upload. Togliere la prop (torna al valore predefinito) è ammesso.

Lo usano il salvataggio e l'anteprima (`POST /documents/{id}`, `POST /render`); il builder ripete la stessa regola (`contentOnlyProblem( …, props )` in `editor/src/lib/contentOnly.ts`) e, nel pannello Proprietà ridotto, mostra i campi delle sole props ammesse (`config.contentProps`). Test: `ClientModeTest::test_xb_edit_props_lets_a_client_set_literal_props_and_nothing_more`, `editor/test/contentOnly.test.ts`.

## Struttura bloccata

Una pagina o un articolo con il metadato `xb_structure_locked` (il pannello del documento **Histeon · Struttura** in Gutenberg; lo cambia solo chi ha `xb_build`) tiene ferma la struttura per chiunque la modifichi senza `xb_build`, per esempio un Editor o un Autore di WordPress: nell'editor a blocchi lavora in modalità solo contenuti (`templateLock: contentOnly`), e il server applica la regola di `ContentOnly::check()` a ogni salvataggio: `403` in REST, contenuto salvato invariato per le altre vie. Chi costruisce non è vincolato. Implementazione: `plugin/src/Security/StructureLock.php`, `EditorLaunch`, `editor/src/gutenberg/lock.ts`. Test: `plugin/tests/Integration/StructureLockTest.php`.

## Permessi del cliente

Chi costruisce (`xb_build`) o amministra il sito sceglie, per ogni cliente, che cosa può modificare e dove: **Histeon → Permessi dei clienti** (scheda «Clienti»), oppure il link «Permessi Histeon» nell'elenco degli utenti e nel profilo. Implementazione: `plugin/src/Security/ClientAccess.php`, `plugin/src/Admin/ClientAccessScreen.php`.

La schermata elenca i clienti (chi ha `xb_edit_content` senza `xb_build`) con un riepilogo; per ognuno:
- **Che cosa può modificare**:
  - testi e immagini (sempre, nelle pagine con almeno quel livello);
  - **props dei componenti**: come `xb_edit_props`. Se il ruolo dà già `xb_edit_props`, la casella è spuntata e spenta (lo si toglie dal ruolo): il permesso dato con un ruolo continua a valere;
  - **aspetto della pagina** (sotto), con i **gruppi** dell'Aspetto che può usare: Impaginazione, Spazi, Dimensioni, Testo, Colori e bordi, Posizione (i gruppi della Mini GUI, `PageLook::GROUPS`).
- **Su quali pagine**: tutte quelle che può modificare in WordPress, oppure solo un elenco, dove ogni pagina o articolo ha un livello:

| Livello | Builder | WordPress |
|---|---|---|
| Nessuno (anche ogni pagina non in elenco) | non si apre (`403` su `GET` e `POST /documents/{id}`, `POST /render`) | non modificabile |
| Solo vedere nel builder | si apre e si disegna, niente si salva (`403`); la tela non apre i testi | non modificabile |
| Testi e immagini | come la modalità contenuti | come prima (`ClientGuard`) |
| Anche aspetto | anche l'aspetto della pagina, nei gruppi scelti | come prima |

«Anche aspetto» vale solo con «Aspetto della pagina» acceso e almeno un gruppo; altrimenti vale come «Testi e immagini». Con «Tutte» ogni pagina ha «Testi e immagini», o «Anche aspetto» se l'aspetto è acceso.

**Tutto spento di serie.** Senza una scelta (`ClientAccess::defaults()`) il cliente ha esattamente quello di prima: tutte le pagine che può modificare, testi e immagini, props solo dal ruolo. Nessun cliente guadagna permessi con l'aggiornamento.

**Dove vale, lato server.**
- Si salva per utente e per sito (opzione utente `xb_client_access`), solo da `ClientAccess::update()`: chi la chiama deve avere `xb_build` o `manage_options`, l'utente deve essere un cliente e diverso da sé (un cliente non cambia i propri permessi né quelli di altri; nessuno li cambia a un amministratore o a chi costruisce). Ogni valore si controlla con elenchi chiusi (livelli, gruppi, `all`/`list`, booleani, id di pagine e articoli, al massimo 500); il form ha un nonce legato al cliente; ogni salvataggio va nel Registro (`client_access`).
- In WordPress una pagina sotto «Testi e immagini» non è modificabile per quel cliente: `map_meta_cap` dà `do_not_allow` a `edit_post`, quindi niente editor a blocchi, editor classico, modifica rapida o scrittura REST (`403`).
- Nel builder i controlli usano il livello (`ClientAccess::allows()`): `GET /documents/{id}` e `POST /render` vogliono almeno «Solo vedere», `POST /documents/{id}` almeno «Testi e immagini», l'aspetto «Anche aspetto» e i gruppi. Il documento risponde con `access: {level, groups}`; il builder riceve `viewOnly` e `lookGroups` e li rispetta (`BuilderState`), ma decide il server.

## Aspetto della pagina

Il cliente con «Anche aspetto» usa il pannello **Histeon · Aspetto** in Gutenberg, e nel builder la sezione **Aspetto di questa pagina** del pannello Proprietà ridotto (la Mini GUI con stato e larghezza), ma ciò che cambia vale **solo su quella pagina**: se cambia il colore di un titolo nella pagina «Contatti», cambia solo lì, e la classe resta com'è sul resto del sito. Chi costruisce, in Gutenberg, sceglie con l'interruttore **Solo questa pagina** se scrivere nella classe (tutto il sito) o nello stile della pagina. Implementazione: `plugin/src/Styles/PageLook.php`, `editor/src/lib/pageLook.ts`, `editor/src/gutenberg/pageStyles.ts`, `appearance.ts`, `InfoPanel.svelte`.

**Dove si salva.** Nel documento della pagina, accanto a contenuto e script: la chiave `styles` del payload, una lista di voci `{selector, css}`. Si salva in sviluppo con `POST /documents/{id}` (parametro `styles`, mandato solo se cambiato; senza, resta quello che c'era), va online con la release della pagina (meta protetto `_xb_page_styles`), si vede nelle anteprime di sviluppo (filtro `xb_document_styles`) e passa con l'esportazione delle release. Gli stili condivisi (`xb_styles`, il documento `styles`) il cliente non li tocca mai: `POST /styles` resta riservato a `xb_build`.

**Come si stampa.** Ogni regola sta sotto una classe della pagina, `.xb-page-<id> .titolo { … }`, e la classe `xb-page-<id>` va sul `<body>` della sola pagina singola (`body_class`); il CSS si stampa in `<style id="xb-page-styles">` dopo gli stili del sito (`wp_head` 100). Nelle tele del builder e di Gutenberg, che mostrano solo quella pagina, l'ambito è `:root`. In tutti i casi la regola ha una classe in più di quella del sito e quindi la vince. In un archivio o in un'altra pagina che mostra la stessa classe non arriva nulla.

**Grammatica chiusa** (`PageLook::validate()`, per tutti: cliente, chi costruisce, una release o un'importazione):
- il selettore è una classe o un id (`.titolo`, `#hero`), una volta sola;
- il corpo ha dichiarazioni, un blocco di stato (`&:hover`, `&:focus-visible`, `&:active`, `&::before`, `&::after`), un blocco di larghezza con nome (`@media (--tablet)`) e uno stato dentro una larghezza, nient'altro: niente altri selettori, at-rule, commenti, stringhe, `url()` o `!important`;
- ogni proprietà è un controllo della Mini GUI (`PageLook::PROPS`, uguale a `GROUPS` in `miniGui.ts`: lo controlla `editor/test/pageLook.test.ts`), ogni valore una sua opzione oppure, per le scale del framework (spazi, testo, raggi, ombre, colori), un `var(--nome)`;
- al massimo 100 voci e 20.000 byte.

Ciò che non la rispetta riceve `400` e non si scrive nulla.

**Regole del cliente** (`POST /documents/{id}`, dopo la grammatica): si confrontano le dichiarazioni prima e dopo, per selettore, blocco e proprietà (`PageLook::changed()`). Se qualcosa cambia serve il livello «Anche aspetto» su quella pagina, e ogni proprietà cambiata deve stare in un gruppo concesso; altrimenti `403`. Le dichiarazioni che c'erano già (di altri gruppi) restano come sono. Un salvataggio dei soli testi lascia l'aspetto com'era.

Test: `ClientAccessTest::test_the_page_look_stays_on_the_page_and_in_the_groups_given` (selettori, proprietà, valori, at-rule e graffe fuori grammatica, gruppo non concesso, stili condivisi intatti, stampa sulla sola pagina dopo la release), `test_pages_and_levels`, `test_only_who_builds_sets_permissions_and_with_closed_lists`, `test_props_from_the_role_or_from_the_permissions`, `test_nothing_is_gained_with_the_update`, `test_a_release_applies_only_the_grammar`; `editor/test/pageLook.test.ts`.

Limiti noti:
- il formato dei profili di Histeon CMS (`Remote\PageProfile`) non porta l'aspetto della pagina: una pagina inviata da Histeon Publish tiene l'aspetto che ha nel sito;
- la rinomina di una classe dal gestore dei selettori e la migrazione fra framework non riscrivono l'aspetto delle pagine: una voce `.vecchia` resta con quel nome (non ha più effetto e si toglie dal pannello), un `var(--…)` del framework precedente resta com'è;
- la schermata dei permessi elenca al massimo 300 pagine e articoli (i più recenti) che il cliente può modificare; le pagine che non compaiono tengono il livello che avevano.

