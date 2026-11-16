# Histeon CSS

Histeon CSS è il framework CSS e design system libero di Histeon. Da pochi valori (i colori del marchio, le dimensioni minime e massime di testi e spazi) calcola una palette completa, scale fluide che si adattano allo schermo, raggi, ombre e caratteri. Ci aggiunge classi di utilità e ricette pronte. È incluso in Histeon, è acceso di serie e non chiede licenze.

## Che cos'è

Histeon CSS è fatto di tre parti:

- **i token**, variabili CSS con prefisso `--xb-`: `var(--xb-primary)`, `var(--xb-space-m)`, `var(--xb-text-l)`;
- **le classi di utilità**, con prefisso `xb-` e sempre con un trattino singolo: `xb-grid-3`, `xb-text-l`, `xb-btn xb-btn-primary`;
- **le ricette**, gruppi di dichiarazioni da espandere nel pannello CSS: `@centra`, `@card`, `@tronca`.

Histeon CSS non dà stile agli elementi nudi (`h1`, `a`, `button`): attivarlo su un sito esistente non cambia nulla finché non usi le sue classi o i suoi token. E grazie al CSS su richiesta, in ogni pagina arrivano solo le classi che quella pagina usa.

## Il pannello Histeon CSS

Il pannello si apre sopra qualsiasi pagina, sul sito o nell'amministrazione, senza ricaricarla:

- dalla voce **Histeon CSS** della barra di amministrazione;
- con [[Alt+Maiusc+H]];
- aggiungendo `#xb-histos-css` all'indirizzo della pagina.

Ha sei schede:

| Scheda | Che cosa si fa |
|---|---|
| **Colori** | Le otto famiglie di colori; un clic sulla striscia apre le 11 sfumature e le 9 trasparenze, e un clic su un campione copia la sua variabile |
| **Testo** | La scala del testo, con l'anteprima di ogni passo |
| **Spazi** | La scala degli spazi e le larghezze di riferimento, con una barra per ogni passo |
| **Forme** | Raggio e larghezze, con l'anteprima di raggi e ombre |
| **Caratteri** | Le tre famiglie di caratteri, con l'anteprima |
| **Ricette** | Cerca una ricetta, leggila, copiala |

Ogni modifica si vede **subito sulla pagina che hai davanti**, ma non viene salvata finché non premi **Salva in sviluppo**: i nuovi valori diventano una modifica in sviluppo e vanno online con la prossima release, come tutto il resto.

- Un'opzione cambiata ha un **punto** accanto al nome, e **↺** la riporta al valore predefinito. Il punto compare anche sulla scheda.
- **Cerca un'opzione** trova un'opzione per nome, anche in inglese: «line length» trova «Misura del testo».
- **Esporta** scarica le impostazioni in un file JSON, con i soli valori cambiati; **Importa** le rilegge da un file, dopo il controllo del server. Poi le salvi come al solito.
- **Esporta i token (DTCG)** scarica i token calcolati nel formato del Design Tokens Community Group (versione 2025.10), da aprire in Figma o Penpot: `tokens.chiaro.json` e, con il tema scuro acceso, `tokens.scuro.json`. Colori, misure in px o rem, caratteri, pesi e ombre; le misure fluide (`clamp()`), le unità che il formato non ammette (em, ch…) e gli altri valori restano fuori e sono elencati, con il loro CSS, nel file stesso.
- **Importa i token (DTCG)** legge i token esportati da uno strumento di design: da Figma un file per ogni modo delle variabili («Export modes»), da Penpot il file di **Token → Esporta**. Puoi scegliere più file insieme; un file con `dark` o `scuro` nel nome è il tema scuro, e un file di Penpot con i temi porta già il suo. I colori delle famiglie (primario, secondario, neutro, gli stati), i caratteri, la misura base del testo, il peso e l'interlinea dei titoli e il raggio diventano parametri del pannello, sopra i valori che ci sono; il server li controlla come per **Importa**. Le sfumature non si copiano: le calcola Histeon CSS dal colore della famiglia. Le misure di testo non diventano mai misure fisse: il **Rapporto dei token importati**, sotto il messaggio, dice per ognuna il gradino più vicino (`--xb-text-l`, `--xb-h2`) e che cosa è rimasto fuori, con il motivo. Nel builder gli altri token (altri colori, spazi, ombre) entrano fra le **Variabili del sito**. Poi salvi come al solito.
- **Predefiniti** riporta tutto ai valori di Histeon CSS, dopo una conferma.

