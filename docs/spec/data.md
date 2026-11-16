# Sorgenti dati — v0.1

Implementazione:
- `plugin/src/Render/Sources.php`, `plugin/src/Data/DataNode.php`, `plugin/src/Render/LoopPresets.php` (utenti);
- `editor/src/components/QueryPanel.svelte`, `JsonSourceEditor.svelte`, `DataPreview.svelte`.

Test: `plugin/tests/Integration/DataSourcesTest.php`, `editor/test/jsonTable.test.ts`.

## 1. Sorgenti
| Tipo | Cos'è | Dove |
|---|---|---|
| Query | Contenuti, termini, **utenti** o la query della pagina (`docs/spec/queries.md`) | Documento `settings` → `presets` |
| JSON | Dati scritti da chi costruisce il sito: orari, sedi, listini… | Documento `settings` → `sources` |

Entrambe hanno versioni, anteprima firmata e release come il resto. Una sorgente JSON e una query non possono avere lo stesso nome. I nomi `posts`, `terms`, `main` e `users` sono riservati.

## 2. Lettura senza loop: la radice `data`
`data` è disponibile ovunque, anche nei componenti:
- `{data.orari[0].giorno}` legge da una sorgente JSON;
- `{data.ultimi_eventi[0].title}` legge da una query, eseguita con i suoi valori predefiniti e con le stesse regole di un loop.

Altre regole:
- **Built-in:** si leggono allo stesso modo `data.posts`, `data.terms` e `data.users`.
- **Memoria:** ogni nome si risolve al massimo una volta per render.
- **Assenze:** un nome sconosciuto, un indice o una chiave mancanti valgono `null`, cioè testo vuoto.

## 3. Loop su una sorgente JSON
`{#loop data.orari as h}…{h.giorno}…{/loop}` ripete per ogni elemento della lista. "Avvolgi in un loop" lo crea dall'elemento selezionato.

## 4. Loop sugli utenti
Tipo di query `users`, oppure il preset predefinito `users(limit:, role:, orderby:, order:)`.
- **Chi compare:** solo chi ha **contenuti pubblicati** di un tipo ammesso nei loop, cioè le stesse persone che WordPress mostra già nelle pagine autore.
- **Campi** (`person.*`): `name`, `url` (pagina autore), `avatar`, `bio`, `id`, `postCount` (contenuti pubblici). Mai email, nome utente o ruolo.
- **Argomenti ammessi:** solo `number`, `offset`, `orderby` (`display_name`, `post_count`, `registered`, `ID`, `include`), `order`, `include`, `exclude`, `role__in`, `role__not_in` e `post_type`. Niente ricerca, meta o campi, cioè nulla che possa sondare dati privati.

## 4b. Sorgente da un indirizzo
Una sorgente può essere `{type: 'url', url, path?, ttl?}`: il JSON di un altro servizio, letto come una sorgente scritta (`plugin/src/Render/RemoteSource.php`; `Sources::data()` la risolve per `DataNode`).
- **Solo `https`**, senza credenziali nell'indirizzo, e **solo dagli host consentiti** da un amministratore (opzione `xb_remote_hosts`, `GET`/`POST xb/v1/sources/hosts`, scrittura con `manage_options`, nel Registro). La richiesta è `wp_safe_remote_get`: niente indirizzi privati o locali, 5 secondi, al massimo 2 redirezioni, risposta oltre `MAX_BYTES` rifiutata.
- Il JSON passa dagli stessi limiti di una sorgente scritta (peso, profondità, chiavi); `path` (`items`, `data.eventi`) sceglie una parte.
- **Copia:** un transient per `ttl` minuti (5-1440, predefinito 60) e l'ultima copia buona in un'opzione. Scaduta la copia, chi visita riceve subito l'ultima buona e un evento di WP-Cron (`xb_refresh_remote_source`) la rilegge; solo la prima lettura in assoluto aspetta il servizio. Un servizio che sbaglia lascia l'ultima copia buona e si riprova dopo al più 5 minuti.
- `POST xb/v1/sources/test` legge subito senza tenere copie: è «Prova» nel pannello.
- Static Bridge e Histeon Publish usano i dati del momento in cui costruiscono la pagina. L'invio verso Publish non porta le sorgenti da indirizzo (solo quelle scritte) e non le tocca.

## 5. Sicurezza
- **Valori:** i dati JSON sono dati. Escono con l'escaping di ogni espressione (`esc_html`, `esc_attr`, `esc_url`) e non diventano mai markup.
- **Limiti:**
  - 50 sorgenti, 256 KB l'una e 1 MB in tutto;
  - profondità 10;
  - chiavi lunghe al massimo 100 caratteri;
  - `__proto__`, `constructor` e `prototype` rifiutati.
- **Permessi:** salvare richiede `xb_build`, come le query.

## 6. Builder (sezione "Dati e query")
Una lista unica con **Query** e **Sorgenti JSON**.
- **Una sorgente JSON** si modifica:
  - come **tabella** (righe e colonne, con i tipi dei valori conservati);
  - come elenco di chiavi, quando è un solo oggetto;
  - nella vista **Codice**.
- **L'anteprima** mostra i record e, per ognuno, le espressioni **nel loop** (`{item.giorno}`) o **senza loop** (`{data.orari[0].giorno}`), da copiare o inserire.

## Campi personalizzati
I campi dei contenuti (Histeon, ACF, Secure Custom Fields, JetEngine) si leggono con `this.fields.nome` e `item.fields.nome`; regole e permessi in `docs/spec/content.md`.
