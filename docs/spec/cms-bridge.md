# Ponte Histeon CMS — v1

Implementazione: `plugin/src/Cms/` (`Link`, `Bridge`), `plugin/src/Remote/PageProfile.php` (formato), `plugin/src/Remote/PageProfileAdapter.php` e `ContentProfileAdapter.php` (WordPress ↔ profilo), `ProductProfile.php` (prodotti, §15). Test: `plugin/tests/Integration/CmsBridgeTest.php`, `PageProfileAdapterTest.php`, `ContentProfileAdapterTest.php`, `plugin/tests/Unit/PageProfileTest.php`.

Histeon CMS è il CMS locale di Histeon Publish: progetti in SQLite, l'editor di Histeon, pubblicazione statica. Il ponte gli permette di **scaricare** un sito Histeon, modificarlo e **rimandarlo**. È una cosa diversa dallo staging → produzione ([remote.md](remote.md)): ha un collegamento suo e un formato suo.

## 1. Collegamento

- Si crea in **Histeon → Release → Histeon CMS**: un codice `histos-cms-1:` con indirizzo del sito, segreto casuale di 32 byte e permessi. Si mostra una volta, vale l'ultimo creato.
- È **separato** dal collegamento dello staging (opzione `xb_cms_link`): abbinare il CMS non scollega lo staging, e viceversa.
- Permessi, **tutti spenti di serie**: chi crea il codice li sceglie uno per uno.
  - **lettura**: scaricare il profilo del sito;
  - **scrittura**: inviare modifiche, che arrivano **in sviluppo**;
  - **pubblicazione**: mettere online ciò che si invia (serve anche `xb_publish`).
- Il segreto è cifrato a riposo come per lo staging. **Revoca** lo invalida subito; il CMS può anche revocarlo con una richiesta firmata.
- Le richieste girano con i permessi dell'amministratore che ha creato il codice; se non è più amministratore con `xb_build`, il sito rifiuta.
- Il sito dichiara in `capabilities.markup` i profili di markup che legge e scrive oltre alle regole di base: oggi `["histos-transfer-markup-v1", "histos-media-links-v1", "histos-url-path-v1", "histos-loop-group-v1", "histos-svg-text-v1"]` (SVG statico, `picture`/`srcset`, `details`, testo iniziale di `textarea`; link `<a href>` al file di un media; `url.path` nelle espressioni; loop a gruppi sul risultato di una query; SVG con testo, sfumature e stile in linea, dalla 0.5.14; §14). Histeon Publish non invia un profilo che li usa a un sito che non lo dichiara.

## 2. Richieste

Rotte, tutte firmate: `/xb/v1/cms/v1/capabilities` (GET, per qualsiasi collegamento valido: dice cosa può fare, compresi `stagedMedia`, `markup` e `commerce`), `/export` (GET), `/import` e `/publish` (POST, corpo ZIP), `/status/{operationId}` (GET), `/revoke` (POST), `/languages` (POST), `/front-page` (GET e POST, §18), `/preview-links` (GET, §20).

Firma come lo staging: `X-Histos-Time`, `X-Histos-Nonce`, `X-Histos-Signature`, HMAC-SHA256 del segreto su metodo, rotta, ora, nonce e SHA-256 del corpo. Ora entro ±5 minuti, nonce ricordato 10 minuti (in uno spazio diverso da quello dello staging), corpo fino a 512 MB, HTTPS obbligatorio fuori dagli ambienti `local`/`development` con un indirizzo locale (come lo staging, remote.md). Le richieste rifiutate finiscono nel Registro al massimo una al minuto.

## 3. Formato

Archivio ZIP «xb-site» **versione 2** (`.xbsite`), diverso dal trasloco `.xbsite` versione 1: `manifest.json`, `entities.ndjson`, `references.ndjson` e i file dei media. Schemi in `packages/spec/schemas/` (`site-transfer-v2`, `site-entity-v2`, `site-reference-v2`, `histos-static-page-v1`).

Ogni entità ha un id stabile, un `revisionHash` (SHA-256 del JSON canonico RFC 8785) e, se il CMS l'aveva già ricevuta, il `baseHash` da cui è partito. Tipi: pagine, articoli, componenti, parti di template, template, stili, media, tipi di contenuto, record, tassonomie, termini, query, sorgenti dati e cancellazioni (`tombstone`). La validazione è stretta: qualsiasi cosa inattesa rifiuta l'intero archivio. Articoli e ruoli dei template sono descritti al §12, il file da WordPress al §13, i prodotti di WooCommerce al §15.

## 4. Scaricare (lettura)

Il profilo descrive il sito **com'è in sviluppo**, cioè ciò che il builder di Histeon sta modificando: le copie di lavoro dei documenti Histeon, degli stili e delle impostazioni (tipi di contenuto, query, sorgenti), più i contenuti (record, termini) e i media. Così un invio successivo si confronta con la stessa cosa che il CMS ha ricevuto.

