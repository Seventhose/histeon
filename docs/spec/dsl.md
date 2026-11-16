# Specifica del DSL di template Histeon — v0.1

Stato: **v0.1.1** (aggiunto l'operatore condizionale `?:`; semantica di runtime precisata in §4.3–4.5). Implementazione di riferimento: `packages/dsl` (parser, printer, mapping sui blocchi). I test di round-trip sono in `packages/dsl/test`.

Il DSL è il testo del **pannello HTML**: HTML ben formato più alcune estensioni ispirate a Svelte. È una *proiezione* dell'albero dei blocchi: gli `uid` non compaiono nel testo, e l'editor li ricongiunge confrontando il nuovo albero con quello precedente.

## 1. Esempio

```html
<section xb:label="Ultimi articoli" class="posts">
  {#loop posts(limit: 3, type: "post") as post, i}
    <Card title={post.title} url={post.url} featured={i == 0}>
      {#slot media}<img src={post.image.url} alt={post.image.alt}>{/slot}
      <p>{post.excerpt.truncateWords(20) ?? "Nessun estratto"}</p>
    </Card>
  {/loop}
</section>
```

## 2. Grammatica (EBNF)

```ebnf
document     = fragment ;
fragment     = { node } ;
node         = element | component | text | if | loop | slot-fill | raw
             | slot-outlet | content | wp-block | comment ;

element      = "<" tag { attribute } ( "/>" | ">" [ fragment ] "</" tag ">" ) ;  (* void: nessuna chiusura *)
component    = "<" ctag { attribute } ( "/>" | ">" fragment "</" ctag ">" ) ;
tag          = lower { alnum } { "-" alnum { alnum } } ;           (* div, linearGradient, my-widget *)
ctag         = upper { alnum } [ "." upper { alnum } ] ;           (* Card, Ui.CardHero *)

attribute    = name [ ws? "=" ws? value ] ;
value        = '"' { char | interpolation | escape } '"'
             | "'" { char | interpolation | escape } "'"
             | interpolation
             | unquoted ;

text         = { char | interpolation | escape } ;
interpolation= "{" expression "}" ;
escape       = "\{" | "\}" ;

if           = "{#if" ws expression "}" fragment
               { "{:else if" ws expression "}" fragment }
               [ "{:else}" fragment ]
               "{/if}" ;
loop         = "{#loop" ws source ws "as" ws ident [ "," ident ] "}" fragment [ "{:else}" fragment ] "{/loop}" ;
source       = preset-call [ group-by ] | expression ;
preset-call  = ident "(" [ ident ":" expression { "," ident ":" expression } ] ")" ;
group-by     = ".groupBy(" string [ "," string ] ")" ;      (* solo testi scritti: campo e modo, §4.4 *)
slot-fill    = "{#slot" ws slot-name "}" fragment "{/slot}" ;       (* solo figlio diretto di component *)
raw          = "{#raw}" { any-char } "{/raw}" ;
slot-outlet  = "{@slot" [ ws slot-name ] "}" ;
content      = "{@content}" ;
wp-block     = "<!-- wp:" block-name … "-->" … "<!-- /wp:" block-name " -->"
             | "<!-- wp:" block-name … "/-->" ;
comment      = "<!--" { any-char } "-->" ;                         (* rimosso, con avviso *)
```

## 3. Regole lessicali e semantiche

### 3.1 Markup
- **Ben formato e stretto**: ogni elemento non-void si chiude esplicitamente; niente chiusure implicite (`<p>` o `<li>` senza chiusura sono errori). `<div/>` vale come `<div></div>`.
- **I tag HTML vanno in minuscolo.** Un'iniziale maiuscola indica un componente; `<DIV>` è un errore, mentre `<Header>` è un componente valido.
- Elementi void: `area base br col embed hr img input link meta source track wbr`.
- Il contenuto di `script`, `style` e `textarea` è testo letterale: nessuna interpolazione, nessun markup.
- Attributi duplicati sono un errore. L'ordine si conserva.
- Pseudo-attributo `xb:label="…"` su elementi e componenti (nome nel pannello Struttura). Qualsiasi altro `xb:*` è un errore.
- I commenti `<!-- … -->` vengono **rimossi**, con un avviso. I delimitatori Gutenberg `<!-- wp:… -->` invece diventano `xb/passthrough`.

### 3.2 Componenti
- `<Ui.CardHero>` ↔ `ref: "ui/card-hero"`. La conversione è biiettiva: `PascalCase` ↔ `kebab-case` per segmento, `.` ↔ `/`.
- Props: `a="testo"` → stringa; `a` → `true`; `a={3}` / `{false}` / `{null}` / `{"x"}` → letterale JSON; `a={espr}` → `{expr}`; `a="Ciao {user.name}"` → `{tpl}`. I nomi delle props sono identificatori.
- I figli sono i riempimenti degli slot: `{#slot nome}…{/slot}`. Il contenuto libero va nello slot `default`. Usare insieme contenuto libero e `{#slot default}` è un errore.

### 3.3 Spazi bianchi (fuori da `pre`, `textarea`, `script`, `style`)
1. Un nodo di testo **fatto solo di spazi e contenente un a capo** viene eliminato (indentazione).
2. Testo e formattazione inline adiacenti formano una **sequenza** che diventa un `xb/text`.
3. All'inizio e alla fine del contenuto di un genitore, gli spazi **che contengono un a capo** vengono tolti.
4. Ogni sequenza di spazi rimanente collassa in un solo spazio.
5. Dentro `pre` tutto è conservato alla lettera.

Conseguenza (come in JSX): `<a class="x">A</a>⏎<a class="x">B</a>` non ha spazio tra i due link. Per inserirlo si scrive `{" "}` o si tengono gli elementi sulla stessa riga.

### 3.4 Formattazione inline nel testo
I tag `a abbr b br code em i kbd mark q s small span strong sub sup time u wbr` si fondono nel `xb/text` circostante se:
- hanno solo questi attributi: `a` → `href`, `target`, `rel`; `abbr` → `title`; `time` → `datetime`; gli altri nessuno;
- nessun attributo contiene interpolazioni;
- non hanno `xb:label`;
- contengono solo testo o altra formattazione inline.

Altrimenti diventano `xb/element` selezionabili. Ad esempio `<a class="btn" href={url}>` è un elemento, mentre `Leggi <a href="/x">qui</a>` è un unico testo.

### 3.5 Escape
`\{` e `\}` producono graffe letterali nel testo e nei valori degli attributi; nel contenuto salvato restano scritti come escape. Le entità HTML (`&amp;`, `&lt;`) restano come scritte.

## 4. Linguaggio delle espressioni

### 4.1 Grammatica
```ebnf
expression = coalesce [ "?" expression ":" expression ] ;   (* condizionale, associativo a destra *)
coalesce   = or  { "??" or } ;
or         = and { "||" and } ;
and        = eq  { "&&" eq } ;
eq         = rel { ( "==" | "!=" ) rel } ;
rel        = unary { ( "<" | "<=" | ">" | ">=" ) unary } ;
unary      = "!" unary | postfix ;
postfix    = primary { "." ident [ "(" [ expression { "," expression } ] ")" ] | "[" expression "]" } ;
primary    = number | "-" number | string | "true" | "false" | "null" | ident
           | "(" expression ")" | "[" [ expression { "," expression } ] "]"
           | "{" [ key ":" expression { "," key ":" expression } ] "}" ;
```

### 4.2 Cosa non c'è, di proposito
- **Niente aritmetica, assegnazioni o chiamate libere** (`alert(1)` è un errore di sintassi). Il comportamento si aggiunge con **modificatori** risolti da un registro lato server (catalogo in §4.4).
- `===` e `!==` non esistono, perché `==` e `!=` sono già **stretti** (nessuna conversione di tipo).
- Gli argomenti nominati esistono solo nei preset di loop.
- Dopo un preset di loop è ammesso solo **un** `.groupBy("campo")` o `.groupBy("campo", "modo")`, con testi scritti: `{#loop foto().groupBy("anno") as gruppo}`, `{#loop foto(limit: 20).groupBy("publishedAt", "Y") as gruppo}`. Ogni elemento del loop è un gruppo `{key, items}`, come quelli di `groupBy` su un elenco (§4.4), da una sola query. Il campo è un nome o un percorso con punti (`fields.anno`, al massimo 8 parti da 64 caratteri di lettere, cifre, `_`, `$`, `-`); il modo è `"letter"` o un formato di data, da 1 a 32 caratteri senza caratteri di controllo. Ogni altra catena (`foto().upper()`, due `groupBy`, un modificatore o un indice dopo `groupBy`, `?? []`) e un campo o un modo che non sono testi scritti sono errori di sintassi. Nel blocco diventa `source.groupBy` (`blocks.md`, `xb/loop`).

### 4.3 Semantica
- Radici disponibili:
  - ovunque: `this` (il post corrente), `site`, `user` (il visitatore), `url`, `data` (sorgenti dati e query lette senza loop, `docs/spec/data.md`), `facets` (filtri dei loop, `docs/spec/facets.md`);
  - nei componenti: in più `props` e `slots`;
  - fuori dai componenti (template, parti di template, pagine): `props.page`, la pagina mostrata, con la paginazione degli archivi (`page totalPages totalRecords prevUrl nextUrl pageLinks`; `docs/spec/templates.md` §5);
  - nei loop: in più le variabili dichiarate con `as` e `index`.
- `user.role` è il ruolo di chi visita (il primo, `subscriber`, `customer`…), solo per sé stesso: mai quello di un autore. Nell'anteprima vista come ruolo (`docs/spec/preview.md`) è il ruolo scelto.
- Dentro un componente **non** sono visibili le variabili del chiamante: si passano come props. I riempimenti degli slot invece vengono valutati nel contesto del chiamante.
- L'accesso a membri e indici è **null-safe**: `a.b.c` con `a` nullo dà `null`, non un errore. Un identificatore sconosciuto vale `null`.
- Truthiness: sono falsi `false`, `null`, `0`, `""` e l'array vuoto; tutto il resto è vero.
- `==` e `!=` sono stretti; int e float si confrontano per valore (`1 == 1.0`).
- `<`, `<=`, `>`, `>=` confrontano solo due numeri o due stringhe; in tutti gli altri casi il risultato è `false`.
- `||` e `&&` restituiscono un **booleano**. Per un valore di ripiego si usa `??`, che scatta solo su `null`; oppure `.default(x)`, che scatta su qualsiasi valore falso.
- Gli errori di valutazione (es. un modificatore sconosciuto) non rompono la pagina: l'espressione vale `null`. Con `WP_DEBUG` attivo, chi ha `xb_build` vede un commento HTML che descrive il problema.

### 4.4 Modificatori disponibili (v0.1.1)
| Modificatore | Effetto |
|---|---|
| `upper()` `lower()` `capitalize()` `trim()` `stripTags()` | Trasformazioni di testo, compatibili con UTF-8 |
| `inline()` | Il testo con i soli formati in linea (`strong`, `em`, `a href`, `br`, `code`, `sub`, `sup`, `u`, `s`), stampato senza escape: tutto il resto viene tolto. Per le props `rich-text` |
| `truncateWords(n, più = "…")` `truncateChars(n, più = "…")` | Accorciano il testo dopo aver tolto i tag |
| `date(formato?)` | Formatta una data ISO o un timestamp; senza formato usa quello del sito. Una data senza fuso (`2026-10-23 14:30`) è nell'ora del sito |
| `number(decimali = 0)` | Numero localizzato |
| `length()` `first()` `last()` `join(sep = ", ")` `slice(inizio, lunghezza?)` `includes(x)` `pluck(chiave)` | Liste e stringhe |
| `groupBy(campo, come?)` | Una lista in gruppi `{key, items}`, nell'ordine della lista: per valore del campo (anche annidato, `fields.tipo`), per prima lettera (`"letter"`, `#` per ciò che non inizia con una lettera) o per data con il formato dato (`"Y"`, `"F Y"`). Anche sul risultato di una query, subito dopo il preset di un loop (§4.2) |
| `default(x)` | `x` se il valore è falso |
| `json()` `slug()` | Serializzazione JSON e slug |

Il compositore del pannello Proprietà (`editor/src/lib/expressionComposer.ts`) scrive le espressioni di un solo dato con un solo modificatore e un ripiego `?? "…"`, e rilegge solo quelle; tutto il resto resta al codice. Attributi e props accettano già le espressioni, e così i valori CSS dentro `style` (`style="--colore: {this.fields.colore}"`); per le traduzioni dei testi c'è Lingua Bridge, non un modificatore.

Si possono aggiungere modificatori con il filtro PHP `xb_modifiers` (`docs/spec/php.md` §2). I nuovi modificatori devono essere funzioni pure, senza effetti collaterali.

### 4.5 Uscita dei valori
- **Nel testo** il valore diventa stringa ed è escapato con `esc_html`:
  - `null` diventa vuoto;
  - i booleani diventano `true` / `false`;
  - le liste diventano una sequenza separata da virgole;
  - gli oggetti diventano la loro rappresentazione testuale (titolo del post, nome del termine, URL dell'immagine).
- **Negli attributi** il valore è escapato con `esc_attr`. Gli attributi URL dinamici passano anche da `esc_url`.
- Un attributo che contiene **solo** un'interpolazione (`disabled={cond}`) si comporta così:
  - se vale `false` o `null` viene omesso;
  - se vale `true` diventa un attributo booleano.

## 5. Forma canonica (printer)
- Indentazione di 2 spazi, un nodo per riga.
- **Un frammento che contiene testo si stampa su una riga sola**, così il printer non aggiunge mai spazi accanto al testo.
- Un attributo il cui valore è una sola interpolazione si stampa senza virgolette (`href={url}`); gli altri tra `"…"`, oppure tra `'…'` se il valore contiene `"`.
- Un componente con slot nominati stampa anche lo slot `default` in forma esplicita.
- **Escape del contenuto non canonico** (contenuto scritto fuori da Histeon; ADR 0005, R2):
  - nel testo, `{#` `{:` `{/` `{@` e le graffe non bilanciate diventano `\{`, le interpolazioni restano come sono;
  - un `<` resta markup solo se apre o chiude, in coppia, un tag di formattazione inline ammesso (§3.4); altrimenti diventa `&lt;`; un `\` finale diventa `&#92;`;
  - dentro `script`, `style` e `textarea` un `</tag` diventa `<\/tag`; dentro `{#raw}` la sequenza `{/raw}` diventa `&#123;/raw}`.
- **Guardia di round-trip** (`roundTrips`): se `parse(print(t))` non ha la stessa struttura di `t`, il pannello HTML è in sola lettura.
- **Limiti**: annidamento massimo 128 livelli (`MAX_NESTING`) nel parser, nel printer e in `parseXb`; nomi `__proto__`, `constructor` e `prototype` vietati come attributi e prop.
- **Invarianti** (testate su ogni fixture):
  - `parse(print(t)) ≡ t` a meno degli `uid`;
  - `print(parse(print(t))) = print(t)`;
  - `parseXb(serializeXb(t)) = t`, `uid` compresi.

## 6. Errori
Ogni errore di sintassi riporta un messaggio in italiano, la riga e la colonna. Errori principali: tag non chiuso o non corrispondente; blocco `{#…}` non chiuso; ramo dopo `{:else}`; attributo duplicato; pseudo-attributo sconosciuto; `{#slot}` fuori da un componente; chiamata a funzione libera; preset con argomenti posizionali o annidato in un'espressione, o seguito da altro che un solo `groupBy` con testi scritti; graffa non chiusa; tag HTML in maiuscolo.

## 7. Limiti noti (decisi, non dimenticati)
- `{#loop …}…{:else}…{/loop}`: il ramo `{:else}` si mostra quando il loop non ha elementi e non vede la variabile del loop. Nei blocchi è un `xb/branch` senza `test`, ultimo figlio di `xb/loop` (`loopElse()`); un ramo con condizione o non ultimo, letto da WordPress, viene tolto con un avviso, e il builder lo tiene sempre in fondo (`elseLast()`). I trasferimenti verso Histeon CMS (`ProfileValidator`, `TransferMarkup::check_branches`) lo ammettono con le stesse regole di Publish: un solo `{:else}`, in fondo, senza `test`, e senza la variabile del loop (`docs/spec/transfer-markup.md` §3).
- I commenti HTML non vengono conservati.
- Un testo scritto nel DSL che finisce con `\`, seguito subito da un blocco `{#…}`, viene letto come escape. Il printer lo evita stampando `&#92;`.
- Gli elementi SVG vuoti si stampano come `<path></path>` (valido, ma meno compatto di `<path/>`).
