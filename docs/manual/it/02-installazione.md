# Installazione

Questo capitolo ti porta dal pacchetto scaricato a un Histeon pronto all'uso: verifica dei file, installazione, primo avvio, tema, moduli e aggiornamenti.

## I pacchetti

Ogni release di Histeon è fatta di pochi file:

| File | Che cos'è |
|---|---|
| `xb-<versione>-<commit>.zip` | Il plugin Histeon, con i moduli inclusi (Histeon CSS, Lingua Bridge, Static Bridge) |
| `fondamenta-<versione>-<commit>.zip` | Il tema a blocchi Fondamenta (facoltativo, consigliato) |
| `xb-blank-<versione>-<commit>.zip` | Il tema XB Blank, solo per i siti che lo usano già |
| `histos-css-<versione>-<commit>.zip` | Histeon CSS come plugin autonomo, **solo** per i siti che non usano il builder |
| `SHA256SUMS` | Le impronte SHA-256 dei pacchetti |
| `….zip.minisig` | La firma di ciascun pacchetto |

Il nome del plugin nei file è `xb`: è il prefisso tecnico di Histeon, rimasto per compatibilità con i siti esistenti.

> Se usi Histeon, non installare anche il pacchetto `histos-css`: Histeon CSS è già dentro Histeon. Se lo installi comunque, resta fermo e te lo segnala.

## Verificare i pacchetti

Prima di caricare un pacchetto su un sito, conviene controllare che sia proprio quello pubblicato. Bastano due minuti.

### L'impronta SHA-256

Metti i pacchetti e `SHA256SUMS` nella stessa cartella, poi da un terminale:

| Sistema | Comando |
|---|---|
| Linux | `sha256sum -c SHA256SUMS` |
| macOS | `shasum -a 256 -c SHA256SUMS` |
| Windows (PowerShell) | `Get-FileHash .\xb-0.1.0-….zip -Algorithm SHA256` |

Su Linux e macOS ogni file deve risultare `OK`. Su Windows confronta a occhio l'impronta mostrata con quella scritta in `SHA256SUMS`.

### La firma

L'impronta dice che il file non si è rovinato durante il download; la firma dice che l'ha prodotto chi possiede la chiave di rilascio di Histeon. Le firme usano il formato standard di **minisign**, un piccolo programma libero disponibile per tutti i sistemi.

La chiave pubblica di rilascio di Histeon (id `7245FEACC0E94D12`) è:

```
RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

Con il pacchetto e il suo `.minisig` nella stessa cartella:

```bash
minisign -Vm xb-0.1.0-….zip -P RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

Se la firma è valida, minisign risponde `Signature and comment signature verified`. Qualunque altro messaggio vuol dire che il file non va installato.

> È la stessa chiave da scrivere nelle impostazioni degli aggiornamenti (più avanti in questo capitolo): da lì in poi Histeon verificherà da solo ogni aggiornamento.

## Installare e attivare

1. Controlla i requisiti: WordPress 6.9 o successivo, PHP 8.3 o successivo.
2. In WordPress apri **Plugin → Aggiungi nuovo plugin → Carica plugin**, scegli lo ZIP di Histeon e installalo.
3. Attiva il plugin.
4. Se vuoi il tema Fondamenta, caricalo da **Aspetto → Temi → Aggiungi nuovo tema → Carica tema** e attivalo.
5. Segui la schermata **Histeon → Primo avvio** (vedi la prossima sezione).
6. Apri una pagina con **Modifica con Histeon**.

All'attivazione Histeon prepara le sue tabelle per le versioni e dà agli amministratori i permessi per costruire e pubblicare. Nel menu di WordPress compare la voce **Histeon**, con **Panoramica**, **Pagine**, **Articoli**, **Componenti**, **Template**, **Release**, **Versioni**, **Moduli** e **Impostazioni**.

## Il primo avvio

Finché non hai completato o saltato il primo avvio, un avviso in amministrazione ti porta a **Histeon → Primo avvio**. È una pagina sola, con tre scelte.

1. **Il framework CSS.** Sono le variabili e le classi che il builder ti propone. **Histeon CSS** è consigliato e incluso. Core Framework e Automatic.css si possono scegliere se sono installati sul sito; altrimenti compaiono come «non installato».
2. **I moduli.** Lingua Bridge e Static Bridge, da accendere solo se ti servono. Un modulo attivo come plugin separato, o che richiede un PHP più recente, si vede ma non si cambia da qui.
3. **Da dove arrivi.** Se il sito ha contenuti di Etch o usa Automatic.css, la pagina ti porta dove l'importazione si prepara. Da qui non parte nessuna importazione: tutto avviene in sviluppo, con l'anteprima, e il sito continua a funzionare anche senza importare.

Poi scegli:

- **Salva e inizia**: applica il framework e i moduli scelti;
- **Salta per ora**: non cambia nulla.

Tutte le scelte si possono cambiare in qualsiasi momento. Il primo avvio resta raggiungibile da **Histeon → Impostazioni → Primo avvio**.

> Finché non scegli un framework, Histeon usa Histeon CSS. Se un giorno il framework scelto non è più installato, Histeon torna a Histeon CSS invece di lasciare il sito senza stili.

## Il tema Fondamenta

Fondamenta è un tema a blocchi volutamente vuoto:

