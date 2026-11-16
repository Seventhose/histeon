# ADR 0004 — Sito statico e Cloudflare (Fase 5)

Data: 2026-10-20 · Stato: **accettato**

| # | Decisione | Motivazione |
|---|---|---|
| S1 | **Static Bridge non viene modificato da questo repository.** Histos ha un proprio export verso Cloudflare; il contratto con SB è in `docs/integration/static-bridge.md`. | SB ha un suo repository e un suo processo di rilascio con gate di QA: le modifiche si fanno lì |
| S2 | Render tramite **loopback anonimo** con un token HMAC che la risposta deve ripetere | È la pagina che vede davvero un visitore, generata ora e non presa da una cache |
| S3 | Asset copiati **dal disco** con allowlist e `realpath` | Nessun PHP eseguito per servire file, nessun traversal |
| S4 | **Scansione bloccante** prima di ogni deploy | Un nonce o un percorso del server pubblicato non si ritira più: meglio fermarsi prima |
| S5 | **Workers Static Assets** con versioni annotate; rollback = deploy di una versione precedente | API documentata, upload deduplicato, ripristino istantaneo (ADR 0001, D5) |
| S6 | Token Cloudflare preferibilmente in wp-config; altrimenti **cifrato**, mai restituito; API base cambiabile solo da costante | Né un dump del database né un'impostazione manomessa espongono o dirottano il token |
| S7 | Form via Worker: Turnstile, HMAC con timestamp e id anti-replay, dati in un tipo di post privato riservato agli amministratori | Difesa contro messaggi falsi o ripetuti; i segreti stanno solo nei *secret* del Worker |
| S8 | Mock locale dell'API Cloudflare per i test (`dev/cloudflare-mock`) | Nessun account reale toccato durante lo sviluppo; il mock verifica hash, JWT e metadati come l'API vera |
