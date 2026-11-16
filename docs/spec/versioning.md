# Versioning — v0.1

Implementazione: `plugin/src/Versioning/`. Test: `plugin/tests/Integration/VersioningTest.php`, `plugin/tests/Integration/ScheduledReleaseTest.php` (§11), `plugin/tests/Unit/MergeTest.php`.

## 1. Modello
| Concetto | Significato |
|---|---|
| **Documento** | `post:{id}` (pagina, articolo o componente), `styles` (stili e fogli globali), `settings` (framework scelto) |
| **Commit** | Istantanea immutabile del payload di un documento: JSON con la sua impronta (§1.1), autore, messaggio, `parent` e `base`. Un commit alterato nel database viene **rifiutato** in lettura. |
| **Ramo** | `dev`: il lavoro del builder. `prod`: ciò che vedono i visitatori, sempre allineato al `post_content` e alle opzioni pubblicate. |
| **Release** | Versione semver con note, autore e data. Registra **la testa `prod` di tutti i documenti**, quindi è un'istantanea completa. L'elenco delle voci ha un hash, verificato prima di ogni ripristino. |
| **Audit** | Registro di pubblicazioni, ripristini e revoche delle anteprime, con utente e orario |

Tabelle: `xb_commits`, `xb_refs`, `xb_releases`, `xb_release_items`, `xb_audit` (InnoDB, query sempre preparate).

### 1.1 Impronta e spostamenti di indirizzo

L'impronta (`hash`) è un SHA-256 del payload in cui la parte d'indirizzo di ogni URL (schema e host, con barre protette una o più volte, o codificate) diventa un segnaposto (`Versioning\Integrity`). Così uno spostamento a un altro indirizzo non rompe i commit: gli strumenti di spostamento (per esempio `wp search-replace`) possono riscrivere l'indirizzo anche dentro i payload, e fino alla 0.3.7, con un SHA-256 semplice, ogni commit che nominava l'indirizzo del sito diventava illeggibile dopo uno spostamento e il builder mostrava pagine vuote. L'impronta protegge dalla corruzione, non da chi può scrivere nel database.

- **Commit scritti prima della 0.3.8** (SHA-256 semplice): si leggono ancora. Dopo un aggiornamento passano in sottofondo alla nuova impronta, 200 per volta (evento `xb_versions_fingerprint`), senza cambiare il payload.
- **Dopo uno spostamento**, i commit che non si leggono accendono un avviso per gli amministratori in tutta l'amministrazione (`Admin\VersionsRepair`), oppure si usa `wp xb versions-repair`:
  - con l'indirizzo di prima (`--from`), Histeon rimette quell'indirizzo nelle URL del sito e ripara solo i commit il cui SHA-256 torna: è la prova che è cambiato soltanto l'indirizzo;
  - se lo spostamento ha portato `http` e `https` a un unico schema, la prova non si può fare per i commit che li avevano entrambi. L'amministratore, dopo aver controllato le pagine, li accetta così come sono (`--accept`, o la casella di conferma): solo quelli il cui payload è ancora un documento JSON;
  - si scrive solo l'impronta, mai il payload, e il registro (`versions_repair`) conserva l'indirizzo dato e i commit accettati.

## 2. Payload
| Documento | Payload |
|---|---|
| `post:{id}` | `{content, script}`; per i componenti anche `{ref, schema}`; per pagine e articoli con un aspetto proprio anche `styles` ([client-mode.md](client-mode.md)) |
| `styles` | `{styles: [{id, selector, css}], globals: [{id, css}]}` |
| `settings` | `{framework, reset, presets, loopTypes, loopTaxonomies, sources, content, publicFields}`: `content` e `publicFields` (tipi, campi e campi leggibili di altri plugin, `docs/spec/content.md`) cambiano solo per mano di un amministratore |

## 3. Flussi
- **Primo accesso:** quando un documento entra nel versioning, il suo stato pubblicato diventa il primo commit di entrambi i rami.
- **Salva (builder):** crea un commit su `dev`. Il contenuto viene filtrato con i permessi di chi salva, come per un salvataggio normale. Il JavaScript richiede `unfiltered_html`. C'è un blocco ottimistico sulla testa `dev` caricata: se è cambiata nel frattempo, il salvataggio risponde **409**. Il sito pubblicato non cambia.
- **Pubblica:** richiede `xb_publish`. In **una sola transazione**, per ogni documento scelto:
  1. si uniscono i testi del cliente (§4);
  2. il payload si applica al sito con i permessi di chi pubblica;
  3. lo stato pubblicato risultante diventa un commit `prod`;
  4. `dev` riparte da lì;
  5. si registra la release.

  Dalla finestra «Pubblica» del builder può arrivare anche l'esito dei controlli (`checks`): si tiene nel registro con la release e in `xb_checks_last`, come promemoria e mai come condizione (docs/spec/checks.md, «L'esito con la release»).

  Se qualcosa fallisce, per esempio un JavaScript pubblicato da chi non ha `unfiltered_html` o un framework non installato, il database torna indietro e le cache di oggetti dei documenti coinvolti vengono svuotate. A pubblicazione riuscita viene emesso `do_action('xb_release_published', $id, $docs)`.
