# Moduli

Decisioni: ADR 0015. Implementazione:
- `plugin/src/Modules/` (`Module`, `Registry`);
- `plugin/src/Admin/ModulesScreen.php`;
- `plugin/src/Framework/HistosCss/Module.php`.

Test: `plugin/tests/Integration/ModulesTest.php`.

## Registro
`Registry::all()` elenca i moduli. Per ciascuno ci sono:
- id, nome, descrizione, icona;
- versione del codice incluso e PHP minimo;
- se è incluso in Histeon e se parte acceso;
- il file del plugin separato equivalente;
- le procedure di avvio, accensione e spegnimento.

Altri moduli si aggiungono con il filtro `xb_modules`.

**Stato.** Lo stato si salva nell'opzione `xb_modules` (id → acceso o spento). Un modulo mai scelto prende il suo valore predefinito. Gli stati possibili sono:

| Stato | Quando |
|---|---|
| `on` | Incluso, PHP sufficiente, acceso, nessun plugin separato attivo |
| `off` | Incluso e spento |
| `standalone` | Il plugin separato dello stesso software è attivo: il modulo non si carica mai (niente due copie dello stesso codice) e non si può accendere |
| `php` | La versione di PHP non basta |
| `planned` | Non ancora incluso in Histeon: oggi si usa come plugin separato |

**Avvio.** `Registry::boot()` gira su `init` (priorità 1) e carica solo i moduli `on`, una volta.

**Accendere e spegnere.** `Registry::set()` esegue una sola volta la procedura di accensione o di spegnimento del modulo, poi salva lo stato. Spegnere non cancella mai i dati.

## Histeon CSS (primo modulo)
- **Acceso** (predefinito): classi (`histos.css`), parametri della palette, ricette proprie, pannello in popup, palette e dimensioni del testo in Gutenberg, anteprima `design/preview`.
- **Spento:** restano solo i token `--xb-*` di base, quelli di `xb-tokens.css` e del pannello Design system, perché i componenti li usano. Il framework si chiama «Token Histeon» e l'anteprima REST risponde 404.

## Schermata
**Histeon → Moduli** (`manage_options` e `xb_build`) mostra una scheda per modulo con:
- icona, stato e descrizione;
- versione e requisiti;
- l'interruttore (`role="switch"`), un form con il proprio nonce, `admin-post.php?action=xb_module_toggle`;
- il collegamento alle impostazioni.

Per un modulo attivo come plugin separato c'è il collegamento alla pagina Plugin.

## Menu
Implementazione: `plugin/src/Admin/ModuleMenus.php`. Test: `plugin/tests/Integration/ModuleMenusTest.php`.

Un modulo che Histeon fa girare (stato «acceso») aggiunge il suo menu principale, come farebbe da plugin: Lingua Bridge (`lingua-bridge`) e Static Bridge (`static-bridge`), dichiarati con `Module::$menu`. Le sue voci passano sotto **Histeon**, in fondo al menu: la prima pagina con il nome del modulo, le altre rientrate sotto.
- **Si sposta solo la voce.** Ogni pagina resta registrata come l'ha registrata il modulo: stesso indirizzo, stesso id della schermata, stesso hook su cui il modulo aspetta i suoi script (`toplevel_page_static-bridge`). Il test lo verifica rifacendo il calcolo di `admin.php`.
- **Aperta una pagina del modulo**, il menu aperto è quello di Histeon.
- **Chi non può aprire Histeon** (per esempio un traduttore con il solo `edit_posts`) continua a trovare il menu del modulo dov'era.
- **Un modulo attivo come plugin separato** tiene il suo menu.

## Lingua Bridge
Implementazione: `plugin/src/Modules/LinguaBridge.php`. Test: `plugin/tests/Integration/ModulesLinguaBridgeTest.php`.

