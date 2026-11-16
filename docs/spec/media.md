# Gestore dei media — v0.1

Implementazione:
- `plugin/src/Media/Collections.php`, `plugin/src/Media/Usage.php`;
- `editor/src/components/MediaPicker.svelte`;
- `editor/src/lib/mediaManager.ts`;
- `editor/src/lib/media.ts` (`mediaKind`, `mediaElement`, `pageImages`, `focalPoint`), `AltTable.svelte`, `InfoPanel.svelte`.

Test:
- `plugin/tests/Integration/MediaCollectionsTest.php`, `plugin/tests/Integration/MediaUsageTest.php`;
- `editor/test/mediaManager.test.ts`;
- `editor/test/media.test.ts`;
- `e2e/specs/media.spec.ts`, `e2e/specs/media-files.spec.ts`, `e2e/specs/alt-table.spec.ts`.

Lavora sulla libreria di WordPress.

## Raccolte
- Cartelle annidate per i media: la tassonomia `xb_media_collection` sugli allegati.
- **Privata:** niente pagine pubbliche, niente variabile di query.
- **Permessi:** si crea, si modifica e si assegna con `upload_files`, tramite l'API REST di WordPress (`wp/v2/xb_media_collection` e il campo dell'allegato).
- **Nel builder:** la colonna a sinistra mostra Tutte le immagini, Senza raccolta e l'albero delle raccolte con i conteggi. Una nuova raccolta nasce dentro quella selezionata.

## Caricamento
- Più file alla volta (fino a 20), ciascuno con la sua barra di avanzamento. I file vanno nella raccolta aperta.
- **Compressione nel browser**, prima dell'invio:
  - preset **Web**: lato massimo 2560 px, AVIF o WebP;
  - preset **Leggera**: lato massimo 1600 px, AVIF o WebP;
  - preset **Originale**.
- **Il codificatore** è quello di Histeon Publish: jSquash, cioè i codec di Squoosh in WebAssembly, alle stesse versioni e con le stesse opzioni.
  - Opzioni: AVIF qualità 60 velocità 8, WebP qualità 75 metodo 4. Il ridimensionamento usa Lanczos3.
  - Gira in un Web Worker (`editor/src/media/encoder.worker.ts`, compilato in `plugin/assets/builder/media/`), con i soli codec a un thread: la pagina non è isolata cross-origin.
  - Il browser decodifica (`createImageBitmap`): segue l'orientamento EXIF e porta i colori in sRGB.
  - Si prova ogni formato del preset e si tiene il più leggero, solo se pesa meno dell'originale; altrimenti parte l'originale.
  - **Sorgenti senza perdita** (PNG, WebP senza perdita): mai AVIF, come in Publish. Si prova WebP senza perdita e anche WebP con perdita, come faceva il builder prima. Qui il builder differisce da Publish di proposito: una grafica ridotta, senza perdita, pesa spesso più dell'originale, che altrimenti partirebbe a piena misura.
- **Limiti, letti dall'intestazione prima di decodificare** (`lib/imageHeader.ts`):
  - partono come sono i file oltre 32 MB, oltre 40 megapixel (bombe di decompressione), con un'intestazione che non dice le misure, e i PNG e WebP animati;
  - le misure decodificate devono essere quelle dell'intestazione;
  - un'immagine ha 60 secondi, poi il worker si chiude;
  - un worker fermo da 20 secondi si chiude e libera la memoria dei codec.
- **CSP:** la pagina permette un solo worker, `worker-src` con il percorso esatto di `encoder.js`, mai `unsafe-eval` né `wasm-unsafe-eval`. Il WebAssembly si compila dentro il worker, che ha la CSP della sua risposta (i file statici del plugin di solito non ne hanno).
  - I `.wasm` li serve il plugin accanto a `encoder.js`, mai una CDN.
  - Il worker manca quando il plugin è servito da un'altra origine, perché un worker di modulo deve essere della stessa origine: allora resta `worker-src 'none'`.
- **Senza il worker** (non compilato, altra origine, nessun WebAssembly, errore) si usa il WebP del browser (canvas, qualità 0,75). Prova: `e2e/specs/media-encoder.spec.ts`, in Chromium e Firefox.
- GIF (animazioni) e SVG partono come sono.
- **Licenze:** jSquash e `wasm-feature-detect` Apache-2.0; nei moduli libavif (BSD-2-Clause), libaom (BSD-2-Clause e Alliance for Open Media Patent License 1.0), libwebp (BSD-3-Clause), il crate `resize` (MIT). I testi vanno in `plugin/assets/builder/media/licenses/` a ogni build; quelli delle librerie compilate stanno in `editor/licenses/`. Vedi `docs/policy/PROVENANCE.md`.
- I limiti e i controlli di WordPress valgono come sempre: tipi ammessi, dimensione massima, `upload_files`.

