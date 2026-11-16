# Permessi e sicurezza

Su un sito lavorano persone diverse: chi costruisce, chi pubblica, chi scrive solo i testi. Histeon usa i ruoli e i permessi di WordPress per decidere chi può fare cosa, e aggiunge alcuni permessi suoi. Questo capitolo li spiega, e racconta in parole semplici cosa fa Histeon per tenere il sito al sicuro.

## I permessi di Histeon

Histeon aggiunge quattro permessi (in WordPress si chiamano *capabilities*):

| Permesso | Cosa permette |
|---|---|
| `xb_build` | Usare il builder: modificare pagine, stili, componenti e query predefinite, e salvare in sviluppo |
| `xb_publish` | Pubblicare le release e ripristinare quelle precedenti: cioè cambiare il sito online |
| `xb_manage_deploy` | Gestire le destinazioni del sito statico (Cloudflare) e le loro credenziali |
| `xb_edit_content` | **Modalità cliente**: cambia testi e immagini delle pagine che può modificare, nel builder, in sviluppo. È il permesso del ruolo **Cliente Histeon**. |
| `xb_edit_props` | In modalità cliente, cambia anche le props semplici dei componenti di quelle pagine (testo, numero, sì/no, link, immagine, scelta da un elenco), mai espressioni o codice. **Spento di serie**: nessun ruolo lo riceve, nemmeno Cliente Histeon; lo dai tu, con un editor dei ruoli o con `wp cap add xb_client xb_edit_props`. |

All'attivazione di Histeon, gli **amministratori** li ricevono tutti. Histeon crea anche il ruolo **Cliente Histeon**: può modificare (non pubblicare né eliminare) pagine e articoli, caricare immagini e usare il builder solo in modalità cliente. Le pagine fatte con Histeon le cambia solo dal builder, mai dall'editor a blocchi. Gli altri ruoli non hanno permessi di Histeon finché non glieli dai tu.

## Chi può fare cosa

Oltre ai permessi di Histeon contano quelli normali di WordPress:

| Per… | Serve |
|---|---|
| Aprire una pagina nel builder | `xb_build` e il permesso di modificare quella pagina |
| Modificare template e parti di template | Anche il permesso di modificare il tema (`edit_theme_options`) |
| Pubblicare con **Pubblica…** | `xb_publish` |
| Caricare immagini e file | Il permesso di WordPress di caricare file |
| Scrivere JavaScript e codice libero | `unfiltered_html` (sotto) |
| Impostazioni, Moduli, font locali | Essere amministratori (con `xb_build`) |
| Esportare e importare release, traslocare il sito | Essere amministratori |
| Configurare Cloudflare | Essere amministratori, con `xb_manage_deploy` |

Senza `xb_publish` puoi costruire e salvare in sviluppo, ma non mettere online: la schermata **Release** te lo dice («Per pubblicare serve il permesso xb_publish: chiedi a chi gestisce il sito»). È un modo semplice per far lavorare un collaboratore o un'agenzia lasciando a te l'ultima parola.

Chi modifica solo i testi (per esempio un cliente) può continuare a usare l'editor a blocchi di WordPress, che mostra le pagine Histeon come sul sito.

## Che cosa può fare ogni cliente

