# Filtri dei loop (facet) — v1

Implementazione: `plugin/src/Render/Facets.php`, `plugin/src/Data/FacetsNode.php`, `plugin/assets/front/facets.js`, `plugin/assets/front/facets-static.js`, `editor/src/components/FacetsPanel.svelte`, `editor/src/lib/facets.ts`. Test: `plugin/tests/Integration/FacetsTest.php`, `editor/test/facets.test.ts`, `e2e/specs/facets.spec.ts`, `e2e/specs/facets-static.spec.ts`.

I filtri fanno scegliere ai visitatori cosa vedere in un elenco di contenuti:
- ricerca, termini di una tassonomia, valore di un campo;
- ordine e pagine.

Si definiscono una volta nelle impostazioni del sito e nelle pagine si leggono come `facets.<nome>`, in qualunque punto: il conteggio può stare sopra i risultati e la paginazione sotto.

## 1. Definizione
Le definizioni stanno nel documento `settings` (chiave `facets`), come le query:
- si salvano in sviluppo;
- arrivano al sito con una release;
- un ripristino le riporta indietro;
- l'esportazione delle release le porta con sé.

```json
{
  "eventi": {
    "query": "posts", "type": "evento", "perPage": 12, "pageKey": "pagina",
    "filters": [
      { "kind": "search", "key": "q", "label": "Cerca" },
      { "kind": "taxonomy", "key": "tema", "label": "Tema", "taxonomy": "tema_evento", "multiple": true, "match": "any" },
      { "kind": "field", "key": "citta", "label": "Città", "field": "xb:evento:citta" },
      { "kind": "sort", "key": "ordine", "label": "Ordina", "options": [
        { "value": "recenti", "label": "Più recenti", "orderby": "date", "order": "desc" }
      ] }
    ]
  }
}
```

