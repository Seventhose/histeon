# Scorciatoie da tastiera

Nel builder quasi tutto si fa anche dalla tastiera. Questo capitolo elenca le scorciatoie di serie, i tasti che valgono in punti particolari (la tela, la struttura, il codice) e spiega come cambiarle.

Su Mac, al posto di [[Ctrl]] si usa [[⌘]] e al posto di [[Alt]] si usa [[⌥]]. Nelle tabelle trovi la versione per Windows e Linux.

## La palette dei comandi

La scorciatoia più utile è [[Ctrl+K]]: apre la **palette dei comandi**, da cui si raggiunge tutto il builder. Scrivi qualche lettera, scegli con [[↑]] e [[↓]], conferma con [[Invio]]. [[Esc]] la chiude. La apre anche il pulsante **Comandi** nella barra in alto.

Accanto a ogni comando la palette mostra la sua scorciatoia, se ne ha una. Così le impari mentre lavori.

## Le scorciatoie di serie

| Comando | Windows e Linux | Mac |
|---|---|---|
| Apri i comandi | [[Ctrl+K]] | [[⌘+K]] |
| Salva in sviluppo | [[Ctrl+S]] | [[⌘+S]] |
| Aggiungi un elemento | [[Ctrl+I]] | [[⌘+I]] |
| Annulla | [[Ctrl+Z]] | [[⌘+Z]] |
| Ripeti | [[Ctrl+Shift+Z]] oppure [[Ctrl+Y]] | [[⌘+Shift+Z]] oppure [[⌘+Y]] |
| Duplica l'elemento selezionato | [[Ctrl+D]] | [[⌘+D]] |
| Elimina l'elemento selezionato | [[Canc]] | [[Canc]] |
| Sposta su | [[Alt+↑]] | [[⌥+↑]] |
| Sposta giù | [[Alt+↓]] | [[⌥+↓]] |
| Porta fuori dal contenitore | [[Alt+←]] | [[⌥+←]] |
| Metti dentro l'elemento prima | [[Alt+→]] | [[⌥+→]] |
| Taglia l'elemento selezionato | [[Ctrl+X]] | [[⌘+X]] |

[[Esc]] è fisso e non si può cambiare: chiude solo il riquadro aperto (Storia, Altro, Connettore AI), altrimenti la sezione aperta, poi il pannello del framework, poi toglie la selezione.

> **Mentre scrivi**, le scorciatoie non ti rubano i tasti. Nei campi di testo e negli editor di codice funzionano solo «Apri i comandi» e «Salva in sviluppo». «Aggiungi un elemento» funziona anche nei campi, ma non nel codice. Tutte le altre valgono solo fuori da campi ed editor.

Le scorciatoie funzionano anche dopo un clic dentro la tela: l'anteprima passa i tasti al builder.

## Copiare e incollare elementi

Fuori dai campi di testo e dal codice:

| Tasti | Cosa fa |
|---|---|
| [[Ctrl+C]] | Copia l'elemento selezionato con gli stili delle sue classi |
| [[Ctrl+V]] | Incolla: un elemento copiato da Histeon (anche da un altro sito) apre la finestra per incollarlo; l'HTML copiato da altrove viene ripulito e inserito come elementi |

## Tasti in punti particolari

Questi tasti valgono solo in un punto preciso del builder e non si possono cambiare.

**Testo scritto nella tela** (doppio clic su un titolo, un paragrafo, un link o un pulsante):

| Tasti | Cosa fa |
|---|---|
| [[Invio]] | Conferma il testo (anche un clic fuori) |
| [[Shift+Invio]] | Va a capo nello stesso elemento |
| [[Esc]] | Rimette il testo com'era |
| [[Ctrl+B]] / [[Ctrl+I]] | Grassetto / corsivo |
| [[Ctrl+S]] | Conferma e salva in sviluppo |

**Pannello Struttura:**

| Tasti | Cosa fa |
|---|---|
| [[F2]] | Rinomina l'elemento (anche con un doppio clic sul nome) |
| [[Shift+F10]] o il tasto menu | Apre il menu contestuale dell'elemento |