Di serie un cliente (ruolo **Cliente Histeon**) cambia testi e immagini di tutte le pagine che può modificare. In **Histeon → Clienti** (o dal link **Permessi Histeon** nell'elenco degli utenti) chi costruisce o amministra il sito sceglie, cliente per cliente:

- **che cosa può modificare**: testi e immagini (sempre), le **props dei componenti** (come `xb_edit_props`; se il ruolo lo dà già, la casella resta spuntata) e l'**aspetto della pagina**, con i gruppi del pannello Aspetto che può usare (Impaginazione, Spazi, Dimensioni, Testo, Colori e bordi, Posizione);
- **su quali pagine**: tutte quelle che può modificare, oppure solo un elenco. Nell'elenco ogni pagina ha un livello: **Nessuno** (non la apre e non la modifica nemmeno in WordPress), **Solo vedere nel builder**, **Testi e immagini**, **Anche aspetto**. Le pagine che non scegli restano senza accesso.

**L'aspetto della pagina vale solo lì.** Se il cliente cambia il colore di un titolo nella pagina «Contatti», cambia solo in quella pagina: la stessa classe sulle altre pagine resta com'è. Lo fa dal pannello **Histeon · Aspetto** in Gutenberg o dalla sezione **Aspetto di questa pagina** nel builder, con i soli pulsanti dei gruppi che gli hai dato; salva in sviluppo e va online con la prossima release, come i testi. Anche tu, in Gutenberg, puoi scrivere uno stile solo per la pagina con l'interruttore **Solo questa pagina**.

> Nessun cliente riceve nuovi permessi con l'aggiornamento: finché non scegli nulla, vale quello di prima. Il cliente non vede questa schermata e non può cambiare i permessi suoi o di altri: il server rifiuta ogni salvataggio fuori da ciò che gli hai dato.

## Dare i permessi ad altri ruoli

Histeon non ha una sua schermata dei ruoli: usa quella di WordPress. Per dare `xb_build` o `xb_publish` a un ruolo (per esempio all'editor) usa un qualsiasi plugin per la gestione dei ruoli e dei permessi, cerca i permessi che cominciano con `xb_` e assegnali.

Un esempio di divisione dei compiti:

| Persona | Ruolo WordPress | Permessi di Histeon |
|---|---|---|
| Chi gestisce il sito | Amministratore | Tutti |
| Sviluppatore esterno | Editor | `xb_build` |
| Responsabile dei contenuti | Editor | `xb_build`, `xb_publish` |
| Cliente che cambia i testi | Autore o editor | Nessuno: usa l'editor a blocchi |

> Dai a ciascuno solo quello che gli serve. Vale anche per gli agenti IA: lavorano con i permessi dell'utente con cui si collegano, e un utente senza `xb_publish` non può pubblicare nulla. Vedi [Histeon e l'intelligenza artificiale](#it-intelligenza-artificiale).

## Chi può scrivere codice

In WordPress il permesso `unfiltered_html` decide chi può inserire codice libero: script, gestori di eventi, qualsiasi tag. Su un sito singolo di solito ce l'hanno amministratori ed editor; non ce l'hanno autori e collaboratori. Histeon lo rispetta ovunque.

| Chi | Livello in Histeon | Cosa può scrivere |
|---|---|---|
| Ha `unfiltered_html` | **trusted** (fidato) | Qualsiasi elemento e attributo, il JavaScript della pagina e dei componenti |
| Non lo ha | **restricted** (limitato) | Elementi di contenuto, SVG statici, stili; niente script, niente `onclick` e simili, niente link `javascript:` |

Cosa vedi in pratica:

- il pannello del JavaScript è in **sola lettura** per chi non ha `unfiltered_html`, con il messaggio che lo spiega;
- se una persona «limitata» modifica una pagina che contiene codice scritto da una persona «fidata», quel codice resta **intatto e bloccato**: non lo può cambiare né duplicare;
- al contrario, un contenuto scritto da una persona limitata **resta limitato** anche quando un amministratore salva, pubblica o ripristina la pagina che lo contiene. Il codice non diventa mai «fidato» solo perché passa dalle mani giuste.

Le regole valgono su ogni strada: il builder, l'editor a blocchi, le importazioni di release e di siti Etch, gli agenti IA.

> Nel builder non si scrive PHP, per scelta: sarebbe codice eseguito sul server a portata di chiunque modifichi i contenuti. Il PHP del sito, nei file del tema o di un plugin, si usa nel builder come dati e blocchi dinamici.

## Cosa fa Histeon per la sicurezza

Ecco le protezioni principali, in parole semplici.

**Il builder è protetto.**

- La pagina del builder ha una politica di sicurezza dei contenuti (CSP) rigida: gira solo il codice di Histeon, e la pagina non si può incorporare in altri siti.
- L'anteprima nella tela è isolata: gli script della pagina non girano, i link non navigano e i form non si inviano.
- La tela carica immagini, video, font e fogli di stile solo dal sito (anche dalla sua CDN dei media) e dalle origini scritte in **Histeon → Impostazioni → Origini esterne ammesse nella tela**: così l'indirizzo di chi lavora non arriva a siti terzi. Un'immagine da un'altra origine si vede come un rettangolo grigio con l'origine nel suggerimento; nella pagina pubblicata resta com'è. Anche i font di un foglio esterno (per esempio Google Fonts) non arrivano nella tela: meglio scaricarli sul sito (Design system → Font del sito).
- Le richieste del builder al sito sono controllate con i permessi dell'utente e con i codici di sicurezza di WordPress.

**Niente va online per sbaglio.**

- Tutto quello che salvi va **in sviluppo**. Il sito cambia solo con una release, e ogni release si può ripristinare.
- Ogni versione salvata ha un'impronta (SHA-256) che viene ricontrollata quando si legge. Una release alterata non viene ripristinata.
- Histeon tiene un **registro delle operazioni** importanti: pubblicazioni e ripristini, importazioni, esportazioni, configurazioni, modifiche fatte dagli agenti IA. Gli amministratori lo leggono in **Histeon → Registro**, con i filtri per operazione, persona e giorni, e lo esportano in CSV.

**Anteprime e sito statico non lasciano scappare nulla.**

- I link di anteprima sono firmati, scadono, valgono solo per quella pagina e quell'utente, e non vengono indicizzati. Si possono revocare tutti insieme.
- Prima di pubblicare la copia statica su Cloudflare o di scaricarla come zip, Histeon la controlla: se trova dati privati o di sessione (percorsi del server, codici di sicurezza, token di anteprima), si ferma.

**Credenziali e dati personali.**

- Il token di Cloudflare si mette in `wp-config.php` oppure si salva cifrato, e non viene mai mostrato né restituito.
- I messaggi dei form sono visibili solo agli amministratori, si conservano per 180 giorni e si esportano o cancellano con gli strumenti privacy di WordPress.
- I font si possono ospitare sul sito, così le pagine non chiedono i font ai server di Google.

**Aggiornamenti firmati, nessuna licenza.**

- Nessuna chiave di licenza e nessuna telemetria.
- Gli aggiornamenti sono spenti finché non li accendi, e si installano solo se firmati con la chiave di Histeon. Vedi [Moduli e aggiornamenti](#it-moduli-e-aggiornamenti).

**Nessuna chiamata esterna che tu non abbia chiesto.** Histeon si collega a servizi esterni solo per le funzioni che attivi tu:

| Funzione | Verso dove | Quando |
|---|---|---|
| Aggiornamenti | GitHub | Solo se li accendi, al massimo ogni 12 ore |
| Font locali | Google Fonts | Solo quando importi un font, una volta |
| Sito statico | Cloudflare | Solo se configuri la pubblicazione |
| Traduzione automatica (Lingua Bridge) | Il servizio che scegli tu | Solo con la tua chiave, su richiesta |
| Video YouTube o Vimeo | YouTube o Vimeo | Quando inserisci un video: titolo e immagine di anteprima, una volta |
| Sorgente JSON **Da un indirizzo** | L'indirizzo che scrivi tu | Solo se crei una sorgente di questo tipo |
| Invio dallo staging alla produzione | Il sito di produzione abbinato | Solo quando invii |
| Nomi proposti da un modello in **Converti una pagina HTML** | Anthropic, o un server compatibile che scegli tu | Solo se imposti la tua chiave o il tuo server |

**Agenti IA con le briglie.** Il connettore accetta solo un elenco chiuso di operazioni, ascolta solo sul tuo computer e non può pubblicare. Le abilità di WordPress lavorano con i permessi dell'utente, e un agente non pubblica da solo: la richiesta di pubblicare porta alla pagina Release, dove pubblica una persona.

**Alla disinstallazione** Histeon toglie le sue tabelle, opzioni, segreti e permessi. Restano i contenuti delle pagine.

## Installazione blindata

Ogni plugin di WordPress, moduli di Histeon compresi, gira nello stesso processo PHP con gli stessi permessi su database e file: un punto debole in uno qualsiasi riguarda tutto il sito. Per questo un modo efficace di ridurre i rischi non è dividere i plugin, ma **togliere WordPress dalla strada dei visitatori**. Se il sito lo permette, conviene questa configurazione.

1. **Sito pubblico statico.** Pubblica la copia statica su Cloudflare oppure servila con Static Bridge (vedi [Il sito statico](#it-sito-statico)). Chi visita riceve solo HTML e file: non esegue PHP e non tocca il database. Restano dinamici solo i filtri dei loop e i form, se li usi.
2. **Amministrazione privata.** Rendi raggiungibili `wp-admin` e `wp-login.php` solo da chi lavora al sito: una regola del server per indirizzi IP fidati, una VPN, oppure un servizio di accesso come Cloudflare Access davanti all'indirizzo di WordPress. Con la pubblicazione su Cloudflare, WordPress può stare su un indirizzo che il pubblico non conosce.
3. **Solo i moduli che usi.** Spegni in **Histeon → Moduli** quelli che non servono: un modulo spento non registra pagine, rotte né operazioni. Per esserne certi anche se qualcuno riaccende l'interruttore, scrivilo in `wp-config.php`:

```php
define( 'XB_DISABLE_MODULES', 'lingua-bridge,static-bridge' );
```

4. **Credenziali fuori dal database.** Metti il token di Cloudflare in `wp-config.php` (`XB_CLOUDFLARE_API_TOKEN`) invece che nelle impostazioni.
5. **Niente modifiche ai file da wp-admin.** Con `define( 'DISALLOW_FILE_EDIT', true );` in `wp-config.php` nessuno può modificare il codice di temi e plugin dall'amministrazione.
6. **Pochi utenti con i permessi forti.** Dai `unfiltered_html` e `xb_publish` solo a chi serve (vedi [Chi può scrivere codice](#it-permessi-e-sicurezza-chi-puo-scrivere-codice)).
7. **Aggiornamenti firmati.** Accendili e lascia che Histeon installi solo le versioni firmate (vedi [Moduli e aggiornamenti](#it-moduli-e-aggiornamenti)).

> Non serve Docker né un server dedicato: tutto questo si fa anche su un hosting normale, con il sito statico su Cloudflare. Chi ospita in proprio può fare un passo in più e tenere WordPress su una rete interna.
