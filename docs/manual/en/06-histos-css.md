# Histeon CSS

Histeon CSS is Histeon's free CSS framework and design system. From a few values (the brand colors, the minimum and maximum sizes of text and spacing) it calculates a complete palette, fluid scales that adapt to the screen, radii, shadows and fonts. On top of that it adds utility classes and ready-made recipes. It is included in Histeon, turned on by default and requires no license.

## What it is

Histeon CSS is made of three parts:

- **tokens**, CSS variables with the `--xb-` prefix: `var(--xb-primary)`, `var(--xb-space-m)`, `var(--xb-text-l)`;
- **utility classes**, with the `xb-` prefix and always with a single hyphen: `xb-grid-3`, `xb-text-l`, `xb-btn xb-btn-primary`;
- **recipes**, groups of declarations to expand in the CSS panel: `@centra`, `@card`, `@tronca`.

Histeon CSS doesn't style bare elements (`h1`, `a`, `button`): turning it on for an existing site changes nothing until you use its classes or tokens. And thanks to on-demand CSS, each page gets only the classes that page uses.

## The Histeon CSS panel

The panel opens over any page, on the site or in the admin, without reloading it:

- from the **Histeon CSS** item in the admin bar;
- with [[Alt+Shift+H]];
- by adding `#xb-histos-css` to the page address.

It has six tabs:

| Tab | What you do there |
|---|---|
| **Colors** | The eight color families; a click on the strip opens the 11 shades and the 9 transparencies, and a click on a swatch copies its variable |
| **Text** | The text scale, with a preview of each step |
| **Space** | The spacing scale and the reference widths, with a bar for each step |
| **Shapes** | Radius and widths, with a preview of radii and shadows |
| **Fonts** | The three font families, with a preview |
| **Recipes** | Search for a recipe, read it, copy it |

Every change shows **immediately on the page in front of you**, but it isn't saved until you press **Save to development**: the new values become a change in development and go live with the next release, like everything else.

- A changed option has a **dot** next to its name, and **↺** takes it back to its default. The dot shows on the tab too.
- **Search an option** finds an option by name, in Italian or in English: «line length» finds «Text measure».
- **Export** downloads the settings as a JSON file, with only the changed values; **Import** reads them back from a file, once the server has checked them. Then you save them as usual.
- **Export tokens (DTCG)** downloads the computed tokens in the Design Tokens Community Group format (version 2025.10), to open in Figma or Penpot: `tokens.chiaro.json` (light) and, with the dark theme on, `tokens.scuro.json` (dark). Colours, sizes in px or rem, fonts, weights and shadows; fluid sizes (`clamp()`), units the format does not admit (em, ch…) and other values stay out and are listed, with their CSS, in the file itself.
- **Import tokens (DTCG)** reads the tokens exported by a design tool: from Figma one file per variable mode («Export modes»), from Penpot the file of **Tokens → Export**. You can choose several files at once; a file with `dark` or `scuro` in its name is the dark theme, and a Penpot file with themes already carries its own. The family colours (primary, secondary, neutral, the states), the fonts, the base text size, the weight and line height of headings and the radius become parameters of the panel, over the values already there; the server checks them as for **Import**. Shades are not copied: Histeon CSS computes them from the family colour. Text sizes never become fixed lengths: the **Report of the imported tokens**, under the message, gives for each the nearest step (`--xb-text-l`, `--xb-h2`) and what was left out, with the reason. In the builder the other tokens (other colours, spaces, shadows) join the **Site variables**. Then you save as usual.
- **Defaults** takes everything back to the Histeon CSS values, after a confirmation.

It is the same panel as the builder's **Design system** section (in WordPress and in Histeon Publish) and as the Histeon CSS plugin's.

The panel is visible only to users who can build with Histeon. If the site uses another framework, a notice says so and takes you to the settings.

## Motion and effects

In the same tab, **Motion and effects** sets the motion classes, CSS only:

- **on entering and leaving** the view while scrolling: `xb-enter-fade`, `xb-enter-up`, `-down`, `-left`, `-right`, `-grow`, `-shrink`, `-blur` (and `xb-exit-…`). Choose where in the view the entrance is complete, the distance and the easing. In browsers that cannot tie an animation to the scroll the elements simply stay still;
- **on hover:** `xb-hover-lift`, `-shadow`, `-glow`, `-scale`, `-bright`, `-fade` and `xb-hover-underline` (also `-center` and `-right`), with duration, lift and scale.

You can also set the starting size of `grow` and `shrink`, the blur of `blur`, where the exit is complete, the brightness of `bright` and the opacity of `fade`.

For those who ask their system for less motion, everything stays still.

## Advanced

At the end of the **Forms and details** tab, **Advanced** chooses which groups of variables to print: transparencies, shadows, buttons and links, forms, cards, icons, motion. A group switched off is not in the site's sheet, which gets lighter; the classes that use it go back to their own values or do nothing, and the CSS panel flags its variables as unknown. In the same way you choose the **groups of classes** to print (colours, buttons, motion, icons, cards, forms): a group switched off is not in the sheet and not offered in the CSS panel. Another switch leaves the theme's palette to the block editor instead of Histeon CSS's.

There too, **Tokens inline** prints the tokens in the page instead of loading a file (WordPress only: in Histeon Publish all the CSS is one sheet already). A group of variables or classes switched off shows at once in the preview.

## Forms and details

In the panel's **Forms and details** tab:

- **Forms:** padding, radius, background, border, focus ring, text size and placeholder of the fields; label size and weight; help size and colour; error and valid colours; checkboxes and radios (also as a switch); gap between fields. With the default styles also the dropdown arrow and field groups (`fieldset`). `xb-input` fields always follow them; with **Default form styles** also fields without a class and **Fluent Forms** forms, whose submit button becomes like the site's buttons;
- **Accessibility and details:** a focus ring across the site, smooth scrolling (never for those who ask for less motion), reduced motion when the system asks for it (these three are on to start with), selection colour, quote style and **text columns** (class `xb-columns`).

**Step bar:** with the default form styles, the bar of Fluent Forms' multi-page forms takes the height, colour and track chosen here.

## Shapes, buttons and links

Besides what follows: every **shadow** is set by step (offset, blur, opacity); **buttons** switch off by family or by variant (outline, light, dark), and the classes switched off leave the sheet; the light and dark variants choose their **hover shade**; a **surface** can have a name of its own (`xb-surface-<name>`) and be a gradient, with text that reads at both ends; there are two more **overlays** (`xb-overlay-2`, `-3`) and the card title (`xb-card-title`) with its own size; **icons** have their own sizes; **icon lists** choose their symbol; **external links** show ↗ (or only a text for screen readers), once you write the site's domain.

In the panel's **Shapes** tab:

- **Default border and dividers:** width and style of the `xb-border` border; width, style and space of the `xb-divider` dividers; the ratio of the radius scale;
- **Buttons:** padding, radius (up to a pill), weight, line height and text size, case, letter spacing, border, minimum width, and **full width on phones**. They apply to every button: `xb-btn`, each family's variants (`xb-btn-primary`, `xb-btn-primary-outline`, `xb-btn-primary-light`, `xb-btn-primary-dark`, and the same for secondary, tertiary, accent, neutral and the states) and the sizes from `xb-btn-xs` to `xb-btn-xl`. On hover a filled button takes the shade chosen in the palette;
- **Links:** underline (always, on hover, never), thickness and offset of the line; with **Default link styles** they also apply to links without a class.

Also in the **Shapes** tab:

- **Cards:** padding, inner gap, radius, background, shadow, border and media aspect ratio. Inside an `xb-card`, `xb-card-media` puts an image edge to edge with that ratio and `xb-card-body` lays out the text with the chosen gap; a button at the end stays at the bottom. `xb-radius-inner` gives a nested element the right (concentric) radius;
- **Icons:** colour and stroke width, sizes from `xb-icon-xs` to `xb-icon-2xl`, a boxed icon (`xb-icon-boxed`) and lists with a check mark (`xb-list-icon`).

Also in the **Shapes** tab, **Overlays and surfaces**: colour, opacity and blend of the `xb-overlay` overlay (and of its graded `xb-overlay-gradient`), and three **site surfaces** (`xb-surface-1`, `-2`, `-3`) with their background, border and shadow: the text on them turns white or black by itself, whichever reads.

There are also text shadows (`xb-text-shadow-s`, `-m`, `-l`) and `drop-shadow` shadows for images and transparent SVGs (`xb-drop-shadow-s`, `-m`, `-l`).

## Space and layout

Text spacing can apply to **all the content** (`main`), not only to `xb-prose`, and sections can sit **on the content grid by default**: both start off, and any class of the site wins over them.

In the panel's **Space** tab, besides the scale:

- **Sections:** the **multiplier** of the sections' vertical space (small and large screen), the **default padding** of `xb-section` and the **side gutter** in pixels (at 0 the space m applies);
- **Gaps between elements:** between a section's children, between the blocks of `xb-stack`, in the grids; for `xb-grid-auto` the minimum column and whether the columns fill the row or keep their place;
- **Texts:** in an `xb-prose` text, the gap between blocks, before and after headings and between list items;
- **Layout:** the **boxed layout** (the page narrow and centred on a background, with a shadow), minimum site width, **header height** (`--xb-header-height`, used by `--xb-sticky-top` for sticky elements) and **Anchors below the header** (on to start with), so a link to a section does not hide it behind a fixed header.

The **content grid** works with the `xb-content-grid` class on the container: its children sit in the content column, `xb-wide` takes them to the wide width, `xb-full` edge to edge, `xb-narrow` to the text measure.

## Typography

Headings can be italic; each level also has its own letter spacing and max width. Each text step (`xb-text-xs` … `xb-text-4xl`) can have its own line height, letter spacing, max width, case and wrapping. In **Site fonts**, the **Accent** and **Display** buttons give a site font to the two free roles.

In the panel's **Text** tab, besides the text scale:

- **Separate heading scale** (on to start with): headings H1-H6 get their own base and ratio; off, they follow the text scale;
- **Headings:** font (one of the five roles: body, headings, code, accent, display), weight, line height, letter spacing, case, wrapping (balanced to start with) and max width. They apply to the `xb-h1 … xb-h6` classes; with **Default heading styles** also to the site's `h1 … h6` headings;
- **Headings by level:** weight, line height, colour, font and case of one level only, for example all H2 lighter and in the primary colour («As all» or 0 = the value of all headings);
- **Sizes of single steps:** a text step or a heading with its own two sizes in pixels (small and large screen), outside the scale;
- **Text and root:** line height and wrapping of the text (with **Default text styles** they apply to the page body and paragraphs) and the **root size**, which changes every rem measure: use it only if you know why.

The **Fonts** tab has two free roles, **Accent** and **Display**, for the headings or with `var(--xb-font-accent)` and `var(--xb-font-display)`.

## Colors

There are eight families: **Primary**, **Secondary**, **Accent**, **Neutral**, **Success**, **Warning**, **Danger** and **Info**. For each one, choose the color with the picker or type a value (hexadecimal, `rgb()` or `oklch()`).

From each color Histeon CSS calculates:

- the base color: `--xb-primary`;
- eleven shades, from `--xb-primary-50` (the lightest) to `--xb-primary-950` (the darkest);
- the color of readable text on top of it: `--xb-on-primary`.

Shades are calculated in the OKLCH color space, which keeps the hue and makes lightness uniform across different families.

In the panel's **Colors** tab you also find:

- **Tertiary**, a ninth family, off until you switch it on; the switch next to the name also turns off secondary, accent and the state families you do not use (their variables go away);
- **Palette:** **one lightness** for the brand families or for the state ones, so they all weigh the same; the **hover shade** (`--xb-primary-hover` and the like, 600 to start with);
- **Colour roles:** for background, surfaces, text, headings, border, links and focus pick a family or a shade, or leave «Automatic». With the **automatic contrast relationships**, an automatic text that does not read on its background moves by itself to the nearest shade that does;
- **Dark theme:** when on, the site has the dark roles and colours. The **site scheme** says whether it stays light (dark only where you put `xb-scheme-dark`), is always dark or follows the visitor's system. Each family can have its own dark colour, or keep the automatic one, and you choose the dark theme's hover shade.

The **Readability** list checks the dark theme's pairs too.

Then there are the **role colors**, which point to the right shades: `--xb-bg`, `--xb-surface`, `--xb-text`, `--xb-text-muted`, `--xb-heading`, `--xb-border`, `--xb-link`, `--xb-focus` and others. With the `xb-scheme-dark` class on a container, these colors switch to the dark shades.

## Fluid text and spacing

Text and spacing are **fluid**: they grow with the screen width between a minimum and a maximum, without media queries. You choose a few values, Histeon CSS calculates all the steps.

| Parameter | Default |
|---|---|
| Text: size of step `m` | from 16 to 18 px |
| Text: ratio between one step and the next | from 1.2 to 1.25 |
| Spacing: size of step `m` | from 16 to 24 px |
| Spacing: ratio between one step and the next | from 1.5 to 1.6 |
| Reference screen widths | from 360 to 1440 px |

The first value applies on narrow screens, the second on wide ones; in between, the value grows continuously.

The steps you get:

- **text:** from `--xb-text-xs` to `--xb-text-4xl`, plus `--xb-h1` … `--xb-h6` for headings;
- **spacing:** from `--xb-space-3xs` to `--xb-space-4xl`, plus the section spacing (`--xb-section-s`, `-m`, `-l`, `-xl`) and the side margin `--xb-gutter`.

## Shapes, widths and fonts

- **Radii:** `--xb-radius-s`, `-m`, `-l`, `-xl` and `-full`, calculated from the radius of step `m` (8 px by default).
- **Shadows:** `--xb-shadow-s`, `-m`, `-l`, `-xl`.
- **Widths:** `--xb-width-content` (72 rem), `--xb-width-wide` (90 rem) and `--xb-width-prose` (65 characters, the comfortable measure for reading).
- **Fonts:** `--xb-font-body`, `--xb-font-heading` and `--xb-font-mono`. Write the names separated by commas, with quotes around those that contain spaces.

> To serve fonts from your own site, without requesting them from Google on every visit, use **Histeon → Settings → Local fonts**: import a family from Google Fonts or upload a WOFF2 file. Then write its name in the panel.

## The generator and the Design system in the builder

In the builder, the **Design system** section shows all the variables of the active framework with their real value: a swatch for colors, a bar for spacing, “Aa” at its size for text, a square for radii, a card for shadows. A click copies `var(--name)`; the search filters by name.

With Histeon CSS active, the same section contains the **Histeon CSS generator**: colors, scales and shapes from a few values, with the changes visible right away on the canvas. Below the colors, **Readability** checks the contrast of the text and background pairs, which must be at least 4.5:1, and says “all readable” or how many pairs need a second look. **Save in development** keeps the changes; **Back to defaults** starts over; **↺** next to a token resets it to its default value.

Colors are shown by family: only the main color of each one appears (primary, secondary, accent, neutral, the states and the roles such as text and background). The button with the dots next to it opens that family's shades, transparencies and text color. Searching for a name shows every color that contains it.

