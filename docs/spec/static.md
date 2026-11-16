# Sito statico e Cloudflare — v0.1

Implementazione: `plugin/src/StaticSite/`, `plugin/src/Deploy/`, `plugin/src/Forms/`, `plugin/assets/worker/worker.js`.
Test: `plugin/tests/Integration/DeployTest.php`, `FormsTest.php`, `packages/spec/test/worker.test.ts`. Il mock dell'API Cloudflare per i test è in `dev/cloudflare-mock/`.

## 1. Export
1. **URL raccolti:** home, contenuti pubblici visibili (senza password), archivi dei tipi di post, termini delle tassonomie pubbliche, indice del blog, archivi d'autore (autori con articoli pubblicati) e archivi per anno e mese. Ogni archivio porta con sé le pagine successive (`/page/2/`…), calcolate da `posts_per_page`, fino a 50 per archivio (filtro `xb_static_archive_pages`). Con i permalink semplici le pagine successive sono query string e restano fuori, come ogni URL con query string. Filtro: `xb_static_paths`. Restano fuori, con WooCommerce attivo, le sue pagine di carrello, cassa e account: hanno nonce di sessione o rimandano altrove a carrello vuoto, e restano dinamiche in WordPress (`ecommerce.md`). Un archivio che il sito non serve, per esempio perché un plugin SEO spegne gli archivi d'autore, non entra: l'export tiene solo le risposte 200.
2. **Render:** richiesta di loopback **anonima** (senza cookie), con `X-XB-Export: <scadenza>.<hmac>`.
   - WordPress risponde ripetendo il token in `X-XB-Export-Render`: una risposta senza quel token (per esempio servita da una cache) viene scartata.
   - In modalità export vengono rimossi la discovery REST e XML-RPC, shortlink, oEmbed, generator, emoji e admin bar.
   - Origine del loopback: costante `XB_EXPORT_ORIGIN` in wp-config (es. `http://127.0.0.1`), con l'header `Host` del sito pubblico.
3. **Link:** gli URL assoluti del sito diventano relativi alla radice, così la copia funziona su qualsiasi dominio.
4. **Asset:** copiati **dal disco**, mai tramite PHP, con questi vincoli:
   - solo percorsi sotto `/wp-content/` o `/wp-includes/`;
   - solo estensioni in allowlist (mai `.php`);
   - `realpath` deve restare in quelle cartelle, quindi niente traversal, nemmeno `%2e%2e`, né symlink verso l'esterno;
   - al massimo 25 MB per file;
   - da uploads passano solo i media della libreria non legati a contenuti non pubblicati, con le dimensioni che WordPress ha generato. «Da uploads» si decide dal **percorso reale** del file (`realpath` dentro il `realpath` della cartella degli upload), oltre che dall'indirizzo: un symlink sotto plugin o temi che punta negli upload, un `upload_url_path` o un CDN diversi, e su multisite il file del sito principale o di un altro sottosito citato da una pagina passano dallo stesso controllo, fatto sulla libreria del sito a cui il file appartiene (`uploads/sites/<id>/`, `blogs.dir/<id>/files/`; il resto di `uploads/` è del sito principale). Anche `wp-content/uploads/` conta sempre come upload, qualunque sia `upload_path`;
   - i CSS vengono scansionati in modo ricorsivo per `url()` e `@import`.
5. **`404.html`:** si ottiene renderizzando un percorso inesistente.
6. **Bundle:** in `uploads/xb-static/<etichetta>-<data>/`, cartella non navigabile.

## 2. Scansione pre-deploy
| Regola | Gravità |
|---|---|
| Percorso del server (`ABSPATH`) | blocca |
| Token di anteprima `xb_preview=` | blocca |
| Annotazioni del builder `data-xb-uid` | blocca |
| Nonce WordPress (`_wpnonce=`, `"nonce":"…"`, `X-WP-Nonce`), tranne negli script e negli stili che WordPress, i plugin e i temi distribuiscono (`wp-includes/`, `wp-content/plugins/`, `themes/`, `mu-plugins/`): sono copiati dal disco, uguali per ogni visitatore, e librerie come `api-fetch` nominano l'intestazione nel codice. Upload e cache restano controllati | blocca |
| Link a `wp-admin`, `wp-login.php`, `/wp-json/` | avviso |
| Email dell'amministratore | avviso |

Con anche una sola regola che blocca, il deploy non parte e nel registro dei deploy resta lo stato `blocked`.

## 3. Cloudflare Workers Static Assets
- **Credenziali:**
  - costanti `XB_CLOUDFLARE_ACCOUNT_ID`, `XB_CLOUDFLARE_SCRIPT`, `XB_CLOUDFLARE_API_TOKEN` in wp-config (consigliato);
  - oppure Impostazioni → Histeon: il token viene salvato cifrato con sodium secretbox e una chiave derivata da `AUTH_SALT`, e non viene mai restituito da REST né mostrato nella pagina.
  - Permesso minimo del token: *Account › Workers Scripts › Edit*.
  - L'URL dell'API si cambia solo con la costante `XB_CLOUDFLARE_API_BASE` (usata dal mock): niente nel database può dirottare il token altrove.
- **Flusso:**
  1. manifest `{percorso: {hash, size}}`, con `hash` = primi 32 caratteri esadecimali di `sha256(base64(contenuto) + estensione)`;
  2. `assets-upload-session`: Cloudflare indica quali hash mancano, e solo quelli vengono caricati (i file invariati non si ricaricano);
  3. upload a gruppi, autenticato con il JWT della sessione;
  4. nuova **versione del Worker**, con le annotazioni `workers/tag` (versione della release Histeon) e `workers/message`, e `keep_bindings: ["secret_text"]`;
  5. deploy al 100%.

  Al primo deploy lo script viene creato con `PUT`.
