# Il manuale

Il manuale di Histeon per chi lo usa (non per chi lo sviluppa: per quello ci sono `docs/spec/` e `docs/adr/`). Esce in un solo file HTML, `docs/histos-manual.html`, in italiano e in inglese, che funziona offline e si apre con un doppio clic.

```bash
node scripts/build-manual.mjs          # rigenera docs/histos-manual.html
node scripts/build-manual.mjs --check  # fallisce se il file non è aggiornato
```

## Capitoli

Un file per capitolo, `NN-nome.md`, con gli stessi nomi in `it/` e in `en/`: la build si ferma se differiscono. Ogni file comincia con `# Titolo`. I titoli `##` entrano nell'indice.

## Markdown ammesso

- Titoli da `#` a `####`, paragrafi, elenchi puntati e numerati (due livelli, rientro di due spazi), tabelle con `|`, blocchi di codice con tre apici inversi.
- Note: righe che iniziano con `> `.
- Testo: `codice`, **grassetto**, *corsivo*, link `[testo](https://…)` o interni `[testo](#it-capitolo-sezione)`. Altri link diventano testo semplice.
- Tasti: `[[Ctrl+Z]]` diventa <kbd>Ctrl</kbd>+<kbd>Z</kbd>.
- Niente HTML, niente immagini esterne: il file deve restare autonomo.

Gli id delle sezioni sono `<lingua>-<nome del file senza numero>-<titolo in minuscolo con trattini>`.

## Stile

Si scrive per chi costruisce siti, non per chi scrive codice di Histeon: cosa fa, dove si trova, come si usa, cosa succede quando si pubblica. Le etichette dell'interfaccia sono quelle che si vedono a schermo (in italiano in `it/`, in inglese in `en/`). Frasi brevi, esempi concreti, nessun nome di classe PHP se non serve a chi usa il prodotto.
