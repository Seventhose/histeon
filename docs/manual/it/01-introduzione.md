# Introduzione

Benvenuto in Histeon. Questo capitolo spiega che cos'è, a chi serve, che cosa lo rende diverso dagli altri builder e di che cosa ha bisogno per funzionare.

## Cos'è Histeon

Histeon è un builder visuale per WordPress pensato per chi ama il codice pulito. Costruisci le pagine guardandole crescere in una tela, ma sotto c'è sempre HTML vero, con i tag e le classi che hai scelto tu, e CSS scritto per selettore, annidato come in SCSS.

Il nome viene dal greco *histós* (ἱστός): il telaio verticale dell'antica Grecia, e anche la tela che vi si tesse. Una tela, appunto: il *web*. Nel logo, due montanti e una trama corallo formano una H.

Histeon salva le pagine nel formato standard di WordPress, quello dei blocchi di Gutenberg. Gutenberg è il formato in cui i contenuti vengono conservati, non l'interfaccia con cui lavori: per questo revisioni, esportazione, API REST e plugin SEO continuano a funzionare come sempre.

Con Histeon puoi:

- scrivere e modificare la struttura nei pannelli HTML, CSS e JavaScript, oppure con il mouse nella tela e nella Struttura;
- creare **componenti** riutilizzabili, con proprietà e slot;
- mostrare contenuti dinamici con loop, condizioni e query visuali;
- lavorare in **sviluppo** e portare online le modifiche con una **release**, che si può ripristinare;
- scegliere il framework CSS, a partire da **Histeon CSS**, il framework libero incluso.

## Per chi è

Histeon è pensato per chi costruisce siti per mestiere:

- **designer e sviluppatori front-end**, che vogliono controllo completo su markup e CSS senza rinunciare a un'interfaccia visuale;
- **agenzie**, che hanno bisogno di componenti riutilizzabili, versioni, anteprime da mandare al cliente e pubblicazioni controllate;
- **redattori e clienti**, che dopo la consegna modificano i testi direttamente nell'editor a blocchi di WordPress, con la pagina che appare come sul sito.

Non serve saper programmare in PHP. Serve invece conoscere un po' di HTML e CSS: Histeon ti aiuta a scriverli, non li nasconde.

## Cosa lo distingue

### Codice pulito

Quello che scrivi è quello che arriva nella pagina: niente contenitori aggiunti di nascosto, niente classi generate automaticamente. Gli stili sono legati al selettore (una classe o un id) e una pagina riceve **solo il CSS che usa davvero**, sia per i tuoi stili sia per quelli del framework. Il risultato sono pagine leggere e un codice che chiunque può leggere.

### Versioni e release

Ogni salvataggio nel builder va in **sviluppo**: il sito pubblico non cambia. Quando sei pronto, **Pubblica…** porta online le modifiche scelte come una release con numero di versione e note. Prima puoi guardare le differenze, fare un confronto visivo tra la pagina online e quella in sviluppo e mandare al cliente un link d'anteprima firmato, che scade da solo. Se qualcosa va storto, ripristini la release precedente.

### Sicurezza

- Ogni contenuto viene filtrato con i permessi di chi lo salva, come in WordPress: chi non può scrivere HTML senza filtri non può inserire script.
- Un contenuto non fidato non diventa fidato solo perché un amministratore lo salva o lo pubblica.
- Il JavaScript si scrive e si pubblica solo con il permesso di WordPress per l'HTML senza filtri.
- La tela del builder non esegue gli script della pagina.
- Gli aggiornamenti sono firmati e verificati prima dell'installazione.

### Open source, senza licenze

Histeon è software libero, con licenza GPL-3.0-or-later. Non ci sono chiavi di licenza da inserire, versioni «pro» da sbloccare, telemetria o servizi esterni obbligatori. Gli aggiornamenti arrivano dalle release pubbliche su GitHub: il sito chiede qual è l'ultima release e ne scarica pacchetto e firma, senza mandare contenuti, utenti o impostazioni.

