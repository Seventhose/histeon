# Prestazioni del sito pubblicato

Implementazione: `plugin/src/Render/Renderer.php` (`image_extras`). Test: `plugin/tests/Integration/ImageExtrasTest.php`.

## Immagini dai contenuti
Un'immagine che arriva dai contenuti (`<img src={item.image.url}>` oppure `src={item.image}`) riceve da Histeon gli attributi che l'autore non ha scritto. L'allegato si conosce dall'espressione, quindi non serve cercarlo per URL.
- **`width` e `height` dell'originale:** il browser riserva lo spazio e la pagina non salta mentre carica (CLS).
- **`srcset`:** le misure generate da WordPress. Con una misura scelta (`{item.image.medium_large}`) solo quelle non più larghe di lei: un telefono con schermo denso non scarica l'originale. Con `.url` (l'originale) tutte.
- **`sizes`:** quelle di WordPress. Se l'immagine ha `loading="lazy"`, con `auto, ` davanti: il browser usa la larghezza reale dell'immagine nella pagina e scarica il file giusto.

Vale anche per una misura registrata: `{item.image.medium_large}`, o `{item.image.sizes.medium_large.url}` nella forma dei dati di Etch, riceve le dimensioni di quella misura.

`image.sizes` ha due usi:
- come testo è l'attributo `sizes` suggerito da WordPress, come sempre;
- seguito da una misura (`.sizes.medium_large.url`, `.width`, `.height`) è quella misura.

Prima dava solo il testo, quindi le pagine importate da Etch che scrivono `image.sizes.<misura>.url` producevano immagini senza `src`. Ora hanno `src`, `width` e `height`.

Quello che l'autore ha scritto non viene toccato: si aggiunge solo ciò che manca. Le immagini statiche (`src="/…"`), che hanno già i loro attributi dalla libreria media, restano come sono. Così come un URL che non è un'immagine.

`loading`, `decoding` e `fetchpriority` li gestisce WordPress (`wp_filter_content_tags`).

## Le regole di base di WP

WP carica su ogni pagina le regole di base dei suoi blocchi (`wp-block-library`, per esempio `:where(figure){margin:0 0 1em}`). Il builder e la build di Histeon Publish non le hanno. Dalla 0.5.6 una pagina servita da un template Histeon che disegna solo blocchi Histeon non le riceve, e si vede come nel builder e in Publish. Se la pagina disegna anche un solo blocco di WP o di un altro plugin, per esempio un articolo scritto con l'editor a blocchi, le regole restano. I blocchi che contengono solo altri blocchi (`core/template-part`, `core/block`, `core/pattern`) non contano: conta ciò che hanno dentro.

Il foglio resta registrato senza file (`src` vuoto), quindi gli stili che dipendono da `wp-block-library` e il CSS aggiunto in linea si stampano ancora. Lo stesso vale per `wp-block-library-theme` e `classic-theme-styles`. Il filtro `xb_drop_wp_base_styles` con `false` lascia le regole anche sulle pagine solo Histeon. Gli stili globali del tema (`:where(body){margin:0}`) non cambiano.

Implementazione: `plugin/src/Render/WpBaseStyles.php`. Test: `plugin/tests/Integration/WpBaseStylesTest.php`; la prova di parità di Publish (`npm run test:parita-wp`) confronta le pagine con le immagini.

## Misura su un sito di prova
- **Homepage:**
  - elemento più grande disegnato a 264 ms;
  - CLS 0;
  - font precaricati;
  - un solo script, in `defer`;
  - il CSS di Histeon è il reset più quello delle classi usate nella pagina.
- **Pagina di ricerca:** le immagini dei loop avevano `srcset` ma non le dimensioni né `sizes`. Con questa modifica le ricevono.

## Misura ripetibile delle pagine pubbliche
`dev/bench-front.mjs` misura pagine pubbliche come le vede un browser: Chromium dello spazio e2e, un contesto nuovo per misura (cache fredda), la mediana di N misure, desktop 1366 px o telefono (`--mobile`). Prima delle misure una visita non contata scalda il server (opcache, cache degli oggetti), come su un sito che ha visitatori. Per ogni pagina:
- TTFB, primo disegno, elemento più grande (LCP), spostamenti (CLS), elementi del DOM, richieste;
- **byte trasferiti** di HTML, CSS e JavaScript, compressi se il server comprime: ciò che passa in rete;
- **CSS da leggere** e **JavaScript da eseguire**: file più ciò che è in linea nella pagina, non compressi. Servono a confrontare un sito che collega il foglio intero di un framework con uno che mette in linea solo le regole usate;
- immagini, font e il resto, trasferiti;
- **tempo PHP e query al database**, se la copia di prova ha `dev/bench-server.php` in `wp-content/mu-plugins`: scrive i due numeri in un commento in fondo alla pagina. Mai su un sito in produzione (dice a chiunque quanto lavora il server). Non scrive nulla nelle pagine chieste per una copia statica.

Le pagine si passano come `nome=indirizzo`, una o più, con `--runs N` per il numero di misure.

Il confronto vale se la pagina è la stessa: per esempio il sito di partenza, la sua importazione in Histeon (`plugin/src/Import/Etch/`) e la copia statica dello stesso progetto. Tre pagine: una di presentazione, un archivio con loop, un articolo.

**Come si rifà (prima di una release, come la prova di parità):**
1. Uno stack tutto per la prova: `COMPOSE_PROJECT_NAME=xb-bench XB_PORT=8823 dev/up.sh`. Se Docker non ha più reti libere, un file aggiuntivo per `COMPOSE_FILE` con `networks.default.ipam.config` e una sottorete libera.
2. Nel volume di WP: plugin e tema del sito di partenza, la cartella dei caricamenti, `dev/bench-server.php` in `mu-plugins`; il database dalla copia del sito (`wp db import`, poi `wp search-replace` dell'indirizzo); `WP_DEBUG` spento. I file del sito si copiano, non si toccano. Non usare `chown -R` su `wp-content/plugins`: dentro c'è la cartella `plugin/` del repository, montata.
3. Misura del sito di partenza, poi `wp db export` (per tornare indietro).
4. `wp xb import-etch --user=<amministratore>` (rapporto), poi con `--prepare`; la release con `\XB\Plugin::instance()->versioning->publish()`; il builder di partenza spento. Prima di misurare, confronta testo e immagini delle due pagine: con una pagina diversa il confronto non vale.
5. Misura di Histeon.
6. La copia statica: `wp xb static-build` (le tre pagine con il filtro `xb_static_paths`), servita dallo stesso Apache su una seconda porta.
7. Alla fine `docker compose down -v`: la copia del sito non resta sul disco.

Le misure del server più stabili vengono da 21 richieste dirette per pagina dopo due di riscaldamento (`curl`, mediana del commento di `bench-server.php`); le 5 visite del browser bastano per byte, richieste e DOM.

**Una misura di riferimento** (un sito di prova su Histeon 0.5.9, home, desktop, mediana di 3): TTFB 96 ms, FCP e LCP 184 ms, CLS 0,095, 682 elementi, 5 richieste, HTML 129 KB con CSS e JS in linea, CSS 85 KB, JavaScript 7 KB.
