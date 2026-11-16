# Pannello Proprietà

Implementazione:
- `editor/src/lib/elementFields.ts`;
- `editor/src/components/InfoPanel.svelte`.

Test: `editor/test/elementFields.test.ts`, `e2e/specs/properties.spec.ts`.

## Campi per tipo di elemento
Sopra gli attributi, l'elemento selezionato mostra i campi che gli servono, chiamati in parole e non con il nome dell'attributo:

| Elemento | Campi |
|---|---|
| Titolo (h1–h6) | livello, con un clic |
| Link | indirizzo, **Apri in una nuova scheda** (aggiunge `target="_blank"` e `rel="noopener"`, e li toglie), suggerimento |
| Immagine | **Scegli immagine…** (libreria media), testo alternativo, larghezza, altezza, caricamento |
| Pulsante | tipo (pulsante, invia, azzera), disattivato |
| Campo, area di testo, scelta | tipo, nome, testo di esempio, obbligatorio |
| Etichetta | per il campo |
| Video | file, copertina, comandi, parte da solo, senza audio, in loop, nella pagina su telefono |
| Contenuto incorporato | indirizzo, titolo, caricamento |
| Elenco numerato | parte da, al contrario |
| Data | data per le macchine |

Ogni elemento ha anche **Identità**: id (per i link `#ancora`) e nome accessibile.

## Regole
- **Valori:** un campo svuotato toglie l'attributo. Un numero deve essere intero (o un'espressione). Una scelta non prevista resta visibile così com'è.
- **Avvisi:** dove manca qualcosa che conta, il campo lo spiega, per esempio un'immagine senza testo alternativo o un iframe senza titolo.
- **Controlli:** ogni modifica passa dagli stessi controlli del pannello codice (`attributes.ts`: nome valido, nessun `xb:`, rilettura identica nel DSL). Il server decide comunque cosa si può salvare (gestori di eventi, URL `javascript:`).
- **Attributi:** l'elenco completo resta sotto, per tutto il resto.
