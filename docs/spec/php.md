# PHP del sito nel builder — v1

Implementazione: `plugin/src/Render/Extensions.php`, `plugin/src/Expr/Modifiers.php`, `editor/src/lib/completions.ts` (`expressionSource`), gruppo «Blocchi PHP del sito» in `editor/src/components/InsertMenu.svelte`. Test: `plugin/tests/Integration/ExtensionsTest.php`, `editor/test/completions.test.ts`. Decisione: `docs/adr/0012-php-nel-builder.md`.

Histeon non esegue PHP scritto nell'editor. Il PHP si scrive in un file del tema o di un plugin, e le pagine lo usano in tre modi.

## 1. Dati: `data.nome`
```php
add_filter( 'xb_data_providers', static fn ( array $providers ): array => $providers + array(
	'meteo' => array(
		'description' => 'Previsioni dei prossimi giorni',
		'callback'    => static fn (): array => mio_meteo_cache(),
	),
) );
```
Nelle pagine si usa `{data.meteo.oggi}` oppure `{#loop data.meteo.giorni as giorno}…{/loop}`.

| Regola | Dettaglio |
|---|---|
| Nome | Lettere, cifre e `_`. `posts`, `terms` e `users` restano di Histeon; una sorgente JSON o una query con lo stesso nome ha la precedenza. |
| Valori | Scalari, liste e mappe (anche `JsonSerializable`). Post, termini e utenti diventano nodi con le regole di visibilità di sempre: una bozza resta nascosta a chi visita, un termine di una tassonomia non pubblica e un utente senza contenuti pubblicati non compaiono. Gli altri oggetti valgono `null`; al massimo 8 livelli e 2000 valori. |
| Uscita | Escapata come ogni valore (`docs/spec/dsl.md` §4.5): una funzione non può stampare HTML attraverso un'espressione. |
| Errori | Un'eccezione rende il valore `null`, la pagina resta in piedi. Con `WP_DEBUG` il messaggio va nel log. |
| Chiamate | La funzione è chiamata al massimo una volta per pagina e contenuto corrente (anche quando l'esportazione statica rende molte pagine di seguito). Il risultato non viene salvato da Histeon: la cache, se serve, è della funzione. |

## 2. Modificatori: `valore.nome()`
```php
add_filter( 'xb_modifiers', static fn ( array $m ): array => $m + array(
	'euro' => static fn ( mixed $v ): string => number_format( (float) $v, 2, ',', '.' ) . ' €',
) );
```
Si usano come `{item.product.price.euro()}`. Le regole:
- devono essere funzioni pure;
- il nome è fatto di lettere, cifre e `_`;
- valgono anche quelle registrate nel `functions.php` del tema, perché il filtro si legge al primo uso.

## 3. Blocchi dinamici
Un blocco registrato con `register_block_type( 'tema/orari', array( 'render_callback' => … ) )` compare nel menu + del builder, nel gruppo «Blocchi PHP del sito». Si inserisce come `<!-- wp:tema/orari /-->`. Il suo HTML è quello del `render_callback`: è codice del sito, e ne ha la stessa fiducia di un template del tema.

## 4. Nel builder
- `GET /xb/v1/extensions` (permesso `xb_build`) elenca:
  - i dati, con la descrizione;
  - i modificatori, di Histeon e del sito;
  - i blocchi dinamici dei temi e dei plugin (non quelli di WordPress né di Histeon).
- Nel pannello HTML:
  - dentro `{…}`, dopo `data.`, il completamento propone i dati del sito;
  - dopo un altro `.`, con Ctrl+Spazio, propone i modificatori.

## 5. Siti statici
Le pagine che leggono `data.` sono già tra quelle rigenerate quando cambia un contenuto (`docs/integration/static-bridge.md`). Un dato che cambia per conto suo (un servizio esterno) arriva nella copia statica alla rigenerazione successiva.
