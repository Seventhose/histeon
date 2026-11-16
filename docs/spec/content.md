# Tipi di contenuto e campi personalizzati — v0.1

Implementazione:
- `plugin/src/Content/ContentTypes.php`, `PrivateTypeController.php`, `EditorFields.php`;
- `plugin/src/Fields/` (`Fields`, `Provider`, `XbFields`, `AcfFields`, `JetEngineFields`, `MetaBoxFields`, `Values`), `plugin/src/Data/FieldsNode.php`;
- `editor/src/components/ContentHubPanel.svelte`, `editor/src/lib/contentHub.ts`;
- `plugin/assets/editor/fields.js` (pannello "Campi" dell'editor a blocchi).

Test:
- `plugin/tests/Integration/ContentFieldsTest.php`;
- `editor/test/contentHub.test.ts`;
- `e2e/specs/content.spec.ts`.

## 1. Cosa offre
La sezione **Tipi e campi** del builder permette di:
- creare **tipi di contenuto** (Eventi, Sedi, Prodotti…);
- creare **tassonomie** (categorie annidate o tag);
- aggiungere **campi** ai propri tipi, agli articoli e alle pagine;
- scegliere quali campi di **ACF, Secure Custom Fields, JetEngine e Meta Box** possono leggere le pagine.

Nelle pagine:
- `{this.fields.prezzo}` in un modello singolo;
- `{item.fields.prezzo}` in un loop;
- `{#loop this.fields.capitoli as r}{r.titolo}{/loop}` per ripetitori e gallerie;
- `this.acf.nome`, `this.jetengine.nome` e `this.metabox.nome` leggono solo da quel plugin.

## 2. Definizioni: documento `settings`
Le definizioni stanno in `settings.content` = `{ types, taxonomies, fields }`. Hanno versioni, anteprima e release come le query. WordPress registra solo quello che la release online contiene (opzione `xb_content`), quindi un tipo nuovo esiste nell'amministrazione dopo la pubblicazione. Il builder lo segnala con lo stato "Da pubblicare / Modificato / Online".

### Tipo di contenuto
| Chiave | Valori | Effetto |
|---|---|---|
| nome tecnico | `[a-z][a-z0-9_-]{0,19}` | Non può essere un nome riservato (tipi e variabili di WordPress, radici Histeon, `wp_*`) né un nome già registrato da altri. Dopo la pubblicazione non si rinomina. |
| `singular`, `plural` | testo, 60 caratteri | Etichette |
| `public` | sì/no | Pagine proprie (singolo, archivio, ricerca, menu). Se no, vedi §3. |
| `archive`, `slug` | sì/no, indirizzo | `/slug/` e `/slug/elemento/` |
| `hierarchical` | sì/no | Genitori, come le pagine |
| `icon` | `dashicons-*` | Icona nel menu |
| `supports` | elenco chiuso | titolo, contenuto, immagine, riassunto, autore, commenti, revisioni, attributi |
| `capability` | `post` o `page` | Si gestisce come gli articoli (anche gli autori) o come le pagine |

### Tassonomia
Ha nome tecnico (al massimo 32 caratteri, stesse regole del tipo), etichette, `hierarchical` (categorie o tag), `public`, `slug` e i tipi a cui si applica: i tipi creati qui, gli articoli o le pagine.

### Campo
Un campo ha:
- `name`: `[a-z][a-z0-9_]{0,63}`, mai con `_` iniziale;
- `label` e `help`;
- `type`, scelto tra testo, testo lungo, numero, data, ora (`time`, `HH:MM` o `HH:MM:SS`), sì/no, scelta, immagine, file (`file`: un allegato qualsiasi, si legge come `{url, title, filename, mime}`), indirizzo web, email, contenuto collegato e ripetitore;
- `multiple: true` per scelta, immagine, file e contenuto collegato: più valori, salvati come lista e letti con un loop. Nel builder si chiamano «Più valori (caselle)», «Più immagini (galleria)», «Più file», «Più contenuti»; nell'editor a blocchi una scelta multipla è una casella per opzione;
- `radio: true` per una scelta a un valore: pulsanti di scelta invece del menu, nell'editor a blocchi e nelle schermate di termini e utenti. Solo presentazione: il valore è lo stesso.

Una scelta ha opzioni `{value, label}`. Un contenuto collegato ha `postType`. Al massimo 50 campi per tipo.

### Dove vanno i campi
| Chiave in `fields` | Campi di | Meta di WordPress | Si leggono con |
|---|---|---|---|
| `evento`, `post`, `page` | contenuti di quel tipo | post meta, nell'API REST | `this.fields.x`, `item.fields.x` |
| `term:luogo`, `term:category`, `term:post_tag` | termini di una tassonomia creata qui, delle categorie o dei tag | term meta (nell'API REST se la tassonomia è pubblica) | `this.categories[0].fields.x`, `this.terms.luogo[0].fields.x`, `item.fields.x` in un loop di termini |
| `user` | utenti, profilo pubblico | user meta, **fuori** dall'API REST | `this.author.fields.x`, `item.fields.x` in un loop di utenti; mai `user.fields` (chi visita) |

Termini e utenti non hanno il ripetitore.

### Ripetitore
Un ripetitore (`repeater`) è una lista di righe con gli stessi campi: orari, tappe, voci di un listino.
- I campi di ogni riga stanno in `sub`: da 1 a 20, ciascuno con `name` (stesse regole dei campi, unico nella riga), `label`, `type` e, per le scelte, `options`.
- Tipi ammessi nella riga: testo, testo lungo, numero, data, ora, sì/no, scelta, indirizzo web, immagine, file e **un altro ripetitore**, che ha a sua volta i suoi campi in `sub` ma non può contenere un terzo ripetitore. Mai un contenuto collegato.
- Si legge con un loop: `{#loop this.fields.orari as r}{r.giorno} {r.ore}{/loop}`; un'immagine della riga è un nodo immagine (`{r.foto.url}`). Un ripetitore nella riga è una lista di righe: `{#loop r.orari as o}{o.giorno}{/loop}`.

Ogni campo diventa un meta del post registrato con `register_post_meta`:
- è `single`;
- ha lo schema REST del suo tipo (`enum` per le scelte);
- ha una sanitizzazione per tipo: numeri; date reali `AAAA-MM-GG`; solo `http(s)` per gli indirizzi; solo un'opzione prevista; solo un'immagine della libreria; solo un contenuto del tipo previsto;
- se è un ripetitore, è un solo meta con la lista delle righe:
  - schema REST `array` (al massimo 200 elementi) di `object` con una proprietà per campo della riga e `additionalProperties: false`, quindi l'API REST rifiuta chiavi in più;
  - la sanitizzazione tiene al massimo 200 righe, scarta ciò che non è una riga, tiene in ogni riga esattamente i suoi campi e sanitizza ciascuno per tipo come sopra (un numero vuoto è `null`);
  - un ripetitore dentro una riga ha nello schema lo stesso formato, con al massimo 50 righe (`MAX_INNER_ROWS`), e la stessa sanitizzazione;
  - senza righe vale `[]`;
- si modifica solo con `edit_post`.

Un nome già registrato da un altro plugin per quel tipo viene rifiutato. Alla registrazione, ciò che nel frattempo è stato registrato da altri non viene mai sovrascritto.

## 3. Tipi senza pagine proprie
Un tipo con `public = false`:
- non ha indirizzi;
- è escluso dalla ricerca;
- **non è elencato né leggibile nell'API REST** se non da chi può modificarlo (`PrivateTypeController`);
- alimenta comunque i loop (filtro `xb_loop_post_types`), solo con i contenuti pubblicati.
- compare negli archivi delle tassonomie pubbliche a cui appartiene: la query principale di quegli archivi lo aggiunge ai tipi che WordPress elencherebbe (`ContentTypes::term_archives_list_hidden_types`), perché WordPress li riempie solo con i tipi inclusi nella ricerca. Una query che nomina i suoi tipi resta com'è.

È il caso tipico di testimonianze o voci di un listino, mostrate dentro altre pagine.

## 4. Campi di altri plugin
| Plugin | Rilevamento | Valori |
|---|---|---|
| ACF e Secure Custom Fields (stessa API) | `acf_get_field_groups` (per i termini con la posizione `taxonomy`, per gli utenti `user_form`) | Grezzi (`acf_get_value`), con gruppi applicati secondo le regole di posizione |
| JetEngine | `jet_engine()->meta_boxes`: meta box di tipi, tassonomie e utenti | Meta di post, termine o utente con il nome del campo |
| Meta Box (metabox.io) | `rwmb_get_registry('meta_box')`: meta box di post, termini (MB Term Meta) e utenti (MB User Meta); mai le pagine di impostazioni | Grezzi (`rwmb_get_value`, mai l'output HTML di `rwmb_the_value`). Un campo clonabile è una lista; un gruppo clonabile (estensione Meta Box Group) è una lista di righe, come un ripetitore. |
| Altri | filtro `xb_field_providers` (interfaccia `XB\Fields\Provider`; `XB\Fields\ObjectProvider` anche per termini e utenti; `XB\Fields\OptionsProvider` per le pagine di opzioni) | — |

**Pagine di opzioni.** I campi delle pagine di opzioni di ACF e Secure Custom Fields (`acf_get_options_pages`, gruppi con la posizione `options_page`) si leggono in qualunque pagina come `site.options.<pagina>.<campo>`, con i trattini dello slug della pagina scritti come `_` (`site.options.dati_sito.orari` per `dati-sito`). Sola lettura, stesse conversioni dei campi dei contenuti. Un campo di una pagina di opzioni è leggibile **solo** se un amministratore lo rende tale: `show_in_rest` del gruppo non conta. Nel builder i loro gruppi compaiono fra i campi di altri plugin con «pagina di opzioni «…»» e l'espressione già pronta. Provato con Secure Custom Fields 6.9.6 installato (`OptionsPagesTest`). Le pagine di opzioni di Meta Box (MB Settings Page) e di JetEngine non si leggono.

Un campo di questi plugin può contenere dati interni (note, codici). Le regole di lettura:
- **Leggibile solo se** un amministratore lo rende tale nel builder (`settings.publicFields`, ID come `acf:field_abc`, `jetengine:evento:prezzo` o `metabox:dettagli:prezzo`), oppure se il plugin lo pubblica già nell'API REST (`show_in_rest`; per Meta Box: l'estensione MB REST API attiva, salvo `hide_from_rest`, oppure un meta registrato con `show_in_rest`).
- **Mai leggibili:** i campi password e le chiavi con `_` iniziale; per Meta Box anche i campi `hidden` e i tipi senza conversione nota.
- **Anteprima:** usa l'elenco di sviluppo; il sito usa quello online.

Conversione dei valori (`Values`), senza mai eseguire la formattazione del plugin (niente shortcode, oEmbed o filtri):
- **Testo:** il testo ricco perde il markup, perché le espressioni stampano testo escapato.
- **Date:** `AAAA-MM-GG`, oppure `AAAA-MM-GGThh:mm:ss` nel fuso del sito. Un campo di Histeon salvato in ISO 8601 con il suo fuso (`2026-11-15T09:30:00+01:00`, come lo scrive Histeon Publish) resta com'è, perché il sito scriva lo stesso testo della build statica di Publish; `.date()` lo mostra nell'ora del sito.
- **Immagini:** nodi immagine (`.url`, `.alt`, `.width`…); un file dà `{url, title, filename, mime}`.
- **Relazioni:** un post compare solo se il visitatore potrebbe leggerlo (`PostNode::for_post`: mai bozze, privati o protetti); i termini solo di tassonomie pubbliche; gli utenti solo come profilo pubblico.
- **Link:** solo `http`, `https`, `mailto` e `tel`.
- **Ripetitori, gruppi, contenuti flessibili:** liste di righe per nome (`layout` indica il layout), al massimo 3 livelli e 200 righe.

Un errore dentro un altro plugin lascia il campo vuoto invece di rompere la pagina.

La sezione del builder elenca i gruppi e i campi di ogni plugin attivo. Per ciascun campo mostra:
- se una pagina lo può leggere;
- l'interruttore per renderlo leggibile, che vale anche per tutto il gruppo;
- l'espressione da copiare o inserire, con un loop già pronto per le liste.

L'anteprima dei dati (Dati e query) mostra le chiavi `fields.*` leggibili di ogni record.

## 5. Permessi
| Azione | Chi |
|---|---|
| Vedere tipi, campi e campi di altri plugin nel builder | `xb_build` |
| Cambiare `content` o `publicFields` (salvataggio in sviluppo, importazione, ripristino, pubblicazione) | `manage_options`, controllato in `Versioning::save_dev` e in `Documents::apply`. Un salvataggio che lascia invariati questi valori passa per chiunque possa salvare le impostazioni. |
| Compilare i campi di un contenuto | Chi può modificare quel contenuto (`edit_post`) |
| Compilare i campi di un termine | `edit_term`, con nonce, nella schermata del termine |
| Compilare i campi di un utente | `edit_user` (ognuno il proprio profilo), con nonce, nel profilo |

## 6. Editor a blocchi
Per i tipi con campi Histeon, la barra laterale del documento ha il pannello **Campi**:
- testo, numero, data, interruttore, scelta, immagine dalla libreria e contenuto collegato;
- i ripetitori come righe: **Aggiungi riga**, sposta su o giù, rimuovi; ogni riga ha i suoi campi;
- i valori si salvano con il post tramite il meta REST;
- il pannello compare a chiunque modifichi il contenuto, anche senza accesso al builder.

### Termini e utenti
I campi di categorie, tag, tassonomie create qui e utenti compaiono nei moduli di WordPress (`ObjectFieldForms`):
- nella schermata di creazione e di modifica del termine;
- nel profilo dell'utente, sezione «Campi del profilo».

Le immagini si scelgono dalla libreria dei media. Un valore vuoto cancella il meta.

## Gruppi di campi
`settings.content.groups`: `{ nome: {label, all, types, except, fields} }`, al massimo 20, nome `[a-z][a-z0-9_]{0,39}`. Un gruppo dà gli stessi campi ai tipi che nomina (`types`), oppure a tutti tranne quelli esclusi (`all: true`, `except`). I tipi sono quelli creati qui, gli articoli e le pagine.
- `ContentTypes::validate()` scrive i campi del gruppo nella lista di ogni tipo, segnati con `group`; WP li registra come gli altri e le pagine li leggono con `this.fields.nome`.
- Un campo del tipo con lo stesso nome vince. Ogni campo si controlla di nuovo per il tipo (un nome registrato da un altro plugin lì è rifiutato).
- I campi segnati con `group` che arrivano dal builder si ignorano: tornano dal gruppo. Così un tipo creato dopo riceve i campi al primo salvataggio, e togliere un tipo da un gruppo li toglie.
- Nel builder la sezione «Gruppi di campi» crea e modifica i gruppi («Solo questi tipi» o «Tutti tranne»); nella lista di un tipo i campi dei gruppi non si modificano, e una nota dice da quali gruppi vengono.

## 8. Tabella dei contenuti
Per ogni tipo registrato (e per articoli e pagine) la sezione ha due viste: **Definizione** e **Contenuti**. La vista Contenuti è una tabella (`ContentTable.svelte`):
- ricerca, filtro per stato, 20 righe per pagina;
- titolo e campi semplici (testo, numero, data, sì/no, scelta, indirizzo, email) si modificano nella cella;
- immagini, contenuti collegati e righe si modificano nei dettagli della riga («Altri campi»): l'immagine si sceglie dalla libreria dei media, il contenuto collegato fra quelli del suo tipo, le righe di un ripetitore (con il ripetitore dentro una riga) si aggiungono, spostano e tolgono e si salvano insieme con «Salva le righe». I valori vanno a WordPress nella forma del suo schema REST (un numero vuoto è `null`, un'immagine vuota `0`), e WordPress li controlla di nuovo; resta anche l'editor di WordPress, che il link «Modifica» apre;
- «+ Aggiungi» crea una bozza.

**Termini.** Una tassonomia creata qui, le categorie e i tag hanno la vista **Termini** (`TermTable.svelte`): nome, slug, genitore (mai un proprio discendente), descrizione e i campi semplici del termine modificati nella cella; ricerca, 20 per pagina, «+ Aggiungi» con il genitore; **Elimina** con conferma, che dice a quanti contenuti era assegnato. Passa dalla rotta REST di WP della tassonomia (`termTaxonomies` in `GET /content`: le tassonomie con interfaccia e REST che l'utente gestisce, `manage_terms`), con i permessi di WP per creare, modificare ed eliminare. I campi dei termini sono nella REST solo se la tassonomia è pubblica (§2); immagini e altri campi si cambiano con «Modifica», che apre il termine in WP.

Ogni lettura e scrittura passa dall'API REST di WordPress (`wp/v2/<tipo>`) con il nonce del builder: valgono i permessi di WordPress (`edit_post`, pubblicazione) e la sanitizzazione dei meta registrati. Come in WordPress, le modifiche ai contenuti vanno subito sul sito: le versioni di Histeon riguardano pagine, modelli, stili e impostazioni, non i dati dei contenuti.

## 7. Limiti noti
- Ora e file non viaggiano verso Histeon Publish (il suo CMS non ha questi tipi): un sito che li usa lo dice all'invio come per il ripetitore. Nell'uscita verso blocchi l'adattatore ACF/SCF li scrive come `time_picker` e `file`, e una scelta a pulsanti come `radio`; gli altri adattatori come testo.
- Si leggono campi di contenuti, termini e utenti, e le pagine di opzioni di ACF e Secure Custom Fields; non quelle di Meta Box o JetEngine.
- I campi utente di ACF si cercano con la posizione «modulo utente: modifica»; quelli di Meta Box richiedono le estensioni MB Term Meta e MB User Meta, provate solo con definizioni simulate.
- Il ripetitore Histeon ha al massimo due livelli (un ripetitore dentro una riga) e nessun contenuto collegato in una riga. Nella tabella dei contenuti un ripetitore si vede come «N righe»: le righe si modificano nel pannello Campi dell'editor a blocchi.
- L'adattatore Meta Box è provato con Meta Box 5.15 e con MB REST API. I gruppi seguono il formato documentato dell'estensione Meta Box Group, non provata installata. Le condizioni di visibilità delle estensioni (Include/Exclude, Show/Hide) non contano: vale l'elenco `post_types` del meta box.
- Eliminare un tipo pubblicato non cancella i suoi contenuti: restano nel database, invisibili finché il tipo non torna con lo stesso nome. Il builder dice quanti sono (`count` di `postTypes` in `GET /content`) e, dopo l'eliminazione, offre di spostarli in un altro tipo o di metterli nel Cestino (`POST /content/items`: solo amministratori, 200 per chiamata, ogni contenuto con i permessi di WordPress e nel Registro). Spostamento e Cestino valgono subito, non con la release; pagine, articoli e tipi interni restano fuori.
- L'adattatore JetEngine segue il formato documentato dei suoi meta box ed è provato con definizioni simulate, non con il plugin installato.