- **Ripristina:** richiede `xb_publish`. Riapplica le voci di una release come **nuova** release (`restored_from`), quindi la storia non viene mai riscritta. Con `keepContent` (predefinito) la struttura torna quella di allora ma restano i testi scritti dopo.
- **Modifiche fuori dal builder:** Gutenberg, REST e WP-CLI su un documento versionato creano un commit `prod`. Durante una pubblicazione non vengono registrate come modifiche esterne.

## 4. Unione dei testi (per uid)
Se `dev` non ha nulla di suo (il suo contenuto è quello della base), la copia di lavoro è semplicemente `prod`, blocchi aggiunti compresi, senza le righe vuote che Gutenberg mette fra i blocchi. Altrimenti, per ogni `xb/text` e per ogni prop di testo scritta in un'istanza di componente, con `b` = base, `d` = dev e `p` = prod:

| Caso | Risultato |
|---|---|
| `p == b` oppure `p == d` | Si tiene `d` |
| `d == b` (è cambiato solo prod) | Si prende `p` (testo del cliente) |
| Entrambi diversi | **Conflitto:** si tiene `d` e il caso viene mostrato nel builder |

La struttura arriva sempre da `dev`. L'unione avviene quando il builder carica il documento e di nuovo al momento della pubblicazione: un testo del cliente non va perso nemmeno se il builder è rimasto aperto.

## 5. Anteprima di sviluppo
- Il link porta `xb_preview=<scadenza>.<hmac>`:
  - firmato con un segreto del sito più `AUTH_SALT`;
  - valido 48 ore, oppure 1 ora, 1 giorno o 7 giorni scelti nel builder (`ttl` di `GET /preview-link`, ogni altro valore dà 400; la risposta ha `expires`); mai più di 7 giorni;
  - non legato a un login, quindi si può condividere con il cliente.
- La risposta mostra la versione `dev` di contenuti, componenti, stili e JS, con `no-cache`, `X-Robots-Tag: noindex` e un'etichetta "Anteprima di sviluppo".
- **Revoca:** `POST /xb/v1/preview-link/revoke` (`manage_options`) ruota il segreto e invalida ogni link già emesso.
- Il canvas del builder usa gli stessi dati `dev`.

## 6. REST
| Metodo | Rotta | Permessi |
|---|---|---|
| GET/POST | `/documents/{id}` | `xb_build` + `edit_post`. POST richiede `head` e `base`. |
| GET/POST | `/styles` | `xb_build`. POST richiede `head` e `base`. |
| GET | `/changes` | `xb_build` |
| GET | `/releases` (con `scheduled`, `scheduledLast`, `cron`, `timezone`: §11) | `xb_build` |
| POST | `/releases` (`version?`, `notes`, `docs?`, `checks?`) | `xb_build` + `xb_publish`, mai con una password per applicazioni (403 `xb_app_password`) |
| POST | `/releases/{id}/restore` (`keepContent`) | `xb_build` + `xb_publish`, mai con una password per applicazioni (403 `xb_app_password`) |
| POST | `/releases/schedule` (`at`, `version?`, `notes`, `docs?`, `checks?`) | `xb_build` + `xb_publish`, mai con una password per applicazioni (§11) |
| DELETE | `/releases/schedule` (`last?`) | `xb_build` + (`xb_publish` o chi ha programmato) |
| GET | `/preview-link?post=` | `xb_build` + `edit_post` |
| POST | `/preview-link/revoke` | `manage_options` + `xb_build` |
| GET | `/audit` | `manage_options` + `xb_build` |

## 7. Limiti noti
- I post e i media restano fuori dal versioning (ADR 0001). Si versionano struttura, stili, componenti, JS e impostazioni.
- Il cambio di framework assistito c'è (Impostazioni → Cambio di framework, [framework.md](framework.md)); l'export firmato fra siti anche ([signing.md](signing.md)).

## 8. Differenze, esportazione e importazione
**Differenze prima di pubblicare.** Nella finestra "Pubblica", "Differenze" confronta ogni documento modificato con la versione online.
- **Come si vedono:** codice Histeon per pagine, template e componenti (con JavaScript e schema); CSS per gli stili; JSON per le impostazioni.
- **Calcolo:** diff per righe nel browser, con le parti uguali compresse.
- **Endpoint:** `GET /changes/diff?doc=…`, che vede solo i documenti presenti nell'elenco delle modifiche visibili all'utente.

