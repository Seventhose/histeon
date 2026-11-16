# ADR 0006 — CSS su richiesta

Data: 2026-10-20 · Stato: **accettato**

## Contesto
Il modello scelto per gli stili:
- gli stili sono **legati al selettore di un elemento**, che sia classe o id;
- il CSS di un selettore si carica **solo se l'elemento è presente in pagina**;
- i fogli globali stanno in un gestore separato.

Un framework CSS, di solito, carica il proprio foglio intero.

Prima di questo ADR, Histos aveva tre limiti:
- legava ogni voce di stile alla **prima classe** del selettore, quindi `.a, .b` arrivava solo con `.a`, mentre `#id` e `[data-x]` arrivavano sempre;
- guardava solo gli elementi Histos;
- stampava sempre per intero il foglio globale e il foglio del framework.

## Decisioni

| # | Decisione | Motivazione |
|---|---|---|
| C1 | **Un solo motore** (`CssRules`) decide regola per regola che cosa la pagina può usare, per gli stili Histos, il foglio globale e il framework | Un'unica semantica, testata una volta; nessuna differenza fra "voce" e "foglio" |
| C2 | La presenza si legge dall'**HTML finale della pagina** (buffer di output) | Conta tutto ciò che il visitatore riceve: core block, `{#raw}`, tema. È anche il punto in cui l'export statico cattura la pagina |
| C3 | Regola **conservativa**: nel dubbio la regola si tiene (attributi, tipi, pseudo-classi funzionali, at-rule non di gruppo, `:root`) | Un byte in più è meglio di una pagina rotta |
| C4 | **Safelist** per ciò che aggiunge JavaScript, e modalità "framework completo" come via d'uscita | Nessuna analisi dell'HTML può vedere classi che non ci sono ancora |
| C5 | Framework "su richiesta" come **predefinito** | È il caso che pesa: sulla home di sviluppo il foglio di Core Framework scende dell'87% senza differenze visive misurate |
| C6 | Pill del pannello CSS anche per l'**id** statico dell'elemento | Il selettore di un elemento può essere una classe o un id |
| C7 | Cache del foglio analizzato come **JSON** (dati) in `uploads/xb-cache/`, invalidata da percorso, dimensione, data e versione del motore; rimossa da `uninstall.php` | Analizzare il foglio a ogni richiesta costerebbe decine di ms; un file PHP in cache sarebbe codice eseguibile scrivibile da chi controlla uploads |

## Conseguenze
- Il CSS di Histos e quello del framework sono **inline** in ogni pagina. Il browser non li mette in cache fra una pagina e l'altra, ma il peso trasferito per pagina scende molto.
- I contenuti caricati dopo via JavaScript (paginazione AJAX, modali iniettate) possono richiedere voci nella safelist.
- Un framework installato ma non scelto continua a caricare il proprio foglio: le impostazioni lo segnalano.
- Il buffer di output trattiene i flush parziali fino alla fine della pagina, perché l'head va riscritto conoscendo tutta la pagina.

## Aggiornamento (2026-10-28)
I fogli globali possono arrivare come file in cache (`uploads/xb-cache/global-css-<hash>.css`), a scelta nelle Impostazioni; il CSS dei selettori resta in linea e su richiesta. Spento per difetto e mai nelle copie statiche: vedi `docs/spec/styles.md` §3.
