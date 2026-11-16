# Testo scritto nella tela

Implementazione:
- `editor/src/lib/inlineText.ts`;
- `Canvas.svelte` (`startInline`);
- `BuilderState.setTextContent`.

Test: `editor/test/inlineText.test.ts`, `e2e/specs/inline-text.spec.ts`.

## Uso
- **Aprire:** doppio clic su un titolo, un paragrafo, un link o un pulsante nella tela, oppure **Scrivi il testo nella pagina** dal menu contestuale. L'elemento si apre in modifica sul posto, con il bordo corallo.
- **Tasti:**
  - **Invio**, o un clic fuori, conferma;
  - **Esc** rimette il testo com'era;
  - **Maiusc+Invio** va a capo (`<br>`);
  - `Ctrl+B` e `Ctrl+I` mettono grassetto e corsivo;
  - `Ctrl+S` conferma e salva.
- **Annullare:** ogni modifica è un passo di Annulla.

## Cosa si può scrivere qui
Solo il testo di un elemento che contiene **solo testo statico**, anche con formattazione in linea.

Restano fuori, e si modificano nel pannello HTML:
- un testo con espressioni (`{item.title}`): il builder lo dice nella riga di stato;
- un elemento con altri elementi dentro;
- un elemento ripetuto da un loop;
- `script`, `style`, i campi dei moduli e i media.

Un doppio clic su un componente apre invece la sessione del componente (`component-session.md`).

## Sicurezza e fedeltà
Quello che il browser lascia nell'elemento viene prima riordinato:
- `b` e `i` diventano `strong` ed `em`;
- i tag sconosciuti lasciano il posto al loro testo;
- gli attributi non ammessi vengono tolti;
- le graffe digitate diventano `\{` e `\}`, perché un testo scritto nella pagina non è mai un'espressione.

Poi il risultato passa dal parser del DSL. Torna nel documento solo se è un testo con la formattazione in linea che il DSL rappresenta (`INLINE_FORMAT`, `docs/spec/dsl.md` §3.4); altrimenti la tela torna com'era e il testo scritto non si perde: passa al pannello HTML come codice dell'elemento (`draftCode`), che lo applica o lo segnala come non valido, e intanto conta come non salvato. In modalità contenuti, che non ha il pannello HTML, va negli appunti.

Il testo incollato arriva sempre come testo semplice. Il canvas resta senza script (sandbox): la modifica usa solo l'editing del browser, comandato dal builder.

## Mentre si scrive
Un nuovo disegno della tela che arriva mentre stai scrivendo, per esempio quello della modifica precedente, aspetta che tu abbia finito. Non conferma né cancella il testo che stai scrivendo, ed Esc continua ad annullare.
