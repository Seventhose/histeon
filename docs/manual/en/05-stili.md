# Styles

In Histeon, styling is written in CSS, tied to a selector: a class or an id. The builder helps you with SCSS-style nesting, a bar of visual controls (the Mini GUI), named media queries and a selector manager. Each page gets only the CSS it actually uses.

## Classes and selectors

Histeon styles live in two places:

- **the selector CSS:** one entry for each class or id you have styled, for example `.card` or `#contacts`. It is the right place for almost everything;
- **the global sheets:** the CSS that applies to the whole site, such as a reset, the variables in `:root` or the styling of bare tags.

Styles apply across the whole site: the same `.card` looks the same on every page that uses it. Like content, they are saved in development and go live with the next release.

## The CSS panel

Select an element: at the top of the **CSS** panel a pill appears for each of its classes, one for its id (if it has one) and one for the **Global sheet**. Choose the pill and write.

In the panel you write only the **body** of the rule, without repeating the selector:

```css
padding: var(--space-m);
border-radius: var(--radius-m);
```

If the element has no classes, the panel asks you to add one: type it in the field (for example `hero`) and press **Add class**. The field already proposes a name, from the nearest block with a class and from what the element is (for example `hero__title` for a heading inside `.hero`): with the field empty, **Add class** uses it.

A link, a bold word or a `span` inside a sentence is part of the text: you edit it by typing in the paragraph. To style one on its own, **Alt+click** it on the canvas: it becomes an element with its own class (for example `hero__link`), selected and ready for the CSS panel.

While you type, the editor helps you:

