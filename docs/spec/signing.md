# Firma degli export fra siti

Implementazione: `plugin/src/Versioning/Signing.php`, con `Transfer` (`.xbrelease`),
`SiteTransfer` (`.xbsite`), la sezione **Firma degli export** in Histeon → Impostazioni e le anteprime di
importazione in Histeon → Release. Test: `plugin/tests/Integration/SigningTest.php`.

## Il problema

Un file `.xbrelease` o `.xbsite` ha già lo SHA-256 del suo contenuto, che scopre un file rovinato ma non uno
cambiato apposta: chi cambia il contenuto ricalcola anche l'hash. Chi importa non sa da quale sito arriva davvero
il file. Il collegamento staging → produzione ([remote.md](remote.md)) non ha questo problema (ogni richiesta è
firmata con un segreto condiviso), ma i file passano anche a mano: una mail, una chiavetta, un'altra agenzia.

## Scelte

Semplici, senza servizi esterni, senza nulla di commerciale: libsodium, già in PHP.

1. **Una chiave per sito, generata sul sito.** Ed25519 (`sodium_crypto_sign_keypair`), creata alla prima
   esportazione o quando un amministratore la chiede. La chiave privata non lascia mai il sito: sta nell'opzione
   `xb_signing_key`, cifrata a riposo come il segreto del collegamento (secretbox, chiave derivata da `AUTH_KEY` e
   `AUTH_SALT` di `wp-config.php`). Senza quelle costanti il sito non firma: lo dice, e l'export resta senza firma.
2. **L'impronta.** Lo SHA-256 della chiave pubblica, in 16 gruppi di 4 cifre esadecimali
   (`3F2A 9C01 …`). È ciò che due persone confrontano, a voce o per iscritto, fuori dal file.
3. **Cosa si firma.** Il file intero tranne la firma stessa: il contenuto in forma canonica (JSON con le chiavi
   degli oggetti in ordine, le liste come sono, senza escape di `/` e Unicode), preceduto da `xb-signed-v1|` e dal
   formato. Nel `.xbsite` la firma copre il manifest, e il manifest ha lo SHA-256 di ogni file di media: quindi
   copre anche i media. Il file porta `signature`: `{alg: "ed25519", key, sig, site, rotation?}`. Il `site` della
   firma non è firmato: l'anteprima e l'elenco delle chiavi mostrano il `site` del contenuto firmato, e usano
   quello della firma solo se il file non ne ha uno. Un file che non si può scrivere in JSON non si firma e non
   passa il controllo.
4. **Fiducia fissata al primo abbinamento (TOFU).** Il sito che importa tiene un elenco di chiavi fidate
   (`xb_trusted_keys`: chiave, impronta, sito dichiarato, chi e quando). All'anteprima:
   - **firma valida di una chiave fidata:** si importa come sempre;
   - **firma valida di una chiave nuova:** l'anteprima mostra l'impronta e il sito dichiarato, e chiede di
     confrontarla con quella del sito d'origine (Impostazioni → Firma degli export). L'importazione passa solo se
     un amministratore spunta «Mi fido di questa chiave»: da quel momento la chiave è fidata;
   - **firma non valida** (file cambiato dopo la firma, o firma rovinata): rifiutato, sempre;
   - **chiave revocata:** rifiutato, sempre;
   - **senza firma** (file di prima, o di un sito senza `AUTH_KEY`): l'anteprima lo dice, e si importa solo
     spuntando «Importa anche senza firma». Chi vuole può vietarlo: **Richiedi la firma** in Impostazioni.
5. **Rotazione.** **Genera una nuova chiave** sostituisce la chiave del sito. La nuova chiave è firmata con la
   vecchia (`rotation: {from, sig}` nei file successivi), così un sito che si fidava della vecchia passa da solo
   alla nuova, e la vecchia smette di valere lì. La vecchia chiave privata si cancella.
6. **Revoca.** Sul sito che importa, ogni chiave fidata ha **Revoca**: i file firmati con lei sono rifiutati, e
   una rotazione che parte da lei non vale. Se la chiave privata di un sito è stata rubata, la rotazione non basta
   (chi l'ha rubata potrebbe firmarne un'altra): si revoca la vecchia chiave sui siti che la usano e si fa fidare
   la nuova da capo, confrontando l'impronta. L'elenco tiene al massimo 200 chiavi fidate; le revocate restano
   sempre, così un file firmato con una di loro non torna a sembrare «nuovo».
7. **Chiave illeggibile.** Se le chiavi di `wp-config.php` cambiano, la chiave privata cifrata non si legge più:
   gli export escono senza firma, un avviso lo dice agli amministratori, e **Genera una nuova chiave** ne crea una
   nuova (senza `rotation`, perché la vecchia non può garantirla: chi importa la confronta da capo).

## Cosa non cambia

- I file restano quelli di prima con un campo in più: un sito che non conosce la firma la ignora.
- Il collegamento staging → produzione resta autenticato dal suo segreto; i suoi `.xbsite` portano la firma ma non
  chiedono la fiducia (la produzione sa già chi è lo staging).
- La firma dice da quale sito arriva il file, non che il contenuto sia buono: l'importazione passa sempre dalle
  stesse convalide e dai permessi di chi importa.

## Registro

`signing_key_created`, `signing_key_rotated`, `signing_key_trusted`, `signing_key_revoked`, `import_unsigned`.

**Progetti di Histeon Publish.** I pacchetti di progetto (`.xbsite` v2) non hanno firma. Con «Richiedi la firma» attivo, «Importa un file» della pagina Release li rifiuta; restano il collegamento autenticato con Histeon Publish e il pacchetto che l'import HTML costruisce nella stessa schermata.