**Confronto visivo (facoltativo).** Nella stessa finestra, «Confronta» mette fianco a fianco la pagina online e quella in sviluppo, a schermo grande (1366 px) e su telefono (390 px), e segna cosa cambia: in corallo ciò che è nuovo o diverso in sviluppo, in blu ciò che sparisce dalla pagina online.
- **Quali pagine:** quella aperta nel builder (stili e componenti si vedono attraverso di lei) e ogni pagina o template nell'elenco delle modifiche, al massimo 6. I componenti e le pagine non ancora online non hanno una pagina da confrontare e lo dice.
- **Come confronta:** la stessa «firma» di `dev/visual-diff`: per ogni elemento visibile testo, posizione, dimensioni e stili calcolati principali. Gli elementi si allineano per tag, classe e testo; un riquadro cresciuto solo per ciò che contiene non viene segnato, e ciò che scende per una modifica sopra conta come «spostato».
- **Sicurezza:** le due pagine si caricano in riquadri `sandbox="allow-same-origin"`, come la tela: nessuno script delle pagine gira, i link non navigano, e una pagina finita su un'altra origine non viene letta. L'indirizzo online arriva dal server (`GET /preview-link` restituisce anche `live`, nullo per componenti e pagine non pubblicate); quello di sviluppo è il link d'anteprima firmato (§5).
- **Non blocca:** è un controllo da guardare prima di pubblicare, non una condizione.
- **La riga «Aspetto»:** nei controlli delle pagine, «Calcola» fa lo stesso confronto (`compare()`) sulle stesse pagine, in un riquadro suo, e scrive «Aspetto: N pagine cambiano a schermo grande, M al telefono» (`appearanceSummary()`: una pagina troppo diversa per allinearla conta come cambiata, un confronto non riuscito si conta a parte). «Guarda» apre questo confronto. Parte solo su richiesta, perché carica ogni pagina altre quattro volte (online e in sviluppo, alle due larghezze); è un'informazione, non un avviso, e non blocca.

**Esportazione.** `GET /releases/{id}/export` produce un file `.xbrelease` (JSON) con i documenti della release.
- **Come sono identificati:** in modo portabile. Pagine e articoli per tipo e slug, componenti per chiave, template per slug nel tema attivo.
- **Cosa contiene anche:** stili e impostazioni, l'indirizzo del sito di origine e lo SHA-256 dei documenti.
- **Chi può:** solo gli amministratori.
- **Firma:** il file è firmato con la chiave Ed25519 del sito ([signing.md](signing.md)); l'importazione la controlla e segue le sue regole (`trust`, `allowUnsigned` in `POST /releases/import`).

**Importazione.** `POST /releases/import` con `{data, write}`: prima un'anteprima, poi i commit di sviluppo.
- **File rifiutato per intero** se il formato, la versione o l'hash non tornano, se un tipo di post non è ammesso o una chiave non è valida, se un campo supera i 4 MB o il file i 20 MB.
- **Indirizzi:** quello del sito di origine diventa quello del sito attuale, anche nella forma JSON con gli slash escapati.
- **Documenti mancanti:**
  - pagine e articoli si creano come bozze;
  - i componenti si creano pubblicati e con la chiave, così una seconda importazione li ritrova;
  - i template si prendono dal file del tema se esiste.
- **Fiducia:** il contenuto passa da `save_dev` con quella di chi importa. Chi non ha `unfiltered_html` ottiene il markup filtrato e nessun JavaScript, come difesa contro un file che porti codice nel sito.
- **Impostazioni:** framework non disponibili, query non valide e tipi di contenuto inesistenti non si applicano e finiscono nel report. Sorgenti dati, token e parametri di Histeon CSS passano con la loro convalida; tipi di contenuto e campi pubblici solo se importa un amministratore.
- **Pubblicazione:** niente va online finché non si pubblica una release.

Per spostare tutto il sito (anche i contenuti che non sono documenti Histeon e i media) c'è il trasloco, con un file `.xbsite`: [site-transfer.md](site-transfer.md).


## 9. Storia di un documento (Histeon → Versioni)
Ogni commit di un documento è una versione: salvataggi in sviluppo, modifiche fatte fuori dal builder (registrate su online), pubblicazioni e ripristini. La schermata le elenca dalla più recente con ramo, autore, messaggio, le release che le contengono e il segno "non fidata" per quelle scritte senza `unfiltered_html`.
- **Confronta** mostra la differenza testuale tra quella versione e l'attuale in sviluppo: un blocco per riga, regole CSS e fogli una per riga.
- **Ripristina in sviluppo** crea un nuovo commit dev con il contenuto della versione scelta (`Versioning::revert()`). Passa da `save_dev()` come ogni salvataggio, quindi il blocco ottimistico, il filtro restricted e la regola sul JavaScript valgono anche qui. Una versione non fidata resta non fidata e non fa da riferimento al filtro. Niente va online finché non si pubblica una release.
- **Chi vede cosa:** chi ha `xb_build` e può modificare il post; gli stili con `xb_build`; le impostazioni anche con `manage_options`.

## 10. SQLite
Histeon funziona anche con WordPress su SQLite, attraverso il plugin ufficiale **SQLite Database Integration** (WordPress.org, Performance Team), che traduce le query di MySQL. Così si lavora in locale con un database in un solo file, e con Static Bridge si pubblica un sito senza server di database.

**Cosa è stato verificato** (plugin 3.1.0, PHP 8.3): l'intera suite di integrazione gira verde su SQLite, da database nuovo (`dev/sqlite.sh --fresh`, poi `COMPOSE_PROJECT_NAME=xb-sqlite XB_PORT=8894 dev/test.sh`), pubblicazione statica compresa. Su SQLite funzionano le parti SQL di Histeon:
- tabelle con `dbDelta` (`ENGINE=InnoDB`, `unsigned`, `AUTO_INCREMENT`);
- `ON DUPLICATE KEY UPDATE` e `INSERT IGNORE` (riferimenti, moduli, contatori);
- `START TRANSACTION`, `SAVEPOINT`, `ROLLBACK`, `COMMIT`;
- `information_schema.TABLES` (le tabelle risultano transazionali);
- `SHOW TABLES LIKE`.

