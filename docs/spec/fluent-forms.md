# Fluent Forms nel builder

Implementazione: `plugin/src/Integrations/FluentForms.php`, `Admin\BuilderScreen::framed_dashboard()`, `editor/src/components/FormEditor.svelte`, `InsertMenu.svelte` e `InfoPanel.svelte`. Test: `plugin/tests/Integration/FluentFormsTest.php` e `e2e/specs/fluent-forms.spec.ts`; tutti e due si saltano dove Fluent Forms non è attivo.

Histeon non costruisce moduli. Usa quelli di **Fluent Forms**, se è attivo, senza uscire dal builder.

- **Inserire**: il menu Aggiungi ha il gruppo **Moduli (Fluent Forms)** con i moduli del sito (`GET /integrations/fluentforms`: id e titolo, i più recenti per primi, al massimo 200). Un modulo entra nella pagina come blocco di Fluent Forms (`fluentfom/guten-block` con `formId`), conservato così com'è (`xb/passthrough`) e disegnato da Fluent Forms, sulla tela e sul sito.
- **Modificare**: con il modulo selezionato, **Modifica il modulo** apre l'editor di Fluent Forms (`page=fluent_forms&route=editor&form_id=…`) in una finestra del builder. È un iframe sulla stessa origine, senza i menu di WordPress (`xb_frame`). Alla chiusura l'anteprima si aggiorna.
- **Creare**: **Nuovo modulo Fluent Forms…** apre la schermata di creazione di Fluent Forms nella stessa finestra.

## Permessi

- Il gruppo, la rotta e la cornice dell'editor richiedono `xb_build` **e** il permesso di Fluent Forms `fluentform_forms_manager` (`Acl::hasPermission`), come nel suo menu.
- La pagina di Fluent Forms dentro la cornice resta la sua: permessi, nonce e salvataggi sono quelli di Fluent Forms. Histeon nasconde solo la cornice di WordPress, lato presentazione.
- Invii, voci, notifiche e privacy restano di Fluent Forms. Sul sito statico funzionano con Static Bridge.
