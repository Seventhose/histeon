# I componenti

Un componente è un pezzo di pagina che costruisci una volta e usi dove vuoi: una scheda evento, un'intestazione, un pulsante con icona, un blocco di contatti. Ha le sue **props** (i valori che cambiano da un uso all'altro) e i suoi **slot** (i punti in cui inserire altro contenuto). Quando lo modifichi, cambia ovunque è usato.

## Come funziona

Ogni componente ha:

- un **nome**, quello che leggi nei menu, per esempio «Scheda evento»;
- una **chiave** stabile, in minuscolo con i trattini, per esempio `scheda-evento` o `ui/card-hero`. La chiave non cambia quando sposti il sito, lo esporti o lo importi altrove;
- una **definizione**: il markup, gli stili delle sue classi, eventualmente un JavaScript, le props e gli slot.

Nel pannello HTML un componente si scrive con il nome della chiave in maiuscolo: `scheda-evento` diventa `<SchedaEvento>`, `ui/card-hero` diventa `<Ui.CardHero>`. L'iniziale maiuscola è ciò che distingue un componente da un tag HTML.

I componenti del sito si trovano nella sezione **Componenti** del builder e in **Histeon → Componenti**.

## Creare un componente

### Da zero

1. Nella barra in alto premi **Nuovo componente** (oppure **Nuovo componente…** nella palette dei comandi).
2. Scrivi la **Chiave** (per esempio `ui/card-hero`) e il **Nome**.
3. Premi **Crea e apri**.

Il componente vuoto si apre subito in modifica, sopra il documento su cui stavi lavorando.

### Da un elemento della pagina

Spesso un componente nasce da qualcosa che hai già costruito:

1. Seleziona l'elemento, per esempio la scheda che vuoi riutilizzare.
2. Nelle **Proprietà** premi **Crea componente…**, oppure scegli la stessa voce nel menu contestuale della Struttura.
3. Scrivi il **Nome**: la **Chiave** viene proposta da sola («Scheda evento» diventa `scheda-evento`), e puoi cambiarla.
4. Premi **Crea**.

L'elemento e tutto il suo contenuto diventano la definizione del componente, e al suo posto nella pagina resta un'istanza del componente.

> **Crea componente…** è disattivato quando la parte selezionata usa valori che esistono solo lì, come la variabile di un loop esterno (`post`). Un componente vede solo i dati generali (`this`, `site`, `user`, `url`, `data`) e le sue props: i valori che vengono da fuori vanno passati come props. Le variabili dei loop che stanno dentro la selezione vanno bene.

## Le props

Le props sono i valori che cambiano da un uso all'altro: il titolo della scheda, l'immagine, il link. Mentre modifichi un componente, l'icona **Props del componente** nella colonna di sinistra apre il loro pannello:

- **Chiave del componente**, con l'esempio di come si scrive nel codice;
- l'elenco delle props, ognuna con il suo **tipo**, un valore **default** facoltativo e un **esempio** facoltativo; ✕ la rimuove;
- un campo **nuova prop** con il pulsante **Aggiungi**.

| Tipo | Per che cosa |
|---|---|
| `string` | Un testo breve |
| `number` | Un numero |
| `boolean` | Vero o falso, per esempio una variante evidenziata |
| `url` | Un indirizzo |
| `image` | Un'immagine |
| `rich-text` | Un testo con formattazione |
| `enum` | Una scelta tra valori fissi |
| `array` | Una lista, da ripetere con un loop |
| `object` | Un gruppo di valori |

Nel codice del componente le props si leggono con `{props.nome}`:

```html
<article class="card">
  <img src={props.image.url} alt={props.image.alt}>
  <h3 class="card__title">{props.title}</h3>
  {#if props.featured}<span class="card__badge">In evidenza</span>{/if}
  {@slot}
</article>
```

### Il contenuto di prova

Un componente aperto da solo non ha una pagina che gli dia le props, e spesso è fatto per essere riempito: senza aiuti la tela sarebbe bianca. Per questo, solo nella tela del builder:

- ogni prop vale il suo **esempio**, se c'è, altrimenti il suo default, altrimenti un valore preso dal tipo: il nome della prop in parole per un testo («mainTitle» diventa «Main title»), un'immagine segnaposto, la prima scelta di un `enum`, «vero» per un `boolean` (così si vede quello che la prop mostra);
- gli elementi con l'attributo `hidden` si vedono, con un contorno tratteggiato e l'etichetta «nascosto nella pagina»;
- un contenitore vuoto mostra un segnaposto: «vuoto», oppure «vuoto: lo riempie uno script» se il componente ha del JavaScript (per esempio un indice che uno script compila).

Niente di tutto questo finisce nel componente salvato né nelle pagine: le pagine usano il default, o il valore che danno loro.

### Le varianti: interruttori per chi usa il componente

Una «card scura» o una «card grande» non serve un secondo componente: è una **variante**. Nel pannello del componente, sotto **Varianti**, scrivi il nome (per esempio *Scura*) e premi **Aggiungi una variante**. Histeon:

- aggiunge una prop sì/no `scura`, spenta di serie;
- dà al primo elemento la classe `card--scura` quando la prop è accesa (`class="card {props.scura ? "card--scura" : ""}"`);
- prepara il CSS di `.card--scura` con le variabili che `.card` dichiara, da ridefinire: per esempio `--fondo: black;` al posto di `--fondo: white;`.

Il CSS della variante si trova nel pannello **CSS**, tra le pillole dell'elemento. Nelle pagine la variante è un interruttore tra le props, nel builder e nell'editor a blocchi; nel builder si può accendere subito, prima di pubblicare il componente. Il primo elemento del componente deve avere una classe: è il nome da cui nasce quella della variante.

## Gli slot

Uno slot è il punto in cui chi usa il componente può mettere il contenuto che vuole: un paragrafo, un pulsante, un altro componente.

- Nella definizione, `{@slot}` segna lo slot principale. Lo trovi anche nel menu **Aggiungi** come **Slot (nei componenti)**.
- Puoi avere più slot con un nome: `{@slot media}`, `{@slot azioni}`.
- Uno slot che nessuno riempie non mostra nulla. Per nascondere un contenitore vuoto, chiedi `{#if !slots.azioni.empty}…{/if}`.

## Usare un componente

Per inserire un componente in una pagina:

- dal menu **Aggiungi**, nel gruppo **Componenti** (puoi cercarlo per nome o per chiave);
- oppure scrivendolo nel pannello HTML.

Nel codice le props si passano come attributi, e il contenuto tra apertura e chiusura va nello slot principale:

```html
<Card title="Concerto d'estate" featured>
  <p>Sabato alle 21, ingresso libero.</p>
</Card>
```

Per riempire uno slot con un nome usa `{#slot nome}`:

```html
<Card title={post.title}>
  {#slot media}<img src={post.image.url} alt={post.image.alt}>{/slot}
  <p>{post.excerpt}</p>
</Card>
```

Un valore tra virgolette è un testo (`title="Ciao"`), anche con espressioni dentro (`title="Ciao {user.name}"`); un valore tra graffe è un'espressione (`title={post.title}`, `count={3}`); una prop scritta senza valore vale «vero» (`featured`). Dentro un loop, passa al componente i valori del loop: `<Card title={post.title}>`.

Se selezioni un'istanza, il pannello **Proprietà** mostra le sue **Props**: per ognuna scegli se è un **Testo** o un'**Espressione** e scrivi il valore.

## Modificare un componente

Un componente si modifica **sul posto**, senza lasciare la pagina:

- doppio clic sul componente nella tela o nella Struttura;
- **Modifica il componente** nel menu contestuale della Struttura;
- il pulsante **Modifica il componente** nelle Proprietà dell'istanza.

Durante la modifica tutti i pannelli (Struttura, HTML, CSS, JavaScript, Props) lavorano sulla definizione del componente. In alto compare il percorso, per esempio **Home › Componente Card**, e due pulsanti:

