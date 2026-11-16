# Media e font

Immagini e caratteri pesano più di tutto il resto di una pagina. Histeon lavora sulla libreria media di WordPress, aggiunge le raccolte per tenerla in ordine, comprime le immagini prima del caricamento e scrive da solo gli attributi che fanno caricare l'immagine giusta per ogni schermo. I font, invece, li serve direttamente dal tuo sito.

## Il gestore dei media

Il **Gestore dei media** è la finestra in cui scegli e carichi le immagini senza uscire dal builder. Si apre in tre modi:

- dal menu **Aggiungi** ([[Ctrl+I]]) → **Immagine dalla libreria…**, per inserire una nuova immagine;
- selezionando un'immagine nella pagina e premendo **Scegli immagine…** nel pannello **Proprietà**, per sostituirla;
- dal pulsante **Libreria dei media** nella barra a sinistra (o dalla palette dei comandi), per sfogliare tutta la libreria: immagini, video, audio e PDF.

Aperta dalla barra, la libreria non inserisce nulla al primo clic: un clic su un file ne mostra i dettagli a destra. Tipo, dimensioni, peso e data di caricamento; l'indirizzo, con **Copia**; il testo alternativo e la didascalia, con **Salva i dettagli**; le raccolte; e **Dove è usato**, cioè i contenuti che lo nominano (a qualsiasi dimensione) o che lo hanno come immagine in evidenza. I contenuti che non puoi modificare sono solo contati. **Inserisci nella pagina** lo mette dopo l'elemento selezionato; **Apri in WordPress** apre la sua scheda in wp-admin.

Le immagini sono quelle della libreria media di WordPress: quello che carichi qui lo ritrovi in **Media** in wp-admin, e viceversa. Per cercare usa il campo **Cerca nella libreria…**.

### Raccolte

Le **Raccolte** sono cartelle per le immagini, anche annidate: "Eventi", "Eventi → Concerti", "Icone"…

- La colonna a sinistra mostra **Tutte le immagini**, **Senza raccolta** e l'albero delle raccolte, ognuna con il numero di immagini.
- Per creare una raccolta scrivi il nome nel campo **Nuova raccolta…**. Se ne hai una aperta, il campo diventa **Sottoraccolta…** e la nuova raccolta nasce dentro quella.
- Una raccolta non ha pagine pubbliche: serve solo a chi costruisce il sito.

Per creare raccolte e assegnarle basta poter caricare file in WordPress.

### Caricare immagini

1. Apri la raccolta in cui vuoi mettere le immagini.
2. Scegli la **Compressione prima del caricamento** (vedi la tabella).
3. Premi **Carica** e scegli fino a 20 file: ognuno ha la sua barra di avanzamento nella lista **Caricamenti**.

| Compressione | Cosa fa |
|---|---|
| **Web (max 2560 px, AVIF o WebP)** | Riduce il lato più lungo a 2560 px e converte in AVIF o WebP: tiene il più leggero. Va bene per quasi tutto. |
| **Leggera (max 1600 px, AVIF o WebP)** | Come Web, ma con il lato più lungo a 1600 px: per immagini piccole nella pagina o siti molto leggeri. |
| **Originale** | Il file parte così com'è. |

La compressione avviene nel browser, prima dell'invio: il server riceve già il file leggero. L'orientamento delle foto scattate col telefono viene rispettato. JPEG, PNG e WebP vengono compressi. GIF, PNG e WebP animati e SVG partono come sono, e così i file oltre 32 MB o 40 megapixel. Un PNG non diventa mai AVIF: resta senza perdita, oppure diventa un WebP con perdita se pesa meno. Il builder tiene il formato più leggero, e solo se pesa meno dell'originale; altrimenti viene caricato l'originale. Una foto richiede qualche secondo. I codificatori sono quelli di Histeon Publish e li serve il tuo sito: nessun file va a servizi esterni.

> Valgono sempre i limiti di WordPress: tipi di file ammessi, dimensione massima del caricamento e permesso di caricare file.

### Preset propri e immagini già caricate

