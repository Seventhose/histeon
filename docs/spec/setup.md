# Primo avvio

Implementazione: `plugin/src/Admin/SetupScreen.php`. Test: `plugin/tests/Integration/SetupScreenTest.php`, `e2e/specs/setup.spec.ts`.

Una pagina sola (**Histeon → Primo avvio**) raccoglie le scelte che prima stavano in tre schermate.

1. **Il framework CSS.** Histeon CSS è consigliato e incluso; gli altri si scelgono se installati (Core Framework, Automatic.css).
2. **I moduli.** Quelli inclusi in questa copia di Histeon (Lingua Bridge, Static Bridge), da accendere solo se servono. Un modulo attivo come plugin separato, spento da `wp-config.php` o che chiede un PHP più recente si vede ma non si cambia da qui. Spegnere non cancella dati.
3. **Da dove arrivi.** Se il sito ha contenuti di Etch o usa Automatic.css (attivo, o con i suoi fogli rimasti), la pagina porta dove l'importazione si prepara, in sviluppo e con l'anteprima. Da qui non parte nessuna importazione.

**Quando compare.** Finché il sito non ha scelto un framework e non ha completato o saltato il primo avvio: un avviso in amministrazione e la voce nel menu di Histeon. Dopo resta raggiungibile da **Impostazioni → Primo avvio**.

**Salvataggio.** «Salva e inizia» sceglie il framework (registrato nella storia delle impostazioni e nell'audit, come da Impostazioni) e accende o spegne i moduli con `Registry::set()`; «Salta per ora» non cambia nulla. In entrambi i casi l'opzione `xb_setup_done` ricorda quando e chi.

**Permessi.** Solo chi ha `manage_options` e `xb_build`; il modulo ha il suo nonce.
