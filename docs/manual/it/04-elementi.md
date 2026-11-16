# Gli elementi

Una pagina Histeon è fatta di elementi HTML veri: sezioni, titoli, paragrafi, immagini, link. Questo capitolo spiega come aggiungerli, organizzarli, scriverne il testo e modificarne il codice e le proprietà.

## Inserire un elemento

Apri il menu di inserimento in uno di questi modi:

- il pulsante **Aggiungi** nella barra in alto;
- il pulsante **+** nell'intestazione della Struttura;
- [[Ctrl+I]], anche mentre scrivi in un campo.

In cima al menu c'è la ricerca (**Cerca un elemento o un componente…**) e, sotto, l'indicazione esatta di dove andrà il nuovo elemento: «Dentro …, in fondo» se l'elemento selezionato è un contenitore, altrimenti «Dopo …», oppure «In fondo alla pagina» se non hai selezionato nulla. I pulsanti **Prima**, **Dentro** e **Dopo** accanto cambiano il posto. Scegli con un clic, oppure con le frecce e [[Invio]].

| Gruppo | Che cosa contiene |
|---|---|
| **Elementi** | Sezione, Contenitore, Titolo, Paragrafo, Link, Pulsante, Immagine, Lista, Condizione, Loop di articoli, Slot (nei componenti), Contenuto del post |
| **Interazioni** | Schede, Fisarmonica, Menu mobile, Selettore di lingua, Comparsa allo scorrimento: già accessibili, senza scrivere JavaScript |
| **Media** | **Immagine dalla libreria…** e **SVG (incolla o file)…** |
| **Componenti** | I componenti del sito |
| **Blocchi PHP del sito** | I blocchi che il tema o un plugin del sito mettono a disposizione |

Ogni elemento inserito è un passo che si annulla con [[Ctrl+Z]].

## La Struttura

Il pannello **Struttura** mostra la pagina come un albero: ogni riga è un elemento, con i suoi figli rientrati sotto. Un clic seleziona l'elemento (anche nella tela), la freccia accanto apre e chiude i figli.

Ogni riga ti aiuta a riconoscere l'elemento senza aprirlo:

- **l'etichetta**, se gliene hai data una, oppure il tag e la classe;
- **l'etichetta automatica BEM**: un elemento senza etichetta né classe, dentro un blocco con una classe, mostra il suo nome BEM; per esempio un `h2` dentro `.card` mostra `card__title`;
- **il nome della sezione**: una sezione o un contenitore prende il nome dal suo primo titolo (per esempio «I giusti»), aperto o chiuso; senza titoli, dal suo `aria-label` o dal suo `id`;
- **il testo**, tra virgolette: poche parole del titolo o del primo testo che contiene. Per un'immagine, il suo testo alternativo. Passando sopra la riga leggi il testo completo;
- **che cosa è**, dopo il testo e più in piccolo: «Paragrafo», «Titolo H2», «Scheda»…

La formattazione dentro un testo (grassetti, corsivi, link, a capo) non è una riga a sé: un paragrafo con un grassetto e un a capo resta una riga sola, con le sue parole. La freccia lo apre, se vuoi arrivare al grassetto.

In cima alla Struttura il campo **Cerca nella struttura** trova gli elementi per parole del testo, tag, classe, id (`#chi-siamo`), nome o tipo («paragrafo»): servono tutte le parole scritte. I risultati hanno una barra colorata a sinistra e i loro rami si aprono; [[Invio]] seleziona il successivo, [[Maiusc+Invio]] il precedente, [[Esc]] svuota il campo.

Sopra la tela, il **percorso** dice dove sei: per esempio «Pagina › I giusti › Corpo › Il giusto legge…». Un clic su un passo seleziona quell'elemento; «Pagina» toglie la selezione.

