# SEO e prestazioni

Un sito bello deve anche farsi trovare e caricarsi in fretta. Histeon lavora con i plugin SEO che già conosci e manda a chi visita solo quello che la pagina usa davvero: CSS, script, immagini della misura giusta.

## SEO con il tuo plugin preferito

Histeon non sostituisce il plugin SEO: titoli, descrizioni, dati per i social e sitemap restano di **Yoast SEO**, **Rank Math**, **Slim SEO** o **The SEO Framework**. Histeon fa in modo che questi plugin vedano le pagine come le vede un visitatore.

### Perché serve

Histeon salva i testi in modo strutturato, dentro i blocchi. Un plugin SEO che legge il contenuto "grezzo" di una pagina Histeon troverebbe zero parole, nessun titolo e nessuna immagine. Per questo Histeon gli passa la pagina **già disegnata**, con testi, titoli e immagini veri.

### Cosa ottieni

| Dove | Cosa succede |
|---|---|
| **Analisi di Yoast SEO e Rank Math** nell'editor a blocchi | Il plugin analizza il testo vero della pagina, con titoli e immagini, e si aggiorna poco dopo ogni modifica |
| **Sitemap di Yoast SEO e Rank Math** | Le immagini delle pagine Histeon, anche quelle che arrivano dai contenuti, entrano nella sitemap |
| **Slim SEO** | Legge le pagine Histeon per descrizione e titolo, e ne aggiunge le immagini alla sitemap |
| **The SEO Framework** | Usa il testo della pagina per le descrizioni generate e le sue immagini per i social, dopo l'immagine in evidenza |
| **Riassunto automatico di WordPress** | Se una pagina non ha un riassunto scritto, prende le prime parole della pagina. Lo usano le variabili come `%%excerpt%%` di Yoast, `%excerpt%` di Rank Math e i feed |

> Ciò che scrivi a mano vince sempre: un riassunto o una meta descrizione scritti dall'autore hanno la precedenza sul testo preso dalla pagina.

### Cosa vedono i plugin SEO

La pagina che Histeon passa ai plugin SEO è quella **online**, disegnata come la vede un **visitatore anonimo**:

- le modifiche in sviluppo non entrano in descrizioni e sitemap finché non le pubblichi;
- niente di personale (il nome dell'utente collegato, contenuti riservati) può finire in una descrizione pubblica;
- le pagine protette da password non producono nulla.

Il risultato viene tenuto in memoria e rifatto quando cambia la pagina, quando pubblichi una release o quando aggiorni Histeon.

> Nell'editor a blocchi, invece, l'analisi usa la pagina con i permessi di chi la sta modificando: vedi subito l'effetto di quello che scrivi.

### I controlli SEO del builder

La sezione **Controlli** del builder segnala anche i problemi SEO più comuni: una pagina senza titolo H1, o con due H1. Componenti e parti di template non hanno bisogno di un H1. Vedi il capitolo sulle versioni e le release.

> I link delle anteprime di sviluppo chiedono esplicitamente ai motori di ricerca di non essere indicizzati.

## CSS su richiesta

Molti siti mandano a ogni pagina centinaia di KB di CSS, quasi tutti inutili per quella pagina. Histeon legge l'HTML finale di ogni pagina e include solo le regole delle classi e degli id che ci sono davvero.

- **Gli stili di Histeon** arrivano in pagina solo se un elemento li usa.
- **Il framework CSS** (Histeon CSS, Core Framework, Automatic.css): Histeon può fare lo stesso anche con le regole del framework. Lo scegli in **Histeon → Impostazioni**, sezione **Caricamento del CSS**:
  - **Framework su richiesta** (consigliato): ogni pagina riceve solo le regole del framework che usa. Variabili e regole generali restano sempre.
  - **Framework completo**: il foglio intero su ogni pagina, come lo carica il framework.
- Nel dubbio una regola viene tenuta: un byte in più è meglio di una pagina rotta.

Il CSS arriva dentro la pagina stessa, senza file separati da scaricare. Con fogli globali grandi conviene **Fogli globali in un file in cache**, nella stessa sezione: quei fogli arrivano in un file che il browser riusa da una pagina all'altra.

### Classi aggiunte dagli script

Histeon vede solo le classi presenti nell'HTML della pagina. Se uno script aggiunge una classe dopo il caricamento (un menu che si apre, una finestra che compare, risultati caricati dopo), le sue regole potrebbero mancare.

In questo caso scrivi quelle classi in **Classi e id sempre inclusi** (**Histeon → Impostazioni**, sezione **Caricamento del CSS**), separate da spazi. Un `*` finale vale come prefisso: `is-*` include tutte le classi che cominciano con `is-`.

> Se è attivo anche il plugin di un framework diverso da quello scelto, quel plugin può caricare il proprio foglio su ogni pagina, fuori dal caricamento su richiesta di Histeon: le impostazioni te lo segnalano.

## Script solo dove servono

Histeon non carica script "per sicurezza" su tutte le pagine:

- lo script delle **interazioni** (schede, menu mobile, comparsa allo scorrimento) pesa pochi KB e arriva solo nelle pagine che ne usano una;
- lo script dei **filtri dei loop** arriva solo nelle pagine che usano i filtri;
- il **JavaScript della pagina** e dei componenti arriva solo dove serve, una volta sola, dopo il contenuto.

Senza JavaScript le pagine restano usabili: le interazioni si vedono aperte e i filtri funzionano ricaricando la pagina.

## Immagini che caricano bene

Le immagini sono quasi sempre la parte più pesante di una pagina. Histeon aiuta in tre punti.

- **Al caricamento.** Il gestore dei media riduce e converte le immagini in AVIF o WebP nel browser, prima dell'invio (vedi il capitolo su media e font).
- **Nella pagina.** Un'immagine scelta dalla libreria riceve `srcset`, `sizes`, `width` e `height`. Un'immagine che arriva dai contenuti (`<img src={item.image.url}>`) riceve gli stessi attributi al momento della visita, senza toccare quelli che hai scritto tu.
- **Nessun salto.** `width` e `height` fanno riservare al browser lo spazio dell'immagine prima che arrivi: la pagina non si sposta mentre carica.

Se un'immagine ha `loading="lazy"`, Histeon aggiunge `auto` a `sizes`, così il browser sceglie il file in base allo spazio reale dell'immagine. `loading`, `decoding` e `fetchpriority` li gestisce WordPress.

I **Controlli** del builder segnalano le immagini senza larghezza e altezza e quelle molto più grandi di come appaiono.

## Font senza richieste esterne

I **Font locali** stanno nel tuo sito: nessuna richiesta a Google o ad altri server quando qualcuno visita le pagine. È più veloce (nessuna connessione in più) e più rispettoso della privacy. Il testo si vede subito con un carattere di sistema e passa al font scelto appena è arrivato. Vedi il capitolo su media e font.

## Riepilogo per una pagina veloce

1. Lascia attivo **Framework su richiesta**.
2. Carica le immagini con la compressione **Web** o **Leggera** e inseriscile dal gestore dei media.
3. Usa i font locali invece di quelli remoti.
4. Preferisci le interazioni di Histeon a librerie esterne pesanti.
5. Prima di pubblicare, guarda la sezione **Controlli** e risolvi gli errori.
6. Se il sito lo permette, servilo come sito statico (vedi il capitolo sul sito statico).
