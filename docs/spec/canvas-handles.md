# Maniglie e trascinamento nella tela

Implementazione:
- `editor/src/lib/handles.ts`: le parti pure;
- `editor/src/lib/overlay.ts`: il livello nella tela;
- `Canvas.svelte`;
- `BuilderState.setDeclarationOn`.

Test: `editor/test/handles.test.ts`, `e2e/specs/canvas-handles.spec.ts`.

## Spaziature
L'elemento selezionato ha una cornice a due toni, bianco intorno all'indaco (`xb-frame` nell'overlay), che si vede su sezioni chiare, scure e indaco; un nodo ripetuto da un loop ha invece il solo contorno indaco su ogni copia. Sull'elemento selezionato compaiono le maniglie:
- **padding**, corallo, sui quattro lati interni;
- **margine** sopra e sotto, indaco, sui lati esterni.

Trascinare una maniglia:
- **Aggancio:** il valore si aggancia alla scala di spaziature del framework (`var(--space-m)`, `var(--xb-space-l)`…). La scala viene misurata nella pagina stessa all'inizio del trascinamento, così vale con qualunque framework.
- **Valore libero:** con **Alt** il valore è in pixel interi.
- **Durante il trascinamento:** un'etichetta mostra il valore (`padding-top: m · 24px`) e la pagina cambia subito.
- **Al rilascio:** la dichiarazione viene scritta nel CSS proprio della prima classe dell'elemento ed è un passo di Annulla. Il pannello CSS la mostra. Senza una classe la tela non scrive nulla e la riga di stato dice di aggiungerne una.

## Spostare un elemento
- **Linguetta:** sopra l'elemento selezionato c'è una linguetta con il suo nome leggibile (`⠿ Tre cerchi`).
- **Trascinare:** trascinandola sulla pagina, il punto di arrivo segue le regole della Struttura:
  - quarto superiore prima;
  - quarto inferiore dopo;
  - al centro dentro, dove è ammesso.

  Una linea corallo, o un riquadro tratteggiato per "dentro", mostra dove andrà.
- **Annullare:** Esc annulla il trascinamento. Lo spostamento è un passo di Annulla.

## Dove vive
Il livello sta nel documento della tela ma fuori da `<body>`, così un nuovo disegno della pagina non lo cancella.
- **Isolamento:** le sue parti stanno in uno **shadow root**, quindi il CSS del sito non le raggiunge. A ogni movimento del puntatore cambiano solo posizione e visibilità.
- **Dopo un nuovo disegno:** la tela riaggancia le maniglie al nuovo elemento nello stesso istante, così non spariscono nemmeno per un fotogramma e un clic non va mai a vuoto.
- **Durante un trascinamento:** il trascinamento nativo e la selezione di testo della pagina restano spenti.
- **Selezione:** un clic su maniglie e linguetta non la cambia.

Le maniglie non compaiono:
- su un nodo ripetuto da un loop, che ha più riquadri;
- mentre si scrive un testo nella tela.

La tela resta senza script: tutto è comandato dal builder.
