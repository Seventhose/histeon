# Elements

A Histeon page is made of real HTML elements: sections, headings, paragraphs, images, links. This chapter explains how to add them, organize them, write their text and edit their code and properties.

## Inserting an element

Open the insert menu in one of these ways:

- the **Add** button in the top bar;
- the **+** button in the header of the Structure;
- [[Ctrl+I]], even while typing in a field.

At the top of the menu there is the search (**Search for an element or a component…**) and, below it, the exact place where the new element will go: “Inside …, at the end” if the selected element is a container, otherwise “After …”, or “At the end of the page” if nothing is selected. The **Before**, **Inside** and **After** buttons next to it change the place. Choose with a click, or with the arrows and [[Enter]].

| Group | What it contains |
|---|---|
| **Elements** | Section, Container, Title, Paragraph, Link, Button, Image, List, Condition, Post loop, Slot (in components), Post content |
| **Interactions** | Tabs, Accordion, Mobile menu, Language switcher, Reveal on scroll: accessible out of the box, without writing JavaScript |
| **Media** | **Image from the library…** and **SVG (paste or file)…** |
| **Components** | The site's components |
| **Site PHP blocks** | The blocks that the site's theme or a plugin make available |

Every inserted element is a step you can undo with [[Ctrl+Z]].

## The Structure

The **Structure** panel shows the page as a tree: each row is an element, with its children indented below. A click selects the element (on the canvas too), the arrow next to it expands and collapses its children.

Each row helps you recognize the element without opening it:

- **the label**, if you gave it one, or else the tag and the class;
- **the automatic BEM label**: an element with no label or class, inside a block with a class, shows its BEM name; for example an `h2` inside `.card` shows `card__title`;
- **the section's name**: a section or a box takes the name of its first heading (for example “I giusti”), open or closed; without headings, its `aria-label` or its `id`;
- **the text**, in quotes: a few words of the heading or of the first text it contains. For an image, its alternative text. Hovering over the row shows the full text;
- **what it is**, after the text and smaller: “Paragraph”, “Heading H2”, “Card”…

Formatting inside a text (bold, italics, links, line breaks) is not a row of its own: a paragraph with a bold word and a line break stays one row, with its words. The arrow opens it if you want to reach the bold word.

At the top of the Structure, the **Search the structure** field finds elements by words of their text, tag, class, id (`#chi-siamo`), name or kind (“paragraph”): every word typed must match. Results have a coloured bar on the left and their branches open; [[Enter]] selects the next one, [[Shift+Enter]] the previous one, [[Esc]] clears the field.

Above the canvas, the **path** tells you where you are: for example “Page › I giusti › Body › Il giusto legge…”. A click on a step selects that element; “Page” clears the selection.

**Several elements at once:** [[Ctrl]]+click (or [[⌘]]+click on a Mac), in the Structure or in the canvas, adds an element to the selection or takes it away; [[Shift]]+click selects every sibling between the selected one and the one clicked. The path above the canvas says how many more are selected. **Duplicate**, **Delete**, **Move up/down** and **Wrap** (from the context menu, the palette or their keys) act on all of them, in a single undo step; siblings next to each other go together into the same container. The **Properties** show the first selected element. A plain click goes back to one element.

To move an element, drag its row in the Structure: toward the top or bottom of a row it goes before or after, in the middle it goes inside, where allowed.

## Moving, duplicating, deleting

| Action | How |
|---|---|
| Move up or down among siblings | [[Alt+↑]] / [[Alt+↓]] |
| Move out of the container, or into the element before | [[Alt+←]] / [[Alt+→]] |
| Move anywhere | By dragging the row in the Structure or the tab on the canvas |
| Duplicate | [[Ctrl+D]] |
| Delete | [[Delete]] |
| Select the parent | From the context menu |

The same commands are also in the **Properties** panel, under **In the structure**: **Up**, **Down**, **Duplicate**, **Delete**.

## The context menu

Right-click a row in the Structure or an element in the canvas (or press the menu key, or [[Shift+F10]]). In the canvas the right click also selects the element under the pointer; holding [[Shift]] opens the browser's menu instead. You move through it with the arrows, and [[Esc]] closes it.