## La famiglia Histeon

Histeon è un solo plugin, con alcune parti che puoi accendere e spegnere (i **moduli**). Alcune esistono anche come plugin separati.

| Nome | Che cosa fa | Come si usa |
|---|---|---|
| **Histeon** | Il builder: tela, struttura, pannelli, componenti, versioni e release | Plugin principale |
| **Histeon CSS** | Il framework CSS e design system libero: colori, scale fluide di testo e spazi, classi di utilità, ricette, pannello in popup | Modulo incluso e acceso; esiste anche come plugin autonomo per i siti senza il builder |
| **Lingua Bridge** | Il multilingua: traduzioni come pagine WordPress normali, memoria di traduzione, aggiornamento delle frasi cambiate | Modulo incluso, spento finché non lo accendi; esiste anche come plugin separato |
| **Static Bridge** | La copia statica del sito: esportazione, pubblicazione su Cloudflare, form e lingue | Modulo incluso, spento finché non lo accendi; richiede PHP 8.4 |
| **Fondamenta** | Il tema a blocchi di base, senza preset né controlli visivi di Gutenberg, con template costruiti con Histeon; prende il posto di XB Blank | Tema da installare a parte (facoltativo) |

> Un modulo attivo come plugin separato non viene caricato due volte: Histeon lo riconosce e lascia lavorare il plugin. Spegnere un modulo non cancella mai i suoi dati.

## Requisiti

| Che cosa | Requisito |
|---|---|
| WordPress | 6.9 o successivo |
| PHP | 8.3 o successivo |
| Static Bridge (modulo) | PHP 8.4 o successivo |
| Histeon CSS come plugin autonomo | WordPress 6.6 e PHP 8.3 o successivi |
| Database | MySQL o MariaDB, come WordPress |
| Tema | Qualsiasi; per i template del sito serve un tema a blocchi, come Fondamenta |

Histeon funziona anche con WordPress su **SQLite**, attraverso il plugin ufficiale *SQLite Database Integration*: è comodo per lavorare in locale con un database in un solo file, o per un sito pubblicato come copia statica. WordPress supporta ufficialmente solo MySQL e MariaDB, quindi per un sito dinamico in produzione resta la scelta consigliata.

All'attivazione, gli **amministratori** ricevono i permessi per costruire con Histeon e per pubblicare le release.

## Come leggere questo manuale

Il manuale segue il percorso di chi costruisce un sito. Se è la prima volta, leggi in ordine i primi capitoli:

1. [Installazione](#it-installazione): i pacchetti, la verifica delle firme, il primo avvio e i moduli.
2. [Il builder](#it-il-builder): com'è fatta l'interfaccia, la tela, l'anteprima, il salvataggio e la pubblicazione.
3. [Gli elementi](#it-elementi): inserire, spostare, scrivere i testi, il pannello HTML e le proprietà.
4. [Gli stili](#it-stili): classi, selettori, Mini GUI, media query e il CSS che arriva nelle pagine.
5. [Histeon CSS](#it-histos-css): il framework e il design system.
6. [I componenti](#it-componenti): crearli, dar loro props e slot, usarli e pubblicarli.

Gli altri capitoli si leggono quando servono: contenuti e dati, template, versioni e release, sito statico, multilingua, importazione da Etch, permessi e sicurezza. L'indice a sinistra li elenca tutti, e la ricerca in alto trova una parola in tutto il manuale.

Alcune convenzioni:

- Le etichette dell'interfaccia sono scritte in **grassetto**, esattamente come le vedi a schermo: **Pubblica…**, **Salva**, **Stili e selettori**.
- I percorsi nel menu di WordPress usano la freccia: **Histeon → Impostazioni**.
- I tasti si scrivono così: [[Ctrl+S]]. Su Mac, al posto di Ctrl usa ⌘.
- Il codice da scrivere è in carattere a larghezza fissa: `class="card"`.

> I riquadri come questo contengono consigli e avvertenze: vale la pena leggerli.
