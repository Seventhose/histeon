# API JavaScript del builder — v1

Implementazione: `editor/src/lib/publicApi.ts`. Test: `e2e/specs/js-api.spec.ts`.

Nella pagina del builder, `window.xb` dà a strumenti, estensioni e agenti le stesse operazioni dei controlli del builder, sullo stesso stato. Non dà permessi nuovi:
- ogni salvataggio passa dai filtri e dalle versioni del server;
- la CSP del builder resta quella di sempre: niente script inline o `eval`.

| Metodo | Cosa fa |
|---|---|
| `xb.version` | `1` |
| `getHtml()` / `setHtml(html)` | Il testo del pannello HTML. `setHtml` conserva gli uid degli elementi invariati e dà errore su un testo non valido o non rappresentabile. |
| `getTree()` | Una copia dell'albero dei blocchi. |
| `getSelection()` / `select(uid \| null)` | Selezione. |
| `getElementHtml(uid)` | HTML di un elemento. |
| `insert(html)` | Inserisce dentro o dopo la selezione, come il menu +. Restituisce gli uid nuovi. |
| `remove(uid)` | Elimina un elemento. |
| `setText(uid, testo)` | Il testo di un elemento che contiene solo testo, con le espressioni `{…}`; come il pannello Proprietà. |
| `setAttribute(uid, nome, valore)` | Un attributo: `null` lo toglie, `true` lo scrive senza valore. Stessi controlli del pannello Proprietà. |
| `move(uid, destinazione, posizione)` | Sposta un elemento `before`, `after` o `inside` un altro, dove lo permette la struttura. |
| `getCss(selettore)` / `setCss(selettore, css)` | CSS di una voce di stile, come nel pannello CSS. |
| `listStyles()` | Selettori con stili. |
| `getDocument()` | Documento aperto: id, tipo, titolo, chiave del componente, modifiche non salvate, pubblicato. |
| `getFramework()` | Variabili (con il valore, quando il foglio lo definisce) e classi del framework CSS del sito. |
| `getComponents()` | Componenti del sito con le loro props. |
| `getBrief()` | Promessa con il sito per un agente: framework e variabili per gruppo con i valori, token `--xb-*`, media query con nome, query dei loop e altri dati leggibili, blocchi BEM in uso, componenti (`docs/spec/connector.md`). |
| `lintCss(selettore, css)` | Il lint del CSS del pannello CSS (`docs/spec/styles.md`), come `{code, severity, message}`. |
| `lintHtml(html)` | I problemi del markup per lo stile del sito: classi fuori BEM, catene di 4 o più classi utility del framework, stili in linea. |
| `getChecks()` | Il pannello Controlli sulla pagina disegnata, più il lint del CSS dei selettori usati. Sola lettura. |
- `getView()`: cosa vede l'utente, in sola lettura (selezione con selettori, CSS, stili calcolati e riquadro; tela; elementi principali). Vedi `docs/spec/connector.md`.
| `render()` | HTML e CSS renderizzati dal server (versione di sviluppo), dopo le modifiche in corso. |
| `environment` | Dove gira il builder: `type` (`wp_get_environment_type()`: local, development, staging, production), `histeon`, `wordpress`, `multisite`, `locale`, `framework`, `version` (sempre `dev`: si salva in sviluppo). In sola lettura. |
| `copy(uids)` / `paste(clip, {target?, classes?})` | Copia e incolla come Ctrl+C e Ctrl+V: la clip è la stessa del builder, quindi va anche in un'altra scheda o in un altro sito Histeon. `paste` passa dal server ciò che la scheda non ha copiato (`restrictClip`), rinomina le classi in conflitto o con `classes: 'keep'` tiene quelle del sito, e restituisce i nuovi uid. La finestra di incolla e l'API usano lo stesso codice (`lib/pastePlan.ts`). |
| `listMedia(filtro)`, `getMedia(id)` | La libreria dei media: filtri `search`, `type`, `collection` (0 = senza raccolta), `missingAlt`, `page`; ogni file con indirizzo, testo alternativo, misure, peso, raccolte e se è stato compresso. |
| `uploadMedia(file, {collection?, preset?})` | Carica un `File` passando dal codificatore del builder (preset `web` se non se ne nomina un altro). |
| `setMediaAlt(id, alt)`, `setMediaCollections(id, ids)` | Testo alternativo e raccolte di un file. |
| `listCollections()`, `createCollection(nome, genitore?)`, `updateCollection(id, {name?, parent?})`, `deleteCollection(id)` | Le raccolte; eliminarne una lascia i file nella libreria. |
| `compressMedia(id, preset?)`, `listMediaPresets()` | Un'immagine più leggera sul posto (`docs/spec/media.md`) e i preset disponibili. |
| `searchContent(testo?, tipo?, pagina?)`, `getContent(id)`, `updateContent(id, {title?, excerpt?, fields?})` | Contenuti e campi (`docs/spec/ai.md` «Contenuti»): lo stato non cambia mai. |
| `getQuery(nome)`, `setQuery(nome, query \| null)`, `getSource(nome)`, `setSource(nome, dati \| null)` | Query dei loop e sorgenti JSON, salvate in sviluppo con i controlli del pannello Query. |
| `getComponent(ref)`, `updateComponent(ref, {html?, props?})` | La definizione di un componente come HTML del builder con le props, e la sua modifica in sviluppo: stessi controlli di «Crea componente» (`componentProblem`); rifiutata se il componente è aperto in questa scheda. Restituisce le props che il markup non legge più. |
| `undo()` / `redo()` | Come Ctrl+Z / Ctrl+Shift+Z. |
| `save()` | Salva in sviluppo (Ctrl+S). |
| `isDirty()` | Ci sono modifiche non salvate. |
| `on('change' \| 'select' \| 'save', fn)` | Eventi. Restituisce la funzione per smettere di ascoltare. |

Esempio:
```js
xb.on('select', ({ uid }) => console.log('selezionato', uid, xb.getElementHtml(uid)));
xb.insert('<p class="nota">Aggiunto da uno strumento</p>');
await xb.save();
```

Un agente AI locale usa queste stesse operazioni attraverso il connettore (`docs/spec/connector.md`).