Una lettura non scrive nulla mentre avviene, e l'intestazione `x-histos-export` dice a che cosa serve. Senza intestazione è uno scaricamento: identità e basi di ciò che il sito non aveva ancora collegato si salvano, ad archivio completo. Con `read` (il controllo prima di scaricare) il sito resta com'era: le identità nuove sono derivate dall'indirizzo del sito e dal contenuto, quindi il controllo e lo scaricamento dopo mostrano le stesse. Con `linked` (il controllo prima di un invio, l'invio e il ritorno indietro) si legge solo ciò che il progetto possiede: i contenuti del sito restano fuori e non ricevono nulla.

Con `check` (da 0.5.7, il pulsante «Controlla il sito WP» di Publish) la lettura è come `read`, ma un contenuto che il progetto conosce e che non può viaggiare non ferma la lettura. Finisce in `manifest.leftOut` con `"blocks": true`: un invio o uno scaricamento si fermerebbero lì. Così il controllo nomina tutti i contenuti in una volta. Un archivio `check` serve solo a leggere: non si importa. Le capacità (`capabilities.readings`) dicono quali letture il sito conosce. Publish chiede `check` solo a un sito che la elenca, perché un sito più vecchio tratterebbe un'intestazione sconosciuta come uno scaricamento.

Da 0.5.7 un template senza il blocco «Contenuto del post» (lo slot predefinito) non ferma più l'intera lettura con un errore generico. Se il progetto non lo conosce, resta fuori ed è nominato in `leftOut`. Se lo conosce, la lettura si ferma con il suo nome.

Un contenuto del sito che non si può trasportare (una pagina con un `srcset` dinamico, un componente che ne contiene uno, una query su un tipo che il progetto non ha, ciò che li usa) resta fuori dal profilo ed è elencato in `leftOut` nel manifesto, con tipo, titolo e motivo, fino a 200. Vale solo per ciò che il progetto non conosce: un contenuto con una base ferma ancora la lettura e dice quale, perché lasciato fuori sembrerebbe cancellato.

## 5. Inviare (scrittura): sempre in sviluppo

Prima di scrivere qualsiasi cosa, l'invio viene **controllato per intero**: formato, permessi, conflitti, collisioni di nomi, CSS che il sito toglierebbe. Un solo problema ferma tutto, e il sito resta com'era.

- **Conflitti**: se un'entità è cambiata sul sito dopo l'ultimo scaricamento (il suo hash attuale è diverso dal `baseHash`), l'invio si ferma con l'elenco dei conflitti. Il CMS li risolve e riprova.
- **Documenti Histeon** (pagine, componenti, parti di template, template): il contenuto diventa un commit **in sviluppo** (`save_dev`), come un salvataggio del builder. Il markup è controllato prima di ogni scrittura con le regole di [`histos-transfer-markup-v1`](transfer-markup.md), le stesse di Histeon Publish con gli stessi codici di motivo, anche se il collegamento è di un amministratore; restano dentro il livello ristretto di `MarkupPolicy`, tranne i moduli (§9). Un documento che non le rispetta fa rifiutare l'intero invio. Cosa passa in ciascuna direzione è al §14. Un documento nuovo nasce come **bozza**. Titolo, slug, stato e template della pagina **aspettano la prossima release** sul post (`_xb_page_profile_pending_fields`): lo scaricamento li mostra come inviati, e la release li applica ai documenti che non hanno più contenuto non pubblicato. Un invio interrotto lascia il post nuovo segnato (`_xb_page_profile_creating`): non compare nel profilo e il nuovo invio lo completa invece di duplicarlo. Anche un file di media scritto da un invio interrotto si ritrova: il nuovo invio lo riprende invece di scriverne un doppione (`xb_page_profile_written_media`, con la data). Se nessun invio lo riprende entro sette giorni e nessun media lo usa, il primo invio successivo lo cancella.
- **Stili e impostazioni** (design, token, media query con nome, tipi di contenuto, campi pubblici, query, sorgenti dati): entrano nelle copie di lavoro dei documenti `styles` e `settings`, con la stessa validazione dell'import delle release. WordPress registra i tipi nuovi solo quando le impostazioni vengono pubblicate.
- **Contenuti** (record dei tipi CMS, termini), che Histeon non versiona in sé: senza pubblicazione **aspettano in una coda di sviluppo** (`xb_cms_pending_contents`). Lo scaricamento li mostra come sono in sviluppo, e la **prossima release** di Histeon li applica, che la pubblichi il builder o il CMS. Un contenuto il cui tipo non è ancora registrato resta in coda finché una release non porta le impostazioni che lo registrano. Anche i template assegnati ai tipi aspettano in coda. Un errore nell'applicarli finisce nel Registro (`cms_queue_failed`) e lascia la coda com'è; la release non fallisce.
- **Ordine dei termini di un record**: quello inviato (`_xb_page_profile_terms`), finché il record ha gli stessi termini; cambiati in WordPress, si leggono nell'ordine di WordPress (per nome). Prima un record con due termini fuori da quell'ordine tornava con un altro hash e Publish rifiutava il primo invio con la pubblicazione (`cms-remote-heads-invalid`).
- **Modifiche in WordPress dopo un invio**: vincono sempre. Ciò che aspetta (campi di un documento, record, termini) ricorda com'era il sito quando è stato inviato; se nel frattempo qualcuno lo cambia in WordPress, lo scaricamento mostra la versione di WordPress (il CMS vede il conflitto) e la release scarta l'attesa invece di sovrascrivere (`cms_queue_stale`).
- **Media**: si caricano nella libreria. Solo immagini raster (PNG, JPEG, GIF, WebP, AVIF) e font web (WOFF, WOFF2, OTF, TTF), mai SVG; il tipo si verifica sulla firma dei byte, non solo su quanto dichiara il manifest, prima di scrivere qualsiasi cosa, e i byte devono avere lo SHA-256 dichiarato.
- **Media già sul sito**: senza pubblicazione un media che esiste già (per id Histeon) **non cambia online**, perché pagine, campi CMS e CSS lo mostrano per id o per indirizzo. I byte nuovi vanno in un **file in attesa** nella cartella uploads, che non è un allegato e non compare nella libreria; titolo, didascalia e alt aspettano sull'allegato (`_xb_page_profile_pending_media`, il file in `_xb_page_profile_pending_file`). Il rapporto li elenca in `pending` con il motivo `next-release`; lo scaricamento mostra il media come inviato (byte, testi, `revisionHash`), così le basi del CMS restano valide. In sviluppo i documenti che mostrano l'immagine, anche quelli inviati invariati, passano al file in attesa (commit «Immagine sostituita da Histeon CMS»), quindi c'è qualcosa da pubblicare. La **prossima release** di Histeon, del builder o del CMS, rende il file l'allegato con le sue dimensioni, e applica titolo, didascalia e alt (`cms_queue_applied`); se nel frattempo l'allegato è cambiato in WordPress, l'attesa si scarta (`cms_queue_stale`) e il file in attesa si cancella se nessun documento lo usa. Un invio che riporta il media com'è online (il ripristino di Histeon Publish) toglie l'attesa e cancella il file. Il file che un allegato aveva prima resta e porta ancora all'allegato (`_xb_page_profile_media_alias`): una release precedente o una pagina non reinviata lo può mostrare. Un media nuovo si crea subito: nessuna pagina online lo usa. Il file in attesa, come un media nuovo, è già raggiungibile al suo indirizzo, ma nessuna pagina online lo mostra prima della release. Il sito lo dichiara con `capabilities.stagedMedia`.
- **Cancellazioni**: in sviluppo non si cancella nulla; il rapporto le elenca in attesa.

