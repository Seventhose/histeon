# Design system

Implementazione:
- `editor/src/components/DesignPanel.svelte`, `editor/src/lib/designSystem.ts`;
- `plugin/src/Framework/TokenOverrides.php`;
- REST `GET/POST /xb/v1/tokens`.

Test: `editor/test/designSystem.test.ts`, `plugin/tests/Integration/TokenOverridesTest.php`, `e2e/specs/design-system.spec.ts`.

## Il pannello
La sezione **Design system** della barra laterale mostra le variabili del framework attivo (Core Framework, Automatic.css o Histeon CSS). I valori sono quelli che la pagina calcola davvero, anche quando una variabile è fatta di altre variabili, e sono ordinati per tipo:

| Tipo | Come si vede |
|---|---|
| Colori | campione |
| Spazi | barra lunga quanto lo spazio, in pixel |
| Testi | «Aa» alla sua misura |
| Raggi | quadrato con quell'arrotondamento |
| Ombre | cartoncino con quell'ombra |
| Altro | nome e valore |

Un clic copia `var(--nome)`. La ricerca filtra per nome.

## Modificare i token di Histeon CSS
Con **Histeon CSS** come framework (`docs/spec/histos-css.md`) i valori si modificano nel pannello (i parametri della palette e delle scale passano dalla REST `xb/v1/tokens`, chiave `design`):
- **Anteprima:** scrivendo, la tela mostra subito il nuovo valore. Per l'anteprima è una proprietà in linea sulla radice; non viene salvata.
- **Salva in sviluppo:** porta i valori nel documento delle **impostazioni** (chiave `tokens`), quindi con versioni, release e ripristino come tutto il resto.
- **Dopo la release:** il sito li stampa subito dopo `xb-tokens.css` (`:root{--xb-…}`). L'anteprima firmata e la tela usano la versione in sviluppo.
- **↺:** riporta un token al valore predefinito.

Con Core Framework o Automatic.css i valori si modificano nei loro pannelli (CF: il pannello che si apre dalla barra laterale).

## Colori per famiglia
I colori si mostrano per famiglia (`colorFamilies` in `lib/designSystem.ts`). La famiglia di una variabile è il suo nome senza sfumatura (`-50 … -950`), trasparenza (`-a10`, `-trans-20`), passi con nome di altri framework (`-light`, `-ultra-dark`, `-hover`) e senza `on-`. Si vede il colore principale. Le altre voci si aprono con il pulsante accanto, che mostra i primi campioni e quante sono. Con una ricerca, la lista torna piatta e mostra tutto ciò che corrisponde.

## Variabili e ricette del sito
Si scrivono solo con Histeon CSS attivo e stanno nel documento delle impostazioni, quindi hanno versioni, release e ripristino.

- **Variabili** (`Framework\SiteTokens`, chiave `siteTokens`):
  - nome `[a-z0-9]` più trattini singoli, fino a 40 caratteri, stampato come `--xb-site-<nome>`; il prefisso le tiene separate dai token di Histeon CSS;
  - valore controllato come gli override (`TokenOverrides::problem`);
  - al massimo 200;
  - sono stampate in `:root` subito dopo gli override (`TokenOverrides::css`);
  - `GET /framework` le aggiunge alle variabili (gruppo `site`), così l'autocompletamento del pannello CSS le propone.
- **Ricette** (`Framework\SiteRecipes`, chiave `recipes`):
  - nome come sopra; CSS di al massimo 4 KB, con graffe bilanciate;
  - non sono ammessi `<`, `url()`, `@import`, `expression()`, `javascript:`, `behavior:` e `-moz-binding`;
  - al massimo 200;
  - `GET /framework` le unisce alle ricette di Histeon e a quelle importate, e quelle del sito vincono a parità di nome; non sono nella cache delle liste, ma entrano nell'ETag.
- **REST:**
  - `GET /tokens` restituisce anche `siteTokens` e `recipes: {site, imported, histos}`;
  - `POST /tokens` accetta `siteTokens` e `recipes`; senza questi campi, quelli salvati restano.
- **Builder:** la sezione mostra «Variabili del sito» (aggiungi, modifica con anteprima immediata sulla tela, ✕) e «Ricette» (origine, modifica, ↺, nuova ricetta). Le regole sono le stesse del server (`siteNameProblem`, `recipeProblem`).
- **Histeon Publish:** gli stessi campi stanno negli stili del progetto (`siteTokens`, `siteRecipes`), con gli stessi controlli.

## Sicurezza
Prima di essere salvato, ogni valore viene controllato dal server, e prima ancora dal builder con le stesse regole:
- solo nomi di token che `xb-tokens.css` definisce;
- solo valori CSS semplici (numeri, unità, colori, `calc()`, `clamp()`, `var()`), al massimo 200 caratteri;
- niente `; { } < >`, virgolette, `@`, `!`, commenti, `url()`, `expression()`;
- parentesi bilanciate.

Un valore non può chiudere la regola né caricare risorse esterne. Il salvataggio richiede il permesso di costruire (`xb_build`); la pubblicazione segue le regole delle release.