**Più elementi insieme:** [[Ctrl]]+clic (o [[⌘]]+clic sul Mac), nella Struttura o nella tela, aggiunge un elemento alla selezione o lo toglie; [[Maiusc]]+clic seleziona tutti i fratelli fra quello selezionato e quello cliccato. Il percorso sopra la tela dice quanti altri sono selezionati. **Duplica**, **Elimina**, **Sposta su/giù** e **Avvolgi** (dal menu contestuale, dalla palette o con i loro tasti) agiscono su tutti, in un solo passo di annullamento; i fratelli vicini finiscono insieme nello stesso contenitore. Le **Proprietà** mostrano l'elemento selezionato per primo. Un clic semplice torna a un elemento solo.

Per spostare un elemento trascina la sua riga nella Struttura: verso l'alto o il basso di una riga va prima o dopo, al centro va dentro, dove è ammesso.

## Spostare, duplicare, eliminare

| Azione | Come |
|---|---|
| Spostare su o giù tra i fratelli | [[Alt+↑]] / [[Alt+↓]] |
| Uscire dal contenitore, o entrare nell'elemento prima | [[Alt+←]] / [[Alt+→]] |
| Spostare ovunque | Trascinando la riga nella Struttura o la linguetta nella tela |
| Duplicare | [[Ctrl+D]] |
| Eliminare | [[Canc]] |
| Selezionare il genitore | Dal menu contestuale |

Gli stessi comandi ci sono anche nel pannello **Proprietà**, sotto **Nella struttura**: **Su**, **Giù**, **Duplica**, **Elimina**.

## Il menu contestuale

Fai clic destro su una riga della Struttura o su un elemento nella tela (o premi il tasto menu, o [[Maiusc+F10]]). In tela il clic destro seleziona anche l'elemento sotto il puntatore; con [[Maiusc]] premuto si apre invece il menu del browser. Ci si muove con le frecce, [[Esc]] lo chiude.

| Voce | Che cosa fa |
|---|---|
| **Modifica il componente** | Su un componente: apre la sua definizione sul posto |
| **Scrivi il testo nella pagina** | Su un testo: lo apre in modifica nella tela |
| **Copia** | Copia l'elemento con gli stili delle sue classi |
| **Incolla qui** | Incolla dentro o dopo l'elemento |
| **Duplica** | Come [[Ctrl+D]] |
| **Avvolgi in un div / una section / un link** | L'elemento diventa figlio di un nuovo contenitore |
| **Togli il contenitore** | L'elemento sparisce e i suoi figli prendono il suo posto |
| **Crea componente…** | Trasforma l'elemento in un componente riutilizzabile |
| **Classi BEM ai figli** | Dà ai figli senza classe un nome `blocco__parte` |
| **Rinomina** | Cambia l'etichetta nella Struttura ([[F2]]) |
| **Copia HTML** | Copia il codice dell'elemento |
| **Copia stile** / **Incolla stile** | Prende le classi e l'attributo `style` di un elemento e li dà a un altro (sostituendo i suoi); si annulla con [[Ctrl+Z]] |
| **Seleziona il genitore**, **Sposta su**, **Sposta giù**, **Elimina** | Come i comandi omonimi |

Le voci che non si possono usare restano visibili ma disattivate; passandoci sopra leggi il motivo.

## Scrivere il testo nella tela

Fai doppio clic su un titolo, un paragrafo, un link o un pulsante: l'elemento si apre in modifica sul posto, con il bordo corallo.

- [[Invio]] o un clic fuori confermano; [[Esc]] rimette il testo com'era.
- [[Maiusc+Invio]] va a capo.
- [[Ctrl+B]] e [[Ctrl+I]] mettono grassetto e corsivo.
- [[Ctrl+S]] conferma e salva.

Il testo incollato arriva sempre come testo semplice. Si modificano qui solo gli elementi che contengono **solo testo**, anche formattato. Un testo con espressioni (per esempio `{this.title}`), un elemento con altri elementi dentro o un elemento ripetuto da un loop si modificano nel pannello HTML. Se scrivi nella tela qualcosa che lì non si può tenere, il testo non si perde: passa nel pannello HTML, così come l'hai scritto, da completare lì.

## Il pannello HTML

Il pannello **HTML** mostra il codice dell'elemento selezionato; il pulsante **Pagina intera** mostra tutta la pagina. Quello che scrivi si vede subito nella tela.