- nessun preset di colori, caratteri o spaziature;
- nessun controllo visivo di Gutenberg per colori, bordi e spazi, così lo stile resta nelle tue classi;
- template per pagine, articoli, archivi e pagina 404, con intestazione e piè di pagina costruiti con Histeon.

Non è obbligatorio: va bene qualsiasi tema. Per modificare i template del sito dal builder (sezione **Template**) serve però un tema a blocchi. Fondamenta prende il posto di XB Blank: se il sito usa ancora XB Blank, il capitolo sui template spiega il passaggio.

## I moduli

**Histeon → Moduli** mostra una scheda per ogni modulo, con icona, stato, descrizione, versione e requisiti, l'interruttore e il collegamento alle impostazioni.

| Stato | Cosa significa |
|---|---|
| **Attivo** | Il modulo è acceso e funziona |
| **Spento** | Il modulo è incluso ma non si carica |
| **Attivo come plugin separato** | Lo stesso software è attivo dalla pagina Plugin: il modulo resta fermo e il plugin si gestisce da lì |
| **Richiede PHP …** | La versione di PHP del server non basta |
| **Spento dopo un errore fatale** | Il modulo ha causato un errore ed è stato fermato (vedi sotto) |
| **Spento da wp-config.php** | Una costante in `wp-config.php` lo tiene spento |

Per accendere o spegnere un modulo usa il pulsante **Accendi …** o **Spegni …** sulla sua scheda. Spegnere un modulo **non cancella mai i suoi dati**: lingue, traduzioni, copie statiche e impostazioni restano dove sono.

Quando un modulo acceso ha delle pagine proprie, i suoi menu compaiono in fondo al menu **Histeon**.

Note sui singoli moduli:

- **Histeon CSS** è acceso di serie. Se lo spegni, restano solo i token di base `--xb-*` (i componenti li usano), mentre classi, palette e pannello in popup spariscono.
- **Static Bridge** richiede PHP 8.4. Prima di accendersi controlla PHP, WordPress, memoria e accesso ai file: se qualcosa manca, non cambia nulla e i motivi compaiono nella schermata.
- **Lingua Bridge** e **Static Bridge**, se installati anche come plugin separati, non si possono attivare dalla pagina Plugin mentre il modulo è acceso: la pagina mostra «Attivo come modulo di Histeon».

### Se un modulo va in errore

Se un modulo provoca un errore fatale, Histeon lo spegne da solo: la richiesta successiva parte senza quel modulo e il resto del sito funziona. Gli amministratori vedono un avviso con il modulo, il file e la riga dell'errore, e il pulsante **Apri Histeon → Moduli**. Dopo aver corretto o aggiornato il modulo, riaccendilo.

Se l'amministrazione non si apre affatto, puoi spegnere i moduli da `wp-config.php`:

```php
define( 'XB_SAFE_MODE', true );                               // nessun modulo
define( 'XB_DISABLE_MODULES', 'lingua-bridge,static-bridge' ); // solo questi
```

Togliendo la costante, il modulo torna allo stato che avevi scelto.

## La Panoramica

**Histeon → Panoramica** è il punto di partenza dopo l'installazione: mostra cosa è online, cosa aspetta di andarci e da dove ripartire. La scheda **Stato del sito** raccoglie ciò che merita uno sguardo, con un pallino verde (a posto) o corallo (da guardare) e il collegamento a dove si sistema: moduli, copia statica, importazioni consigliate.

## Aggiornamenti

Histeon si aggiorna dalle release pubbliche su GitHub, come ogni plugin dall'amministrazione di WordPress: nessuna licenza, nessuna telemetria. Il sito chiede solo qual è l'ultima release e ne scarica pacchetto e firma, senza mandare contenuti, utenti o impostazioni.

Gli aggiornamenti automatici sono **spenti di serie**. Per accenderli:

1. Apri **Histeon → Impostazioni → Aggiornamenti**.
2. In **Repository** scrivi il repository GitHub delle release, nella forma `proprietario/nome`.
3. In **Chiave pubblica minisign** incolla la chiave di rilascio di Histeon (quella della sezione «Verificare i pacchetti»).
4. Salva. Con **Cerca gli aggiornamenti su GitHub** puoi controllare subito; la schermata mostra l'ultima release firmata trovata e la versione installata.

In alternativa, puoi impostare gli stessi valori in `wp-config.php`, dove vincono sulle impostazioni:

```php
define( 'XB_UPDATE_REPOSITORY', 'proprietario/nome' );
define( 'XB_UPDATE_PUBLIC_KEY', 'RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W' );
```

Come funziona:

- Histeon controlla le release al massimo ogni 12 ore. Conta solo una release pubblicata (non bozza né pre-release) che contiene il pacchetto e la sua firma.
- Se la versione è più nuova di quella installata, WordPress la mostra tra gli aggiornamenti come qualsiasi plugin.
- Prima di installare, Histeon scarica il pacchetto e la firma e li verifica con la chiave configurata. Se qualcosa non torna, l'aggiornamento si ferma con il motivo e il file scaricato viene cancellato.
- I moduli inclusi (Lingua Bridge, Static Bridge) si aggiornano insieme a Histeon, con la release che li contiene. Un plugin separato continua invece con i suoi aggiornamenti.

> Gli aggiornamenti funzionano solo se le release del repository sono pubbliche: un repository privato non risponde alle richieste anonime che Histeon usa.
