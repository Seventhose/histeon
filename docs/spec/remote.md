# Staging → produzione

Implementazione: `plugin/src/Remote/` (`Link`, `Receiver`, `Sender`) e `plugin/src/Admin/RemoteScreen.php`, nella schermata Histeon → Release. Test: `plugin/tests/Integration/RemoteTest.php`, `e2e/specs/remote.spec.ts`.

Un sito di staging manda al sito di produzione ciò che ha online, con i media che usa. Le possibilità sono due:
- **ciò che ha cambiato l'ultima release**: i documenti la cui versione è diversa da quella della release precedente;
- **tutto il sito**.

Il formato è quello del trasloco del sito (`.xbsite`, [site-transfer.md](site-transfer.md)), con la sua validazione stretta. Prima arriva sempre un'anteprima, poi l'invio.

Histeon CMS (Histeon Publish) ha un collegamento e un formato suoi: [cms-bridge.md](cms-bridge.md). I due collegamenti sono indipendenti.

## Abbinamento

1. In produzione un amministratore crea il **codice di abbinamento**: contiene l'indirizzo del sito e un segreto casuale di 32 byte. Il codice si mostra una sola volta, e vale solo l'ultimo creato.
2. Chi crea il codice decide se lo staging può **anche pubblicare**. Se non lo concede, tutto arriva in sviluppo. Lo staging non può chiedere di più.
3. Sullo staging un amministratore incolla il codice in **Invia a produzione**.
4. Lo staging conferma subito l'abbinamento con una richiesta firmata (`POST /xb/v1/remote/pair`, corpo `{"site": "<indirizzo dello staging>"}`): la produzione **fissa quell'indirizzo al collegamento** e da lì accetta pacchetti solo da lì. Il pannello della produzione lo mostra («accetta pacchetti solo da …»).
   - Un pacchetto che dice di venire da un altro sito (`site` del manifesto) è rifiutato, anteprima compresa, prima di qualsiasi scrittura. Senza questo controllo un mittente disonesto potrebbe segnare i contenuti con un'altra origine e farsi elencare nelle cancellazioni i titoli di ciò che è arrivato da altrove, bozze e privati compresi.
   - Una seconda conferma con lo stesso indirizzo va bene; con un altro è rifiutata (409) e lo staging dimentica il codice: per un altro staging serve un nuovo codice, che riparte senza indirizzo. Anche uno staging che cambia indirizzo ha bisogno di un nuovo codice.
   - **Collegamenti senza indirizzo fissato** (abbinati con una versione precedente di Histeon), o la cui conferma non è arrivata (produzione irraggiungibile in quel momento): continuano a funzionare, e il loro **primo pacchetto firmato** (anteprima o invio) fissa l'indirizzo. È la stessa fiducia della conferma: solo chi ha il segreto firma, e il primo a farlo è lo staging a cui è stato dato il codice. Rifiutarli avrebbe costretto a rifare ogni abbinamento senza guadagnare sicurezza; da quel momento la regola vale anche per loro.

Il segreto è cifrato a riposo su entrambi i siti (libsodium, con la chiave derivata da `AUTH_KEY` e `AUTH_SALT` di `wp-config.php`) e non si mostra più. **Revoca il collegamento** in produzione lo invalida subito.

## Richieste

- Rotte di produzione: `POST /xb/v1/remote/plan` per l'anteprima e `POST /xb/v1/remote/import` per l'invio, con il file `.xbsite` come corpo; `POST /xb/v1/remote/pair` per la conferma dell'abbinamento.
- Ogni richiesta ha `X-Histos-Time`, `X-Histos-Nonce` e `X-Histos-Signature`. La firma è HMAC-SHA256 con il segreto su metodo, rotta, ora, nonce e SHA-256 del corpo.
- La produzione rifiuta:
  - una firma diversa (confrontata con `hash_equals`);
  - un'ora fuori da ±5 minuti;
  - un nonce già visto (ricordato per 10 minuti);
  - un corpo oltre 512 MB;
  - una richiesta in HTTP semplice.

  La rotta fa parte della firma: un'anteprima non si può rigiocare come importazione.
- L'importazione gira **con i permessi dell'amministratore che ha creato il codice**. Se questa persona non è più amministratore, la produzione rifiuta e serve un nuovo codice.
- Lo staging invia solo verso indirizzi pubblici (`reject_unsafe_urls`), senza seguire redirect. Riduce la risposta della produzione a testo semplice e limitato prima di mostrarla.
- In sviluppo, cioè con `WP_ENVIRONMENT_TYPE` uguale a `local` o `development` e con l'indirizzo del sito locale (`localhost`, `127.0.0.1`, `::1`, o un nome che finisce in `.local`, `.test` o `.localhost`), sono ammessi HTTP e indirizzi locali, per gli stack di prova. Uno stack di prova con un altro nome usa il filtro `xb_remote_plain_http`.

## Registro

- Su entrambi i siti:
  - `remote_link` e `remote_revoke` per i codici, `remote_pair` in produzione quando l'indirizzo dello staging si fissa;
  - `remote_send` sullo staging;
  - `remote_import` in produzione.
- `remote_refused` registra le richieste rifiutate, al massimo una al minuto, così chi non ha il segreto non riempie il registro.

## Limiti

- Si invia lo stato online dello staging, non le modifiche in sviluppo.
- **Cancellazioni.** Con «Tutto il sito» il pacchetto è completo, e l'anteprima elenca i contenuti che erano arrivati in produzione da questo staging e che lo staging non ha più. Ogni contenuto importato è segnato con il sito d'origine (`_xb_transfer_origin`): quelli scritti in produzione, o arrivati da altrove, non sono mai proposti. I contenuti proposti vanno nel Cestino della produzione solo se lo si sceglie nell'anteprima: l'invio passa allora da `POST /xb/v1/remote/import-delete`, una rotta a sé, così la scelta fa parte della firma. Contano i permessi di chi ha creato il codice (`delete_post`), e il Registro lo segna come `import_site_trash`. Un invio di «Ciò che ha cambiato l'ultima release», o un pacchetto tagliato a 5.000 contenuti, non propone niente.
- I file molto grandi dipendono dai limiti del server di produzione (`post_max_size`). Per un sito intero con molti media conviene il trasloco con il file.
