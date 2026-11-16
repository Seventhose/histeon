# Scorciatoie da tastiera — v1

Implementazione: `editor/src/lib/shortcuts.ts`, `editor/src/components/ShortcutsDialog.svelte`, `plugin/src/Admin/Shortcuts.php`. Test: `editor/test/shortcuts.test.ts`, `plugin/tests/Integration/ShortcutsTest.php`, `e2e/specs/shortcuts.spec.ts`.

Ogni comando della palette (Ctrl+K, ⌘K su Mac) può avere fino a tre combinazioni di tasti: azioni, sezioni, pannelli, snippet, componenti. Restano fuori solo le voci «Seleziona», che cambiano con la pagina.

Dove si cambiano: palette → «Scorciatoie da tastiera…». Una combinazione nuova si registra premendola (Esc annulla). Ogni comando si può ripristinare, e c'è «Ripristina tutte».

## 1. Predefinite
| Comando | Combinazione |
|---|---|
| Apri i comandi | Mod+K |
| Salva in sviluppo | Mod+S |
| Aggiungi un elemento | Mod+I |
| Annulla | Mod+Z |
| Ripeti | Mod+Shift+Z, Mod+Y |
| Duplica l'elemento selezionato | Mod+D |
| Elimina l'elemento selezionato | Canc |
| Sposta su / giù | Alt+↑ / Alt+↓ |
| Porta fuori dal contenitore / Metti dentro l'elemento prima | Alt+← / Alt+→ |

I suggerimenti (titoli dei pulsanti, voci del menu contestuale con `aria-keyshortcuts`, la struttura vuota) mostrano la prima combinazione dell'utente per quel comando, o niente se non ne ha (`lib/keys.svelte.ts`); anche il salvataggio mentre si scrive nella tela usa la combinazione di «Salva in sviluppo».

Ai bordi (primo o ultimo fratello, primo livello, nessun contenitore prima) la riga di stato lo dice invece di non fare nulla. Ctrl+X (fisso, come Ctrl+C e Ctrl+V) taglia l'elemento selezionato con i suoi stili. Sono comandi della palette, quindi possono avere una scorciatoia, anche le azioni del menu contestuale: avvolgi in un div, una section o un link, togli il contenitore, seleziona il genitore, copia HTML, crea componente dalla selezione, scrivi il testo nella pagina.

`Mod` è Ctrl, oppure ⌘ su Mac. Esc resta fisso: chiude solo il popover aperto (Storia delle modifiche, «Altro» del pannello CSS, Connettore AI: ascoltano in cattura, fermano la propagazione e rimettono il focus sul loro pulsante), altrimenti la sezione aperta, poi il cassetto del framework, poi toglie la selezione (`App.svelte`, `onkeydown`).

## 2. Regole
- **Combinazioni:** `Mod`, `Alt` e `Shift`, in quest'ordine, più un tasto:
  - una lettera o una cifra;
  - F1–F12;
  - le frecce, Canc, Inizio, Fine, Pag↑, Pag↓;
  - `, . / ; [ ] = -`.

  Lettere e punteggiatura si leggono dal tasto fisico, quindi Alt su Mac e il layout della tastiera non le cambiano.
- **Riservate al browser o alla scrittura** (mai assegnabili): Mod+C/V/X/A/W/T/N/Q/R/L/F/P, Mod+Shift+T/N/W, Backspace.
- **Una combinazione, un comando.** Assegnarla a un secondo comando è rifiutato, con il nome di chi la usa già.
- **Non ruba la scrittura:**
  - nei campi di testo e negli editor di codice valgono solo «Apri i comandi» e «Salva»;
  - «Aggiungi un elemento» vale anche nei campi, ma non nel codice;
  - tutte le altre combinazioni valgono solo fuori da campi ed editor.
- **Dalla tela:** l'anteprima passa al builder i tasti assegnati, così le scorciatoie funzionano anche dopo un clic nella pagina.

## 3. Dove si salvano
Sono una preferenza personale, non un permesso. Valgono per chi le ha scelte, su ogni browser.
- **Cosa si salva:** solo le differenze dalle predefinite, nel profilo dell'utente (meta `xb_shortcuts`). Una lista vuota spegne una predefinita.
- **Validazione:** il server ricontrolla le stesse regole: combinazioni, riservate, duplicati, al massimo 300 comandi e 3 combinazioni per comando.
- **Accesso:** leggere e salvare richiede `xb_build` (`GET`/`POST /xb/v1/shortcuts`).
- **Effetto:** una scorciatoia esegue il comando come la palette, con gli stessi controlli del server.
