# ADR 0002 — Runtime della Fase 1

Data: 2026-10-19 · Stato: **accettato**

| # | Decisione | Motivazione |
|---|---|---|
| R1 | **Il nome resta provvisorio (`xb/`)** fino alla prima beta pubblica | Finché nessun sito in uso salva contenuti, il cambio è un trova/sostituisci; diventa una migrazione solo dopo. Questo corregge l'ADR 0001, secondo cui il nome andava deciso "prima della Fase 1". |
| R2 | Tutti i blocchi Histos usano `skip_inner_blocks`; il sottoalbero lo renderizza `Renderer` | Loop, props e slot passano per un contesto immutabile proprio, non per il block context di WordPress, e nessun blocco viene renderizzato due volte |
| R3 | **Operatore condizionale `a ? b : c`** aggiunto al linguaggio | Serve in continuazione per le classi e le varianti (`variant={i == 0 ? "featured" : "base"}`); la sua mancanza è emersa scrivendo la demo |
| R4 | `||` / `&&` restituiscono booleani; il ripiego si scrive con `??` o `.default()` | Semantica unica e prevedibile tra il parser TS e quello PHP |
| R5 | **Nessun `default` nei `block.json`** | Verificato nel browser: Gutenberg elimina al salvataggio gli attributi uguali al default (`"tag":"div"`) |
| R6 | Script dell'editor **senza build** (solo globali `wp.*`) | Il lato Gutenberg è sottile (visualizzazione + RichText) e lo strumento serio è il builder della Fase 2 |
| R7 | Stili Histos **fuori da `@layer`**, stampati solo per le classi usate | Devono prevalere sugli stili del tema, che sono senza layer; l'ordine rispetto al framework si decide nella Fase 3 |
| R8 | Il sanitizer **conserva i nodi invariati** (stesso `uid`, stessi attributi) | Un cliente senza `unfiltered_html` che corregge un testo non deve distruggere il markup dello sviluppatore |
| R9 | PHPStan **livello 8** (non 9) | Il livello 9 vieta ogni uso di `mixed`, che le API WordPress restituiscono ovunque; i valori vengono ristretti esplicitamente dove serve |
| R10 | Ambiente di sviluppo `dev/` con Docker Compose (WordPress 7.1, MariaDB 11) e suite d'integrazione eseguita dentro WordPress | Le parti critiche (sanitizer, query, escaping, editor) vanno provate su WordPress vero, non con mock |
| R11 | Il CLI `xb-dsl` è compilato con `tsc` in `packages/dsl/dist` | Il Node di sistema non esegue TypeScript; il CLI serve a compilare tema, demo e fixture |

## Limiti noti alla fine della Fase 1
- L'editor a blocchi mostra le espressioni come testo (`{site.name}`): l'anteprima con i valori risolti arriva con il builder (Fase 2).
- Il loop `main()` non ha ancora la paginazione.
- I preset personalizzati si gestiscono solo con l'opzione `xb_loop_presets` (WP-CLI o codice). L'interfaccia arriva nella Fase 2.
- Nessuna API REST propria. Il salvataggio passa per gli endpoint standard di WordPress, comunque coperti dal sanitizer.
