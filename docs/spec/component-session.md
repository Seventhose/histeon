# Sessione di modifica di un componente

Implementazione:
- `editor/src/lib/state.svelte.ts`: `openComponent` e `closeComponent`;
- `editor/src/components/Topbar.svelte`: percorso, Annulla, Salva ed esci.

Test: `e2e/specs/component-session.spec.ts`.

## 1. Cosa fa
Un componente usato in una pagina si modifica **sul posto**, senza ricaricare il builder e senza lasciare la pagina.
- **Aprire:** si apre in tre modi:
  - doppio clic sul componente nella tela o nella Struttura;
  - voce **Modifica il componente** del menu contestuale;
  - pulsante nel pannello Elemento.
- **Durante la sessione:** tutti i pannelli (Struttura, HTML, CSS, JS, Props) lavorano sulla definizione del componente. In alto compaiono il percorso (Pagina › Componente) e i pulsanti **Esci senza salvare** e **Salva ed esci**. `Ctrl+S` salva senza uscire.
- **Salva ed esci:** salva il componente in sviluppo, stili condivisi compresi, e torna alla pagina. La tela mostra subito la nuova versione e l'istanza da cui si era partiti è di nuovo selezionata. Se il salvataggio non riesce, si resta nella sessione con l'errore.
- **Annulla:** chiede conferma se ci sono modifiche, poi le scarta, stili compresi, e torna indietro.
- **Componenti annidati:** un componente dentro un componente apre una sessione sopra l'altra. Il percorso permette di risalire di più livelli. Un componente già aperto nella catena non si riapre.
- **Nuovo componente:** si apre subito in sessione, sopra il documento corrente.

## 2. Cosa resta della pagina
Mentre il componente è aperto, la pagina resta in memoria così com'era: modifiche non salvate, selezione, cronologia di annullamento e articolo di esempio dei template. Uscendo dalla sessione torna tutto, compreso `Ctrl+Z` sulle modifiche fatte prima.

Chiudendo la scheda, il browser avvisa se ci sono modifiche non salvate anche solo nella pagina messa da parte (`anyDirty`). Lo stesso controllo vale per i link che aprono un altro documento.

## 3. Pubblicare
La sessione salva in sviluppo, come ogni salvataggio. Il componente va online con la prossima release (Pubblica…), insieme alle pagine.

## 4. Varianti

Una variante è una prop `boolean` più una classe modificatore BEM sul primo elemento del componente, scritta come espressione: `class="card {props.scura ? "card--scura" : ""}"`. `editor/src/lib/variants.ts`:

- `planVariant` sceglie il nome della prop dalle parole (`Card scura` → `cardScura`, classe `card--card-scura`), rifiuta un nome già usato e un primo elemento senza classe statica, e prepara il CSS del modificatore copiando le proprietà personalizzate che `.card` dichiara al primo livello (quelle annidate in `@media` o `&:hover` no);
- `listVariants` le riconosce dalla forma esatta del token; `removeVariant` toglie classe e prop, il CSS resta come per ogni selettore non più usato.

Il pannello CSS offre anche le classi scritte tra virgolette nelle espressioni della classe (`conditionalClasses`), così il CSS della variante si scrive dall'elemento. `GET /components` legge lo schema dalla testa di sviluppo quando differisce da quella online (`Store::unpublished`, una query sui ref): nel builder una prop appena aggiunta si imposta subito sulle pagine. L'editor a blocchi mostra il sito online, quindi lì la variante compare dopo la release.

## 5. Un componente in uso non si cancella

`Render\ComponentUsage` cerca `"ref":"<chiave>"` nel contenuto di pagine, articoli, componenti, template e parti (non nel cestino) e nelle teste di sviluppo dei documenti, al massimo 30 risultati. Prima del cestino (`pre_trash_post`) e della cancellazione (`pre_delete_post`), quando la richiesta viene da wp-admin, AJAX o REST, rifiuta con l'elenco (in wp-admin una pagina d'errore 409, in REST `false`). `DELETE /wp/v2/blocks/<id>` dell'editor a blocchi risponde 409 `xb_component_in_use` con lo stesso testo. Il codice che cancella apposta (WP-CLI, migrazioni, test) non viene fermato; il filtro `xb_protect_components_in_use` spegne il controllo.
