# Aggiornamenti senza licenze

Implementazione: `plugin/src/Updates/Updater.php`, `plugin/src/Updates/Minisign.php`, `scripts/sign-release.php`. Test: `plugin/tests/Unit/MinisignTest.php`, `plugin/tests/Integration/UpdaterTest.php`.

Histeon si aggiorna dalle release su GitHub come ogni plugin dall'amministrazione di WordPress: nessuna licenza, nessuna telemetria; il controllo delle release non manda dati del sito.

## Come funziona
- **Spento di serie.** Si accende in **Impostazioni → Aggiornamenti** con il repository (`proprietario/nome`) e la chiave pubblica di rilascio, oppure in `wp-config.php` con `XB_UPDATE_REPOSITORY` e `XB_UPDATE_PUBLIC_KEY`, che vincono.
- **Chi la sceglie.** Repository e chiave decidono quale codice riceve l'intera installazione: li salva solo chi ha `update_plugins` (su multisito `manage_network_plugins`, l'amministratore del network), e restano a livello di network (`get_site_option`), non del singolo sito.
- **Controllo.** Una richiesta anonima all'API pubblica di GitHub (`/releases/latest`) al massimo ogni 12 ore. Una release conta solo se non è bozza né pre-release, ha il tag `vX.Y.Z` e contiene `xb-X.Y.Z[-sha].zip` con accanto `xb-X.Y.Z[-sha].zip.minisig`, entrambi scaricati da `https://github.com/`.
- **Offerta.** Se la versione è più nuova di quella installata, WordPress la mostra come un aggiornamento di Histeon.
- **Verifica prima di installare** (`upgrader_pre_download`). Histeon scarica lo ZIP (massimo 60 MB) e la firma (massimo 4 KB), poi controlla:
  - la firma minisign con la chiave configurata (Ed25519 su BLAKE2b-512 del file, più la firma del commento fidato), con l'id della chiave;
  - il nome del file nel commento fidato (`file:`) è esattamente quello dell'asset della release, nella forma `xb-X.Y.Z[-sha].zip`: un'altra build firmata della stessa versione (senza moduli, di un altro commit) non passa per questa;
  - che il commento fidato nomini proprio quel pacchetto (`file:xb-X.Y.Z…`).

  Se qualcosa non torna, l'aggiornamento si ferma con il motivo e il file scaricato viene cancellato. Un aggiornamento di Histeon il cui pacchetto non è più l'ultima release firmata (la release è cambiata, la firma è sparita, l'API non risponde, gli aggiornamenti sono stati spenti dopo l'offerta) viene rifiutato: non è mai installato senza verifica. Gli altri pacchetti restano a WordPress.

## Firmare una release
Formato minisign standard: le firme si verificano anche con `minisign -Vm xb-X.Y.Z-sha.zip -P <chiave pubblica>`.

```bash
php scripts/sign-release.php keygen /percorso/sicuro/release.key   # una volta: stampa la chiave pubblica
XB_SIGNING_KEY=/percorso/sicuro/release.key scripts/package.sh   # pacchetti firmati in dist/
```

La chiave segreta (file 0600) resta fuori dal repository e dai pacchetti, idealmente offline. Nei siti va solo la chiave pubblica.

**Chiave di rilascio di Histeon** (id `7245FEACC0E94D12`), dalla 0.1.0:

```
RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

**Limite.** Un repository privato non risponde all'API anonima: gli aggiornamenti funzionano quando le release sono pubbliche.

## Copia di sicurezza prima di aggiornare

Codice: `plugin/src/Updates/Backup.php`, `plugin/src/Admin/BackupPanel.php`; test: `plugin/tests/Integration/UpdateBackupTest.php`.

- **Quando**: prima che WordPress installi un pacchetto di Histeon, sul filtro `upgrader_source_selection` (dopo aver estratto lo ZIP, prima di toccare la cartella installata). Vale per l'aggiornamento dalla pagina dei plugin, gli aggiornamenti automatici, WP-CLI, l'aggiornatore firmato di Histeon e uno ZIP caricato che sostituisce quello installato («Sostituisci la versione attuale con quella caricata»: `clear_destination` in `upgrader_package_options`). Un caricamento che non sostituisce nulla non fa copie. In più, in Histeon → Impostazioni → Copie di sicurezza, «Fai una copia adesso».
- **Cosa**: la cartella del plugin come ZIP (`plugin.zip`, senza collegamenti simbolici, `.git` e `node_modules`) e le tabelle di Histeon (`xb_commits`, `xb_refs`, `xb_releases`, `xb_release_items`, `xb_audit`, `xb_deploys`) con il loro `CREATE TABLE` e le righe (`tables.ndjson.gz`), più la versione dello schema (`xb_db_version`). In `uploads/xb-backups/<data>-<casuale>/`, chiusa come `xb-static` (`.htaccess` per Apache; su nginx `location ~ /xb-backups/ { deny all; }`). L'elenco, con l'impronta SHA-256 dei due file, è l'opzione `xb_update_backups`. Al massimo **due copie**: una nuova toglie la più vecchia.
- **Se la copia non riesce** (spazio, estensioni zip o zlib mancanti, upload non scrivibili) l'aggiornamento si ferma con il motivo. Per aggiornare senza copia: `define( 'XB_UPDATE_BACKUP', false );` in `wp-config.php`.
- **«Torna alla versione di prima»**: solo per chi ha `manage_options`, `update_plugins` e `xb_build`, con una casella di conferma. Controlla le impronte (una copia cambiata o incompleta non si usa), estrae lo ZIP accanto alla cartella dei plugin (`.xb-restore-…`, i nomi con il punto WordPress non li legge come plugin) e ricostruisce le tabelle accanto (`…__xbr`); poi scambia le tabelle con un solo `RENAME TABLE` e la cartella con due rinomine. Se la cartella non si scambia, anche le tabelle tornano come prima. Richiede che WordPress scriva direttamente nei plugin (metodo `direct`, niente FTP). Rimette `xb_db_version` della copia e toglie dall'opcache i file della cartella.
- **Cosa non copre**: i contenuti del sito (pagine, articoli e loro meta, media, menu), le impostazioni e le altre opzioni di Histeon, i dati dei moduli dentro il plugin (le tabelle e le opzioni di Lingua Bridge e Static Bridge: il loro codice è nella cartella), gli altri plugin e il tema. Le versioni salvate dopo la copia spariscono dalla cronologia, le pagine restano come sono. Su un **multisito** non si fanno copie: la cartella del plugin è di tutti i siti, le tabelle di ognuno.