È lo stesso pannello della sezione **Design system** del builder (in WordPress e in Histeon Publish) e del plugin Histeon CSS.

Il pannello lo vedono solo gli utenti che possono costruire con Histeon. Se il sito usa un altro framework, un avviso lo dice e porta alle impostazioni.

## Movimento ed effetti

Nella stessa scheda, **Movimento ed effetti** regola le classi di movimento, solo CSS:

- **all'entrata e all'uscita** dalla vista mentre si scorre: `xb-enter-fade`, `xb-enter-up`, `-down`, `-left`, `-right`, `-grow`, `-shrink`, `-blur` (e `xb-exit-…`). Scegli a che punto della vista l'entrata è compiuta, la distanza e la curva. Nei browser che non sanno legare un'animazione allo scorrimento gli elementi restano semplicemente fermi;
- **al passaggio del mouse:** `xb-hover-lift` (si solleva), `-shadow`, `-glow`, `-scale`, `-bright`, `-fade` e `xb-hover-underline` (anche `-center` e `-right`), con durata, sollevamento e ingrandimento.

Si regolano anche la misura di partenza di `grow` e `shrink`, la sfocatura di `blur`, il punto in cui l'uscita è compiuta, la luminosità di `bright` e l'opacità di `fade`.

Per chi chiede al sistema meno movimento, tutto resta fermo.

## Avanzate

In fondo alla scheda **Moduli e dettagli**, **Avanzate** sceglie quali gruppi di variabili stampare: trasparenze, ombre, pulsanti e link, moduli, card, icone, movimento. Un gruppo spento non va nel foglio del sito, che diventa più leggero; le classi che lo usano tornano ai loro valori o non fanno nulla, e il pannello CSS segnala come sconosciuta una sua variabile. Allo stesso modo si scelgono i **gruppi di classi** da stampare (colori, pulsanti, movimento, icone, card, moduli): un gruppo spento non va nel foglio e non è proposto nel pannello CSS. Un altro interruttore lascia all'editor a blocchi la palette del tema invece di quella di Histeon CSS.

Sempre lì, **Token in linea** stampa i token nella pagina invece di caricare un file (solo WordPress: in Histeon Publish tutto il CSS sta già in un foglio solo). Un gruppo di variabili o di classi spento si vede subito nell'anteprima.

## Moduli e dettagli

Nella scheda **Moduli e dettagli** del pannello:

- **Moduli:** padding, raggio, sfondo, bordo, anello di focus, misura del testo e segnaposto dei campi; misura e peso delle etichette; misura e colore dell'aiuto; colori di errore e conferma; caselle e scelte (anche come interruttore); spazio fra i campi. Con gli stili predefiniti anche la freccia dei menu a tendina e i gruppi di campi (`fieldset`). Li seguono sempre i campi `xb-input`; con **Stili predefiniti dei moduli** anche i campi senza classe e i moduli di **Fluent Forms**, il cui pulsante d'invio diventa come i pulsanti del sito;
- **Accessibilità e dettagli:** anello di focus su tutto il sito, scorrimento morbido (mai per chi chiede meno movimento), movimento ridotto quando il sistema lo chiede (questi tre sono accesi di partenza), colore della selezione, stile delle citazioni e le **colonne di testo** (classe `xb-columns`).

**Barra dei passi:** con gli stili predefiniti dei moduli, la barra dei moduli a più pagine di Fluent Forms prende altezza, colore e fondo scelti qui.

## Forme, pulsanti e link

Oltre a quanto segue: ogni **ombra** si regola per passo (distanza, sfocatura, opacità); i **pulsanti** si spengono per famiglia o per variante (contorno, chiara, scura), e le classi spente non vanno nel foglio; le varianti chiara e scura scelgono la **sfumatura al passaggio**; una **superficie** può avere un nome suo (`xb-surface-<nome>`) ed essere un gradiente, con il testo leggibile a entrambe le estremità; ci sono altre due **sovrapposizioni** (`xb-overlay-2`, `-3`) e il titolo delle card (`xb-card-title`) con la sua misura; le **icone** hanno misure proprie; le **liste con icona** scelgono il simbolo; i **link esterni** mostrano ↗ (o solo un testo per i lettori di schermo), se scrivi il dominio del sito.

