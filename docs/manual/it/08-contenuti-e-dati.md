# Contenuti e dati

Una pagina di Histeon non contiene solo testo scritto a mano: può mostrare il titolo del contenuto che si sta guardando, gli ultimi articoli, gli eventi della settimana, il prezzo di un prodotto. Questo capitolo spiega da dove arrivano i dati e come metterli nella pagina.

## Le espressioni: i segnaposto tra graffe

Un dato si scrive tra graffe, dentro un testo o un attributo: `{this.title}` diventa il titolo del contenuto corrente, `{site.name}` il nome del sito. Nel pannello HTML si scrivono a mano; nei pannelli dei dati c'è sempre l'espressione pronta da copiare o da inserire nell'elemento selezionato.

Le radici disponibili in ogni pagina:

| Radice | Cosa contiene | Esempi |
|---|---|---|
| `this` | Il contenuto che si sta guardando (articolo, pagina, prodotto…) | `{this.title}`, `{this.url}`, `{this.excerpt}`, `{this.date}`, `{this.image.url}`, `{this.author.name}`, `{this.categories}` |
| `site` | Il sito | `{site.name}`, `{site.description}`, `{site.url}`, `{site.year}`, `{site.logo}` |
| `user` | Chi visita | `{user.loggedIn}`, `{user.name}` |
| `url` | L'indirizzo della pagina | `{url.path}`, `{url.query.q}` |
| `data` | Query e sorgenti JSON lette senza loop | `{data.orari[0].giorno}` |
| `facets` | I filtri dei loop | `{facets.eventi.total}` |

Dentro un loop si aggiunge la variabile scelta (di solito `item`) e `index`, la posizione nel loop; dentro un componente ci sono anche `props` e `slots`.

Con Histeon Publish viaggia solo `url.path` (da Histeon 0.5.4): in Publish è il percorso della pagina costruita, sul sito quello della richiesta, uguali in un sito statico. `url.query`, `user` e `facets` restano del sito: un contenuto che li usa non si manda a Histeon Publish (capitolo 12).

Alcune regole utili:

- **Niente errori a schermo.** Un nome che non esiste, un campo vuoto o un errore valgono "niente": la pagina resta in piedi e quel punto resta vuoto.
- **Valori di ripiego.** `{this.excerpt ?? "Nessun estratto"}` usa il testo tra virgolette quando il valore manca; `.default("…")` scatta anche su un testo vuoto o su zero.
- **Confronti.** `==`, `!=`, `<`, `>`, `&&`, `||`, `!` e il condizionale `cond ? "sì" : "no"`. Non ci sono calcoli né chiamate libere: è un linguaggio per mostrare dati, non per programmare.
- **Sicurezza.** Ogni valore esce ripulito: un dato non diventa mai markup, quindi non può rompere la pagina o iniettare codice.

### Modificatori

Un modificatore trasforma il valore e si scrive dopo un punto: `{this.title.upper()}`.

| Modificatore | Cosa fa |
|---|---|
| `inline()` | Stampa il testo con i soli formati in linea (grassetto, corsivo, link): serve alle props di testo formattato |
| `upper()`, `lower()`, `capitalize()`, `trim()`, `stripTags()` | Maiuscole, minuscole, iniziale maiuscola, spazi, tag |
| `truncateWords(20)`, `truncateChars(120)` | Accorciano il testo e aggiungono "…" |
| `date("j F Y")` | Formatta una data; senza formato usa quello del sito |
| `number(2)` | Numero con i separatori della lingua del sito |
| `length()`, `first()`, `last()`, `join(", ")`, `slice(0, 3)`, `includes(x)`, `pluck("name")` | Liste e testi |
| `groupBy("date", "Y")`, `groupBy("title", "letter")` | Divide un elenco in gruppi (`key` e `items`) per anno, per iniziale o per il valore di un campo, per un titolo per gruppo |
| `default(x)` | `x` se il valore è vuoto, falso o zero |
| `json()`, `slug()` | Testo JSON, testo da indirizzo |

Un elenco diviso in gruppi, con un titolo per gruppo:

```html
{#loop data.ultimiArticoli.groupBy("date", "Y") as anno}
  <h2>{anno.key}</h2>
  {#loop anno.items as post}<p><a href={post.url}>{post.title}</a></p>{/loop}
{/loop}
```