- **Salva ed esci** salva il componente in sviluppo, stili compresi, e torna alla pagina: la tela mostra subito la nuova versione e l'istanza da cui eri partito è di nuovo selezionata;
- **Esci senza salvare** scarta le modifiche (dopo una conferma, se ce ne sono) e torna indietro.

[[Ctrl+S]] salva senza uscire. Un componente dentro un altro componente si apre sopra il primo, e il percorso in alto permette di risalire di uno o più livelli.

Mentre il componente è aperto, la pagina resta in memoria così com'era: modifiche non salvate, selezione e cronologia di annullamento. Uscendo ritrovi tutto, compreso [[Ctrl+Z]] sulle modifiche fatte prima.

> Le modifiche a un componente valgono ovunque il componente è usato. Prima di cambiarne la struttura, controlla in quali pagine compare.

### Un componente in uso non si cancella

Se un componente è usato da una pagina, un template o un altro componente, online o anche solo in sviluppo, non si può spostare nel cestino né cancellare: né da wp-admin né dall'editor a blocchi. Il messaggio dice dove è usato, per esempio *Il componente «Scheda» è in uso e non si può eliminare. Toglilo prima da: Home, Chi siamo (in sviluppo).* Toglilo da quelle pagine, salva, e poi cancellalo.

## Stili e JavaScript

Gli stili di un componente sono quelli delle sue classi, scritti nel pannello CSS come per ogni altro elemento (vedi il capitolo [Gli stili](#it-stili)). Valgono in tutto il sito e arrivano in una pagina solo se la pagina usa il componente. Per stili che funzionano con qualsiasi framework, usa i token `--xb-*` di Histeon CSS.

Nel pannello **JavaScript** puoi scrivere un modulo JavaScript del componente: va nella pagina come `<script type="module">`, dopo il contenuto, e non viene eseguito nella tela. Il JavaScript si scrive solo con il permesso di WordPress per l'HTML senza filtri; per gli altri utenti il pannello è in sola lettura.

## Come si pubblica

Un componente segue le stesse regole delle pagine:

1. **Salva ed esci** (o [[Ctrl+S]]) salva il componente in **sviluppo**. Il sito pubblico non cambia.
2. L'**Anteprima** della pagina mostra già la nuova versione del componente, così puoi controllarla dove è usato.
3. **Pubblica…** elenca il componente tra le modifiche da portare online, insieme alle pagine e agli stili. Con la release, il componente cambia in tutte le pagine che lo usano nello stesso momento.

Nella finestra **Pubblica…**, un componente non ha una pagina sua da confrontare: il confronto visivo lo mostra attraverso la pagina aperta nel builder. Se qualcosa non va, il ripristino della release precedente riporta indietro anche il componente.

> Un componente creato da un elemento esiste subito, ma nessuna pagina online lo usa finché non salvi e pubblichi la pagina in cui l'hai creato.

## La libreria di sezioni

Una sezione riuscita, per esempio una hero, una griglia di schede o un piè di pagina, si salva per riusarla in altre pagine o in un altro sito.

1. Seleziona l'elemento nella tela o nella struttura.
2. Apri la sezione **Libreria** nella barra a sinistra, scrivi un nome e premi **Salva nella libreria**. Con l'elemento si salvano gli stili delle sue classi.
3. Per riusarla premi **Inserisci**: si apre la finestra di incolla, la stessa del copia e incolla tra siti, che mostra le classi nuove, uguali o in conflitto.
4. Con **Esporta** scarichi tutta la libreria in un file `.xblibrary`; in un altro sito Histeon lo carichi con **Importa…**. Le sezioni che ci sono già non si duplicano.

> Le immagini restano agli indirizzi del sito d'origine. Per portarle davvero usa il trasloco del sito, oppure caricale nella libreria media del nuovo sito.

### Farla ricostruire all'agente

**Fai ricostruire all'agente**, accanto a una sezione della libreria, manda all'agente AI collegato una richiesta: ricostruire la sezione in questa pagina con le classi e i token del progetto. L'agente non riceve il markup da copiare ma la sua descrizione: le parti, il loro ruolo, e la disposizione con il token del progetto più vicino a ogni misura e colore. La richiesta compare nel pannello ✦.
