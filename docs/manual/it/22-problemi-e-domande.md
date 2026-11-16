# Problemi e domande frequenti

Le situazioni che capitano più spesso, con la causa e cosa fare. Se un problema non è qui, guarda il capitolo dell'argomento: spesso la risposta è nelle note.

## Il builder e la pubblicazione

### Il builder non si apre, o non vedo «Modifica con Histeon»

Compare il messaggio «Non hai i permessi per modificare questo contenuto con Histeon», oppure il link manca dall'elenco delle pagine.

Per aprire una pagina nel builder servono **due** cose: il permesso `xb_build` di Histeon e il permesso di WordPress di modificare **quella** pagina. Di serie `xb_build` ce l'hanno solo gli amministratori. Per i template serve anche il permesso di modificare il tema.

Cosa fare: chiedi a chi amministra il sito di darti `xb_build` (vedi [Permessi e sicurezza](#it-permessi-e-sicurezza)). Il builder si apre su pagine, articoli, componenti e template: non su altri tipi di contenuto.

### Ho salvato, ma sul sito non cambia nulla

È il comportamento normale. **Salva** (o [[Ctrl+S]]) mette le modifiche **in sviluppo**: le vedi nel builder e in **Anteprima**, ma chi visita il sito vede ancora la versione online. Per metterle online usa **Pubblica…** nella barra in alto.

Se il problema resta dopo la pubblicazione, controlla:

- **la pagina in WordPress:** se è ancora una bozza, i visitatori non la vedono;
- **i componenti tradotti:** una traduzione di un componente si usa solo quando è pubblicata (vedi [Siti in più lingue](#it-multilingua));
- **la copia statica:** se il sito è servito come copia statica, vedi più avanti;
- **la cache del browser o del sito:** prova a ricaricare la pagina forzando l'aggiornamento.

### Non vedo il pulsante «Pubblica…»

Il pulsante compare solo a chi ha il permesso `xb_publish`. Senza, puoi costruire e salvare in sviluppo, e qualcun altro pubblica: la schermata **Release** lo ricorda.

Se il pulsante c'è ma è disattivato, non ci sono modifiche da pubblicare. Se nella finestra di pubblicazione il pulsante **Pubblica** resta grigio, i controlli delle pagine hanno trovato errori: correggili, poi riprova.

### Il pannello HTML è in sola lettura

Succede quando un elemento contiene contenuto scritto fuori da Histeon (per esempio dall'editor a blocchi o da un'importazione) che il codice di Histeon non può rappresentare fedelmente. È una protezione: modificarlo potrebbe salvare qualcosa di diverso da quello che vedi. Il messaggio nel pannello lo spiega. Correggi l'elemento dalla struttura o dagli altri pannelli.

### Il pannello JavaScript è in sola lettura

Solo chi ha il permesso WordPress `unfiltered_html` può scrivere JavaScript. È voluto: vedi [Chi può scrivere codice](#it-permessi-e-sicurezza-chi-puo-scrivere-codice).

### Ho pubblicato una versione sbagliata

Apri **Histeon → Release**, trova la release giusta nello **Storico delle release** e premi **Ripristina**. La release ripristinata torna online come una release nuova, e le modifiche che hai in sviluppo restano dove sono. Vedi [Versioni e release](#it-versioni-e-release).

## Il sito statico

### La copia statica non è aggiornata

La copia statica è una fotografia del sito: cambia solo quando viene rigenerata (vedi [Sito statico](#it-sito-statico)).

- **Con Static Bridge:** in **Histeon → Release**, sezione **Sito statico**, controlla lo stato. Se dice «si aggiorna da sola a ogni release Histeon», dopo una pubblicazione Static Bridge aggiorna le pagine coinvolte entro poco tempo (le vedi come «pagine in coda di rigenerazione»). Se dice «aggiornamento automatico spento: rigenera da Static Bridge», rigenera a mano da **Apri Static Bridge**.
- **Con Cloudflare:** se la pubblicazione automatica è attiva, ogni release pianifica un deploy in background, che parte poco dopo con le operazioni pianificate di WordPress. Altrimenti, nel builder apri la sezione **Versioni** e premi **Pubblica ora su Cloudflare**.
- **Stringhe tradotte:** una stringa di Lingua Bridge vale per tutte le pagine di una lingua. Dopo averne cambiata una, rigenera la copia statica completa.

### Il deploy o lo zip si fermano con un errore di contenuti privati

Prima di pubblicare su Cloudflare o di creare lo zip, Histeon controlla ogni pagina. Se trova dati che non devono uscire (percorsi del server, codici di sicurezza, link di anteprima), si ferma. Di solito la causa è un plugin che stampa dati di sessione nelle pagine anche per i visitatori anonimi. Trova la pagina indicata e correggi la causa: il controllo non si può saltare.

### Static Bridge non si accende

Static Bridge richiede **PHP 8.4**. Con un PHP più vecchio la schermata **Moduli** mostra «Richiede PHP 8.4». Se invece il PHP va bene, i motivi del rifiuto compaiono nella stessa schermata (memoria, funzioni dei file, versione di WordPress).

## Moduli e aggiornamenti

### Un modulo si è spento da solo

Compare l'avviso «Histeon ha spento un modulo dopo un errore fatale». Histeon ha fermato il modulo perché causava un errore, e il resto del sito funziona. Aggiorna Histeon (o correggi la causa indicata nell'avviso), poi riaccendi il modulo in **Histeon → Moduli**. Vedi [Quando un modulo va in errore](#it-moduli-e-aggiornamenti-quando-un-modulo-va-in-errore).

### L'amministrazione non si apre più

Se sospetti un modulo, chi ha accesso ai file del sito può aggiungere a `wp-config.php` la riga:

```php
define( 'XB_SAFE_MODE', true );
```

Nessun modulo verrà caricato. Quando il problema è risolto, togli la riga.

### Non posso accendere Lingua Bridge (o Static Bridge)

Se il messaggio dice «È attivo come plugin separato», lo stesso software è già attivo come plugin: va bene così, oppure disattiva il plugin e accendi il modulo. I dati sono gli stessi.

Se la schermata dice «Spento da wp-config.php», qualcuno l'ha spento con `XB_SAFE_MODE` o `XB_DISABLE_MODULES`: va tolta quella riga.

### Histeon non trova aggiornamenti

Sotto le impostazioni degli aggiornamenti compare «Nessuna release firmata trovata (o GitHub non raggiungibile)». Controlla che il repository sia scritto bene e che sia **pubblico**: un repository privato non risponde. Histeon riprova da solo ogni 12 ore.

### Un aggiornamento è stato rifiutato

Histeon installa solo pacchetti firmati con la chiave pubblica configurata. Se la firma non corrisponde, l'aggiornamento si ferma e il file scaricato viene cancellato. Controlla che la chiave nelle impostazioni sia quella di Histeon (la trovi in [Moduli e aggiornamenti](#it-moduli-e-aggiornamenti-perche-le-release-sono-firmate)); se lo è, **non** forzare l'installazione a mano.

## Importazioni

### L'importazione di una release o di un sito è rifiutata

Il messaggio dice per esempio «File di release non valido: contenuto alterato o incompleto (hash)», oppure «File del sito non valido: …».

Ogni file esportato da Histeon contiene un'impronta del suo contenuto. Se il file è stato modificato dopo l'esportazione, anche di una virgola, oppure il download si è interrotto, l'impronta non torna e Histeon rifiuta tutto il file. Esportalo di nuovo dal sito di origine e non aprirlo né modificarlo prima di importarlo.

Altri motivi di rifiuto: il file supera i limiti di dimensione, oppure supera il limite di caricamento del server («File non ricevuto (forse supera il limite di caricamento del server)»).

### Dopo il trasloco mancano dei contenuti

Se il sito di origine ha tipi di contenuto creati in Histeon, al primo passaggio i loro contenuti vengono saltati con un avviso: WordPress li riconosce solo dopo che le impostazioni sono pubblicate. Pubblica la release, poi importa **di nuovo lo stesso file**: la seconda importazione non duplica nulla.

### Un file XLIFF tradotto non si importa

L'originale è cambiato dopo l'esportazione. Esporta di nuovo: il programma di traduzione ritroverà le frasi già tradotte nella sua memoria.

### Ho importato un sito Etch e manca del JavaScript

Histeon importa il JavaScript solo dai contenuti scritti da persone che avevano il permesso di scrivere codice (`unfiltered_html`). Il rapporto di importazione lo indica nella colonna **Fiducia**. Se lo script serve davvero, chi ha quel permesso lo riscrive nel builder. Vedi [Importare un sito Etch](#it-importazione-etch).

### Ho spento Etch: il sito cambierà?

No. Con Etch spento, Histeon disegna da sé i contenuti salvati con Etch e il sito resta com'era. L'importazione resta consigliata, perché rende tutto modificabile nel builder.

### Ho spento (o tolto) Automatic.css

Finché i fogli che Automatic.css aveva generato restano nella cartella dei caricamenti del sito, restano caricati finché non importi: classi e variabili continuano a funzionare. Un avviso ricorda che l'importazione in Histeon CSS è consigliata. **Non cancellare quei fogli** prima di aver importato: senza di loro le pagine perderebbero i loro stili.

## Lingue e agenti IA

### Il selettore di lingua non compare

Compare solo quando la pagina ha almeno **due** versioni pubblicate. Le traduzioni in bozza non contano.

### La traduzione fatta dall'IA non è online

È voluto: agenti, traduzione automatica e XLIFF creano **solo bozze**, mai pagine pubblicate. Rivedi la bozza e pubblicala tu.

### Il connettore IA non si collega

Controlla, in ordine:

1. che sul computer ci sia **Node 18** o successivo;
2. che il builder sia aperto in **HTTPS** oppure su `localhost`;
3. che il connettore sia avviato con il comando copiato **dopo** l'ultima rigenerazione del segreto: se hai rigenerato il segreto, ricopia le istruzioni e riavvia il connettore;
4. di aver premuto **Collega questa scheda** nel pannello ✦.

Ricaricando la scheda il collegamento si riapre da solo; chiudendola si chiude.

## Domande frequenti

**Serve una licenza?** No. Histeon è libero (GPL), senza chiavi di licenza. Anche Histeon CSS, Lingua Bridge e Static Bridge lo sono.

**Histeon invia dati a qualcuno?** Non da solo: nessuna telemetria. Si collega a servizi esterni solo per le funzioni che attivi tu (per esempio aggiornamenti, import dei font, Cloudflare, traduzione automatica con la tua chiave), e un agente IA che colleghi riceve ciò che legge dal sito. Vedi [Permessi e sicurezza](#it-permessi-e-sicurezza).

**Un agente IA può rovinare il sito online?** Dal connettore no: lavora solo in sviluppo e non può pubblicare. Nemmeno con le abilità di WordPress: può chiedere di pubblicare, ma a pubblicare, dalla pagina **Release**, è una persona.

**Posso usare Histeon in inglese?** Sì: scegli l'inglese nel tuo profilo WordPress. Vedi [L'interfaccia in italiano e in inglese](#it-lingue-interfaccia).

**Posso cambiare le scorciatoie?** Sì, ogni comando della palette: [[Ctrl+K]] → **Scorciatoie da tastiera…**. Vedi [Scorciatoie da tastiera](#it-scorciatoie).

**Spegnere un modulo cancella i dati?** No, mai.
