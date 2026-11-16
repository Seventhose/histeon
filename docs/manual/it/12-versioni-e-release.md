# Versioni e release

In Histeon lavori su una copia del sito che solo tu vedi, e la metti online quando è pronta, tutta insieme. Se qualcosa va storto, torni indietro con un clic. Questo capitolo spiega come funziona, dal salvataggio alla pubblicazione, fino allo spostamento di un sito intero da un server all'altro.

## Sviluppo e online

Ogni pagina, template, componente, foglio di stile e impostazione di Histeon esiste in due versioni:

| Versione | Chi la vede | Come cambia |
|---|---|---|
| **In sviluppo** | Tu, la tela del builder e chi ha un link di anteprima | Ogni volta che salvi nel builder |
| **Online** | Chi visita il sito | Solo quando pubblichi una release |

**Salva** ([[Ctrl+S]]) scrive in sviluppo: il sito pubblico non cambia. Puoi salvare quanto vuoi, chiudere il builder, tornare domani. **Pubblicato**, nella barra in alto, apre la pagina come la vedono i visitatori adesso.

> Gli articoli, i contenuti dei tuoi tipi e i media non hanno versioni Histeon: come in WordPress, le loro modifiche vanno subito online. Le versioni riguardano la struttura delle pagine, i template, i componenti, gli stili, il JavaScript e le impostazioni (query, sorgenti, filtri, tipi di contenuto).

### I testi scritti dal cliente

Mentre lavori in sviluppo, un redattore può correggere un testo della stessa pagina nell'editor a blocchi. Quella modifica va subito online. Histeon non la perde: quando carichi il documento nel builder e di nuovo quando pubblichi, unisce i testi.

- Se hai cambiato solo la struttura, il testo del cliente resta.
- Se avete cambiato tutti e due lo stesso testo, vince quello del builder e la finestra di pubblicazione lo segnala come conflitto.

## Pubblicare una release

Una **release** mette online in un colpo solo tutto quello che hai scelto. Ha un numero di versione (1.0.0, 1.0.1, 1.1.0…), delle note e un autore, ed è una fotografia completa del sito: per questo si può sempre tornare a una release precedente.

### Dal builder

1. Premi **Pubblica…** nella barra in alto.
2. In **Cosa va online** trovi ogni documento salvato in sviluppo e non ancora pubblicato. Togli la spunta a quelli che non vuoi pubblicare adesso.
3. Facoltativo: **Differenze** accanto a un documento mostra le righe cambiate rispetto al sito pubblicato.
4. Facoltativo: i **Controlli prima di pubblicare** e il **Confronto visivo** (vedi sotto).
5. Controlla la **Versione** proposta (la successiva, in automatico) e scrivi nelle **Note** cosa cambia.
6. Premi **Pubblica**.

In cima alla finestra compare anche l'approvazione di un revisore, se c'è (vedi il capitolo sulle interazioni e il codice). La pubblicazione è tutto o niente: se un documento non si può applicare, nessuno va online.

### Da Histeon → Release in wp-admin

La schermata **Histeon → Release** mostra la stessa cosa fuori dal builder:

- **Da pubblicare**, con l'elenco dei documenti e **la prossima release sarà la …**. Il numero di versione si può lasciare vuoto (**Lascia vuoto per la prossima in automatico.**); le **Note** sono facoltative. Poi **Pubblica i documenti scelti**.
- **Storico delle release**, con le release passate, quella **online**, i **ripristino**, e i pulsanti **Ripristina** ed **Esporta**.
- Le sezioni **Sito statico**, **Importa una release** e **Trasloco del sito**, descritte più avanti.

La voce **Release** del menu mostra un numero quando ci sono documenti in attesa.

> Per pubblicare serve il permesso di pubblicare di Histeon. Gli amministratori lo hanno; puoi darlo ad altri ruoli con un editor dei ruoli. Chi costruisce senza quel permesso salva in sviluppo, e qualcun altro pubblica.

### Programmare una release

Invece di pubblicare subito puoi scegliere giorno e ora: la release esce da sola, ma **solo se nulla è cambiato** da quando l'hai programmata.

1. Nel builder premi **Pubblica…** e scegli i documenti, la versione e le note come sempre.
2. In **Quando** scegli **Programma per…** e scrivi giorno e ora. L'ora è sempre quella del sito (accanto al campo c'è il fuso, per esempio *ora del sito, Europe/Rome*), anche se tu sei in un altro paese. Almeno 5 minuti avanti, al più 60 giorni.
3. Premi **Programma**. Con **Blocca la pubblicazione se i controlli trovano errori** attiva, prima i controlli devono finire senza errori, come per **Pubblica**.

