# Libreria di sezioni

Implementazione: `plugin/src/Library/` (`Sections`, `Rest`), `editor/src/components/sections/LibrarySection.svelte`. Test: `plugin/tests/Integration/LibraryTest.php`, `e2e/specs/library.spec.ts`.

Sezioni e componenti riutilizzabili, da salvare nel sito e portare su un altro sito Histeon.

## Come funziona

- **Salva**: nella sezione **Libreria** del builder, l'elemento selezionato si salva con un nome. Si salva come *clip*, cioè il formato del copia e incolla tra siti (`lib/clip.ts`): il markup e gli stili delle classi che usa.
- **Inserisci**: una sezione della libreria passa dalla stessa finestra di **Incolla** dei clip da un altro sito. Lì le classi sono nuove, uguali o in conflitto, e in caso di conflitto si sceglie se rinominare o tenere. Poi l'inserimento passa dal salvataggio normale del documento, con la fiducia di chi inserisce: per chi non ha `unfiltered_html` vale il filtro solito.
- **Esporta / Importa**: tutta la libreria in un file `.xblibrary` (JSON con formato, versione, sito, sezioni e hash SHA-256). Importando, una sezione che c'è già (stesso id) viene saltata, quindi lo stesso file due volte non aggiunge nulla.

## Regole

- Solo chi ha `xb_build` usa le rotte:
  - `GET` e `POST /sections`;
  - `GET` e `DELETE /sections/{id}`. Eliminare una sezione è permesso a chi l'ha salvata, oppure a chi ha `xb_publish`; agli altri risponde 403;
  - `GET /sections/export`;
  - `POST /sections/import`.
- Un clip si accetta solo con la forma di `makeClip()` (`xb: clip`, `version: 1`, origine, markup, stili `selector`/`css`) e al massimo 2 MB. Si salva **ricostruito** da quei campi, quindi ogni campo in più viene scartato.
- Al massimo 200 sezioni. L'elenco si invia senza i clip, che si leggono uno alla volta.
- Importare un file controlla l'hash e ogni clip prima di aggiungere qualcosa: un file alterato o una sezione non valida rifiuta tutto il file.
- Importazione ed eliminazione finiscono nel Registro (`library_import`, `library_delete`).

- **Immagini.** Il file `.xblibrary` porta le immagini caricate sul sito che le sezioni usano, in `media`, con il loro hash (`media_hash`):
  - solo JPEG, PNG, GIF, WebP e AVIF, al massimo 5 MB l'una e 20 MB in tutto;
  - un'immagine compare una volta, con tutti gli indirizzi usati (anche le copie ridimensionate).

  All'importazione, per chi ha `upload_files`:
  - le immagini passano dai controlli di WordPress sul tipo reale del file;
  - un'immagine già arrivata con gli stessi byte viene riusata (`_xb_import_source`);
  - gli indirizzi nelle sezioni nuove diventano quelli di questo sito.

  Niente viene scaricato da un indirizzo scritto in una sezione. Un elenco di immagini alterato viene ignorato: le sezioni arrivano, le immagini no.

## Limiti

- Copiando e incollando una sezione fra due siti, le immagini restano agli indirizzi del sito d'origine. Con l'esportazione e l'importazione della libreria arrivano con il file.
- Un componente usato da una sezione deve esistere nel sito che la riceve, come nell'incolla.

## In Gutenberg

Il pannello **Histeon · Libreria** (barra laterale del plugin nell'editor a blocchi, voce nel menu «⋮») elenca le sezioni, con la ricerca. **Inserisci** fa ciò che fa l'incolla del builder con le scelte di serie: la clip passa da `restrictClip` (il server toglie ciò che chi inserisce non può scrivere), i blocchi prendono uid nuovi, le classi nuove portano i loro stili, quelle uguali usano quelle del sito, quelle in conflitto si importano con un nome nuovo. Gli stili si salvano subito in sviluppo; i blocchi entrano dopo il blocco selezionato (o in fondo). Un avviso dice quali classi sono state rinominate. Il cliente non ha il pannello.

**Parti BEM.** Rinominare una classe in conflitto rinomina con lei le sue parti (`card__title`, `card--dark` → `card-2__title`, `card-2--dark`), negli elementi e nei selettori: il CSS annidato del blocco (`&__title`) le stila con il nome nuovo. Vale anche per l'incolla del builder, che prima lasciava le parti col nome vecchio e senza stile.

Implementazione: `editor/src/gutenberg/library.ts` (`planInsert()`), `editor/src/lib/clip.ts` (`renameClasses`, `renameSelector`). Test: `editor/test/gutenberg-appearance.test.ts`, `e2e/specs/gutenberg-library.spec.ts`.

## Una sezione come istruzioni per l'agente

`editor/src/lib/sectionBrief.ts` legge il clip di una sezione e ne fa istruzioni invece di markup: l'albero degli elementi con il ruolo (titolo, paragrafo, link, immagine…) e il ruolo BEM della classe (blocco, parte, variante), loop, condizioni e componenti; le dichiarazioni di disposizione al primo livello di ogni selettore (display, griglia, gap, padding, testo, colore, raggi…) con accanto il token di questo progetto più vicino a ogni valore fisso (`nearestToken`: lunghezze entro il 25% fra le variabili del gruppo giusto, colori uguali). Le regole annidate restano fuori.

- Nel builder, **Fai ricostruire all'agente** accanto a una sezione mette nel pannello ✦ una richiesta «Ricostruisci in questa pagina la sezione …», con le istruzioni del builder e il brief.
- Per l'agente, `listSections` e `getSectionBrief` (CLI `sections`, `section-brief <id>`; MCP `xb_listSections`, `xb_getSectionBrief`), in sola lettura.