**Cosa Histeon fa diversamente su SQLite.** Su SQLite Histeon non si affida a `GET_LOCK` per tenere separate pubblicazione, ripristino e deploy: quando `DB_ENGINE === 'sqlite'`, `Store::locked()` usa una riga di lock nella tabella delle opzioni (`Store::row_locked()`):
- la prende chi la inserisce (`INSERT IGNORE`, atomico su ogni motore); gli altri aspettano fino a 15 secondi, poi ricevono 409;
- porta la scadenza (10 minuti) e un token casuale: la toglie solo chi la possiede, e una riga scaduta (una richiesta morta) si riprende con un compare-and-delete;
- è rientrante nella stessa richiesta, come `GET_LOCK`.

Provato con due processi contemporanei: il secondo entra solo quando il primo ha finito. Test: `plugin/tests/Integration/StoreLockTest.php`, su entrambi i motori.

**Date nelle stringhe.** Su SQLite una stringa che ha la forma di una data ISO («2026-10-23T10:00:00Z») può tornare scritta in un'altra forma («2026-10-23 10:00:00»), anche nelle meta e nelle opzioni. La data di pubblicazione mandata da Histeon Publish per un record (`ContentProfileAdapter`, meta `_xb_page_profile_published_at`) è quindi salvata su SQLite come stringa JSON e riletta uguale: altrimenti il record tornerebbe con un'altra impronta di revisione.

**Copie di sicurezza.** Su SQLite Histeon non rinomina le tabelle. «Torna alla versione di prima» (`Backup::restore()`) riscrive quindi le tabelle di Histeon dalla copia dentro una transazione, scambia la cartella del plugin e solo dopo fa `COMMIT`; se lo scambio fallisce, `ROLLBACK` e la cartella torna com'era. Test: `plugin/tests/Integration/UpdateBackupTest.php`, su entrambi i motori.

**Un'istruzione che fallisce chiude la transazione.** Su MySQL una query che fallisce annulla solo sé stessa e la transazione resta aperta. Su SQLite (plugin 3.1.0) una query che fallisce annulla tutta la transazione in corso, salvo l'errore 1305 (savepoint inesistente). Histeon ne tiene conto così:
- in `Store::transaction()` un livello annidato che fallisce trova il suo savepoint già sparito: il `ROLLBACK TO SAVEPOINT xb_sp_N` risponde 1305, l'eccezione risale lo stesso e anche i livelli esterni la rilanciano;
- i cinque punti che aprono una transazione (`Versioning::save_dev()`, `Versioning::record_live()`, le release in `Versioning::atomically()`, la rinomina in `Selectors`, `Migration::prepare()`) non prendono l'eccezione di un livello interno per poi continuare a scrivere: `save_dev()` è annidata nella rinomina e nella migrazione senza `try`, `record_live()` non scrive durante una release (`Documents::applying()`), e i `catch` di `atomically()` e di `prepare()` svuotano le cache e rilanciano. Un errore che diventa eccezione risale quindi sempre fino in cima e su SQLite non si perde niente in silenzio;
- le scritture di `Store` (`commit`, `move`, `move_if`, la riga della release) controllano l'esito di ogni query e lanciano un'eccezione se fallisce.

**Limite noto su SQLite.** Se durante una release fallisce una query il cui esito non controlla nessuno (WordPress o un altro plugin dentro `Documents::apply()`, `Store::audit()`, `update_option()`), la transazione si chiude senza eccezione e le scritture successive vanno nel database una per una: su SQLite una release interrotta così può restare a metà. Con MySQL questo non succede. Se capita, si ripristina la release precedente da Histeon → Release (o dal pannello «Versioni» del builder) e si ripubblica.

**Limiti.** WordPress supporta ufficialmente solo MySQL e MariaDB; con Histeon SQLite resta una scelta per lo sviluppo locale e per i siti pubblicati come statici. Plugin di terze parti con SQL proprio possono non funzionare su SQLite.

**Anteprime più strette.** Il token di una pagina non vale se la richiesta porta una variabile di query di WordPress che sceglierebbe un altro post (una tassonomia, un autore, una data, una ricerca); restano quelle che sfogliano la stessa pagina e i parametri della pagina stessa, come i filtri dei suoi loop. In più, dopo la query principale, se il post mostrato non è quello del token, l'anteprima si chiude e la richiesta torna all'indirizzo senza token. Nel builder e per un agente si vede la versione di sviluppo solo dei documenti che l'utente può modificare; componenti e template compaiono come saranno pubblicati.

## 11. Release programmata

Implementazione: motore in `Versioning\Scheduled` (prove in `ScheduledReleaseTest`), interfaccia in `editor/src/lib/schedule.ts`, dialogo «Pubblica» e `Admin\ReleasesScreen`. Le alternative scartate sono nella nota in fondo (11.7).

L'idea in una riga: chi ha `xb_publish` sceglie giorno e ora per una release; all'ora stabilita il server ricontrolla che tutto sia **esattamente** come quando l'ha programmata e solo allora pubblica, con i permessi di quella persona. Se qualcosa è cambiato, non pubblica e lo dice.