**Menu contestuale, palette e menu Aggiungi:** [[↑]] e [[↓]] per muoversi (nel menu Aggiungi anche [[←]] e [[→]]), [[Invio]] per scegliere, [[Esc]] per chiudere. Nel menu contestuale [[Inizio]] e [[Fine]] vanno alla prima e all'ultima voce.

**Pannello HTML e codice:**

| Tasti | Cosa fa |
|---|---|
| [[Tab]] | Espande un'abbreviazione Emmet (per esempio `ul>li*3`), oppure fa rientrare la riga |
| [[Esc]], poi [[Tab]] | Esce dall'editor di codice con la tastiera (il suggerimento compare in basso a destra quando l'editor ha il focus) |
| [[F12]] o [[Ctrl]]+clic su una classe o un id | Seleziona l'elemento e apre il pannello CSS su quel selettore |

**Maniglie della larghezza della tela** (ai lati dell'anteprima, dopo averle raggiunte con [[Tab]]):

| Tasti | Cosa fa |
|---|---|
| [[←]] / [[→]] | Stringe o allarga di 10 pixel |
| [[Shift+←]] / [[Shift+→]] | Stringe o allarga di 100 pixel |
| [[Inizio]] | Larghezza minima |
| [[Fine]] o [[Esc]] | Torna alla larghezza piena (anche con un doppio clic sulla maniglia) |

**Anteprima:** [[Esc]] torna al builder.

**Editor sul sito di Lingua Bridge:** [[Ctrl+Invio]] salva la traduzione del testo e passa al successivo.

## Cambiare le scorciatoie

Ogni comando della palette può avere fino a **tre** combinazioni: azioni, sezioni, pannelli, snippet, componenti. Restano fuori solo le voci «Seleziona», che cambiano da una pagina all'altra.

1. Apri la palette con [[Ctrl+K]] e scegli **Scorciatoie da tastiera…**.
2. Cerca il comando per nome, per gruppo o per combinazione.
3. Premi **+** accanto al comando. Compare «Premi la combinazione… (Esc annulla)».
4. Premi la combinazione che vuoi. Viene salvata subito.

Per togliere una combinazione premi **✕** accanto a lei. Per tornare alle scorciatoie di serie di un comando premi **↺**; per tornare a quelle di serie di tutto il builder, **Ripristina tutte**.

Le tue scorciatoie si salvano nel tuo **profilo**: valgono per te, su ogni browser e computer, e non cambiano quelle dei colleghi.

## Le regole delle combinazioni

- **Forma:** [[Ctrl]], [[Alt]] e [[Shift]] (in quest'ordine, quelli che vuoi) più un tasto. Il tasto può essere una lettera, una cifra, da [[F1]] a [[F12]], una freccia, [[Canc]], [[Inizio]], [[Fine]], [[Pag↑]], [[Pag↓]] oppure uno di `, . / ; [ ] = -`.
- **Una combinazione, un comando.** Se è già usata, Histeon te lo dice con il nome del comando che la usa («… è già di …»): togli prima quella.
- **Combinazioni riservate**, che restano al browser o alla scrittura e non si possono assegnare: [[Ctrl+C]], [[Ctrl+V]], [[Ctrl+X]], [[Ctrl+A]], [[Ctrl+W]], [[Ctrl+T]], [[Ctrl+N]], [[Ctrl+Q]], [[Ctrl+R]], [[Ctrl+L]], [[Ctrl+F]], [[Ctrl+P]], [[Ctrl+Shift+T]], [[Ctrl+Shift+N]], [[Ctrl+Shift+W]] e [[Backspace]].
- **Il tasto fisico.** Lettere, cifre e punteggiatura si riconoscono dalla posizione del tasto, non dal carattere che produce: per questo [[Alt]] (o [[⌥]] su Mac) non le cambia.

> Con una tastiera dalla disposizione molto diversa (per esempio AZERTY), se una scorciatoia non risponde come ti aspetti, registrala di nuovo premendola: Histeon salva il tasto che premi davvero.

Una scorciatoia esegue il comando esattamente come la palette, con gli stessi controlli: non permette nulla che il comando non permetta già.