**Preset…**, accanto al menu della compressione, aggiunge i preset del sito: un nome, il lato più lungo, i formati e la qualità. L'ultimo preset usato resta scelto.

Un'immagine già nella libreria si alleggerisce dai suoi dettagli: **Comprimi con «preset»** la riduce e la ricomprime nello stesso formato, al suo posto. L'indirizzo non cambia, le misure che WordPress aveva fatto restano con il loro nome, e l'originale resta da parte: **Ripristina l'originale** lo rimette. Se non c'è niente da guadagnare, l'immagine resta com'era.

### Testo alternativo e dettagli

Il pulsante **⋯** su un'immagine apre i **Dettagli**:

- **Testo alternativo**: la descrizione per chi usa un lettore di schermo e per i motori di ricerca. **Salva testo alternativo** lo salva sull'immagine nella libreria, così vale ovunque la userai dopo.
- Le **Raccolte** a cui appartiene l'immagine.
- **Usa questa immagine** per inserirla nella pagina.

### Video, audio e PDF

Dal menu **Aggiungi** → **Video, audio o PDF dalla libreria…** si apre lo stesso gestore con il filtro **Tipo di file**. Un video o un audio entrano nella pagina con i comandi di riproduzione; un PDF entra come link al file.

### Video da YouTube o Vimeo, caricati al clic

Dal menu **Aggiungi** → **Video YouTube o Vimeo…** incolli l'indirizzo del video. Histeon scarica una volta l'immagine di anteprima nella libreria del sito e inserisce un riquadro con l'anteprima e il pulsante di riproduzione. Chi visita la pagina non contatta YouTube o Vimeo finché non avvia il video: solo allora il lettore si carica (da `youtube-nocookie.com`, o da Vimeo con «non tracciare»). Nella tela si vede l'anteprima; il lettore si prova nell'**Anteprima**.

### Mappe caricate al clic

Dal menu **Aggiungi** → **Mappa (OpenStreetMap o Google)…** incolli un link di OpenStreetMap o di Google Maps (o il codice «incorpora» che danno) e scrivi il nome del luogo. Nella pagina c'è un riquadro con il nome e «Carica la mappa»: la mappa arriva, e il fornitore riceve una richiesta, solo quando chi visita la apre. Senza JavaScript il link apre la mappa sul sito del fornitore.

### Tutti i testi alternativi della pagina

Dalla palette (**Testi alternativi della pagina**) o dai **Controlli**, una tabella mostra tutte le immagini della pagina con il loro testo alternativo: scrivilo direttamente lì, segna **Decorativa** le immagini che non dicono nulla, e con **Solo senza testo alternativo** vedi solo quelle da sistemare.

### Punto focale

Quando il riquadro taglia l'immagine, nelle **Proprietà** fai clic su **Punto focale** sul punto da tenere sempre in vista (un volto, un prodotto); le frecce lo spostano e **Al centro** lo toglie.

> Un'immagine decorativa può avere il testo alternativo vuoto; un'immagine che dice qualcosa (un prodotto, una persona, un grafico) deve descriverlo. I controlli prima della pubblicazione segnalano le immagini senza testo alternativo.

## Immagini responsive

Uno schermo di telefono non ha bisogno di una foto da 2560 px. WordPress crea più misure di ogni immagine caricata; Histeon fa in modo che il browser scelga quella giusta.

### Immagini scelte dalla libreria

Quando inserisci un'immagine dal gestore dei media, Histeon scrive nell'elemento:

