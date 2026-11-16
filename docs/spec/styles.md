# Stili Histeon — v0.2 (CSS su richiesta)

Implementazione: `plugin/src/Styles/`. Test: `plugin/tests/Unit/CssCompilerTest.php`, `plugin/tests/Integration/RenderTest.php` e, per il gestore dei selettori, `plugin/tests/Unit/SelectorsTest.php` e `plugin/tests/Integration/SelectorsTest.php`.

## 1. Dove vivono
| Opzione | Contenuto |
|---|---|
| `xb_styles` | Lista di `{id, selector, css}`: una voce per selettore modificato nel pannello CSS |
| `xb_global_styles` | Lista di `{id, css}`: fogli globali, sempre stampati |

Gli stili sono sotto versioning (commit e release), con le voci in questo formato.

## 2. Cosa si scrive nel pannello CSS
Il **corpo** della regola del selettore selezionato:
```css
padding: var(--space-m);
&:hover { color: var(--primary); }
@media (width > 48em) { padding: var(--space-l); }
&__title { font-weight: 700; &--big { font-size: 2em; } }
&--dark { background: #000; }
```
- Il nesting nativo (`&:hover`, `& .figlio`, `@media` annidate) viene emesso così com'è: i browser lo supportano dal 2023.
- **Concatenazione BEM**: una regola annidata il cui selettore inizia con `&__` o `&--` diventa una regola sorella con il nome concatenato. L'esempio sopra produce `.card__title`, `.card__title--big` e `.card--dark`, anche in modo ricorsivo e su liste di selettori (`.a, .b` + `&__x` → `.a__x, .b__x`). Anche dentro una `@media`, `@container`, `@supports` o `@layer` annidata: `@media (…) { &__title { … } }` diventa `@media (…) { .card__title { … } }`, e il resto del blocco resta annidato dov'era.
- I commenti vengono rimossi; stringhe e parentesi sono rispettate.
- Ogni `<` diventa `\3C `, così il CSS non può chiudere l'elemento `<style>`.

**Classi definite nei fogli globali** (siti importati, CSS scritto a mano): selezionando la classe, la scheda **Nel foglio** mostra **solo le regole che la nominano**, una per editor, con il loro contesto (`@media …`) e formattate una dichiarazione per riga. Ogni modifica torna nel foglio esattamente al posto di quella regola, e solo se il testo lì è ancora quello mostrato: altrimenti le regole vengono ricaricate. Una regola con graffe non bilanciate non viene scritta, così non può chiudere il blocco che la contiene; finché resta così conta come non salvata (`html-panel.md`, «Testo rifiutato»). Il foglio intero resta disponibile dalla sua pillola.

## 3. Quali stili finiscono in una pagina (CSS su richiesta)
Un CSS arriva in pagina **solo se c'è un elemento che può usarlo** (ADR 0006).

**Fogli globali in un file in cache** (Impostazioni, `xb_css_global_file`, spento per difetto): i fogli globali, interi e con le media query con nome risolte, vanno in `uploads/xb-cache/global-css-<md5>.css` (riscritto solo quando cambiano) e la pagina li collega con `<link id="xb-global-css">` prima del suo `<style id="xb-styles">`, che resta su richiesta con le sole voci dei selettori. Si torna al CSS in linea: per un'anteprima di stili non pubblicati, se un foglio ha un `url()` relativo (da `xb-cache/` punterebbe altrove), se la cartella non si scrive, durante l'export statico di Histeon (che da uploads copia solo i media pubblicati) e con Static Bridge attivo. Il framework intero in un file c'è già: «Framework completo».

**Motore:** `CssRules`, nel plugin.
- Il foglio viene diviso in regole, e per ogni regola si calcola cosa serve in pagina.
- Una regola si tiene se **almeno uno** dei selettori della sua lista può corrispondere. Un selettore può corrispondere quando **tutte** le classi (`.x`) e gli id (`#x`) che nomina sono presenti.
- Non escludono mai una regola:
  - i selettori di tipo (`h1`) e di attributo (`[class*="grid--"]`);
  - ciò che sta dentro `:not()`, `:is()`, `:where()` e `:has()` (regola conservativa: nel dubbio si tiene).