## Preset del sito
Oltre a Web, Leggera e Originale, il sito ha i suoi preset: **Preset…** accanto al menu della compressione. Ognuno ha un nome, il lato più lungo (0 = misura originale), i formati provati al caricamento (AVIF, WebP) e la qualità dei file con perdita (30-95; i senza perdita restano come sono). Stanno nell'opzione `xb_media_presets` (`plugin/src/Media/Compress.php`, `GET`/`POST xb/v1/media/presets`, con `upload_files`, al massimo 10, ripuliti dal server). L'ultimo preset usato resta scelto in quel browser (`localStorage`, solo comodità).

## Comprimere un'immagine già caricata
Nei dettagli di un'immagine JPEG, PNG, WebP o AVIF, **Comprimi con «preset»** la rende più leggera al suo posto:
- il builder scarica il file, lo riduce al lato più lungo del preset e lo riscrive **nello stesso formato** (JPEG con il codificatore del browser alla qualità del preset, 82 se non la dice; PNG senza perdita, quindi utile solo se si riduce; WebP e AVIF con il codificatore di Histeon), così l'indirizzo non cambia;
- se non è più leggero non manda nulla e lo dice;
- il server (`POST xb/v1/media/{id}/compress`, con `upload_files` e `edit_post` sul file) accetta solo un'immagine dello stesso formato, con le stesse proporzioni (1%), non più grande e più leggera; la mette al posto del file, ridisegna ogni misura di WordPress **con il suo nome e la sua misura** dal file nuovo (le pagine e i `srcset` che le nominano restano validi; una misura più grande del file nuovo resta com'era) e tiene l'originale accanto, `foto.xb-originale.jpg`;
- **Ripristina l'originale** (`POST xb/v1/media/{id}/restore`) rimette l'originale e le sue misure e cancella la copia;
- il campo REST `xb_compressed` dell'allegato dice pesi prima e dopo e quando; Registro `media-compress` e `media-restore`.
L'agente lo fa con `compressMedia` (`docs/spec/js-api.md`).

## Dettagli
Il pulsante **⋯** di un'immagine apre una scheda con:
- testo alternativo, salvato sull'allegato;
- raccolte a cui appartiene;
- **Usa questa immagine**.

## La libreria dalla barra
Il pulsante **Libreria dei media** nella barra a sinistra, e il comando omonimo della palette, aprono lo stesso gestore su tutti i tipi di file. Un clic su un file non lo inserisce: apre i dettagli con tipo, dimensioni, peso (`media_details.filesize`), data, indirizzo da copiare, testo alternativo (immagini), didascalia (letta con `context=edit`: solo per chi può modificare il file), raccolte e **Dove è usato**. **Inserisci nella pagina** lo mette dopo la selezione, come dal menu +; **Apri in WordPress** apre `post.php?post=…&action=edit`.

**Dove è usato:** `GET xb/v1/media/{id}/usage`, per chi ha `upload_files`. Cerca nei contenuti (tutti i tipi tranne allegati e revisioni; pubblicati, bozze, in attesa, privati, programmati) il percorso del file sotto `uploads` senza estensione, anche con le barre scritte `\/` come negli attributi dei blocchi, e conferma con un'espressione: le sue dimensioni (`-300x150`), la copia `-scaled`, quella modificata (`-e123`), l'estensione originale o WebP/AVIF; un nome che comincia allo stesso modo è un altro file. Aggiunge i contenuti che lo hanno come immagine in evidenza. Al più 200 contenuti letti. Risponde `{items: [{id, title, type, status, featured, link}], hidden}`: i contenuti che l'utente non può modificare sono solo contati in `hidden`. Guarda il contenuto salvato in WordPress (per Histeon, quello pubblicato).

## Video, audio e PDF
Dal menu + c'è anche **Video, audio o PDF dalla libreria…**: lo stesso gestore, con un filtro **Tipo di file** (tutti, immagini, video, audio, PDF; per il PDF il filtro REST è `mime_type=application/pdf`). Si caricano anche questi tipi, senza compressione; restano i limiti di WordPress sui tipi ammessi. Quello che si sceglie diventa:
- un'immagine: `<img>` come sempre;
- un video: `<video src controls preload="metadata">`, con `width` e `height` se la libreria li conosce;
- un audio: `<audio src controls preload="metadata">`;
- un PDF: un link `<a href>` al file, con il titolo come testo.

Solo URL `http(s)`; testo e indirizzi entrano come testo letterale (graffe con `\`).

## Testi alternativi della pagina
**Testi alternativi della pagina** (dalla palette, o dai Controlli quando manca un alt) mostra in una tabella tutte le immagini della pagina: miniatura, file, testo alternativo modificabile e **Decorativa** (`alt=""`). **Solo senza testo alternativo** filtra quelle da sistemare. Un alt o un'immagine che vengono dal contenuto (espressioni) si vedono ma si cambiano nel pannello HTML o nel contenuto. Ogni modifica è un passo di Annulla.

## Punto focale
Nelle Proprietà di un'immagine con un indirizzo fisso, **Punto focale** mostra l'immagine intera: un clic sul punto da tenere in vista lo scrive come `object-position: X% Y%` nello stile in linea dell'immagine (le altre dichiarazioni restano); le frecce lo spostano di 5 punti (Maiusc: 20); **Al centro** lo toglie. Serve quando il riquadro ritaglia l'immagine (`object-fit: cover`).