**Site variables.** **＋ Variable** adds a variable of your own, next to Histeon CSS's. Its name becomes `--xb-site-<name>`: lower-case letters, digits and hyphens, up to 40 characters. The value follows the token rules: numbers, units, colors, `calc()`, `clamp()`, `var()`. The canvas shows it at once; **Save in development** keeps it, and the release puts it online. It appears in the CSS panel's completions. **✕** deletes it. **Import variables** adds many from one file: `variabili-sito.json` written by Histeon Publish, or the tokens exported by Figma or Penpot (those without a design parameter). A variable of the same name takes the new value; the maximum is 200.

**Recipes.** The same section lists every recipe with where it comes from: Histeon, Automatic.css (imported) or Site. A click opens a recipe's CSS, which you can change: the site's version replaces Histeon's recipe with the same name, and **↺ Back to the original recipe** brings it back. **＋ Recipe** adds a new one, usable at once with `@name` in the CSS panel and in the Recipes panel. A recipe cannot contain markup, `url()`, `@import` or anything else that loads or runs something, and is at most 4 KB.

## Utility classes

| Family | Examples |
|---|---|
| Containers | `xb-container`, `xb-container-wide`, `xb-container-prose`, `xb-section`, `xb-section-l` |
| Layout | `xb-stack`, `xb-cluster`, `xb-flow`, `xb-center`, `xb-grid-2` … `xb-grid-6`, `xb-grid-auto` |
| Spacing | `xb-p-m`, `xb-px-l`, `xb-py-s`, `xb-mt-xl`, `xb-mb-0`, `xb-gap-m` |
| Text | `xb-text-l`, `xb-h2`, `xb-font-heading`, `xb-weight-700`, `xb-align-center`, `xb-balance`, `xb-prose` |
| Colors | `xb-color-primary`, `xb-bg-primary-700` (with readable text), `xb-border-neutral`, `xb-scheme-dark` |
| Shapes | `xb-rounded-m`, `xb-shadow-l`, `xb-border` |
| Components | `xb-btn`, `xb-btn-primary`, `xb-btn-outline`, `xb-btn-l`, `xb-card`, `xb-badge`, `xb-link` |
| Accessibility and utilities | `xb-visually-hidden`, `xb-skip-link`, `xb-clickable-parent`, `xb-cover`, `xb-aspect-video`, `xb-overlay` |

The `xb-grid-2` … `xb-grid-6` grids reduce the number of columns by themselves when there isn't enough space. In the HTML panel, inside `class="…"`, the builder suggests these classes as you type.

## Recipes

In the CSS panel, type `@` plus the name at the start of a declaration, and the recipe expands into a group of declarations.

| Group | Recipes |
|---|---|
| Layout | `@centra`, `@flex-centro`, `@pila`, `@fila`, `@fila-spaziata`, `@griglia-auto`, `@griglia-2`, `@griglia-3`, `@griglia-4`, `@contenitore`, `@sezione`, `@tutta-larghezza`, `@flusso`, `@appiccicoso`, `@scorri-orizzontale` |
| Position and media | `@riempi`, `@immagine-copri`, `@aspetto-video`, `@aspetto-quadrato`, `@sovrapposizione`, `@link-esteso` |
| Text | `@tronca`, `@tronca-righe`, `@bilancia`, `@misura`, `@testo-gradiente`, `@titolo` |
| Components | `@card`, `@solleva`, `@vetro`, `@divisore`, `@pulsante-reset`, `@lista-pulita` |
| Accessibility and motion | `@nascosto-accessibile`, `@focus`, `@transizione`, `@meno-movimento`, `@barra-nascosta` |

Recipes use the `--xb-*` tokens with a fallback value, so they work with any framework.

## With other frameworks

Histeon also works with **Core Framework** and **Automatic.css**, if you have installed them yourself: Histeon doesn't include them. You choose the framework in **Histeon → Settings → CSS framework**. With Core Framework or Automatic.css, a button in the builder's left column opens their panel over the canvas.