- `@media`, `@supports`, `@container`, `@layer {…}` e `@scope` vengono attraversate, e cadono se restano vuote.
- Le altre at-rule (`@font-face`, `@keyframes`, `@property`, `@import`, `@layer a, b;`) e `:root` restano sempre.
- Le regole annidate seguono la regola che le contiene.
- **Si stampano solo istruzioni chiuse e lette senza dubbi** (§5). Ogni foglio globale e ogni stile si legge per conto suo. Al primo livello un'istruzione è una regola `@` che finisce con `;`, o un preambolo con il suo blocco. Non si stampano:
  - una `}` che non chiude niente e il testo sciolto fuori da un blocco: si saltano e la lettura va avanti;
  - lo stesso testo sciolto dentro un gruppo che tiene solo regole (`@media`, `@supports`, `@container`, `@layer {…}`, `@starting-style`, `@document`, quando attorno non c'è un `@scope`). Dentro `@scope` un blocco può avere anche dichiarazioni, e lì il testo resta com'è scritto;
  - dal primo punto in cui questa lettura e un browser possono non concordare su dove finisce una stringa, un indirizzo o un blocco, tutto il resto di quel foglio: per esempio una stringa o un commento non chiusi, una parentesi spaiata, una graffa o un `;` dentro una parentesi.
- Un blocco lasciato aperto in fondo al foglio si chiude lì, come fa un browser. Fra un'istruzione e l'altra cadono solo i commenti (restano quelli di licenza `/*!`) e gli spazi del CSS; attorno a un preambolo cadono solo gli spazi che una barra rovesciata non protegge (`.a\ ` è il nome «a» più uno spazio, e resta com'è).
- Il costo del saltare: su un foglio con un errore un browser scarta la regola che segue un testo sciolto o un `;` da solo, la pagina invece la tiene. Quali regole valgono può quindi differire dal foglio scritto; dove finisce una stringa, un indirizzo o un blocco no.
- Conseguenza: un foglio scritto a mano con un errore (una virgoletta non chiusa) perde dalla pagina ciò che segue l'errore in quel foglio.

**Cosa conta come presente.**
- Le classi e gli id dell'**HTML finale della pagina**. La pagina passa da un buffer di output e viene letta con `WP_HTML_Tag_Processor`.
- Contano quindi anche `{#raw}`, i core block, le parti del tema e i widget, non solo gli elementi Histeon.
- Più la **safelist** (Impostazioni → Histeon), per classi e id che uno script aggiunge dopo il caricamento: `.is-open`, `#modal`, `.is-*`.

**A cosa si applica.**
- **Stili Histeon** (voci per selettore e foglio globale): sempre. Ogni regola sollevata con BEM decide per conto suo, quindi `.card--dark` arriva solo se la pagina usa `card--dark`. Il CSS sostituisce un marcatore casuale nell'head e diventa `<style id="xb-styles">`.
  Le regole analizzate degli stili pubblicati restano in cache fra una richiesta e l'altra, come JSON in `uploads/xb-cache/site-css-*.json`. La chiave comprende gli stili salvati, le media query con nome, il CSS di runtime, le versioni dell'analizzatore e del plugin e una generazione dei media (`xb_css_media_gen`), che cresce quando cambia un allegato a cui il CSS può puntare. Una release, quindi, produce una chiave nuova e sostituisce il file. Gli stili di un'anteprima non entrano mai in cache.
- **Foglio del framework attivo**, in modalità "su richiesta" (predefinita; l'alternativa è "completo"):
  - il `<link>` del framework diventa `<style id="xb-framework-N">` con le sole regole usate;
  - gli `url()` e gli `@import` relativi vengono resi assoluti;
  - il foglio analizzato è salvato in cache come JSON in `uploads/xb-cache/`, ed è un dato, mai codice;
  - anche le liste di variabili e classi del framework attivo che il builder riceve (`GET /framework`) si costruiscono una volta per versione: file del framework, ricette, plugin e lingua (`uploads/xb-cache/fw-lists-*.json`). Arrivano con un `ETag` e `Cache-Control: private, no-cache`, quindi un'apertura del builder a framework invariato riceve un 304;
  - si accettano solo file `.css` locali, dentro `wp-content` e sotto i 5 MB. In ogni altro caso il `<link>` resta com'è.

**Senza buffer** (filtro `xb_css_on_demand` a false, richieste non HTML): vale la presenza registrata dal renderer Histeon. Gli stili si stampano in `<style id="xb-styles">` nell'head e, per ciò che viene renderizzato dopo, in `<style id="xb-styles-late">` nel footer.

**Editor e canvas:** ricevono sempre tutto. Se l'elaborazione fallisce, la pagina riceve tutti gli stili Histeon: la pagina non va mai persa. In questi due casi il CSS è il testo unito di fogli e stili (`StyleCollector::all_css()`), non le istruzioni di `CssRules`: un foglio con un errore lì si vede ancora.

**Esempio misurato sulla home di sviluppo:** `core_framework.css` passa da 49.869 byte a 6.349 inline, senza differenze negli stili calcolati (59 elementi confrontati).

- Gli stili Histeon non sono dentro un `@layer`: devono prevalere sugli stili del tema, che sono senza layer. Rispetto al framework CSS (ACSS e Core usano dei layer) vale la regola dei layer: a parità di importanza vince ciò che sta fuori da un layer.

## 4. Gestore dei selettori (scheda Selettori)
Implementazione: `plugin/src/Styles/Selectors.php`, `editor/src/components/SelectorsPanel.svelte`. Decisioni: ADR 0007.

La scheda **Selettori** del builder elenca ogni voce di `styles` con:
- ricerca per nome e ordinamento per nome, uso o dimensione del CSS; filtro "solo inutilizzati";
- per ogni selettore: dove è usato, il CSS (modificabile come nel pannello CSS, si salva con il builder), rinomina ed eliminazione.

Elenca anche le **classi definite nei fogli globali** che non hanno una voce propria (dopo un'importazione il CSS sta quasi tutto in un foglio globale). Per ognuna: il foglio, quante regole la nominano, quelle regole in sola lettura (fino a 8 KB, dentro le loro at-rule) e l'uso, calcolato come per le voci con il solo requisito `.classe`. Si possono rinominare (la rinomina riscrive già i fogli globali), non eliminare da qui: si tolgono modificando il foglio. Al massimo 3000 classi.

**Cosa conta come uso.** Si leggono le **teste dev** (nessun commit, nessun documento messo sotto versioning per il solo fatto di leggerlo) dei documenti tracciati e di ogni pagina, articolo e componente con blocchi Histeon (al massimo 2000; oltre, conteggi parziali e scritture disattivate):
- le classi e gli id **statici** degli `xb/element`; una classe completata da un'interpolazione (`card--{props.variant}`) conta per il suo prefisso;
- le classi nell'HTML libero (`{#raw}`, core block);
- i **template e le parti del tema** a blocchi (header e footer di `xb-blank` sono markup Histeon);
- la **safelist**.

Una voce è usata se una delle sue regole può applicarsi (stessa semantica di §3, regole sollevate con BEM comprese: `.card` con `&__title` è usata da una pagina che ha solo `card__title`). Le voci senza classi né id (`h1`) valgono "sempre". I documenti che l'utente non può modificare (`edit_post`) contano ma non vengono elencati. Non si vedono le classi che arrivano da props, script o widget: per questo eliminare e rinominare restano commit dev, annullabili.

**Rinomina** (`POST /selectors/rename`). Rinominare `card` in `box` cambia la classe e i suoi **figli BEM** (`card__title` → `box__title`, `card--dark` → `box--dark`; `card-alt` e `cardigan` no):
- nei selettori delle voci, nei selettori annidati nei loro corpi e nei fogli globali. Dichiarazioni, stringhe, commenti, `url()`, selettori di attributo e preludi delle at-rule restano come sono scritti;
- negli attributi `class` statici di ogni documento; le interpolazioni restano intatte, e così un token attaccato dopo un'interpolazione (`{x}-card`);
- come **commit dev**, uno per documento più uno per gli stili, in un'unica transazione. Va online con la prossima release.

La rinomina è rifiutata (**409**, con l'anteprima che spiega il motivo) se:
- il nuovo nome, o uno dei nomi derivati, è già usato da stili o documenti;
- la classe compare in documenti che l'utente non può modificare, o nel tema;
- il commit risultante sarebbe non fidato e un elemento rinominato non supera il livello restricted (ad esempio un `onclick` scritto da un amministratore). Rinominare cambia gli attributi, quindi l'elemento non verrebbe più riconosciuto come già approvato e il filtro lo toglierebbe.

**HTML libero** (`{#raw}` e blocchi core o di altri plugin): l'anteprima elenca ogni attributo `class` che cambierebbe, con il testo prima e dopo (`html`); per un blocco core `className` e le classi del suo markup sono una sola voce, perché cambiano insieme. La rinomina riscrive solo le voci scelte (`html: [{id, before}]`), ciascuna ricontrollata sul testo dell'anteprima (diverso: 409, non si scrive nulla), nella stessa transazione. Solo chi può scrivere HTML senza filtri (`htmlAllowed`); gli altri le vedono come «da rinominare a mano». Gli id restano fuori. Con `dryRun: true` la rotta restituisce solo l'anteprima.

**Safelist dal gestore** (`POST /selectors/safelist {name, on}`, solo per chi può cambiare le impostazioni, `manage_options`): una classe o un id aggiunti da script, props o widget entrano o escono dalla safelist delle Impostazioni (stesse regole, stessa opzione), registrati come modifica delle impostazioni. Così non risultano più «inutilizzati» e restano sempre nel CSS.

**Eliminazione** (`POST /selectors/delete`): solo voci inutilizzate (né documenti, né tema, né safelist, né "sempre"), tutte o nessuna, in un commit dev degli stili.

**Fiducia.** Le scritture passano da `Versioning::save_dev()` con `inherit_trust`: chi non ha `unfiltered_html` passa dal filtro restricted come per ogni salvataggio, e un commit che sostituisce una testa dev non fidata resta **non fidato** anche se lo crea un amministratore (ADR 0005 R1).


## 5. Sicurezza dell'elemento `<style>`
Il CSS stampato in `<style>` neutralizza solo `</` (diventa `\3C /`), l'unica sequenza che può chiudere un elemento di testo grezzo. Il resto resta com'è, compreso `<`, necessario alle media query di intervallo (`@media (width < 48em)`). Prima veniva escapato ogni `<`, e quelle query non funzionavano. La regola vale sia nel compilatore PHP sia nel suo gemello TypeScript, e i vettori condivisi in `packages/spec/vectors/css.json` la verificano.

**Risorse nei fogli scritti senza `unfiltered_html`** (`Documents::restricted_styles()`). Il filtro vale per chi non ha `unfiltered_html`, per gli agenti, per i commit importati, per i clip e per l'incolla; un profilo del CMS che il filtro cambierebbe si ferma («The style profile contains CSS which the receiving site would remove»). Che cosa fa:

- toglie ogni `@import` e ogni regola `@namespace`, comunque siano scritte (maiuscole, escape), dalla `@` al primo `;` compreso, o fino alla fine del foglio;
- un `url()` o una stringa di `image-set()` restano solo se puntano a `http(s)` di questo sito o sono relativi; un `url()` che non lo è diventa `url()`, un `image-set()` diventa `none`. `src()` segue le regole di `image-set()`. Un `image-set()` o `src()` che ha fra le opzioni una funzione diversa da `url()`, `type()`, `calc()`, `min()`, `max()`, `clamp()` e dai gradienti (`var()`, `attr()`, `env()`, una funzione personalizzata) diventa `none`, perché l'indirizzo arriverebbe da altrove;
- legge il foglio come lo legge il browser: gli escape decodificati (un nome scritto con un escape è lo stesso nome), le stringhe e i commenti, le funzioni lasciate aperte fino alla fine del foglio, i nomi più lunghi che finiscono come una funzione. Si legge anche tutto il testo, stringhe comprese, e vale ciò che una delle due letture toglie;
- dopo ogni rimozione rilegge il testo, al più cinque volte; se ancora cambia, la voce resta senza CSS. Ciò che il filtro restituisce, ripassato dal filtro, non cambia;
- giudica ogni voce anche come la stampano il compilatore e la pagina (con `.xb-restricted` e col suo selettore, con ogni `</` scritto `\3C /`, con i media collegati presenti e assenti): se in una di queste forme resterebbe qualcosa da togliere, la voce resta senza CSS;
- un CSS, un selettore o un media collegato che non è UTF-8 valido non è giudicato: la voce resta senza CSS.

Un foglio innocuo resta byte per byte, con i suoi escape e le sue mappature dei media. Il CSS in linea (`style="…"`) non cambia: nessun `url()`.

**Media collegati.** I media collegati a una regola o a un foglio (`assets`) si scrivono nel CSS dopo il filtro, a ogni stampa (`CssAssets::rewrite()`), e solo negli `url()` che anche il filtro legge come un indirizzo chiuso, nello stesso punto (`Documents::closed_urls()`); altrove il riferimento resta com'è scritto. Ciò che scrive è `url("…")` con l'indirizzo del media e il `?…` o `#…` del riferimento, con ogni byte codificato con `%` tranne lettere, cifre e `- . _ ~ : / ? # & = + %`; oppure `url()` se il media non c'è, o se il suo indirizzo ha `@` o parentesi quadre prima del percorso (nome utente, host IPv6). Limiti: il giudizio usa un indirizzo sostituto di questo sito al posto di ogni media, quindi un media che la libreria dà su un altro host resta stampato; il giudizio vale al salvataggio e alla release di una versione non fidata, e gli stili già online non sono rigiudicati.

**Disegni SVG in linea.** Un `url()` resta anche quando contiene un disegno SVG inerte, come lo scrive il convertitore di Histeon Publish: `url("data:image/svg+xml;charset=utf-8,%3Csvg…")`. La regola, in `Security\InlineSvg`:

- tipo `image/svg+xml` (maiuscole e spazi attorno a `;` come li legge il browser), con al più `charset=utf-8` o `utf8` e `base64`; nessun altro tipo `data:`;
- corpo di soli caratteri URL: niente virgolette, barre rovesciate, parentesi, spazi, `#`, `<`, `>` o graffe (un SVG scritto in chiaro, come nei fogli di WordPress, va codificato con `%`);
- scritto così nel foglio: un `data:` che compare solo togliendo escape o commenti si toglie;
- al più 196 672 caratteri scritti e 64 KiB decodificati (il tetto di Publish; i disegni dei fogli di WordPress stanno sotto 1,5 kB), al più 64 disegni per foglio (i fogli di WordPress e dei plugin più diffusi ne hanno al più 23): oltre, quel `url()` si toglie come gli altri;
- decodificato (`%`, poi base64) deve essere UTF-8 valido, senza `<!` né `<?`, e un documento XML ben formato, letto da libxml senza rete né DTD, con radice `svg`, al più 2000 elementi di un elenco chiuso di forme statiche (lo stesso di Publish: niente `script`, `foreignObject`, `style`, `image`, `a`, animazioni), nessun attributo `on*`, `href`, `xlink:href` e `src` solo `#id`, namespace solo SVG e XLink, e valori e testi senza `url()` diversi da `url(#id)`, `@import`, `data:`, schemi di script, `expression`, `image-set`.

In `image-set()` e in `@import` un `data:` resta vietato, e un disegno `data:` resta solo in un `url()` chiuso.

**Che cosa costa togliere `@import` e `@namespace`.** Va via anche ciò che li nomina soltanto, in un commento, in una stringa o in un indirizzo, fino al primo `;`, e il commento o la stringa possono restare aperti. Allora sulla pagina (`CssRules`, §3) di quel foglio non si stampa più niente da lì in poi; nell'editor, nel ripiego della pagina e nel file dei fogli globali, che stampano fogli e stili uniti, possono perdere effetto anche i fogli e gli stili stampati dopo. Una `@namespace` con prefisso (`@namespace svg url(…);`) si toglie lo stesso, e i selettori `svg|a` di quel foglio non valgono più. Se un foglio di un amministratore contiene una `@namespace`, chi non ha `unfiltered_html` riceve 409 a `POST /selectors/delete`, e un suo salvataggio degli stili la toglie dal foglio. Il filtro gira quando si scrive: i fogli salvati con una versione di Histeon che la lasciava non si rigiudicano da soli, quindi un amministratore li controlli e la tolga dai fogli globali.

**Locale di PHP e testo non UTF-8.** Il filtro degli stili, e ogni controllo che decide che cosa passa (azioni dei moduli, anteprima a script bloccati, ricette e token del sito, trasferimento dei profili), legge le lettere ASCII allo stesso modo qualunque sia il locale di PHP. Una versione non fidata non passa dalla riparazione del testo di WordPress: `Store::commit()` la scrive con `json_encode()`, quindi l'esito non dipende dalle impostazioni di mbstring. Ogni sequenza non UTF-8 diventa U+FFFD (`JSON_INVALID_UTF8_SUBSTITUTE`), nei valori e nelle chiavi, e il testo valido resta uguale byte per byte. Una versione non fidata che non si può scrivere in JSON per un altro motivo (troppi livelli, `INF`, `NAN`) è rifiutata e niente è tenuto. Le versioni fidate passano da `wp_json_encode()`. Prove: `SanitizerTest`, `CommitReadBackTest`, `TurkishLocaleTest`; quest'ultima si salta dove manca un locale turco vero.

**Che cosa stampa la pagina del testo giudicato.** Il filtro giudica il testo salvato e quello che ne stampa il compilatore; la pagina ne stampa solo le regole che servono, attraverso `CssRules` (§3). Perché il giudizio valga anche per ciò che si stampa:

- `CssRules` legge le stringhe con la regola del compilatore (`CssCompiler::string_end()`): ciò che il compilatore stampa come una regola è una regola anche per lui;
- ogni foglio globale e ogni stile si legge per conto suo (`StyleCollector::rules()`): ciò che uno lascia aperto, o chiude una volta di troppo, finisce con lui;
- si stampano solo istruzioni chiuse e lette senza dubbi (§3), e fra un'istruzione e l'altra si tolgono solo commenti e spazi del CSS.

Lo scopo è che ogni pezzo stampato sia un pezzo contiguo, allo stesso livello, del testo giudicato, e che niente di ciò che è stampato prima o dopo ne cambi la lettura: è provato su fogli fatti a caso e nei browser. Al primo livello e dentro i gruppi di sole regole una regola lasciata fuori non cambia la lettura di quelle che restano. Limite noto: dentro `@scope`, dove un blocco può avere anche dichiarazioni, le regole che la pagina lascia fuori possono cambiare la lettura di quelle che restano nello stesso blocco. L'effetto resta dentro un'istruzione sola di un foglio solo, il filtro giudica gli indirizzi allo stesso modo anche lì, e nessuna richiesta va a un altro sito.

Limiti: l'editor, il ripiego della pagina (`PageStyles::fallback()`) e il file dei fogli globali (`global_file_url()`) stampano il testo unito di fogli e stili, non passato da `CssRules`; `Carry::rules()` unisce più file del framework prima di leggerli, quindi un dubbio nel primo ferma anche gli altri.

## 6. Media e container query con nome
- Si definiscono nella sezione Stili e selettori → **Media query con nome** (impostazioni del sito, con versioni e release; `NamedQueries.php`).
- Valori predefiniti: `mobile` `(width < 40em)`, `tablet` `(width < 64em)`, `desktop` `(width >= 64em)`.
- Si usano ovunque con la sintassi della bozza CSS Custom Media: `@media (--tablet)` oppure `@container card (--tablet)`.
- Histeon le risolve quando costruisce il CSS, sul sito e nell'anteprima del builder, con la versione di sviluppo. Un nome sconosciuto resta com'è scritto e il browser ignora quella regola.
- La condizione accetta solo i caratteri di una condizione: niente `{`, `;`, `url(`, `image-set`, `src(`, `//`, commenti, e le parentesi devono essere bilanciate. Si ricontrolla a ogni sostituzione.
- La sostituzione avviene dopo il filtro degli stili (§5), quindi solo dove non può comporre niente: nel preludio di una regola `@media` o `@container` vera (all'inizio del foglio o dopo `{`, `}` o `;`, fuori da stringhe, commenti e parentesi); solo se quel preludio, che finisce al primo `{`, `;` o `}`, non ha stringhe, commenti, escape o `url(`; e solo per un `(--nome)` isolato, cioè subito dopo `@media` o dopo un carattere che non continua un nome, e non seguito da un carattere di nome né da `(`. Scrivi quindi `(--a) and (--b)`, con lo spazio dopo la parentesi. `@media(--tablet)` diventa `@media (width < 64em)`. Le stesse regole valgono nel builder (`resolveNamedQueries` di `@xb/dsl`), con gli stessi vettori.

## 7. Azioni rapide
Nel pannello CSS di una classe, la voce **Azioni rapide** offre:
- **layout:** flex in riga o in colonna, griglie a 2, 3 o 4 colonne, griglia automatica, centra, spazio tra, pila centrata;
- **spazi:** gap, padding o margine con la scala del framework (`--space-*`) se c'è, altrimenti in rem;
- **px → rem:** converte i valori in px, lasciando invariati 0, 1px, stringhe e `url()`.

Le dichiarazioni esistenti vengono aggiornate al loro posto, le regole annidate restano come sono.

Nell'editor ci sono anche:
- **abbreviazioni** all'inizio di una dichiarazione: `df`, `dg`, `fdc`, `jcc`, `aic`, `pic`, `mxa`, `w100`, `ofc`…;
- **completamento** dei nomi delle query dopo `@media (`.

## 8. Anteprima a qualsiasi larghezza
L'anteprima non ha dispositivi fissi: la pagina si disegna alla larghezza che scegli tu, e una media query parte proprio da lì.
- **Maniglie** ai due lati dell'anteprima: si trascinano, e il ridimensionamento resta centrato. Si usano anche da tastiera: frecce ±10px, Maiusc+frecce ±100px, Inizio per la larghezza minima, Esc o Fine per «Adatta». Durante il trascinamento la larghezza compare sopra la pagina.
- **Limite delle maniglie:** si fermano al bordo dell'anteprima, sia trascinando sia da tastiera, quindi non riducono mai la pagina per sbaglio.
- **Campo in px** nella barra, da 240 a 3840. **Adatta** torna alla larghezza dello spazio disponibile. La scelta resta in questo browser.
- **Più larga dello spazio:** una larghezza maggiore si sceglie solo di proposito, digitandola o con un punto di rottura, per vedere un layout più largo dello schermo. La pagina viene allora ridotta e la barra dice «ridotta al …%»; i breakpoint del sito vedono comunque la larghezza vera.
- **Punti di rottura del sito:** i pulsanti accanto al campo vengono dalle query con nome (§6). Per esempio `tablet 1024` viene da `(width < 64em)`. Un clic porta l'anteprima lì.
- **Pannello CSS → «A 720px»:**
  - **@media fino a qui:** aggiunge al selettore un blocco vuoto `@media (width <= 720px) { }`;
  - **@media da qui in su:** aggiunge `(width >= 720px)`;
  - **@container fino a qui:** aggiunge `@container (width <= 720px)`, che segue la larghezza del contenitore e quindi serve un antenato con `container-type`.

  Un blocco identico già presente non viene duplicato.

Implementazione: `editor/src/lib/canvasWidth.ts`, `editor/src/components/Canvas.svelte`, `CssPanel.svelte`. Test: `editor/test/canvasWidth.test.ts`, `e2e/specs/canvas-width.spec.ts`.

## 9. Mini GUI
Una barra compatta sopra l'editor CSS, che scrive nel codice del selettore le dichiarazioni più usate mentre guardi l'elemento nell'anteprima. Il codice resta la verità.

**Una riga sola.** Stato, larghezza, i gruppi di proprietà e «Altro» stanno sulla stessa riga. Il gruppo aperto aggiunge solo le sue righe; un clic sul gruppo aperto lo chiude, e resta la riga (la scelta si ricorda in questo browser). «Altro» (query alla larghezza dell'anteprima e azioni rapide) si apre sopra l'editor, senza spingerlo in basso.

**Dinamica.** Il gruppo mostra solo ciò che ha effetto adesso (`relevant()` in `miniGui.ts`):
- direzione, a capo, allineamenti e `gap` solo con `display: flex` o `grid`, le colonne solo con `grid`;
- `inset` e `z-index` solo con una `position`;
- `object-fit` solo per immagini, video e iframe.

Una proprietà che ha già un valore si vede sempre; «+N proprietà che ora non servono» mostra le altre. Un puntino corallo segna i gruppi che hanno valori.
- **Comportamento dei pulsanti:** ogni pulsante scrive una dichiarazione vera nel CSS del selettore, e l'anteprima la applica subito.
- **Valore attivo:** è evidenziato; un secondo clic toglie la dichiarazione.
- **Valori scritti a mano:** compaiono come un pulsante in più, che si può togliere.
- **Cosa resta intatto:** si leggono e scrivono le dichiarazioni del blocco scelto (§10: il primo livello, uno stato, una larghezza o un blocco `@media`/`@container` già scritto); gli altri blocchi annidati restano come sono.

Schede:

| Scheda | Proprietà |
|---|---|
| Layout | `display`, `flex-direction`, `flex-wrap`, `grid-template-columns` (2, 3, 4, auto), `justify-content`, `align-items`, `gap` |
| Spazi | `padding`, `padding-block`, `padding-inline`, `margin-block`, `margin-inline` |
| Dimensioni | `width`, `max-width`, `min-height`, `aspect-ratio`, `object-fit` |
| Testo | `font-size`, `font-weight`, `line-height`, `text-align`, `text-wrap`, `color` |
| Colori e bordi | `background`, `border-radius`, `box-shadow`, `border`, `opacity` |
| Posizione | `position`, `inset`, `z-index`, `overflow`, `container-type` |

**Scale dal framework.** Spazi, dimensioni del testo, raggi, ombre e colori vengono dalle variabili del framework attivo, sempre come `var(--…)`. Si riconoscono dal nome (`space-*`, `text-*`, `radius-*`, `shadow-*`, `primary…`), così funzionano con Core Framework, ACSS e i token. Se il framework non ha nomi propri per una scala si usano i token `--xb-*`. I colori hanno un campione.

**Dove si trova:** nel pannello **CSS**, con un elemento selezionato che ha una classe. Il pulsante **Mini GUI** la apre e la chiude; la scelta resta in questo browser.
- **Vista «Nel foglio»:** c'è anche quando la classe ha le sue regole nel foglio globale, come nei siti importati. Scrive sempre nel CSS proprio della classe, e dopo il clic il pannello passa a quella vista per mostrarne il codice.
- **Elemento senza classi:** il pannello chiede di aggiungerne una, perché la mini GUI lavora sulla classe.

Implementazione: `editor/src/lib/miniGui.ts`, `editor/src/components/MiniGui.svelte`. Test: `editor/test/miniGui.test.ts`, `e2e/specs/mini-gui.spec.ts`.



**Stile compatto**. Accanto al pulsante «Mini GUI» si sceglie lo stile: quello di Histeon (gruppi e righe) o quello compatto. La scelta resta in questo browser (`xb-mini-gui-style`, valore `compact`; il valore `etch` salvato dalle versioni precedenti vale come `compact`). Lo stile compatto è una striscia di gruppi di pulsanti piccoli, e compaiono solo i gruppi che servono:
- Display;
- Griglia, se l'elemento è una griglia;
- Distribuisci, se è flex;
- Allinea, se è flex o griglia;
- Testo, sugli elementi di testo;
- Immagine, su immagini e video;
- Movimento, negli stati come `:hover`;
- Transizione, Posizione e Box.

Ogni pulsante scrive le sue dichiarazioni con un valore di partenza, un token Histeon con valore di riserva, e il valore si modifica poi nel codice. Un secondo clic su un pulsante attivo toglie le sue dichiarazioni; un pulsante con il punto vuol dire che la proprietà c'è già, con un altro valore. Scrive dove scrive la Mini GUI di Histeon: stato, larghezza, CSS proprio della classe. I gruppi sono in `editor/src/lib/miniGuiCompact.ts`.

## 10. Stati e larghezze a un clic
Nella riga della Mini GUI, due menu scelgono **dove** scrive (● segna quelli che hanno già dichiarazioni):
- **Stato:** Normale, `:hover`, `:focus-visible`, `:active`, `::before`, `::after`;
- **Larghezza:**
  - Tutte;
  - le media query con nome del sito (`@media (--tablet)`);
  - la larghezza che mostra la tela (`@media (width <= 1104px)`);
  - i blocchi `@media` e `@container` già scritti nel CSS del selettore, anche a mano (`widthPreludes`).

Gli stessi menu ci sono anche nella vista **Nel foglio** di una classe con le regole nei fogli globali: la Mini GUI scrive nel CSS proprio della classe, nello stato e alla larghezza scelti, e il pannello passa a quella vista.

**Come scrive**
- **Annidamento:** la Mini GUI legge e scrive le dichiarazioni dentro il blocco annidato giusto: `&:hover { … }`, `@media (--tablet) { … }`, o uno stato a una larghezza (`@media (--tablet) { &:hover { … } }`).
- **Blocchi mancanti o vuoti:** il blocco si crea se manca, e sparisce quando resta vuoto.
- **Il resto del CSS:** commenti e formattazione restano come scritti, perché il blocco viene trovato nel testo e modificato sul posto.
- **Editor:** scegliendo un contesto, l'editor seleziona il blocco.

**Alla larghezza della tela.** Con la tela portata a una larghezza (trascinando le maniglie, scrivendo i pixel o con un punto di rottura), le modifiche di stile vanno da sole nella `@media` di quella larghezza:
- la query con nome del sito che vale lì: la più stretta tra quelle «fino a» (`(width < 40em)`), altrimenti la più larga tra quelle «da» (`(min-width: 1200px)`); se nessuna vale, `@media (width <= 600px)` alla larghezza della tela (`widthPrelude()` in `canvasWidth.ts`);
- valgono la Mini GUI e le maniglie degli spazi sulla tela;
- sopra la tela una pillola dice dove vanno («Modifiche in @media (--mobile)»), e un clic la spegne: allora tutto vale per tutte le larghezze;
- nel menu Larghezza, «Come la tela» torna a seguirla; scegliere un'altra larghezza a mano smette di seguirla;
- «Adatta» (la tela larga quanto lo spazio) torna a scrivere per tutte le larghezze.

**Anteprima dello stato:** mentre uno stato è scelto, la tela mostra l'elemento selezionato in quello stato (per esempio come se il mouse ci fosse sopra), con le sue dichiarazioni. Il CSS del sito non cambia: è solo una regola dell'anteprima su `data-xb-uid`.

Implementazione: `editor/src/lib/cssContext.ts`, `CssPanel.svelte`. Test: `editor/test/cssContext.test.ts`, `e2e/specs/css-context.spec.ts`.

## 11. Dal foglio al selettore, come in SCSS
Nei siti importati quasi tutto il CSS sta in un foglio globale, dove la stessa classe si ripete: `.card`, `.card:hover`, `.card__title`, `.card--dark`, e di nuovo in ogni `@media`. Il selettore invece si scrive una volta sola, annidato come in SCSS:

```css
padding: 1rem;
&:hover { … }
&__title { … }
@media (width > 40em) { padding: 2rem; &__title { … } }
```

**Come SCSS nel selettore.** Selezionando un elemento la cui classe ha regole nel foglio, la vista «Nel foglio» mostra in cima quelle regole già raccolte in questa forma, con «Porta nel selettore». Il clic toglie le regole dai fogli e le mette in testa al CSS proprio della classe (prima di ciò che c'era: i fogli venivano prima), in un solo passo da annullare (Ctrl+Z); una voce nuova va prima delle voci esistenti, per lo stesso motivo; le `@media` rimaste vuote spariscono. Da lì in poi la classe si legge e si modifica in un posto solo, con Mini GUI, stati e larghezze.

**Cosa si sposta.** Le regole i cui selettori parlano tutti del blocco: la classe, i suoi stati e pseudo-elementi, le parti `__` e le varianti `--`, i discendenti (`& > a`), dentro `@media`, `@container`, `@supports` o `@layer`. L'ordine delle regole resta quello del foglio; le regole consecutive nella stessa `@media` la condividono.

**Cosa resta nel foglio, e perché la pagina non cambia.** Il CSS dei selettori arriva in pagina dopo i fogli globali.
- Una regola resta nel foglio se più in basso un'altra regola che resta lì, sulla stessa classe o su un'altra classe dell'elemento, tocca le stesse proprietà (o della stessa famiglia: `padding` e `padding-top`): spostarla cambierebbe quale vince (`safeToMove()`). Il pannello dice quante.
- Le regole condivise con altre classi (`.card, .tile`) restano nel foglio.
- Il CSS su richiesta vale allo stesso modo per fogli e selettori (§3), safelist compresa.
- Tutto avviene in sviluppo: il confronto visivo della finestra Pubblica (versioning.md §8) mostra se qualcosa è cambiato prima della release.

**Tutti i blocchi in una volta.** «Nei selettori…», sulla pillola del foglio globale (quel foglio, se ce n'è più d'uno) o nella scheda Selettori (tutti i fogli), fa lo stesso per ogni blocco, dopo un'anteprima (`planSheetMove()`, `BulkMove.svelte`):
- *Blocchi.* Una classe appartiene al blocco che precede il primo `__` o `--` (`card__title`, `card--dark` → `.card`). Una regola si sposta nel blocco della sua prima classe se tutti i suoi selettori parlano di quel blocco, come sopra. I blocchi si prendono nell'ordine in cui compaiono la prima volta; le voci nuove si stampano in quest'ordine, prima di quelle esistenti, e le regole spostate in una voce esistente vanno prima del suo CSS.
- *Solo i blocchi usati.* Un blocco si sposta se almeno una delle sue classi è usata da un documento o da un template (uso del gestore dei selettori, §4, sugli stili salvati in sviluppo) o dalla pagina aperta. Gli altri restano nel foglio e l'anteprima li elenca: le classi aggiunte da script non si vedono, e il CSS morto si pulisce a mano.
- *La cascata.* Una regola resta nel foglio quando una regola che nel foglio veniva dopo, una volta spostata stamperebbe prima: una regola che resta (liste condivise `.a, .b`, blocchi non usati, altri fogli), una regola di un altro blocco la cui voce si stampa prima (blocchi intrecciati), o il CSS di una voce esistente che si stampa prima. Vale anche dentro una stessa voce: il compilatore scrive le parti BEM (`&__titolo`, `&--scuro`, anche dentro `@media`) dopo tutto il resto della voce, quindi una parte BEM seguita nel foglio da una regola del blocco che non lo è (`.nav__g a` e poi `.nav a[aria-current]`) resta nel foglio. Conta solo se le due regole toccano proprietà della stessa famiglia (`padding` e `padding-top`, `margin-left` e `margin-inline`, `inset` e `top`, `all` con tutte) e se la specificità può coincidere (con `:is()`, `:not()`, `:has()`, `:where()` o regole annidate si assume di sì). Una regola trattenuta trattiene a sua volta quelle prima di lei, fino a che nulla cambia. Non sapendo quali classi stanno sullo stesso elemento, il controllo è prudente: un foglio con tutte le `@media` in fondo tiene nel foglio molte regole di base.
- *Anteprima.* Quanti blocchi e quante regole si spostano; quante restano per la cascata (ognuna con la regola che la terrebbe dietro e la proprietà), quante perché condivise, quante di blocchi non usati. «Sposta» scrive tutto in un solo passo da annullare (`BuilderState.moveToSelector()` riceve tutte le voci e i fogli).

**Il foglio, collegato ai selettori.** La pillola del foglio globale ricorda di scrivere lì solo ciò che è davvero globale (reset, `:root`, tag), e con «CSS completo» mostra in sola lettura il foglio e il CSS compilato di ogni selettore, come arrivano nella pagina: un posto solo per leggere tutto, mentre si modifica per selettore.

**Provato su un sito di prova.** Il foglio globale di un sito importato (59 KB, minificato) è stato svuotato con questa logica, blocco per blocco: 40 blocchi e 532 regole nei selettori, 26 regole tenute nel foglio da `safeToMove`, foglio a 11,5 KB. Confronto visivo (`dev/visual-diff`) di 8 pagine, a schermo grande e su telefono: nessuna differenza di posizione, dimensioni o stile calcolato degli elementi, stesse altezze di pagina; le sole differenze di pixel venivano da immagini caricate in differita, diverse anche tra due scatti senza modifiche. La prova ha trovato un difetto, corretto: in un foglio minificato l'ultima dichiarazione di una regola non ha `;`, e unendo due regole le dichiarazioni si fondevano.

Implementazione: `editor/src/lib/scss.ts`, `CssPanel.svelte`, `BulkMove.svelte`, `SelectorsPanel.svelte`, `BuilderState.moveToSelector()`; le parti `__`/`--` dentro le at-rule annidate in `CssCompiler.php` e `packages/dsl/src/css.ts`. Test: `editor/test/scss.test.ts`, `packages/spec/vectors/css.json`, `e2e/specs/scss-sheet.spec.ts`, `e2e/specs/scss-bulk.spec.ts`.

## 12. Controllo del CSS
Lo stesso controllo per persone e agenti: `cssLint(selettore, css, contesto)` in `packages/dsl/src/csslint.ts`, esportato da `@xb/dsl`. Legge il testo così com'è scritto (dichiarazioni, regole annidate, at-rule) e non lo cambia. Il contesto sono le variabili che il sito definisce (framework, token `--xb-*`, `--…:` dei fogli globali e dei selettori) e le media query con nome.

| Codice | Cosa | Gravità |
|---|---|---|
| `brace-open`, `brace-close` | graffa aperta e mai chiusa, o chiusa senza una aperta | errore |
| `unknown-query` | `@media (--nome)` o `@container (--nome)` con un nome che il sito non ha: il browser la ignorerebbe | da rivedere |
| `unknown-variable` | `var(--x)` che nessuno definisce; non si controlla senza un elenco di variabili, né per i preset di WordPress (`--wp--…`) | da rivedere |
| `important` | `!important` | da rivedere |
| `empty-value` | dichiarazione senza valore | da rivedere |
| `token-value` | una lunghezza o un colore fisso uguale al valore di un token: «usa var(--…)» | consiglio |
| `not-bem` | una classe del selettore, di una regola annidata o di `&__parte` fuori dallo schema blocco__elemento--modificatore | consiglio |

Dove si vede:
- **pannello CSS:** sottolineature nel CSS del selettore, mentre si scrive (`CssPanel.svelte`, `lib/cssLint.ts`);
- **Controlli:** area **CSS**, per i selettori che la pagina usa (`checks.md`);
- **agente:** gli avvisi tornano dalle scritture e dallo strumento di controllo del connettore.

Ogni avviso ha `code` e `args`, così le interfacce in altre lingue scrivono il proprio messaggio; `message` è quello italiano.

Test: `packages/dsl/test/csslint.test.ts`, `editor/test/cssLint.test.ts`, `e2e/specs/css-lint.spec.ts`.

## 13. Aspetto in Gutenberg
La Mini GUI (§9-§10) anche nell'editor a blocchi, per chi non scrive CSS: pannello **Histeon · Aspetto** nella barra laterale di un elemento Histeon, con le parole del cliente al posto dei nomi delle proprietà (il nome CSS resta accanto, piccolo) e i colori come campioni. Usa gli stessi gruppi, opzioni dai token del framework, `relevant()` e la scrittura nei blocchi annidati (`Quando` = stato, `Su quali schermi` = query con nome).

**Un solo stato condiviso** (`editor/src/gutenberg/styles.ts`): Aspetto, la scheda CSS del pannello sotto la pagina e il salvataggio leggono e scrivono gli stessi dati, quindi interfaccia e codice non possono divergere. L'ultima modifica fatta da un pulsante (`StyleChange`) porta selettore, proprietà, valore prima e dopo e blocco: la scheda CSS seleziona quella dichiarazione nell'editor (`declarationRange()`) e mostra la nota.

**Anteprima incrementale.** Ogni selettore ha il suo `<style>` nella tela, ricompilato solo se il suo CSS (o le query con nome) è cambiato; i fogli globali in un `<style>` a parte, riscritto solo se cambiano; gli aggiornamenti di un frame si applicano insieme (`requestAnimationFrame`). L'ordine dei `<style>` segue quello degli stili del sito, così la cascata è la stessa. Su 500 selettori un clic ricompila una regola (test: meno di 16 ms).

Implementazione: `editor/src/gutenberg/appearance.ts`, `styles.ts`, `inspector.ts` (`declarationRange`, scheda CSS), `code.ts` (`highlight`). Test: `editor/test/gutenberg-appearance.test.ts`, `e2e/specs/gutenberg-appearance.spec.ts`.

**Solo questa pagina.** L'interruttore «Solo questa pagina» fa scrivere i pulsanti in uno stile proprio della pagina invece che nella classe: vale solo lì, sotto la classe `xb-page-<id>` del `<body>`. Per un cliente con il permesso è l'unico posto dove Aspetto scrive, nei soli gruppi concessi. Grammatica, salvataggio e regole: `client-mode.md`, «Aspetto della pagina».

**Annulla e ripeti con le parole.** In Gutenberg le modifiche agli stili sono dati di Histeon, fuori dall'annulla di WordPress: lo stato condiviso tiene la sua cronologia (100 passi). Ogni passo ha un'etichetta («padding di .card» per un pulsante di Aspetto, «CSS di .card» per il codice, dove i tasti battuti entro 1,5 secondi sullo stesso selettore fanno un passo solo); i pulsanti «↶ Annulla: …» e «↷ Ripeti: …» stanno in Aspetto e sopra il salvataggio della scheda CSS. Implementazione: `styles.ts` (`editStyles`, `undoStyles`, `redoStyles`, `HistoryButtons`); test: `e2e/specs/gutenberg-appearance.spec.ts`.
