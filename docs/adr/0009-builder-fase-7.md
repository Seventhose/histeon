# ADR 0009 — Builder, Fase 7

Data: 2026-10-20 · Stato: **accettato**

| # | Decisione | Motivazione |
|---|---|---|
| B1 | **Template e parti di template sono documenti del builder** (`docs/spec/templates.md`): si vedono con un contenuto di esempio, e i template solo-file vengono scritti nel database alla prima apertura | Dopo un'importazione da Etch la struttura del sito sta nei template; vanno modificati con lo stesso flusso delle pagine |
| B2 | **Il token di anteprima di un template apre tutte le pagine che il template renderizza**; quello di una pagina apre solo quella pagina. La CSP che blocca gli script scatta se c'è contenuto non fidato ovunque | Un template vale per molti URL; la portata del token resta la più stretta possibile per ogni tipo |
| B3 | **Query e tipi ammessi nei loop stanno nel documento `settings`** (`docs/spec/queries.md`) | Una query cambia ciò che le pagine mostrano: deve passare per anteprima, release e ripristino come il resto |
| B4 | **Gli argomenti di visibilità sono di Histos, non dei preset**: rifiutati al salvataggio e tolti all'esecuzione | Nessuna query, scritta o importata, può mostrare bozze, contenuti privati o protetti da password |
| B5 | **Drag & drop con le regole dell'albero in funzioni pure e testate** (`canDrop`/`moveTo`): mai dentro se stesso, rami solo nel proprio `if` con l'`else` per ultimo, slot solo nei componenti, figli solo dove sono ammessi | La struttura resta sempre valida, qualunque cosa si trascini |
| B6 | **Palette dei comandi** (Ctrl+K) costruita da ciò che esiste già: azioni, pannelli, snippet, componenti, elementi della pagina | Un solo punto d'accesso da tastiera, senza duplicare logica |
| B7 | **Selettore di immagini proprio, sulla REST API di WordPress**, non la libreria media del block editor | La libreria del block editor richiede `'unsafe-eval'`, che la CSP del builder non concede (ADR 0005, R5). I permessi restano quelli di WordPress (`upload_files`); i valori inseriti sono testo letterale, quindi le graffe non diventano mai espressioni |
| B8 | **Differenze calcolate nel browser** sul codice Histos stampato, non sul markup a blocchi | Si confronta ciò che lo sviluppatore scrive e legge; il server restituisce solo le due versioni |
| B9 | **`.xbrelease` con hash d'integrità ma senza firma.** L'autenticità sta nel fatto che il file lo sceglie un amministratore; il contenuto è filtrato con la fiducia di chi importa | Una firma richiederebbe una chiave condivisa fra installazioni: si valuterà più avanti |
| B10 | **La guardia del pannello HTML segue la piegatura bottom-up del parser**: un `strong` salvato come testo + link + testo è ancora una sola riga di testo | Il contenuto importato (Etch, HTML, AI) veniva messo in sola lettura per un falso allarme |
