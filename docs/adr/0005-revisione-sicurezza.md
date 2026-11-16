# ADR 0005 — Revisione di sicurezza dopo la Fase 6

Data: 2026-10-20 · Stato: **accettato**

## Contesto
Finite le Fasi 0–6, il codice è stato rivisto da quattro revisori indipendenti, uno per area: runtime e sanitizer, versioning e REST, export/deploy/form, builder e DSL. Le correzioni sono arrivate in tre lotti, con un commit e test di regressione per ciascuno:
- **lotto A**: fiducia dei contenuti e filtro del markup;
- **lotto B**: export, deploy, credenziali, form e privacy;
- **lotto C**: builder e DSL.

## Decisioni

| # | Decisione | Motivazione |
|---|---|---|
| R1 | **Un contenuto non fidato non diventa mai fidato perché qualcuno di fidato lo salva o lo pubblica.** Quando un admin salva o pubblica, i commit non fidati vengono sanitizzati di nuovo. Gli elementi fidati esistenti si riconoscono per uid e ciascuno vale una sola volta. | È la classe di difetti più grave trovata: un contenuto non fidato poteva diventare fidato quando qualcuno di fidato lo risalvava |
| R2 | **Il round-trip del DSL è una frontiera di fiducia.** Il printer fa l'escape di testo, contenuto raw-text e `{#raw}`. L'editor verifica che `parse(print(albero))` dia la stessa struttura; se non è così, il pannello HTML diventa di sola lettura. | Il pannello HTML ristampa il contenuto salvato, che chiunque può aver scritto via Gutenberg o REST, e alla prima modifica lo risalva con i permessi di chi edita |
| R3 | **`parseXb` valida tipi e nomi** come il parser del DSL. Sono vietati come chiave `__proto__`, `constructor` e `prototype`. | Ciò che arriva dal database è input esterno, anche se è "nostro" formato |
| R4 | **Limiti espliciti a ogni ricorsione e cardinalità.** Annidamento 128 (DSL e `parseXb`), espressioni 64, nodi e loop nel renderer, body dei form, file per deploy. Regex senza backtracking quadratico. | Un input costruito apposta non deve poter bloccare il server o esaurirne la memoria |
| R5 | **La pagina del builder ha una CSP con nonce**, `frame-ancestors 'none'` e nessuna source map. Il canvas aggiunge una CSP propria al sandbox e inserisce l'HTML solo tramite `<template>`, togliendo i tag che possono navigare o caricare documenti. | Difesa in profondità: anche se uno script sfuggisse al filtro, nell'anteprima non verrebbe eseguito né porterebbe il frame altrove |
| R6 | **I segreti derivano dai salt di `wp-config.php`**: token Cloudflare (HKDF) e chiave dei form. Il token non si salva nel database se i salt mancano o sono quelli di esempio. | Un dump del solo database non basta per decifrare il token o falsificare messaggi |
| R7 | **Operazioni concorrenti serializzate.** Anti-replay atomico con `add_option`, lock per publish, restore e deploy, compare-and-swap sui ref. | Due richieste parallele non devono poter passare entrambe un controllo pensato per una sola |
| R8 | **Niente resta sull'origine dopo il deploy.** Il bundle viene cancellato, la cartella di lavoro è deny-all e i messaggi di blocco non contengono estratti. | Una copia statica dimenticata è un leak (bozze, nonce, path) |
| R9 | **Dati personali dei form:** conservazione limitata (180 giorni, filtro `xb_form_retention_days`), esportazione e cancellazione dagli strumenti privacy di WordPress, rimozione completa con `uninstall.php`. | Privacy by default (GDPR) |
| R10 | **Audit delle dipendenze in CI** (`npm audit`, `composer audit`). | Una dipendenza con una falla nota si vede prima che arrivi in una release |

## Conseguenze
- Un documento con contenuto che il DSL non rappresenta fedelmente (scritto fuori da Histos) mostra il codice in sola lettura finché non lo si corregge dagli altri pannelli. È un prezzo voluto.
- Dopo l'aggiornamento cambia la chiave dei form: va risincronizzata salvando di nuovo le impostazioni Turnstile, che riscrivono il secret del Worker.
- Le modifiche richieste a Static Bridge restano in `docs/integration/static-bridge.md`. Questo repository non le applica (ADR 0004, S1).
