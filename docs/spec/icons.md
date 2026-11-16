# Libreria di icone

Implementazione: `editor/src/lib/icons.ts`, `editor/src/components/IconDialog.svelte`, `plugin/assets/icons/lucide.json`
(con `LICENSE-lucide.txt`), `scripts/icons-lucide.py`, la chiave `icons` della configurazione del builder.

Test: `editor/test/icons.test.ts`, `e2e/specs/speed.spec.ts`.

- **Fonte:** [Lucide](https://lucide.dev), licenza ISC. `scripts/icons-lucide.py <cartella del pacchetto lucide>` legge
  `dist/esm/icons/*.js` come testo (non esegue nulla) e tiene solo `path`, `circle`, `rect`, `line`, `polyline`,
  `polygon`, `ellipse` con attributi di geometria (e `fill`) dai valori fatti di cifre, lettere, spazi, punti,
  virgole e trattini. Un'icona con altro viene scartata. La licenza si copia accanto.
- **Caricamento:** il builder legge `lucide.json` (circa 75 KB compressi) solo all'apertura del dialogo, dal sito.
- **Ricerca:** per nome; tutte le parole devono esserci; prima il nome esatto, poi chi comincia così, poi il resto.
- **Inserimento:** `iconMarkup()` ricontrolla gli stessi limiti e scrive
  `<svg class="icon icon--nome" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">`;
  poi `svgToBlocks()`, la stessa pulizia di un SVG incollato, e il salvataggio filtra come sempre.
- **Anteprime nel dialogo:** elementi Svelte (`svelte:element`), mai `{@html}`.