Nella scheda **Forme** del pannello:

- **Bordo predefinito e divisori:** spessore e stile del bordo di `xb-border`; spessore, stile e spazio dei divisori `xb-divider`; il rapporto della scala dei raggi;
- **Pulsanti:** padding, raggio (fino alla pillola), peso, interlinea e misura del testo, maiuscole, spaziatura delle lettere, bordo, larghezza minima, e **a tutta larghezza sui telefoni**. Valgono per tutti i pulsanti: `xb-btn`, le varianti di ogni famiglia (`xb-btn-primary`, `xb-btn-primary-outline`, `xb-btn-primary-light`, `xb-btn-primary-dark`, e così per secondario, terziario, accento, neutro e stati) e le misure da `xb-btn-xs` a `xb-btn-xl`. Al passaggio del mouse un pulsante pieno prende la sfumatura scelta nella palette;
- **Link:** sottolineatura (sempre, al passaggio, mai), spessore e distanza della linea; con **Stili predefiniti dei link** valgono anche per i link senza classe.

Sempre nella scheda **Forme**:

- **Card:** padding, spazio interno, raggio, sfondo, ombra, bordo e proporzioni del media. Dentro una `xb-card`, `xb-card-media` mette un'immagine a filo dei bordi con quelle proporzioni e `xb-card-body` dispone il testo con lo spazio scelto; un pulsante in fondo resta in fondo. `xb-radius-inner` dà a un elemento annidato il raggio giusto (concentrico);
- **Icone:** colore e spessore del tratto, misure da `xb-icon-xs` a `xb-icon-2xl`, l'icona in un riquadro (`xb-icon-boxed`) e le liste con la spunta (`xb-list-icon`).

Sempre nella scheda **Forme**, **Sovrapposizioni e superfici**: colore, opacità e fusione della sovrapposizione di `xb-overlay` (e della sua versione sfumata `xb-overlay-gradient`), e tre **superfici del sito** (`xb-surface-1`, `-2`, `-3`) con il loro sfondo, bordo e ombra: il testo sopra diventa bianco o nero da solo, quello che si legge.

Ci sono anche le ombre del testo (`xb-text-shadow-s`, `-m`, `-l`) e le ombre `drop-shadow` per immagini e SVG trasparenti (`xb-drop-shadow-s`, `-m`, `-l`).

## Spazi e layout

La spaziatura del testo può valere per **tutto il contenuto** (`main`) e non solo per `xb-prose`, e le sezioni possono stare **in griglia dei contenuti di default**: sono spente di partenza e ogni classe del sito le supera.

Nella scheda **Spazi** del pannello, oltre alla scala:

