# Pannello HTML: abbreviazioni e salto al CSS

Implementazione:
- `editor/src/lib/emmet.ts`;
- `CodeEditor.svelte` (`emmet`, `ondefinition`);
- `HtmlPanel.svelte`.

Test: `editor/test/emmet.test.ts`, `editor/test/state.test.ts`, `e2e/specs/html-emmet.spec.ts`, `e2e/specs/unsaved-text.spec.ts`.

## Abbreviazioni (stile Emmet)
Scrivi un'abbreviazione e premi **Tab**: diventa HTML, indentato come la riga, con il cursore nel primo elemento vuoto.

| Scrivi | Ottieni |
|---|---|
| `section.hero>h2.hero__title{Ciao}+p` | una section con titolo e paragrafo |
| `.card#top` | `<div id="top" class="card"></div>` |
| `a[href="/x" target=_blank]{Vai}` | un link con i suoi attributi |
| `ul>.item$*3` | tre `li` con `item1`, `item2`, `item3` |
| `div>(h3+p)*2^footer` | gruppi ripetuti, `^` risale di un livello |

**Sintassi supportata**
- **Elementi:** tag, `.classe`, `#id`, `[attr="v"]`, `{testo}`.
- **Ripetizioni e operatori:**
  - `*N` ripete, al massimo 50 volte, con `$` per numerare;
  - `>` crea un figlio, `+` un fratello;
  - `^` risale di un livello;
  - `( )` raggruppa.
- **Tag impliciti:** senza tag si usa il figlio naturale (`li` in un elenco, `td` in una riga), altrimenti `div`.

**Regole**
- **Parole normali:** una parola sola si espande solo se è un tag HTML. Tab dopo una parola qualunque del testo indenta, come sempre, senza inventare markup.
- **Testo tra graffe:** resta testo. `<`, `>` e `&` vengono resi come entità, le graffe diventano `\{` e `\}`: niente espressioni.
- **Limite:** al massimo 200 elementi per abbreviazione.
- **Dipendenze:** nessuna. È un sottoinsieme scritto per Histeon.

## Dalla classe al suo CSS
**Ctrl+clic** (⌘+clic su Mac) o **F12** su una classe o un id nel codice: l'elemento si seleziona e il pannello CSS si apre su quel selettore. Il pannello HTML continua a mostrare quello che mostrava.

## Quando ristampa
Il pannello ristampa il codice dopo una modifica fatta altrove solo se si vede (non dietro un pannello allargato, non con i pannelli in basso nascosti o coperti da una sezione) e solo se l'elemento mostrato è cambiato: lo stesso blocco congelato è lo stesso codice. L'esito del controllo di andata e ritorno (`roundTrips`) si ricorda per blocco o albero congelato.

## Testo rifiutato
Il testo che un pannello non può applicare resta nel pannello ed è lavoro dell'utente: non si perde in silenzio (`BuilderState.reject`, `rejected`, `invalid`).
- **Quando:** HTML con un errore di sintassi o vuoto, una regola del foglio globale con graffe non bilanciate, il testo della tela che passa al pannello HTML (`inline-text.md`).
- **Conta come non salvato:** la riga di stato dice «Non salvato: HTML non valido alla riga N», «Salva» resta acceso e chiudendo la scheda il browser avvisa.
- **Salvare:** `Ctrl+S` salva il resto, se c'è, e dice cosa non ha salvato («HTML non valido alla riga N: non salvato»).
- **Prima di perderlo, Histeon chiede:** un'altra selezione, una modifica della pagina fatta da un altro pannello, Annulla e Ripeti, l'apertura di un componente. Con «Annulla» nella domanda resta tutto com'è; con «OK» il testo si lascia perdere e il pannello torna a mostrare il documento.
- **Smette di contare** appena il pannello riceve un testo valido o torna a mostrare il documento.

