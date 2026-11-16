# Storia delle modifiche

Implementazione: `editor/src/lib/historySteps.ts`, `editor/src/components/HistorySteps.svelte`, `BuilderState.historySteps()` e `jump()`. Test: `editor/test/historySteps.test.ts`, `e2e/specs/history-steps.spec.ts`.

Accanto ad Annulla e Ripeti, **Storia** apre gli ultimi passi (fino a 200, quelli di Ctrl+Z) in parole, dal più recente.

- **Come si chiamano.** Ogni passo prende il nome dal confronto tra lo stato prima e quello dopo, così nessuna modifica deve descriversi da sé: «Aggiunto div.card», «Eliminato …» (solo l'elemento più esterno, non tutto quello che contiene), «Spostato …», «Testo «…»», «Modificato …» (attributi, tag), «CSS di .card», «Foglio global». Più cambi nello stesso passo si elencano insieme. La scrittura continua nello stesso posto resta un passo solo, come per Ctrl+Z.
- **Tornare indietro.** Un clic su un passo riporta la pagina a prima di quel passo, in un colpo solo: sono gli annullamenti uno dopo l'altro, quindi valgono le stesse regole (struttura, stili e fogli insieme).
- **Andare avanti.** I passi annullati restano in grigio sopra «Ora» finché non modifichi altro: un clic li rifà fino a quel punto.
- **Ora** segna lo stato che vedi. Esc o un clic fuori chiudono la finestra.

La storia è quella della scheda aperta: si perde ricaricando la pagina. Le versioni salvate restano nel pannello Versioni.