Da quel momento la finestra **Pubblica** e la schermata **Histeon → Release** mostrano la riga **Programmata per il … alle … da … · versione … · N documenti**, con **Annulla la programmazione**. Può annullarla chi l'ha programmata o chiunque possa pubblicare; se annulla un altro, chi aveva programmato riceve un'email. C'è **una sola release programmata alla volta**; **Pubblica ora** resta sempre possibile. La versione scelta resta riservata: nessun'altra release la prende.

**All'ora stabilita** Histeon pubblica con i permessi di chi ha programmato, e solo se:

- quella persona esiste ancora, può ancora pubblicare e modificare ogni pagina scelta;
- ogni documento è ancora com'era in sviluppo (lo stesso salvataggio);
- nessun'altra release ha toccato quei documenti, e la versione è ancora libera;
- non sono arrivati contenuti nuovi da Histeon CMS;
- il cliente non ha cambiato in Gutenberg un testo che cambia anche la release.

Se qualcosa non torna, **non va online nulla**: la release è **saltata**, il motivo finisce nel Registro e chi aveva programmato riceve un'email. Dopo l'ora la riga diventa per qualche giorno **Pubblicata la versione … alle …** oppure **Saltata la release programmata per il …: motivo**, finché qualcuno non preme **Chiudi**.

Cose da sapere:

- **Se salvi una pagina della release programmata**, quella release non partirà più. Il builder lo dice con una barra sopra la pagina.
- **Una release a mano** (o un invio da Histeon Publish) **sugli stessi documenti annulla la programmata**: la finestra **Pubblica** lo dice prima. Una release su altri documenti la lascia stare.
- **Un ripristino** annulla la programmata, dopo una conferma che lo dice.
- **Disattivare Histeon** annulla la programmata; riattivandolo non riparte.
- **Il cron.** WordPress fa partire le cose programmate solo quando qualcuno visita il sito. Se il sito non ha un cron di sistema (o è servito in statico, con Static Bridge o su Cloudflare) la release può partire in ritardo; **oltre 2 ore di ritardo non parte** e viene saltata. La finestra e la schermata Release lo dicono e mostrano la riga da far aggiungere al cron dell'hosting, più la riga `define( 'DISABLE_WP_CRON', true );` per `wp-config.php`.
- **Se cambi il fuso del sito** dopo aver programmato, l'istante resta quello: la riga lo mostra nell'ora nuova e ricorda l'ora scritta allora.
- Un agente IA non può programmare release, e nemmeno la REST con una password per applicazioni: si programma solo dal builder.
- Lo stesso vale per pubblicare subito, ripristinare una release e mettere online o tornare indietro con il sito statico: con una password per applicazioni la REST risponde con un rifiuto. Lo fa una persona dal builder o da wp-admin; i collegamenti firmati con Histeon Publish e con lo staging restano come li ha creati l'amministratore.

### Vedere la pagina come un altro visitatore

Nell'**Anteprima** del builder, **Vedi come** mostra la pagina a chi non ha fatto l'accesso, a un abbonato, a un cliente del negozio (i ruoli del sito che non scrivono). Cambiano le condizioni scritte con `user`, per esempio `{#if user.loggedIn}` o `{#if user.role == "customer"}`. È un visitatore di prova: nessun account viene usato, e ciò che altri plugin mostrano per ruolo resta quello di chi non ha fatto l'accesso.

### Proposte per il cliente: A e B

Per far scegliere al cliente tra due versioni, in **Histeon → Release**, sezione **Proposte per il cliente**:

1. prepara la prima versione nel builder e salvala in sviluppo;
2. scrivi il nome (*Proposta A*), scegli la pagina su cui si apre e premi **Salva lo sviluppo come proposta**;
3. cambia ciò che serve, salva, e salva la seconda proposta (*Proposta B*).

Ogni proposta ha il suo link di anteprima, valido 7 giorni: mostra il sito online con le modifiche di quella proposta, anche a chi non ha un account. **Confronta con** apre un link con le due proposte una accanto all'altra, da mandare al cliente. Quando ha deciso, **Scegli questa** riporta in sviluppo la proposta scelta (le altre modifiche in sviluppo tornano come sono online e restano nella storia): controllala e pubblicala come una release qualsiasi. **Elimina** spegne il link di una proposta.

