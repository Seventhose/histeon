# Gli stili

In Histeon lo stile si scrive in CSS, legato a un selettore: una classe o un id. Il builder ti aiuta con l'annidamento come in SCSS, una barra di comandi visuali (la Mini GUI), le media query con nome e un gestore dei selettori. Ogni pagina riceve solo il CSS che usa davvero.

## Classi e selettori

Gli stili di Histeon vivono in due posti:

- **il CSS dei selettori:** una voce per ogni classe o id a cui hai dato uno stile, per esempio `.card` o `#contatti`. È il posto giusto per quasi tutto;
- **i fogli globali:** il CSS che vale per tutto il sito, come un reset, le variabili in `:root` o lo stile dei tag nudi.

Gli stili valgono in tutto il sito: lo stesso `.card` ha lo stesso aspetto in ogni pagina che lo usa. Come i contenuti, si salvano in sviluppo e vanno online con la prossima release.

## Il pannello CSS

Seleziona un elemento: in cima al pannello **CSS** compare una pillola per ogni sua classe, una per il suo id (se ne ha uno) e una per il **Foglio globale**. Scegli la pillola e scrivi.

Nel pannello si scrive solo il **corpo** della regola, senza ripetere il selettore:

```css
padding: var(--space-m);
border-radius: var(--radius-m);
```

Se l'elemento non ha classi, il pannello ti chiede di aggiungerne una: scrivila nel campo (per esempio `hero`) e premi **Aggiungi classe**. Il campo propone già un nome, preso dal blocco con una classe più vicino e da ciò che l'elemento è (per esempio `hero__title` per un titolo dentro `.hero`): con il campo vuoto, **Aggiungi classe** usa quello.

Un link, un grassetto o uno `span` dentro una frase fanno parte del testo: si modificano scrivendo nel paragrafo. Per stilarne uno da solo, fai **Alt+clic** su di esso nella tela: diventa un elemento con una classe propria (per esempio `hero__link`), selezionato e pronto per il pannello CSS.

Mentre scrivi, l'editor ti aiuta:

