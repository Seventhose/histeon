# Interazioni senza codice

Implementazione:
- `editor/src/lib/snippets.ts` (`INTERACTIONS`);
- `plugin/src/Render/Behaviors.php`;
- `plugin/assets/front/behaviors.js` e `behaviors.css`.

Test: `editor/test/interactions.test.ts`, `plugin/tests/Integration/BehaviorsTest.php`, `e2e/specs/interactions.spec.ts`.

Nel menu **Aggiungi**, gruppo **Interazioni**:

| Interazione | Come funziona | Senza JavaScript |
|---|---|---|
| **Schede** | `data-xb-tabs`; pulsanti `role="tab"` con `aria-controls`, pannelli `role="tabpanel"`. Frecce, Home e Fine si spostano tra le schede, una sola scheda è nel giro del Tab. | tutti i pannelli visibili, uno sotto l'altro |
| **Fisarmonica** | `<details name>` nativo: una sola sezione aperta alla volta, tastiera e lettori di schermo inclusi | uguale: non usa script |
| **Menu mobile** | `button[data-xb-toggle][aria-controls][aria-expanded]` apre e chiude il menu (`data-open`); Esc lo chiude e riporta il focus sul pulsante. Il CSS lo ripiega sotto i 48rem solo quando lo script c'è (`.xb-js`). | menu sempre aperto |
| **Comparsa allo scorrimento** | `data-xb-reveal`: compare quando entra nello schermo | tutto visibile; con movimento ridotto subito visibile |
| **Video YouTube o Vimeo** (gruppo Media) | `figure[data-xb-video]` con l'indirizzo del lettore (`https://www.youtube-nocookie.com/embed/ID` o `https://player.vimeo.com/video/ID`, l'unico formato accettato dallo script) e dentro un link al video con l'anteprima salvata sul sito. Al clic lo script mette al posto del link l'`iframe` (`?autoplay=1&rel=0`, per Vimeo `?autoplay=1&dnt=1`); i clic con un tasto modificatore aprono il link. Prima del clic nessuna richiesta va a YouTube o Vimeo. L'anteprima e il titolo li prende il server una volta (`POST xb/v1/video`, `xb_build` e `upload_files`: oEmbed del fornitore, immagine solo da `i.ytimg.com` o `i.vimeocdn.com`, JPEG/PNG/WebP, riusata se lo stesso video torna). | il link apre il video sul sito del fornitore |
| **Mappa** (gruppo Media) | `figure[data-xb-map]` con l'indirizzo della mappa da incorporare e dentro un link alla mappa con il nome del luogo. Il builder accetta link di OpenStreetMap (`#map=z/lat/lon`, `mlat`/`mlon`) e di Google Maps (`@lat,lon,zz`, `q=`, o il codice incorpora con `pb=`) e ne ricava solo `https://www.openstreetmap.org/export/embed.html?bbox=…&layer=mapnik&marker=…`, `https://maps.google.com/maps?q=…&z=…&output=embed` o `https://www.google.com/maps/embed?pb=…`; lo script accetta solo queste forme. Al clic mette l'`iframe` al posto del link. Niente si scarica in anticipo, nemmeno dal server. | il link apre la mappa sul sito del fornitore |

## Regole
- **Codice degli inserimenti:** HTML di Histeon con classi BEM (`tabs`, `tabs__tab`…) e id unici a ogni inserimento. Il CSS usa i token Histeon `--xb-*`, con valori di riserva, quindi funziona con qualsiasi framework. Il CSS si aggiunge solo alle classi che nel sito non hanno ancora uno stile, e poi si modifica come ogni altro.
- **Lo script:** lo script di Histeon (pochi KB, `defer`, niente dipendenze) si carica solo nelle pagine i cui elementi hanno un attributo `data-xb-tabs`, `data-xb-toggle`, `data-xb-reveal`, `data-xb-video` o `data-xb-map`. Legge solo questi attributi e l'ARIA: non esegue mai nulla scritto nella pagina.
- **Esc:** chiude prima la cosa più interna. Un menu aperto si chiude con Esc e lo segnala (`preventDefault`), così né l'anteprima del builder né una finestra attorno lo intercettano.
- **Nella tela del builder:** non ci sono script, quindi le interazioni si vedono aperte, come senza JavaScript, e ogni parte resta modificabile. Il comportamento si prova nell'**Anteprima**.
