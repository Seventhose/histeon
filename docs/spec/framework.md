# Framework CSS — v0.1

Implementazione: `plugin/src/Framework/`. Test: `plugin/tests/Integration/FrameworkTest.php`, `plugin/tests/Unit/FrameworkListsTest.php`, `editor/test/completions.test.ts`.

## Principi
- **Histeon non include framework di terze parti e non gestisce codici di licenza.** Un provider rileva un framework che il proprietario del sito ha installato e ne legge ciò che espone.
- La scelta si fa in **Impostazioni → Histeon**. La pagina richiede `manage_options` e `xb_build` e il form è protetto da nonce. Finché non si sceglie, compare un avviso e si usa **Histeon CSS** (`docs/spec/histos-css.md`).
- Se il framework scelto non è più installato, Histeon torna a Histeon CSS invece di rompere il sito.

## Provider
| id | Rilevamento | Variabili e classi | Fogli nel canvas |
|---|---|---|---|
| `core-framework` | Plugin Core Framework attivo | Le variabili e le classi che il plugin espone, raggruppate per categoria. Vuote finché non si salva un preset nell'app di Core. | `uploads/core-framework/css/core_framework.css` |
| `acss` | Plugin Automatic.css attivo | L'elenco di variabili e classi che il plugin ha generato per il sito | `uploads/automatic-css/automatic-*.css` |
| `xb-tokens` (**Histeon CSS**, ADR 0014) | sempre | Token da `assets/tokens/xb-tokens.css`, classi da `HistosCss\Utilities` | `xb-tokens.css` e `histos.css`, accodati da Histeon anche sul frontend (classi su richiesta) |
| `none` | sempre | — | — |

I nomi forniti da plugin terzi passano da `Lists`: entrano solo identificatori CSS validi, al massimo 5000 e lunghi al massimo 100 caratteri. Così niente che arrivi da un altro plugin può iniettare CSS o markup nell'editor.

## Livello semantico `--xb-*`
- Ogni provider dichiara una mappa token Histeon → variabile del framework (es. `xb-space-xxl` → `space-2xl` in Core, `space-xxl` in ACSS).
- Histeon stampa sul frontend e nel canvas `:root{--xb-space-m:var(--space-m);…}`, **solo per le variabili che esistono davvero** nel framework attivo.
- Un componente scritto con `--xb-*` funziona senza modifiche con qualsiasi framework.

| Token | Core Framework | ACSS |
|---|---|---|
| `xb-space-xs … xxl` | `space-xs … 2xl` | `space-xs … xxl` |
| `xb-text-s … xxl` | `text-s … 2xl` | `text-s … xxl` |
| `xb-primary`, `-light`, `-dark` | `primary`, `primary-l-2`, `primary-d-2` | `primary`, `primary-light`, `primary-dark` |
| `xb-bg`, `xb-surface`, `xb-text`, `xb-heading` | `bg-body`, `bg-surface`, `text-body`, `text-title` | `body-bg-color`, `bg-light`, `text-dark`, `heading-color` |
| `xb-radius-s/m/l` | `radius-s/m/l` | `radius-s/m/l` |
| `xb-shadow-s/m/l` | `shadow-s/m/l` | `box-shadow-1/2/3` |

## Nel builder
- `GET /xb/v1/framework` restituisce le variabili e le classi del framework attivo, più i token `--xb-*`.
- **Pannello CSS:** scrivendo `--sp` si apre l'elenco delle variabili. Fuori da `var()` il completamento inserisce `var(--space-m)`.
- **Pannello HTML:** dentro un attributo `class="…"` statico vengono proposte le classi del framework.
- **Pannello del framework** (pulsante «CF» o «A» nella barra a sinistra, oppure Esc per chiuderlo). L'app di Core Framework o di ACSS si apre in un pannello laterale sopra l'anteprima, senza passare dal backend:
  - **Contenuto:** solo l'app del framework, senza menu, barra, piè di pagina e avvisi di WordPress (`?xb_frame=1`, `BuilderScreen::framed_dashboard`). La pagina, i suoi permessi e i suoi nonce restano quelli di WordPress e del framework; WordPress la lascia incorniciare solo dallo stesso sito.
  - **Apri e chiudi:** chiuso resta caricato, quindi si riapre dov'era.
  - **Applicare le modifiche:** chiuderlo, o «↻ Applica all'anteprima», ricarica variabili, classi e fogli del framework.
  - **Altro:** la larghezza si regola trascinando il bordo sinistro e viene ricordata; «↗» apre la pagina completa in una nuova scheda.