## Ripristinare

Una release precedente si rimette online da **Histeon → Release** (**Ripristina** nello storico) oppure nella sezione **Versioni** del builder.

- Il ripristino **crea una nuova release**: la storia non viene mai riscritta, e puoi sempre tornare anche alla release da cui sei partito.
- Le modifiche in sviluppo non ancora pubblicate restano dove sono.
- Se c'è una release programmata, il ripristino la annulla (la conferma lo dice).
- Nel builder, l'opzione **Nei ripristini mantieni i testi scritti dopo quella versione** decide i testi: se è attiva, la struttura torna quella di allora ma i testi corretti nel frattempo restano; se è spenta, tornano anche i testi di allora.

## La storia di un documento

**Histeon → Versioni** elenca i documenti con il loro stato (**da pubblicare** o **online**). Aprendone uno vedi tutte le sue versioni, dalla più recente: ogni salvataggio nel builder, ogni modifica fatta nell'editor a blocchi e ogni release.

- **Confronta** mostra le differenze tra quella versione e quella attuale in sviluppo.
- **Ripristina** riporta quella versione in sviluppo. Non va online da sola: andrà con la prossima release.
- Il segno **non fidata** indica una versione scritta da chi non ha il permesso `unfiltered_html`: a ogni ripristino viene ripulita di nuovo.

Dal builder ci arrivi dalla sezione **Versioni** con **Tutte le versioni di questa pagina, con confronto e ripristino ↗**.

### Quanto pesa la storia, e come alleggerirla

In **Histeon → Panoramica**, il riquadro **Storia delle versioni** dice quante versioni sono salvate, quanto spazio occupano nel database e da quando. Un amministratore può scegliere **Togli le versioni più vecchie di** 6, 12 o 24 mesi e premere **Pulisci la storia**: dopo una conferma, le versioni più vecchie di quella data spariscono e non si possono più ripristinare. Restano sempre ciò che è in sviluppo e online, le versioni da cui partono e ogni release, che resta ripristinabile. L'operazione finisce nel registro delle attività.

Allo stesso modo Histeon dimentica da solo, dopo 30 giorni, i resoconti degli invii da Histeon Publish già conclusi.

## Controlli prima di pubblicare

La sezione **Controlli** del builder guarda la pagina come è disegnata nella tela e segnala i problemi più comuni. L'icona mostra il numero di errori; **Mostra** seleziona l'elemento e torna alla tela.

| Area | Esempi di controlli |
|---|---|
| **Accessibilità** | Immagine senza testo alternativo, link o pulsante senza testo, campo senza etichetta, iframe senza titolo, livelli di titolo saltati, contrasto del testo insufficiente, link dal testo vago («clicca qui») |
| **SEO** | Pagina senza H1, oppure con due H1 |
| **Prestazioni** | Immagine senza larghezza e altezza, immagine molto più grande di come appare |

Ogni segnalazione è un **Errore**, **Da rivedere** o un **Consiglio**. Ciò che è nascosto apposta ai lettori di schermo non viene segnalato.

Nella finestra **Pubblica**, **Controlla** fa passare dagli stessi controlli la versione in sviluppo di ogni pagina che cambia (al massimo 6).

Lì si aggiungono controlli che nella tela non hanno senso:

| Controllo | Gravità |
|---|---|
| Pagina senza titolo (`<title>`) | Errore |
| Pagina senza lingua (`lang`) | Errore |
| WordPress chiede ai motori di ricerca di non indicizzare il sito (**Impostazioni → Lettura**, «Scoraggia i motori di ricerca»): detto una volta sola, e non blocca la pubblicazione | Da rivedere |
| Pagina senza descrizione | Da rivedere |
| Pagina che chiede di non essere indicizzata (`noindex`) | Da rivedere |
| Link a una pagina dello stesso sito che non esiste (risposta 404 o 410) | Da rivedere |
| Pagina che pesa più di 1,5 MB | Da rivedere |
| Pagina già online senza indirizzo canonico | Consiglio |

I link si provano dal tuo browser, senza i cookie, solo verso lo stesso sito: al massimo 200 per release, ognuno per 5 secondi. Un link a una pagina che esiste solo in sviluppo non conta come rotto, e nemmeno una risposta lenta o diversa da 404 e 410. I link verso altri siti non si provano. Il peso conta l'HTML, i fogli di stile, i font e le immagini caricate: gli script e le immagini caricate più in basso restano fuori, quindi il peso vero può essere più alto.

