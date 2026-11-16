# Sito statico

Un sito statico è fatto di semplici file HTML: niente PHP né database a ogni visita. È più veloce, regge meglio i picchi di traffico e offre meno punti d'attacco. Con Histeon continui a lavorare in WordPress come sempre, e chi visita riceve le pagine già pronte.

Ci sono tre strade, che puoi anche combinare:

| Strada | Dove vivono le pagine | Quando sceglierla |
|---|---|---|
| **Zip statico** | Dove vuoi: lo scarichi e lo carichi tu | Per un archivio, una consegna, un hosting di soli file |
| **Static Bridge** | Sullo stesso server di WordPress | Il sito resta sul suo hosting, ma i visitatori ricevono HTML statico |
| **Cloudflare** | Su Cloudflare Workers, nel tuo account | Il sito pubblico vive su Cloudflare, con versioni e ritorno a una versione precedente |

## Cosa finisce nella copia statica

Ogni pagina viene generata come la vede un **visitatore anonimo**: niente barra di amministrazione, niente dati dell'utente collegato, niente modifiche in sviluppo. Entrano:

- la home, i contenuti pubblici pubblicati (senza password), gli archivi dei tipi di contenuto e delle tassonomie pubbliche;
- le risorse che le pagine usano (CSS, script, immagini, font), copiate direttamente dal disco del server;
- una pagina `404.html`.

I link del sito diventano relativi, così la copia funziona su qualsiasi dominio.

### Il controllo di sicurezza

Prima di creare uno zip o di pubblicare su Cloudflare, Histeon controlla tutte le pagine generate. Si ferma se trova qualcosa che non deve mai uscire dal sito:

- percorsi del server;
- link di anteprima di sviluppo;
- annotazioni interne del builder;
- codici di sessione di WordPress (i "nonce"), che scadono e romperebbero la pagina.

Segnala invece con un avviso, senza fermarsi, i link all'amministrazione o all'API di WordPress e l'email dell'amministratore.

> Histeon blocca la copia statica se una pagina contiene un codice di sessione, che non può finire in file uguali per tutti i visitatori. Histeon non fa la copia statica di un negozio FluentCart: il negozio resta dinamico, oppure va servito con Static Bridge escludendo le pagine del negozio.

## Lo zip statico

1. Apri **Histeon → Release**, sezione **Sito statico**.
2. Premi **Scarica il sito statico (zip)**.
3. Histeon genera ogni pagina, la controlla e, se è tutto a posto, scarica lo zip.

Su siti grandi può richiedere qualche minuto. Se il controllo trova problemi bloccanti, lo zip non viene creato e un messaggio lo spiega. Lo zip contiene solo le pagine e le loro risorse: niente PHP, niente file nascosti. Sul server non resta copia: i file temporanei vengono cancellati dopo il download.

> Solo gli amministratori possono scaricare il sito statico.

## Static Bridge

**Static Bridge** è un plugin open source (GPL) che serve le pagine come HTML statico **dallo stesso server** di WordPress. L'amministrazione, il login, le richieste con parametri e i percorsi che dichiari dinamici continuano ad andare a WordPress.

È incluso in Histeon come **modulo**: lo accendi in **Histeon → Moduli**. Richiede PHP 8.4. Se lo hai già installato come plugin separato, Histeon lo riconosce e usa quello, con gli stessi dati. Una volta acceso, le sue pagine (**Genera**, **Impostazioni**, **Diagnostica**, **Attività**…) compaiono in fondo al menu **Histeon**.

### Primo avvio

1. Accendi il modulo e apri le impostazioni di Static Bridge: conferma il dominio e controlla i percorsi dinamici.
2. Configura il server web seguendo la documentazione di Static Bridge: con Nginx serve un intervento manuale.
3. Esegui la **Diagnostica** e risolvi gli errori.
4. Da **Genera** avvia la prima generazione completa.
5. Ripeti la Diagnostica e prova alcune pagine pubbliche, il login e l'amministrazione.

> Fai il primo collaudo su un sito di prova con un backup, con il ritorno a WordPress attivo e la modalità "strict" spenta, come consiglia la documentazione di Static Bridge.

### Cosa fa Histeon per Static Bridge

A ogni release, Histeon dice a Static Bridge quali pagine rigenerare:

- un **componente** modificato aggiorna solo le pagine che lo usano;
- **stili** e **impostazioni** aggiornano tutto il sito;
- un **articolo** modificato aggiorna anche le pagine con un loop, i dati `data.` o i filtri dei loop che potrebbero mostrarlo.