- **Sezioni:** il **moltiplicatore** dello spazio verticale delle sezioni (schermo piccolo e grande), il **padding predefinito** di `xb-section` e il **margine laterale** in pixel (a 0 vale lo spazio m);
- **Spazi fra gli elementi:** fra i figli di una sezione, fra i blocchi di `xb-stack`, nelle griglie; per `xb-grid-auto` la colonna minima e se le colonne riempiono la riga o tengono il posto;
- **Testi:** in un testo `xb-prose`, lo spazio fra i blocchi, prima e dopo i titoli e fra le voci delle liste;
- **Layout:** il **layout a riquadro** (la pagina stretta e centrata sopra uno sfondo, con un'ombra), larghezza minima del sito, **altezza dell'intestazione** (`--xb-header-height`, usata da `--xb-sticky-top` per gli elementi fissi) e **Ancore sotto l'intestazione** (accese di partenza), perché un link a una sezione non la nasconda dietro un'intestazione fissa.

La **griglia dei contenuti** si usa con la classe `xb-content-grid` sul contenitore: i figli stanno nella colonna dei contenuti, `xb-wide` li porta alla larghezza ampia, `xb-full` da bordo a bordo, `xb-narrow` alla misura del testo.

## Tipografia

I titoli possono andare in corsivo; ogni livello ha anche spaziatura delle lettere e larghezza massima sue. Ogni passo del testo (`xb-text-xs` … `xb-text-4xl`) può avere interlinea, spaziatura delle lettere, larghezza massima, maiuscole e a capo suoi. In **Font del sito** i pulsanti **Accento** e **Display** assegnano un font del sito ai due ruoli liberi.

Nella scheda **Testo** del pannello, oltre alla scala del testo:

- **Scala dei titoli separata** (accesa di partenza): i titoli H1-H6 hanno la loro base e il loro rapporto; spenta, seguono la scala del testo;
- **Titoli:** carattere (fra i cinque ruoli: testo, titoli, codice, accento, display), peso, interlinea, spaziatura delle lettere, maiuscole, a capo (bilanciato di partenza) e larghezza massima. Valgono per le classi `xb-h1 … xb-h6`; con **Stili predefiniti dei titoli** valgono anche per i titoli `h1 … h6` del sito;
- **Titoli per livello:** peso, interlinea, colore, carattere e maiuscole di un solo livello, per esempio tutti gli H2 più leggeri e del colore primario («Come tutti» o 0 = il valore di tutti i titoli);
- **Misure dei singoli passi:** un passo del testo o un titolo con le sue due misure in pixel (schermo piccolo e grande), fuori dalla scala;
- **Testo e radice:** interlinea e a capo del testo (con **Stili predefiniti del testo** valgono per il corpo della pagina e i paragrafi) e la **dimensione radice**, che cambia ogni misura in rem: usala solo se sai perché.

Nella scheda **Caratteri** ci sono due ruoli liberi, **Accento** e **Display**, da usare per i titoli o con `var(--xb-font-accent)` e `var(--xb-font-display)`.

## Colori

Le famiglie sono otto: **Primario**, **Secondario**, **Accento**, **Neutro**, **Successo**, **Avviso**, **Pericolo** e **Informazione**. Per ognuna scegli il colore con il selettore o scrivi un valore (esadecimale, `rgb()` o `oklch()`).

Da ogni colore Histeon CSS calcola:

- il colore di base: `--xb-primary`;
- undici sfumature, da `--xb-primary-50` (la più chiara) a `--xb-primary-950` (la più scura);
- il colore del testo leggibile sopra: `--xb-on-primary`.

Le sfumature si calcolano nello spazio colore OKLCH, che mantiene la tinta e rende uniforme la luminosità tra famiglie diverse.

Nella scheda **Colori** del pannello trovi anche:

- **Terziario**, una nona famiglia, spenta finché non la accendi; con l'interruttore accanto al nome spegni anche secondario, accento e famiglie di stato che non usi (le loro variabili spariscono);
- **Palette:** una **luminosità unica** per le famiglie del marchio o per quelle di stato, perché abbiano tutte lo stesso peso; la **sfumatura al passaggio** (`--xb-primary-hover` e simili, 600 di partenza);
- **Ruoli dei colori:** per sfondo, superfici, testo, titoli, bordo, link e focus scegli una famiglia o una sfumatura, oppure lascia «Automatico». Con le **relazioni automatiche di contrasto** un testo automatico che non si legge sul suo sfondo passa da solo alla sfumatura più vicina che si legge;
- **Tema scuro:** acceso, il sito ha i ruoli e i colori scuri. Lo **schema del sito** dice se resta chiaro (lo scuro vale solo dove metti `xb-scheme-dark`), è sempre scuro o segue il sistema del visitatore. Puoi dare a ogni famiglia il suo colore in scuro, o lasciare quello automatico, e scegliere la sfumatura al passaggio del tema scuro.

La lista **Leggibilità** controlla anche le coppie del tema scuro.

Ci sono poi i **colori di ruolo**, che puntano alle sfumature giuste: `--xb-bg`, `--xb-surface`, `--xb-text`, `--xb-text-muted`, `--xb-heading`, `--xb-border`, `--xb-link`, `--xb-focus` e altri. Con la classe `xb-scheme-dark` su un contenitore, questi colori passano alle sfumature scure.

## Testo e spazi fluidi

Testi e spazi sono **fluidi**: crescono con la larghezza dello schermo tra un minimo e un massimo, senza media query. Tu scegli pochi valori, Histeon CSS calcola tutti i passi.

| Parametro | Predefinito |
|---|---|
| Testo: dimensione del passo `m` | da 16 a 18 px |
| Testo: rapporto tra un passo e l'altro | da 1,2 a 1,25 |
| Spazi: dimensione del passo `m` | da 16 a 24 px |
| Spazi: rapporto tra un passo e l'altro | da 1,5 a 1,6 |
| Larghezze dello schermo di riferimento | da 360 a 1440 px |

Il primo valore vale sugli schermi stretti, il secondo su quelli larghi; in mezzo il valore cresce in modo continuo.

I passi che ottieni:

- **testo:** da `--xb-text-xs` a `--xb-text-4xl`, più `--xb-h1` … `--xb-h6` per i titoli;
- **spazi:** da `--xb-space-3xs` a `--xb-space-4xl`, più gli spazi delle sezioni (`--xb-section-s`, `-m`, `-l`, `-xl`) e il margine laterale `--xb-gutter`.

## Forme, larghezze e caratteri

- **Raggi:** `--xb-radius-s`, `-m`, `-l`, `-xl` e `-full`, calcolati dal raggio del passo `m` (8 px di serie).
- **Ombre:** `--xb-shadow-s`, `-m`, `-l`, `-xl`.
- **Larghezze:** `--xb-width-content` (72 rem), `--xb-width-wide` (90 rem) e `--xb-width-prose` (65 caratteri, la misura comoda per leggere).
- **Caratteri:** `--xb-font-body`, `--xb-font-heading` e `--xb-font-mono`. Scrivi i nomi separati da virgole, tra virgolette quelli con spazi.

> Per servire i font dal tuo sito, senza chiederli a Google a ogni visita, usa **Histeon → Impostazioni → Font locali**: importa una famiglia da Google Fonts o carica un file WOFF2. Poi scrivi il suo nome nel pannello.

## Il generatore e il Design system nel builder

Nel builder, la sezione **Design system** mostra tutte le variabili del framework attivo con il loro valore reale: un campione per i colori, una barra per gli spazi, «Aa» alla sua misura per i testi, un quadrato per i raggi, un cartoncino per le ombre. Un clic copia `var(--nome)`; la ricerca filtra per nome.

Con Histeon CSS attivo, nella stessa sezione c'è il **Generatore Histeon CSS**: colori, scale e forme da pochi valori, con le modifiche visibili subito nella tela. Sotto i colori, **Leggibilità** controlla il contrasto delle coppie di testo e sfondo, che deve essere almeno 4,5:1, e dice «tutto leggibile» oppure quante coppie sono da rivedere. **Salva in sviluppo** tiene le modifiche; **Torna ai predefiniti** riparte da zero; **↺** accanto a un token lo riporta al valore predefinito.

I colori si vedono per famiglia: compare solo il colore principale di ognuna (primario, secondario, accento, neutro, gli stati e i ruoli come testo e sfondo). Il pulsante con i pallini accanto apre le sfumature, le trasparenze e il colore del testo di quella famiglia. Cercando un nome, compaiono tutti i colori che lo contengono.

**Variabili del sito.** Con **＋ Variabile** si aggiunge una variabile tua, accanto a quelle di Histeon CSS. Il nome diventa `--xb-site-<nome>`: lettere minuscole, cifre e trattini, fino a 40 caratteri. Il valore segue le regole dei token: numeri, unità, colori, `calc()`, `clamp()`, `var()`. La tela la mostra subito; **Salva in sviluppo** la tiene, e la release la porta online. Compare nell'autocompletamento del pannello CSS. **✕** la elimina. **Importa variabili** ne aggiunge molte da un file: `variabili-sito.json` scritto da Histeon Publish, oppure i token esportati da Figma o Penpot (quelli senza un parametro del design). Una variabile con lo stesso nome prende il valore nuovo; il massimo è 200.

**Ricette.** Nella stessa sezione ci sono tutte le ricette, con la loro origine: Histeon, Automatic.css (importate) o Sito. Un clic apre il CSS di una ricetta e lo si può cambiare: la versione del sito sostituisce quella di Histeon con lo stesso nome, e **↺ Torna alla ricetta originale** la riporta. **＋ Ricetta** ne aggiunge una nuova, che si usa subito con `@nome` nel pannello CSS e nel pannello Ricette. Una ricetta non può contenere markup, `url()`, `@import` né altre istruzioni che caricano o eseguono qualcosa, e ha al massimo 4 KB.

## Le classi di utilità

| Famiglia | Esempi |
|---|---|
| Contenitori | `xb-container`, `xb-container-wide`, `xb-container-prose`, `xb-section`, `xb-section-l` |
| Disposizione | `xb-stack`, `xb-cluster`, `xb-flow`, `xb-center`, `xb-grid-2` … `xb-grid-6`, `xb-grid-auto` |
| Spazi | `xb-p-m`, `xb-px-l`, `xb-py-s`, `xb-mt-xl`, `xb-mb-0`, `xb-gap-m` |
| Testo | `xb-text-l`, `xb-h2`, `xb-font-heading`, `xb-weight-700`, `xb-align-center`, `xb-balance`, `xb-prose` |
| Colori | `xb-color-primary`, `xb-bg-primary-700` (con il testo leggibile), `xb-border-neutral`, `xb-scheme-dark` |
| Forme | `xb-rounded-m`, `xb-shadow-l`, `xb-border` |
| Componenti | `xb-btn`, `xb-btn-primary`, `xb-btn-outline`, `xb-btn-l`, `xb-card`, `xb-badge`, `xb-link` |
| Accessibilità e utilità | `xb-visually-hidden`, `xb-skip-link`, `xb-clickable-parent`, `xb-cover`, `xb-aspect-video`, `xb-overlay` |

Le griglie `xb-grid-2` … `xb-grid-6` riducono da sole il numero di colonne quando lo spazio non basta. Nel pannello HTML, dentro `class="…"`, il builder ti propone queste classi mentre scrivi.

## Le ricette

Nel pannello CSS scrivi `@` più il nome, all'inizio di una dichiarazione, e la ricetta si espande in un gruppo di dichiarazioni.

| Gruppo | Ricette |
|---|---|
| Disposizione | `@centra`, `@flex-centro`, `@pila`, `@fila`, `@fila-spaziata`, `@griglia-auto`, `@griglia-2`, `@griglia-3`, `@griglia-4`, `@contenitore`, `@sezione`, `@tutta-larghezza`, `@flusso`, `@appiccicoso`, `@scorri-orizzontale` |
| Posizione e media | `@riempi`, `@immagine-copri`, `@aspetto-video`, `@aspetto-quadrato`, `@sovrapposizione`, `@link-esteso` |
| Testo | `@tronca`, `@tronca-righe`, `@bilancia`, `@misura`, `@testo-gradiente`, `@titolo` |
| Componenti | `@card`, `@solleva`, `@vetro`, `@divisore`, `@pulsante-reset`, `@lista-pulita` |
| Accessibilità e movimento | `@nascosto-accessibile`, `@focus`, `@transizione`, `@meno-movimento`, `@barra-nascosta` |

Le ricette usano i token `--xb-*` con un valore di riserva, quindi funzionano con qualsiasi framework.

## Con altri framework

Histeon funziona anche con **Core Framework** e **Automatic.css**, se li hai installati tu: Histeon non li include. Il framework si sceglie in **Histeon → Impostazioni → Framework CSS**. Con Core Framework o Automatic.css, un pulsante nella colonna di sinistra del builder apre il loro pannello sopra la tela.

I token `--xb-*` fanno da ponte: Histeon li collega alle variabili del framework attivo (per esempio `--xb-space-m` diventa `var(--space-m)`). Un componente scritto con i token funziona quindi con qualsiasi framework, senza modifiche.

### Cambiare framework

**Histeon → Impostazioni → Cambio di framework con migrazione** riscrive i nomi nativi del framework attuale (variabili e classi) in quelli del nuovo, in pagine, componenti e stili.

1. In **Passa a** scegli il nuovo framework.
2. Lascia attiva **Porta con te le variabili e le regole di … che il sito usa**: le variabili e le regole del vecchio framework restano disponibili, così niente si rompe.
3. **Anteprima della migrazione** mostra, documento per documento, cosa cambierebbe e quali nomi non hanno corrispondenza. Non modifica nulla.
4. **Prepara in sviluppo** prepara tutto come modifica in sviluppo.
5. Controlla l'anteprima e pubblica dal builder. Una sola release porta online contenuti e framework insieme, e il ripristino della release precedente riporta indietro anche il framework.

## Da Automatic.css a Histeon CSS

Se il sito usa Automatic.css, puoi passare a Histeon CSS senza cambiare l'aspetto delle pagine. La migrazione legge i valori dai fogli che Automatic.css ha generato nel sito.

Segui i passi di «Cambiare framework» scegliendo **Histeon CSS** in **Passa a**. Ecco che cosa succede:

1. **I valori.** Tutte le variabili di Automatic.css vengono conservate in un foglio globale, e i token di Histeon CSS puntano a loro: le pagine usano gli stessi valori di prima. Prima di pubblicare, controllale con il confronto visivo.
2. **La palette.** I colori di base di Automatic.css (primario, secondario, accento, neutro) diventano i colori della palette di Histeon CSS.
3. **I nomi.** Le classi e le variabili con un equivalente esatto cambiano nome: `text--l` diventa `xb-text-l`, `var(--space-m)` diventa `var(--xb-space-m)`, `hidden-accessible` diventa `xb-visually-hidden`.
4. **Il resto.** Le classi senza un equivalente identico (`btn--*`, `bg--*`, `section--*`…) tengono il loro nome e la loro regola, servite da un foglio di compatibilità.
5. **Le ricette** che il sito aveva in Automatic.css arrivano anch'esse, se Automatic.css è ancora attivo durante l'importazione, e restano accanto a quelle di Histeon CSS (a parità di nome vince quella del sito).

Dopo la release, le pagine prendono valori e classi da Histeon CSS e dal foglio di compatibilità.

| Automatic.css | Histeon CSS |
|---|---|
| `ultra-light` | 50 |
| `light` | 200 |
| `semi-light` | 300 |
| colore di base | base (500) |
| `hover` | 600 |
| `semi-dark` | 700 |
| `dark` | 800 |
| `ultra-dark` | 950 |

La famiglia `base` di Automatic.css diventa `neutral` in Histeon CSS, se il sito non ne ha già una.

> Importare è consigliato, non obbligatorio. Senza importazione, i fogli già presenti nel sito restano caricati finché non importi, così il sito resta com'era. Un avviso in amministrazione ti ricorda l'importazione: **Importa ora** ti porta alle impostazioni, **Più tardi** lo nasconde per 14 giorni.

Se importi un sito da Etch che usa Automatic.css, la pagina dell'importazione ti chiede dove va il suo sistema di design: **In Histeon CSS** (consigliato), **In Core Framework** (se è installato) o **Resta su Automatic.css**.

### Da Automatic.css a Core Framework

Se preferisci Core Framework, la sezione **Da Automatic.css a Core Framework, tutto compreso** crea con **Crea il progetto Core Framework** un nuovo progetto con i colori e tutte le variabili di Automatic.css, più un foglio di compatibilità con le regole che il sito usa. Poi apri Core Framework e premi **Save changes**, passa a Core Framework con **Prepara in sviluppo** e pubblica.

## Histeon CSS come plugin autonomo

Histeon CSS esiste anche come plugin a sé (`histos-css`), per i siti che non usano il builder: per esempio un sito fatto con l'editor a blocchi e un tema qualsiasi.

Offre i token, le classi, le ricette e lo stesso pannello in popup (barra di amministrazione o [[Alt+Maiusc+H]]), con l'anteprima dal vivo e il controllo di leggibilità. Nell'editor a blocchi, la palette e le dimensioni del testo diventano quelle del sito. **Aspetto → Histeon CSS** spiega dove trovare tutto.

Le differenze rispetto a Histeon CSS dentro Histeon:

- non ci sono sviluppo e release: salvare nel pannello applica subito i valori al sito;
- il pannello è riservato a chi può modificare l'aspetto del sito (di norma gli amministratori);
- disinstallando il plugin, i suoi valori vengono cancellati.

> Se attivi Histeon su un sito che ha già il plugin Histeon CSS, il plugin resta fermo e un avviso ti dice che puoi disattivarlo: Histeon CSS è già incluso.