- **Rollback istantaneo:** si rimette al 100% una versione precedente, senza ricostruire nulla (`POST /xb/v1/deploy/{id}/rollback`).
- **Chi mette online:** `POST /xb/v1/deploy` e il rollback chiedono `xb_build` + `xb_publish` e rifiutano le password per applicazioni (403 `xb_app_password`): si lanciano dal builder o con WP-CLI.
- **Contenuti ritirati e rollback**. Togliere un contenuto dalla pubblicazione in WordPress (bozza, in attesa, privato, programmato, Cestino) non crea una release, quindi la copia su Cloudflare lo serve fino al deploy successivo; e una versione precedente, rimessa online, serve ciò che era pubblicato quando è stata generata. Per questo:
  - quando un contenuto lascia `publish` su un sito con Cloudflare configurato, Histeon ne segna l'ora nel meta `_xb_withdrawn`; per i contenuti messi nel Cestino prima di questa regola vale il meta del Cestino di WordPress (`_wp_trash_meta_status` = `publish`, `_wp_trash_meta_time`);
  - il pannello delle versioni (`GET /xb/v1/deploy`, campo `withdrawn` delle ultime versioni ripristinabili) mostra accanto a «Rimetti online» quanti contenuti ritirati dopo quella versione tornerebbero online, e la conferma li nomina (i primi 10, gli altri contati);
  - il rollback (REST e `wp xb deploy-rollback`) li ripete nei `warnings` della risposta.
  Un contenuto conta se era pubblicato quando la versione è stata generata (data di pubblicazione non successiva) ed è stato ritirato dopo; per il rollback di un rollback vale la versione originale. Un contenuto cancellato per sempre non è più nel database e non si elenca.
  - **Il rollback non li ritira da solo**, e un ritiro non fa partire un deploy: è voluto, perché un deploy a ogni cambio di stato costerebbe una ricostruzione intera del sito. **Per toglierli a mano:** dopo il rollback, o subito dopo aver ritirato un contenuto urgente, **Pubblica ora su Cloudflare** (pannello Versioni) oppure `wp xb deploy`: il nuovo deploy genera solo ciò che è pubblicato adesso.
- **Deploy automatico:** se attivo, ogni release Histeon pianifica un deploy in background (WP-Cron), così la pubblicazione non rallenta. Gli errori finiscono nell'audit.
- **Siti grandi:** si usano `wp xb static-build`, `wp xb deploy`, `wp xb deploys` e `wp xb deploy-rollback <id>`.
- **Limiti:** 20.000 file per versione (filtro `xb_cloudflare_max_files`, da alzare se il proprio account Cloudflare ne ammette di più) e 25 MB per file.
- **Intestazioni di sicurezza** (`StaticSite/SecurityHeaders.php`). Sono un elenco chiuso:
  - `X-Content-Type-Options: nosniff`;
  - `X-Frame-Options: SAMEORIGIN`;
  - `Referrer-Policy: strict-origin-when-cross-origin`;
  - `Permissions-Policy` (fotocamera, microfono, posizione e pagamenti spenti);
  - `Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'`. Una CSP con `script-src` romperebbe gli script in linea delle pagine.

  Il filtro `xb_static_security_headers` cambia i valori, o spegne un'intestazione con un valore vuoto, ma non ne aggiunge altre. Un valore con caratteri non stampabili o andate a capo viene scartato.

  Le intestazioni arrivano su tre strade:
  - nel deploy su Workers, come `assets.config._headers` della versione, per le pagine e i file serviti direttamente;
  - come binding `XB_SECURITY_HEADERS`, per le risposte del Worker (i form);
  - come file `_headers` nello ZIP, per gli host che lo leggono (Cloudflare Pages, Netlify).

  Nel deploy il file `_headers` non viene caricato fra i file serviti.

## 4. Form
1. Il markup del form punta a `POST /xb-form` e contiene il widget Turnstile. Campi di controllo: `xb_form` (nome) e `xb_redirect` (solo percorsi interni).
2. Il **Worker** (`assets/worker/worker.js`):
   - accetta solo `POST` con corpo fino a 32 KB;
   - verifica Turnstile lato server;
   - tiene al massimo 50 campi con nomi validi, ciascuno al massimo di 5000 caratteri;
   - inoltra a `XB_FORMS_ENDPOINT` un JSON firmato HMAC-SHA256, con timestamp e id univoco;
   - fa redirect solo verso percorsi dello stesso sito.
3. **WordPress** (`POST /xb/v1/forms`):
   - verifica la firma in tempo costante;
   - accetta solo timestamp entro 5 minuti;
   - rifiuta un id già visto (anti-replay);
   - salva il messaggio come testo in un tipo di post privato, leggibile solo dagli amministratori;
   - invia una notifica email.
4. **Segreti:** il segreto di firma e quello di Turnstile vengono inviati a Cloudflare come *secret* del Worker (Impostazioni → Histeon) e non compaiono mai nei file statici.

## 5. Static Bridge
Vedi `docs/integration/static-bridge.md`.

## 7. Scarica il sito statico (zip)
In **Histeon → Release**, sezione **Sito statico**, gli amministratori trovano **Scarica il sito statico (zip)**:
1. Il sito si genera come per un deploy: visitatore anonimo, modalità export, risorse copiate dal disco.
2. Lo Scanner lo controlla. Se trova problemi bloccanti (dati privati o di sessione), lo zip non viene creato.
3. Lo zip si scarica.

Il bundle e lo zip restano nell'area di lavoro protetta (`uploads/xb-static/`) solo per il tempo del download, poi vengono cancellati. Lo zip non contiene file nascosti né PHP.

Test: `plugin/tests/Integration/StaticZipTest.php`.
