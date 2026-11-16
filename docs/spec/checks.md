# Controlli della pagina

Implementazione:
- `editor/src/lib/checks.ts`;
- `editor/src/components/ChecksPanel.svelte`, sezione **Controlli** della barra laterale;
- `editor/src/components/PublishChecks.svelte`, nella finestra Pubblica.

Test: `editor/test/checks.test.ts`, `e2e/specs/checks.spec.ts`, `plugin/tests/Integration/ChecksBlockTest.php`, `HistosCssTest::test_the_palette_says_how_readable_it_is`.

I controlli guardano la pagina **come è disegnata nella tela**: l'HTML vero e gli stili calcolati del sito. Si rifanno dopo ogni nuovo disegno: con il pannello chiuso solo le regole che non leggono stili né misure (quelle degli errori che il contatore mostra), tutte quando il pannello si apre e nella finestra Pubblica (`quick`). Lo sfondo di ogni elemento si calcola una volta per giro, da quello del genitore. L'icona dei Controlli mostra il numero di errori. **Mostra** seleziona l'elemento e torna alla tela.

| Area | Controllo | Gravità |
|---|---|---|
| Accessibilità | immagine senza `alt` (decorativa: `alt=""`) | errore |
| Accessibilità | immagine senza file (il contenuto non ne ha: va in un `{#if}`) | da rivedere |
| Accessibilità | link senza testo né nome accessibile | errore |
| Accessibilità | link dal testo vago («clicca qui», «leggi di più»…) | da rivedere |
| Accessibilità | link senza indirizzo, nuova scheda senza `rel="noopener"` | da rivedere |
| Accessibilità | pulsante senza testo né `aria-label` | errore |
| Accessibilità | campo senza etichetta | errore |
| Accessibilità | iframe senza `title` | errore |
| Accessibilità | livello di titolo saltato, titolo vuoto | da rivedere |
| Accessibilità | contrasto del testo sotto 4,5:1 (3:1 per il testo grande), su sfondo a tinta unita | da rivedere |
| SEO | nessun H1 in una pagina, un secondo H1 | da rivedere |
| Prestazioni | immagine senza `width`/`height` (la pagina salta) | consiglio |
| Prestazioni | immagine molto più grande di come appare, senza `srcset` | da rivedere |
| CSS | il lint del CSS (`styles.md`, «Controllo del CSS») dei selettori che la pagina usa, sul primo elemento con la classe | come il lint |

**Il colore che passa.** Un contrasto insufficiente dice in chiaro che il testo si legge a fatica e propone il colore più vicino che basta: stessa tinta e stessa saturazione in OKLCH, si muove solo la luminosità, verso lo scuro o il chiaro secondo quale cambia meno (`contrastFix`, ricerca binaria, arrotondato dal lato che continua a passare). Se nessuna luminosità basta, chiede di cambiare lo sfondo. I controlli girano anche prima di pubblicare (dialogo di pubblicazione) e possono bloccarla (`docs/manual` cap. 12).

**Colori OKLCH.** I colori di Histeon CSS arrivano dal browser come `oklch()` (o `oklab()`, `color(srgb …)` per i `color-mix`): il controllo del contrasto li converte in sRGB con la stessa definizione del server, invece di saltarli.

## Leggibilità della palette (Histeon CSS)
Nel generatore di Histeon CSS (Design system), la sezione **Leggibilità** mostra il contrasto WCAG delle coppie che ogni sito usa: testo, titoli, testo secondario e link sullo sfondo e sulle superfici, e il testo che Histeon CSS mette su ogni famiglia di colore (`--xb-on-*`). Serve almeno 4,5:1. Il calcolo è del server (`Tokens::contrast`, in `GET /tokens` e `POST /design/preview`) e si aggiorna a ogni modifica. Non considera i token sovrascritti a mano: per quelli valgono i controlli della pagina.

## Prima di pubblicare
Nella finestra **Pubblica**, «Controlla» fa passare dagli stessi controlli la versione di sviluppo di ogni pagina che cambia (più la pagina aperta, al massimo 6), caricata in un riquadro `sandbox` senza script, a 1366 px.

**Blocca la pubblicazione se i controlli trovano errori** (Histeon → Impostazioni, opzione `xb_checks_block`, spenta di serie):
- i controlli partono da soli all'apertura della finestra, e «Pubblica» resta spento finché girano o se trovano errori (gli avvisi non bloccano); una pagina che non si riesce a controllare conta come errore;
- la schermata Histeon → Release non pubblica più: rimanda al builder.

È un aiuto per chi costruisce, non una barriera di sicurezza: chi ha `xb_publish` può pubblicare anche dalla REST.

### Regole della sola finestra Pubblica
Leggono il documento di sviluppo caricato nel riquadro, mai la tela (la cui testa è quella del builder). Le parti pure sono in `checks.ts` e provate in `checks.test.ts` (un caso che passa e uno che fallisce per regola).

