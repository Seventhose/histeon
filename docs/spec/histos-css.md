# Histeon CSS — il framework CSS libero di Histeon

Decisioni: ADR 0014. Implementazione:
- `plugin/src/Framework/HistosCss/` (`Color`, `Scale`, `Design`, `Tokens`, `Utilities`, `AcssImport`);
- `plugin/assets/framework/histos.css` e `plugin/assets/tokens/xb-tokens.css`, generati da `plugin/bin/build-histos-css.php`.

Test: `plugin/tests/Unit/HistosCssTest.php`, `plugin/tests/Integration/HistosCssImportTest.php`.

Histeon CSS nasce da *Histeon Tokens*. Il provider resta `xb-tokens`, il nome mostrato diventa **Histeon CSS**.

## 1. Parametri
Pochi valori, salvati nel documento `settings` (chiave `design`), con versioni, release e ripristino. Senza parametri valgono i predefiniti.

| Gruppo | Parametro | Predefinito |
|---|---|---|
| Colori | `primary`, `secondary`, `tertiary`, `accent`, `neutral`, `success`, `warning`, `danger`, `info` | colori in esadecimale, `rgb()` o `oklch()` |
| Famiglie attive | `{famiglia}_on` per secondario, terziario, accento e stati (primario e neutro sempre attivi) | tutte 1, `tertiary_on` 0 |
| Luminosità unica | `brand_lightness_on` + `brand_lightness` (primario, secondario, terziario, accento, neutro); `state_lightness_on` + `state_lightness` (stati) | 0, 0,62 (0,30-0,85) |
| Passaggio | `hover_shade`: 400, 500, 600, 700 o 800 | 600 |
| Ruoli | `role_{ruolo}` e `role_{ruolo}_dark` per `bg`, `surface`, `surface_2`, `text`, `text_muted`, `heading`, `border`, `link`, `link_hover`, `focus`: `auto`, `white`, `black`, una famiglia o `famiglia-sfumatura` | `auto` |
| Contrasto automatico | `auto_contrast` | 1 |
| Tema scuro | `dark_on`; `scheme`: `light`, `dark`, `system`; `{famiglia}_dark`: colore o `''` (automatico, la sfumatura 400) | 0, `light`, `''` |
| Testo | `text_min`, `text_max` (px del passo `m`), `text_ratio_min`, `text_ratio_max` | 16, 18, 1.2, 1.25 |
| Spazi | `space_min`, `space_max` (px del passo `m`), `space_ratio_min`, `space_ratio_max` | 16, 24, 1.5, 1.6 |
| Viewport | `viewport_min`, `viewport_max` (px) | 360, 1440 |
| Forme | `radius` (px del passo `m`) | 8 |
| Larghezze | `width_content`, `width_wide` (rem), `width_prose` (ch) | 72, 90, 65 |
| Caratteri | `font_body`, `font_heading`, `font_mono`; `font_accent`, `font_display` (facoltativi, `''` = nessuno) | pile di sistema |
| Scala dei titoli | `heading_scale_on`, `heading_min`, `heading_max` (px), `heading_ratio_min`, `heading_ratio_max` | 1, 18, 20, 1.2, 1.333 |
| Stile dei titoli | `heading_font` (`body`, `heading`, `mono`, `accent`, `display`), `heading_weight`, `heading_leading`, `heading_tracking` (em), `heading_case`, `heading_wrap` (`wrap`, `balance`, `pretty`), `heading_measure` (ch, 0 = nessuna); per livello `h1_weight` … `h6_weight`, `h1_leading` … `h6_leading` (0 = come tutti); `headings_on` | `heading`, 700, 1.15, 0, `none`, `balance`, 0; 0; 0 |
| Testo | `text_on`, `text_leading`, `text_wrap`, `root_size` (%) | 0, 1.6, `pretty`, 100 |
| Misure e livelli | `size_text_<passo>_min`/`_max` e `size_h<n>_min`/`_max` (px, 0 = la scala; servono entrambe); per livello `h<n>_color` (`auto`, `heading`, `text`, `text-muted`, `primary`, `secondary`, `accent`), `h<n>_font` (`auto` o un ruolo), `h<n>_case` (`auto` o una trasformazione) | 0; `auto` |
| Sezioni | `section_multiplier_min`, `section_multiplier_max` (0,5-4), `section_padding` (`s`…`xl`), `gutter_min`, `gutter_max` (px, 0 = lo spazio `m`) | 1, 1, `m`, 0, 0 |
| Spazi fra gli elementi | `container_gap`, `content_gap`, `grid_gap` (un passo degli spazi), `grid_min` (rem), `grid_fill` (`auto-fit`, `auto-fill`) | `l`, `m`, `m`, 14, `auto-fit` |
| Layout | `width_min` (px), `header_min`, `header_max` (px), `scroll_offset_on` | 320, 64, 80, 1 |
| Spaziatura del testo e riquadro | `flow_text`, `flow_heading`, `flow_after` (0 = come `flow_text`), `flow_list` (em, per `xb-prose`); `boxed_on`, `boxed_width` (rem), `boxed_bg`, `boxed_shadow` | 1, 1.6, 0, 0; 0, 90, `neutral-100`, `m` |
| Bordo | `border_width` (px), `border_style` (`solid`, `dashed`, `dotted`, `double`) | 1, `solid` |
| Raggi, divisori, griglia | `radius_ratio` (0 = i passi di partenza ¼, ½, 2, 3; altrimenti xs = r/rapporto², s = r/rapporto, l = r·rapporto, xl = r·rapporto²), `divider_width` (px), `divider_style`, `divider_space`, `grid_max` (0 = nessun limite) | 0, 1, `solid`, `m`, 0 |
| Pulsanti | `btn_pad_y`, `btn_pad_x` (em), `btn_radius` (`none`, `s`…`xl`, `full`), `btn_weight`, `btn_leading`, `btn_size` (`inherit` o un passo del testo), `btn_case`, `btn_tracking` (em), `btn_border` (px, 0 = il bordo predefinito), `btn_min` (rem), `btn_full_mobile`, `btn_full_below` (px) | 0.75, 1.4, `m`, 600, 1.2, `inherit`, `none`, 0, 0, 0, 0, 480 |
| Link | `link_underline` (`always`, `hover`, `never`), `link_thickness` (px, 0 = automatico), `link_offset` (em), `links_on` | `always`, 0, 0.2, 0 |
| Card | `card_pad`, `card_gap` (passi degli spazi), `card_radius` (`s`…`xl`), `card_bg` (`bg`, `surface`, `surface-2`, `white`), `card_shadow` (`none`, `s`…`xl`), `card_border`, `card_ratio` (`16/9`, `4/3`, `3/2`, `1/1`, `3/4`) | `l`, `m`, `l`, `surface`, `s`, 1, `16/9` |
| Sovrapposizioni e superfici | `overlay_color` (`black`, `white`, una famiglia del marchio), `overlay_opacity` (0-1), `overlay_blend`; `role_named_1…3` (un ruolo, `auto` = superficie normale), `surface_<n>_border`, `surface_<n>_shadow` | `black`, 0.5, `normal`; `auto`, 0, `none` |
| Icone | `icon_color` (`currentColor`, una famiglia del marchio, `text`, `text-muted`), `icon_stroke`, `icon_box_shade` (50-200), `list_icon_color`, `list_icon_gap` | `currentColor`, 2, 100, `primary`, `xs` |
| Moduli | `forms_on`, `field_pad_y`, `field_pad_x` (em), `field_radius` (`none`, `s`, `m`, `l`, `full`), `field_bg` (`bg`, `surface`, `surface-2`, `white`), `field_border` (px, 0 = bordo predefinito), `field_focus_ring` (px), `label_weight` | 0, 0.65, 0.9, `m`, `bg`, 0, 3, 600 |
| Moduli (seguito) | `field_size`, `label_size` (`inherit` o un passo), `field_placeholder`, `help_size`, `help_color`, `field_invalid` (`danger`, `warning`, `primary`), `field_valid` (`success`, `info`, `primary`), `check_style` (`normal`, `switch`), `check_size` (em), `field_gap` | `inherit`, `inherit`, `text-muted`, `xs`, `text-muted`, `danger`, `success`, `normal`, 1.1, `m` |
| Accessibilità e dettagli | `focus_on`, `focus_width`, `focus_offset` (px), `smooth_scroll`, `reduce_motion`, `selection_on`, `selection_shade` (100-400), `quotes_on`, `columns_min` (rem), `columns_gap` (un passo), `columns_rule` | 1, 2, 2, 1, 1, 0, 200, 0, 20, `l`, 0 |
| Movimento ed effetti | `enter_range` (% 10-100), `enter_distance` (un passo), `enter_ease` (`ease-out`, `ease-in-out`, `ease`, `linear`), `hover_duration` (ms), `hover_lift` (px), `hover_scale`; `enter_scale`, `enter_blur` (px), `exit_range` (%), `hover_bright`, `hover_fade` | 30, `l`, `ease-out`, 150, 4, 1.04; 0.85, 8, 100, 1.08, 0.8 |
| Passi del testo | `text_<passo>_leading`, `text_<passo>_tracking` (em), `text_<passo>_measure` (ch), `text_<passo>_case`, `text_<passo>_wrap`: stile di `.xb-text-<passo>` (0 o `auto` = nessuna regola) | 0, `auto` |
| Ombre | `shadow_<s…2xl>_y`, `_blur` (px), `_opacity`; `tshadow_<s,m,l>_…` per le ombre del testo e i `drop-shadow` | quelle di partenza |
| Varianti dei pulsanti | `btn_<famiglia>_on`, `btn_outline_on`, `btn_light_on`, `btn_dark_on`: a 0 le classi di quella famiglia o variante non si stampano | tutti 1 |
| Link esterni | `external_on`, `external_host` (il dominio del sito), `external_label`, `external_symbol` (`arrow`, `none`), `external_place` (`after`, `before`) | 0, vuoto, `(sito esterno)`, `arrow`, `after` |
| Superfici con gradiente | `role_named_<n>_to` (`auto` = nessun gradiente), `surface_<n>_angle` (gradi): `--xb-surface-<n>-image`; il testo si legge a entrambe le estremità | `auto`, 180 |
| Superfici con nome, passaggio delle varianti | `surface_<n>_name` (classe `xb-surface-<nome>`, nome controllato); `btn_light_hover`, `btn_dark_hover` | vuoto; 200, 900 |
| Opzioni minori | `transition_ms`, `heading_italic`, `h<n>_tracking`, `h<n>_measure` (0 = come tutti), `focus_style`, `quote_width`, `quote_color`, `quote_pad`, `quote_italic`, `selection_text`, `divider_color`, `border_color`, `label_color`, `legend_size`, `field_focus_color`, `icon_box_pad`, `icon_box_radius` | 150, 0, 0, 0, `solid`, 4, `primary-300`, `m`, 0, `text`, `border`, `border`, `heading`, `inherit`, `focus`, `xs`, `full` |
| Icone, card, sovrapposizioni, livelli | `icon_xs` … `icon_2xl` (em); `card_title` (`h2`…`h6`, classe `xb-card-title`); `overlay_2_*`, `overlay_3_*` (`_color`, `_opacity`, `_blend`, classi `xb-overlay-2`, `-3`); `h<n>_wrap` | 0.75 … 3; `h4`; `black`, 0.5, `normal`; `auto` |
| Liste con icona | `list_icon_symbol` (`check`, `arrow`, `dot`, `star`, `plus`, `dash`) | `check` |
| Barra dei passi | `step_height` (px), `step_color`, `step_track`, con `forms_on` | 8, `primary`, `neutral-200` |
| Spaziatura e griglia | `flow_scope` (`prose`, `content`), `section_grid_on` | `prose`, 0 |
| Gruppi di classi | `classes_colors`, `classes_buttons`, `classes_motion`, `classes_icons`, `classes_cards`, `classes_forms` | tutti 1 |
| Avanzate | `print_alphas`, `print_shadows`, `print_buttons`, `print_forms`, `print_cards`, `print_icons`, `print_motion` (gruppi di variabili da stampare), `block_palette`; `tokens_inline` (token nella pagina, senza file) | tutti 1; 0 |

