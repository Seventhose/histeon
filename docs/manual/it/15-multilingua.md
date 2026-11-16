# Siti in più lingue

Histeon costruisce siti in più lingue insieme a **Lingua Bridge**, il plugin multilingua libero (GPL) pensato per lavorare con Histeon. Questo capitolo spiega come si attiva, come si traducono pagine, componenti e testi del sito, e come si mette online una lingua nuova senza sorprese.

## Come funziona

Ogni lingua è una **pagina WordPress normale**, collegata all'originale da Lingua Bridge. La pagina inglese di «Chi siamo» è un contenuto a sé, che si apre nel builder come qualsiasi altra pagina.

Ne seguono alcune cose utili da sapere:

- **Tutto quello che conosci vale per ogni lingua:** salvataggio in sviluppo, release, versioni, revisione e anteprima funzionano allo stesso modo su ogni traduzione.
- **Ogni lingua può essere diversa:** un'immagine in più, una sezione in meno, un testo riscritto per un pubblico diverso.
- **Una modifica di struttura non si propaga da sola:** se aggiungi una sezione all'originale, nelle traduzioni va aggiunta (Lingua Bridge ti segnala cosa è cambiato, vedi più avanti).
- **Stili, impostazioni e query** sono uguali per tutte le lingue: si cambiano una volta sola.
- **Disattivando Lingua Bridge** le pagine restano normali contenuti WordPress, e restano modificabili.

> Histeon non dipende da nessun plugin delle lingue in particolare: offre dei punti di aggancio che Lingua Bridge usa. Lingua Bridge è però l'unico motore provato e consigliato.

## Attivare Lingua Bridge

Lingua Bridge è incluso in Histeon come **modulo**, spento di serie.

