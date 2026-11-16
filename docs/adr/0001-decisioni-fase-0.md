# ADR 0001 — Decisioni della Fase 0

Data: 2026-10-19 · Stato: **accettato**

| # | Decisione | Motivazione |
|---|---|---|
| D1 | **Completamente open source, GPL-3.0-or-later, nessuna chiave di licenza**, nessun updater verso server di vendor, nessuna telemetria | Requisito del progetto; compatibile con WordPress (GPL-2.0-or-later) e con le dipendenze scelte |
| D2 | Editor in **Svelte 5 + TypeScript + Vite + CodeMirror 6** | Leggero e reattivo per un editor con molti pannelli; nessun bisogno di `@wordpress/components` |
| D3 | **Core Framework come dipendenza** del plugin wp.org nella v1; motore incorporato nella v2 | MIT, nessuna chiave; il CSS è generato nel browser, quindi incorporarlo richiede lavoro dedicato |
| D4 | **Estendere Static Bridge** (API pubblica, bundle mode, target pluggable) invece di un plugin di deploy separato | Riuso di un motore già collaudato; resta utile anche senza Histos |
| D5 | Deploy su **Cloudflare Workers Static Assets** (non Pages) | API di upload documentate, rollback per versione, è il percorso su cui Cloudflare investe |
| D6 | Il versioning copre **design e struttura** (pagine del builder, template, componenti, stili, impostazioni); post, blog e media restano in WordPress | Evita di duplicare il CMS; i testi del cliente entrano come content commit con merge per `uid` |
| D7 | **Token semantici Histos** facoltativi, attivi di default nei componenti forniti | Permettono di cambiare framework senza codemod |
| D8 | Primo traguardo: **MVP verticale di circa 10 settimane** | Un primo risultato completo e usabile, da allargare dopo |
| D9 | Minimi supportati: **WordPress 6.9, PHP 8.3** | Allineati a Static Bridge (WP 6.9); PHP 8.3 per tipi `true` e classi readonly, senza imporre l'8.4 |
| D10 | Monorepo con **npm workspaces** (`packages/*`) + `plugin/` Composer | Nessuno strumento aggiuntivo da installare; pnpm si potrà valutare in seguito |
| D11 | Il **DSL ha un'implementazione di riferimento in TypeScript** (`packages/dsl`) usata dall'editor; il server PHP lavora sugli attributi JSON dei blocchi, non sul testo DSL | Il server non deve mai parsare il DSL. Serve però un **parser PHP delle espressioni** (render), allineato a quello TS da vettori di test condivisi (Fase 1). |
| D12 | **Policy di sicurezza del markup in PHP puro**, testabile senza WordPress | Va applicata su ogni percorso di scrittura; l'adapter WP fornisce solo il livello di permesso e i protocolli |

## Decise dopo
- **Nome:** Histos fino alla 0.3.11, poi Histeon (0.3.12). Il namespace dei blocchi resta `xb/` (ADR 0002, R1), così i contenuti salvati non hanno bisogno di una migrazione.
- **Distribuzione:** le release del repository pubblico, firmate.
