# Interazioni e codice

Molte pagine hanno bisogno di qualcosa che si muove: schede, domande frequenti che si aprono, un menu per il telefono. Histeon offre queste interazioni già pronte, senza scrivere codice. Quando serve di più, puoi aggiungere JavaScript alla pagina o usare il PHP del tuo tema. Questo capitolo spiega anche i form del sito statico e la revisione delle pagine da parte dei clienti.

## Interazioni senza codice

Nel menu **Aggiungi** ([[Ctrl+I]]), gruppo **Interazioni**, trovi:

| Interazione | Cosa fa | Senza JavaScript |
|---|---|---|
| **Schede** | Pulsanti che mostrano un pannello alla volta. Le frecce, Home e Fine passano da una scheda all'altra. | Tutti i pannelli visibili, uno sotto l'altro |
| **Fisarmonica** | Domande e risposte: una sezione aperta alla volta. Usa l'elemento nativo del browser, quindi tastiera e lettori di schermo funzionano da soli. | Funziona uguale: non usa script |
| **Menu mobile** | Un pulsante **Menu** apre e chiude la navigazione sugli schermi stretti; [[Esc]] lo chiude e riporta il focus sul pulsante. | Menu sempre aperto |
| **Comparsa allo scorrimento** | Il blocco compare quando entra nello schermo. | Tutto visibile; con il movimento ridotto attivo, subito visibile |
| **Selettore di lingua** | I link alle altre lingue della pagina, se il sito è multilingua. | Funziona uguale |

Ogni inserimento è normale HTML di Histeon, con classi in stile BEM (`tabs`, `tabs__tab`, `accordion__item`…) e i suoi stili di base. Puoi cambiare testi, struttura e aspetto come per ogni altro elemento. Gli stili di base vengono aggiunti solo alle classi che nel sito non hanno ancora uno stile, quindi un secondo inserimento non sovrascrive quello che hai già personalizzato.

> Nella tela del builder gli script non girano: schede e menu si vedono aperti, così ogni parte resta modificabile. Il comportamento si prova con **Anteprima**.

Lo script delle interazioni pesa pochi KB e si carica solo nelle pagine che ne usano almeno una. Legge solo gli attributi delle interazioni: non esegue mai niente di scritto nella pagina.

## JavaScript della pagina

Ogni pagina, template o componente può avere un suo script, nel pannello **JavaScript** del builder. Lo script va in pagina come `<script type="module">` dopo il contenuto.

- **Nella tela e nell'anteprima del builder non viene eseguito.** Lo vedi in azione sulla pagina pubblicata, o aprendo l'anteprima in una **Nuova scheda**.
- Si salva e si pubblica con il documento, e ha le sue versioni come il resto.
- Lo script di un componente arriva in ogni pagina che usa quel componente.

### Chi può scrivere codice

Il JavaScript è il contenuto più delicato di un sito: chi può scriverlo può fare qualunque cosa nel browser di chi visita, compresi gli amministratori. Per questo Histeon segue la regola di WordPress:

- **Solo chi ha il permesso `unfiltered_html`** può scrivere o modificare il JavaScript. In un'installazione standard ce l'hanno gli amministratori (e, nei multisito, solo i super amministratori).
- Gli altri vedono il pannello in sola lettura, con la nota **Sola lettura: solo chi ha il permesso `unfiltered_html` può modificare il JavaScript.**
- Allo stesso modo, il markup di chi non ha quel permesso viene ripulito al salvataggio, come fa WordPress con il contenuto degli autori.
- **La fiducia non si "ricicla".** Se un redattore senza `unfiltered_html` modifica una pagina e poi un amministratore la salva o la pubblica, quel contenuto viene ripulito di nuovo: un amministratore non lo rende fidato solo premendo un pulsante.
- **Anteprima protetta.** Quando la versione in sviluppo contiene contenuto non fidato, l'anteprima gira con gli script bloccati.

> La stessa regola vale quando importi una release da un altro sito: chi non ha `unfiltered_html` ottiene il markup ripulito e nessun JavaScript.

### Script del sito

Una libreria o un file che serve a tutto il sito (una mappa, un modulo di prenotazione, uno strumento di statistiche) si aggiunge una volta sola: nel pannello **JavaScript**, apri **Script del sito** e premi **Aggiungi uno script**. Per ogni script scegli:

