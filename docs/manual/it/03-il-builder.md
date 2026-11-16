# Il builder

Il builder è lo spazio di lavoro di Histeon: una tela con la pagina vera, la struttura degli elementi e i pannelli del codice. Questo capitolo ne fa il giro completo, dall'apertura alla pubblicazione.

## Aprire il builder

Puoi aprire una pagina, un articolo, un componente o un template in diversi modi:

- dall'elenco delle pagine o degli articoli, con l'azione **Modifica con Histeon** sotto il titolo;
- dalla barra di amministrazione, nel menu **Histeon → Modifica questa pagina con Histeon**, anche mentre guardi la pagina sul sito;
- dall'editor a blocchi, con il pulsante **Modifica con Histeon** nella barra in alto;
- da **Histeon → Pagine**, **Articoli**, **Componenti** o **Template**.

Dall'editor a blocchi il builder si apre a tutto schermo sopra Gutenberg. Se in Gutenberg ci sono modifiche non salvate, Histeon ti chiede di salvarle prima. Il pulsante **← Gutenberg** in alto a sinistra chiude il builder e ti riporta all'editor: se nel frattempo una release ha cambiato la pagina, l'editor si ricarica; altrimenti un avviso ti ricorda che ci sono modifiche salvate in sviluppo, che compariranno dopo la pubblicazione.

## L'interfaccia

Il builder è diviso in quattro zone:

| Zona | Dove | Che cosa contiene |
|---|---|---|
| **Barra in alto** | In alto | Titolo del documento, Aggiungi, Comandi, Annulla e Ripeti, Anteprima, Salva, Pubblica… |
| **Colonna delle sezioni** | A sinistra | Icone delle sezioni del sito, che si aprono sopra la tela |
| **Tela** | Al centro | La pagina come la vedrà chi visita, alla larghezza che scegli |
| **Pannelli** | A destra e in basso | **Struttura** e **Proprietà** a destra; **HTML**, **CSS** e **JavaScript** in basso |

I pannelli in basso mostrano il codice **dell'elemento selezionato**, non dell'intera pagina: così vedi e modifichi solo quello che stai toccando.

### La barra in alto

| Elemento | Che cosa fa |
|---|---|
| Logo Histeon | Apre la Panoramica di Histeon nell'amministrazione |
| **← Gutenberg** | Torna all'editor a blocchi |
| **Aggiungi** | Apre il menu per inserire un elemento ([[Ctrl+I]]) |
| Titolo | Il documento aperto; per un template o un componente compare anche il tipo |
| Lingue | Con Lingua Bridge acceso, le lingue della pagina: un clic apre la traduzione nel builder |
| Stato | Messaggi come «Modifiche non salvate» o l'esito dell'ultimo salvataggio |
| **Comandi** | Apre la palette dei comandi ([[Ctrl+K]]) |
| Annulla, Ripeti, Storia | Tornano indietro e avanti tra le modifiche |
| **Nuovo componente** | Crea un componente vuoto e lo apre |
| **Anteprima** | Salva e mostra la pagina vera sopra il builder |
| **Pubblicato** | Apre la pagina online in una nuova scheda |
| **Salva** | Salva in sviluppo ([[Ctrl+S]]) |
| **Pubblica…** | Porta online le modifiche salvate (solo per chi ha il permesso di pubblicare) |

### Le sezioni del sito

Le icone della colonna a sinistra aprono le sezioni sopra la tela; **Chiudi ✕** o un secondo clic sull'icona riportano alla tela.

| Sezione | A cosa serve |
|---|---|
| **Contenuti** | Pagine e articoli del sito, per passare da un documento all'altro senza uscire |
| **Template** | I template e le parti del tema a blocchi |
| **Stili e selettori** | Tutti i selettori del sito, i fogli globali e le media query con nome |
| **Dati e query** | Le query dei loop e le sorgenti di dati |
| **Filtri dei loop** | I filtri che chi visita usa per restringere un elenco |
| **Tipi e campi** | Tipi di contenuto, tassonomie e campi |
| **Componenti** | I componenti del sito |
| **Versioni** | Le release, con esportazione, importazione e ripristino |
| **File unico** | La pagina o il componente come un solo file con HTML, CSS e JavaScript |
| **Controlli** | Accessibilità, SEO e prestazioni della pagina |
| **Design system** | Le variabili del framework e il generatore di Histeon CSS |
| **Commenti** | I commenti della revisione con il cliente |