## Nell'editor a blocchi
Il riquadro di Gutenberg (un iframe: ci arrivano solo i fogli di `enqueue_block_assets`) riceve gli stessi fogli del sito, nello stesso ordine: reset, framework, alias, fogli globali e stili (`Plugin::on_enqueue_block_assets`). Con Histeon CSS i fogli li mette in coda `Frameworks::enqueue`, così i valori del sito (font, colori) vengono dopo i token predefiniti. Fino alla 0.3.6 una seconda copia dei token predefiniti arrivava dopo i valori del sito: nell'editor i testi avevano il font di sistema (test `TokenOverridesTest`, e2e `gutenberg-canvas.spec.ts`). Gli altri framework hanno i loro fogli interi, perché l'editor non sa quali classi userà la pagina.

## Cambio di framework
- Con i token `--xb-*` non serve fare nulla.
- Per i nomi nativi c'è **Impostazioni → Histeon → Cambio di framework con migrazione** (`plugin/src/Framework/Migration.php`). Il passaggio ACSS → Core è verificato con i plugin reali in `MigrationTest`.

**Mappa dei nomi**
- **Variabili:** derivate dalle tabelle degli alias. Lo stesso token `--xb-*` collega i due nomi nativi (ACSS → Core: 11 da riscrivere; le altre hanno già lo stesso nome).
- **Classi:** convenzione `a--b` ↔ `a-b`, accettata solo se la classe esiste davvero nel framework di destinazione (ACSS → Core: 13).

**Cosa viene riscritto:** `class` statiche (le interpolazioni `{…}` restano intatte), attributi `style`, CSS e selettori delle voci di stile.

**Come si usa**
1. **Anteprima della migrazione.** Mostra un rapporto per documento e l'elenco dei nomi senza corrispondenza (es. `--primary-ultra-light`), da sistemare a mano. Non modifica nulla.
2. **Prepara in sviluppo.** Crea commit `dev` per i documenti interessati e per `settings`, con il nuovo framework, tutti in **una transazione**: se una scrittura fallisce a metà, nessun documento resta con i nomi nuovi e la scelta del framework non cambia (`MigrationAtomicTest`). Le variabili e le regole portate dal framework precedente (Carry) si scrivono dopo, a migrazione riuscita. Nulla va online.
3. **Pubblica dal builder.** Una normale release porta online contenuti e framework insieme, in un'unica transazione. Il ripristino della release precedente riporta indietro anche il framework.

**Portare con sé il framework precedente** (`plugin/src/Framework/Carry.php`, test `CarryTest`). Rinominare i nomi non basta: un sito scritto con Automatic.css usa le sue variabili e le sue classi ovunque, e il nuovo framework non ha né gli stessi valori né le stesse regole. Nel cambio di framework c'è quindi **Porta con te le variabili e le regole di …**, attiva di default. Prende dal framework che si lascia:
- **le variabili:** tutte quelle definite nei `:root` di primo livello dei suoi fogli, con i loro valori; quelle dentro `@media` valgono solo per certe larghezze e restano fuori;
- **le regole usate dal sito:** quelle degli elementi e delle classi che il sito usa davvero, lette dalle pagine pubbliche come le vede chi visita, dai contenuti Histeon e dagli stili.

Dove finiscono:
- **Da Automatic.css a Core Framework:** un progetto di Core Framework (sotto) con i colori nel suo sistema e tutte le variabili.
- **In tutti gli altri casi** (verso i token Histeon, da Core Framework…):
  - le variabili vanno in un **foglio globale di Histeon** (`carry-<framework>`), primo nell'ordine, salvato in sviluppo e pubblicato con la stessa release che cambia framework;
  - verso Histeon CSS, i token `--xb-*` prendono i valori del framework di prima, per esempio `--xb-space-m: var(--space-m)`; da Automatic.css con la tabella completa di `docs/spec/histos-css.md` §4 (sfumature, scale, sezioni), più i colori di base come parametri della palette.
