# Template e parti di template nel builder — v0.1

Implementazione: `plugin/src/Templates/Templates.php`, `plugin/src/Admin/TemplatesScreen.php`. Test: `plugin/tests/Integration/TemplatesTest.php`.

## 1. Documenti
- `wp_template` e `wp_template_part` sono documenti del builder come pagine, articoli e componenti. Salvataggio in sviluppo, anteprima firmata, pubblicazione come release e ripristino funzionano allo stesso modo.
- Per aprirli servono `xb_build`, `edit_theme_options` (attraverso `edit_post`) e un tema a blocchi.
- Le modifiche fatte fuori da Histeon, ad esempio nel Site Editor, diventano commit di produzione, come per le pagine.

## 1a. Il blocco del contenuto
Ogni template (non le parti) deve avere il blocco del contenuto della pagina, `xb/post-content` (`{@content}`, «Contenuto del post» nel menu Aggiungi), in qualunque punto, anche dentro una condizione. Verso Histeon Publish diventa lo slot predefinito (`PageProfileAdapter::portable_template_content`) e `PageProfile::validate` rifiuta un template senza (`A Histeon page template must contain the default slot.`): l'intera rilettura del sito e l'invio si fermano, non solo quel template. In un elenco (archivi, ricerca, indice) il blocco resta vuoto, nel Renderer come in Publish, e va messo lo stesso.

Il builder lo dice dove il template si modifica: quando il documento aperto è un template (`config.template`, non `part`), non c'è una sessione di componente aperta e l'albero non ha `xb/post-content` (`hasPostContent` in `editor/src/lib/tree.ts`), sopra la tela compare una striscia (`role="note"`, `data-xb-template-without-content`) che spiega in parole semplici cosa succede e cosa fare, con il pulsante **Aggiungi il contenuto del post** (inserisce `{@content}` nel punto selezionato, oppure in fondo). Non blocca né il salvataggio né la pubblicazione; in modalità contenuti non compare (chi la usa non può aggiungere blocchi). Test: `editor/test/tree.test.ts`, `e2e/specs/template-without-content.spec.ts`.

Da Histeon 0.5.8 anche i template `index` e `404` del tema (Fondamenta e XB Blank 0.4, `fondamenta.md` §3) hanno il blocco: un template del tema salvato nel database non resta più fuori dalla rilettura.

## 2. Dove si aprono
- **Aspetto → Template Histeon** elenca template e parti del tema attivo.
  - Un template personalizzato (nel database) si apre direttamente.
  - Un template che esiste solo come file del tema viene prima scritto nel database (`Templates::materialize`), come fa il Site Editor quando lo si personalizza. Si fa una sola volta, con una richiesta POST protetta da nonce.
- Nella barra di amministrazione del sito, **"Template con Histeon: …"** apre il template usato dalla pagina che si sta guardando.

## 3. Anteprima con un contenuto di esempio
Un template non ha una pagina sua: il canvas e l'anteprima firmata lo mostrano con un campione.

| Slug | Campione |
|---|---|
| `single`, `singular`, `single-{tipo}[-…]` | un contenuto pubblicato di quel tipo (il prefisso più lungo che è un tipo registrato: `single-corso-online` → `corso-online`) |
| `page`, `page-*`, `front-page`, parti di template | una pagina |
| `archive*`, `index`, `home`, `search`, `404`… | un elenco, cioè la query principale con gli ultimi articoli |

- Nel builder, **"Anteprima con"** sceglie il campione tra gli ultimi 20 contenuti pubblicati che l'utente può leggere.
- Il rendering imposta `$post` e la query principale per il campione e li ripristina subito dopo.

## 4. Anteprima firmata
- Il token di una pagina apre solo quella pagina.
- Il token di un template apre qualunque pagina del sito, perché un template vale per molti URL. Il link porta al campione scelto.
- Per un template, la CSP che blocca gli script si applica quando c'è contenuto di sviluppo non fidato **ovunque**, non solo nel documento.