| Chiave | Contenuto |
|---|---|
| nome | Minuscole, cifre e `_` (si scrive in un'espressione: `facets.eventi`). Al massimo 20. |
| `query` | `posts` (con `type`, i contenuti di un tipo) oppure una query personalizzata di tipo posts (`docs/spec/queries.md`). I filtri si aggiungono ai suoi argomenti. |
| `perPage` | Risultati per pagina, da 1 a 100. |
| `pageKey` | Il parametro dell'indirizzo con il numero di pagina. |
| `filters` | Al massimo 10. |

Ogni filtro legge un parametro dell'indirizzo (`key`). I tipi:

| `kind` | Cosa filtra | Valore nell'indirizzo |
|---|---|---|
| `search` | Ricerca di WordPress. | Testo, al massimo 100 caratteri. |
| `taxonomy` | Contenuti con i termini scelti. `multiple`: più scelte; `match`: `any` (almeno uno) o `all` (tutti). | Slug, separati da virgole (`tema=musica,teatro`), oppure `tema[]=…` dal form senza JavaScript. Al massimo 20. |
| `field` | Contenuti con quel valore del campo. | Testo, al massimo 100 caratteri. |
| `range` | Contenuti con il campo fra due valori, uno solo o entrambi (`type`: `number` o `date`, `AAAA-MM-GG`). Nel form sono due campi, `<key>_min` e `<key>_max`, che contano come parametri. `filters.<key>.min` e `.max` riempiono il form. | `prezzo=10..50`, `prezzo=10..`, `quando=..2026-03-31`, oppure `prezzo_min=10&prezzo_max=50`. Un valore che non si legge non filtra. |
| `sort` | L'ordine, tra le opzioni definite (`orderby`: `date`, `title`, `modified`, `menu_order`, `comment_count`, `rand`, oppure `field` con `field`, un campo pubblico: numeri come numeri, il resto come testo; i contenuti senza quel campo restano fuori, come in WordPress). | Il `value` di un'opzione. |

## 2. Nelle pagine
`facets.<nome>` restituisce:

| Chiave | Contenuto |
|---|---|
| `items` | I risultati della pagina: `{#loop facets.eventi.items as item}`. |
| `total`, `page`, `pages`, `perPage` | Conteggi. |
| `values.<key>` | Il valore scelto: testo, lista di slug o `null`. |
| `filters.<key>` / `filterList` | Ogni filtro con `label`, `name` (il nome del campo del form, `tema[]` per le scelte multiple), `value` e `options`. |
| `options` (di un filtro) | `value`, `label`, `count`, `selected`, `url`. `url` sceglie l'opzione, o la toglie se è già scelta. |
| `active` | I filtri attivi, ognuno con l'`url` che lo toglie. |
| `hasFilters` | C'è almeno un filtro attivo. |
| `resetUrl`, `prevUrl`, `nextUrl` | Link: senza filtri, pagina precedente, pagina successiva. |
| `pageLinks` | Pagine attorno a quella corrente, più la prima e l'ultima: `{number, url, current}`. Un salto ha `number` nullo. |
| `action`, `hidden` | Indirizzo del form e campi nascosti da ripetere (il token di un'anteprima firmata). |

**Conteggi.** Il conteggio di un'opzione considera tutti gli altri filtri ma non quello dell'opzione stessa. Così, scelto «Musica», l'opzione «Teatro» dice quanti risultati aggiungerebbe. Le opzioni senza risultati non compaiono, tranne quelle già scelte. I conteggi sono esatti fino ai primi 2000 risultati.

**Inserisci nella pagina** (pannello «Filtri dei loop») scrive il markup completo, che si modifica come ogni elemento:
- un form GET con i campi;
- i risultati con il loro numero e «Azzera i filtri»;
- le pagine;
- gli stili di base `.xb-facets`.

## 3. Aggiornamento senza ricaricare
Form e link funzionano senza JavaScript: l'indirizzo cambia e la pagina si ricarica. Lo script dei filtri si carica solo nelle pagine che li usano, e allora:
- intercetta l'invio del form, i cambi di scelta (la ricerca dopo una breve pausa) e i link dentro le parti marcate `data-xb-facet="<nome>"`;
- scarica la stessa pagina con il nuovo indirizzo e sostituisce quelle parti;
- aggiorna l'indirizzo, così il tasto Indietro funziona;
- rimette il focus dove era;
- emette l'evento `xb:facets` sul documento, per gli script che devono riattivarsi sui nuovi elementi.

Solo stessa origine. Il documento scaricato è inerte (`DOMParser`): i suoi script non vengono eseguiti. Se qualcosa non torna (errore di rete, parti diverse) carica la pagina normalmente.

## 4. Sicurezza
- I visitatori scelgono solo tra ciò che la definizione ammette:
  - parametri fuori dalla definizione: ignorati, e mai ripetuti nei link;
  - termini: solo esistenti;
  - ordinamenti: solo quelli definiti;
  - pagine: limitate (oltre l'ultima si vede l'ultima);
  - testi: ripuliti e accorciati.
- La query mantiene le regole dei loop (`docs/spec/queries.md` §3): contenuti pubblicati, senza password, leggibili da chi visita, di tipi ammessi.
- Un campo si può filtrare solo se è pubblico (`docs/spec/content.md`) e ha un valore semplice. Un campo che smette di essere pubblico smette di filtrare.
- Al salvataggio sono rifiutati:
  - parametri che WordPress usa già: le sue variabili di query e quelle di tipi e tassonomie, che cambierebbero la query principale della pagina;
  - tassonomie non pubbliche;
  - ordinamenti liberi.
- Salvare e provare richiedono `xb_build`. «Prova» usa le query in sviluppo, come il canvas.

## 5. Siti statici
- Con Static Bridge la pagina senza parametri è statica; quella con parametri va a WordPress, come ogni richiesta con query string.
- Le pagine che usano `facets.` vengono rigenerate quando cambia un contenuto (`docs/integration/static-bridge.md`).
- **Nello zip statico** non c'è WordPress a cui chiedere, quindi:
  - la pagina esportata porta **tutti** i risultati (al più 500, `Facets::STATIC_MAX`) senza paginazione; il primo elemento di ogni risultato del loop `facets.<nome>.items` ha `data-xb-facet-item="<nome>:<id>"`;
  - nel piè di pagina c'è un indice, `<script type="application/json" id="xb-facets-index-<nome>">`, con per ogni risultato i valori di ogni filtro (slug dei termini, valore del campo), le chiavi d'ordine di ogni opzione e le sue parole (titolo, riassunto, testo, al più 2000 caratteri): solo ciò che la pagina mostra già pubblicamente;
  - al posto di `facets.js` arriva `facets-static.js`, che filtra (ricerca: tutte le parole; termini: `any` o `all`; campo: uguale; intervallo: numeri o date), ordina e nasconde i risultati che non c'entrano, aggiorna gli elementi `data-xb-facet-total="<nome>"` (li scrive «Inserisci nella pagina»), scrive i parametri nell'indirizzo e li legge all'apertura, così anche i link dei filtri funzionano;
  - restano com'erano i conteggi delle opzioni, calcolati all'esportazione senza filtri, e la paginazione, che non c'è.
