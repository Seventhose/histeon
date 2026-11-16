# Azioni rapide del builder — v0.1

Implementazione:
- `editor/src/lib/speed.ts`;
- `editor/src/components/InfoPanel.svelte`, `SvgDialog.svelte`, `InsertMenu.svelte`;
- `POST /xb/v1/components` con `content`.

Test:
- `editor/test/speed.test.ts`;
- `plugin/tests/Integration/ComponentFromSelectionTest.php`;
- `e2e/specs/speed.spec.ts`.

## 1. Componente dalla selezione
Pannello Proprietà di un elemento → **Crea componente…**: si scelgono nome e chiave (proposta dal nome, per esempio "Scheda evento" diventa `scheda-evento`).
- Il sottoalbero diventa il markup del nuovo componente, **in sviluppo**: il post `wp_block` nasce vuoto, così il sito online resta com'era, e il markup è un commit di sviluppo che la tela e l'anteprima usano subito. Per chi non ha `unfiltered_html` passa dallo stesso filtro di ogni salvataggio. Vale anche per gli agenti (`createComponent` del connettore): niente va online senza una release.
- **Release.** Il componente va online con la release. Se la release comprende solo una pagina che lo usa, il componente mai pubblicato entra nella release con lei (`Versioning::publish`), anche attraverso altri componenti nuovi; altrimenti la pagina online lo mostrerebbe vuoto.
- La selezione diventa un'istanza del componente. La pagina resta da salvare, con versioni e release come sempre.
- **Rifiutata** se la selezione legge nomi che esistono solo lì, come la variabile di un loop esterno (`item`): un componente vede solo `this`, `site`, `user`, `url`, `data` e le sue props. Il pulsante resta disattivato e il motivo viene mostrato. Le variabili dei loop interni alla selezione vanno bene.

## 2. SVG
Menu **+** → **SVG (incolla o file)…**: codice incollato o un file `.svg` dal computer (al massimo 200 KB, nessun caricamento sul server), inserito come elementi modificabili.

La pulizia avviene in tre passaggi, prima che il codice entri nella pagina:
1. Si tolgono dal testo gli attributi `on…`. Chromium li compila, e li segnala alla CSP, anche in documenti inerti.
2. Il parser HTML di `DOMParser` costruisce un documento inerte.
3. Restano solo gli elementi grafici ammessi da `MarkupPolicy`, cioè niente `script`, `style`, `foreignObject` né animazioni. `href` è ammesso solo verso `#id` dello stesso documento. Le graffe vengono escapate, così nulla diventa un'espressione.

Ciò che è stato tolto viene elencato. Il server filtra di nuovo al salvataggio per gli autori senza `unfiltered_html`.

## 3. Classi BEM
Pannello Proprietà → **Classi BEM**: gli elementi figli senza classe ricevono `blocco__parte`.
- **Blocco:** è la prima classe dell'elemento, altrimenti la sua etichetta o il tag.
- **Parte:** è l'etichetta del figlio o il suo ruolo (`title`, `text`, `image`, `link`, `list`, `item`…).
- **Esclusi:** gli elementi che hanno già una classe, l'interno degli SVG e le istanze di componenti.

## 4. Etichette nella Struttura
- **Rinomina:** doppio clic sul nodo, oppure F2 quando è selezionato. L'etichetta si salva come `xb:label`, è testo su una riga, senza graffe, al massimo 60 caratteri. Un'etichetta vuota torna a quella automatica.
- **Etichetta automatica BEM:** un elemento senza etichetta né classe, dentro un blocco che ha una classe, mostra accanto al tag il suo nome BEM. Per esempio un `h2` dentro `.card` mostra `card__title`. È lo stesso nome che **Classi BEM** darebbe come classe.
  - Il blocco è la prima classe dell'antenato più vicino che ne ha una.
  - I componenti iniziano una nuova numerazione.
  - Gli elementi interni di un SVG non ricevono etichetta.