**Codice.** È lo ZIP di rilascio di Lingua Bridge, fissato in `modules.json` per percorso e SHA-256 (oggi la 1.14.0, che porta Fluent Forms e FluentCRM nella lingua della pagina e le correzioni di XLIFF e dei link alla pagina degli articoli; la 1.13.0 porta selettore visibile di serie e nella testata Etch con un clic, lingua del visitatore, hreflang su home ed elenchi, reindirizzamenti e domini per la copia statica di Static Bridge, meta SEO tradotti, campi ACF e parti dei template, traduzione in sottofondo, RTL, x-default a scelta, file PO, ricerca nella copia statica, una valuta per lingua con WooPayments, lingua in WPGraphQL. La 1.7.1 porta ruolo «Traduttore» con le sue lingue, domini per lingua, hreflang nelle mappe del sito, barra della lingua del browser, glossario CSV e TBX, XLIFF di più lingue in uno ZIP, memoria di traduzione con TMX, testo alternativo e didascalie dei media per lingua, opzioni del selettore. La 1.5.10 risponde a `static_bridge_translated_urls` di Static Bridge con le traduzioni pubblicate di un contenuto e aggiunge i suoi elenchi tradotti all'export completo; richiede Static Bridge 2.2.11. La 1.5.9 porta rafforzamenti di sicurezza: meta copiate con `wp_slash`, router che rispetta `DISALLOW_FILE_MODS`, componenti Etch sostituiti solo da componenti pubblicati, una regola «online» unica per i link fra le lingue, meta `_xb_` non copiate nelle traduzioni dei prodotti; la 1.5.8 porta gli aggiornamenti firmati del plugin autonomo, spenti quando è un modulo). `scripts/modules.sh` lo verifica e lo estrae in `plugin/modules/lingua-bridge/`:
- in sviluppo, nella cartella del repository, che git ignora;
- nel pacchetto, nella cartella preparata da `scripts/package.sh`.

Uno ZIP con un'impronta diversa, o con percorsi fuori dalla sua cartella, viene rifiutato.

**Senza gli ZIP.** Un clone del repository che non ha gli ZIP di rilascio dei moduli costruisce il pacchetto con `XB_WITHOUT_MODULES=1 scripts/package.sh`: il file si chiama `xb-<versione>-<commit>-senza-moduli.zip` e non contiene `modules/`. Lingua Bridge e Static Bridge risultano allora non inclusi e si usano come plugin separati; il resto di Histeon non cambia.

**Avvio.** `Registry::load_early()` gira nel file principale di Histeon, subito dopo l'autoloader, prima di `plugins_loaded`, come WordPress caricherebbe il plugin. Carica il modulo solo se sono vere tutte e tre le condizioni:
- è acceso;
- il PHP basta;
- il plugin separato non è attivo.

**Accendere** fa quello che fa l'attivazione del plugin:
- crea le tabelle;
- riscrive il router in `mu-plugins`, se il sito tiene gli indirizzi delle lingue anche a plugin spento, perché potrebbe ancora puntare a una copia separata disattivata;
- aggiorna le regole di riscrittura.

**Spegnere** toglie le regole di riscrittura e non cancella nulla: lingue, relazioni, memoria e stringhe restano.

**Pagina Plugin.** Con il modulo acceso, il Lingua Bridge separato mostra «Attivo come modulo di Histeon» al posto di «Attiva»: la stessa copia di codice due volte fermerebbe il sito con un errore fatale.

## Errori fatali
Implementazione: `plugin/src/Modules/FatalGuard.php`. Test: `plugin/tests/Integration/FatalGuardTest.php`.

**Spegnimento automatico.** Un errore fatale che nasce dai file di un modulo spegne quel modulo:
- `modules/<id>/` per i moduli che si caricano come plugin;
- `src/Framework/HistosCss/` e `assets/histos-css/` per Histeon CSS.

Histeon registra l'errore nel gestore degli errori fatali di WordPress (`wp_should_handle_php_error`), che chiude la richiesta. Una funzione di `shutdown` copre i siti che quel gestore lo hanno spento. Entrambi si registrano nel file principale, prima che qualsiasi modulo si carichi.

La richiesta successiva parte senza il modulo e il resto del sito funziona. Lo stato diventa «Spento dopo un errore fatale». In `xb_modules_paused` restano data, file e riga, relativi al plugin, e il messaggio ripulito: senza percorsi, senza markup, al massimo 300 caratteri. Gli errori fuori dai moduli, Histeon compreso, non spengono nulla.

**Avviso.** Finché il modulo non viene riacceso, gli amministratori vedono in ogni schermata quale modulo si è fermato e perché, con il collegamento a Histeon → Moduli. Riaccenderlo dopo la correzione cancella l'errore.

**Da wp-config.php**, quando l'amministrazione non si apre:

```php
define( 'XB_SAFE_MODE', true );                               // nessun modulo
define( 'XB_DISABLE_MODULES', 'lingua-bridge,static-bridge' ); // solo questi
```

Un modulo tenuto spento così non si accende dalla schermata, che mostra «Spento da wp-config.php» e un avviso. Togliere la costante lo riporta allo stato scelto.

**Verifica.** Nel WordPress di sviluppo, un errore fatale inserito apposta nella copia estratta di Lingua Bridge:
- la prima richiesta si è fermata;
- la seconda ha funzionato senza il modulo;
- l'avviso indicava `modules/lingua-bridge/lingua-bridge.php`, riga 97.

Poi la copia è stata rigenerata dallo ZIP verificato.

## Static Bridge
Implementazione: `plugin/src/Modules/StaticBridge.php`. Test: `plugin/tests/Integration/ModulesStaticBridgeTest.php`.

**Codice.** È lo ZIP di rilascio di Static Bridge fissato in `modules.json`: oggi la **2.5.0**: il pulsante «Aggiorna la copia statica» nella barra di amministrazione e la casella «Non salvare questa pagina in statico» nell'editor, un'attesa dopo l'ultima modifica prima degli aggiornamenti automatici, l'email all'amministratore quando un export fallisce, l'export completo pianificato, i comandi WP-CLI, il controllo dei link con Strict, il sito di prova dietro Basic Auth, la versione Markdown delle pagine, i parametri di tracciamento ignorati, i redirect importati da Redirection, Rank Math e Yoast SEO Premium e il controllo del blocco Nginx in uso; corregge un contenuto salvato durante l'aggiornamento incrementale che poteva non arrivare mai alla copia statica e l'errore fatale attivando il plugin con il modulo acceso; la **2.4.4**: una pagina che mostra un errore di PHP o del database non entra più nella copia statica; la **2.4.3**: un indirizzo del sito scritto nel testo della pagina resta completo anche nella copia statica (prima perdeva il dominio, e con lui citazioni e indirizzi da copiare o stampare), e le estensioni di Complianz dichiarano le loro opzioni con il filtro `static_bridge_complianz_options`; la **2.4.2**: le icone dei pulsanti e delle schede sono centrate sul testo; la **2.4.1**: il nonce di Fluent Forms non tiene più le pagine di WooCommerce a WP (prima un modulo nel piè di pagina svuotava l'export completo), un indirizzo reindirizzato non conta fra le pagine escluse, il lock del worker incrementale è atomico; la **2.4.0**: la scheda Versioni (le versioni salvate, da eliminare una per una o in blocco, una precedente tenuta di serie) e il lock dell'export atomico; la **2.3.0**: la scheda Server (server web, cache dell'hosting, compressione, PHP e OPcache, Redis e Memcached, intestazioni di sicurezza), compressione e cache dei file e object cache a un clic, HiSpeed Cache di Aruba svuotata dopo ogni pubblicazione; la **2.2.15**: i reindirizzamenti di Lingua Bridge diventano regole del server (valgono anche in Strict) e i domini delle lingue non ricevono la copia statica; la **2.2.14**: canonical, hreflang, Open Graph e JSON-LD restano assoluti, il CSS di Etch salvato rigenera le pagine, gli errori 429 si riprovano, l'export va avanti a scheda chiusa, endpoint `refresh`; la **2.2.13**: con l'integrazione WooCommerce accesa il catalogo statico si aggiorna da solo, e carrello, cassa e account restano a WordPress; la **2.2.12**: un redirect verso una pagina con traduzioni si completa (dalla 2.2.9 veniva riprovato all'infinito); la **2.2.11**: alla riattivazione le copie dei contenuti ritirati mentre il plugin era spento si tolgono prima di riarmare il routing, e le pagine tradotte messe in coda si riscrivono invece di essere tolte (difetto della 2.2.9 e 2.2.10). La 2.2.10 porta rafforzamenti di sicurezza: regole Apache pubblicate prima di chiudere una rimozione, pagine con password tolte dalla copia, niente più vecchia via di export senza controllo SSI, `perl` fra i comandi SSI, `RemoveOutputFilter` per mod_include sulle pagine statiche, una frase su Strict nel readme. La 2.2.9: un aggiornamento incrementale rigenera ogni percorso anche nelle altre lingue (TranslatePress letto direttamente, gli altri plugin col filtro `static_bridge_translated_urls`) e la Diagnostica avvisa quando Complianz è in modalità sicura. La 2.2.8 porta gli aggiornamenti firmati del plugin autonomo, spenti quando è un modulo. La 2.2.7 chiama il builder col nuovo nome Histeon. La 2.2.6: una rotta a cui WordPress risponde 401 o 403 perde subito la copia statica, come con 404 e 410, e lo ZIP contiene la licenza; suite di sicurezza sullo ZIP esatto. La 2.2.5, passata dai controlli di rilascio sullo ZIP esatto, toglie il token di rendering dell'export anche dagli indirizzi codificati nei link, come quelli di condivisione verso Facebook, WhatsApp e X. La 2.2.4 esporta le pagine con slug tradotto da TranslatePress (SEO Pack), che prima finivano tra gli errori come «Rendering WordPress non verificato»: il controllo del rendering usa anche l'indirizzo della richiesta letto al caricamento del plugin. La 2.2.3 rigenera tutte le pagine statiche a ogni salvataggio di Complianz (banner, opzioni, Script Center), così banner, script bloccati e versione della policy non restano quelli dell'ultimo export; la Diagnostica confronta la versione del banner e della policy della homepage statica con Complianz. La 2.2.2 tocca solo l'interfaccia di amministrazione: contrasto, focus da tastiera, schermi stretti, Impostazioni a sezioni; nessuna funzione cambiata. La 2.2.1, provata anche su un sito di prova, chiude i punti che la 2.2.0 lasciava aperti: slug come `/tag/pdf/` serviti dalla cartella giusta, intestazioni di sicurezza del sito ripetute sulle pagine statiche (su Apache con mod_headers), permalink senza barra finale o con `.html`, Strict spento dopo una riattivazione con contenuti cambiati, paginazione senza fine non seguita, attivazione fermata con la home in una sottocartella o i contenuti fuori da `/wp-content/`. La 2.2.0 aveva reso statici gli archivi: categorie, tag, autori, date e paginazioni entrano nell'export completo e si aggiornano con quello incrementale. La 2.1.0 aveva aggiunto l'interfaccia in inglese. La 2.0.25, rispetto alla 2.0.24, aveva aggiunto due filtri:
- `static_bridge_status`: lo stato pubblico che Histeon mostra in Release → Sito statico;
- `static_bridge_archive_urls_for_post`: con cui Lingua Bridge fa rigenerare gli elenchi nella lingua del contenuto cambiato.

**Requisito.** Serve PHP 8.4. Con un PHP più vecchio il modulo mostra «Richiede PHP 8.4», non si accende e non si carica mai.

**Accendere.** Prima girano i controlli preliminari di Static Bridge (`activation_preflight`: PHP, WordPress, memoria, funzioni dei file). Se falliscono, non cambia nulla e i motivi compaiono nella schermata Moduli. Altrimenti si esegue l'attivazione del plugin.

**Spegnere.** Fa quello che fa la disattivazione del plugin: toglie le operazioni pianificate, il marcatore del routing e le regole Apache. Copie statiche e impostazioni restano.

**Fermato in un altro modo.** Le regole di Apache e Nginx servono le copie statiche solo finché esiste `wp-content/static-bridge/.enabled`. Se il modulo si ferma senza la sua disattivazione (FatalGuard dopo un errore fatale, `XB_SAFE_MODE` o `XB_DISABLE_MODULES`, Histeon disattivato o eliminato), Histeon toglie lui `.enabled` e `.strict`, senza caricare il codice del modulo (`StaticBridge::stop_routing`): il sito torna a WordPress, e una pagina resa privata o eliminata dopo l'ultima copia non resta pubblica. Alla disattivazione di Histeon, se il codice del modulo è caricato, gira la sua disattivazione completa. Con il plugin separato attivo i marcatori sono suoi e restano. Le copie tornano online alla prossima pubblicazione da Static Bridge.

**Pagina Plugin.** Come per Lingua Bridge, il plugin separato non si attiva sopra il modulo.
