# Registro

Implementazione: `plugin/src/Admin/AuditScreen.php` e `Store::audit_page()` / `Store::audit_facets()` in `plugin/src/Versioning/Store.php`. Test: `plugin/tests/Integration/AuditScreenTest.php`, `e2e/specs/audit.spec.ts`.

Histeon annota le operazioni importanti nella tabella `xb_audit` (`Store::audit()`): data in UTC, utente, operazione, oggetto e dettagli in JSON. **Histeon → Registro** le mostra agli amministratori (`manage_options` e `xb_build`), come fa la rotta REST `GET /xb/v1/audit`.

## Cosa si vede

- **Una riga per voce, dalla più recente**:
  - quando, nella data e nell'ora del sito;
  - chi;
  - l'operazione, in parole: per esempio `publish` diventa «Release pubblicata». Un'operazione che Histeon non conosce mostra la sua chiave;
  - l'oggetto: una release diventa «Release 1.2», un documento il suo nome, se l'utente lo può vedere;
  - i dettagli, come righe «chiave: valore», con gli elenchi accorciati a 8 elementi e al massimo 12 righe.
- **Filtri**: operazione e persona (solo quelle presenti nel registro), dal giorno, al giorno (giorni del sito, convertiti in UTC), 50 voci per pagina.
- **Esporta CSV**: le ultime 500 voci con gli stessi filtri, con un nonce.

## Sicurezza

- Solo lettura. I filtri sono valori legati con `$wpdb->prepare`, mai SQL: un'operazione accetta solo caratteri da chiave, una data solo `AAAA-MM-GG`.
- Tutto si stampa come testo (`esc_html`), compresi oggetto e dettagli scritti da altri codici, come gli agenti IA. Nessun markup dai dati.
- Nel CSV, una cella che inizia come una formula (`=`, `+`, `-`, `@`, tabulazione, a capo) prende un apostrofo davanti, contro l'iniezione di formule nei fogli di calcolo.

## Limiti

- Il registro non ha una scadenza: cresce con le operazioni, che sono poche (non c'è una riga per ogni visita). Si svuota disinstallando Histeon.