### 11.1 Che cosa vede e fa la persona

- **Dialogo «Pubblica» del builder.** Sopra il pulsante, due scelte: «Pubblica ora» (com'è oggi) e «Programma per…». Con la seconda compare un campo data e ora, con accanto il nome del fuso del sito (per esempio «ora del sito, Europe/Rome»), e il pulsante diventa «Programma». Si sceglie come oggi: documenti, versione, note; i controlli delle pagine girano come oggi e il loro esito viene salvato con la programmazione.
- **La data.** Si legge nel fuso del sito (`wp_timezone()`), mai in quello del computer di chi programma. Non può essere nel passato: almeno 5 minuti avanti (per «subito» c'è «Pubblica ora»). Al più 60 giorni avanti. Un'ora che nel fuso del sito non esiste (il giorno del passaggio all'ora legale) si rifiuta con un messaggio; un'ora che esiste due volte (il ritorno all'ora solare) si prende la prima e si mostra con lo scostamento (`+02:00`). Il server rifà tutti questi controlli: il campo del browser è solo una comodità.
- **Una sola release programmata alla volta.** Se ce n'è già una, «Programma per…» è spento con la riga che dice quale; «Pubblica ora» resta acceso (vedi 11.4 d).
- **La riga.** Nel dialogo «Pubblica» e in cima alla schermata Release di wp-admin (il pannello «Versioni» del builder non la ripete: la sua conferma del ripristino dice che la programmata si annulla): «Programmata per il 20/11/2026 alle 09:00 da Utente di esempio · versione 1.4.0 · 3 documenti», con «Annulla la programmazione». Chiede conferma; lo può premere chi ha programmato o chiunque abbia `xb_build` e `xb_publish`. Se annulla un altro, chi aveva programmato riceve un'email.
- **Dopo l'ora.** La stessa riga diventa per qualche giorno «Pubblicata la versione 1.4.0 alle 09:02» oppure «Saltata la release programmata per il 20/11 alle 09:00: una pagina è cambiata dopo la programmazione», per 7 giorni o finché qualcuno non preme «Chiudi» (`DELETE /releases/schedule` con `last`). Così chi non è amministratore (il Registro lo vede solo chi ha `manage_options`) sa che cosa è successo anche se l'email non arriva.
- **Avviso nel builder.** Aprendo o salvando un documento che fa parte della release programmata, una barra dice: «Questa pagina è nella release programmata per il 20/11 alle 09:00. Se salvi una modifica, quella release non partirà.» È un avviso, non un blocco.
- **Avviso sul cron.** Nella schermata Release e nel dialogo, quando `DISABLE_WP_CRON` non è definito (cioè il sito non ha un cron di sistema), un avviso spiega che l'ora può slittare e mostra la riga da copiare (11.5).
- **Solo dal builder.** Si programma dal dialogo del builder, dove girano i controlli. La schermata Release di wp-admin mostra la riga e «Annulla» ma non programma, come la sua «Pubblica» con i controlli bloccanti, che già rimanda al builder.

### 11.2 Il record salvato

Un'opzione **non autoload**, `xb_scheduled_release`. Una sola, quindi una sola programmazione per sito:

| Campo | Che cosa |
|---|---|
| `v` | Versione del formato del record (1). Un formato che il codice non conosce non parte mai. |
| `id` | 16 byte casuali in esadecimale: lega il record al suo evento cron. |
| `at` | L'istante di pubblicazione in UTC (intero, secondi). |
| `tz`, `local` | Il fuso del sito e l'ora scritta dalla persona, così com'erano: servono solo per mostrare e per accorgersi di un cambio di fuso. |
| `docs` | Ogni documento scelto **con il commit `dev` di ciascuno in quel momento** (`{"post:12": "<id>", "styles": "<id>"}`), già allargato ai componenti nuovi che usano (`Versioning::selection()`), più le chiavi `media` e `contents` (valore vuoto) se erano spuntati. |
| `prod` | La testa `prod` di ogni documento in quel momento: serve a sapere se il cliente ha cambiato testi in Gutenberg nel frattempo (11.4 b) e a riconoscere una release intermedia. |
| `release` | L'id dell'ultima release in quel momento (anche se non è quella online): le release con id più alto sono «intermedie». |
| `waiting` | `media`, `contents` (quanti erano in attesa) e `hash`, l'impronta dei media in attesa (`PageProfileAdapter::pending_media_marks()`: id e sha256) e della coda dei contenuti (serve a 11.4 a). |
| `version`, `notes` | La versione (scelta o la prossima libera, **riservata** da quel momento) e le note. |
| `user`, `unfiltered` | Chi ha programmato e se allora aveva `unfiltered_html`. |
| `created` | Quando è stata programmata (UTC). |
| `checks` | L'esito dei controlli già passato da `ReleaseChecks::clean()`, o null. Come oggi è un promemoria, non una prova. |
| `approval` | Per ogni pagina, l'approvazione del cliente vista in quel momento (nome e testa), o niente: solo per il Registro e l'email. |
| `mac` | HMAC-SHA256 di tutti i campi sopra in forma canonica (chiavi ordinate), con la chiave `hash_hmac( 'sha256', 'xb-scheduled-release', wp_salt( 'auth' ) )`, cioè da `AUTH_KEY` e `AUTH_SALT` (in `wp-config.php`, non nel database). |

L'evento è `wp_schedule_single_event( $at, 'xb_scheduled_release', array( $id ) )`. Programmare, annullare, saltare e pubblicare scrivono ciascuno una voce nel Registro, con oggetto `schedule:<id>`: `schedule`, `schedule_cancel`, `schedule_skip`, `schedule_early` (lanciata prima dell'ora, una volta sola), `schedule_done` (con la release e il ritardo), più la solita `publish` della release. L'esito dell'ultima programmazione resta in una seconda opzione non autoload (`xb_scheduled_release_last`: `state`, `code`, `reason`, `version`, `at`, `user`, `release`, `done`) per la riga di 11.1.

Rotte: `POST /releases/schedule` (`at` come lo scrive `<input type="datetime-local">`, `version?`, `notes`, `docs?`, `checks?`; i documenti ridotti a quelli visibili come in `POST /releases`), `DELETE /releases/schedule` (con `last` chiude solo l'esito), e `GET /releases` che restituisce anche `scheduled` (`Scheduled::view()`), `scheduledLast`, `cron` e `timezone`. Permessi: `xb_build` + `xb_publish`, come `POST /releases`, più quelli di 11.6; annulla anche chi ha programmato. Nessuna delle due rotte accetta una password per applicazioni: un agente fuori dal browser non programma e non annulla.

### 11.3 Che cosa succede all'ora

Il gestore dell'evento, in quest'ordine:

1. **Il record c'è ed è quello giusto.** Nessun record, o un `id` diverso da quello dell'evento: non fa nulla (un evento rimasto da una programmazione annullata). Un `mac` sbagliato o un formato sconosciuto: salta («programmazione alterata»).
2. **Non è in anticipo.** Se l'ora è prima di `at` (l'evento lanciato a mano con WP-CLI o WP Crontrol) non fa nulla, lascia il record e rimette l'evento se chi l'ha lanciato l'ha tolto; scrive `schedule_early` una volta sola.
3. **Prende il lucchetto `release`** (lo stesso di pubblicazione, ripristino e deploy; è rientrante, quindi `publish()` lo riprende senza attese), **rilegge il record e lo toglie** subito, dentro il lucchetto. Un secondo giro dello stesso evento, anche in parallelo, trova il record già tolto e non fa nulla. Se il lucchetto è occupato, l'evento si rimette fra un minuto. Poi: record alterato o formato sconosciuto → salta; oltre la tolleranza (11.5) → salta («troppo in ritardo»).
4. **I controlli.** Pubblica solo se sono veri tutti:
   - **la persona:** l'utente esiste; ha ancora `xb_build` e `xb_publish`; non è in modalità cliente (`Capabilities::content_only()`); in un multisito è ancora membro di quel sito; ha ancora lo stesso `unfiltered_html` di allora; può ancora modificare ogni pagina (`edit_post`, come `visible_changes()`);
   - **i documenti:** ogni documento esiste ancora ed è **ancora allo stesso commit `dev`**; l'allargamento ai componenti nuovi, rifatto adesso, dà la stessa lista (`componente-nuovo` altrimenti);
   - **nessun'altra release** (pubblicazione o ripristino) ha toccato quei documenti dopo la programmazione. Nota: oggi ogni pubblicazione e ogni ripristino spostano anche la testa `dev` dei documenti che toccano, quindi questa condizione è già dentro la precedente; si tiene lo stesso, come seconda difesa a buon mercato (voci `publish` e `restore` del Registro dopo `created`, intersecate con `docs`);
   - **la versione** è ancora libera;
   - i casi di 11.4 (a) e (b): nessun invio nuovo da Histeon CMS (media o contenuti in attesa, non zero, con un'impronta diversa da allora: `contenuti-nuovi`), nessun conflitto dei testi del cliente (`Versioning::conflicts()`, calcolato senza scrivere).
5. **Pubblica con l'identità della persona, e solo per quel tempo:** `wp_set_current_user( $user )`, poi `publish( $version, $notes, $docs, $checks )`, poi, in un `finally`, l'utente torna quello di prima (0, nel cron). Niente cookie, niente sessione, niente email a nome suo. Anche ciò che si aggancia a `xb_release_published` (media e contenuti in attesa, Static Bridge, deploy) gira dentro `publish()` e quindi con quella identità, come quando pubblica a mano.
6. **Esito.** Pubblicata: email a chi aveva programmato («Pubblicata la versione 1.4.0 alle 09:02»), con il ritardo se c'è. Saltata: **non pubblica nulla**, scrive nel Registro `schedule_skip` con il motivo (un codice e una frase: `utente-assente`, `permesso-perso`, `permessi-cambiati`, `documento-cambiato`, `release-intermedia`, `versione-occupata`, `componente-nuovo`, `contenuti-nuovi`, `conflitti`, `troppo-tardi`, `alterata`, `errore`) e manda l'email a chi aveva programmato, oppure a `admin_email` se l'utente non c'è più o il record era alterato (un record alterato può nominare chiunque). Un errore dentro `publish()` (per esempio una tabella non InnoDB) fa tornare indietro tutto come oggi e conta come «saltata: errore».

L'email è solo testo: che cosa è successo, il motivo, il link alla schermata Release. Nessun link con token. `wp_mail` può non partire in silenzio su molti hosting: per questo l'esito resta anche nel Registro e nella riga di 11.1.

### 11.4 Casi particolari

- **(a) Media in attesa e contenuti in coda.** Oggi ogni release porta online tutti i media e i contenuti arrivati da Histeon CMS e in attesa (`apply_pending_media`, `apply_queue`), anche quelli non scelti. Un invio arrivato dopo la programmazione uscirebbe all'ora senza che nessuno l'abbia visto.
  *Come si comporta:* se dopo la programmazione è arrivato un invio nuovo (media o coda diversi da allora: si salva nel record il numero e un'impronta della coda), la release **salta** con il motivo «sono arrivati contenuti nuovi da Histeon CMS».
- **(b) Testi del cliente integrati e conflitti.** Se il cliente cambia testi in Gutenberg dopo la programmazione, la testa `dev` non cambia e `publish()` li unisce: quelli che il builder non ha toccato restano del cliente (bene: sono già online); dove tutti e due hanno cambiato lo stesso testo vince il builder e il testo del cliente sparisce dal sito, senza nessuno davanti.
  *Come si comporta:* prima di pubblicare si calcola l'unione senza scrivere; integrazioni sì, ma **un solo conflitto fa saltare** la release («il cliente ha cambiato un testo che cambia anche questa release»).
- **(c) Approvazione del cliente data prima e «ritirata» dopo.** Oggi l'approvazione non si ritira: si sostituisce con un'altra o diventa vecchia quando la pagina cambia in sviluppo (e allora la release salta già per «documento cambiato»). Non è una condizione nemmeno per «Pubblica ora», e la scrive chiunque abbia il link dell'anteprima.
  *Come si comporta:* resta un'informazione: si salva quella vista alla programmazione e l'email dice se all'ora è la stessa; non ferma nulla.
- **(d) Release a mano o da Histeon Publish mentre ce n'è una programmata.** Se tocca gli stessi documenti, la programmata salterà comunque all'ora (le teste `dev` si spostano); se non li tocca, la programmata resta valida. Anche la versione riservata va protetta.
  *Come si comporta:* la release a mano, o in arrivo da Histeon Publish, che tocca documenti della programmata **la annulla subito** (Registro `schedule_cancel` con «superata dalla release 1.3.2», email a chi aveva programmato); nel dialogo un avviso lo dice prima di premere. Una che non li tocca la lascia stare. La versione riservata non si può usare a mano (`next_version()` la salta, una scelta uguale dà 409).
- **(e) «Blocca la pubblicazione se i controlli trovano errori».** All'ora i controlli del browser non possono girare. L'impostazione la fanno rispettare il builder e la «Pubblica» di wp-admin (che si rifiuta); una chiamata diretta a `POST /releases` non la controlla.
  *Come si comporta:* con l'impostazione accesa, «Programma» resta spento finché un giro completo dei controlli non finisce senza errori, come «Pubblica»; la rotta rifiuta una programmazione senza `checks` o con errori. All'ora, gli stessi commit `dev` sono le stesse pagine controllate, quindi si pubblica. Si accetta che stili e componenti fuori dalla release o contenuti dei loop possano cambiare nel frattempo.
- **(f) Deploy statico e Static Bridge dopo la release.** Il deploy automatico verso Cloudflare parte con un evento cron messo a `time()` dentro `xb_release_published`: messo durante un giro del cron, gira al giro **successivo**. Static Bridge mette in coda le pagine (via `wp_after_insert_post`) e, se cambiano stili o impostazioni, tutto l'output condiviso, e lavora la coda con i suoi eventi cron. Su un sito senza visite tutto questo slitta come la release.
  *Come si comporta:* nulla di speciale: stessi agganci, stesso ordine, e con un cron di sistema (11.5) il deploy segue entro un giro; l'email della release dice che la copia statica si aggiorna nei minuti seguenti, e il Registro ne ha l'esito (`deploy_failed` come oggi).
- **(g) Ripristino di una versione precedente mentre c'è una programmazione.** `restore()` sposta le teste `prod` e `dev` di tutti i documenti della release ripristinata, quindi la programmata salterebbe all'ora. Lo stesso vale per «Ripristina in sviluppo» di una pagina della programmata.
  *Come si comporta:* il ripristino di una release **annulla subito** la programmazione, dopo una conferma che lo dice («Ripristinare annulla la release programmata per il 20/11 alle 09:00»); il ripristino in sviluppo di una pagina mostra l'avviso di 11.1 e la lascia saltare all'ora.
- **(h) Multisito e cambio di fuso.** Opzioni ed eventi cron sono di ciascun sito della rete: ogni sito ha la sua programmazione e il suo cron va chiamato per ogni sito. L'istante è salvato in UTC.
  *Come si comporta:* se il fuso del sito cambia dopo la programmazione, l'**istante resta quello** (in UTC) e la riga lo mostra nell'ora nuova con l'avviso «il fuso del sito è cambiato dopo la programmazione: erano le 09:00 Europe/Rome»; in un multisito si controlla anche che l'utente sia membro del sito, e la riga del cron di 11.5 si scrive per tutti i siti.
- **(i) Plugin disattivato o aggiornato prima dell'ora.** Disattivato, l'evento resta nel cron di WordPress senza nessuno che lo ascolti: all'ora WordPress lo toglie e non succede nulla, in silenzio. Aggiornato, record ed evento restano e il codice nuovo li legge.
  *Come si comporta:* alla disattivazione si toglie l'evento e la programmazione diventa «annullata: Histeon disattivato» (Registro ed email); alla riattivazione non riparte nulla. Dopo un aggiornamento un record con un `v` che il codice non conosce salta («formato non riconosciuto»), mai indovinato. La disinstallazione toglie le due opzioni e l'evento (`uninstall.php`).

### 11.5 Il cron

**Che cosa garantisce WP-Cron:** che l'evento non parte **prima** dell'ora. **Che cosa non garantisce:** che parta all'ora. WP-Cron gira solo quando una richiesta raggiunge il PHP di WordPress (una visita, wp-admin, una chiamata REST), che fa partire `wp-cron.php` con una richiesta a se stesso; se quella richiesta è bloccata (protezione con password, firewall, hosting che non la permette) non gira mai. Il suo lucchetto (`doing_cron`) non è atomico: due richieste vicine possono lanciare lo stesso evento due volte, per questo il gestore toglie il record dentro il lucchetto `release` (11.3, punto 3).

**Il sito servito in statico.** Con Static Bridge o con la copia su Cloudflare i visitatori non toccano il PHP: WordPress può non ricevere richieste per ore, e la release slitta fino alla prossima visita a wp-admin. Lo stesso per un WordPress su un computer locale spento.

**L'avviso**, quando `DISABLE_WP_CRON` non è definito: «Questo sito non ha un cron di sistema: la release partirà alla prima visita a WordPress dopo l'ora scelta, non necessariamente all'ora. Se il sito è servito in statico può slittare di ore. Chiedi all'hosting di aggiungere questa riga.» Sotto, da copiare, con l'indirizzo vero del sito:

```
*/5 * * * * curl -fsS "https://example.org/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

oppure, dove c'è WP-CLI, `*/5 * * * * cd /percorso/del/sito && wp cron event run --due-now --quiet`; in un multisito una riga per sito (`--url=`). E in `wp-config.php` `define( 'DISABLE_WP_CRON', true );`, così le visite non lanciano più il cron. `wp-cron.php` deve restare raggiungibile dal PHP anche quando il resto del sito è servito in statico.

**Se l'ora passa di molto.** Una tolleranza di **2 ore**. Fino a 2 ore di ritardo la release parte e l'email dice di quanto è partita tardi; oltre, **non parte**, salta con «troppo in ritardo» e avvisa. Il motivo: chi ha scelto le 9 di lunedì per un annuncio non vuole vederlo uscire mercoledì. Anche una visita a wp-admin dopo la tolleranza trova il record scaduto e lo chiude come saltato, così la riga non resta «programmata» per sempre.

### 11.6 Che cosa resta vietato

- **Un agente non programma.** Nessuna ability nuova; `xb/publish-release` resta di sola lettura e non nomina la programmazione. Nessuna operazione del connettore locale.
- **Nessuna rotta dei collegamenti** (Histeon CMS/Publish in `Cms\Bridge`, staging in `Remote\Receiver`, i pacchetti caricati come file) programma: pubblicano subito o mandano in sviluppo, come oggi.
- **Nessun comando WP-CLI** per programmare.
- **La rotta di programmazione** accetta solo richieste del builder con il cookie di accesso e il nonce `wp_rest`, e rifiuta quelle autenticate con una password per applicazioni (`rest_get_authenticated_app_password()`), che è la strada con cui un agente esterno parlerebbe con la REST di WordPress.
- Il record non porta mai credenziali, token o cookie: solo l'id dell'utente, controllato di nuovo all'ora.

### 11.7 Nota: alternative scartate

Comportamenti considerati e non adottati, per chi si chiede perché.

- (a) partono comunque, come con «Pubblica ora», e l'email elenca quanti media e contenuti sono usciti.
- (b) si pubblica come a mano (vince il builder) e l'email elenca i conflitti con i due testi.
- (c) se alla programmazione una pagina era approvata, all'ora l'approvazione deve essere ancora la stessa (stesso nome e testa), altrimenti salta.
- (d) nessuna azione subito: la programmata salta all'ora con «release intermedia».
- (e) con l'impostazione accesa non si programma affatto.
- (f) il gestore della programmata chiama il deploy subito, nello stesso processo, dopo `publish()` (più veloce, ma un processo del cron lungo, esposto ai limiti di tempo dell'hosting).
- (g) nessun annullamento: salta all'ora con «documento cambiato».
- (h) resta l'ora scritta dalla persona (`local`) e l'evento si sposta al nuovo istante quando il fuso cambia.
- (i) alla disattivazione il record resta; alla riattivazione, se l'ora non è ancora passata, l'evento si rimette, altrimenti salta con «Histeon era disattivato».
- Ritardo: nessuna tolleranza (parte appena può), oppure una tolleranza scelta nel dialogo.
- Password per applicazioni: stessi permessi di `POST /releases`, che allora le accettava (ora le rifiuta anche lei: §6).