| Item | What it does |
|---|---|
| **Edit the component** | On a component: opens its definition in place |
| **Write the text on the page** | On a text: opens it for editing on the canvas |
| **Copy** | Copies the element with the styles of its classes |
| **Paste here** | Pastes inside or after the element |
| **Duplicate** | Like [[Ctrl+D]] |
| **Wrap in a div / a section / a link** | The element becomes the child of a new container |
| **Remove the wrapper** | The element disappears and its children take its place |
| **Create component…** | Turns the element into a reusable component |
| **BEM classes for children** | Gives children without a class a `block__part` name |
| **Rename** | Changes the label in the Structure ([[F2]]) |
| **Copy HTML** | Copies the element's code |
| **Copy style** / **Paste style** | Takes an element's classes and `style` attribute and gives them to another (replacing its own); undone with [[Ctrl+Z]] |
| **Select the parent**, **Move up**, **Move down**, **Delete** | Like the commands of the same name |

Items that can't be used stay visible but disabled; hovering over them tells you why.

## Writing text on the canvas

Double-click a heading, a paragraph, a link or a button: the element opens for editing in place, with a coral border.

- [[Enter]] or a click outside confirms; [[Esc]] puts the text back as it was.
- [[Shift+Enter]] starts a new line.
- [[Ctrl+B]] and [[Ctrl+I]] apply bold and italic.
- [[Ctrl+S]] confirms and saves.

Pasted text always arrives as plain text. Only elements that contain **only text**, even formatted, can be edited here. Text with expressions (for example `{this.title}`), an element with other elements inside or an element repeated by a loop are edited in the HTML panel. If you write something in the canvas that can't stay there, the text isn't lost: it moves to the HTML panel, as you wrote it, to be finished there.

## The HTML panel

The **HTML** panel shows the code of the selected element; the **Whole page** button shows the entire page. What you write appears on the canvas right away.

- Inside a `class="…"` attribute, the panel suggests the framework's classes.
- If the code has an error, the panel points it out and the canvas shows the last valid version until you fix it. Wrong code counts as "not saved": [[Ctrl+S]] says what it didn't save, closing the tab makes the browser warn you, and before you select another element or undo, Histeon asks whether to drop it. The same goes for a global sheet rule with unbalanced braces.
- [[Ctrl]]+click (⌘+click on a Mac) or [[F12]] on a class or an id opens the CSS panel on that selector.

> If an element contains content written outside Histeon that the code can't represent faithfully, the panel becomes read-only. Fix it from the Structure or the Properties.

### Abbreviations (Emmet style)

Type an abbreviation and press [[Tab]]: it becomes HTML, with the cursor in the first empty element.

| Type | Get |
|---|---|
| `section.hero>h2.hero__title{Hello}+p` | a section with a heading and a paragraph |
| `.card#top` | `<div id="top" class="card"></div>` |
| `a[href="/x" target=_blank]{Go}` | a link with its attributes |
| `ul>.item$*3` | three `li` with the classes `item1`, `item2`, `item3` |
| `div>(h3+p)*2^footer` | repeated groups; `^` climbs up one level |

A single word expands only if it is an HTML tag: [[Tab]] after any other word indents, as usual.

## The HTML panel language

The HTML panel uses normal HTML, with a few stricter rules and some additions for dynamic content.

**HTML rules.**

- Every element is closed explicitly: `<p>Text</p>`, never `<p>` on its own. `<div/>` counts as `<div></div>`.
- HTML tags are written in lowercase. A name with a capital initial is a component: `<Card>`.
- A line break between two elements doesn't become a space. If you need a space between two links on different lines, write `{" "}` or keep them on the same line.
- `<!-- … -->` comments are removed.

**Expressions.** Between curly braces you write a value to display: `{this.title}` is the title of the current page. They work in text and in attributes: `<a href={post.url}>`. To write a literal brace, use `\{` and `\}`. The main values are `this` (the current content), `site`, `user` (the visitor), `url` and `data` (the data sources). A missing value doesn't break the page: it becomes empty. All the available values are described in the [Content and data](#en-contenuti-e-dati) chapter.