- **variabili del framework:** scrivendo `--sp` si apre l'elenco delle variabili; fuori da `var()` il completamento inserisce `var(--space-m)`;
- **abbreviazioni** all'inizio di una dichiarazione: `df` (display: flex), `dg` (display: grid), `jcc`, `aic`, `mxa`, `w100` e altre;
- **ricette:** `@` seguito dal nome, per esempio `@centra`, si espande in un gruppo di dichiarazioni pronte (vedi [le ricette di Histeon CSS](#it-histos-css-le-ricette));
- **media query con nome:** dopo `@media (` vengono proposti i nomi del sito.

Mentre scrivi, il pannello sottolinea ciò che non va: graffe non chiuse, variabili e media query con nome che il sito non ha, `!important`, dichiarazioni senza valore, valori fissi uguali a un token (con il token da usare) e classi fuori dallo schema BEM. Passa con il mouse sulla sottolineatura per leggere il motivo. Gli stessi avvisi, per i selettori della pagina, sono nei **Controlli**, area **CSS**.

## Annidare come in SCSS

Dentro una regola puoi annidare stati, parti, varianti e media query:

```css
padding: var(--space-m);
&:hover { color: var(--primary); }
@media (--tablet) { padding: var(--space-l); }
&__title { font-weight: 700; &--big { font-size: 2em; } }
&--dark { background: #000; }
```

Su `.card` questo produce anche `.card__title`, `.card__title--big` e `.card--dark`: le regole che iniziano con `&__` o `&--` diventano regole sorelle con il nome concatenato, secondo la convenzione BEM. Ognuna arriva in una pagina solo se la pagina usa quella classe.

## La Mini GUI

La Mini GUI è una barra compatta sopra l'editor CSS che scrive per te le dichiarazioni più usate, mentre guardi l'elemento nella tela. Il pulsante **Mini GUI** la apre e la chiude; la scelta resta in questo browser.

| Gruppo | Proprietà |
|---|---|
| **Layout** | display, direzione, a capo, colonne della griglia (2, 3, 4, auto), allineamenti, gap |
| **Spazi** | padding, padding verticale e orizzontale, margini |
| **Dimensioni** | larghezza, larghezza massima, altezza minima, proporzioni, adattamento delle immagini |
| **Testo** | dimensione, peso, interlinea, allineamento, a capo bilanciato, colore |
| **Colori e bordi** | sfondo, raggio, ombra, bordo, opacità |
| **Posizione** | posizione, distanze, livello, overflow, tipo di contenitore |

Come funziona:

- ogni pulsante scrive una dichiarazione vera nel CSS del selettore, e la tela la mostra subito; un secondo clic sul valore attivo la toglie;
- un gruppo mostra solo ciò che ha effetto in quel momento: gli allineamenti solo con flex o grid, le distanze solo con una posizione impostata. «+N proprietà che ora non servono» mostra le altre;
- spazi, testi, raggi, ombre e colori vengono dalla scala del framework attivo, sempre come `var(--…)`;
- un puntino corallo segna i gruppi che hanno già dei valori;
- le regole annidate che hai scritto a mano restano come sono.

## Stato e larghezza

Accanto alla Mini GUI, due menu scelgono **dove** scrivere (● segna le voci che hanno già dichiarazioni):

- **Stato:** Normale, `:hover`, `:focus-visible`, `:active`, `::before`, `::after`;
- **Larghezza:** **Tutte**, **Come la tela**, le media query con nome del sito, oppure la larghezza attuale della tela (per esempio `≤ 1104px`).

La Mini GUI scrive dentro il blocco annidato giusto, per esempio `&:hover { … }` o `@media (--tablet) { &:hover { … } }`, lo crea se manca e lo toglie quando resta vuoto. Mentre uno stato è scelto, la tela mostra l'elemento in quello stato, come se il mouse ci fosse sopra.

Quando porti la tela a una certa larghezza, la Mini GUI la segue da sola: è [la tela che segue](#it-il-builder-la-tela-che-segue), descritta nel capitolo sul builder. Scegliere un'altra larghezza a mano smette di seguirla; **Come la tela** torna a seguirla.

## Altro: query alla larghezza e azioni rapide

Il pulsante **Altro ▾** apre due gruppi di comandi.

**Alla larghezza della tela** (per esempio «A 720px»):

- **@media fino a qui** aggiunge un blocco `@media (width <= 720px) { }`;
- **@media da qui in su** aggiunge `@media (width >= 720px) { }`;
- **@container fino a qui** aggiunge `@container (width <= 720px) { }`, che segue la larghezza del contenitore (serve un antenato con `container-type`).

**Azioni rapide:**

- layout: **Flex riga**, **Flex colonna**, **Griglia 2**, **Griglia 3**, **Griglia 4**, **Griglia automatica**, **Centra**, **Spazio tra**, **Pila centrata**;
- spazi: gap, padding o margine con la scala del framework;
- **px → rem:** converte i valori in pixel in rem (base 16 px).

Le dichiarazioni che ci sono già vengono aggiornate al loro posto.

## Media query con nome

Invece di ripetere `(width < 64em)` in cento regole, dai un nome alla condizione e usa il nome. Le trovi nella sezione **Stili e selettori → Media query con nome**, e ne aggiungi con **+ Media query**.

| Nome | Condizione predefinita |
|---|---|
| `mobile` | `(width < 40em)` |
| `tablet` | `(width < 64em)` |
| `desktop` | `(width >= 64em)` |

Si usano così, anche nelle container query:

```css
@media (--tablet) { padding: var(--space-s); }
@container card (--tablet) { flex-direction: column; }
```

Cambiando la condizione in un posto solo, cambia ovunque. Le media query con nome compaiono anche come pulsanti sopra la tela e nel menu **Larghezza** della Mini GUI. Hanno versioni come il resto: vanno online con la release. Un nome sconosciuto resta com'è scritto e il browser ignora quella regola.

## I fogli globali

La pillola **Foglio globale** apre il CSS che vale per tutto il sito. Scrivici solo ciò che è davvero globale: reset, variabili in `:root`, stile dei tag. Lo stile di un blocco sta meglio nel suo selettore. Con **CSS completo** vedi, in sola lettura, il foglio e il CSS di ogni selettore come arrivano nella pagina.

### Dal foglio al selettore

Nei siti importati, per esempio da Etch, buona parte del CSS può stare in un foglio globale. Quando selezioni un elemento la cui classe ha regole nel foglio, il pannello mostra due viste:

- **Nel foglio (N):** solo le regole che nominano quella classe, una per editor, con il loro contesto (`@media …`). Ogni modifica torna nel foglio esattamente al posto di quella regola;
- **CSS proprio:** il CSS del selettore.

In cima alla vista **Nel foglio**, **Come SCSS nel selettore** mostra quelle regole già raccolte in forma annidata. **Porta nel selettore** le toglie dal foglio e le mette nel CSS della classe, in un solo passo da annullare con [[Ctrl+Z]]. Da lì in poi la classe si legge e si modifica in un posto solo, con Mini GUI, stati e larghezze.

Per fare lo stesso con tutti i blocchi in una volta, usa **Nei selettori…** (sulla pillola del foglio o nella sezione **Stili e selettori**): un'anteprima dice quanti blocchi e regole si spostano e quali restano, poi **Sposta** fa tutto in un passo.

> Una regola resta nel foglio quando spostarla cambierebbe quale regola vince, o quando è condivisa con altre classi (`.a, .b`). Per questo la pagina non cambia aspetto. Prima di pubblicare, il confronto visivo della finestra **Pubblica…** te lo conferma.

## Il gestore dei selettori

La sezione **Stili e selettori** elenca tutti i selettori del sito, anche le classi definite solo nei fogli globali.

- **Cerca un selettore**, ordina per **Nome**, **Uso** o **Dimensione**, filtra per origine (**Tutti**, **Voci**, **Fogli globali**) o mostra **Solo inutilizzati**.
- Per ogni selettore vedi dove è usato e il suo CSS, modificabile come nel pannello CSS.
- **Rinomina ovunque** cambia il nome della classe e delle sue parti BEM (`card` in `box` cambia anche `card__title` e `card--dark`, ma non `card-alt`) negli stili, nei fogli globali e in tutte le pagine. Prima vedi un'anteprima; la rinomina viene rifiutata, con il motivo, se il nuovo nome è già in uso o se la classe compare dove non puoi modificarla, per esempio nei file del tema.
- Se la classe compare anche nell'HTML libero (blocchi di HTML scritti a mano, blocchi di Gutenberg), l'anteprima mostra ogni punto con il prima e il dopo: spunta quelli da rinominare insieme al resto. Serve il permesso di scrivere HTML senza filtri.
- **Elimina selettore** ed **Elimina inutilizzati** tolgono solo le voci che nessun documento, template o safelist usa.

- Una classe che aggiunge solo uno script, una prop o un widget sembra inutilizzata: **Usata da script, props o widget: tienila sempre** la mette nella safelist (serve il permesso di cambiare le impostazioni), e **Togli dalla safelist** la toglie.

> Rinomina ed eliminazione lavorano sugli stili salvati: con modifiche non salvate, **Salva ora** (nella nota) o **Salva ora e rinomina** (dopo l'anteprima) salvano prima. Diventano modifiche in sviluppo, quindi vanno online con la release e si possono annullare. Il gestore non vede le classi aggiunte da script, props o widget: controlla prima di eliminare.

## Il file unico

La sezione **File unico** mostra la pagina, o il componente, come un solo file, sul modello dei file di Vue e Svelte:

- `<template>`: l'HTML, lo stesso del pannello HTML;
- `<style>`: una regola per ogni selettore usato nel documento che ha un CSS proprio, con media query e stati dentro;
- `<script>`: il JavaScript del documento (modificabile solo con il permesso di scrivere HTML senza filtri);
- per i componenti, le props in JSON.

**Applica** porta tutto nel documento insieme, dopo aver controllato il file intero: se c'è un errore, ti dice la riga e non cambia nulla. **Ricarica dal documento** riprende il documento com'è. Le modifiche vanno in sviluppo con il prossimo salvataggio.

> Gli stili valgono in tutto il sito: togliere una regola dal file non cancella lo stile, smette solo di comparire qui.

## Il CSS che arriva nelle pagine

Histeon manda in una pagina **solo il CSS che può usare**: una regola arriva se nella pagina c'è un elemento con le classi e gli id che nomina. Vale per i tuoi selettori, per i fogli globali e, di serie, anche per il foglio del framework. Le regole sui tag, le variabili in `:root`, i font e le animazioni arrivano sempre. Il CSS è scritto direttamente nella pagina, quindi niente file in più da scaricare.

Nell'editor e nella tela arriva invece sempre tutto.

Le opzioni sono in **Histeon → Impostazioni → Caricamento del CSS**:

- **Framework su richiesta** (predefinito): ogni pagina riceve solo le regole del framework che usa;
- **Framework completo**: il foglio intero su ogni pagina, come lo caricherebbe il framework;
- **Fogli globali in un file in cache**: i fogli globali arrivano interi in un file che il browser tiene fra una pagina e l'altra, mentre il CSS dei selettori resta nella pagina. Conviene con fogli globali grandi, come quelli importati. Un foglio con indirizzi `url()` relativi, e le copie statiche del sito, restano come prima;
- **Classi e id sempre inclusi** (la safelist): per le classi e gli id che uno script aggiunge dopo il caricamento e che quindi Histeon non vede nella pagina, come `.is-open` o `#modal`. Si può usare l'asterisco: `.is-*`.

> Se un menu o una finestra aperti da JavaScript perdono lo stile sul sito ma non nel builder, quasi sempre manca una classe nella safelist.

## I blocchi di WordPress con lo stile del sito

Dentro le pagine si possono usare anche i blocchi normali di WordPress: paragrafi, pulsanti, gruppi, immagini, citazioni. Con Histeon CSS i loro controlli offrono i colori, le dimensioni del testo, le spaziature e le ombre del sito, e in **Stili** compaiono varianti già pronte: pulsante pieno o a contorno, gruppo come sezione o come scheda, immagine arrotondata, citazione. Seguono il design del sito: se cambi i colori o le misure nel pannello di Histeon CSS, cambiano anche loro.