- **Indirizzo del file**: un file di questo sito (per esempio `/wp-content/uploads/js/mappa.js`) o un indirizzo `https`.
- **Impronta di integrità**: obbligatoria per un file di un altro sito (`sha384-…`, la trovi accanto al link della libreria). Se quel file cambia, il browser non lo esegue.
- **Dove**: tutto il sito, oppure solo le pagine di un tipo di contenuto (e il suo archivio).
- **Posizione** (testata o fondo pagina) e **Caricamento**: *defer* (dopo la pagina, il consueto), *async*, *modulo* o *subito* (blocca la pagina finché non arriva: solo se serve davvero).

Gli script del sito si salvano in sviluppo e vanno sul sito con la prossima release, come le impostazioni. Valgono le stesse regole del JavaScript della pagina: solo chi ha `unfiltered_html` li cambia e li pubblica, un'importazione o un agente non li tocca, e nel builder non girano.

## PHP: nei file del tema, non nell'editor

Histeon **non esegue mai PHP scritto nel builder**, in nessuna forma. Il motivo è semplice: un account del builder rubato, una release importata da un altro sito o un link di anteprima non devono poter eseguire codice sul server. Il permesso giusto per scrivere PHP è quello di modificare i file del tema, che molti siti tengono spento, e Histeon non lo riapre da un'altra porta.

Il PHP si scrive quindi in un file del tema (per esempio `functions.php`) o di un plugin, dove ha le sue difese: è versionato, si rivede, si salva con i backup. Le pagine lo usano in tre modi sicuri.

| Cosa | Nel PHP | Nella pagina |
|---|---|---|
| **Dati** | Il filtro `xb_data_providers` registra una funzione con un nome | `{data.meteo.oggi}` o `{#loop data.meteo.giorni as g}` |
| **Modificatori** | Il filtro `xb_modifiers` registra una trasformazione | `{item.product.price.euro()}` |
| **Blocchi dinamici** | `register_block_type` con una funzione di rendering | Menu **Aggiungi**, gruppo **Blocchi PHP del sito** |

Esempio di modificatore, da mettere nel `functions.php` del tema:

```php
add_filter( 'xb_modifiers', static fn ( array $m ): array => $m + array(
	'euro' => static fn ( mixed $v ): string => number_format( (float) $v, 2, ',', '.' ) . ' €',
) );
```

Le regole che proteggono il sito:

- I valori dei dati escono ripuliti come ogni espressione: una funzione non può stampare HTML attraverso `{data.…}`.
- Contenuti, termini e utenti restituiti da una funzione seguono le solite regole di visibilità: una bozza resta nascosta a chi visita.
- Un errore nella funzione lascia il valore vuoto, la pagina resta in piedi.
- Un blocco dinamico produce l'HTML che decide il suo codice, con la stessa fiducia di un template del tema.

Nel pannello HTML, dentro `{…}`, dopo `data.` il completamento propone i dati registrati dal sito; dopo un altro punto, [[Ctrl+Spazio]] propone i modificatori.

## API JavaScript del builder

Per chi crea strumenti o automatismi, la pagina del builder espone `window.xb`: le stesse operazioni dei controlli del builder, sullo stesso stato. Non dà permessi nuovi: ogni salvataggio passa dagli stessi filtri e dalle stesse versioni.

| Metodo | Cosa fa |
|---|---|
| `getHtml()`, `setHtml(html)` | Legge e sostituisce il testo del pannello HTML |
| `getSelection()`, `select(uid)` | Selezione |
| `insert(html)`, `remove(uid)` | Aggiunge dentro o dopo la selezione, toglie un elemento |
| `getCss(selettore)`, `setCss(selettore, css)` | CSS di una voce di stile |
| `render()` | HTML e CSS della pagina come li rende il server |
| `undo()`, `redo()`, `save()`, `isDirty()` | Come i comandi del builder |
| `on(evento, fn)` | Ascolta un evento: `change`, `select` o `save` |

```js
xb.on('select', ({ uid }) => console.log(xb.getElementHtml(uid)));
xb.insert('<p class="nota">Aggiunto da uno strumento</p>');
await xb.save();
```


## Moduli con Fluent Forms

Histeon non costruisce moduli: per contatti, iscrizioni e preventivi usa **Fluent Forms**, se è attivo sul sito, direttamente dal builder.

1. Nel menu **Aggiungi** ([[Ctrl+I]]) trovi il gruppo **Moduli (Fluent Forms)** con i moduli del sito: scegline uno e compare nella pagina, disegnato da Fluent Forms come sul sito.
2. Seleziona il modulo e, nel pannello **Proprietà**, premi **Modifica il modulo**: si apre l'editor di Fluent Forms dentro il builder. Lì cambi campi, messaggi e notifiche.
3. **Chiudi e aggiorna l'anteprima** riporta alla pagina, con il modulo aggiornato.
4. **Nuovo modulo Fluent Forms…**, nello stesso gruppo del menu, crea un modulo senza uscire dal builder.

