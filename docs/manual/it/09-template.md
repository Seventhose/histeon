# Template

Con un tema a blocchi, WordPress costruisce ogni pagina del sito partendo da un **template**: uno per gli articoli, uno per le pagine, uno per gli archivi, uno per la pagina 404 e così via. Le **parti di template** sono i pezzi condivisi tra più template, come l'intestazione e il piè di pagina. In Histeon template e parti si modificano nel builder esattamente come una pagina.

## Cosa serve

- **Un tema a blocchi.** Histeon lavora sui template del tema attivo; con un tema classico la voce **Template** non compare.
- **I permessi giusti.** Servono l'accesso al builder e il permesso di modificare l'aspetto del sito (di solito gli amministratori).

> Il tema consigliato è **Fondamenta**, distribuito con Histeon (vedi sotto). Va bene anche un altro tema a blocchi: i suoi template si aprono nel builder e i blocchi di WordPress che contengono restano dove sono.

## Dove si trovano

Ci sono tre strade per aprire un template.

| Dove | Come |
|---|---|
| **Histeon → Template** in wp-admin | L'elenco dei template e delle parti del tema attivo, divisi per gruppo |
| Sezione **Template** del builder | Lo stesso elenco dentro il builder, con filtri per gruppo e la ricerca **Cerca un template…** |
| Barra di amministrazione sul sito | Mentre guardi una pagina, **Template con Histeon: …** apre il template che quella pagina usa (se è già stato personalizzato) |

