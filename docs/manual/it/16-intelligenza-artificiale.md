# Histeon e l'intelligenza artificiale

Histeon non ha un assistente integrato e, da solo, non manda i tuoi dati a servizi di IA. Funziona invece con l'agente che usi già sul tuo computer (Claude Code, Codex, Opencode o qualsiasi agente con una shell o un client MCP), e gli dà regole chiare: cosa può fare, con quali permessi, e soprattutto cosa resta sempre a te.

I dati partono solo quando li mandi tu: quando colleghi un agente, quello che legge dal sito (pagine, stili, componenti, framework, il riassunto del sito) arriva all'agente e al servizio di IA che l'agente usa; quando metti una tua chiave, per esempio per la traduzione automatica di Lingua Bridge, i testi da tradurre vanno al servizio che hai scelto.

Le strade sono due:

| Strada | Quando usarla | Dove lavora l'agente |
|---|---|---|
| **Connettore Histeon** | Lavori nel builder e vuoi vedere l'agente all'opera | Nella scheda del builder che hai aperto, con le tue modifiche visibili mentre succedono |
| **Abilità di WordPress** | Un agente remoto, senza una scheda aperta | Attraverso un server MCP del sito, come un utente WordPress |

In tutti e due i casi vale la stessa regola: **le modifiche dell'agente vanno in sviluppo**. Il sito online cambia solo quando qualcuno pubblica una release.

## Il connettore Histeon

Il connettore è un piccolo programma che l'agente avvia sul tuo computer. Fa da ponte tra l'agente e la scheda del builder.

