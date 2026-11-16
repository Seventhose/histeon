# Revisione: commenti e approvazione dall'anteprima

Implementazione:
- `plugin/src/Review/Comments.php`, `plugin/assets/front/review.js` e `review.css`;
- `editor/src/components/CommentsPanel.svelte`, sezione **Commenti**.

Test: `plugin/tests/Integration/ReviewTest.php`, `e2e/specs/review.spec.ts`.

## Per chi rivede
Chi ha il **link di anteprima** (Anteprima → Nuova scheda, firmato e a scadenza) vede in basso a sinistra una piccola barra. Non serve un account.
- **Commenta:** si passa sopra un elemento (si evidenzia), un clic apre un riquadro, si scrive il commento e il proprio nome, Invia. Esc esce.
- **Approva:** chiede il nome e registra l'approvazione della versione che si sta guardando.

## Nel builder
- **La sezione Commenti** mostra i commenti del documento, divisi tra **Da fare** e **Risolti**, con autore e data. **Mostra l'elemento** seleziona l'elemento commentato; Risolto, Riapri, Elimina. L'icona mostra quanti sono da fare.
- **L'approvazione** compare in cima ai Commenti e nella finestra **Pubblica**. Dice se è la versione in sviluppo di adesso o se la pagina è cambiata dopo, perché l'approvazione ricorda la versione vista.

## Sicurezza
- **Chi scrive:** solo chi ha un link di anteprima **valido**, che è la credenziale, e solo sul documento di quel link. Un link scaduto o revocato non scrive nulla.
- **Testo:** sempre testo semplice, al massimo 2000 caratteri, e nomi al massimo di 80. Nel builder è mostrato come testo.
- **Bot e invii ripetuti:**
  - un campo esca invisibile fa rifiutare l'invio a chi lo compila;
  - al massimo 30 invii all'ora per link e indirizzo, 300 all'ora per tutto il sito.
- **Dove finiscono:** in un tipo di contenuto privato (`xb_comment`), non pubblico, assente dall'API standard, che nessuno può creare a mano. Leggono, risolvono ed eliminano solo i costruttori che possono modificare quel documento.
- **Quando la barra non compare:** se l'anteprima gira con gli script bloccati, perché il contenuto in sviluppo è stato scritto da chi non ha `unfiltered_html`, la barra non compare e non si commenta.
- **Identificatori degli elementi:** nelle pagine aperte con un link di anteprima gli elementi hanno `data-xb-uid`, per agganciare i commenti. Le anteprime non vanno mai in cache, nei motori di ricerca o nel sito statico.
- **Dati personali:** i nomi scritti da chi rivede sono dati personali. Si eliminano con il commento.