Lo stesso sul risultato di una query, con una query sola: `.groupBy(…)` subito dopo la chiamata, con testi scritti (il campo e, se serve, il modo).

```html
{#loop notizie(limit: 50).groupBy("date", "Y") as anno}
  <h2>{anno.key}</h2>
  {#loop anno.items as post}<p><a href={post.url}>{post.title}</a></p>{/loop}
{/loop}
```

Dopo la query è ammesso solo un `groupBy`: niente altri modificatori. In Gutenberg lo stesso si sceglie nel pannello del loop, alla voce **In gruppi**; nel builder, in **Dati e query**, alla voce **In gruppi** sotto **Usala nella pagina** (vedi «Usare una query nella pagina»).

### Senza scriverle: Inserisci un dato

Nel pannello **Proprietà**, sotto **Contenuto**, il pulsante **{ } Inserisci un dato** compone l'espressione per te:

1. **Dato**: cosa mostrare. L'elenco segue il punto della pagina: dentro un loop trovi per primi i campi del suo elemento (titolo, data, autore, immagine…), in un componente le sue props, poi la pagina, il sito e chi visita.
2. **Forma**: come mostrarlo. Una data come «4 novembre 2026» o «04/11/2026», un numero con i decimali, le prime parole di un testo, un elenco con il suo separatore, di un'immagine l'indirizzo o il testo alternativo.
3. **Se è vuoto, mostra**: il testo di ripiego.
4. **Testo prima** e **Testo dopo**: le parole intorno, per esempio «Pubblicato il ».

Sotto vedi come si scrive nel codice, per esempio `Pubblicato il {this.date.date("j F Y")}`; **Aggiungi al contenuto** lo mette in fondo al testo. Per una prop di un componente in modo **Espressione**, **Componi l'espressione** fa lo stesso e rilegge l'espressione che c'è già, se l'aveva scritta il compositore.

## Condizioni

Una **Condizione** mostra una parte della pagina solo se un'espressione è vera. Si aggiunge dal menu **Aggiungi** ([[Ctrl+I]]) → **Condizione**, oppure nel pannello HTML:

```html
{#if this.image}
  <img src={this.image.url} alt={this.image.alt}>
{:else if user.loggedIn}
  <p>Aggiungi un'immagine in evidenza.</p>
{:else}
  <p>Nessuna immagine.</p>
{/if}
```

Nel pannello Struttura la condizione compare come **Condizione**, con i suoi rami: **se …** per ogni test e **altrimenti** per l'ultimo. Sono falsi `false`, il vuoto, `0`, un testo vuoto e una lista vuota; tutto il resto è vero.

> Per un loop senza risultati non c'è un ramo "vuoto": avvolgi il loop in una condizione, per esempio `{#if data.eventi.length()}`.

## Loop e query

Un **loop** ripete un pezzo di pagina per ogni risultato: una scheda per ogni articolo, una riga per ogni orario. I risultati vengono da una **query**, cioè una ricerca salvata con un nome nella sezione **Dati e query** del builder.

Le query predefinite sono sempre disponibili e non serve crearle:

| Query | Cosa restituisce |
|---|---|
| `posts` | Contenuti (articoli, pagine, tipi personalizzati) |
| `terms` | Categorie e termini |
| `users` | Autori con contenuti pubblicati |
| `main` | La query della pagina che si sta guardando: un archivio, una ricerca |

### Creare una query

1. Apri **Dati e query** e premi **Nuova query**. Scegli il tipo: **Contenuti**, **Categorie e termini**, **Utenti** o **Della pagina**.
2. Nella vista **Visuale** scegli cosa mostrare: tipo di contenuto, **Filtri** per categoria, tag o tassonomia (**è uno di**, **non è**, **ha tutti**), autori, testo, **Ordine** (anche per campo personalizzato), **Quanti**, contenuti precisi o da escludere.
3. A destra vedi subito i dati: i primi 20 record come schede e, per ognuno, le chiavi con il loro valore e l'espressione pronta (`{item.title}`, `{item.image.url}`, `{item.author.name}`).
4. Salva: la query è salvata in sviluppo e arriva al sito con la prossima release.

La vista **Codice** mostra gli stessi argomenti in JSON. Gli argomenti che la vista visuale non conosce restano intatti e si modificano lì.

### Query pronte: la galleria

Nella sezione **Query**, **Dalla galleria…** aggiunge con un clic una query già scritta, da salvare e poi modificare come le altre:

- **Ultimi articoli**: i più recenti, senza quelli in evidenza;
- **Articoli correlati**: altri articoli della stessa categoria della pagina, senza la pagina stessa (il loop le passa da solo `this.id` e la categoria);
- **Prodotti in evidenza**: i prodotti segnati «in evidenza» in WooCommerce (compare solo se il negozio c'è);
- **Eventi futuri**: i contenuti di tipo «evento» con il campo «data» da oggi in poi, dal più vicino; tipo e campo si cambiano nella query.

### Usare una query nella pagina

- **Avvolgi in un loop**: seleziona nella pagina l'elemento da ripetere (una scheda, una riga di tabella) e premi il pulsante: l'elemento diventa il modello, ripetuto per ogni risultato.
- **Inserisci un loop nuovo**: aggiunge un loop già pronto, da modificare.

**In gruppi**, sopra i due pulsanti, divide il risultato della query in gruppi: **Per anno di pubblicazione**, **Per mese di pubblicazione** o **Per iniziale del titolo** (per le query di contenuti e per quella della pagina). La query resta una sola: è il loop che ne divide il risultato. Con un raggruppamento scelto, **Avvolgi in un loop** mette un titolo per ogni gruppo (`{gruppo.key}`, per esempio «2026») e, sotto, l'elemento selezionato ripetuto per ogni contenuto del gruppo; **Inserisci un loop nuovo** fa lo stesso con una scheda pronta. A destra l'anteprima dice quanti gruppi ci sono e quanti contenuti ha ciascuno. È lo stesso loop che si sceglie in Gutenberg e che si scrive a mano:

```html
{#loop ultimi_eventi(limit: 3).groupBy("date", "Y") as gruppo}
  <section>
    <h2>{gruppo.key}</h2>
    {#loop gruppo.items as item}<article><h3><a href={item.url}>{item.title}</a></h3></article>{/loop}
  </section>
{/loop}
```

Nel pannello HTML un loop si scrive così:

```html
{#loop ultimi_eventi(limit: 3) as item}
  <article>
    <h3><a href={item.url}>{item.title}</a></h3>
  </article>
{/loop}
```

### Query con parametri

Una query può lasciare al loop la scelta di alcuni valori. Negli argomenti, `"$limit ?? 10"` significa "il parametro `limit` del loop, oppure 10". Così la stessa query `ultimi_eventi` serve sia alla home (`ultimi_eventi(limit: 3)`) sia all'archivio (`ultimi_eventi(limit: 12)`). La vista visuale indica i valori che il loop può cambiare e ne modifica il valore predefinito. `-1` vale il massimo ammesso, cioè 100.

> I loop mostrano solo contenuti pubblicati, senza password e leggibili da chi visita, anche se la query chiede altro. I tipi di contenuto senza pagine pubbliche entrano nei loop solo se un amministratore li ammette in **Histeon → Impostazioni**, sezione **Contenuti non pubblici nei loop**.

Un risultato si legge anche fuori da un loop, con la radice `data`: `{data.ultimi_eventi[0].title}`.

## Sorgenti JSON

Una **sorgente JSON** contiene dati scritti da te: orari di apertura, sedi, voci di un listino. Si crea in **Dati e query** → **Nuova sorgente JSON** e si modifica come tabella (righe e colonne), come elenco di chiavi o nella vista **Codice**.

- In un loop: `{#loop data.orari as h}{h.giorno}: {h.ore}{/loop}`.
- Senza loop: `{data.orari[0].giorno}`.

Le sorgenti hanno versioni come il resto del sito e vanno online con la prossima release. Una sorgente e una query non possono avere lo stesso nome, e `posts`, `terms`, `main` e `users` sono riservati.

### Dati da un indirizzo

Una sorgente può anche leggere il JSON di un altro servizio (un calendario, un listino tenuto altrove): scegli **Da un indirizzo**, scrivi l'indirizzo `https://`, se serve la parte da usare (per esempio `items`) e ogni quanti minuti rileggerla, poi **Prova**. Si legge come una sorgente scritta qui. Per sicurezza il sito chiama solo gli host che un amministratore ha consentito, accanto alla sorgente; tiene una copia per il tempo scelto e, se il servizio non risponde, mostra l'ultima copia buona.

## Tipi di contenuto, tassonomie e campi

La sezione **Tipi e campi** del builder crea la struttura dei contenuti senza altri plugin:

- **+ Tipo di contenuto**: Eventi, Sedi, Persone… con nome singolare e plurale, nome tecnico, indirizzo, pagine proprie sì o no, genitori (come le pagine), funzioni dell'editor (titolo, contenuto, immagine in evidenza, riassunto, autore, commenti, revisioni, ordine e genitore) e chi lo gestisce (come gli articoli o come le pagine).
- **+ Tassonomia**: **Categorie (annidate)** o **Tag**, con i tipi a cui si applica.
- **+ Campo**: i campi di un tipo, degli articoli, delle pagine, dei termini o degli utenti.

I tipi di campo sono **Testo**, **Testo lungo**, **Numero**, **Data**, **Ora**, **Sì / no**, **Scelta**, **Immagine**, **File** (un PDF o un altro file della libreria), **Indirizzo web**, **Email**, **Contenuto collegato** e **Ripetitore** (righe con gli stessi campi: orari, tappe, voci di listino). Una riga può avere a sua volta un ripetitore, per esempio le tappe di un viaggio, ciascuna con i suoi orari: due livelli al massimo, e nel secondo niente altro ripetitore.

Una scelta può avere **più valori (caselle)** o mostrarsi come **pulsanti di scelta** invece del menu; un'immagine può diventare una **galleria**, un file o un contenuto collegato possono essere più d'uno. I valori multipli si leggono con un loop: `{#loop this.fields.foto as f}<img src={f.url} alt={f.alt}>{/loop}`.

> Un tipo nuovo esiste in WordPress solo dopo la pubblicazione: salva, poi **Pubblica…**. Il builder indica lo stato di ogni tipo. Dopo la pubblicazione il nome tecnico non si cambia più. Solo un amministratore crea tipi e campi; gli altri vedono i campi e ne usano le espressioni.

Un tipo **senza pagine proprie** (testimonianze, voci di un listino) non ha indirizzo, non compare nella ricerca né nell'API pubblica: si mostra solo dove lo metti con un loop.

### Compilare e leggere i campi

I campi si compilano nell'editor a blocchi, nel pannello **Campi** della barra laterale. Per un ripetitore ci sono **Aggiungi riga** e i comandi per spostare o togliere le righe. I campi di termini e utenti compaiono nelle schermate di WordPress del termine e nel profilo, sezione «Campi del profilo».

Nelle pagine:

- `{this.fields.prezzo}` nel modello di un singolo contenuto;
- `{item.fields.prezzo}` in un loop;
- `{#loop this.fields.orari as r}{r.giorno} {r.ore}{/loop}` per un ripetitore;
- `{#loop this.fields.tappe as t}{t.luogo}: {#loop t.orari as o}{o.giorno}{/loop}{/loop}` per un ripetitore dentro le righe di un altro;
- `{this.categories[0].fields.colore}` per un campo di una categoria, `{this.author.fields.ruolo}` per un campo dell'autore.

Il blocco **Usali nella pagina** elenca le espressioni dei campi del tipo selezionato, con il pulsante per inserirle.

### Gruppi di campi

Quando più tipi hanno gli stessi campi (contatti, prezzi, orari), un **gruppo di campi** li scrive una volta sola: in **Gruppi di campi** dai un nome, scegli **Solo questi tipi** oppure **Tutti tranne**, e aggiungi i campi. Ogni tipo scelto li riceve e li legge come i suoi (`{this.fields.email}`). Se un tipo ha già un campo con lo stesso nome, vale il suo; un tipo creato dopo riceve i campi quando salvi.

### I termini

Per una tassonomia, per le categorie e per i tag la vista **Termini** mostra i termini in una tabella: nome, slug, genitore, descrizione e campi si cambiano nella cella, **+ Aggiungi** crea un termine (anche dentro un altro), **Elimina** lo toglie dopo una conferma. Le modifiche vanno subito sul sito.

### La tabella dei contenuti

Per ogni tipo (e per articoli e pagine) la sezione ha due viste: **Definizione** e **Contenuti**. La vista **Contenuti** è una tabella con ricerca, filtro per **Stato** e 20 righe per pagina. Titolo e campi semplici si modificano direttamente nella cella; immagini, contenuti collegati e righe si modificano nell'editor di WordPress con **Modifica ↗**. **+ Aggiungi (bozza)** crea un nuovo contenuto in bozza.

> Le modifiche ai contenuti vanno subito sul sito, come in WordPress: i contenuti non hanno versioni Histeon. Le hanno le pagine e i modelli che li mostrano.

### Campi di ACF, Secure Custom Fields, JetEngine e Meta Box

Se usi già uno di questi plugin, i suoi gruppi di campi compaiono in **Campi di altri plugin**. Per ogni campo vedi se le pagine lo possono leggere e un interruttore per renderlo leggibile (vale anche per l'intero gruppo). Un campo di un altro plugin può contenere dati interni, per questo è leggibile solo se un amministratore lo permette o se il plugin lo pubblica già nell'API REST. I campi password non sono mai leggibili.

Anche i campi delle **pagine di opzioni** di Secure Custom Fields o ACF (orari, contatti, testi del piè di pagina) compaiono lì, con la loro pagina. Resi leggibili, si usano in qualunque pagina come `{site.options.dati_sito.orari}`: il nome della pagina di opzioni, con `_` al posto dei trattini, poi quello del campo. Si leggono soltanto: si modificano dalla pagina di opzioni in WordPress.

Si leggono con `this.fields.nome` oppure, per leggere solo da un plugin, con `this.acf.nome`, `this.jetengine.nome`, `this.metabox.nome`. Histeon non esegue la formattazione del plugin: il testo ricco perde il markup e le date diventano `AAAA-MM-GG`.

## Filtri dei loop

I **Filtri dei loop** fanno scegliere ai visitatori cosa vedere in un elenco: ricerca, categorie, valore di un campo, ordine e pagine. Funzionano anche senza JavaScript e, se c'è, aggiornano i risultati senza ricaricare la pagina.

1. Apri la sezione **Filtri dei loop** e crea un gruppo con un nome, per esempio `eventi`.
2. Scegli i contenuti (**Contenuti di un tipo** oppure una tua query), i risultati **Per pagina** e il **Parametro della pagina**.
3. Aggiungi i filtri: **Ricerca**, **Tassonomia** (con **più scelte** e la combinazione **almeno una** o **tutte**), **Campo**, **Ordinamento**. Ogni filtro ha un'**Etichetta** e un **Parametro**, il nome che compare nell'indirizzo.
4. **Prova come un visitatore**, poi **Salva i filtri**.
5. **Inserisci nella pagina** scrive il markup completo: il form, i risultati con il loro numero, «Azzera i filtri» e le pagine. Da lì si modifica come ogni altro elemento.

Nelle espressioni i filtri si leggono come `facets.eventi`: `{#loop facets.eventi.items as item}` per i risultati, `{facets.eventi.total}` per il conteggio, `facets.eventi.pageLinks` per le pagine. Ogni opzione ha il suo conteggio, che tiene conto degli altri filtri già scelti.

> Un campo si può filtrare solo se è leggibile dalle pagine e ha un valore semplice. Sul sito statico i filtri restano dinamici: vedi il capitolo sul sito statico.

## E-commerce: WooCommerce e FluentCart

Con WooCommerce o FluentCart attivi, un prodotto si legge con `this.product.*` nel modello del prodotto e con `item.product.*` in un loop di prodotti (tipo `product` per WooCommerce, `fluent-products` per FluentCart).

| Chiave | Cosa contiene |
|---|---|
| `priceText` | Il prezzo formattato dal negozio (in FluentCart anche un intervallo «10 € – 25 €») |
| `price`, `regularPrice`, `salePrice`, `onSale` | Prezzi come numeri, e se è in saldo |
| `inStock`, `stockStatus` | Disponibilità |
| `sku`, `type`, `shortDescription` | Codice, tipo, riassunto senza markup |
| `addToCartUrl`, `addToCartText` | Link e testo del pulsante «aggiungi al carrello» |
| `gallery`, `categories` | Immagini e categorie del prodotto |
| `rating`, `reviewCount` | Valutazione e recensioni (solo WooCommerce) |

FluentCart aggiunge `minPrice`, `maxPrice`, `variations` (le varianti attive), `subscription`, `fulfillment`, `brands`, `buyNowUrl`, `cartUrl` e `checkoutUrl`.

> Histeon legge solo il catalogo: mai ordini, clienti o carrelli. I prodotti nascosti non compaiono. Carrello e cassa restano pagine dinamiche del negozio.