Quando lavori su un componente compare anche **Props del componente**. In fondo alla colonna trovi la palette dei comandi, il pulsante per mostrare o nascondere i pannelli in basso e quello del tema, che a ogni clic passa da **come il sistema** (chiaro di giorno, scuro di notte, se il computer cambia da solo) a **chiaro** e a **scuro**. Histeon ricorda solo la scelta che fai tu. Se il framework scelto è Core Framework o Automatic.css, un pulsante apre il suo pannello sopra la tela: dopo averci salvato qualcosa, **↻ Applica all'anteprima** aggiorna variabili, classi e anteprima.

### Disporre i pannelli

I pannelli si adattano al tuo modo di lavorare:

- trascina l'intestazione di un pannello per spostarlo in un'altra area (sinistra, destra, in basso) o riordinarlo;
- **▾** lo comprime alla sola intestazione, **⤢** lo allarga a tutta la sua area;
- trascina i bordi delle aree per cambiarne larghezza e altezza, e la linea fra due pannelli della stessa area (per esempio HTML e CSS) per dare più spazio all'uno o all'altro; da tastiera, frecce, [[Inizio]] e [[Fine]] sulla linea;
- il menu **⋯** di ogni pannello offre le stesse mosse da tastiera, più **Ripristina la disposizione**, che per qualche secondo si può annullare.

La disposizione resta salvata in questo browser, compreso il pannello allargato.

### Su tablet e telefono

Sotto i 1024 px di larghezza le colonne laterali diventano cassetti sopra la tela: il pulsante **Pannelli laterali** nella barra a sinistra li apre e li chiude. Nella barra in alto **Salva** e **Pubblica…** restano sempre visibili, le altre azioni passano nel menu **⋯**. Sotto i 768 px il builder avvisa che serve uno schermo più largo; si può continuare comunque. In modalità contenuti il telefono va bene: la tela prende tutta la larghezza e un tocco su un testo lo apre in modifica. Con il dito o la penna si trascina un elemento della Struttura tenendo premuta la sua icona, e un pannello tenendo premuta la sua maniglia **⠿**; la linguetta dell'elemento selezionato nella tela si trascina come con il mouse.

## La tela

La tela mostra la pagina con gli stili veri del sito, ma senza eseguire i suoi script: è uno spazio sicuro in cui lavorare.

- **Selezionare:** un clic su un elemento lo seleziona; la Struttura, le Proprietà e i pannelli del codice passano a lui. [[Esc]] toglie la selezione.
- **Spostare:** sopra l'elemento selezionato compare una linguetta con il suo nome (per esempio `⠿ Card`). Trascinala: una linea corallo mostra se andrà prima o dopo, un riquadro tratteggiato se andrà dentro. [[Esc]] annulla il trascinamento.
- **Spaziature:** sull'elemento selezionato compaiono le maniglie del padding (corallo, sui lati interni) e del margine sopra e sotto (indaco). Trascinandole il valore si aggancia alla scala degli spazi del framework, per esempio `var(--space-m)`; tenendo premuto [[Alt]] il valore è in pixel interi. Un'etichetta mostra il valore durante il trascinamento.

> Le maniglie degli spazi scrivono nel CSS della prima classe dell'elemento. Se l'elemento non ha classi, la tela non scrive nulla e la riga di stato ti chiede di aggiungerne una.

## Le larghezze della tela

L'anteprima non ha dispositivi fissi: la pagina si disegna alla larghezza che scegli tu, e le media query del sito reagiscono proprio a quella larghezza.