**Testo del nodo.** Accanto al nome, in grigio e tra virgolette, poche parole (al massimo 28 caratteri, tagliate a fine parola) del testo più importante che il nodo contiene, così un blocco si riconosce senza aprirlo.
- **Quale testo:** prima un titolo, cioè h1–h6 o un elemento con una classe come `__title`, `titolo` o `heading`; poi il primo testo vero. Contatori, numeri e frecce come «01 / 07» non contano.
- **Immagini:** il loro testo alternativo, ma solo se non c'è altro testo.
- **Componenti:** la prop di testo principale (`title`, `heading`, `label`…).
- **Contenuti dinamici:** restano come espressione, per esempio `{this.title}`.
- **Quando non compare:** sulle righe aperte, perché le righe figlie mostrano già il loro testo, e quando coinciderebbe con l'etichetta. Il testo completo si legge passando sopra il nome.

## 5. Copia e incolla tra siti
Fuori dai campi di testo e dagli editor di codice:
- **Ctrl+C** copia l'elemento selezionato insieme agli stili delle classi che usa. È un JSON `{xb: "clip", version: 1, origin, markup, styles}`.
- **Ctrl+V** di un clip Histeon apre **Incolla da …**, che mostra cosa succede a ogni classe:
  - **nuova:** viene aggiunta con i suoi stili;
  - **uguale** a quella del sito (stesse dichiarazioni, spaziatura a parte): si usa quella del sito;
  - **in conflitto:** si sceglie se importarla con un nome nuovo (`classe-2`, rinominata negli elementi e nei selettori) o se usare quella del sito.

Il clip viene ricontrollato prima dell'uso: forma, dimensione (2 MB) e selettori senza `{ } < ;`. Gli uid sono rigenerati. I componenti che il sito non ha vengono segnalati.

**HTML da altre fonti** (pagine web, altri builder) viene inserito come elementi. Prima però si tolgono:
- gli attributi `on…`, ancora prima di analizzare il testo;
- gli elementi `script`, `style`, `link`, `iframe`, `object`, `form` e simili;
- gli indirizzi `javascript:` e `data:`.

Le graffe diventano testo. Le classi restano.

**Sempre al livello ristretto.** Gli appunti li scrive l'ultima pagina da cui si è copiato, che può impostarli dal proprio evento di copia (e dichiarare come origine qualunque sito, anche questo). Per questo clip e HTML incollati passano dal server (`POST /xb/v1/paste/sanitize`) e arrivano nel documento filtrati come se li avesse scritti chi non ha `unfiltered_html`: markup con `MarkupPolicy` ristretta e testo con `Spec::TEXT_INLINE_HTML`, CSS con `Documents::restricted_styles`. Vale anche per un amministratore. Fa eccezione solo ciò che la stessa scheda ha copiato: il builder lo ricorda e lo reincolla com'era. Se il controllo non riesce, non si incolla nulla.

## 6. Menu contestuale della Struttura
Clic destro su un nodo del pannello Struttura. Dalla tastiera si apre con il tasto menu o con Shift+F10; ci si muove con le frecce, Inizio e Fine; Esc lo chiude e riporta il focus sul nodo.

| Voce | Cosa fa |
|---|---|
| Copia | Copia l'elemento con gli stili delle sue classi (il clip di §5). |
| Incolla qui | Incolla dentro o dopo il nodo: un clip Histeon passa dalla finestra di §5, l'HTML di altre fonti viene ripulito come con Ctrl+V. Il browser può chiedere il permesso di leggere gli appunti. |
| Duplica | Come Ctrl+D. |
| Avvolgi in un div / una section / un link | Il nodo diventa il figlio di un elemento nuovo, che resta selezionato. |
| Togli il contenitore | L'elemento sparisce e i suoi figli prendono il suo posto. |
| Crea componente… | Come «Crea componente» delle Proprietà (§2), dal nodo cliccato: nome, chiave, e il nodo diventa un'istanza. È disattivata quando la parte usa variabili che esistono solo lì. |
| Classi BEM ai figli | Come §3. |
| Rinomina | Come F2. |
| Copia HTML | L'HTML del builder del nodo. |
| Seleziona il genitore, Sposta su / giù, Elimina | Come i comandi omonimi. |

- **Voci non applicabili:** restano visibili ma disattivate, con il motivo nel suggerimento.
- **Annullare:** ogni voce è un'azione che il builder ha già, quindi si annulla con Ctrl+Z.

Implementazione: `editor/src/components/ContextMenu.svelte`, `wrapBlock` / `unwrapBlock` in `editor/src/lib/tree.ts`. Test: `editor/test/tree.test.ts`, `e2e/specs/context-menu.spec.ts`.