You can transform a value with modifiers:

| Example | Result |
|---|---|
| `{post.excerpt.truncateWords(20)}` | The first 20 words |
| `{post.date.date("j F Y")}` | The date in the chosen format |
| `{post.title.upper()}` | The title in uppercase |
| `{post.excerpt ?? "No excerpt"}` | A fallback text if the value is missing |

**Conditions.**

```html
{#if this.image}
  <img src={this.image.url} alt={this.image.alt}>
{:else}
  <p>No image</p>
{/if}
```

**Loops.** They repeat a piece of the page for each result of a query:

```html
{#loop posts(limit: 3) as post}
  <article>
    <h3><a href={post.url}>{post.title}</a></h3>
  </article>
{:else}
  <p>No posts yet.</p>
{/loop}
```

The part after `{:else}` shows when the query finds nothing. There, `post` does not exist.

**Labels.** The `xb:label="Latest posts"` attribute gives the element a name in the Structure. It doesn't reach the page.

## Pasting

Outside text fields and code editors:

- [[Ctrl+C]] copies the selected element **with the styles** of the classes it uses;
- [[Ctrl+V]] pastes it, even into another Histeon site.

When you paste from another site, **Paste from …** opens, showing what happens to each class:

- **new**: it is added with its styles;
- **same as the site's**: the one already there is used;
- **in conflict**: choose whether to **import with a new name** (for example `card-2`) or **use this site's**.

Components the site doesn't have are flagged: they will stay empty.

You can also paste **HTML from other sources**, such as a web page or another builder: it becomes editable elements. Scripts, embedded styles, iframes, forms and `javascript:` addresses are removed first. Classes stay.

## SVG

**Add → SVG (paste or file)…** inserts an icon or a vector illustration as editable elements. Paste the code or choose an `.svg` file from your computer (up to 200 KB). Before insertion the SVG is cleaned of scripts, styles, animations and event attributes, and the window tells you what was removed. For colors that follow the text, use `currentColor`.

**Add → Icon from the library…** opens the roughly 1,700 [Lucide](https://lucide.dev) icons (ISC licence) bundled with Histeon: no CDN, no icon font. Search them by name (`house`, `arrow right`, `mail`); a click inserts the icon as an inline SVG, with the class `icon icon--name`, the text colour (`currentColor`) and `aria-hidden="true"` (if the icon alone says something, give the button or link around it an accessible name).

## Properties and attributes

The **Properties** panel shows the selected element: the **Tag**, the **Content** if it is a text, and the fields needed for that type of element, named in plain words.

| Element | Fields |
|---|---|
| Heading | The level, from h1 to h6, with one click |
| Link | Address (also **Choose a file from the library…**, for a PDF or an image), **Open in a new tab**, tooltip |
| Image | **Choose image…** from the library, alternative text, width, height, loading |
| Button | Type (button, submit, reset), disabled |
| Form field | Type, name, placeholder text, required |
| Video | File, poster, controls, autoplay, muted, loop |
| Embedded content | Address, title, loading |
| Numbered list | Start from, reversed |

Each element also has its **Identity**: the id (useful for `#anchor` links) and the accessible name. Where something important is missing, such as an image's alternative text, the field tells you.

Below, the **Attributes** section lists all the attributes: edit the value, remove one with ✕ or add a new one with a name and value. Emptying a field removes the attribute.

> What can be saved is always decided by the server, based on your permissions: for example, event attributes such as `onclick` are kept only for those who can write unfiltered HTML.

## Quick actions

- **BEM classes** (in the Properties) or **BEM classes for children** (in the context menu): children without a class receive a `block__part` name, where the block is the element's class and the part comes from the child's label or role (`title`, `text`, `image`, `link`…).
- **Rename:** double-click the row in the Structure or [[F2]]. The label is a line of text of at most 60 characters; an empty label goes back to the automatic one.
- **Create component…:** turns the element and its content into a component. The [Components](#en-componenti) chapter explains how.