- Dentro un attributo `class="…"` il pannello propone le classi del framework.
- Se il codice ha un errore, il pannello lo segnala e la tela mostra l'ultima versione valida, finché non lo correggi. Il codice sbagliato conta come «non salvato»: [[Ctrl+S]] dice che cosa non ha salvato, chiudendo la scheda il browser avvisa, e prima di selezionare un altro elemento o annullare Histeon chiede se lasciarlo perdere. Lo stesso vale per una regola del foglio globale con le graffe non bilanciate.
- [[Ctrl]]+clic (⌘+clic su Mac) o [[F12]] su una classe o un id apre il pannello CSS su quel selettore.

> Se un elemento contiene contenuto scritto fuori da Histeon che il codice non può rappresentare fedelmente, il pannello diventa di sola lettura. Correggilo dalla Struttura o dalle Proprietà.

### Abbreviazioni (stile Emmet)

Scrivi un'abbreviazione e premi [[Tab]]: diventa HTML, con il cursore nel primo elemento vuoto.

| Scrivi | Ottieni |
|---|---|
| `section.hero>h2.hero__title{Ciao}+p` | una section con titolo e paragrafo |
| `.card#top` | `<div id="top" class="card"></div>` |
| `a[href="/x" target=_blank]{Vai}` | un link con i suoi attributi |
| `ul>.item$*3` | tre `li` con le classi `item1`, `item2`, `item3` |
| `div>(h3+p)*2^footer` | gruppi ripetuti; `^` risale di un livello |

Una parola sola si espande solo se è un tag HTML: [[Tab]] dopo una parola qualunque indenta, come sempre.

## Il linguaggio del pannello HTML

Il pannello HTML usa HTML normale, con qualche regola più severa e alcune aggiunte per i contenuti dinamici.

**Regole dell'HTML.**

- Ogni elemento si chiude esplicitamente: `<p>Testo</p>`, mai `<p>` da solo. `<div/>` vale come `<div></div>`.
- I tag HTML si scrivono in minuscolo. Un nome con l'iniziale maiuscola è un componente: `<Card>`.
- Un a capo tra due elementi non diventa uno spazio. Se ti serve uno spazio tra due link su righe diverse, scrivi `{" "}` o tienili sulla stessa riga.
- I commenti `<!-- … -->` vengono tolti.