I gruppi seguono la logica di WordPress: **Generali** (indice, home, pagina iniziale, 404, ricerca), **Pagine**, **Articoli**, **Archivi**, un gruppo per ogni tipo di contenuto personalizzato (per esempio "Eventi", con il singolo e l'archivio), **Categorie, tag e tassonomie**, i modelli personalizzati e, per le parti, **Intestazioni**, **Piè di pagina** e le altre parti. Ogni voce ha una riga che spiega dove vale.

### Template del tema e template personalizzati

Ogni voce porta un'etichetta:

- **File del tema**: il template esiste solo come file del tema. In wp-admin il pulsante è **Modifica con Histeon**.
- **Personalizzato**: il template è già stato copiato nel database. Il pulsante è **Apri in Histeon**.

La prima volta che apri un template "file del tema", Histeon ne fa una copia nel database e apre quella, come fa l'Editor del sito di WordPress quando personalizzi un template. Succede una volta sola; il file del tema non viene toccato.

## Creare un template

In **Histeon → Template** apri **Nuovo template**. Dalla sezione Template del builder lo stesso pannello si apre con **+ Nuovo template**, in una nuova scheda.

1. Scegli il **tipo**: **Template completo** oppure **Parte di template**. Per una parte scegli anche l'**area**: generale, intestazione o piè di pagina.
2. Scrivi il **nome tecnico**. Il campo suggerisce i nomi della gerarchia di WordPress che il tema non ha ancora: per esempio `single-evento` per ogni evento singolo, `archive-evento` per l'elenco degli eventi, `category`, `search`, `404`.
3. Scrivi un **titolo**, se vuoi, e scegli da dove partire in **Parti da**: vuoto, oppure la copia di un template esistente.
4. Premi **Crea e apri in Histeon**: il template nuovo si apre nel builder.

> Un nome della gerarchia vale da solo dove previsto. Qualsiasi altro nome crea un **modello personalizzato**: lo assegni a pagine e articoli dal loro editor, nel pannello del template.

## Modificare un template

Un template si costruisce come una pagina: elementi, classi, componenti, loop e condizioni (vedi il capitolo sui contenuti e i dati). Due differenze contano.

**I dati vengono dalla pagina visitata.** In un template `this` è il contenuto che il visitatore sta guardando: `{this.title}` nel template degli articoli diventa il titolo di ciascun articolo. In un archivio si usa la query della pagina:

```html
{#loop main() as post}
  <article>
    <h2><a href={post.url}>{post.title}</a></h2>
    {#if post.excerpt}<p>{post.excerpt}</p>{/if}
  </article>
{/loop}
```

**Le pagine di un archivio.** WordPress divide un archivio in pagine (quante voci per pagina lo decidi in Impostazioni → Lettura). In un template d'archivio `props.page` dice dove sei e dà già gli indirizzi giusti: `props.page.page` è la pagina mostrata, `props.page.totalPages` quante sono, `props.page.totalRecords` quanti contenuti ha l'archivio, `props.page.prevUrl` e `props.page.nextUrl` gli indirizzi della pagina precedente e seguente (vuoti quando non c'è), `props.page.pageLinks` una voce per pagina con `number`, `url` e `current`:

```html
<nav aria-label="Paginazione">
  {#if props.page.prevUrl}<a href={props.page.prevUrl}>Precedente</a>{/if}
  {#loop props.page.pageLinks as l}
    {#if l.current}<span aria-current="page">{l.number}</span>{:else}<a href={l.url}>{l.number}</a>{/if}
  {/loop}
  {#if props.page.nextUrl}<a href={props.page.nextUrl}>Seguente</a>{/if}
</nav>
```

Gli indirizzi seguono i permalink del sito (`/libri/page/2/`, oppure `?paged=2` con i permalink semplici) e valgono per gli archivi dei tipi e dei termini, l'elenco degli articoli e gli archivi di autore e di data.

**Il contenuto scritto nell'editor ha il suo posto.** Nei template di articoli e pagine, l'elemento **Contenuto del post** (menu **Aggiungi**, oppure `{@content}` nel pannello HTML) indica dove va il testo che i redattori scrivono nell'editor a blocchi.

**Ogni template ha il suo Contenuto del post, anche quelli di elenchi.** Se un template non ce l'ha, il builder lo dice in una striscia sopra la tela, mentre lo modifichi: con Histeon Publish un template senza questo blocco non viaggia, e la rilettura e l'invio del sito si fermano su di lui. Il pulsante **Aggiungi il contenuto del post** lo aggiunge (dove hai selezionato, oppure in fondo): spostalo dove va il testo e salva. In un template di elenchi (archivi, ricerca, 404) resta vuoto e non si vede, sul sito come in Histeon Publish. L'avviso non blocca nulla: puoi salvare e pubblicare lo stesso. Le parti di template non ne hanno bisogno.

Nella barra in alto del builder, accanto al nome, compare **Template** o **Parte di template**, così sai sempre cosa stai modificando.

### Le parti di template

Intestazione e piè di pagina sono documenti a sé: si aprono dalla stessa lista e si modificano una volta per tutte le pagine che li usano. Nei template di Fondamenta sono richiamati con il blocco "parte di template" di WordPress, che il builder conserva così com'è.

## Anteprima con contenuti di esempio

Un template non ha una pagina sua: per mostrarlo, la tela e l'anteprima usano un contenuto di esempio.

| Template | Contenuto di esempio |
|---|---|
| Articolo singolo, singolo di un tipo (`single`, `single-evento`…) | Un contenuto pubblicato di quel tipo |
| Pagina, pagina iniziale, parti di template | Una pagina |
| Archivi, indice, home, ricerca, 404 | Un elenco: gli ultimi articoli |

Sopra la tela, il menu **Anteprima con** sceglie il contenuto di esempio tra gli ultimi 20 pubblicati che puoi leggere. Per gli elenchi compare invece la nota **elenco degli ultimi articoli**. Cambiare esempio è utile per vedere come si comporta il template con un titolo lungo, senza immagine o con molte categorie.

Anche il pulsante **Anteprima** della barra in alto apre la pagina vera sul contenuto di esempio scelto.

> Il link di anteprima di un template apre qualunque pagina del sito con le modifiche in sviluppo, perché un template vale per molti indirizzi. Condividilo solo con chi deve vedere la versione non ancora pubblicata.

## Salvare e pubblicare

Template e parti funzionano come ogni documento di Histeon:

- **Salva** ([[Ctrl+S]]) li salva in sviluppo: il sito pubblico non cambia.
- **Pubblica…** li porta online con una release, insieme alle altre modifiche.
- Dalla storia del documento si ripristina una versione precedente.

Se qualcuno modifica un template fuori da Histeon, per esempio nell'Editor del sito di WordPress, la modifica entra comunque nella storia del documento, come per le pagine. Per release, ripristini e storia vedi il capitolo sulle versioni e le release.

## Il tema Fondamenta

**Fondamenta** è il tema a blocchi di base pensato per Histeon. Con Histeon attivo non aggiunge niente di suo: tutto quello che si vede lo disegna Histeon.

- **Nessun preset**: niente palette, gradienti, dimensioni dei caratteri o pattern di WordPress che si mettano in mezzo al tuo framework CSS.
- **Nessun pattern remoto** scaricato dalla libreria di WordPress.
- **Quattro template** già scritti con gli elementi di Histeon: **index** (l'elenco degli articoli), **singular** (articoli e contenuti singoli), **page** e **404**. Tutti hanno il blocco «Contenuto del post», anche index e 404, dove resta vuoto: così ogni template viaggia con Histeon Publish.
- **Due parti**: **header**, con il nome del sito e il menu di navigazione, e **footer**.

È il punto di partenza consigliato: apri la sezione **Template**, personalizza intestazione e piè di pagina, poi il template dei contenuti singoli e quello degli elenchi.

> Ti serve un template in più, per esempio quello di un tuo tipo di contenuto? Crealo da **Nuovo template** (vedi sopra).

Il nome non contiene «Histeon» perché il tema resta anche dopo: è la base su cui un sito potrà un giorno lasciare Histeon e passare a blocchi che funzionano da soli. **Senza Histeon** Fondamenta è «ferma»: le pagine non mostrano nulla, e in wp-admin un avviso dice quale plugin manca, quanti contenuti restano senza pagina e quali tipi di contenuto non sono registrati. Per ora la soluzione è riattivare Histeon.

### Da XB Blank a Fondamenta

XB Blank resta come tema di compatibilità, con gli stessi template di Fondamenta: un sito che lo usa continua a vedersi come prima. Nelle schermate **Temi** e **Bacheca** un avviso propone il passaggio.

1. Fai una copia di sicurezza del sito.
2. Carica `fondamenta-<versione>-<commit>.zip` della stessa release di Histeon da **Aspetto → Temi → Aggiungi nuovo tema → Carica tema**.
3. Attiva Fondamenta (dall'avviso o dalla schermata Temi).

Al cambio di tema Histeon porta a Fondamenta i template, le parti, gli stili globali e il CSS aggiuntivo che hai personalizzato con XB Blank: restano gli stessi documenti, con la loro storia. Se Fondamenta ha già un template con lo stesso nome, resta quello di Fondamenta e il **Registro** lo dice. Riattivare XB Blank riporta tutto indietro.

### Il rapporto di uscita

Se un giorno un sito dovrà lasciare Histeon, le sue pagine diventeranno blocchi di Fondamenta per la struttura e blocchi di WordPress per i testi. Per sapere prima quanto ne verrebbe fuori, un amministratore può leggere un rapporto da WP-CLI:

```
wp xb export-core --user=<amministratore>
```

Non cambia nulla. Per ogni pagina, template e componente dice cosa diventerebbe un blocco modificabile, cosa resterebbe com'è oggi in un blocco HTML (per esempio un SVG o un elenco di categorie), cosa si perderebbe (per esempio un form di Histeon o una condizione su chi visita) e perché. In fondo, la quota di elementi che diventano blocchi veri. Con `--file=rapporto.json` salva tutto in un file, con `--doc=<id>` legge un documento solo.

### I blocchi di Fondamenta

Da Fondamenta 0.2 il tema ha i blocchi in cui le pagine si convertiranno. Li trovi anche nell'inseritore dell'editor a blocchi:

- **Elemento** (con le varianti Sezione, Contenitore, Immagine, Collegamento, Pulsante): un elemento HTML con il suo tag e le sue classi, che contiene altri blocchi, un testo o niente. Il markup resta quello che hai costruito, quindi il tuo CSS continua a valere. Un elemento può mostrare valori del contenuto: il titolo, la data, un campo, il link di un articolo;
- **Loop**: ripete i suoi blocchi per ogni articolo di una query, per ogni termine, per ogni valore di un campo o per ogni pagina di un archivio; **Se il loop è vuoto** dice cosa mostrare quando non ci sono voci;
- **Se c'è**: mostra i suoi blocchi solo se un valore c'è, o se un confronto è vero (per esempio la voce del menu della sezione in cui si è);
- **Contenuto del post**.

Per vedere come verrebbe una pagina convertita, senza toccarla:

```
wp xb export-core --user=<amministratore> --compare
```

Per ogni pagina, template e parte disegna la pagina due volte, con Histeon e con i blocchi convertiti, e dice se l'HTML è lo stesso. I componenti diventano pattern sincronizzati di WordPress quando possono: la definizione resta una sola, e ogni uso tiene i suoi testi e i suoi link come valori propri. Un componente con slot, o con props in una classe, in una condizione o in un loop, si copia invece in ogni pagina che lo usa. Anche i filtri dei loop (ricerca, temi, ordine, pagine) passano a Fondamenta: le loro definizioni vanno nel tema del sito e le pagine filtrano come prima, anche a Histeon spento. I campi dei contenuti, ripetitori compresi, passano al plugin di campi che scegli (Secure Custom Fields, ACF, Meta Box, Pods, JetEngine o Open Fields): `--theme-zip=tema.zip --campi=scf` mette i gruppi di campi nel tema del sito, e le pagine li mostrano come prima. Anche i form del sito statico (quelli che inviano a `/xb-form`) diventano form del plugin che scegli (Fluent Forms, Contact Form 7, Forminator, JetFormBuilder, Ninja Forms o SureForms), con i messaggi già ricevuti: `--forms --form=fluentforms` mostra cosa diventerebbero.

Quando il sito è pronto, l'uscita si fa da **Histeon → Impostazioni → Uscita verso Gutenberg**: scegli il plugin dei campi e quello dei form, guarda l'anteprima, scarica e installa il tema del sito, poi «Scrivi l'uscita» (con una copia completa del sito fatta prima). Le pagine vengono convertite al loro posto, con una copia di sicurezza: «Ripristina» le rimette com'erano. Dopo aver controllato le pagine, «Concludi l'uscita» attiva il tema del sito e spegne Histeon, senza cancellarlo. Da qui in poi non pubblicare più release di Histeon. Con `--convert --doc=<id>` il comando mostra i blocchi convertiti di un documento, senza scrivere nei contenuti: a scriverli è «Scrivi l'uscita».

### Il tema del sito

Il giorno dell'uscita, sopra Fondamenta va un tema fatto apposta per il tuo sito: il suo foglio di stile, i suoi template e le sue parti già convertiti, gli script delle sue pagine. Lo genera:

```
wp xb export-core --user=<amministratore> --theme-zip=tema.zip
```

Non cambia nulla nel sito: scrive solo il file ZIP, da caricare a mano in **Aspetto → Temi → Aggiungi nuovo tema → Carica tema** quando sarà il momento. Il tema si chiama come il tuo sito e, una volta installato, è tuo: lo modifichi nell'Editor del sito o nei suoi file. Se Histeon un giorno è spento, Fondamenta registra comunque i tuoi tipi di contenuto con gli stessi indirizzi e fa funzionare le interazioni (schede, menu, comparsa).
