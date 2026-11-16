# Anteprima

Implementazione:
- `editor/src/components/PreviewMode.svelte`;
- `Topbar.openPreview`;
- `plugin/src/Versioning/Preview.php`.

Test: `e2e/specs/publish.spec.ts`, `e2e/specs/templates.spec.ts`.

## Come funziona
- **Apertura:** **Anteprima** salva in sviluppo e mostra la pagina **sopra il builder**, a tutto schermo. È la pagina vera, resa da WordPress con i suoi script (slider, menu, filtri), dal link di anteprima firmato e a scadenza: chi non ha il link non vede il lavoro in sviluppo. Non è il disegno della tela.
- **Larghezza:** Intera, Tablet (820 px) o Telefono (390 px).
- **Durata del link:** 1 ora, 1 giorno, 2 giorni (di base) o 7 giorni; il builder chiede un link con quella durata (`ttl` di `GET /preview-link`, solo quei quattro valori) e scrive «Scade il …» con la scadenza della risposta (`expires`). **Copia link** lo copia; la pagina mostrata non si ricarica.
- **Nuova scheda:** apre il link con la durata scelta fuori dal builder (`rel="noopener noreferrer"`).
- **Ritorno:** **Torna al builder** o `Esc` riportano il builder com'era, anche dopo un clic dentro la pagina. Il focus torna sul pulsante Anteprima. Mentre l'anteprima è aperta, le scorciatoie del builder sono sospese.
- **Barra di amministrazione:** nell'anteprima non c'è, perché chi visita non la vede.
- **Template:** l'anteprima di un template si apre sull'articolo di esempio scelto.

**Componenti non ancora pubblicati.** La chiave di un componente si scrive sul suo post quando una release lo pubblica. Un componente creato o importato dopo l'ultima release si trova quindi, in anteprima e nel builder, dalla chiave della sua versione di sviluppo (`Preview::dev_component()`), solo se nessun componente pubblicato ha già quella chiave. Prima della prima release dopo un'importazione da Etch, senza questo, ogni componente disegnava il vuoto e la pagina restava vuota.

Non apre più una scheda vuota da riempire dopo il salvataggio: alcuni browser bloccavano quella finestra e il pulsante sembrava non fare nulla.


## Proposte per il cliente

`Versioning\Proposals` tiene nell'opzione `xb_proposals` (al massimo 20, senza caricamento automatico) istantanee con nome: documento → commit, prese dalle teste di sviluppo dei documenti cambiati. Non si copia nulla e il modello resta sviluppo e online.

- **Link.** Il token di una proposta ha cinque parti, `<scadenza>.<post>.<utente>.<proposta>.<hmac>`, con la proposta dentro la firma (`|proposal|<id>`): cambiare l'id rompe la firma. Dura `PROPOSAL_TTL` (7 giorni). Per il resto vale tutto ciò che vale per un link di pagina (utente ancora autorizzato, segreto, `noindex`, nessuna cache). Una proposta eliminata fa vedere il sito online.
- **Cosa mostra.** `Preview::payload()` legge per i documenti della proposta il loro commit, per tutti gli altri la testa online: il lavoro in sviluppo fuori dalla proposta non si vede. Gli script sono bloccati se uno dei commit non è fidato.
- **Confronto.** `?xb_compare=<token>~<token>` restituisce una pagina con i due link in due iframe e i nomi, con CSP propria (`default-src 'none'; frame-src 'self'`); ogni iframe controlla il suo token.
- **Scelta.** `Proposals::choose()` porta in sviluppo, con `Versioning::revert()`, i documenti della proposta alla loro versione e gli altri documenti cambiati alla versione online: la release successiva è la proposta. Tutto resta nella storia.


## Anteprima vista come ruolo

Nella barra dell'anteprima del builder, **Vedi come** chiede un nuovo link firmato con il ruolo: `<extra>` del token è `as-<ruolo>` (o `<proposta>-as-<ruolo>`), dentro l'HMAC. I ruoli possibili (`Preview::roles()`) sono `guest` e i ruoli del sito che non possono scrivere (`edit_posts` assente: abbonato, il cliente del negozio…). Con un ruolo la richiesta diventa anonima per WordPress (`wp_set_current_user(0)`) e cambia solo `user` di Histeon: un visitatore di prova (`loggedIn` vero, nome «Visitatore di prova», `role` quello scelto), oppure nessuno per `guest`. Nessun account viene usato o impersonato; ciò che WordPress o altri plugin mostrano per ruolo (il conto del cliente in WooCommerce) resta quello di un visitatore anonimo.
