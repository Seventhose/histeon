# Trasloco del sito

Implementazione: `plugin/src/Versioning/SiteTransfer.php`, azioni e schermata in `plugin/src/Admin/ReleasesScreen.php` (Histeon → Release → «Trasloco del sito»). Test: `plugin/tests/Integration/SiteTransferTest.php`, `e2e/specs/site-transfer.spec.ts`.

Una release (`.xbrelease`, [versioning.md §8](versioning.md)) porta i documenti Histeon da un sito all'altro. Il trasloco porta **tutto il sito**: contenuti, stili, componenti, template, menu, impostazioni e i media che usano, in un file `.xbsite`. Solo gli amministratori (`manage_options` e `xb_build`) esportano e importano.

## 1. Il file

Un archivio ZIP con:

- `site.json`: formato `xb-site`, versione 1, sito e cartella `uploads` di origine, generatore, data, `documents`, `contents`, `media` e `hash` (SHA-256 del manifesto senza l'hash);
- `media/<id>-<nome>`: i file dei media usati.

**`documents`** sono i documenti Histeon come in una release: pagine, articoli, componenti e template con blocchi Histeon (la versione online, cioè la testa di produzione), più fogli di stile e impostazioni.

**`contents`** sono i dati di ogni contenuto dei tipi `page`, `post`, `wp_block`, `wp_template`, `wp_template_part`, `wp_navigation` e dei tipi di contenuto definiti in Histeon: tipo, chiave (slug, chiave del componente, slug del template), titolo, stato, data, riassunto, ordine, genitore (per chiave), immagine in evidenza, termini (slug e nome). Per un contenuto che non è un documento Histeon c'è anche il testo.

**`media`** sono gli allegati usati: ogni indirizzo sotto `uploads` trovato nei documenti e nei contenuti (anche una copia ridimensionata `-300x200` o l'originale `-scaled` porta all'allegato), più le immagini in evidenza. Per ognuno: file, indirizzo originale, tipo, testo alternativo, titolo, didascalia e gli indirizzi effettivamente usati (`variants`).

Non sono inclusi utenti, commenti, opzioni degli altri plugin, le revisioni e la storia delle versioni.

## 2. Esportazione

«Esporta tutto il sito» crea il file nella cartella temporanea del server, lo scarica e lo cancella. Viene esportato quello che è online: le modifiche ancora in sviluppo non partono.

## 3. Importazione

Due passi, come per le release.

1. **Controlla il file.** Il file caricato va nella cartella temporanea con un nome casuale e viene letto senza scrivere nulla. Si vede cosa cambierebbe (documenti, contenuti da creare o aggiornare, media, avvisi). Il file resta 30 minuti, legato all'utente; «Annulla» lo cancella.
2. **Conferma.**
   - **Importa in sviluppo:** media caricati, documenti Histeon come modifiche di sviluppo, contenuti nuovi come bozze. Niente va online finché non pubblichi una release e le pagine.
   - **Importa e pubblica:** solo per chi ha anche `xb_publish`. Dopo l'importazione pubblica una release con i documenti importati («Trasloco del sito») e riporta ogni contenuto allo stato che aveva sul sito di origine.

**Ordine.**
1. Media: ognuno passa da `media_handle_sideload`, con i controlli di WordPress sul tipo di file. Un tipo non ammesso qui viene saltato con un avviso. Gli indirizzi vecchi diventano i nuovi: l'originale e ogni copia ridimensionata usata, con la stessa misura se WordPress l'ha creata, altrimenti l'originale.
2. L'indirizzo del sito di origine diventa quello di questo sito, nel testo semplice e in quello JSON.
3. Documenti Histeon: passano dall'importazione delle release (convalida, permessi, commit di sviluppo). Le impostazioni portano anche sorgenti dati, token, parametri di Histeon CSS, e, solo per gli amministratori, tipi di contenuto e campi pubblici.
4. Contenuti: `wp_insert_post` o `wp_update_post` con i filtri di WordPress per il ruolo di chi importa (kses senza `unfiltered_html`). Poi immagine in evidenza, termini (creati se mancano e se l'utente può gestirli) e genitore.

**Una seconda importazione dello stesso file non duplica nulla.** I media vengono ritrovati dalla loro origine (`_xb_import_source`, sito + id), i contenuti dalla chiave. Così, dopo aver pubblicato le impostazioni con nuovi tipi di contenuto, si importa di nuovo il file per i contenuti di quei tipi: al primo passaggio vengono saltati con un avviso, perché WordPress li registra solo dalla richiesta successiva.

## 4. Sicurezza

- Il file è un dato, mai codice.
  - Sono ammessi solo `site.json` e file `media/<numero>-<nome>` con caratteri sicuri. Qualsiasi altro nome (percorsi con `..`, sottocartelle, file sparsi) rifiuta tutto il file.
  - Ci sono limiti al numero di file (20 000), al manifesto (64 MB), a ogni media (256 MB) e ai contenuti (5000).
  - L'hash deve corrispondere, formato e versione devono essere quelli attesi, gli indirizzi di origine devono essere `http` o `https`, e ogni contenuto deve avere tipo, chiave e stato validi.
- I file dei media passano dai controlli di WordPress: un `.php` o un tipo non ammesso viene saltato.
- Si applicano i permessi di chi importa: un contenuto che non può modificare o creare viene saltato, e la pubblicazione richiede `manage_options` e `xb_publish`. Senza pubblicazione (o senza quei permessi, o da un collegamento di staging che non può pubblicare) un contenuto già online non cambia: titolo, testo, data, termini e immagine restano; un documento Histeon riceve solo il commit di sviluppo, da pubblicare dopo. L'import lo segnala.
- Il file in attesa di conferma vive nella cartella temporanea del server, non in `uploads`. Il percorso salvato viene accettato solo se è dentro quella cartella e ha estensione `.xbsite`. Il file viene cancellato dopo la conferma, l'annullamento o un nuovo caricamento.
- Esportazione e importazione finiscono nel registro (`export_site`, `import_site`).

## 5. Limiti noti

- I template vanno nel tema attivo: la chiave è lo slug del template, come per le release.
- I link interni tra contenuti seguono l'indirizzo del sito. Un ID numerico scritto nel testo (per esempio `wp:navigation-link` con `id`) resta quello del sito di origine.
- Un contenuto cancellato sul sito di origine non viene cancellato qui con il trasloco da file. Lo fa, se lo si sceglie, l'invio da staging a produzione di tutto il sito ([remote.md](remote.md), «Cancellazioni»), che usa lo stesso file con `complete: true`. L'importazione segna ogni contenuto con il sito d'origine (`_xb_transfer_origin`).
- I file molto grandi dipendono dai limiti di caricamento del server (`upload_max_filesize`, `post_max_size`).