> Invii, notifiche e permessi restano quelli di Fluent Forms: il gruppo compare solo a chi in Fluent Forms può gestire i moduli. Su un sito statico i moduli di Fluent Forms funzionano con Static Bridge.

## Form del sito statico

Un sito statico non ha un server che riceve i form. Histeon risolve il problema per i siti pubblicati su Cloudflare: il form invia i dati al Worker del sito, che li controlla e li inoltra a WordPress.

Come si costruisce un form:

1. Il form invia con `method="post"` all'indirizzo `/xb-form`.
2. Contiene il widget **Cloudflare Turnstile** (il controllo anti-bot), con la tua chiave del sito.
3. Due campi nascosti facoltativi: `xb_form`, il nome del form (per esempio `contatti`), e `xb_redirect`, la pagina del sito dove portare chi ha inviato (per esempio `/grazie/`).

```html
<form method="post" action="/xb-form">
  <input type="hidden" name="xb_form" value="contatti">
  <input type="hidden" name="xb_redirect" value="/grazie/">
  <label>Nome <input name="nome" required></label>
  <label>Email <input name="email" type="email" required></label>
  <label>Messaggio <textarea name="messaggio"></textarea></label>
  <div class="cf-turnstile" data-sitekey="LA-TUA-CHIAVE"></div>
  <button>Invia</button>
</form>
```

Il widget di Turnstile ha bisogno dello script di Cloudflare. Se il tuo sito non lo carica già, puoi aggiungerlo dal pannello **JavaScript** della pagina:

```js
const s = document.createElement('script');
s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
s.async = true;
document.head.append(s);
```

Cosa succede a ogni invio:

- il Worker verifica Turnstile, tiene al massimo 50 campi e fa redirect solo verso pagine dello stesso sito;
- WordPress accetta solo invii firmati dal Worker e recenti, e rifiuta i doppioni;
- il messaggio compare in wp-admin in **Messaggi dai form**, visibile solo agli amministratori, e arriva un'email all'indirizzo dell'amministratore del sito;
- i messaggi sono dati personali: vengono cancellati dopo 180 giorni e rientrano negli strumenti privacy di WordPress (esportazione e cancellazione).

La chiave segreta di Turnstile si inserisce in **Histeon → Impostazioni → Sito statico**, sezione **Form del sito statico (Turnstile)**, con **Invia al Worker**: viene mandata a Cloudflare e non resta sul sito.

> Il percorso `/xb-form` esiste solo sul sito statico pubblicato su Cloudflare. Sul sito WordPress dinamico usa un plugin per i form. Vedi anche il capitolo sul sito statico.

## Revisione e commenti dei revisori

Un cliente o un collega può commentare una pagina prima che vada online, senza un account WordPress.

1. Nel builder premi **Anteprima**, poi **Nuova scheda**: l'indirizzo che si apre è un link di anteprima firmato e a scadenza.
2. Manda quel link a chi deve rivedere.
3. Chi apre il link vede in basso a sinistra la barra **Revisione**:
   - **Commenta**: passando sopra la pagina gli elementi si evidenziano (**Scegli un elemento… (Esc)**); un clic apre il riquadro, si scrive il commento e il proprio nome, poi **Invia**;
   - **Approva**: chiede il nome e registra l'approvazione della versione che si sta guardando.

Nel builder, la sezione **Commenti** raccoglie i commenti del documento, divisi tra **Da fare** e **Risolti**, con autore e data. **Mostra l'elemento** seleziona l'elemento commentato; poi **Risolto**, **Riapri** o **Elimina**. L'icona della sezione indica quanti commenti sono da fare.

L'approvazione compare in cima ai Commenti e nella finestra **Pubblica**. Ricorda la versione vista: se dopo l'approvazione hai cambiato la pagina, il builder lo dice e conviene chiederne una nuova.

> Il link di anteprima è la credenziale di chi rivede: chi lo ha può commentare quel documento finché il link è valido. Se il contenuto in sviluppo è stato scritto da chi non ha `unfiltered_html`, l'anteprima gira con gli script bloccati e la barra di revisione non compare.

I commenti accettano solo testo semplice (al massimo 2000 caratteri) e hanno limiti contro gli invii ripetuti. I nomi dei revisori sono dati personali e si cancellano insieme al commento.