Se i controlli sono finiti quando premi **Pubblica**, la release si ricorda che cosa avevano trovato: in **Versioni** compare «Uscita con N errori e M avvisi». È un promemoria, non una prova, e non cambia niente di quello che si può fare. Una release pubblicata senza controlli finiti, da **Histeon → Release**, da un file o da Histeon CMS, non ha questa riga.

### Bloccare la pubblicazione con errori

In **Histeon → Impostazioni** puoi attivare **Blocca la pubblicazione se i controlli trovano errori**. Da quel momento:

- i controlli partono da soli quando apri la finestra **Pubblica**, e il pulsante **Pubblica** resta spento finché trovano errori (gli avvisi non bloccano);
- **Histeon → Release** non pubblica più e rimanda al builder.

> I controlli sono un aiuto per chi costruisce, non una verifica completa: non sostituiscono una prova con un lettore di schermo.

### Confronto visivo

Sempre nella finestra **Pubblica**, **Confronta** mette fianco a fianco la pagina **Online** e quella **In sviluppo**, a schermo grande e su telefono, e segna le differenze: in corallo ciò che è nuovo o cambia, in blu ciò che sparisce. **Prossima differenza** salta da una all'altra.

Confronta la pagina aperta e le pagine o i template che cambiano, al massimo 6. Le pagine sono mostrate senza i loro script, quindi ciò che uno script disegna dopo il caricamento non entra nel confronto. Il confronto è facoltativo e non blocca la pubblicazione.

Se non vuoi aprire il confronto pagina per pagina, nei **Controlli delle pagine** la riga **Aspetto** dice quante pagine cambiano a schermo grande e quante al telefono. Si calcola solo quando premi **Calcola**, perché carica ogni pagina altre quattro volte; **Guarda** apre il confronto visivo. È un'informazione, non un avviso.

## Esportare e importare una release

Una release si può portare su un altro sito Histeon con un file `.xbrelease`: tipicamente dal sito locale o di staging a quello di produzione.

**Esportare.** In **Histeon → Release**, **Esporta** accanto a una release (oppure **Esporta** nella sezione **Versioni** del builder) scarica il file. Contiene pagine, componenti, template, stili e impostazioni di quella release. Pagine e articoli sono riconosciuti per slug, i componenti per chiave, i template per nome.

**Importare.**

1. In **Importa una release** scegli il file e premi **Controlla il file**.
2. Histeon mostra cosa cambierebbe, senza ancora scrivere nulla.
3. **Importa in sviluppo** scrive tutto come modifiche in sviluppo. L'indirizzo del sito di origine diventa quello del sito attuale.
4. Controlla in anteprima, poi pubblica una release come al solito.

Pagine e articoli che mancano vengono creati come bozze. Ciò che non si può applicare (un framework non installato, una query non valida) finisce nel resoconto.

### La firma dei file

I file di release e di trasloco che esporti sono **firmati** con la chiave di questo sito, e l'anteprima di un'importazione dice chi li ha firmati:
- **Firmato da questo sito**, o **da un sito di cui ti fidi**: si importa come sempre.
- **Firmato da una chiave che questo sito non conosce**: l'anteprima mostra l'impronta della chiave (16 gruppi di 4 caratteri). Confrontala con quella che il sito d'origine mostra in **Histeon → Impostazioni → Firma degli export**, per esempio al telefono con chi te l'ha mandato. Se coincide, spunta **Mi fido di questa chiave**: da quel momento i file di quel sito si importano senza chiedere.
- **Non firmato** (un file di prima, o di un sito senza le chiavi di `wp-config.php`): si importa solo spuntando **Importa anche senza firma**, se sai da dove viene. In Impostazioni **Richiedi la firma** li rifiuta sempre.
- **La firma non corrisponde**, o la chiave è **revocata**: il file è rifiutato.

In **Impostazioni → Firma degli export** trovi l'impronta di questo sito, **Genera una nuova chiave** (i siti che si fidano della vecchia passano da soli alla nuova) e l'elenco dei siti di cui ti fidi, ciascuno con **Revoca**.

> Esportare e importare release è riservato agli amministratori. Il contenuto importato ha la fiducia di chi importa: senza `unfiltered_html`, il markup viene ripulito e il JavaScript non viene importato.


