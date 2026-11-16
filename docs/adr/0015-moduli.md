# ADR 0015 — Un unico Histos con moduli: Histos CSS, Static Bridge, Lingua Bridge

Data: 2026-10-21 · Stato: **accettato** (registro, schermata Moduli, moduli, spegnimento dopo un errore fatale e pacchetto unico: `docs/spec/modules.md`)

## Contesto
Prima di questa decisione erano quattro plugin separati: Histos (builder), Histos CSS, Static Bridge (sito statico, Cloudflare) e Lingua Bridge (traduzioni). Chi costruisce un sito con Histos li installa, li aggiorna e li configura uno per uno. La richiesta: **un solo plugin Histos**, in cui gli altri sono **moduli attivabili e disattivabili**, che restano però **usabili anche da soli**, come plugin indipendenti.

Fatti che contano:
- **Static Bridge non è il motore di Histos.** Histos rende le pagine da solo. Static Bridge serve solo per la copia statica e la pubblicazione su Cloudflare (`StaticSite\StaticBridge` è un'integrazione). Quindi può essere un modulo spento.
- **Licenze compatibili.** Static Bridge è GPL-3.0-or-later, Lingua Bridge GPL-2.0-or-later, Histos GPL-3.0-or-later. Tutti nostri, nessun problema di provenienza (`docs/policy/PROVENANCE.md`).
- **Requisiti diversi.** Static Bridge richiede PHP 8.4, Histos 8.3: il modulo si accende solo dove c'è PHP 8.4.
- **Attivazione.** Entrambi hanno `register_activation_hook`/`register_deactivation_hook`. Sono procedure che creano tabelle, regole di riscrittura, il file router in `mu-plugins` (Lingua Bridge) e operazioni pianificate, e che come moduli vanno richiamate dall'interruttore del modulo.

## Decisioni

| # | Decisione | Motivazione |
|---|---|---|
| M1 | **Repository separati, pacchetto unico.** Static Bridge e Lingua Bridge restano repository e plugin a sé, e continuano a fare le loro release. `scripts/package.sh` di Histos copia in `plugin/modules/<modulo>/` il codice di una versione fissata (tag + SHA-256 in `modules.json`) | Usabili da soli, come richiesto. Una sola fonte di verità per modulo. Il pacchetto Histos è riproducibile |
| M2 | **Registro dei moduli** (`XB\Modules\Registry`): per ogni modulo id, nome, versione, requisiti (PHP, WordPress), file di avvio, procedure di accensione e spegnimento. Stato in un'opzione (`xb_modules`) | Un solo punto che decide cosa si carica, prima di `plugins_loaded` |
| M3 | **Plugin separato attivo = modulo fermo.** Se Static Bridge o Lingua Bridge sono attivi come plugin, il modulo incluso non si carica e le impostazioni dicono «attivo come plugin separato (versione X)». Si passa al modulo con un clic: disattiva il plugin, accende il modulo, **stessi dati** (stesse opzioni e tabelle) | Mai due copie dello stesso codice caricate. Nessuna migrazione di dati, perché il codice è lo stesso |
| M4 | **Accendere e spegnere** chiamano le stesse procedure di attivazione e disattivazione del plugin (tabelle, regole di riscrittura, router, operazioni pianificate). Spegnere **non cancella mai** i dati. Disinstallare Histos chiede, modulo per modulo, se tenerli | Comportamento identico al plugin separato; nessuna perdita |
| M5 | **Histos CSS diventa un modulo** dentro Histos (codice già in `src/Framework/HistosCss`). Spento: niente pannello, niente classi, niente palette in Gutenberg; i token `--xb-*` di base restano, perché i componenti li usano. Uscirà anche come **plugin a sé** (`histos-css`) per siti senza il builder: token, classi, pannello e Gutenberg, con i parametri in un'opzione invece che nel versioning | Il framework libero serve anche fuori da Histos |
| M6 | **Schermata Moduli** in Histos (Impostazioni → Moduli), nello stile di Histos: scheda per modulo con stato, versione, requisiti, interruttore, collegamento alle sue impostazioni. I menu dei moduli si raccolgono sotto Histos | Un solo posto per gestire tutto |
| M7 | **Aggiornamenti.** Un modulo si aggiorna con la release di Histos che lo include (versione e impronta in `modules.json`, visibili nella schermata). Il plugin separato continua con i suoi aggiornamenti | Nessun canale di aggiornamento nuovo, nessuna chiamata remota |
| M8 | **Test.** La suite di ogni modulo resta nel suo repository. Histos aggiunge test d'integrazione del registro: accensione, spegnimento, plugin separato attivo, requisiti mancanti, dati conservati | Ognuno prova ciò che gli appartiene |

## Fasi
1. **Registro e schermata Moduli**, con Histos CSS come primo modulo (M2, M5, M6).
2. **Lingua Bridge come modulo**: pacchetto, avvio, accensione e spegnimento (router in `mu-plugins`, regole di riscrittura), coesistenza con il plugin separato (M1, M3, M4).
3. **Static Bridge come modulo**: come sopra, più il requisito di PHP 8.4 e le operazioni pianificate.
4. **Histos CSS come plugin a sé** (`histos-css`).
5. **Pacchetto unico** con `modules.json` e impronte, verifica su un sito di prova (sito con i tre plugin separati → Histos con moduli, senza perdita di dati).

## Rischi
- **Caricamento doppio** di classi con lo stesso nome, se il controllo di M3 sbaglia. Mitigazione: il registro decide prima di caricare, con un test dedicato.
- **Percorsi.** I moduli usano `plugin_dir_url( __FILE__ )`: dentro `modules/` gli URL cambiano. Mitigazione: costanti di base (`LINGUA_BRIDGE_DIR`, `LINGUA_BRIDGE_FILE`…) definite dal registro prima dell'avvio, e verifica degli asset nei test.
- **Router di Lingua Bridge in `mu-plugins`.** Punta al percorso del plugin. Mitigazione: il router si riscrive all'accensione del modulo e al passaggio tra plugin e modulo.