## 6. Pubblicare

Con il permesso di pubblicazione e `xb_publish`, `/publish` fa tutto quello che fa `/import` e poi:
1. pubblica una **release** Histeon con i soli documenti toccati da questo invio che differiscono dalla produzione (impostazioni e stili compresi), così i tipi nuovi vengono registrati: il lavoro del sito rimasto in sviluppo non esce; la release applica anche i contenuti rimasti in coda da invii precedenti. I media cambiano subito, al posto di ciò che un invio precedente aveva lasciato in attesa; un documento che mostra l'immagine sostituita esce con lei se in sviluppo non aveva altro da pubblicare;
2. applica titolo, slug e stato (`publish` per ciò che il CMS segna come pubblicato) a documenti e template;
3. scrive record e termini con i loro stati;
4. sposta nel **Cestino** gli elementi cancellati nel CMS: solo quelli collegati a questo progetto, con lo stesso `baseHash` sul sito, e mai la cancellazione definitiva. Il Cestino di WordPress deve essere attivo e resta almeno una pagina del progetto.

## 7. Indirizzi pubblici

I record dei tipi CMS usano i **permalink di WordPress** del loro tipo (`/slug-del-tipo/slug-del-record/`). Il ponte non aggiunge regole di riscrittura. Un record in una lingua diversa da quella predefinita del progetto ha lo slug con il suffisso della lingua (`nome-en-us`) per non scontrarsi con quello predefinito; le sue traduzioni vere sono compito di Lingua Bridge.

## 8. Operazioni, lock e stato

Ogni invio ha un id (`selection.snapshot` del manifest). Un id già completato restituisce lo stesso rapporto; un id riusato per un archivio diverso viene rifiutato. Un solo invio per progetto alla volta (lock con scadenza). Lo stato delle operazioni (`/status`) dice fase, entità cambiate e completezza, solo al collegamento che le ha avviate.

## 9. Moduli

Il ponte trasporta un `<form>` solo con `action` statico (percorso del sito o URL HTTPS, senza credenziali, frammenti, spazi o entità che lo trasformino) e `method` GET o POST. Dentro, i campi di [transfer-markup.md](transfer-markup.md): `input`, `select`/`option`/`optgroup`, `textarea` con il suo testo iniziale, `button`, `label`, `fieldset`/`legend`, `output`, con attributi da elenco chiuso; niente campi `password`, `file` e `image`, niente `autocomplete` di credenziali o carte, niente `form*` sui pulsanti.