- **framework variables:** typing `--sp` opens the list of variables; outside `var()` the completion inserts `var(--space-m)`;
- **abbreviations** at the start of a declaration: `df` (display: flex), `dg` (display: grid), `jcc`, `aic`, `mxa`, `w100` and more;
- **recipes:** `@` followed by the name, for example `@centra`, expands into a group of ready-made declarations (see [the Histeon CSS recipes](#en-histos-css-recipes));
- **named media queries:** after `@media (` the site's names are suggested.

While you write, the panel underlines what is wrong: unclosed braces, variables and named media queries the site doesn't have, `!important`, declarations without a value, fixed values equal to a token (with the token to use) and classes outside the BEM scheme. Hover over the underline to read why. The same warnings, for the page's selectors, are in **Checks**, **CSS** area.

## Nesting as in SCSS

Inside a rule you can nest states, parts, variants and media queries:

```css
padding: var(--space-m);
&:hover { color: var(--primary); }
@media (--tablet) { padding: var(--space-l); }
&__title { font-weight: 700; &--big { font-size: 2em; } }
&--dark { background: #000; }
```

On `.card` this also produces `.card__title`, `.card__title--big` and `.card--dark`: rules that start with `&__` or `&--` become sibling rules with the concatenated name, following the BEM convention. Each one reaches a page only if the page uses that class.

## The Mini GUI

The Mini GUI is a compact bar above the CSS editor that writes the most common declarations for you, while you look at the element on the canvas. The **Mini GUI** button opens and closes it; the choice stays in this browser.

| Group | Properties |
|---|---|
| **Layout** | display, direction, wrap, grid columns (2, 3, 4, auto), alignment, gap |
| **Spacing** | padding, vertical and horizontal padding, margins |
| **Size** | width, maximum width, minimum height, aspect ratio, image fit |
| **Text** | size, weight, line height, alignment, balanced wrapping, color |
| **Colors and borders** | background, radius, shadow, border, opacity |
| **Position** | position, offsets, layer, overflow, container type |

How it works:

- each button writes a real declaration into the selector's CSS, and the canvas shows it right away; a second click on the active value removes it;
- a group shows only what has an effect at that moment: alignment only with flex or grid, offsets only with a position set. “+N properties not needed right now” shows the others;
- spacing, text, radii, shadows and colors come from the active framework's scale, always as `var(--…)`;
- a coral dot marks the groups that already have values;
- nested rules you wrote by hand stay as they are.

## State and width

Next to the Mini GUI, two menus choose **where** to write (● marks the items that already have declarations):

- **State:** Normal, `:hover`, `:focus-visible`, `:active`, `::before`, `::after`;
- **Width:** **All**, **Same as canvas**, the site's named media queries, or the current canvas width (for example `≤ 1104px`).

The Mini GUI writes inside the right nested block, for example `&:hover { … }` or `@media (--tablet) { &:hover { … } }`, creates it if it is missing and removes it when it is left empty. While a state is chosen, the canvas shows the element in that state, as if the mouse were over it.

When you set the canvas to a certain width, the Mini GUI follows it automatically: this is [the canvas that follows](#en-il-builder-the-canvas-that-follows), described in the chapter on the builder. Choosing another width by hand stops following it; **Same as canvas** starts following it again.

## More: width queries and quick actions

The **More ▾** button opens two groups of commands.

**At the canvas width** (for example “At 720px”):

- **@media up to here** adds a `@media (width <= 720px) { }` block;
- **@media from here up** adds `@media (width >= 720px) { }`;
- **@container up to here** adds `@container (width <= 720px) { }`, which follows the width of the container (it needs an ancestor with `container-type`).

**Quick actions:**

- layout: **Flex row**, **Flex column**, **Grid 2**, **Grid 3**, **Grid 4**, **Auto grid**, **Center**, **Space between**, **Centered stack**;
- spacing: gap, padding or margin with the framework's scale;
- **px → rem:** converts pixel values to rem (16 px base).

Declarations that are already there are updated in place.

## Named media queries

Instead of repeating `(width < 64em)` in a hundred rules, give the condition a name and use the name. You find them in the **Styles and selectors → Named media queries** section, and you add them with **+ Media query**.

| Name | Default condition |
|---|---|
| `mobile` | `(width < 40em)` |
| `tablet` | `(width < 64em)` |
| `desktop` | `(width >= 64em)` |

You use them like this, in container queries too:

```css
@media (--tablet) { padding: var(--space-s); }
@container card (--tablet) { flex-direction: column; }
```

Change the condition in one place and it changes everywhere. Named media queries also appear as buttons above the canvas and in the Mini GUI's **Width** menu. They are versioned like everything else: they go live with the release. An unknown name stays as written and the browser ignores that rule.

## Global sheets

The **Global sheet** pill opens the CSS that applies to the whole site. Write only what is truly global there: resets, variables in `:root`, tag styling. A block's styling is better off in its selector. With **Full CSS** you see, read-only, the sheet and the CSS of each selector as they reach the page.

### From the sheet to the selector

In imported sites, for example from Etch, much of the CSS may sit in a global sheet. When you select an element whose class has rules in the sheet, the panel shows two views:

- **In the sheet (N):** only the rules that name that class, one per editor, with their context (`@media …`). Every change goes back into the sheet exactly where that rule was;
- **Own CSS:** the selector's CSS.

At the top of the **In the sheet** view, **As SCSS in the selector** shows those rules already gathered in nested form. **Move into the selector** takes them out of the sheet and puts them into the class's CSS, in a single step you can undo with [[Ctrl+Z]]. From then on the class is read and edited in one place, with the Mini GUI, states and widths.

To do the same with all the blocks at once, use **Into selectors…** (on the sheet's pill or in the **Styles and selectors** section): a preview tells you how many blocks and rules move and which ones stay, then **Move** does everything in one step.

> A rule stays in the sheet when moving it would change which rule wins, or when it is shared with other classes (`.a, .b`). That way the page doesn't change appearance. Before publishing, the visual comparison in the **Publish…** window confirms it.

## The selector manager

The **Styles and selectors** section lists all the site's selectors, including classes defined only in the global sheets.

- **Search for a selector**, sort by **Name**, **Usage** or **Size**, filter by origin (**All**, **Entries**, **Global sheets**) or show **Unused only**.
- For each selector you see where it is used and its CSS, editable as in the CSS panel.
- **Rename everywhere** changes the name of the class and its BEM parts (`card` to `box` also changes `card__title` and `card--dark`, but not `card-alt`) in the styles, in the global sheets and in all pages. You see a preview first; the rename is refused, with the reason, if the new name is already in use or if the class appears where you can't edit it, for example in the theme files.
- If the class also appears in free HTML (hand-written HTML blocks, Gutenberg blocks), the preview shows each place with before and after: tick the ones to rename along with the rest. You need permission to write unfiltered HTML.
- **Delete selector** and **Delete unused** remove only the entries that no document, template or safelist uses.

- A class that only a script, a prop or a widget adds looks unused: **Used by scripts, props or widgets: always keep it** puts it in the safelist (you need permission to change the settings), and **Remove from the safelist** takes it out.

> Renaming and deleting work on the saved styles: with unsaved changes, **Save now** (in the note) or **Save now and rename** (after the preview) save first. They become changes in development, so they go live with the release and can be undone. The manager doesn't see classes added by scripts, props or widgets: check before deleting.

## The single file

The **Single file** section shows the page, or the component, as a single file, modeled on Vue and Svelte files:

- `<template>`: the HTML, the same as in the HTML panel;
- `<style>`: one rule for each selector used in the document that has its own CSS, with media queries and states inside;
- `<script>`: the document's JavaScript (editable only with permission to write unfiltered HTML);
- for components, the props in JSON.

**Apply** brings everything into the document together, after checking the whole file: if there is an error, it tells you the line and changes nothing. **Reload from the document** takes the document back as it is. Changes go to development with the next save.

> Styles apply across the whole site: removing a rule from the file doesn't delete the style, it just stops showing here.

## The CSS that reaches your pages

Histeon sends a page **only the CSS it can use**: a rule is included if the page has an element with the classes and ids it names. This applies to your selectors, to the global sheets and, by default, to the framework's sheet too. Rules on tags, variables in `:root`, fonts and animations are always included. The CSS is written directly into the page, so there are no extra files to download.

In the editor and on the canvas, on the other hand, everything is always included.

The options are in **Histeon → Settings → CSS loading**:

- **Framework on demand** (default): each page gets only the framework rules it uses;
- **Full framework**: the whole sheet on every page, as the framework would load it;
- **Global sheets in a cached file**: the global sheets come whole in a file the browser keeps from page to page, while the selectors' CSS stays in the page. Worth it with large global sheets, such as imported ones. A sheet with relative `url()` addresses, and the static copies of the site, stay as before;
- **Classes and IDs always included** (the safelist): for classes and ids that a script adds after loading and that Histeon therefore doesn't see in the page, such as `.is-open` or `#modal`. You can use the asterisk: `.is-*`.

> If a menu or a window opened by JavaScript loses its styling on the site but not in the builder, a class is almost always missing from the safelist.

## WordPress blocks with the site's style

Inside pages you can also use WordPress's ordinary blocks: paragraphs, buttons, groups, images, quotes. With Histeon CSS their controls offer the site's colours, text sizes, spacing and shadows, and **Styles** shows ready-made variants: solid or outline button, group as a section or a card, rounded image, quote. They follow the site's design: if you change the colours or sizes in the Histeon CSS panel, they change too.