## 5. Il contesto della pagina (`props.page`)
Nei template e nelle parti, `props.page` è la pagina che il template mostra (`PageContextNode`), con gli stessi nomi della build statica di Histeon Publish, dove la radice si chiama `page`. Anche `page` vale come radice (`Render/Context.php`): i template importati da Publish (`{page.title}`, `page.fields.anno`) funzionano come sono scritti. In un componente vale `page`, come in Publish dove ogni componente legge la pagina in cui sta; `props.page` resta delle sue props. In una parte di template vale anche `props.page`, se la parte non dichiara una prop con quel nome: la Testata segna la voce del menu della sezione in cui si è. `this` resta il contenuto corrente, in un archivio il primo dell'elenco; `props.page` di un archivio descrive invece l'archivio e **non prende mai i campi del primo contenuto elencato**. Un campo non elencato vale `null`.

| Pagina | Campi |
|---|---|
| Contenuto singolo (articolo, pagina, record) | `id title name slug url type typeKey excerpt meta fields values terms` del contenuto; le altre chiavi come in `this` (`date publishedAt modified updatedAt author image parent categories tags commentCount readingTime`…) |
| Archivio di un tipo | `id slug type typeKey`: la chiave del tipo; `title name`: l'etichetta plurale |
| Archivio di un termine (categoria, tag, tassonomia) | `id`: l'id del termine; `title name slug`; `type typeKey taxonomy taxonomyKey`: la tassonomia (`taxonomyKey` come in Publish, `taxonomy` resta per compatibilità); `meta fields values` del termine |
| Elenco degli articoli senza pagina degli articoli (la home con «Gli ultimi articoli») | `id type typeKey`: `post`; `title name`: `Blog`; `slug`: `blog`, come `/blog/` di Publish |
| Elenco degli articoli sulla pagina degli articoli (Impostazioni → Lettura) | `id title name slug excerpt meta fields values` di quella pagina; `type typeKey`: `post`, il tipo elencato |
| Archivio di un autore | `id`: l'id dell'utente; `title name`: il nome pubblico (`display_name`); `slug`: `user_nicename` |
| Archivio di una data | `title name`: il periodo come lo scrive WordPress nei titoli degli archivi, senza «Anno:»/«Mese:»/«Giorno:»: l'anno (`2001`), il mese nel formato tradotto `F Y` (`marzo 2001`), il giorno nel formato data del sito |

Negli archivi `url` è l'indirizzo della **pagina mostrata**, come in Publish: la pagina 1 è il link dell'archivio, la pagina 2 `/opera/page/2/` (con i permalink semplici `…&paged=2`), con le regole degli indirizzi qui sotto. Il builder non usa `props.page.url` per altro (né canonical né SEO, che vengono da WordPress); l'indirizzo della prima pagina è `props.page.pageLinks.first().url`. Sui contenuti singoli `url` resta il permalink.

**Paginazione degli archivi** (`ArchivePagination`): sull'archivio di un tipo o di un termine, sull'elenco degli articoli (`home`, con o senza pagina degli articoli) e sugli archivi di autore e di data, cioè dove WordPress pagina la query principale (`main()`), `props.page` ha anche:

| Campo | Valore |
|---|---|
| `page` | la pagina mostrata, da 1 (la query var `paged`; senza, 1) |
| `totalPages` | le pagine dell'archivio, almeno 1 anche se è vuoto (`max_num_pages` della query principale) |
| `totalRecords` | i contenuti dell'archivio su tutte le pagine (`found_posts`) |
| `prevUrl` | l'indirizzo della pagina precedente; `""` sulla prima |
| `nextUrl` | l'indirizzo della pagina seguente; `""` sull'ultima |
| `pageLinks` | una voce `{number, url, current}` per ogni pagina da 1 a `totalPages`, al massimo le prime 100; `current` è vero solo per la pagina mostrata |

