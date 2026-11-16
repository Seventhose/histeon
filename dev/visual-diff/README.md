# Confronto visivo prima/dopo

Strumento usato per verificare una migrazione (ADR 0008): cattura schermate a pagina intera e la "firma" del DOM di un elenco di URL, in desktop e mobile, e confronta due catture. La firma comprende testo, posizione e dimensioni di ogni elemento e gli stili calcolati principali.

```bash
docker run --rm --network host -v "$PWD":/work -w /work mcr.microsoft.com/playwright/python:v1.52.0-noble \
  sh -c 'pip install -q playwright==1.52.0 && python dev/visual-diff/capture.py prima urls.txt'
```

Dopo la modifica si cattura `dopo` allo stesso modo, poi:

```bash
python3 dev/visual-diff/compare.py prima dopo
```

`compare.py` richiede Pillow. Con un terzo argomento (`dettagli`) mostra le differenze elemento per elemento.

Durante la cattura le animazioni sono ferme e la barra di amministrazione è nascosta. Le cartelle con le catture contengono dati del sito: non vanno nel repository.