- **Le regole usate dal sito:** vanno nel foglio di compatibilità, caricato dopo gli stili del sito come lo era il foglio del vecchio framework, e solo mentre è attivo un altro framework (tornando indietro c'è quello vero). Il vecchio foglio di compatibilità ACSS → Core Framework resta riconosciuto.
- **Risultato:**
  - i nomi con un token diventano token, per esempio `var(--space-xxl)` → `var(--xb-space-xxl)`;
  - quelli senza corrispondenza, come `--primary-ultra-light`, restano com'erano e continuano a valere, perché il foglio portato con sé li definisce.

L'anteprima della migrazione dice quante variabili arrivano e dove.

**Tornare indietro, per esempio da Core Framework ad Automatic.css.** La migrazione funziona nei due versi, quindi si torna con gli stessi passi.
1. Se il framework di prima è spento, la sezione lo segnala e offre **Riattiva**. Servono `manage_options`, `xb_build` e `activate_plugins`. Si attiva solo uno dei plugin noti, trovato per titolo: mai un percorso arrivato dal modulo. Le impostazioni del framework sono rimaste dove erano.
2. **Passa a:** si sceglie il framework, si guarda l'anteprima della migrazione, poi **Prepara in sviluppo** e si pubblica. Nomi di variabili e classi tornano quelli di prima: per esempio `bg-light` → `bg--light` e `--space-2xl` → `--space-xxl` (`MigrationTest`).
3. Il foglio di compatibilità ACSS creato da «Da Automatic.css a Core Framework» (qui sotto) si spegne da solo quando il framework attivo torna Automatic.css, che ha le sue regole vere. Il progetto importato resta nell'elenco di Core Framework, se un giorno si torna di nuovo.

In alternativa, il ripristino della release di prima del passaggio riporta indietro contenuti e framework in un colpo.

## Da Automatic.css a Core Framework, tutto compreso
Implementazione: `plugin/src/Framework/AcssToCore.php`, sezione **Impostazioni → Cambio di framework**. Test: `plugin/tests/Integration/AcssToCoreTest.php`.

Il sistema di design passa a Core Framework senza lasciare indietro nulla. Histeon legge quello che ACSS ha generato per il sito (i suoi file in `uploads/automatic-css/` e le recipes tramite la sua API pubblica) e **non modifica nessuno dei due plugin**. Il risultato:

1. **Un nuovo progetto di Core Framework**, copia di quello selezionato, che diventa il progetto attivo. Contiene:
   - i **colori** di brand e di stato di ACSS (`primary`, `secondary`, `tertiary`, `accent`, `success`, `danger`→`error`, `warning`, `info`), convertiti da `oklch`/hex/rgb in `hsla` per il sistema colori di Core Framework, che ne genera tinte e ombre;
   - il foglio **«Automatic.css — variabili»** con tutte le variabili di ACSS e i loro valori. Ogni `var(--…)` di ACSS continua a funzionare. Dove i nomi coincidono valgono i valori di ACSS, perché il foglio arriva dopo le variabili di Core Framework.
2. **Il foglio di compatibilità** (`uploads/xb-css/acss-compat-<hash>.css`) con le regole di ACSS che il sito usa davvero:
   - gli stili di base degli elementi;
   - le regole delle classi presenti nelle pagine pubbliche, lette come le vede chi visita (fino a 60 pagine), comprese tema, plugin e skip link.

   Histeon lo carica dove ACSS caricava il suo CSS, cioè **dopo gli stili del sito**: a parità di specificità ogni regola vince o perde esattamente come prima. Vale anche nella tela del builder e nell'editor a blocchi.
3. **Le recipes di ACSS** diventano completamenti dell'editor CSS di Histeon. Si scrive `@` più il nome, all'inizio di una dichiarazione, e la recipe si espande. Le recipes sono ripulite: nome semplice, niente markup, graffe bilanciate, al massimo 4 KB.

Dopo l'importazione:
1. Apri Core Framework, apri la sezione **Stylesheets** e premi **Save changes**. Core Framework genera il suo CSS solo quando rileva una modifica, e aprire Stylesheets basta.
2. In Histeon passa a Core Framework con **Prepara in sviluppo** e pubblica.
3. Disattiva Automatic.css.

Il progetto precedente di Core Framework resta nel suo elenco.

**Prova su un sito di prova.** Sono stati misurati gli stili calcolati di 400 elementi della home (13 proprietà ciascuno) con ACSS e poi dopo la migrazione, con ACSS disattivato:
- con le sole variabili e classi restavano 96 differenze;
- con le regole di base e il foglio caricato dopo gli stili del sito ne restano 9, tutte di elementi con una transizione CSS sul colore misurati durante il caricamento. A riposo prendono il valore delle variabili del sito stesso, che la migrazione non tocca.