## Dallo staging alla produzione

Se lavori su un sito di staging, cioè una copia di prova, puoi mandare alla produzione quello che hai pubblicato, con le immagini, senza file da scaricare e caricare.

1. **Sul sito di produzione**, in **Histeon → Release → Ricevi da uno staging**, scegli se lo staging può anche pubblicare e premi **Crea il codice di abbinamento**. Copia il codice: si vede una volta sola.
2. **Sullo staging**, in **Histeon → Release → Invia a produzione**, incolla il codice e premi **Collega**.
3. Scegli **Ciò che ha cambiato l'ultima release** oppure **Tutto il sito**, poi premi **Anteprima sulla produzione**: vedi cosa cambierebbe là, senza che nulla venga scritto.
4. Premi **Invia alla produzione**. Se il codice non permette di pubblicare, in produzione tutto arriva in sviluppo e si pubblica da lì con una release.

> Il codice contiene il segreto del collegamento: non mandarlo per email in chiaro. Ogni richiesta è firmata e vale una volta sola. In produzione puoi **revocare il collegamento** quando vuoi, e il **Registro** dei due siti tiene traccia di ogni invio.

## Trasloco del sito

Una release porta solo i documenti di Histeon. Il **Trasloco del sito**, in **Histeon → Release**, porta **tutto il sito** in un file `.xbsite`: pagine, articoli, componenti, template, menu, stili, impostazioni, i contenuti dei tuoi tipi e i media che usano.

Non sono inclusi utenti, commenti, opzioni degli altri plugin, revisioni e storia delle versioni.

**Esportare.** **Esporta tutto il sito** scarica il file con quello che è online; le modifiche in sviluppo non partono.

**Importare.**

1. Carica il **File del sito (.xbsite)** e premi **Controlla il file**: vedi cosa verrebbe creato, aggiornato o saltato.
2. Scegli:
   - **Importa in sviluppo**: media caricati, documenti come modifiche in sviluppo, contenuti nuovi come bozze. Niente va online finché non pubblichi;
   - **Importa e pubblica**: mette online tutto, con lo stato che ogni contenuto aveva sul sito di origine.
3. **Annulla** cancella il file caricato. Un file in attesa resta disponibile per 30 minuti.

Importare due volte lo stesso file non duplica nulla: media e contenuti vengono ritrovati. È utile quando il file contiene tipi di contenuto nuovi: la prima importazione li crea, la seconda importa i loro contenuti.

> Solo gli amministratori traslocano un sito. I file molto grandi dipendono dal limite di caricamento del server. Un contenuto cancellato sul sito di origine non viene cancellato su quello di arrivo.

## Il pacchetto Histeon CMS come file

Il **pacchetto Histeon CMS** è quello che usa Histeon Publish: pagine, **articoli** con categorie e tag, componenti, template con il loro **ruolo** (home, articolo singolo, archivi, 404, ricerca…), stili, contenuti e media, tutto in un file `.xbsite`. È diverso dal trasloco: contiene le copie in sviluppo e si può rimandare indietro con le modifiche.

In **Histeon → Release → Histeon CMS → Il pacchetto come file**:

- **Scarica il pacchetto** lo salva sul computer, da aprire in Histeon Publish anche senza collegamento;
- **Pacchetto da importare** + **Controlla** verifica un pacchetto esportato da Histeon Publish e mostra cosa creerebbe o aggiornerebbe, senza scrivere nulla. Poi **Importa in sviluppo** (va online con la prossima release) oppure **Importa e pubblica**. **Annulla** cancella il file; un file in attesa resta disponibile per 30 minuti.

Un template con un ruolo diventa il template di WordPress per quel caso (per esempio `single` per gli articoli): se il tema ha già una versione personalizzata nel Site Editor con lo stesso nome, l'importazione si ferma e non la sovrascrive. Passano solo gli articoli fatti con Histeon; quelli scritti con l'editor a blocchi restano fuori.

> Solo gli amministratori scaricano e caricano il pacchetto. Un pacchetto di un altro progetto viene rifiutato: il sito resta legato a un solo progetto Histeon CMS.

### Cosa ferma un invio da Histeon Publish