- **Indirizzi:** la pagina 1 è il link dell'archivio di WordPress (`get_post_type_archive_link`, `get_term_link`, la pagina degli articoli o la home, `get_author_posts_url`, `get_year_link`/`get_month_link`/`get_day_link`), quindi segue la struttura dei permalink e i filtri dei plugin su quei link (la lingua, per esempio). La pagina N aggiunge `page/N/` come `get_pagenum_link()` (base della paginazione e barra finale della struttura); con i permalink semplici aggiunge `paged=N` (`/?post_type=libro&paged=2`, `/?cat=3&paged=2`).
- **Niente dalla richiesta:** a differenza di `get_pagenum_link()`, gli indirizzi non partono dall'URL richiesto e non portano la sua query string. Con `/libri/page/2/?x=…` il link alla pagina 3 è `/libri/page/3/`. Per elenchi con filtri si usano i facet (`docs/spec/facets.md`), che hanno i loro `prevUrl`, `nextUrl` e `pageLinks`.
- **Uscita:** come ogni valore, in un attributo URL (`href={props.page.nextUrl}`) passano da `esc_url`, nel testo da `esc_html`.
- La paginazione è quella della query principale di WordPress (il numero per pagina di Impostazioni → Lettura, o quello che un plugin le dà); un preset di tipo `main` che cambia `posts_per_page` cambia gli elementi del loop, non questi campi.
- La ricerca, il 404 e i contenuti singoli non hanno questi campi (valgono `null`); lì `props.page` è il contenuto (sulla ricerca il primo trovato), come prima.

```html
<nav aria-label="Paginazione">
  {#if props.page.prevUrl}<a href={props.page.prevUrl}>Precedente</a>{/if}
  {#loop props.page.pageLinks as l}
    {#if l.current}<span aria-current="page">{l.number}</span>{:else}<a href={l.url}>{l.number}</a>{/if}
  {/loop}
  {#if props.page.nextUrl}<a href={props.page.nextUrl}>Seguente</a>{/if}
</nav>
```

**Nomi di Histeon Publish.** Oltre a `page`, valgono i nomi che usano i progetti di Publish: `publishedAt` (= `date`) e `updatedAt` (= `modified`) dei contenuti, `label` (= `name`) dei termini.

## Gruppi
`Templates::classify()` decide il gruppo di ogni template seguendo la gerarchia dei template di WordPress. Ogni template riceve anche una riga che dice dove vale. La stessa classificazione serve la schermata Histeon → Template e la sezione Template del builder, che filtra per gruppo e cerca.

| Gruppo | Template |
|---|---|
| Generali | `index`, `home`, `front-page`, `404`, `search`, `privacy-policy`, `blank` |
| Pagine | `page`, `page-*` |
| Articoli | `single`, `singular`, `single-post*`, `attachment`, `embed` |
| Archivi | `archive`, `date`, `author*`, `archive-post` |
| Un gruppo per tipo di contenuto | `single-{tipo}`, `archive-{tipo}`. Il tipo è il prefisso più lungo che esiste (`single-corso-online` → `corso-online`); il gruppo prende il nome plurale del tipo |
| Categorie, tag e tassonomie | `category*`, `tag*`, `taxonomy*` |
| Modelli personalizzati / Altri template | template personalizzati (`is_custom`) e slug non riconosciuti |
| Intestazioni, Piè di pagina, Altre parti | parti di template, divise per area |

## Nuovo template

`Templates::create()` e il pannello **Nuovo template** di Histeon → Template creano un template o una parte di template del tema attivo, direttamente nel database, e lo aprono nel builder. Anche la sezione Template del builder lo apre, in una nuova scheda.

I campi sono:
- **tipo**: template completo o parte di template;
- **nome tecnico** (slug): minuscole, cifre, `-` e `_`, al massimo 100 caratteri, unico nel tema;
- **titolo**;
- **area**, per le parti: generale, intestazione, piè di pagina;
- **parti da**: vuoto, oppure la copia di un template dello stesso tipo del tema attivo.

Il nome tecnico decide come vale il template:
- se è della gerarchia di WordPress (`page`, `single-evento`, `archive-evento`, `category`, `404`… secondo `classify()`), vale da solo dove previsto. Histeon lo segna con `is_wp_suggestion`, come fa il Site Editor;
- se è un altro nome, diventa un **modello personalizzato**, che si assegna a pagine e articoli dal loro editor.

I suggerimenti (`Templates::suggestions()`) vengono dalla gerarchia, dai tipi di contenuto e dalle tassonomie pubbliche del sito, tolti i template che il tema ha già. La creazione richiede gli stessi permessi dell'apertura (`xb_build`, `edit_theme_options`, tema a blocchi) e finisce nel Registro (`create_template`).