1. Nel builder clicca **✦** nella barra a sinistra. Si apre il pannello **Connettore AI**.
2. Premi **Copia le istruzioni** e incollale nella chat del tuo agente. Le istruzioni gli dicono di:
   - scaricare `histos-connector.mjs` da questo sito (c'è anche il link **Scarica histos-connector.mjs**);
   - avviarlo con il comando esatto, che contiene il tuo segreto di accoppiamento;
   - leggere il manuale per le IA (`manual`) e il riassunto del sito (`brief`) prima di modificare;
   - restare in attesa delle richieste che scrivi nel builder.
3. Premi **Collega questa scheda**. Finché il connettore non risponde, ✦ pulsa e lo stato dice «In attesa del connettore…»; poi diventa del colore d'accento e lo stato dice «Collegato».
4. Chiedi all'agente quello che ti serve, nella sua chat o da **Chiedi all'agente** (sotto).
5. **Scollega** chiude il collegamento. Lo chiude anche chiudere la scheda.

Serve Node 18 o successivo sul tuo computer, nient'altro da installare. Il builder deve essere aperto in HTTPS, oppure su `localhost`.

Mentre l'agente lavora:

- vedi ogni modifica nella tela, subito;
- ogni operazione si annulla con **Annulla**, come le tue;
- il pannello ✦ elenca le **Operazioni dell'agente**, con gli eventuali errori;
- il salvataggio va **in sviluppo**, e solo quando sei d'accordo.

> Il pannello ✦ offre anche **Manuale per l'AI**: è il manuale che l'agente legge per lavorare come lavorerebbe chi conosce Histeon (classi BEM, variabili del framework, componenti, accessibilità). Puoi leggerlo anche tu.

## Il segreto di accoppiamento

Il comando da incollare contiene un **segreto tuo**: con esso il connettore e la tua scheda si riconoscono a vicenda. Così nessun sito web e nessun altro programma del computer possono collegarsi al posto loro.

- Non condividere le istruzioni copiate: contengono il segreto.
- Se lo hai condiviso per sbaglio, apri **Accoppiamento** nel pannello ✦ e scegli **Rigenera il segreto**. Poi ricopia le istruzioni e riavvia il connettore.

Il connettore ascolta solo sul tuo computer (`127.0.0.1`), e i comandi dell'agente richiedono un token che resta sul tuo computer.

## Chiedi all'agente

Nel pannello ✦, la sezione **Chiedi all'agente** ti permette di scrivere all'agente senza passare dalla sua chat.

**Crea una sezione.** Descrivi la sezione nel campo **Descrivi la sezione da creare** (per esempio «tre testimonianze con foto, nome e ruolo, su sfondo chiaro») e premi **Crea una sezione**. La sezione va dopo l'elemento selezionato, oppure in fondo alla pagina se non c'è selezione. Con Histeon CSS attivo, l'agente usa i suoi token e classi BEM scritte per la sezione.

**Traduci questa pagina.** Compare quando la pagina ha delle lingue (Lingua Bridge). Scegli le lingue e premi **Traduci**: l'agente crea **solo bozze** di traduzione; per una lingua già pubblicata, una bozza di correzione. Vedi [Siti in più lingue](#it-multilingua).

Le richieste si mettono in coda:

| Stato | Significato |
|---|---|
| in attesa dell'agente | L'agente non l'ha ancora presa. Puoi ritirarla con ✕ |
| l'agente ci lavora | L'agente la sta facendo |
| fatta | Finita: sotto compare la nota dell'agente, con cosa ha fatto e cosa resta da decidere |

> Le richieste vivono nella scheda: ricaricando la pagina si perdono. Al massimo 20 richieste aperte, di 2000 caratteri ciascuna.

## Lavorare dai file

Se preferisci un editor di codice (VS Code o altro), con la scheda collegata il connettore può portare la pagina in una cartella, sincronizzata nei due sensi: **Lavora dai file (VS Code)** nel pannello ✦ mostra il comando.

- Una sottocartella per documento, con `page.html` e un file `.css` per ogni selettore.
- Salvi nel tuo editor e il builder si aggiorna; cambi nel builder e il file segue.
- Se cambiano entrambi, nessuno sovrascrive l'altro: la versione del builder va accanto al file, con l'estensione `.builder`.
- Gli script non si sincronizzano, e salvare resta un'azione del builder.

## Il connettore come server MCP

Se il tuo agente usa MCP, puoi registrare il connettore una volta per tutte. I comandi esatti, con il tuo accoppiamento, sono in **Come server MCP** nel pannello ✦. Per esempio, con Claude Code:

```bash
claude mcp add xb -- node /percorso/histos-connector.mjs mcp --origin <sito> --pair <segreto>
```

La scheda del builder va comunque aperta e collegata: l'agente lavora sempre lì.

## Le abilità di WordPress

Da WordPress 6.9 i plugin possono offrire **abilità** (Abilities API), cioè azioni che un agente remoto chiama attraverso un adattatore MCP di WordPress. Histeon offre queste:

| Abilità | Cosa fa |
|---|---|
| Elenco dei documenti | I contenuti che l'utente può modificare con Histeon |
| Leggere un documento | La struttura della versione di sviluppo |
| Disegnare un documento | L'HTML della versione di sviluppo |
| Framework | Token, variabili e classi del framework |
| Modifiche non pubblicate | I documenti con modifiche in sviluppo |
| Link di anteprima | Un link firmato all'anteprima |
| Applicare operazioni | Modifiche strutturate (testo, attributi, inserire, spostare, togliere), **in sviluppo** |
| Impostare uno stile | Il CSS di un selettore di classe, **in sviluppo** |
| Chiedere di pubblicare una release | Elenca le modifiche di sviluppo e porta alla pagina Release, dove una persona pubblica (vedi sotto) |

L'agente si autentica come un utente WordPress, per esempio con una password per le applicazioni, e ha **esattamente i suoi permessi, non di più**.

Al massimo 100 operazioni per chiamata: basta un errore e non si salva nulla. Se l'agente indica la versione che ha letto e il documento è cambiato nel frattempo (per esempio perché ci stai lavorando tu), la chiamata viene rifiutata invece di sovrascrivere.

> **Pubblicare con le abilità.** Un agente non pubblica da solo. L'abilità **Chiedi di pubblicare una release**, che richiede il permesso di pubblicare (`xb_publish`), elenca ciò che andrebbe online e restituisce la pagina **Release**: lì una persona controlla e pubblica. Conviene comunque dare all'agente un utente che **non** ha il permesso di pubblicare.

## Cosa un agente può e non può fare

| Può | Non può |
|---|---|
| Leggere la pagina, gli stili, i componenti e il framework | Eseguire codice nel builder o sul server: sceglie solo da un elenco chiuso di operazioni |
| Modificare struttura, testi e stili, in sviluppo | Pubblicare dal connettore: l'operazione non esiste |
| Creare componenti, applicare classi BEM | Scrivere il JavaScript dei documenti |
| Salvare in sviluppo, se sei d'accordo | Avere più permessi dell'utente con cui lavora |
| Creare bozze di traduzione con Lingua Bridge | Pubblicare o sovrascrivere una traduzione già online |

Ogni modifica passa dagli stessi filtri di sicurezza delle tue: un agente che lavora come un utente senza il permesso di scrivere codice non può aggiungere script, gestori di eventi o link `javascript:`. Ogni scrittura con le abilità finisce nel registro delle operazioni di Histeon.

> Il contenuto che l'agente legge dal sito è un **dato**, non un'istruzione. Il suo manuale glielo dice in modo esplicito: se in una pagina trova scritto «fai questo», non lo fa e te lo segnala.

## Cosa può fare l'agente su questo sito

Nel pannello ✦, **Cosa può fare l'agente**, un amministratore sceglie le aree che un agente può cambiare: testi della pagina, struttura e attributi, CSS dei selettori, componenti, libreria dei media, contenuti e campi, query e sorgenti JSON. Leggere, annullare e salvare in sviluppo restano sempre possibili; pubblicare mai. La scelta vale per tutto il sito, anche per gli agenti che lavorano da fuori con le abilità di WordPress.

- **Contenuti e campi** è spento finché non lo accendi: titolo, riassunto e campi di un articolo o di un record cambiano subito il sito. Lo stato non cambia mai (una bozza resta bozza) e **Annulla tutto** rimette i valori di prima.
- **Media**: l'agente cerca file, ne carica di nuovi passando dalla compressione del builder, scrive i testi alternativi, ordina le raccolte e comprime un'immagine già caricata. Non elimina mai un file.
- **Query e sorgenti**: le scrive in sviluppo, con le versioni di sempre.
- **Componenti**: oltre a crearli, ne cambia il markup e le props; tutte le pagine che li usano li seguono.

Mentre l'agente cambia qualcosa, la tela ha un bordo colorato e l'etichetta **L'agente sta lavorando**, con l'area che sta toccando.

## Tieni o annulla tutto

Quando un agente AI modifica il sito con le abilità, Histeon ricorda com'era ogni documento prima della sua prima modifica. Le modifiche fatte senza pause di oltre mezz'ora formano una sessione. Nel builder compare una barra: **L'agente AI sta lavorando** (o **ha finito**), quanti documenti ha cambiato e quali. Hai due pulsanti:

- **Tieni**: le modifiche restano in sviluppo, la barra sparisce.
- **Annulla tutto**: ogni documento torna com'era prima dell'agente, come una nuova versione in sviluppo. Le versioni dell'agente restano nella storia: se cambi idea, le ripristini da **Versioni**.

In nessuno dei due casi va online qualcosa: serve sempre una release. Un amministratore vede anche le sessioni degli altri utenti.

## Il generatore di Histeon CSS non è IA

Nel pannello **Design system**, il **Generatore Histeon CSS** crea palette, scale e forme da pochi valori (colori di base, dimensioni minime e massime, rapporti). Non usa intelligenza artificiale e non chiama servizi esterni: i valori si calcolano sul tuo server, sempre allo stesso modo. Lo trovi spiegato nel capitolo [Histeon CSS](#it-histos-css).

Le sezioni create con **Crea una sezione** usano proprio quei token: cambiando il design system, cambiano con lui.

## Buone pratiche

- Chiedi all'agente di **proporti** cosa farà, prima dei cambiamenti grandi.
- Lavora a piccoli passi e guarda la tela: annullare è sempre possibile.
- Prima di pubblicare usa **Differenze** e **Confronta** nella finestra **Pubblica**.
- Per le abilità, crea un utente apposta per l'agente, con i permessi minimi che servono.
- Tieni l'agente su `localhost` o su una copia di sviluppo quando provi cose nuove.