- `src`, l'immagine originale;
- `srcset`, le altre misure con le stesse proporzioni (le miniature ritagliate sono escluse, perché deformerebbero l'immagine);
- `sizes`, l'indicazione per il browser su quanto spazio occupa;
- `width` e `height`, perché il browser riservi lo spazio e la pagina non "salti" mentre carica;
- `alt`, il testo alternativo salvato nella libreria.

Sono attributi normali: li vedi e li modifichi nel pannello **Proprietà** o nel pannello HTML. Se cambi il layout, per esempio mettendo l'immagine in una colonna stretta, puoi correggere `sizes` a mano.

### Immagini che arrivano dai contenuti

Un'immagine scritta con un'espressione, come in un loop di articoli, riceve gli stessi attributi al momento della visita:

```html
<img src={item.image.url} alt={item.image.alt}>
```

Histeon sa quale immagine della libreria è, quindi aggiunge `width`, `height`, `srcset` e `sizes`. Aggiunge solo ciò che manca: quello che hai scritto tu non viene toccato.

- Per una misura precisa di WordPress si scrive il suo nome: `{item.image.medium_large}` usa quella misura, con le sue dimensioni.
- Se l'immagine ha `loading="lazy"`, `sizes` comincia con `auto`: il browser usa la larghezza reale dell'immagine nella pagina e scarica il file più adatto.
- `loading`, `decoding` e `fetchpriority` li gestisce WordPress, come per il resto del sito.

Le chiavi di un'immagine che puoi leggere sono `url`, `alt`, `width`, `height`, `caption`, `title`, `srcset`, `sizes` e `id`.

## Font locali

Con i **Font locali** di Histeon i file dei caratteri stanno nel tuo sito e sono serviti dal sito stesso: le pagine non li chiedono ad altri server, nemmeno a Google.

Li trovi in due posti: nel builder, in **Design system** → **Font del sito**, e in **Histeon → Impostazioni**, sezione **Font locali**. Tutti quelli che costruiscono li vedono; solo gli amministratori li aggiungono o li tolgono.

### Dal Design system

**Font del sito** mostra le famiglie servite dal sito, ognuna con un'anteprima scritta nel suo carattere e i pesi che ha. **Copia** copia la pila del carattere (`"Inter", sans-serif`); con Histeon CSS i pulsanti **Testo**, **Titoli** e **Codice** la mettono nei caratteri del generatore, con l'anteprima nella tela, e **Salva in sviluppo** la tiene.

Per aggiungere una famiglia scrivi in **Aggiungi da Google Fonts** una parte del nome o il genere (`serif`, `monospace`…): l'elenco è incluso in Histeon, quindi la ricerca non chiede niente a Google. Scegli la famiglia, spunta i pesi (e **Anche il corsivo**, se c'è) e premi **Scarica sul sito**. Se Google non ha uno dei pesi scelti, Histeon scarica gli altri. Una famiglia che non è nell'elenco si aggiunge da Impostazioni, scrivendone il nome.

### Importare da Google Fonts

1. In **Importa da Google Fonts** scrivi la **Famiglia**, per esempio `Inter`.
2. In **Pesi** scrivi i pesi che ti servono, separati da virgole: `400, 700`.
3. Spunta **anche corsivo** se ti serve.
4. Premi **Scarica nel sito**.

Il server scarica i file una volta sola da Google e da quel momento li serve dal tuo sito. Chi visita le pagine non contatta mai Google.

### Caricare un file

Se hai un font tuo o acquistato con licenza per il web:

1. In **Carica un file** scegli il file, in formato **WOFF2** o **WOFF**, al massimo 2 MB.
2. Indica **Famiglia**, **Peso** e **Stile** (**normale** o **corsivo**).
3. Premi **Carica**. Ripeti per ogni peso e stile.

Histeon riconosce il file dal contenuto, non dal nome: un file che non è davvero un font viene rifiutato.

### Usare i font

Nel CSS si usano con il nome della famiglia:

```css
body {
  font-family: "Inter", system-ui, sans-serif;
}
```

Le regole per i font arrivano da sole nelle pagine del sito, nell'editor a blocchi e nella tela del builder, con la visualizzazione immediata del testo mentre il font carica. Se esporti il sito statico, i file dei font vengono copiati insieme alle pagine.

Per togliere un font, premi **Rimuovi** accanto alla sua famiglia nell'elenco.

> I font sono file e non hanno versioni: aggiungerli o rimuoverli vale subito, senza pubblicare una release. Prima di rimuovere un font, controlla che il CSS del sito non lo usi più.