Questo **non** cambia chi può scrivere un `<form>` sul sito: resta riservato a chi ha `unfiltered_html`. Per questo un invio con un modulo da un collegamento il cui utente non ha `unfiltered_html` si ferma **nei controlli preliminari**, prima di qualsiasi scrittura (altrimenti il salvataggio in sviluppo toglierebbe il modulo dopo altre scritture, contro il §5).

## 10. Registro

`cms_link`, `cms_revoke`, `cms_export`, `cms_import`, `cms_publish`, `cms_front_page` (§18), `cms_refused`, `cms_queue_applied`, `cms_queue_failed`, `cms_queue_stale`, `cms_file_export`, `cms_file_import`.

## 11. Limiti

- Un solo progetto CMS per sito.
- Un contenuto modificato nel CMS cambia sul sito solo con la prossima release. Anche un media: se cambiano solo i testi (alt, titolo, didascalia) o il file, nessun documento cambia; i media in attesa compaiono allora fra le modifiche da pubblicare come una voce sola, `media` («N media dal CMS in attesa»), e una release si può fare anche solo per loro, con gli stessi permessi e controlli di ogni release. I media in attesa vanno comunque online con qualsiasi release. Contano solo quelli di un invio completato: un invio fallito o interrotto non lascia nulla da pubblicare, e la release lo scarta (`cms_queue_stale`). Lo stesso per record, termini e prodotti in coda (un prezzo cambiato, per esempio): compaiono come una voce sola, `contents` («N contenuti dal CMS in attesa»), e una release si può fare anche solo per loro; vanno online con qualsiasi release.
- Le cancellazioni inviate senza pubblicazione non si mettono in coda: il CMS le rimanda al prossimo invio con la pubblicazione.
- Le traduzioni dei record non diventano traduzioni di Lingua Bridge.

## 12. Articoli e ruoli dei template (profilo `histos-static-project-v4`)

