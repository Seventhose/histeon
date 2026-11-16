# ADR 0017 — L'editor di Histos usabile da altri host senza patch

Data: 2026-10-25 · Stato: **proposto** (progetto; nessun cambiamento di codice in questa ADR)

## Contesto

Histos Publish include l'editor di Histos per il suo CMS locale, Histos CMS. Lo fa con una copia dell'editor fissata a un commit di questo repository e con quattro patch applicate sopra:

| Patch | Righe | Cosa cambia |
|---|---:|---|
| `local-cms.patch` | 1.677 | sceglie il trasporto (`createBuilderBackend`: WordPress o API locale); nasconde i pannelli che il CMS non ha (`config.localCms` in 23 punti); media da un indirizzo locale; menu e anteprima ridotti; layout mobile |
| `framework-config.patch` | 195 | importazione di CSS e preset Core Framework nel pannello CSS |
| `forms.patch` | 93 | campi `action` e `method` dei moduli nel pannello Info |
| `spec-contract.patch` | 538 | gli schemi `.xbsite` v2 (ora sono già in main, con il ponte Histos CMS) |

Le patch funzionano, ma costano. Ogni aggiornamento dell'editor le può rompere. La copia non si aggiorna senza ripassarle a mano, e quindi le correzioni di sicurezza dell'editor arrivano in ritardo a Publish. Inoltre la differenza tra «editor» e «host» non è scritta da nessuna parte: è sparsa in `if (config.localCms)`.

## Decisioni

| # | Decisione | Motivazione |
|---|---|---|
| E1 | **L'editor dichiara un contratto di trasporto, `BuilderBackend`**, in `editor/src/lib/api.ts`. È l'elenco dei metodi che pannelli e stato chiamano davvero. L'implementazione WordPress (REST e nonce) resta quella di serie. | È il punto in cui i due host differiscono davvero. Con un tipo esplicito, un metodo nuovo che l'host locale non implementa diventa un errore di compilazione, non un bug in esecuzione. |
| E2 | **L'host passa il proprio trasporto all'avvio** con `mount(target, config, { backend })`. Non usa una variabile globale né `window`. | L'editor resta una libreria e non sa chi lo ospita. Nessun codice dell'host gira se l'host non lo passa. |
| E3 | **`BuilderConfig.capabilities` sostituisce `localCms`**: un elenco di funzioni presenti (`styles`, `facets`, `fields`, `history`, `comments`, `library`, `svg`, `shortcuts`, `preview`). L'editor mostra un pannello solo se la sua funzione c'è. In WordPress ci sono tutte, come oggi. | Una domanda per funzione («c'è la cronologia?») è più chiara e più stabile di «sono nel CMS?». E vale anche per un terzo host. |
| E4 | **I media passano dal trasporto.** Il trasporto risolve l'indirizzo di un media (`mediaUrl(id)`) invece di avere un indirizzo base cablato. | Oggi la patch cambia `media.ts` e `MediaPicker.svelte`. Così lo cambia solo il trasporto. |
| E5 | **Le funzioni utili a tutti salgono nell'editor:** i campi `action`/`method` dei moduli (con lo stesso controllo di `MarkupPolicy::safe_form_action`) e il layout mobile. | Servono anche in WordPress. Le teniamo una volta sola e con i test dell'editor. |
| E6 | **Le funzioni solo dell'host si agganciano a punti dichiarati**: un'estensione del pannello CSS (`cssImporters`) che l'host registra all'avvio. Publish la usa per l'importazione dei preset Core Framework. | L'importazione di Core Framework nel CMS legge file locali e ha senso solo lì. Un punto di aggancio con un'interfaccia piccola sostituisce 195 righe di patch. |
| E7 | **Publish includerà l'editor senza patch.** La copia resta fissata a un commit e verificata con l'impronta, ma l'elenco delle patch scende a zero. Il codice di Publish (trasporto locale, importatori CSS) vive nel pacchetto di Publish e importa l'editor come libreria. | Aggiornare l'editor diventa: cambiare il commit, ricompilare, far girare i test. Una correzione di sicurezza dell'editor arriva a Publish con la release successiva. |

## Piano

1. Builder:
   - aggiungere `BuilderBackend` e `mount(..., { backend })`;
   - spostare dietro `capabilities` i controlli che oggi sono `if (config.localCms)`;
   - aggiungere `mediaUrl`;
   - portare nell'editor i campi dei moduli e il layout mobile;
   - aggiungere l'aggancio `cssImporters`.
   Ogni passo ha i suoi test, nel builder e in WordPress.
2. Publish:
   - aggiornare la copia dell'editor al commit con il punto di aggancio;
   - scrivere il trasporto locale contro `BuilderBackend` e l'importatore Core Framework contro `cssImporters`;
   - togliere le quattro patch e il loro controllo.
3. Accettazione:
   - nessuna patch sull'editor;
   - le prove di Publish verdi;
   - le prove Playwright dell'editor nel CMS (modifica, salvataggio, riapertura, conflitto) invariate;
   - nel builder, i test dell'editor e le prove e2e verdi su WordPress.

## Cosa non cambia

L'editor non acquista permessi nuovi. In WordPress ogni operazione resta una richiesta REST controllata dal server, con nonce e capability. Nel CMS la controlla l'API locale di Publish, con il suo token di sessione. Il punto di aggancio sposta codice, non fiducia.

## Collegamenti

- `docs/spec/cms-bridge.md` (lo scambio di contenuti tra Histos CMS e WordPress).
