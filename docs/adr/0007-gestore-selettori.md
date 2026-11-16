# ADR 0007 — Gestore dei selettori

Data: 2026-10-20 · Stato: **proposto**

## Contesto
Il gestore dei selettori elenca tutti i selettori del progetto, con ricerca e ordinamento; da ciascuno si può vedere e modificare il CSS, rinominarlo o eliminarlo. Resta da decidere come la rinomina tratti gli elementi che usano la classe.

In Histos lo stesso nome vive in tre posti: la voce di stile (`{id, selector, css}`, sotto versioning nel documento `styles`), gli attributi `class` dei documenti e, con la concatenazione BEM, i nomi derivati (`&__title` → `.card__title`). Rinominare solo la voce romperebbe lo stile delle pagine. Rinominare i documenti in modo automatico tocca contenuti che possono essere stati scritti da autori senza `unfiltered_html`.

## Decisioni

| # | Decisione | Motivazione |
|---|---|---|
| S1 | Il gestore lavora sulle **teste dev**, come il builder. La lettura non crea commit e non mette documenti sotto versioning | Ciò che conta è ciò che si sta per pubblicare. Una lista non deve avere effetti collaterali |
| S2 | L'uso si calcola con la **stessa semantica del CSS su richiesta** (ADR 0006): una voce è usata se una delle sue regole, anche sollevata con BEM, può applicarsi. Contano classi e id statici, HTML libero, template del tema e safelist | Un unico significato di "usato" per caricamento e gestione |
| S3 | La rinomina segue il modello della migrazione di framework: **anteprima, poi commit dev**, uno per documento più uno per gli stili, in **una transazione**. Si pubblica dal builder e si annulla con il ripristino di una release | Nessuna modifica arriva online senza una release; un conflitto a metà annulla tutto |
| S4 | Rinominare una classe rinomina anche i suoi **figli BEM** (`__`, `--`), e mai le classi che hanno solo lo stesso inizio (`card-alt`, `cardigan`) | È la convenzione della concatenazione BEM del pannello CSS: `card__title` "appartiene" a `card` |
| S5 | La riscrittura del CSS tocca **solo i preludi delle regole**. Dichiarazioni, stringhe, commenti, `url()`, selettori di attributo e preludi delle at-rule restano byte per byte | `url(img.card.png)` o `content: ".card"` non sono riferimenti alla classe |
| S6 | La rinomina è **tutto o niente**. Si rifiuta se: il nome è già in uso; la classe è in documenti che l'utente non può modificare o nel tema; il filtro restricted toglierebbe un elemento rinominato | Una rinomina parziale romperebbe lo stile proprio dove non si vede. I file del tema non sono del builder |
| S7 | **La fiducia si eredita** (`save_dev(…, inherit_trust: true)`): un commit che sostituisce una testa dev non fidata resta non fidato, anche se lo crea un amministratore. In quel caso, e per chi non ha `unfiltered_html`, ogni elemento rinominato deve superare il livello restricted. Se non lo supera, la rinomina viene bloccata, non filtrata | ADR 0005 R1. Il salvataggio dal builder è una revisione dell'autore, una rinomina automatica no. Un elemento rinominato cambia firma, quindi alla pubblicazione verrebbe di nuovo filtrato e perderebbe ciò che solo un amministratore può scrivere |
| S8 | Si eliminano **solo voci inutilizzate** e la richiesta è tutto o niente. L'interfaccia avverte che classi da props, script e widget non si vedono | L'analisi statica non vede tutto. Il commit dev resta annullabile |
| S9 | Rinomina ed eliminazione richiedono un **builder senza modifiche non salvate**. Dopo l'operazione il builder ricarica e inizia una nuova cronologia di annullamento | Le modifiche locali entrerebbero in conflitto con i nuovi commit dev, e un "annulla" riporterebbe i vecchi nomi |

## Conseguenze
- La rinomina riguarda le classi. Gli id non si rinominano: possono essere ancore, `for`, `aria-*` o riferimenti JavaScript.
- L'HTML libero (`{#raw}`, core block) non viene riscritto. L'anteprima lo segnala da rinominare a mano.
- L'analisi è limitata a 2000 documenti. Oltre questa soglia i conteggi sono parziali e rinomina ed eliminazione sono disattivate.
- `Framework\Migration` usa ancora una propria copia della query dei documenti. Potrà usare quella di `Selectors`, oppure entrambe una funzione comune.