Prima di ricevere un invio il sito rilegge i suoi contenuti. Un contenuto che non può viaggiare (per esempio un'immagine di un altro sito o un'espressione con `url.query`) resta fuori e viene nominato, ma se Histeon Publish lo conosce già l'invio si ferma con il suo nome: lasciato fuori, sembrerebbe cancellato. Si corregge sul sito, poi si riprova.

- `url.path` viaggia (Histeon 0.5.4): un menu che segna la voce attiva con `{#if url.path == "/festival/"}` va bene. Histeon Publish non manda un progetto che lo usa a un sito più vecchio, e lo dice.
- Un'immagine della libreria scritta con l'altro schema (`http://` su un sito passato a `https`) è la stessa immagine e viaggia.
- Ogni template ha il suo blocco del contenuto della pagina, anche quelli degli archivi: in un archivio resta vuoto, sia sul sito sia in Histeon Publish. Il builder avvisa quando a un template manca (capitolo 9).

### La pagina iniziale dopo il primo invio

Su un sito nuovo WordPress apre la `/` sull'elenco degli articoli, e la home del progetto sta a `/home/`. Dopo il primo invio con pubblicazione, Histeon Publish chiede se il sito deve aprirsi sulla home del progetto e, se il progetto ha una pagina «blog», elencare lì gli articoli. Solo se si risponde sì cambia **Impostazioni → Lettura** del sito (pagina iniziale e pagina degli articoli), subito online; serve un collegamento con il permesso di pubblicare. Il Registro lo segna come `cms_front_page`. Gli indirizzi degli articoli non cambiano. Si può sempre cambiare di nuovo da Impostazioni → Lettura.

### Convertire una pagina HTML: il resoconto

In **Histeon → Release**, **Converti una pagina HTML** trasforma una pagina, lo ZIP di un sito o la sua cartella in un progetto Histeon. Il resoconto della conversione è diviso per ciò che è successo:

- **Tolto**: ciò che non entra in un sito Histeon (script, iframe, gestori di eventi, file troppo grandi, stili non portabili);
- **Portato nel JavaScript della pagina**: i gestori di eventi e le finestre `<dialog>` che tornano a funzionare con il JavaScript della pagina;
- **Media query e breakpoint**: le larghezze delle media query del sito, quante volte, e se corrispondono a una media query con nome (`--mobile`, `--tablet`, `--desktop`) o qual è la più vicina;
- **Da controllare**: il resto, per esempio immagini senza testo alternativo o moduli senza indirizzo d'invio.

Lo stesso resoconto lo mostra Histeon Publish quando converte un sito.

