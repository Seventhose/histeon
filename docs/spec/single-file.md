# File unico

Implementazione:
- `editor/src/lib/sfc.ts`;
- `editor/src/components/SourcePanel.svelte`, sezione **File unico** della barra laterale.

Test: `editor/test/sfc.test.ts`, `e2e/specs/single-file.spec.ts`.

Il documento aperto, pagina o componente, si legge e si modifica come **un solo file**, sul modello dei file di Vue e Svelte:

```html
<template>
  <article class="card">…</article>
</template>

<style>
.card {
  padding: var(--space-m);
  &__title { … }
}
</style>

<script>
  // il JavaScript del documento
</script>

<script type="application/json" data-props>
{ "props": { … } }
</script>
```

## Le sezioni
- **`<template>`:** l'HTML di Histeon, lo stesso del pannello HTML. Può contenere anche elementi `<template>`: la sezione finisce all'ultimo `</template>`.
- **`<style>`:** una regola `selettore { … }` per ogni selettore usato nel documento che ha un CSS proprio. Le `@media` e gli stati stanno dentro la regola, come nel pannello CSS. Una regola nuova crea lo stile di quel selettore.
- **`<script>`:** il JavaScript del documento. Si modifica solo con il permesso di scrivere HTML senza filtri, come nel pannello JS. Un `</script>` nel codice viene scritto `<\/script>` e riletto com'era.
- **Props:** solo per i componenti, lo schema delle props in JSON.

## Applicare
**Applica** porta tutto nel documento insieme. Prima controlla il file intero, e un errore dice la riga senza cambiare nulla:
- una sezione mancante o ripetuta;
- del testo fuori dalle sezioni;
- una `@media` fuori da una regola;
- graffe non bilanciate;
- JSON non valido;
- HTML non valido.

Le modifiche vanno in sviluppo con il prossimo salvataggio. **Ricarica dal documento** riprende il documento com'è.

**Stili condivisi:** gli stili valgono in tutto il sito. Togliere una regola dal file non cancella lo stile: smette solo di comparire qui.