Ogni valore passa un controllo stretto: numeri entro limiti, colori validi, interruttori 0 o 1, scelte da un elenco, ruoli da uno schema fisso, pile di caratteri con soli lettere, cifre, spazi, virgole, trattini e virgolette bilanciate. Limiti, scelte e predefiniti stanno in un solo file, `design-data.json` di Histeon Publish, da cui si genera `DesignData.php`; il generatore TypeScript di Publish dà gli stessi token (fixture `histos-css-reference.json`, scritta da questo codice PHP).

## 2. Token (`--xb-*`)
Calcolati in PHP (`Tokens::compute`), stampati come `:root{…}`.
- **Testo fluido:** `--xb-text-xs … --xb-text-4xl` (passi −2 … +5), più `--xb-h1 … --xb-h6`. Ogni passo è un `clamp(min, intercetta + pendenza·vw, max)` fra le due viewport. `--xb-text-xxl` resta come alias di `2xl`.
- **Spazi fluidi:** `--xb-space-3xs … --xb-space-4xl` (passi −4 … +5), `--xb-space-xxl` come alias. Sezioni: `--xb-section-s|m|l|xl`. Margine laterale: `--xb-gutter`.
- **Colori:** per ogni famiglia, il colore di base `--xb-primary`, le sfumature `--xb-primary-50 … -950` e il testo leggibile sopra (`--xb-on-primary`). Le sfumature si calcolano in OKLCH: tinta fissa, luminosità su una scala fissa, croma ridotto agli estremi. Restano `--xb-primary-light` e `--xb-primary-dark` (sfumature 100 e 800). Ogni famiglia ha `--xb-{famiglia}-hover`, la sfumatura scelta per il passaggio. Una famiglia spenta non stampa token (le classi `xb-*-{famiglia}` restano nel foglio, senza effetto); con la luminosità unica il colore di base di una famiglia prende quella luminosità, con tinta e croma suoi.
- **Ruoli:** `--xb-bg`, `--xb-surface`, `--xb-surface-2`, `--xb-text`, `--xb-text-muted`, `--xb-heading`, `--xb-border`, `--xb-link`, `--xb-link-hover`, `--xb-focus` puntano alla sfumatura scelta, o a quella automatica (la stessa di sempre). Con il contrasto automatico, un ruolo di testo automatico che non si legge sul suo sfondo (4,5:1) passa alla sfumatura più vicina della sua famiglia che si legge, più scura su uno sfondo chiaro e più chiara su uno scuro; bianco e nero si scambiano; un ruolo scelto a mano resta com'è e la lista Leggibilità lo segnala. Un ruolo che nomina una famiglia spenta torna all'automatico.
- **Tipografia:** `--xb-h1 … --xb-h6` hanno la loro scala (`heading_scale_on`, accesa di default: h6 è il passo 0, h1 il passo 5), o seguono quella del testo. Lo stile dei titoli è fatto di token: `--xb-heading-font`, `-weight`, `-leading`, `-tracking`, `-transform`, `-wrap`, `-measure`, e per ogni livello `--xb-hN-weight`, `--xb-hN-leading`, `--xb-hN-color`, `--xb-hN-font`, `--xb-hN-transform` (che senza un valore proprio rimandano a quelli di tutti). Ogni passo del testo e ogni titolo può avere le sue due misure, fluide fra le viewport. Le classi `xb-h1 … xb-h6` e i titoli di `xb-prose` li usano; con `headings_on` il foglio delle modifiche stila anche gli elementi `h1 … h6`, dentro `:where()`, così ogni classe del sito vince. `text_on` stila `body` e i paragrafi (`--xb-leading-normal`, `--xb-text-wrap`); `root_size` scrive `html{font-size:…%}` e cambia ogni `rem`. `--xb-font-accent` e `--xb-font-display` esistono solo se scritti.
- **Spazi e layout:** `--xb-section-s … xl` sono i passi `xl … 4xl` degli spazi, oppure, con i moltiplicatori diversi da 1, quei passi moltiplicati (piccolo e grande) in un `clamp()`; `--xb-section-pad` è il padding di `.xb-section`. `--xb-gutter` segue lo spazio `m` o i due valori in px. `--xb-container-gap` (righe della griglia dei contenuti), `--xb-content-gap` (`.xb-stack`), `--xb-grid-gap`, `--xb-grid-min` e `--xb-grid-fill` (`.xb-grid-auto`) riempiono gli agganci che le classi avevano già. `--xb-width-min`, `--xb-header-height` (fluida) e `--xb-sticky-top`; con `scroll_offset_on` il foglio delle modifiche scrive `html{scroll-padding-top:var(--xb-header-height)}`. La **griglia dei contenuti** `.xb-content-grid` ha le colonne con nome `full`, `wide`, `content`: i figli stanno in `content`, `.xb-wide` e `.xb-full` si allargano, `.xb-narrow` resta alla misura della prosa. Con i valori predefiniti nessun token cambia. In `xb-prose` lo spazio fra i blocchi, prima e dopo i titoli e fra le voci delle liste viene da `--xb-flow-text`, `-heading`, `-after`, `-list` (predefiniti uguali a prima). Con `boxed_on` la pagina (`body`) ha la larghezza `--xb-boxed-width`, centrata, con l'ombra `--xb-boxed-shadow` sopra lo sfondo `--xb-boxed-bg`.
- **Forme, pulsanti e link:** `--xb-radius-xs` (un quarto del raggio medio), `--xb-shadow-2xl`, `--xb-text-shadow-s|m|l`, `--xb-drop-shadow-s|m|l` (per `filter`, classi `xb-text-shadow-*` e `xb-drop-shadow-*`), `--xb-border-width` e `--xb-border-style` (`.xb-border`). I pulsanti leggono `--xb-btn-pad-y`, `-pad-x`, `-radius`, `-weight`, `-leading`, `-size`, `-case`, `-tracking`, `-border`, `-min`; ogni famiglia ha `xb-btn-{famiglia}` (piena, al passaggio `--xb-{famiglia}-hover`), `-outline`, `-light`, `-dark`; `xb-btn-outline` e `xb-btn-ghost` restano; misure `xb-btn-xs`, `-s`, `-l`, `-xl`. I link (`xb-link`, `xb-prose a`) leggono `--xb-link-line`, `-line-hover`, `-thickness`, `-offset`; `links_on` stila anche i link senza classe e `btn_full_mobile` mette i pulsanti a tutta larghezza sotto la soglia, entrambi in `:where()`. Il passaggio dei pulsanti pieni segue la sfumatura scelta (600 di partenza).
- **Sovrapposizioni e superfici:** `--xb-overlay` (predefinito il nero al 50% di prima) e `--xb-overlay-blend` per `xb-overlay` e `xb-overlay-gradient`; tre superfici del sito `xb-surface-1/2/3` con `--xb-surface-<n>-bg`, `-text` (bianco o nero, quello che si legge di più), `-border`, `-shadow`. Numerate invece che con un nome libero: nessun testo dell'utente entra nei selettori.
- **Divisori e griglia automatica:** `.xb-divider` legge `--xb-divider-width`, `-style`, `-space`; `.xb-grid-auto` usa `--xb-grid-auto-min`, che con `grid_max` impedisce più di N colonne.
- **Card e icone:** `.xb-card` legge `--xb-card-pad`, `-radius`, `-bg`, `-shadow`, `-border` (predefiniti come prima); `.xb-card-media` va a filo dei bordi con `aspect-ratio: var(--xb-card-ratio)`, `.xb-card-body` dispone i figli con `--xb-card-gap` e porta in fondo un pulsante finale; `--xb-radius-inner` (classe `xb-radius-inner`) è il raggio concentrico di un elemento annidato. Icone: `--xb-icon-xs … 2xl` (0,75-3 em), `--xb-icon-color`, `--xb-icon-stroke`; classi `xb-icon`, `xb-icon-<misura>`, `xb-icon-boxed` (`--xb-icon-box-bg`) e `xb-list-icon` (spunta come maschera SVG nel foglio, colore `--xb-list-icon-color`).
- **Moduli e dettagli:** `--xb-field-pad-y`, `-pad-x`, `-radius`, `-bg`, `-border`, `-focus-ring`, `-accent`, `-invalid`, `-valid`, `--xb-label-weight` per `xb-input` e `xb-label`; con `forms_on` anche i campi senza classe e i moduli di Fluent Forms (`.fluentform .ff-el-form-control`, etichette, aiuto `.ff-el-help-message`, errori e conferme, spazio fra i gruppi `.ff-el-group`, `.ff-btn-submit` come i pulsanti, della famiglia `form_submit`), più segnaposto, misura delle caselle (o caselle come interruttore con `check_style: switch`), freccia dei `select` disegnata col CSS, `fieldset` e `legend`. La classe `xb-help` segue l'aiuto. Con i rispettivi interruttori: anello di focus del sito (`--xb-focus-width`, `--xb-focus-offset`), scorrimento morbido (solo senza `prefers-reduced-motion`), movimento ridotto, colore della selezione (`--xb-selection-bg`), stile delle citazioni. Le colonne di testo `xb-columns` leggono `--xb-columns-min`, `-gap`, `-rule`. Tutto in `:where()`. Di default sono accesi l'anello di focus, lo scorrimento morbido, il movimento ridotto e le ancore sotto l'intestazione (`Tokens::css_changes` li stampa anche con i valori predefiniti); titoli, testo, link e moduli nudi restano spenti. Il movimento ridotto mette `scroll-behavior` solo su `html, body`: sugli elementi `<source>` della build di Publish impedirebbe le varianti delle immagini.
- **Fluent Forms:** il suo foglio pesa due classi e più e arriva dopo: con `forms_on` i suoi selettori hanno `html` davanti (`html .fluentform .ff-el-form-control`), mentre i campi senza classe restano in `:where()`.
- **Movimento ed effetti:** solo CSS. `xb-enter-fade|up|down|left|right|grow|shrink|blur` e `xb-exit-…` usano `@keyframes` e `animation-timeline: view()` dentro `@supports (animation-timeline: view())`: dove il browser non segue lo scorrimento gli elementi restano fermi e visibili. `xb-hover-lift|shadow|glow|scale|bright|fade` e `xb-hover-underline` (`-center`, `-right`) al passaggio. Con `prefers-reduced-motion: reduce` animazioni e transizioni di queste classi sono spente. Token: `--xb-enter-range`, `--xb-enter-distance`, `--xb-enter-ease`, `--xb-hover-duration`, `--xb-hover-lift`, `--xb-hover-scale`. Il CSS su richiesta tiene sempre i `@keyframes` (`Styles\CssRules`).
- **Gruppi di variabili** (`print_*`): un gruppo spento non si stampa. `DesignData::PRINT_GROUPS` dice quali token ha ogni gruppo (trasparenze `-aNN`, ombre, pulsanti e link, moduli, card, icone, movimento). Con un gruppo spento `XbTokensProvider::file()` e `::url()` danno il foglio del sito (`Tokens::sheet`), scritto una volta in `uploads/xb-histos-css/xb-tokens-<hash>.css` con un nome che cambia col contenuto, al posto del file fisso: lo usano la pagina, la tela del builder, il cassetto e l'export Etch. `css_changes` non stampa i token spenti, nemmeno nei blocchi del tema scuro; `variables()` non li elenca, così il pannello CSS li segnala come sconosciuti. Le classi che li usano tornano ai loro valori di riserva o non fanno nulla. In Publish `histosGlobalCss` mette `histosTokenSheet` al posto di `xb-tokens.css`. `block_palette` a 0 lascia all'editor a blocchi la palette del tema.
- **Token in linea e anteprima** (`tokens_inline`): solo WordPress; `Frameworks::enqueue` non chiede `xb-tokens.css` e stampa `Tokens::sheet` nell'`head` (Publish mette già tutto il CSS in un solo foglio). La tela usa i fogli del design in sviluppo (`XbTokensProvider::canvas_stylesheets`), così un gruppo spento e salvato si vede prima della release; l'anteprima dal vivo (`Tokens::preview_css`) mette a `initial` i token dei gruppi spenti: un `var()` prende il suo ripiego, come su un sito che non li stampa. Anche i gruppi di classi si vedono subito: la risposta dell'anteprima porta `classesOff` (`Utilities::off_patterns`) e `hideClasses` toglie quelle regole dai fogli già letti dalla pagina, solo in memoria, e le rimette all'anteprima dopo.
- **Link esterni** (`external_*`): il selettore confronta `href` col dominio scritto nel pannello, controllato da `DesignData::PATTERNS`; senza dominio la regola non si stampa, perché ogni link assoluto sembrerebbe esterno. Il testo per i lettori di schermo è il testo alternativo di `content`.
- **Contrasto automatico:** un ruolo di testo automatico (`DesignData::AUTO_TEXT`) deve leggersi anche sulle superfici (`AUTO_ALSO`); se nessuna sfumatura si legge su tutte, basta il fondo della pagina e la Leggibilità segnala la coppia.
- **Gruppi di classi** (`classes_*`, `DesignData::CLASS_GROUPS`): `Utilities::filtered` toglie dal foglio generato, riga per riga, ogni regola le cui classi sono tutte di gruppi spenti e i `@keyframes` col loro nome; una regola che nomina anche una classe accesa resta. `XbTokensProvider::classes_file()`/`classes_url()` danno allora il foglio del sito in `uploads/xb-histos-css/histos-css-<hash>.css`, e `classes()` non propone le classi spente. In Publish `filterHistosClasses` fa lo stesso sullo stesso testo (la fixture ne confronta l'impronta SHA-256), e ogni pagina si alleggerisce.
- **Tema scuro** (`dark_on`): `Tokens::dark()` dà i colori delle famiglie in scuro (il proprio o la sfumatura 400, con il testo leggibile, le trasparenze e il passaggio sulla sfumatura `dark_hover_shade`, 300 di partenza) e i ruoli scuri. Il foglio delle modifiche aggiunge `:root .xb-scheme-dark,:root.xb-scheme-dark{…}` e `:root .xb-scheme-light,:root.xb-scheme-light{…}`, più alto per specificità delle classi di `histos.css`; con lo schema `dark` tutta la pagina (`:root:not(.xb-scheme-light)`) è scura, con `system` lo diventa dentro `@media (prefers-color-scheme:dark)`. La Leggibilità controlla anche le coppie del tema scuro (`scheme: "dark"`).
- **Semantici:** `--xb-bg`, `--xb-surface`, `--xb-surface-2`, `--xb-text`, `--xb-text-muted`, `--xb-heading`, `--xb-border`, `--xb-link`, `--xb-link-hover`, `--xb-focus`. Con la classe `xb-scheme-dark` puntano alle sfumature scure.
- **Forme ed effetti:** `--xb-radius-s|m|l|xl|full`, `--xb-shadow-s|m|l|xl`, `--xb-border-width`, `--xb-transition`.
- **Larghezze:** `--xb-width-content`, `--xb-width-wide`, `--xb-width-prose`.
- **Caratteri:** `--xb-font-body`, `--xb-font-heading`, `--xb-font-mono`, `--xb-leading-tight|normal|loose`.

`xb-tokens.css` contiene i valori predefiniti. Se i parametri del sito sono diversi, Histeon stampa subito dopo i soli token che cambiano, e poi gli override del pannello Design system.

## 3. Classi (`histos.css`)
Tutte con prefisso `xb-` e **senza doppio trattino**: altri framework stilizzano `[class*="btn--"]` o `[class*="grid--"]`, e un sito che arriva da uno di loro ne conserva le regole nel foglio di compatibilità. Nessuno stile sugli elementi nudi.

| Famiglia | Classi |
|---|---|
| Contenitori | `xb-container`, `xb-container-wide`, `xb-container-prose`, `xb-section`, `xb-section-s|l|xl|none` |
| Disposizione | `xb-stack`, `xb-stack-s|l`, `xb-cluster`, `xb-flow`, `xb-center`, `xb-grid`, `xb-grid-2 … --6` (colonne che si riducono da sole sotto la larghezza minima), `xb-grid-auto` |
| Spazi | `xb-p-{passo}`, `xb-px-…`, `xb-py-…`, `xb-mt-…`, `xb-mb-…`, `xb-gap-…` (passi `3xs … 4xl`, più `0`) |
| Testo | `xb-text-{xs … 4xl}`, `xb-h1 … xb-h6`, `xb-font-body|heading|mono`, `xb-weight-400|500|600|700`, `xb-align-start|center|end`, `xb-balance`, `xb-measure`, `xb-prose` |
| Colori | `xb-color-{famiglia}[-{50…950}]`, `xb-bg-{famiglia}[-{50…950}]` (con il testo leggibile), `xb-border-{famiglia}`, `xb-scheme-dark`, `xb-scheme-light` |
| Forme | `xb-rounded-s|m|l|xl|full`, `xb-shadow-s|m|l|xl`, `xb-border` |
| Componenti | `xb-btn`, `xb-btn-primary|secondary|accent|outline|ghost`, `xb-btn-s|l`, `xb-card`, `xb-badge`, `xb-link` |
| Accessibilità e utilità | `xb-visually-hidden`, `xb-skip-link`, `xb-clickable-parent`, `xb-cover`, `xb-aspect-square|video|photo`, `xb-overlay` |

Il motore *CSS su richiesta* (ADR 0006) manda in pagina solo le regole delle classi presenti.

## 4. Importazione da Automatic.css
Si fa da **Impostazioni → Histeon → Cambio di framework**: da Automatic.css a Histeon CSS.

Cosa serve: i fogli che Automatic.css ha generato in `uploads/automatic-css/`. Bastano anche col plugin già spento.

**Anteprima della migrazione (nessuna scrittura)**
- valori trovati e token che li riceveranno;
- classi e variabili da riscrivere, documento per documento;
- nomi senza corrispondenza, che restano serviti dal foglio di compatibilità.

**Prepara in sviluppo**
1. **Valori.** Tutte le variabili di Automatic.css vanno nel foglio globale `carry-acss` (Carry), e i token Histeon CSS corrispondenti vi puntano: `--xb-primary-800: var(--primary-dark)`, `--xb-space-m: var(--space-m)`, `--xb-h1: var(--h1)`… (74 corrispondenze in `AcssImport::tokens()`). I valori vengono così dal foglio `carry-acss` anche con Automatic.css spento; nella prova descritta più sotto la pagina è rimasta uguale al pixel.
2. **Palette.** I colori di base di Automatic.css (`primary`, `secondary`, `accent`, `neutral`) diventano i parametri `design` di Histeon CSS: le sfumature che Automatic.css non ha (100, 400, 500, 900) e i colori di stato appartengono alla stessa palette.
3. **Nomi.** Variabili e classi usate in contenuti, stili e selettori si riscrivono con `AcssImport::variables()` e `::classes()`. Le classi solo dove la classe Histeon CSS imposta la stessa proprietà dallo stesso valore:
   - `text--l` → `xb-text-l`;
   - `text--primary-dark` → `xb-color-primary-800`;
   - `hidden-accessible` → `xb-visually-hidden`;
   - `var(--space-m)` → `var(--xb-space-m)`, `var(--primary-dark)` → `var(--xb-primary-800)`, `var(--section-space-l)` → `var(--xb-section-l)`.

   Le altre classi (`bg--*`, `btn--*`, `section--*`…) tengono il nome e la loro regola. Rinominarle cambierebbe l'aspetto, perché le regole Histeon CSS non sono identiche.
4. **Resto.** Le regole delle classi che il sito usa vanno nel foglio di compatibilità (Carry), servito finché Histeon CSS è attivo.
5. Il framework del documento `settings` diventa Histeon CSS.

**Pubblica:** la release porta online tutto insieme e il ripristino la annulla. Dopo la release Automatic.css si può disattivare.

### Senza importare: i fogli già presenti nel sito
Importare è consigliato, non obbligatorio.
- **Fogli di Automatic.css (`AcssLegacy`).** Se Automatic.css è spento ma i suoi fogli generati sono ancora in `uploads/automatic-css/`, il sito non è stato importato (nessun foglio di compatibilità di Automatic.css) e il framework scelto in Histeon è ancora Automatic.css (o nessuno, o non ancora scelto), Histeon serve lui quei fogli, nello stesso ordine. Se il sito ha scelto Histeon CSS o Core Framework, i fogli rimasti da una prova di Automatic.css non vengono serviti: cambierebbero titoli, link e sezioni del progetto. Classi e variabili continuano a valere, e nessun codice di Automatic.css viene eseguito.
- **Promemoria (`LegacyReminder`).** Nelle schermate di amministrazione, per gli amministratori che possono costruire, un avviso spiega che importare in Histeon e Histeon CSS è consigliato. Ha due pulsanti:
  - «Importa ora» (Impostazioni → Histeon);
  - «Più tardi», che lo nasconde per 14 giorni.

  Lo stesso avviso compare finché ci sono contenuti con blocchi Etch.

### Corrispondenze dei colori
Per ogni famiglia di Automatic.css (`primary`, `secondary`, `accent`, `base`, `neutral`):

| Automatic.css | Histeon CSS |
|---|---|
| `ultra-light` | 50 |
| `light` | 200 |
| `semi-light` | 300 |
| base | base (500) |
| `hover` | 600 |
| `semi-dark` | 700 |
| `dark` | 800 |
| `ultra-dark` | 950 |

La famiglia `base` di Automatic.css diventa `neutral` in Histeon CSS, se il sito non ne ha già una propria.

**Provato su fogli generati.** Con fogli che Automatic.css aveva generato per un sito, copiati su un sito di prova con Automatic.css **spento**: una pagina scritta con le sue classi (sezioni, `content-grid`, card, bottoni, testi, sfondi, larghezze) e variabili, fotografata prima e dopo la migrazione pubblicata. Schermo grande e telefono: 0% di pixel diversi, nessuna differenza di posizione o di stile; cambiano solo i nomi delle classi tradotte (`text--l` → `xb-text-l`). Test: `HistosCssImportTest::test_a_site_with_automatic_css_turned_off_moves_too`, che gira quando i fogli generati ci sono; i test che richiedono Automatic.css acceso lo dicono e si saltano.

## 5. Ricette
Nel pannello CSS del builder si scrive `@` più il nome, all'inizio di una dichiarazione, e la ricetta si espande. Le ricette di Histeon CSS sono in `HistosCss\Recipes` e usano token `--xb-*` con un valore di riserva, quindi funzionano con qualsiasi framework.

| Gruppo | Ricette |
|---|---|
| Disposizione | `@centra`, `@centra-alto/basso/sinistra/destra`, `@flex-centro`, `@pila`, `@fila`, `@fila-spaziata`, `@griglia-auto`, `@griglia-2/3/4/5/6`, `@griglia-1-2`, `@griglia-2-1`, `@griglia-1-3`, `@griglia-3-1`, `@flex-griglia`, `@griglia-contenuto`, `@contenitore`, `@sezione`, `@tutta-larghezza`, `@flusso`, `@colonne`, `@divisori`, `@divisori-orizzontali`, `@appiccicoso`, `@scorri-orizzontale` |
| Posizione e media | `@riempi`, `@sfondo-elemento`, `@immagine-copri`, `@aspetto-video`, `@aspetto-quadrato`, `@sovrapposizione`, `@link-esteso`, `@piede-rivelato` |
| Testo | `@tronca`, `@tronca-righe`, `@bilancia`, `@misura`, `@testo-gradiente`, `@titolo` |
| Sfondi e bordi | `@gradiente-lineare/radiale/conico/netto`, `@trasparenza`, `@vignetta`, `@bordo-gradiente`, `@sfuma-basso/alto/lati/bordi`, `@raggio-concentrico`, `@nastro-angolo` |
| Componenti | `@card`, `@solleva`, `@vetro`, `@divisore`, `@pulsante-reset`, `@lista-pulita`, `@suggerimento` |
| Stati e pseudo-elementi | `@al-passaggio`, `@solo-puntatore`, `@focus`, `@focus-dentro`, `@prima`, `@dopo` |
| Accessibilità e movimento | `@nascosto-accessibile`, `@link-salta`, `@transizione`, `@meno-movimento`, `@barra-nascosta` |

**Nomi alternativi.** Si può scrivere `?nome` invece di `@nome`, e alcune ricette hanno nomi alternativi che portano alla ricetta Histeon che fa la stessa cosa: per esempio `?auto-grid` è `@griglia-auto`, `?line-clamp` è `@tronca-righe`, `?fade-bottom` è `@sfuma-basso`. L'elenco dei nomi alternativi è `RECIPE_ALIASES` in `editor/src/lib/recipes.ts`.

**Pannello Ricette.** Il pannello è a destra, chiuso all'inizio, e si può spostare come gli altri. Ogni ricetta è un pulsante, raccolto per gruppo; la ricerca trova anche i nomi alternativi. Quando il puntatore passa sopra un pulsante, o il pulsante ha il fuoco, la ricetta si vede prima di applicarla:
- in un campione nel pannello, una cornice isolata senza script;
- sull'elemento selezionato nella tela.

Un clic scrive la ricetta in fondo al CSS della classe dell'elemento, con un commento `/* @nome */`. Se l'elemento non ha classi, il pannello propone di dargliene una.

Nella scheda **JavaScript** ci sono le ricette per il modulo JavaScript del documento:
- compare scorrendo;
- numero che conta;
- intestazione che si riduce;
- «torna su»;
- apri e chiudi;
- scorrimento morbido alle ancore;
- anno corrente;
- link esterni in una nuova scheda.

Il codice prende il selettore della classe. Quando l'effetto ha bisogno di stili, aggiunge anche il CSS alla classe. L'anteprima mostra l'effetto solo con il CSS, perché il pannello non esegue script. Le ricette JavaScript le applica solo chi può scrivere il JavaScript del documento (`unfiltered_html`). Ogni ricetta rispetta `prefers-reduced-motion`.

Le ricette che il sito aveva in Automatic.css arrivano con l'importazione (API pubblica di Automatic.css, quando è attivo). Restano accanto a queste e, a parità di nome, vince quella del sito. Ogni ricetta passa lo stesso controllo: nome semplice, niente markup, graffe bilanciate, al massimo 4 KB.

## 6. Il pannello
Il pannello Histeon CSS è **uno solo**, scritto in Svelte in `editor/src/histos-css/`:
- `HistosCssPanel.svelte` disegna le sezioni dall'elenco dichiarativo delle opzioni (`options.ts`: chiave, sezione, etichetta, parole inglesi per la ricerca, limiti uguali a `DesignData::LIMITS`, verificati da un test);
- `host.ts` dice cosa chiede al posto in cui si apre: l'anteprima dal server, dove mostrare il CSS, dove leggere i token;
- nel builder lo usa il generatore della sezione Design system (`DesignGenerator.svelte`), con i token sulla tela, in WordPress e in Histeon Publish;
- sopra le pagine di WordPress lo monta il cassetto (`drawer.ts`, `Drawer.svelte`), costruito a parte da `vite.histos-css.config.ts` in `plugin/assets/builder/histos-css/panel.js` e caricato da `HistosCss\Panel` e dal plugin autonomo (che lo riceve da `scripts/package-histos-css.sh`).

Per tutto il pannello: un punto e **↺** su ogni opzione diversa dal predefinito; la ricerca per nome, anche in inglese; **Esporta** e **Importa** in JSON (`{"format":"histos-css-design","version":1,"design":{…}}`, solo i valori cambiati, al massimo 64 KB, chiavi sconosciute rifiutate e valori validati dal server con `design/preview` prima di prenderli); **Predefiniti** con conferma.

Il cassetto si apre sopra qualsiasi pagina, sul sito o nell'amministrazione, senza ricaricarla:
- dalla voce **Histeon CSS** della barra di amministrazione;
- con **Alt+Maiusc+H**;
- con l'indirizzo che finisce in `#xb-histos-css`.

| Sezione | Cosa si fa |
|---|---|
| Colori | Le otto famiglie: selettore o valore scritto (hex, `rgb()`, `oklch()`), con le 11 sfumature calcolate |
| Testo | Parametri della scala, con l'anteprima di ogni passo e la sua misura in px alla larghezza attuale |
| Spazi | Parametri della scala e delle viewport, con le barre di ogni passo |
| Forme | Raggio e larghezze, con l'anteprima di raggi e ombre |
| Caratteri | Le tre pile di caratteri, con l'anteprima |
| Ricette | Ricerca, testo della ricetta, copia |

- **Anteprima dal vivo.** Ogni modifica va a `POST xb/v1/design/preview`, che valida i parametri e risponde con i soli token che cambiano. Il CSS vale subito sulla pagina aperta. I campioni del pannello leggono i token da un elemento nascosto nello shadow DOM, con i valori predefiniti e l'anteprima: funzionano anche nell'amministrazione e non si mescolano con le variabili dell'interfaccia. Non si salva nulla.
- **Salva in sviluppo.** Scrive un commit di sviluppo del documento `settings` (`POST xb/v1/tokens`, chiave `design`). La release lo porta online. «Predefiniti» torna ai valori di Histeon CSS.
- **Interfaccia.** Vive in uno shadow DOM con i colori del marchio (inchiostro, carta, corallo), quindi non tocca gli stili della pagina e non ne subisce: Svelte vi inserisce gli stili del componente. Nessun testo diventa markup (Svelte, mai `{@html}`); il CSS del server entra come testo di un elemento `style`.
- **Lingua.** I testi inglesi del pannello sono scelti al momento della build da `editor/i18n/en` e viaggiano nel pacchetto; il server dice solo se usarli (`english`).
- **Chi lo vede.** Solo gli utenti con `xb_build` e la barra di amministrazione visibile. Se il sito usa un altro framework, un avviso lo dice e porta alle impostazioni.

## 7. Prove
- **Generatore e foglio** (`Color`, `Scale`, `Design`, `Tokens`, `Utilities`, script di generazione, provider Histeon CSS): test unitari.
- **Importazione** (`AcssImport`: valori, nomi, rapporto; integrazione con `Migration` e `Carry`): prove d'integrazione con fogli di Automatic.css generati nel WordPress di sviluppo (§4).

## 8. Sicurezza
- I parametri passano dal controllo di `Design::validate`. Nessun valore può chiudere la regola `:root`, aprire un blocco o caricare risorse esterne. Valgono le stesse regole di `TokenOverrides::problem`, più limiti numerici.
- L'importazione legge solo file `.css` sotto `uploads/` (risolti con `realpath`), riusa i controlli di `Carry` e accetta come override solo valori che passano `TokenOverrides::problem`.
- Richiede `manage_options` e `xb_build`, nonce, e scrive solo commit di sviluppo.
- Non legge mai le opzioni di licenza di Automatic.css.

## 9. Plugin a sé (`histos-css`)
Per i siti senza il builder (ADR 0015). Sorgenti: `histos-css/` (avvio, impostazioni, rotte) e il nucleo di Histeon CSS in `plugin/src/Framework/HistosCss`. Pacchetto: `scripts/package-histos-css.sh [commit]` → `dist/histos-css-<versione>-<sha>.zip` (firmato con `XB_SIGNING_KEY`, come Histeon).

**Una sola fonte.** Il nucleo puro (`Color`, `Design`, `Scale`, `Tokens`, `Utilities`, `Recipes`, `ThemeJson`) è lo stesso codice di Histeon: lo script lo copia dal commit nel namespace `HistosCss\Core`, e si ferma se un file nomina ancora qualcosa di Histeon. Ciò che dipende da Histeon sta fuori dal nucleo: `SiteDesign` (parametri dal versioning), `Gutenberg` (filtro solo con Histeon CSS attivo), `Panel`, `Module`, `AcssImport`.

**Cosa fa.** Token (`xb-tokens.css`) più i parametri del sito (solo ciò che differisce dai predefiniti, in linea una volta sola), le classi (`histos.css`), la palette e le dimensioni del testo nell'editor a blocchi, e lo stesso pannello in popup (barra di amministrazione, Alt+Maiusc+H) con anteprima dal vivo e Leggibilità. **Aspetto → Histeon CSS** spiega dove si trova.

**Differenze da Histeon.** I parametri stanno nell'opzione `histos_css_design` e salvare li applica subito (non c'è sviluppo né release). Le rotte sono `histos-css/v1/tokens` (GET, POST), `design/preview` e `framework` (recipes), solo per chi ha `edit_theme_options`, con il nonce REST; ogni valore passa da `Design::validate`. Disinstallare cancella l'opzione.

**Con Histeon attivo** il plugin resta fermo (Histeon lo include) e lo dice agli amministratori; i namespace diversi evitano ogni conflitto tra le due copie.

## Blocchi di WordPress con i token

Oltre a palette e dimensioni del testo, Gutenberg riceve da Histeon CSS le **spaziature** (`spacingSizes`, `var(--xb-space-*)`) e le **ombre** (`shadow.presets`, `var(--xb-shadow-*)`), dopo quelle del tema. I blocchi di WordPress hanno anche **stili** fatti con i token, da scegliere in «Stili» del blocco: pulsante «Histeon pieno» e «Histeon contorno», gruppo «Histeon sezione» e «Histeon scheda», immagine «Histeon arrotondata», citazione «Histeon citazione». Valgono alle stesse condizioni dei preset (Histeon CSS attivo, palette accesa nel pannello) e il loro CSS arriva con il blocco, nell'editor e sul sito. Implementazione: `ThemeJson::spacing_sizes()`, `shadows()`, `block_styles()`, `Gutenberg::block_styles()`. Test: `plugin/tests/Integration/BlockStylesTest.php`.
