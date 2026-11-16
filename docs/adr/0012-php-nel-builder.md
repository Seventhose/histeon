# ADR 0012 — PHP nel builder: nei file, non nell'editor

Data: 2026-10-20 · Stato: **accettato**

Histos non esegue PHP scritto dentro l'editor HTML. Il PHP resta nei file del tema o di un plugin, e il builder lo usa in modo sicuro (`docs/spec/php.md`).

| # | Decisione | Motivazione |
|---|---|---|
| P1 | **Nessun PHP scritto nel builder viene eseguito**, in nessuna forma: `eval`, file generati, include. | Sarebbe esecuzione di codice sul server a portata di ciò che oggi è solo contenuto. Lo diventerebbe, cioè: un account builder compromesso, un'importazione di release da un altro sito, un'anteprima firmata del ramo di sviluppo, un'XSS che arriva a un amministratore. Il permesso giusto per scrivere PHP è `edit_themes`, che molti siti spengono (`DISALLOW_FILE_EDIT`); Histos non deve riaprirlo dalla porta di servizio. |
| P2 | **Il PHP sta nei file**, con le sue difese: versionato in Git, revisionabile, distribuito come codice, analizzabile da PHPStan, coperto da backup e dal deploy. | Chi scrive PHP è uno sviluppatore: per lui un file non è un limite. Il contenuto e le release di Histos restano dati, sempre. |
| P3 | **Tre punti di contatto, già sicuri:** dati (`xb_data_providers` → `data.nome`), modificatori (`xb_modifiers` → `valore.nome()`), blocchi dinamici (`register_block_type` con `render_callback` → `<!-- wp:tema/blocco /-->`). | Coprono gli usi del PHP in un template (calcolare, leggere servizi o tabelle, comporre HTML complesso) senza aprire l'esecuzione. |
| P4 | **I valori dei dati diventano dati semplici**: scalari, liste, mappe, e nodi per post, termini e utenti con le regole di visibilità di sempre. Il resto è `null`, con limiti di profondità e quantità. L'uscita è escapata come ogni valore. Un errore non rompe la pagina. | Una funzione PHP non deve diventare una scorciatoia per mostrare una bozza a un anonimo o per stampare HTML non escapato dentro un'espressione. |
| P5 | **Il builder li mostra:** completamento di `data.`, modificatori con Ctrl+Spazio, gruppo «Blocchi PHP del sito» nel menu +. | L'esperienza resta «scrivo PHP e lo uso nella pagina». Cambia solo dove si scrive. |
| P6 | **I modificatori si leggono al primo uso**, non quando Histos si carica. | Il `functions.php` del tema si carica dopo i plugin: i suoi modificatori devono contare. |