Un **prototipo Histeon in un solo file** (l'HTML con `data-histos-prototype="1"`, come lo scrive un agente AI) non viene convertito: si importa così com'è, con template, componenti, contenuti e loop, nel progetto Histeon del sito. Le immagini scritte come `data:` arrivano con il file; quelle indicate con un indirizzo `https` le scarica il browser, se il sito che le ospita lo permette, altrimenti l'importazione si ferma e dice quale caricare in WordPress.

### Importare una pagina di questo sito

Nello stesso riquadro, **Importa una pagina di questo sito** copia in Histeon una pagina che il sito ha già, fatta con un altro strumento (per esempio con i blocchi di WordPress). Nasce una pagina nuova di Histeon, in sviluppo; la pagina d'origine resta com'è.

Che cosa aspettarsi:

- si copia l'**aspetto**: la struttura resta da rifinire nel builder, con le classi dello strumento d'origine;
- **entra solo il contenuto della pagina**: testata, menu, piè di pagina e barra di amministrazione del tema restano fuori, con i loro script (sotto);
- **parti e componenti non si creano**: le schede ripetute restano dentro la pagina;
- i **caratteri e i file di altri siti non entrano** (per esempio un servizio di font): si vede il carattere del sito;
- caratteri e misure di Histeon CSS restano quelli del sito; per i colori scegli tu (sotto).

Come si fa:

1. premi **Importa una pagina di questo sito**: compare l'elenco delle pagine pubblicate che puoi modificare, con una casella per cercarle. Le pagine già fatte con Histeon e quelle con password non ci sono, e il riquadro dice quante;
2. scegli la pagina e, in **Colori della pagina**, quali colori vuoi (sotto); poi premi **Importa questa pagina**. Il browser la scarica come la vede un visitatore, senza i tuoi cookie, con i fogli di stile, le immagini e i caratteri **di questo sito**: mai da altri siti, mai dall'amministrazione o dall'API. Niente della pagina viene eseguito. Il CSS di Histeon del sito e la barra di amministrazione restano fuori;
3. la pagina nuova prende il primo indirizzo libero dopo quello d'origine (`/chi-siamo-2/`, poi `-3`…);
4. il resoconto dice che cosa entra, che cosa il sito aveva già uguale, che cosa aveva già **diverso** (vincono gli stili del sito, quelli della pagina non entrano) e che cosa resta fuori;
5. **Prosegui al controllo** e conferma: come per ogni pacchetto, nulla si scrive prima.

Si importa una pagina alla volta, e se ne possono importare altre dopo: gli stili che il sito ha già non cambiano. Se qualcuno cambia gli stili del sito fra la lettura e la conferma, l'importazione si ferma con un conflitto: rifai la conversione.

**Gli stili della pagina valgono solo su quella pagina.** Le sue regole, anche quelle scritte su `body` (sfondo, colore del testo), non toccano le altre pagine del sito: i suoi elementi principali portano un segno (`data-histos-pagina="chi-siamo-2"`) e ogni regola della pagina vale solo dove c'è. Un'animazione o un carattere che il sito aveva già con lo stesso nome entra con un nome nuovo, e il resoconto lo dice. Limiti da sapere:

- un elemento **nuovo** che aggiungi nel builder al primo livello della pagina non ha il segno e non prende gli stili della pagina: mettilo dentro una sezione che c'è già;
- testata e piè di pagina del sito, su quella pagina, non prendono più le regole della pagina;
- della pagina d'origine entra solo il **contenuto**: il suo `<main>` (che diventa un `<div>`, perché il sito ha già il suo), con un tema a blocchi senza `<main>` ciò che sta fra la testata e il piè di pagina del tema, con un tema classico `#main`, `#content` o `.site-main`. Testata, menu, piè di pagina, barra laterale e barra di amministrazione del tema restano fuori, con i loro script e fogli; il resoconto dice da dove è stato preso il contenuto. Se il contenuto non si riconosce, la pagina entra intera e un avviso lo dice: testata e piè di pagina copiati toglili nel builder.

**I colori della pagina.** La conversione scrive i due colori del marchio della pagina come variabili di Histeon CSS, scelti solo fra i fogli e gli stili della pagina: quelli del tema, di WordPress (la tavolozza `--wp--preset--*`) e dei plugin non contano. Nel sito una variabile ha il valore del sito. Prima di importare scegli:

- **La pagina tiene i suoi colori** (segnata all'apertura): il sito non cambia e la pagina ha i colori dell'originale;
- **La pagina prende i colori del sito**: il sito non cambia, e i colori del marchio della pagina diventano quelli del sito;
- **I colori del sito diventano quelli della pagina**: **cambia l'aspetto di tutto il sito, solo nei colori**. C'è solo se il sito usa Histeon CSS (il framework di Histeon con il modulo Histeon CSS attivo); altrimenti una riga dice perché manca. Prima di proseguire compare l'elenco delle variabili che cambiano, «variabile: prima → dopo», per il tema chiaro e quello scuro, con un campione di ogni colore: premi **Sì, cambia i colori del sito** per andare avanti, o **Annulla** per tornare alla scelta. Caratteri, misure e il resto del design non cambiano; le pagine cambiano in sviluppo, e online con la release.

Gli altri colori della pagina sono valori scritti nella pagina e restano suoi in ogni scelta. Il resoconto dice quali colori della pagina sono diventati variabili e che cosa è stato tenuto.

Il sito deve avere già un progetto Histeon: se non lo ha, la prima pagina si converte con **Converti** e diventa la home del progetto.

## Panoramica e stato del sito

**Histeon → Panoramica** è la pagina da cui ripartire. Mostra:

- se **tutto è online** o cosa c'è **Da pubblicare**, con **Rivedi e pubblica**;
- la release online e da quando, il numero di pagine, articoli e componenti;
- **Modificati di recente**, con **Apri in Histeon**;
- **Ultime release**. La prima pubblicazione crea la versione 1.0.0.

La scheda **Stato del sito** raccoglie ciò che merita uno sguardo, con un pallino verde (a posto) o corallo (da guardare) e il link per sistemarlo: lo stato dei moduli (acceso, spento, attivo come plugin separato, fermato dopo un errore), la copia statica (pagine statiche e da rigenerare), le importazioni consigliate. Quando non c'è niente da segnalare dice **tutto in ordine**.