The `--xb-*` tokens act as a bridge: Histeon links them to the variables of the active framework (for example `--xb-space-m` becomes `var(--space-m)`). A component written with the tokens therefore works with any framework, without changes.

### Switching frameworks

**Histeon → Settings → Framework switch with migration** rewrites the native names of the current framework (variables and classes) into those of the new one, in pages, components and styles.

1. In **Switch to**, choose the new framework.
2. Leave **Carry over the variables and rules of … that the site uses** turned on: the old framework's variables and rules stay available, so nothing breaks.
3. **Migration preview** shows, document by document, what would change and which names have no match. It doesn't change anything.
4. **Prepare in development** prepares everything as a change in development.
5. Check the preview and publish from the builder. A single release takes content and framework live together, and restoring the previous release also brings back the framework.

## From Automatic.css to Histeon CSS

If the site uses Automatic.css, you can switch to Histeon CSS without changing how the pages look. The migration reads the values from the sheets Automatic.css generated on the site.

Follow the steps in “Switching frameworks”, choosing **Histeon CSS** in **Switch to**. Here is what happens:

1. **The values.** All the Automatic.css variables are kept in a global sheet, and the Histeon CSS tokens point to them: the pages use the same values as before. Before publishing, check them with the visual comparison.
2. **The palette.** The Automatic.css base colors (primary, secondary, accent, neutral) become the colors of the Histeon CSS palette.
3. **The names.** Classes and variables with an exact equivalent are renamed: `text--l` becomes `xb-text-l`, `var(--space-m)` becomes `var(--xb-space-m)`, `hidden-accessible` becomes `xb-visually-hidden`.
4. **The rest.** Classes without an identical equivalent (`btn--*`, `bg--*`, `section--*`…) keep their name and their rule, served from a compatibility sheet.
5. **The recipes** the site had in Automatic.css come along too, if Automatic.css is still active during the import, and stay alongside those of Histeon CSS (when names are the same, the site's one wins).

After the release, the pages take their values and classes from Histeon CSS and the compatibility sheet.

| Automatic.css | Histeon CSS |
|---|---|
| `ultra-light` | 50 |
| `light` | 200 |
| `semi-light` | 300 |
| base color | base (500) |
| `hover` | 600 |
| `semi-dark` | 700 |
| `dark` | 800 |
| `ultra-dark` | 950 |

The Automatic.css `base` family becomes `neutral` in Histeon CSS, if the site doesn't already have one.

> Importing is recommended, not required. Without importing, the sheets already on the site stay loaded until you import, so the site stays as it was. An admin notice reminds you about the import: **Import now** takes you to the settings, **Later** hides it for 14 days.

If you import an Etch site that uses Automatic.css, the import page asks you where its design system should go: **Into Histeon CSS** (recommended), **Into Core Framework** (if it is installed) or **Stay on Automatic.css**.

### From Automatic.css to Core Framework

If you prefer Core Framework, the **From Automatic.css to Core Framework, everything included** section uses **Create the Core Framework project** to create a new project with the colors and all the variables of Automatic.css, plus a compatibility sheet with the rules the site uses. Then open Core Framework and press **Save changes**, switch to Core Framework with **Prepare in development** and publish.

## Histeon CSS as a standalone plugin

Histeon CSS also exists as a plugin of its own (`histos-css`), for sites that don't use the builder: for example a site made with the block editor and any theme.

It offers the tokens, the classes, the recipes and the same popup panel (admin bar or [[Alt+Shift+H]]), with live preview and the readability check. In the block editor, the palette and the text sizes become those of the site. **Appearance → Histeon CSS** explains where to find everything.

The differences compared with Histeon CSS inside Histeon:

- there is no development and no release: saving in the panel applies the values to the site immediately;
- the panel is reserved for those who can change the site's appearance (usually administrators);
- uninstalling the plugin deletes its values.

> If you activate Histeon on a site that already has the Histeon CSS plugin, the plugin stays idle and a notice tells you that you can deactivate it: Histeon CSS is already included.
