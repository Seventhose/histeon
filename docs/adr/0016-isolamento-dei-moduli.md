# ADR 0016 — Isolare i moduli in container? No in blocco; sito statico e admin privato prima di tutto

Data: 2026-10-22 · Stato: **accettato** (analisi; nessun cambiamento di codice)

## Contesto

Domanda: ha senso, ed è fattibile, mettere in container i moduli di Histos (Static Bridge, Lingua Bridge, Histos CSS) per ridurre la superficie d'attacco?

Fatti che contano:
- **WordPress non isola i plugin.** Tutti girano nello stesso processo PHP, con le stesse credenziali del database e gli stessi permessi sui file. Un modulo compromesso compromette il sito, che sia dentro Histos o installato come plugin separato. Un isolamento vero richiede processi separati che parlano con WordPress via rete.
- **Il pubblico di Histos** usa in gran parte hosting comuni, senza Docker né processi propri.
- **I tre moduli hanno superfici molto diverse:**
  - **Histos CSS** genera CSS da parametri validati: niente rete, niente file caricati, una rotta REST con permessi.
  - **Lingua Bridge** sta nel percorso di ogni richiesta (URL per lingua, `hreflang`, menu). Chiama i servizi di traduzione scelti dall'utente, con blocco degli indirizzi privati, e importa file XLIFF.
  - **Static Bridge** rende tutte le pagine, scrive file su disco e, con la pubblicazione su Cloudflare, usa un token dell'account.

## Decisioni

| # | Decisione | Motivazione |
|---|---|---|
| I1 | **Niente container per Histos CSS e Lingua Bridge.** | Histos CSS non ha quasi nulla da isolare. Lingua Bridge deve stare dentro ogni richiesta di WordPress: fuori aggiungerebbe solo latenza e un protocollo in più, che è a sua volta superficie. |
| I2 | **La riduzione di superficie raccomandata è architetturale:** sito pubblico statico (Cloudflare o Static Bridge), amministrazione privata, moduli spenti se non servono (`XB_DISABLE_MODULES`), credenziali in `wp-config.php`. È documentata nel manuale («Installazione blindata», capitolo 19). | I visitatori non eseguono PHP e non toccano il database: è l'isolamento più efficace e funziona anche su un hosting comune. |
| I3 | **Possibile in futuro: un worker opzionale di Static Bridge** per chi ospita in proprio. È un processo o container che legge il sito via HTTP, scrive solo nella sua cartella e custodisce il token Cloudflare. WordPress gli chiede una pubblicazione con un segreto condiviso, ma non vede mai il token. | Chi compromette WordPress può ancora chiedere una pubblicazione, ma non può rubare il token né usarlo per altro sull'account. Si fa solo se servirà a utenti reali: è di media difficoltà e si aggiunge un pacchetto da mantenere. |
| I4 | **I file non fidati restano a validazione stretta in processo:** release, trasloco del sito, XLIFF, SVG, incollati. | Formati chiusi, limiti di dimensione, liste di nomi ammessi. Isolarli in un processo separato avrebbe senso solo se si dovessero eseguire parser complessi di terzi. |

## Collegamenti

- `docs/spec/modules.md`, `docs/manual/it/19-permessi-e-sicurezza.md`.