1. Apri **Histeon → Moduli**.
2. Accendi **Lingua Bridge** con il suo interruttore.
3. Il menu di Lingua Bridge compare sotto **Histeon**. Apri le sue **Impostazioni**.
4. Nella scheda **Lingue** scegli la lingua predefinita (quella dell'originale) e le lingue di traduzione.

Se Lingua Bridge è già installato come plugin separato, il modulo resta fermo e il plugin continua a funzionare come prima: i dati sono gli stessi. Trovi i dettagli nel capitolo [Moduli e aggiornamenti](#it-moduli-e-aggiornamenti).

> **Attenzione:** dopo la prima traduzione collegata, la lingua predefinita non si può più cambiare e le lingue già usate non si possono togliere. Sceglile con calma.

La dashboard di Lingua Bridge ha una lista **Per iniziare** che ti guida nei primi passi. Le traduzioni da fare (da tradurre, da aggiornare, in bozza) compaiono anche in **Histeon → Panoramica**, nello stato del sito.

## Gli indirizzi delle lingue

Nella scheda **URL e SEO** delle impostazioni di Lingua Bridge trovi **Indirizzi delle lingue**: «Ogni lingua nella sua cartella: /en/, /en/chi-siamo/…». È l'impostazione consigliata:

- l'originale resta alla radice del sito (`/chi-siamo/`), le traduzioni nella cartella della loro lingua (`/en/about-us/`);
- motori di ricerca, selettore di lingua e copia statica sanno sempre quale lingua stanno guardando;
- Lingua Bridge aggiunge `hreflang` alle pagine collegate e la sitemap delle lingue;
- elenchi, ricerche e loop mostrano i contenuti della lingua della pagina, anche nella tela del builder.

Richiede i permalink leggibili (Impostazioni → Permalink di WordPress). La funzione resta spenta finché non la attivi.

> Se sul sito c'è ancora un altro plugin multilingua (Polylang, WPML, TranslatePress), completa prima l'importazione da **Importa da plugin** e controlla che i due non si contendano gli stessi indirizzi. Non usare due plugin delle lingue sugli stessi percorsi.

Con il sito statico, ogni lingua è una cartella della copia statica (`en/…`), senza reindirizzamenti in base al browser.

## Tradurre una pagina in Histeon

Quando la pagina ha delle lingue, la barra in alto del builder mostra la **barra delle lingue**, accanto al titolo:

| Cosa vedi | Cosa significa |
|---|---|
| Il codice evidenziato (per esempio **IT**) | La lingua della pagina che stai modificando |
| Un codice normale (**EN**) | Una traduzione che esiste già: un clic la apre nel builder |
| Un codice con il più (**+DE**) | Una lingua mancante: un clic crea la traduzione e la apre in Histeon |

La traduzione creata con **+DE** nasce come **bozza**, copia dell'originale, e Lingua Bridge ti dice quali campi ha copiato e quali restano da tradurre. Poi traduci scrivendo direttamente nella tela (doppio clic su un testo) o nel pannello HTML, come in qualsiasi pagina.

Mentre lavori su una traduzione, i **controlli** del builder segnalano i testi di almeno 12 caratteri rimasti identici all'originale, con il messaggio «Uguale all'originale, forse non tradotto». Un nome proprio o un marchio può restare uguale di proposito: in quel caso ignora l'avviso.

> Nelle sessioni di un componente la barra delle lingue non c'è: i componenti si traducono come contenuti a sé (sotto).

## Tradurre i componenti

Header, footer e gli altri componenti sono contenuti WordPress anche loro, e si traducono allo stesso modo: si crea la traduzione del componente e la si traduce.

Sulla pagina inglese, Histeon usa la versione inglese del componente **solo se è pubblicata**. Finché è in bozza, o se non esiste, si vede l'originale. Così una traduzione a metà non finisce mai sul sito per sbaglio.

## Tradurre sul sito

Lingua Bridge ha anche un **editor visuale sul sito**: si traduce sulla pagina vera, con il tema vero.

1. Su una pagina del sito, dalla barra di amministrazione scegli **Traduci questa pagina** e la lingua.
2. Se la traduzione manca, viene creata come bozza e si apre subito.
3. Passa sui testi: quelli da tradurre sono evidenziati in giallo. Clicca un testo (o un'immagine, per il suo testo alternativo) e scrivi la traduzione nel pannello.
4. [[Ctrl+Invio]] salva e passa al testo successivo. **Prossimo da tradurre** porta al testo seguente ancora da tradurre.
5. **Lascia così** segna come fatto un testo che resta uguale per scelta.
6. **Pubblica la traduzione** la mette online, se hai il permesso.

Per ogni testo il pannello mostra la frase dell'originale e i termini del glossario che contiene.

## Stringhe: i testi fuori dalle pagine

Una pagina mostra anche testi che non fanno parte del suo contenuto: titolo e motto del sito, etichette dei menu, testi del tema e dei plugin. Si traducono in **Lingua Bridge → Stringhe**.

- Titolo, motto ed etichette dei menu compaiono da soli alla prima apertura della schermata.
- Gli altri testi si aggiungono scrivendoli esattamente come sono nella lingua originale, oppure con un clic nell'editor sul sito.
- Si sostituiscono solo testi interi, e solo come testo semplice: una stringa non può aggiungere codice a una pagina.
- Le stringhe le traduce chi amministra il sito.

Tema e plugin con un pacchetto di traduzione di WordPress si traducono da soli: Lingua Bridge usa la lingua di WordPress giusta per ogni lingua del sito, e può installare i pacchetti mancanti dalla scheda **Lingue**.

## Traduzione automatica e memoria

**Traduzione automatica.** In **Impostazioni → Traduzione IA** puoi collegare un servizio di traduzione con la **tua** chiave: DeepL, oppure un servizio compatibile OpenAI (anche un modello sul tuo server). **Salva e prova** traduce una frase di prova. Poi:

- nell'editor sul sito, **Traduci automaticamente** traduce le frasi ancora nella lingua originale e lascia stare quelle già tradotte;
- nella dashboard, l'azione di gruppo **Traduci automaticamente (bozze)** crea le bozze che mancano e le traduce.

La traduzione automatica lavora **solo sulle bozze**: una traduzione pubblicata non viene mai toccata. La chiave non viene mai mostrata.

**Memoria di traduzione.** Lingua Bridge ricorda le frasi già tradotte e le ripropone: una traduzione nuova nasce con le frasi note già al loro posto, e nell'editor sul sito compaiono come «Dalla memoria», con **Usa** o **Applica la memoria**. La memoria impara da una traduzione automatica solo quando qualcuno la pubblica, cioè dopo averla rivista.

**Quando l'originale cambia.** La traduzione diventa «da aggiornare» e l'editor sul sito mostra solo le frasi nuove o cambiate. Su una bozza, **Aggiorna la bozza** la ricostruisce tenendo le frasi già tradotte; su una traduzione pubblicata si correggono le frasi a mano e poi si sceglie **Segna come aggiornata**.

## Tradurre con un agente IA

Con il connettore Histeon collegato (capitolo [Histeon e l'intelligenza artificiale](#it-intelligenza-artificiale)), il pannello ✦ mostra **Traduci questa pagina (bozze di Lingua Bridge)**: scegli le lingue e premi **Traduci**. L'agente passa dal connettore di Lingua Bridge e lascia **solo bozze**. Per una lingua già pubblicata crea una bozza di correzione, senza toccare la pagina online.

Lingua Bridge ha anche un percorso guidato tutto suo, in **Lingua Bridge → Traduci con l'IA**.

## XLIFF per traduttori e agenzie

In **Lingua Bridge → XLIFF (agenzie)** esporti i contenuti da tradurre in un file XLIFF, il formato dei programmi di traduzione professionali (Trados, memoQ, Phrase, Smartcat, OmegaT…).

1. Scegli la lingua, il tipo di contenuto e cosa esportare (da tradurre, da aggiornare, oppure tutto per una revisione).
2. Premi **Esporta XLIFF** e manda il file al traduttore. Ogni testo è un segmento; il codice non ci finisce.
3. Quando il file tradotto torna, caricalo nella stessa schermata.

Ogni contenuto diventa una **bozza** con la struttura dell'originale e i testi tradotti; una traduzione già pubblicata riceve una bozza di correzione. Nulla viene pubblicato.

> Se l'originale è cambiato dopo l'esportazione, il file non si importa: esporta di nuovo, e il programma di traduzione ritroverà le frasi già fatte nella sua memoria.

## Il selettore di lingua

Il modo più semplice, in Histeon, è lo snippet **Selettore di lingua**: apri **Aggiungi un elemento** ([[Ctrl+I]]), gruppo **Interazioni**, e mettilo nell'header (di solito nel suo componente).

- Compare solo quando la pagina ha almeno due lingue pubblicate.
- Porta alle versioni pubblicate della pagina, con `hreflang`, `lang` e la lingua corrente segnata per i lettori di schermo.
- Le sue classi seguono il BEM `lang-switch`: lo stili come qualsiasi altro elemento.

In alternativa, Lingua Bridge può aggiungerlo da sé in fondo al menu: **Impostazioni → URL e SEO → Selettore di lingua nel menu**.

## Prima di mettere online una lingua

Una lingua nuova si vede sul sito appena le sue pagine sono pubblicate. È buona pratica non accendere una lingua su un sito già online senza averla controllata:

1. Prepara le traduzioni come bozze, comprese quelle dei componenti (header e footer) e delle stringhe.
2. Controlla ogni pagina in anteprima, con i controlli del builder senza avvisi «forse non tradotto» inattesi.
3. Se puoi, prova prima gli indirizzi per lingua su una copia del sito: attivarli cambia gli URL delle traduzioni, e con un sito statico o una CDN vanno rigenerati gli output e preparati i reindirizzamenti dei vecchi indirizzi.
4. Pubblica le traduzioni insieme, poi aggiungi il selettore di lingua.
5. Con il sito statico, dopo aver cambiato le stringhe rigenera la copia statica completa: una stringa vale per tutte le pagine.
