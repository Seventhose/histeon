# Moduli e aggiornamenti

Histeon è un solo plugin, ma alcune sue parti si possono accendere e spegnere: sono i **moduli**. Questo capitolo spiega come gestirli, cosa succede se uno va in errore, e come Histeon si aggiorna in modo sicuro, senza licenze.

## Cos'è un modulo

Un modulo è una parte di Histeon che fa un lavoro preciso e che non tutti i siti usano. Oggi i moduli sono tre:

| Modulo | Cosa fa | Di serie |
|---|---|---|
| **Histeon CSS** | Il framework CSS libero di Histeon: token fluidi, palette, classi su richiesta, ricette, il pannello in popup e l'importazione da Automatic.css | Acceso |
| **Static Bridge** | Il sito come copia statica: esportazione, pubblicazione su Cloudflare, form e lingue | Spento |
| **Lingua Bridge** | Traduzioni come pagine WordPress normali: editor sul sito, memoria, aggiornamenti delle frasi cambiate, IA | Spento |

Static Bridge e Lingua Bridge esistono anche come **plugin separati**, con le loro release. Dentro Histeon sono lo stesso codice, nella versione inclusa nella release di Histeon.

## Accendere e spegnere un modulo

1. Apri **Histeon → Moduli** (serve essere amministratori).
2. Ogni modulo ha una scheda con icona, stato, descrizione, versione e requisiti.
3. Usa l'interruttore per accenderlo o spegnerlo. Il link **Impostazioni** porta alle sue impostazioni.

> **Spegnere un modulo non cancella mai i suoi dati.** Lingue, traduzioni, memoria, copie statiche e impostazioni restano: riaccendendo il modulo li ritrovi com'erano.

Cosa succede quando accendi un modulo:

- **Lingua Bridge** fa quello che farebbe l'attivazione del plugin: prepara le sue tabelle e le regole degli indirizzi. Il suo menu compare sotto **Histeon**.
- **Static Bridge** prima controlla che il server vada bene (versione di PHP, WordPress, memoria). Se qualcosa non va, non cambia nulla e i motivi compaiono nella schermata. Anche il suo menu compare sotto **Histeon**.
- **Histeon CSS**, spento, lascia solo i token di base che i componenti usano; spariscono classi, pannello e palette in Gutenberg.

Gli stati che puoi vedere:

| Stato | Cosa significa |
|---|---|
| **Attivo** | Il modulo è acceso e funziona |
| **Spento** | Il modulo è incluso ma spento |
| **Attivo come plugin separato** | Lo stesso software è attivo come plugin: il modulo resta fermo |
| **Richiede PHP …** | Il server ha una versione di PHP troppo vecchia (Static Bridge richiede PHP 8.4) |
| **In arrivo come modulo** | Non ancora incluso: oggi si usa come plugin separato |
| **Spento dopo un errore fatale** | Histeon l'ha fermato perché andava in errore (sotto) |
| **Spento da wp-config.php** | Chi gestisce il server l'ha spento da `wp-config.php` |

## Modulo o plugin separato

Lo stesso codice non può girare due volte: fermerebbe il sito. Per questo:

- se Lingua Bridge o Static Bridge sono attivi come plugin, il modulo non si carica e non si può accendere. Il messaggio dice «È attivo come plugin separato: disattiva quello per usare il modulo»;
- con il modulo acceso, nella pagina **Plugin** il plugin separato mostra «Attivo come modulo di Histeon» al posto di **Attiva**.

Per passare dal plugin al modulo: disattiva il plugin dalla pagina **Plugin**, poi accendi il modulo in **Histeon → Moduli**. I dati sono gli stessi (stesse opzioni, stesse tabelle), quindi non c'è nulla da migrare.

Un modulo si aggiorna insieme alla release di Histeon che lo contiene. Il plugin separato continua invece con i suoi aggiornamenti.

## Quando un modulo va in errore

Se un modulo causa un errore fatale, **Histeon lo ferma**, e il resto del sito continua a funzionare.

1. La richiesta in cui avviene l'errore si interrompe, come succederebbe con qualsiasi plugin.
2. Dalla richiesta successiva il sito riparte **senza quel modulo**.
3. In ogni schermata di amministrazione compare l'avviso **Histeon ha spento un modulo dopo un errore fatale**, con il nome del modulo, il file, la riga e il messaggio dell'errore, e il pulsante **Apri Histeon → Moduli**.
4. Nella schermata Moduli lo stato diventa **Spento dopo un errore fatale**.

Cosa fare: aggiorna Histeon (o correggi la causa, se la conosci), poi riaccendi il modulo. Riaccenderlo cancella l'errore registrato.