**Espressioni.** Tra graffe scrivi un valore da mostrare: `{this.title}` è il titolo della pagina corrente. Funzionano nel testo e negli attributi: `<a href={post.url}>`. Per scrivere una graffa vera usa `\{` e `\}`. I valori principali sono `this` (il contenuto corrente), `site`, `user` (chi visita), `url` e `data` (le sorgenti di dati). Un valore che manca non rompe la pagina: diventa vuoto. Tutti i valori disponibili sono descritti nel capitolo [Contenuti e dati](#it-contenuti-e-dati).

Puoi trasformare un valore con i modificatori:

| Esempio | Risultato |
|---|---|
| `{post.excerpt.truncateWords(20)}` | Le prime 20 parole |
| `{post.date.date("j F Y")}` | La data nel formato scelto |
| `{post.title.upper()}` | Il titolo in maiuscolo |
| `{post.excerpt ?? "Nessun estratto"}` | Un testo di ripiego se il valore manca |

**Condizioni.**

```html
{#if this.image}
  <img src={this.image.url} alt={this.image.alt}>
{:else}
  <p>Nessuna immagine</p>
{/if}
```

**Loop.** Ripetono un pezzo di pagina per ogni risultato di una query:

```html
{#loop posts(limit: 3) as post}
  <article>
    <h3><a href={post.url}>{post.title}</a></h3>
  </article>
{:else}
  <p>Ancora nessun articolo.</p>
{/loop}
```

La parte dopo `{:else}` si vede quando la query non trova niente. Lì `post` non esiste.

**Etichette.** L'attributo `xb:label="Ultimi articoli"` dà un nome all'elemento nella Struttura. Non arriva nella pagina.

## Incollare

Fuori dai campi di testo e dagli editor di codice:

- [[Ctrl+C]] copia l'elemento selezionato **con gli stili** delle classi che usa;
- [[Ctrl+V]] lo incolla, anche in un altro sito Histeon.

Quando incolli da un altro sito si apre **Incolla da …**, che mostra cosa succede a ogni classe:

- **nuova**: viene aggiunta con i suoi stili;
- **uguale a quella del sito**: si usa quella che c'è già;
- **in conflitto**: scegli se **importa con un nome nuovo** (per esempio `card-2`) o **usa quella di questo sito**.

I componenti che il sito non ha vengono segnalati: resteranno vuoti.

Puoi incollare anche **HTML da altre fonti**, come una pagina web o un altro builder: diventa elementi modificabili. Prima vengono tolti script, stili incorporati, iframe, form e indirizzi `javascript:`. Le classi restano.

## SVG

**Aggiungi → SVG (incolla o file)…** inserisce un'icona o un'illustrazione vettoriale come elementi modificabili. Incolla il codice o scegli un file `.svg` dal computer (fino a 200 KB). Prima dell'inserimento l'SVG viene ripulito da script, stili, animazioni e attributi di evento, e la finestra ti dice cosa è stato tolto. Per colori che seguono il testo, usa `currentColor`.

**Aggiungi → Icona dalla libreria…** apre le circa 1.700 icone di [Lucide](https://lucide.dev) (licenza ISC) incluse in Histeon: niente CDN né font di icone. Si cercano per nome inglese (`house`, `arrow right`, `mail`); un clic inserisce l'icona come SVG in linea, con la classe `icon icon--nome`, il colore del testo (`currentColor`) e `aria-hidden="true"` (se l'icona da sola dice qualcosa, dai un nome accessibile al pulsante o al link che la contiene).

## Proprietà e attributi

Il pannello **Proprietà** mostra l'elemento selezionato: il **Tag**, il **Contenuto** se è un testo, e i campi che servono a quel tipo di elemento, chiamati con parole comprensibili.

| Elemento | Campi |
|---|---|
| Titolo | Il livello, da h1 a h6, con un clic |
| Link | Indirizzo (anche **Scegli un file dalla libreria…**, per un PDF o un'immagine), **Apri in una nuova scheda**, suggerimento |
| Immagine | **Scegli immagine…** dalla libreria, testo alternativo, larghezza, altezza, caricamento |
| Pulsante | Tipo (pulsante, invia, azzera), disattivato |
| Campo di un modulo | Tipo, nome, testo di esempio, obbligatorio |
| Video | File, copertina, comandi, partenza automatica, senza audio, in loop |
| Contenuto incorporato | Indirizzo, titolo, caricamento |
| Elenco numerato | Parte da, al contrario |

Ogni elemento ha anche l'**Identità**: l'id (utile per i link `#ancora`) e il nome accessibile. Dove manca qualcosa di importante, come il testo alternativo di un'immagine, il campo te lo dice.

Sotto, la sezione **Attributi** elenca tutti gli attributi: modifica il valore, rimuovine uno con ✕ o aggiungine uno nuovo con nome e valore. Un campo svuotato toglie l'attributo.

> Quello che si può salvare lo decide sempre il server, in base ai tuoi permessi: per esempio gli attributi di evento come `onclick` restano solo a chi può scrivere HTML senza filtri.

## Azioni rapide

- **Classi BEM** (nelle Proprietà) o **Classi BEM ai figli** (nel menu contestuale): i figli senza classe ricevono un nome `blocco__parte`, dove il blocco è la classe dell'elemento e la parte viene dall'etichetta o dal ruolo del figlio (`title`, `text`, `image`, `link`…).
- **Rinomina:** doppio clic sulla riga nella Struttura o [[F2]]. L'etichetta è una riga di testo di al massimo 60 caratteri; un'etichetta vuota torna a quella automatica.
- **Crea componente…:** trasforma l'elemento e il suo contenuto in un componente. Il capitolo [I componenti](#it-componenti) spiega come.