Un archivio con almeno un articolo o un template con ruolo dichiara il profilo `histos-static-project-v4` e, fra le capacità richieste, `documents:post:v1` (se ci sono articoli) e `templates:roles:v1` (se c'è un ruolo). Un sito o un CMS che non elenca il profilo fra i suoi `profiles` non lo riceve: il mittente si ferma prima di inviare.

### Articoli

Un articolo è un documento con `documentType: "post"`: lo stesso markup Histeon delle pagine (stesse regole, stessi riferimenti a media e componenti) più tre campi obbligatori.

| Campo | Valore |
|---|---|
| `slug` | `^[a-z0-9]+(?:-[a-z0-9]+)*$`, al massimo 200 caratteri |
| `publishedAt` | `AAAA-MM-GGThh:mm:ssZ` (UTC) se `status` è `published`, `null` se è `draft` |
| `excerpt` | testo, al massimo 20.000 caratteri; chi lo mostra lo tratta come testo |
| `terms` | `{ "chiave-tassonomia": [id dei termini] }`, al massimo 64 tassonomie e 100 termini ciascuna, senza ripetizioni |
| `templateRef` | facoltativo, come per le pagine |
| `image` | facoltativo: l'immagine in evidenza, l'id di un asset del profilo di tipo `image/*`. La chiave c'è solo quando l'articolo ha un'immagine, così un articolo senza conserva la sua impronta |

In WordPress `image` è `_thumbnail_id`: all'esportazione la miniatura diventa l'asset del suo allegato (un'immagine; un altro formato lascia fuori l'articolo), all'importazione l'allegato del media diventa la miniatura e un articolo senza la chiave la perde. Come riassunto, data e termini, su un articolo già pubblicato l'immagine cambia con la release successiva; i campi in attesa scritti da un plugin precedente, che non la nominano, la lasciano com'è. Nei template e nei loop si legge come `post.image` (o `page.image` nel template dell'articolo), con le chiavi di `ImageNode`; in Histeon Publish `width`, `height`, `srcset` e `sizes` sono `null`.

Ogni chiave di `terms` è una tassonomia del profilo che fra i suoi `types` ha `post`, e ogni id è un termine di quella tassonomia. Le tassonomie native di WordPress hanno chiavi riservate: `category` (Categorie) e `post_tag` (Tag), sempre con `types: ["post"]`. Il tipo `post` è predefinito: nessun tipo di contenuto può chiamarsi `post` o `page`. Una query (`query-preset`) può avere `typeKey: "post"` per elencare gli articoli.

Gli articoli passano solo se sono **articoli Histeon** (con un'identità del progetto, un template Histeon assegnato o blocchi Histeon), come le pagine: gli articoli scritti con l'editor a blocchi di WordPress restano fuori dal profilo.

### Ruoli dei template

Un template può avere `role`, uno solo per ruolo in tutto il progetto:

| Ruolo | In WordPress (slug del template del tema attivo) | Nel sito statico di Histeon Publish |
|---|---|---|
| `front-page` | `front-page` | la home `/`, con il contenuto della pagina `home` se c'è |
| `home` | `home` | l'elenco degli articoli `/blog/` (e `/blog/page/N/`) |
| `index` | `index` | l'ultima scelta per articoli, pagine, archivi ed elenco articoli |
| `single` | `single` | un articolo (e un record il cui tipo non ha un template singolo) |
| `page` | `page` | una pagina senza template assegnato |
| `archive` | `archive` | l'archivio di un tipo senza template d'archivio e di una tassonomia |
| `category` | `category` | l'archivio di una categoria |
| `tag` | `tag` | l'archivio di un tag |
| `search` | `search` | nessuno: un sito statico non ha ricerca lato server |
| `404` | `404` | `404.html` alla radice |

L'ordine di scelta segue la gerarchia di WordPress. Articolo: `templateRef` → `single` → `index`. Pagina: `templateRef` → `page` → `index`. Home: `front-page` prima di ogni altra cosa. Elenco articoli: `home` → `index`. Categoria: `category` → `archive` → `index`; tag: `tag` → `archive` → `index`; altre tassonomie e archivi dei tipi: il template del tipo → `archive` → `index`. Senza template, Publish usa la sua pagina semplice.

In WordPress un template con ruolo si salva con lo slug del ruolo, quindi prende il posto del template del tema per quel caso; senza ruolo resta `histos-<hash>` e vale solo dove è assegnato. Lo scaricamento ricava il ruolo dallo slug di un template Histeon del tema attivo. Se il tema ha già, nel database, un template non Histeon con quello slug (una personalizzazione del Site Editor), l'invio si ferma: Histeon non lo sovrascrive. Un cambio di ruolo su un template esistente **aspetta la release**, come lo slug delle pagine. Un ruolo può passare da un template a un altro nello stesso invio: chi lo lascia prende prima il suo slug privato, poi l'altro prende quello del ruolo, e i documenti assegnati seguono il loro template.

### Indirizzi degli articoli

- In WordPress valgono i permalink del sito: il ponte non aggiunge regole.
- Nel sito statico: articolo `/blog/<slug>/`, elenco `/blog/`, categorie `/category/<percorso>/`, tag `/tag/<slug>/`, altre tassonomie `/<chiave>/<percorso>/`. Una pagina con slug `blog` prende il posto dell'elenco (come una pagina con lo slug di un tipo).

### In WordPress

- **Scaricare**: gli articoli Histeon in bozza o pubblicati (copia di lavoro, come le pagine), le tassonomie `category` e `post_tag` con tutti i loro termini, e i ruoli dei template.
- **Inviare**: il contenuto dell'articolo diventa un commit in sviluppo; titolo, slug, stato, data, riassunto, termini e template aspettano la release (`_xb_page_profile_pending_fields`). Un articolo nuovo nasce come bozza. Il riassunto passa solo se `wp_kses_post` non lo cambia. Un termine di `category` o `post_tag` che esiste già con lo stesso slug e non appartiene a nessun progetto viene adottato; se appartiene a un altro, l'invio si ferma. La release crea i termini prima di assegnarli agli articoli.
- Cancellazioni: come per le pagine (Cestino, solo con la pubblicazione).

## 13. Il pacchetto come file in WordPress

In **Histeon → Release → Histeon CMS** un amministratore (`manage_options` e `xb_build`) può:

- **scaricare** il pacchetto `.xbsite` versione 2 del sito, lo stesso che legge il CMS (§4);
- **caricare** un pacchetto: il file viene controllato per intero senza scrivere nulla (formato, permessi, conflitti, collisioni, CSS) e la pagina mostra cosa farebbe; solo la conferma lo applica, **in sviluppo** oppure, con `xb_publish`, pubblicandolo (§5–§6). Fra controllo e conferma il file resta nella cartella temporanea del server per 30 minuti, legato all'utente; poi si cancella.

Il file passa dalle stesse verifiche del ponte (`PageProfileAdapter::import`): un file non è più fidato di una richiesta firmata. Le operazioni finiscono nel Registro (`cms_file_export`, `cms_file_import`).

## 14. Markup trasferibile (`histos-transfer-markup-v1`)

Le regole sono in [transfer-markup.md](transfer-markup.md); qui cosa fa WordPress in ciascuna direzione. Il sito dichiara il profilo in `capabilities.markup` (§1) solo perché sa fare tutte e tre le cose.

| | Invio (CMS → WordPress) | Scaricamento (WordPress → CMS) |
|---|---|---|
| Controllo | `ProfileValidator` su ogni documento prima di qualsiasi scrittura, media compresi, nell'ordine del documento | `ProfileValidator::assert_transfer_block_tree()` sul documento portabile: ciò che non passa ferma lo scaricamento |
| `src` | `histos-media://<id>` di un'immagine del profilo → URL dell'allegato in sviluppo; `{props.nome}` resta com'è | URL degli upload di un allegato (anche una misura intermedia) → `histos-media://<id>`; `{props.nome}` resta com'è; ogni altra espressione si rifiuta |
| `srcset` di `img` e `source` | ogni candidato `histos-media://` → URL dell'allegato, con il suo descrittore | ogni candidato → `histos-media://<id>` con un riferimento `attrs.attributes.srcset` e il suo `index`; si rifiutano misure intermedie (`-300x200.jpg`), l'originale non ridimensionato, file precedenti, URL esterni o di upload che non sono allegati |
| `href` di `a` (`histos-media-links-v1`) | `histos-media://` → URL del file dell'allegato in sviluppo (il file in attesa, se c'è) | il link che è esattamente il file in sviluppo di un'immagine o un PDF allegato → `histos-media://<id>` con un riferimento `attrs.attributes.href`; un link a una misura intermedia, a una pagina o a un upload che non è un allegato resta un link come gli altri |
| SVG | come arriva, testo e sfumature compresi (`histos-svg-text-v1`) | via `xmlns="http://www.w3.org/2000/svg"` e `xmlns:xlink="http://www.w3.org/1999/xlink"` dall'elemento `svg`, esattamente così; ogni altro `xmlns` si rifiuta; `<use>` e `href` hanno un messaggio che spiega di disegnare le forme sul posto |
| Moduli | solo se l'utente del collegamento ha `unfiltered_html` (§9) | come sono |
| Riferimenti | uno per `src` (senza `index`), uno per link a un media (senza `index`), uno per candidato `srcset` (con `index`); la chiave comprende l'indice | ordinati come in Publish: proprietario, blocco, percorso, destinazione, indice |

Quando un invio senza pubblicazione cambia i byte di un'immagine (§5), anche i candidati `srcset` che sono esattamente il file dell'allegato passano al file in attesa; le misure intermedie, che hanno un loro descrittore, restano.

Al **rendering** (`Renderer::attribute`), per i valori dinamici che un trasferimento può portare:

| Valore | Cosa stampa WordPress |
|---|---|
| `src={props.nome}` in un componente scritto da un import (`_xb_transfer_imported` sulla definizione o sul post che ne possiede la chiave) | l'URL valutato, uguale a come `esc_url()` lo stampa e letto come lo legge il browser, solo se è degli upload del sito (assoluto con lo stesso host e percorso, in http o https, oppure dal percorso degli upload), senza spazi, controlli, barre rovesciate né segmenti `.`/`..` (anche `%2e`, `&#46;` o `.&#9;.`); altrimenti l'attributo si toglie |
| `src={props.nome}` in un componente nativo (anche dopo uno scaricamento, che gli dà solo un'identità), e ogni altra espressione (`{item.image.url}`, `{user.avatar}`) | come prima: dati di WordPress, anche su una CDN |
| `style` dinamico | come prima (`safecss_filter_attr()`): l'import rifiuta già ogni `{` in uno stile trasferito |

**Limiti noti** ([transfer-markup.md §5](transfer-markup.md#limiti-noti)): una prop `image` di un'istanza con un valore `histos-media://` non ha un riferimento, quindi resta com'è e si stampa senza `src`; una pagina inviata può dare a un componente nativo un indirizzo d'immagine fuori dal sito, che il componente stampa come gli altri dati (le immagini delle pagine ricevute si guardano nell'anteprima prima di pubblicare); i componenti importati prima del segno lo ricevono dai registri dell'import con una migrazione su `admin_init` e, fino ad allora, rendono come nativi; i moduli annidati attraverso gli slot di un componente non si vedono, perché il modello di contenuto si controlla documento per documento.

Codici di motivo: `tag`, `context`, `attribute`, `dynamic`, `value`, `reference`, `url`, `style`, `form`, `text`, `media` ([transfer-markup.md §2](transfer-markup.md#2-codici-di-motivo)). Il modulo rifiutato per `unfiltered_html` non è una violazione del markup e non ha codice: è un permesso del sito.

## 15. Prodotti di WooCommerce (`commerce:products:v1`)

Implementazione: `plugin/src/Remote/ProductProfile.php`. Le regole del catalogo sono in [ecommerce.md](ecommerce.md#prodotti-dal-cms); qui cosa fa il ponte.

- **Capacità:** con WooCommerce attivo il sito dichiara `capabilities.commerce: ["commerce:products:v1"]`. Un archivio con il tipo `product` o con le tassonomie `product_cat`/`product_tag` la elenca fra le `requiredCapabilities`, accanto a quelle dei contenuti (§12); il controllo stretto la vuole esattamente quando ci sono, nei due sensi. Un sito senza WooCommerce rifiuta l'archivio prima di scrivere.
- **Formato:** nessuna entità nuova. Il tipo `product` è un `content-type` con i campi fissi del prodotto (`regular_price`, `sale_price`, `sku`, `stock_status`, `image`, `gallery`, in quest'ordine e con queste definizioni: `ProductProfile::fields()`, uguale a `productFields()` di Publish); i prodotti sono `content-record`, le categorie e i tag `taxonomy` e `term`, con `types: ["product"]`. `ProductProfile::validate()` aggiunge alla validazione dei record le regole dei valori (prezzi come numeri, SKU unico, testo senza `<` `>`).
- **Invio:** il tipo non entra nelle impostazioni `settings` (WooCommerce registra già `product`); se ne ricordano l'identità (`xb_page_profile_content_type_ids`), le etichette del progetto (`xb_page_profile_product_labels`) e i template assegnati, che aspettano la release come quelli degli altri tipi (§5). I prodotti seguono la coda dei record: senza pubblicazione aspettano in `xb_cms_pending_contents`, e la release li scrive con l'API dei prodotti semplici di WooCommerce. Le categorie e i tag dei prodotti sono trattati come `category` e `post_tag` (§12): identità del progetto, adozione dei termini senza progetto con lo stesso slug, rifiuto di quelli di un altro progetto.
- **Template:** i template assegnati al tipo prendono, una volta pubblicati, il posto dei template `single-product` e `archive-product` del tema attivo (`ContentProfileAdapter::apply_type_templates`), come `single-<tipo>` e `archive-<tipo>` per i tipi di Histeon.
- **Scaricamento:** vedi ecommerce.md. La categoria predefinita di WooCommerce resta fuori finché il progetto non la usa, così un invio successivo non la trova «mancante in locale».
- **Registro:** come per i record (`cms_import`, `cms_publish`, `cms_queue_applied`, `cms_queue_failed`, `cms_queue_stale`).

Due regole valide per ogni contenuto: la ricerca di un termine per identità Histeon ha un ordine esplicito, così un plugin che cambia l'ordine predefinito delle query dei termini non la altera; e un record pubblicato di nuovo tiene il suo indirizzo, mentre prima prendeva un suffisso `-histos-…` perché trovava sé stesso fra gli slug occupati.

## 16. JavaScript dei documenti (`documents:script:v1`)

Il campo `script` di pagine, articoli e componenti porta il JavaScript del documento, lo stesso del pannello JS (`_xb_script`, `Scripts`). Nelle versioni precedenti era sempre vuoto; ora può contenere testo.

- **Capacità.** Un archivio dichiara `documents:script:v1` esattamente quando almeno un documento ha uno `script` non vuoto. Un sito che non conosce la capacità rifiuta l'archivio intero, così nessuno script viene tolto o eseguito senza controllo.
- **Limiti.** Al massimo `Scripts::MAX_BYTES` (100 000 byte UTF-8), senza il carattere NUL. Parti e template non hanno JavaScript: uno `script` non vuoto lì rifiuta l'archivio.
- **Chi lo salva.** Lo stato del collegamento annuncia `capabilities.scripts: ["documents:script:v1"]` solo se l'utente del collegamento ha `unfiltered_html`; Histeon Publish non invia script a un sito che non lo annuncia. L'import lo ricontrolla prima di scrivere: senza `unfiltered_html` si ferma, e non scrive nulla.
- **Dove va.** Nella copia di sviluppo del documento, come il contenuto: arriva online con il rilascio. L'export legge la copia di sviluppo.
- **Nel piano.** Il controllo di un pacchetto (§13) segna con `script: true` le righe dei documenti che portano JavaScript, e la schermata li mostra con «con JavaScript»: chi conferma sa che cosa diventerà eseguibile al rilascio.
- **Quando gira.** Mai nel canvas del builder né durante l'import; sul sito pubblicato come `<script type="module">` dopo il contenuto. In Histeon Publish lo stesso nella build statica, e nell'anteprima isolata (sandbox senza stessa origine, `connect-src 'none'`).

## 17. «Importa HTML»

Nella pagina Release, sotto Histeon CMS, il riquadro «Importa HTML» trasforma una pagina, lo ZIP di un sito o la sua cartella in un progetto Histeon.

- **Dove gira.** Nel browser dell'amministratore: il convertitore e l'import dei prototipi di Histeon Publish, impacchettati per il browser (`plugin/assets/html-import/histos-html-import.js`), sullo stesso magazzino del progetto, con l'SQLite ufficiale per WebAssembly in memoria (`sqlite3.mjs`, `sqlite3.wasm`). Sono lo stesso codice di Publish (versione in `UPSTREAM`); il server non ha una seconda implementazione.
- **Cosa consegna.** Un `.xbsite`, che va al modulo «Controlla» come ogni pacchetto (§13): il server lo valida (PageProfile), mostra cosa scriverebbe, e scrive solo alla conferma, in sviluppo.
- **Sicurezza.** Nulla del sito viene eseguito: i file si leggono come dati. Nel browser i file di altri siti non si scaricano, perché lì non si può escludere la rete interna dell'amministratore: restano fuori, con una nota. I percorsi dello ZIP o della cartella passano dallo stesso controllo (niente percorsi assoluti, `..`, cartelle nascoste), con al massimo 5000 file e 200 MB. Senza `unfiltered_html` gli script del sito non si possono salvare: il riquadro usa le ricette senza JavaScript.
- **Chi lo usa.** Gli amministratori con `manage_options` e `xb_build`, come il resto della sezione.

## 18. Pagina iniziale e pagina degli articoli (`histos-front-page-v1`)

Su un WordPress nuovo la home del progetto resta una pagina a `/home/` e la `/` resta l'elenco degli articoli (`show_on_front` = `posts`), mentre nel sito statico la home è `/` e una pagina `blog` è l'elenco degli articoli (§12). Histeon Publish, dopo il primo invio con la pubblicazione, **propone** di impostarle come nel progetto e le imposta solo se l'utente dice sì. Implementazione: `plugin/src/Cms/FrontPage.php`, `Bridge::front_page()`. Test: `CmsBridgeTest`.

- Il sito dichiara `capabilities.siteSettings: ["histos-front-page-v1"]`; un sito senza non riceve la proposta.
- **`GET /front-page`**, con il permesso di lettura: `{showOnFront: "posts"|"page", frontPage, postsPage, frontPageOther, postsPageOther}`. `frontPage` e `postsPage` sono le identità (UUID del profilo) delle pagine del progetto che oggi fanno da home e da pagina degli articoli, o `null`; una pagina del sito che non è del progetto risulta solo come `…Other: true`, senza id né titolo.
- **`POST /front-page`** `{"frontPage": id, "postsPage": id|null}`, esattamente queste due chiavi, con i permessi di scrittura **e** di pubblicazione e un proprietario del collegamento che ha ancora `manage_options` e `xb_publish` (cambia subito il sito online, come una release). Ogni id è l'identità di una **pagina pubblicata** del progetto (non un articolo, una bozza, una pagina privata o con password, una pagina senza identità, un id di WordPress); le due pagine sono diverse. Scrive `show_on_front` = `page`, `page_on_front` e `page_for_posts`. Con `postsPage: null` la pagina degli articoli resta quella che c'è, tranne quando è la nuova home (WordPress non le mostra insieme): allora il sito resta senza. Il Registro riceve `cms_front_page` con gli id di WordPress prima e dopo.
- Il ponte non cambia i permalink: gli articoli restano dove li mette la struttura del sito (`/primo-giorno/`), mentre il sito statico li mette sotto `/blog/` (§12). Una struttura `/blog/%postname%/` sposterebbe sotto `/blog/` anche categorie e tag di WordPress.

## 19. Abbinamento in un clic

È il PKCE di OAuth, sopra il codice di abbinamento del §1.

1. Publish crea un verificatore di 32 byte, che non esce mai dal computer, e un identificativo (`state`, UUID). Apre `wp-admin/admin.php?page=xb-releases&xb_pair=<sfida>&xb_pair_state=<state>#xb-cms`, dove la sfida è lo SHA-256 del verificatore in base64url.
2. In «Collega Histeon Publish» un amministratore vede la richiesta, sceglie i permessi e conferma (`Pairing::confirm`, con nonce). Il codice nasce come al §1 e per cinque minuti resta cifrato in `xb_cms_pair_<claim>`, legato alla sfida.
3. Il pulsante «Apri Histeon Publish» porta a `histos-publish://collega?state=…&claim=…`. Il `claim` da solo non vale nulla: nessun segreto passa dal browser, dal gestore dei link o da una riga di comando.
4. Publish accetta il ritorno solo per un `state` che ha avviato e chiede il sì all'utente. Poi chiama `POST /cms/v1/pair` con `{ claim, verifier }`, l'unica rotta senza firma: il sito dà `{ code }` se lo SHA-256 del verificatore è la sfida. Il `claim` si cancella al primo tentativo. Trenta rifiuti in dieci minuti fermano la rotta (429).
5. Publish controlla che il codice sia dello stesso sito e collega come con un codice incollato.

## 20. Link di anteprima per i controlli di Publish

«Controlla il sito WP» di Histeon Publish applica axe e prova i link esterni sulle pagine che cambiano in sviluppo, nella sua finestra nascosta. Il sito gli dà gli indirizzi da aprire.

- Il sito dichiara `capabilities.previewLinks: true` quando il collegamento ha la lettura.
- **`GET /preview-links`**, con il permesso di lettura, senza parametri: `{ pages: [{ doc, title, page, url }], skipped, expires }`. Le pagine sono i documenti di `Versioning::changes()` con una pagina da vedere (un template si apre su una pagina che lo usa), al più 20 (`skipped` dice quante ne restano fuori). `url` è il link di anteprima firmato dal proprietario del collegamento, visto come `guest`, valido 10 minuti; `page` lo stesso indirizzo senza gettone, da mostrare e da salvare; `title` al più 160 caratteri. Chi chiama non sceglie post, ruolo né durata.
- Risposta `no-store`. Il Registro riceve `cms_preview_links` con il numero dei link, mai i gettoni.
- Publish tiene i link solo in memoria, apre le pagine in una finestra che carica solo dall'origine del sito e prova i link esterni lui stesso, mai dal server di WordPress.