> Solo gli errori nati dentro un modulo lo spengono. Un errore di Histeon stesso, o di un altro plugin, non spegne nessun modulo.

## La modalità sicura

Se l'amministrazione non si apre più, chi ha accesso ai file del sito può spegnere i moduli da `wp-config.php`:

```php
define( 'XB_SAFE_MODE', true );                               // nessun modulo
define( 'XB_DISABLE_MODULES', 'lingua-bridge,static-bridge' ); // solo questi
```

Un modulo spento così non si può riaccendere dalla schermata, che mostra «Spento da wp-config.php». Togliendo la riga il modulo torna allo stato che avevi scelto.

## Gli aggiornamenti di Histeon

Histeon si aggiorna dalle release pubblicate su GitHub, come qualsiasi plugin dall'amministrazione di WordPress: **nessuna licenza, nessuna telemetria**. Il sito chiede solo qual è l'ultima release e ne scarica pacchetto e firma, senza mandare contenuti, utenti o impostazioni.

Gli aggiornamenti sono **spenti di serie**. Per accenderli:

1. Apri **Histeon → Impostazioni**, sezione **Aggiornamenti**.
2. Spunta **Cerca gli aggiornamenti su GitHub**.
3. In **Repository** scrivi il repository delle release, nella forma `proprietario/nome`.
4. In **Chiave pubblica minisign** incolla la chiave pubblica di rilascio (sotto).
5. Premi **Salva**.

Chi preferisce può impostarli in `wp-config.php` con `XB_UPDATE_REPOSITORY` e `XB_UPDATE_PUBLIC_KEY`: in quel caso valgono quelli e la schermata lo dice.

Histeon controlla al massimo ogni 12 ore. Quando trova una versione più nuova e firmata, WordPress la propone come un normale aggiornamento di plugin. Sotto le impostazioni vedi l'ultima release firmata trovata e la versione installata.

> Gli aggiornamenti funzionano solo con un repository pubblico: un repository privato non risponde alle richieste anonime.

## La copia prima di un aggiornamento

Prima che WordPress sostituisca Histeon con una versione nuova (dalla pagina dei plugin, con gli aggiornamenti automatici, con WP-CLI o caricando uno ZIP), Histeon fa da solo una copia della sua cartella e delle sue tabelle (versioni, release, registro, deploy). Se la copia non riesce, l'aggiornamento si ferma e dice perché.

In **Histeon → Impostazioni → Copie di sicurezza di Histeon** vedi le ultime due copie, puoi farne una adesso e, con una conferma, **tornare alla versione di prima**. La copia non comprende i contenuti del sito (pagine, articoli, media, menu), le impostazioni di Histeon, i dati dei moduli, gli altri plugin e il tema: per quelli serve il backup del sito. Su nginx aggiungi `location ~ /xb-backups/ { deny all; }` alla configurazione.

## Perché le release sono firmate

Ogni pacchetto di Histeon è **firmato** con una chiave che resta a chi pubblica le release, fuori dal repository e dai pacchetti. Nei siti va solo la chiave **pubblica**, che serve a verificare.

Prima di installare un aggiornamento, Histeon scarica il pacchetto e la sua firma e controlla due cose:

- che la firma corrisponda alla chiave pubblica che hai configurato;
- che la firma nomini proprio quel pacchetto.

Se qualcosa non torna, l'aggiornamento si ferma, il motivo compare e il file scaricato viene cancellato. Così, anche se qualcuno riuscisse a mettere un pacchetto falso tra le release, il tuo sito non lo installerebbe.

La chiave pubblica di rilascio di Histeon, dalla versione 0.1.0, è:

```
RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

## Verificare una release a mano

Se installi Histeon caricando lo ZIP a mano, puoi verificarlo tu con [minisign](https://jedisct1.github.io/minisign/), un piccolo programma libero.

1. Scarica dalla release sia lo ZIP (`xb-X.Y.Z-….zip`) sia la sua firma (lo stesso nome con `.minisig` in fondo), nella stessa cartella.
2. Apri un terminale in quella cartella ed esegui, con il nome vero del file:

```bash
minisign -Vm xb-X.Y.Z-sha.zip -P RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

3. Se il pacchetto è integro e firmato con la chiave di Histeon, minisign risponde che la firma e il commento fidato sono validi, e mostra il commento fidato: deve nominare lo stesso file.
4. Se minisign segnala un errore, **non installare** quel pacchetto.

> Confronta sempre la chiave pubblica con quella scritta in questo manuale o nella documentazione ufficiale, non con una chiave trovata accanto al file scaricato.