| Regola | Gravità | Come si giudica |
|---|---|---|
| `title-missing` | errore | `<title>` assente o vuoto (`headFacts` → `headProblems`) |
| `lang-missing` | errore | `lang` assente o vuoto su `<html>` |
| `description-missing` | avviso | `meta name="description"` assente o vuota |
| `canonical-missing` | informazione | `link rel="canonical"` assente, **solo per le pagine già online**: WordPress e i plugin SEO lo stampano solo per le pagine pubblicate, quindi su una pagina nuova sarebbe un falso allarme |
| `noindex` | avviso | `meta name="robots"` o `googlebot` con `noindex` o `none`. L'anteprima stampa il suo `noindex` con `data-xb-preview` (`Preview.php`) e quello non conta. Se il sito intero è chiuso ai motori la regola tace: ci pensa `site-hidden` |
| `site-hidden` | avviso, una volta per release (un sito di prova può essere nascosto apposta: non tiene spento «Pubblica» nemmeno con i controlli bloccanti) | `blog_public` spento («Scoraggia i motori di ricerca»): arriva in sola lettura nella configurazione del builder (`blogPublic`, `BuilderScreen`) |
| `page-heavy` | avviso | somma di `encodedBodySize` della voce `navigation` e delle `resource` del riquadro, oltre 1,5 MiB (1 572 864 byte, la stessa soglia di «Controlla il sito» in Histeon Publish, `HEAVY_PAGE_BYTES`). È un **minimo**: nel riquadro gli script non girano (e non si scaricano), le immagini `loading="lazy"` sotto la piega non si caricano, i file di altri siti senza `Timing-Allow-Origin` contano 0 |
| `link-broken` | avviso | `HEAD` dal browser (`headStatus`: `credentials: "omit"`, `cache: "no-store"`, `redirect: "manual"`) solo sui link dello **stesso sito** (`probeTarget`: mai altri siti, `wp-admin`, `wp-login.php`, `wp-json`, `rest_route`, link d'anteprima firmati; l'ancora della pagina stessa resta fuori). Al più 200 indirizzi diversi per release (`pickLinks`), 4 insieme, 5 secondi ciascuno (`probeLinks`). Solo 404 e 410 sono rotti; un errore di rete, un tempo scaduto o un altro stato no. Un reindirizzamento non si segue: dice già «raggiungibile» (il browser dà una risposta opaca, stato 0), e così un link dello stesso sito che rimanda altrove non fa partire una richiesta verso un altro indirizzo. Prima di segnalare un 404 si chiede la libreria di pagine e articoli: un indirizzo di una pagina non pubblicata (bozza, in attesa, programmata, privata) esiste solo in sviluppo e non è rotto (`devOnlyMatcher`, per `?p=`/`?page_id=`, indirizzo o ultimo pezzo del percorso) |

I link si provano dopo le pagine: sono avvisi, quindi non tengono spento «Pubblica» mentre girano. Nessuna richiesta parte dal server di WordPress verso indirizzi presi dalla pagina, e nessun link esterno si prova qui.

### L'esito con la release
Quando un giro di controlli è **finito** (pagine e link, senza essere fermato), «Pubblica» manda con la release anche `checks: { errors, warnings, rules: { <regola>: <numero> }, at }` (`checksOutcome()`): `errors` le segnalazioni di gravità errore, `warnings` tutte le altre (avvisi e consigli, come «da rivedere» nella finestra), `rules` il numero per regola. Senza un giro finito non si manda niente.

L'esito porta anche `pages: { checked, total }`: le pagine della release che il giro ha letto, su tutte (quelle oltre le 6 di un controllo e quelle che non si caricano non contano fra le controllate); Versioni scrive «…, su N di M pagine». Il server (`ReleaseChecks`) tiene `pages` solo come due interi con `checked <= total`, altrimenti lo toglie, e tiene solo interi non negativi e i nomi di `ReleaseChecks::RULES`, che è la stessa lista di `CHECK_RULES` in `checks.ts` (`ReleaseChecksTest` le confronta), al più 40 voci di `rules`; il resto, `at` compreso, cade senza far fallire la pubblicazione. Lo scrive con la release nel registro (voce `publish`, `details.checks`) e nell'opzione `xb_checks_last` (non autoload) con versione e data **del server**. La pubblicazione da wp-admin, da file o dal collegamento con Histeon CMS non ha esito: la release si registra senza, e `xb_checks_last` resta com'era. Il pannello Versioni del builder mostra «Uscita con N errori e M avvisi».

Altri plugin lo leggono con `xb_last_checks()` (funzione pubblica di sola lettura, in `xb.php`): null, oppure `version`, `at`, `errors`, `warnings`, `rules` e, se c'è, `pages` (`checked` di `total`), ricontrollati in lettura. Gli agenti con l'ability `xb/last-checks` (docs/spec/ai.md).

**È un promemoria, non una prova:** lo scrive il browser di chi pubblica, quindi nessun permesso, nessun blocco e nessun verdetto dipendono da lui.

## Regole
- **Nascosto apposta:** ciò che è nascosto alle tecnologie assistive (`aria-hidden="true"`) non viene segnalato. È il caso del link doppione sull'immagine di una scheda, o di un'icona decorativa.
- **Componenti e parti di template:** non si chiede un H1.
- **Limiti:** al massimo 25 segnalazioni per controllo e 4000 elementi esaminati.
- **Cosa non sostituiscono:** sono un aiuto mentre si costruisce, non una verifica con un lettore di schermo.

## Prova su un sito di prova
La prima esecuzione sulla homepage di un sito di prova ha trovato 18 segnalazioni:
- **5 erano falsi allarmi:** i link doppioni delle schede, con `aria-hidden`. Ora sono riconosciuti.
- **13 erano un difetto dell'importazione da Etch di Histeon:** le immagini dei loop scritte `{item.image.sizes.medium_large.url}` uscivano senza `src` e senza dimensioni. Corretto: ora la homepage non ha segnalazioni.