Dopo una pubblicazione nel builder, il messaggio dice che Static Bridge aggiorna a breve la copia statica delle pagine coinvolte. In **Histeon → Release**, la scheda **Static Bridge** mostra se la copia è servita, quante pagine statiche ci sono, se si aggiorna da sola a ogni release, le pagine in coda e l'ultima attività, con **Apri Static Bridge**. Lo stesso stato compare in **Histeon → Panoramica**, nella scheda **Stato del sito**.

## Pubblicare su Cloudflare

Histeon pubblica il sito statico completo (pagine e risorse) su **Cloudflare Workers** nel tuo account. Ogni pubblicazione è una versione: puoi tornare a una precedente senza rigenerare niente.

### Configurazione

1. In Cloudflare crea un **token API** con il solo permesso *Account › Workers Scripts › Modifica*.
2. In **Histeon → Impostazioni**, sezione **Sito statico** → **Pubblicazione su Cloudflare**, inserisci **Account ID**, **Nome del Worker** e **Token API**.
3. Se vuoi, spunta **Automatico**: **Pubblica su Cloudflare a ogni release, in background**.
4. Premi **Salva impostazioni Cloudflare**.

> Meglio ancora: chiedi a chi gestisce il server di scrivere il token in `wp-config.php` (costante `XB_CLOUDFLARE_API_TOKEN`), così non sta nel database. Se lo salvi nelle impostazioni viene cifrato e non viene mai più mostrato.

### Pubblicare e tornare indietro

Nel builder, la sezione **Versioni** ha una parte **Cloudflare**:

- **Pubblica ora su Cloudflare** genera il sito statico di ciò che è pubblicato e lo carica. Vengono inviati solo i file cambiati.
- L'elenco mostra le ultime pubblicazioni, con il loro stato (**online**, **bloccato** se il controllo di sicurezza ha trovato problemi).
- **Rimetti online** riporta subito una versione precedente.

Con l'opzione automatica, ogni release di Histeon parte da sola verso Cloudflare, in background, senza rallentare la pubblicazione. Pubblicare e tornare indietro richiedono il permesso di pubblicare.

Cloudflare limita il numero di file per versione e la dimensione di ogni file (20.000 file e 25 MB quando questo manuale è stato scritto): i limiti aggiornati, anche dei piani a pagamento, sono nella documentazione di Cloudflare.

## Cosa resta dinamico

Un sito statico non può rispondere a ciascun visitatore in modo diverso. Ecco cosa succede alle parti dinamiche.

| Funzione | Static Bridge | Zip statico | Cloudflare |
|---|---|---|---|
| **Filtri dei loop** | La pagina senza filtri è statica; scegliendo un filtro l'indirizzo cambia e la richiesta va a WordPress | Resta solo la prima pagina dei risultati | Resta solo la prima pagina dei risultati |
| **Form di Histeon** (`/xb-form`) | — | — | Funzionano: li riceve il Worker e li inoltra a WordPress |
| **Form di altri plugin** | Vanno a WordPress; Static Bridge sa gestire Fluent Forms | Non gestiti da Histeon: da verificare caso per caso | Non gestiti da Histeon: da verificare caso per caso |
| **Carrello e cassa** | Restano dinamici nel negozio | I link «aggiungi al carrello» portano al sito | I link «aggiungi al carrello» portano al sito |
| **Utenti collegati** | Ricevono WordPress | — | — |

Altre cose da sapere:

- Le pagine che leggono parametri dell'indirizzo (`url.query`) o i filtri dei loop dipendono dalla richiesta: con Static Bridge vanno sempre a WordPress quando l'indirizzo ha parametri.
- I dati che cambiano per conto loro (per esempio un servizio esterno letto con `data.`) arrivano nella copia statica alla rigenerazione successiva.
- Le anteprime di sviluppo non finiscono mai nella copia statica.

### I form sul sito Cloudflare

Il sito pubblicato su Cloudflare ha un solo indirizzo dinamico, `/xb-form`. Il Worker verifica il controllo anti-bot **Turnstile**, inoltra i campi a WordPress con una firma, e il messaggio compare in **Messaggi dai form** in wp-admin, con una notifica email all'amministratore.

Per attivarlo, in **Histeon → Impostazioni**, sezione **Form del sito statico (Turnstile)**, inserisci la chiave segreta di Turnstile e premi **Invia al Worker**: la chiave va a Cloudflare e non resta sul sito. Come costruire il form è spiegato nel capitolo sulle interazioni e il codice.

### Static Bridge e Cloudflare insieme

Le due pubblicazioni non si escludono: entrambe reagiscono alla stessa release di Histeon. Static Bridge tiene veloce il sito sul suo hosting; Cloudflare pubblica una copia autosufficiente, per esempio come sito principale o come riserva.
