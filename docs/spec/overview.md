# Panoramica

Implementazione: `plugin/src/Admin/OverviewScreen.php`, `plugin/src/Admin/SiteStatus.php`. Test: `plugin/tests/Integration/SiteStatusTest.php`.

**Histeon → Panoramica** mostra cosa è online, cosa aspetta di andarci e da dove ripartire: documenti da pubblicare, release online, pagine e componenti, le ultime modifiche.

## Stato del sito
Una scheda sola con quello che merita uno sguardo, ogni riga con un pallino (verde a posto, corallo da guardare) e il link a dove si sistema.
- **Moduli:** acceso, spento, plugin separato, PHP insufficiente, spento da `wp-config.php`. Un modulo **fermato dopo un errore fatale** dice file e riga (`FatalGuard`).
- **Copia statica:** con Static Bridge attivo, pagine statiche e da rigenerare, e l'ultimo esito; corallo se l'export è fallito.
- **Importazioni consigliate:** le righe del promemoria d'importazione: contenuti salvati da Etch non ancora importati, e i fogli di Automatic.css già presenti nel sito, che restano caricati finché non si importano in Histeon CSS.
- **Righe dei moduli:** il filtro `xb_site_status` riceve la lista e ne aggiunge. Lingua Bridge vi mette le traduzioni da fare. Histeon non conosce il modulo: tiene solo `label`, `value`, `state` (`ok`, `warn`, `info`) e `url`, come testo semplice, al massimo 10 righe, e solo link dentro l'amministrazione di questo sito.