- **Maniglie:** ai due lati della tela ci sono due maniglie da trascinare. Funzionano anche da tastiera: frecce ±10 px, [[Maiusc]]+frecce ±100 px, [[Inizio]] per la larghezza minima, [[Esc]] o [[Fine]] per tornare ad adattare.
- **Campo in px:** nella barra sopra la tela scrivi una larghezza tra 240 e 3840 pixel.
- **Adatta:** riporta la tela alla larghezza dello spazio disponibile.
- **Punti di rottura del sito:** accanto al campo, un pulsante per ogni media query con nome (per esempio `tablet 1024`) porta la tela a quella larghezza.

Se scegli una larghezza maggiore dello spazio, la pagina viene ridotta per entrare e la barra dice «ridotta al …%». Le media query vedono comunque la larghezza vera. La larghezza scelta resta in questo browser.

### La tela che segue

Quando porti la tela a una larghezza diversa da «Adatta», le modifiche di stile fatte con la Mini GUI e con le maniglie degli spazi vanno da sole nella media query di quella larghezza:

- se una media query con nome del sito vale a quella larghezza, si usa quella (per esempio `@media (--mobile)`);
- altrimenti Histeon crea una query alla larghezza della tela, per esempio `@media (width <= 600px)`.

Sopra la tela una pillola dice dove vanno le modifiche, per esempio **Modifiche in @media (--mobile)**. Un clic la spegne e diventa **Modifiche per tutte le larghezze**; un altro clic la riaccende. **Adatta** torna a scrivere per tutte le larghezze. Il capitolo sugli stili spiega come [scegliere a mano stato e larghezza](#it-stili-stato-e-larghezza).

## L'anteprima

Il pulsante **Anteprima** salva in sviluppo e mostra la pagina sopra il builder, a tutto schermo. Non è il disegno della tela: è la pagina vera, resa da WordPress con i suoi script (slider, menu, filtri), nella versione in sviluppo.

- **Larghezza:** **Intera**, **Tablet** (820 px) o **Telefono** (390 px).
- **Durata del link:** quanto vale il link da mandare al cliente: **1 ora**, **1 giorno**, **2 giorni** (se non scegli) o **7 giorni**. Accanto leggi **Scade il …** con data e ora.
- **Copia link:** copia il link con la durata scelta, da mandare al cliente.
- **Nuova scheda:** apre la stessa anteprima fuori dal builder. È un link firmato che scade da solo e non richiede un accesso: puoi mandarlo al cliente.
- **Torna al builder** o [[Esc]]: riportano il builder com'era.

Nell'anteprima non c'è la barra di amministrazione, perché chi visita non la vede. L'anteprima di un template si apre sull'articolo di esempio scelto nella barra della tela (**Anteprima con**).

## Annulla, Ripeti e Storia

[[Ctrl+Z]] annulla, [[Ctrl+Maiusc+Z]] o [[Ctrl+Y]] ripetono. Accanto ai due pulsanti, **Storia** apre gli ultimi passi (fino a 200) descritti a parole, dal più recente: «Aggiunto div.card», «Spostato …», «CSS di .card».

- Un clic su un passo riporta la pagina a prima di quel passo, in un colpo solo.
- I passi annullati restano in grigio sopra **Ora** finché non modifichi altro: un clic li rifà.
- [[Esc]] o un clic fuori chiudono la finestra.

> La storia vive nella scheda aperta e si perde ricaricando la pagina. Le versioni salvate restano nella sezione **Versioni** e in **Histeon → Versioni**.

## Salvare e pubblicare

Histeon tiene separati il lavoro in corso e il sito pubblico.

**Salva** ([[Ctrl+S]]) salva in **sviluppo**: il sito pubblico non cambia. Puoi salvare quanto vuoi, guardare l'anteprima e mandarla al cliente. Finché ci sono modifiche non salvate, sul pulsante **Salva** c'è un punto corallo; gli avvisi della riga di stato (copiato, incollato…) spariscono alla modifica successiva. Se chiudi la scheda con modifiche non salvate, il browser ti avvisa.

**Pubblica…** porta online le modifiche salvate. La finestra mostra:

1. **Cosa va online:** ogni documento cambiato (pagine, componenti, stili, impostazioni), con una casella per scegliere quali pubblicare e **Differenze** per vedere cosa cambia rispetto al sito pubblicato.
2. **I controlli** delle pagine che cambiano. Se nelle impostazioni è attivo il blocco, la pubblicazione aspetta che finiscano senza errori.
3. **Confronta:** facoltativo, mette fianco a fianco la pagina online e quella in sviluppo, su schermo grande e su telefono, e segna cosa cambia.
4. **Versione** e **Note:** il numero della release (per esempio `1.2.0`) e cosa cambia.

Un clic su **Pubblica** porta tutto online insieme. Se qualcosa non va a buon fine, non cambia nulla. Ogni release si può ripristinare dalla sezione **Versioni** o da **Histeon → Release**: tutti i dettagli sono nel capitolo [Versioni e release](#it-versioni-e-release).

> Se un redattore ha cambiato un testo in Gutenberg mentre tu lavoravi nel builder, la pubblicazione tiene il suo testo e la tua struttura. Se avete cambiato entrambi lo stesso testo, vince il tuo e il builder te lo segnala.

## La palette dei comandi

[[Ctrl+K]] (o il pulsante **Comandi**) apre la palette: scrivi qualche lettera e scegli. Trovi azioni (**Salva in sviluppo**, **Pubblica…**, **Duplica l'elemento selezionato**), inserimenti (**Inserisci: Sezione**), le sezioni del builder, le voci per spostare i pannelli e gli elementi della pagina da selezionare.

### Cerca e sostituisci

Dalla palette, **Cerca e sostituisci nella pagina…** cambia una parola o una frase in tutta la pagina. Scegli dove: nei **Testi** (mai dentro i tag o le espressioni `{…}`), nelle **Classi** (solo una classe che ha esattamente quel nome: `card` non tocca `card__title`) e negli **Indirizzi dei link**; **Maiuscole e minuscole** le distingue. Prima di sostituire vedi quante sostituzioni ci saranno e un elenco di com'era e come sarà. Tutte insieme si annullano con un solo [[Ctrl+Z]].

### Le scorciatoie

| Comando | Tasti |
|---|---|
| Apri i comandi | [[Ctrl+K]] |
| Salva in sviluppo | [[Ctrl+S]] |
| Aggiungi un elemento | [[Ctrl+I]] |
| Annulla | [[Ctrl+Z]] |
| Ripeti | [[Ctrl+Maiusc+Z]], [[Ctrl+Y]] |
| Duplica l'elemento selezionato | [[Ctrl+D]] |
| Elimina l'elemento selezionato | [[Canc]] |
| Sposta su / giù | [[Alt+↑]] / [[Alt+↓]] |

Su Mac usa ⌘ al posto di Ctrl. [[Esc]] chiude solo il riquadro aperto (Storia, Altro, Connettore AI) e rimette il focus sul suo pulsante; se non ce n'è, chiude la sezione aperta, poi toglie la selezione. Dopo [[Canc]] resta selezionato l'elemento successivo (o il precedente, o il contenitore), così puoi eliminare più elementi di fila.

Ogni comando della palette può avere fino a tre combinazioni: scegli **Scorciatoie da tastiera…** nella palette, seleziona un comando e premi la combinazione nuova. Le combinazioni riservate al browser (copia, incolla, nuova scheda…) non si possono assegnare. Le tue scorciatoie valgono solo per te, su ogni browser, e ognuna si può ripristinare, anche tutte insieme con **Ripristina tutte**.

## Histeon dentro Gutenberg

Anche senza aprire il builder, nell'editor a blocchi di WordPress un elemento di Histeon selezionato ha i suoi pannelli nella barra laterale:

- **Histeon · Aspetto.** Spazi, testo, colori e bordi, impaginazione e dimensioni, come pulsanti con i valori del design del sito: chi non scrive CSS cambia l'aspetto con un clic. Il secondo clic su un pulsante attivo lo toglie. **Quando** sceglie uno stato (al passaggio del mouse, con il focus…) e **Su quali schermi** una larghezza del sito: il pulsante scrive solo lì. Alcune impostazioni compaiono quando servono, per esempio le colonne solo per una griglia.
- **Histeon · Classi.** Le classi dell'elemento, da aggiungere o togliere. L'aspetto si cambia sulla classe: un elemento senza classi chiede prima un nome.

Sotto la pagina, **Histeon · Codice** mostra l'HTML, il CSS e il JavaScript dell'elemento. È lo stesso CSS che scrivono i pulsanti di Aspetto: dopo un clic la dichiarazione toccata è selezionata nel codice, e una nota dice cosa è cambiato («padding di `.card`: da … a …»). Così si vede sempre che cosa modifica un pulsante, e dove.

Le modifiche di stile si vedono subito nella pagina e si salvano con **Salva in sviluppo**: vanno online con la prossima release, come quelle fatte nel builder.

### I componenti in Gutenberg

Un componente si vede com'è sul sito. Selezionandolo, la barra laterale mostra i suoi contenuti (le props): testi, immagini dalla libreria dei media, collegamenti con i suggerimenti delle pagine del sito, scelte brevi come pulsanti. **Valore predefinito** riporta un contenuto a quello del componente.

Se il componente ha delle **aree** (slot), sotto di lui compare un riquadro per ciascuna, con il suo nome: dentro si aggiungono e si modificano blocchi come in una pagina, mentre il resto del componente resta com'è. **Aggiungi contenuto a «…»** crea l'area che manca.

Chi ha il ruolo **Cliente Histeon** vede tutto questo in sola lettura, insieme al codice: capisce che cosa cambierebbe e dove, e le modifiche le fa chi gestisce il sito.

### La libreria di sezioni in Gutenberg

Nel menu **⋮** dell'editor, **Histeon · Libreria** apre la libreria di sezioni nella barra laterale. **Inserisci** mette la sezione dopo il blocco selezionato, con i suoi stili. Se una sua classe esiste già sul sito con stili diversi, la sezione la porta con un nome nuovo (`card` diventa `card-2`, con le sue parti), così il resto del sito non cambia; un avviso dice quali.

### Elenchi e condizioni in Gutenberg

Selezionando un elenco ripetuto (un loop), **Histeon · Ripeti** dice in una frase che cosa mostra e permette di scegliere la query, quanti elementi e quanti saltare. Selezionando un ramo di una condizione, **Histeon · Condizione** la scrive a parole e la compone con tre campi: che cosa controllare, la condizione e il valore. Sotto c'è come si scrive nel codice, per chi preferisce scriverla a mano.

### Bloccare la struttura di una pagina

Nelle impostazioni della pagina, in Gutenberg, **Histeon · Struttura → Struttura bloccata** lascia a chi non costruisce con Histeon (per esempio un redattore) soltanto i testi e le immagini: niente blocchi aggiunti, tolti o spostati. Il sito lo controlla anche al salvataggio. Chi costruisce continua a cambiare tutto.

### Comandi rapidi in Gutenberg

**Ctrl+K** (⌘K sul Mac) apre la palette dei comandi di WordPress, che contiene anche quelli di Histeon: scrivi «Histeon» per aprire la pagina nel builder, vederne le versioni, andare alle release, inserire una sezione dalla libreria o salvare gli stili in sviluppo.

### La guida in cinque passi

La prima volta che apri il builder, una guida breve mostra le cinque parti dove si fa una pagina: la pagina stessa, la struttura, lo stile, il codice, il salvataggio e la pubblicazione. Si scorre con **Avanti** o con le frecce, si chiude con **Salta la guida** o Esc, e non torna più da sola. Per rivederla: palette dei comandi (Ctrl+K) → **Guida in cinque passi**.
