# The builder

The builder is the Histeon workspace: a canvas with the real page, the structure of the elements and the code panels. This chapter gives you the full tour, from opening it to publishing.

## Opening the builder

You can open a page, a post, a component or a template in several ways:

- from the list of pages or posts, with the **Edit with Histeon** action under the title;
- from the admin bar, in the **Histeon → Edit this page with Histeon** menu, even while you are looking at the page on the site;
- from the block editor, with the **Edit with Histeon** button in the top bar;
- from **Histeon → Pages**, **Posts**, **Components** or **Templates**.

From the block editor, the builder opens full screen over Gutenberg. If there are unsaved changes in Gutenberg, Histeon asks you to save them first. The **← Gutenberg** button at the top left closes the builder and takes you back to the editor: if a release has changed the page in the meantime, the editor reloads; otherwise a notice reminds you that there are changes saved in development, which will appear after publishing.

## The interface

The builder is divided into four areas:

| Area | Where | What it contains |
|---|---|---|
| **Top bar** | At the top | Document title, Add, Commands, Undo and Redo, Preview, Save, Publish… |
| **Section column** | On the left | Icons for the site sections, which open over the canvas |
| **Canvas** | In the center | The page as visitors will see it, at the width you choose |
| **Panels** | On the right and at the bottom | **Structure** and **Properties** on the right; **HTML**, **CSS** and **JavaScript** at the bottom |

The bottom panels show the code **of the selected element**, not of the whole page: that way you see and edit only what you are working on.

### The top bar

| Item | What it does |
|---|---|
| Histeon logo | Opens the Histeon Overview in the admin |
| **← Gutenberg** | Goes back to the block editor |
| **Add** | Opens the menu to insert an element ([[Ctrl+I]]) |
| Title | The open document; for a template or a component the type appears too |
| Languages | With Lingua Bridge turned on, the page's languages: a click opens the translation in the builder |
| Status | Messages such as “Unsaved changes” or the outcome of the last save |
| **Commands** | Opens the command palette ([[Ctrl+K]]) |
| Undo, Redo, History | Go back and forward through your changes |
| **New component** | Creates an empty component and opens it |
| **Preview** | Saves and shows the real page over the builder |
| **Published** | Opens the live page in a new tab |
| **Save** | Saves to development ([[Ctrl+S]]) |
| **Publish…** | Takes the saved changes live (only for those with permission to publish) |

### The site sections

The icons in the left column open the sections over the canvas; **Close ✕** or a second click on the icon takes you back to the canvas.

| Section | What it is for |
|---|---|
| **Content** | The site's pages and posts, to move from one document to another without leaving |
| **Templates** | The templates and template parts of the block theme |
| **Styles and selectors** | All the site's selectors, the global sheets and the named media queries |
| **Data and queries** | Loop queries and data sources |
| **Loop filters** | The filters visitors use to narrow down a list |
| **Types and fields** | Content types, taxonomies and fields |
| **Components** | The site's components |
| **Versions** | Releases, with export, import and restore |
| **Single file** | The page or component as a single file with HTML, CSS and JavaScript |
| **Checks** | Accessibility, SEO and performance of the page |
| **Design system** | The framework variables and the Histeon CSS generator |
| **Comments** | Comments from the review with the client |

When you are working on a component, **Component props** appears too. At the bottom of the column you find the command palette, the button to show or hide the bottom panels and the theme button, which on each click goes from **like the system** (light by day, dark by night, if your computer switches by itself) to **light** and to **dark**. Histeon remembers only the choice you make. If the chosen framework is Core Framework or Automatic.css, a button opens its panel over the canvas: after saving something there, **↻ Apply to the preview** updates variables, classes and preview.

### Arranging the panels

The panels adapt to the way you work:

- drag a panel's header to move it to another area (left, right, bottom) or reorder it;
- **▾** collapses it to just the header, **⤢** expands it to fill its area;
- drag the edges of the areas to change their width and height, and the line between two panels of the same area (for example HTML and CSS) to give one or the other more room; from the keyboard, arrows, [[Home]] and [[End]] on the line;
- the **⋯** menu of each panel offers the same moves from the keyboard, plus **Restore the layout**, which can be undone for a few seconds.

The layout stays saved in this browser, including the expanded panel.

### On tablets and phones

Below 1024 px wide the side columns become drawers over the canvas: the **Side panels** button in the left bar opens and closes them. In the top bar **Save** and **Publish…** always stay visible, the other actions move to the **⋯** menu. Below 768 px the builder warns that a wider screen is needed; you can carry on anyway. In content mode a phone is fine: the canvas takes the whole width and one tap on a text opens it for editing. With a finger or a pen you drag an element of the Structure by pressing its icon, and a panel by pressing its **⠿** grip; the tab of the selected element in the canvas drags as with the mouse.

## The canvas

The canvas shows the page with the site's real styles, but without running its scripts: it is a safe space to work in.

- **Selecting:** a click on an element selects it; the Structure, the Properties and the code panels switch to it. [[Esc]] clears the selection.
- **Moving:** above the selected element a tab with its name appears (for example `⠿ Card`). Drag it: a coral line shows whether it will go before or after, a dashed box whether it will go inside. [[Esc]] cancels the drag.
- **Spacing:** on the selected element, handles appear for padding (coral, on the inner sides) and for the top and bottom margin (indigo). When you drag them, the value snaps to the framework's spacing scale, for example `var(--space-m)`; holding [[Alt]] gives whole pixel values. A label shows the value while you drag.

> The spacing handles write to the CSS of the element's first class. If the element has no classes, the canvas writes nothing and the status line asks you to add one.

## Canvas widths

The preview has no fixed devices: the page is drawn at the width you choose, and the site's media queries respond to exactly that width.

- **Handles:** on both sides of the canvas there are two handles to drag. They also work from the keyboard: arrows ±10 px, [[Shift]]+arrows ±100 px, [[Home]] for the minimum width, [[Esc]] or [[End]] to go back to fitting.
- **Px field:** in the bar above the canvas, type a width between 240 and 3840 pixels.
- **Fit:** brings the canvas back to the width of the available space.
- **Site breakpoints:** next to the field, a button for each named media query (for example `tablet 1024`) sets the canvas to that width.

If you choose a width larger than the space, the page is scaled down to fit and the bar says “scaled to …%”. Media queries still see the real width. The chosen width stays in this browser.

### The canvas that follows

When you set the canvas to a width other than “Fit”, the style changes you make with the Mini GUI and the spacing handles automatically go into the media query for that width:

- if one of the site's named media queries applies at that width, that one is used (for example `@media (--mobile)`);
- otherwise Histeon creates a query at the canvas width, for example `@media (width <= 600px)`.

Above the canvas, a pill says where the changes go, for example **Changes in @media (--mobile)**. A click turns it off and it becomes **Changes for all widths**; another click turns it back on. **Fit** goes back to writing for all widths. The chapter on styles explains how to [choose state and width by hand](#en-stili-state-and-width).

## The preview

The **Preview** button saves to development and shows the page over the builder, full screen. It isn't the canvas drawing: it is the real page, rendered by WordPress with its scripts (sliders, menus, filters), in the development version.

- **Width:** **Full**, **Tablet** (820 px) or **Phone** (390 px).
- **Link lasts:** how long the link you send the client works: **1 hour**, **1 day**, **2 days** (if you don't choose) or **7 days**. Next to it, **Expires on …** gives the date and time.
- **Copy link:** copies the link with the chosen duration, to send to the client.
- **New tab:** opens the same preview outside the builder. It is a signed link that expires on its own and doesn't require a login: you can send it to the client.
- **Back to the builder** or [[Esc]]: bring the builder back as it was.

There is no admin bar in the preview, because visitors don't see it. The preview of a template opens on the sample post chosen in the canvas bar (**Preview with**).

## Undo, Redo and History

[[Ctrl+Z]] undoes, [[Ctrl+Shift+Z]] or [[Ctrl+Y]] redo. Next to the two buttons, **History** opens the latest steps (up to 200) described in words, most recent first: “Added div.card”, “Moved …”, “CSS of .card”.

- A click on a step takes the page back to before that step, in one go.
- Undone steps stay grayed out above **Now** until you change something else: a click redoes them.
- [[Esc]] or a click outside closes the window.

> The history lives in the open tab and is lost when you reload the page. Saved versions stay in the **Versions** section and in **Histeon → Versions**.

## Saving and publishing

Histeon keeps work in progress and the public site separate.

**Save** ([[Ctrl+S]]) saves to **development**: the public site doesn't change. You can save as often as you like, look at the preview and send it to the client. While there are unsaved changes, the **Save** button carries a coral dot; notices in the status line (copied, pasted…) go away with the next edit. If you close the tab with unsaved changes, the browser warns you.

**Publish…** takes the saved changes live. The window shows:

1. **What goes live:** each changed document (pages, components, styles, settings), with a checkbox to choose which ones to publish and **Differences** to see what changes compared with the published site.
2. **The checks** of the pages that change. If blocking is turned on in the settings, publishing waits for them to finish without errors.
3. **Compare:** optional, puts the live page and the development one side by side, on a large screen and on a phone, and marks what changes.
4. **Version** and **Notes:** the release number (for example `1.2.0`) and what changes.

A click on **Publish** takes everything live together. If something fails, nothing changes. Every release can be restored from the **Versions** section or from **Histeon → Releases**: all the details are in the [Versions and releases](#en-versioni-e-release) chapter.

> If an editor changed some text in Gutenberg while you were working in the builder, publishing keeps their text and your structure. If you both changed the same text, yours wins and the builder tells you.

## The command palette

[[Ctrl+K]] (or the **Commands** button) opens the palette: type a few letters and choose. You'll find actions (**Save in development**, **Publish…**, **Duplicate the selected element**), insertions (**Insert: Section**), the builder sections, the items to move panels and the page elements to select.

### Search and replace

From the palette, **Search and replace in the page…** changes a word or a phrase across the whole page. Choose where: in the **Texts** (never inside tags or `{…}` expressions), in the **Classes** (only a class with exactly that name: `card` does not touch `card__title`) and in the **Link addresses**; **Match case** tells capitals apart. Before replacing you see how many replacements there will be and a list of how it was and how it will be. All of them are undone with a single [[Ctrl+Z]].

### Shortcuts

| Command | Keys |
|---|---|
| Open commands | [[Ctrl+K]] |
| Save in development | [[Ctrl+S]] |
| Add an element | [[Ctrl+I]] |
| Undo | [[Ctrl+Z]] |
| Redo | [[Ctrl+Shift+Z]], [[Ctrl+Y]] |
| Duplicate the selected element | [[Ctrl+D]] |
| Delete the selected element | [[Delete]] |
| Move up / down | [[Alt+↑]] / [[Alt+↓]] |

On a Mac use ⌘ instead of Ctrl. [[Esc]] closes only the open popover (History, More, AI connector) and puts the focus back on its button; if there is none, it closes the open section, then clears the selection. After [[Delete]] the next element stays selected (or the previous one, or the container), so you can delete several elements in a row.

Each command in the palette can have up to three key combinations: choose **Keyboard shortcuts…** in the palette, select a command and press the new combination. Combinations reserved by the browser (copy, paste, new tab…) can't be assigned. Your shortcuts apply only to you, in every browser, and each one can be restored, or all of them at once with **Restore all**.

## Histeon inside Gutenberg

Even without opening the builder, a selected Histeon element in the WordPress block editor has its own panels in the sidebar:

- **Histeon · Appearance.** Spacing, text, colours and borders, layout and sizes, as buttons with the site's design values: people who don't write CSS change how it looks with a click. A second click on an active button removes it. **When** picks a state (on hover, with focus…) and **On which screens** one of the site's widths: the button writes only there. Some settings appear when they apply, for example columns only for a grid.
- **Histeon · Classes.** The element's classes, to add or remove. Appearance is changed on the class: an element without classes asks for a name first.

Under the page, **Histeon · Code** shows the element's HTML, CSS and JavaScript. It's the same CSS the Appearance buttons write: after a click, the declaration it touched is selected in the code, and a note says what changed ("padding of `.card`: from … to …"). So you can always see what a button changes, and where.

Style changes show in the page right away and are saved with **Save to development**: they go live with the next release, like those made in the builder.

### Components in Gutenberg

A component shows as it does on the site. When it's selected, the sidebar shows its content (the props): texts, images from the media library, links with suggestions from the site's pages, short choices as buttons. **Default value** puts a content back to the component's own.

If the component has **areas** (slots), a box appears under it for each one, with its name: inside, you add and edit blocks as in a page, while the rest of the component stays as it is. **Add content to “…”** creates a missing area.

People with the **Histeon client** role see all this read-only, together with the code: they understand what would change and where, and changes are made by whoever manages the site.

### The section library in Gutenberg

In the editor's **⋮** menu, **Histeon · Library** opens the section library in the sidebar. **Insert** puts the section after the selected block, with its styles. If one of its classes already exists on the site with different styles, the section brings it under a new name (`card` becomes `card-2`, with its parts), so the rest of the site doesn't change; a notice says which.

### Lists and conditions in Gutenberg

Selecting a repeated list (a loop), **Histeon · Repeat** says in one sentence what it shows and lets you pick the query, how many items and how many to skip. Selecting a branch of a condition, **Histeon · Condition** writes it in words and composes it with three fields: what to check, the condition and the value. Below is how it's written in code, for those who prefer to write it by hand.

### Locking a page's structure

In the page settings, in Gutenberg, **Histeon · Structure → Structure locked** leaves whoever doesn't build with Histeon (an editor, for example) only the texts and pictures: no blocks added, removed or moved. The site checks it on save too. Builders can still change everything.

### Quick commands in Gutenberg

**Ctrl+K** (⌘K on a Mac) opens WordPress's command palette, which also holds Histeon's: type "Histeon" to open the page in the builder, see its versions, go to releases, insert a section from the library or save the styles to development.

### The five-step guide

The first time you open the builder, a short guide shows the five places a page is made in: the page itself, the structure, the style, the code, saving and publishing. Move with **Next** or the arrow keys, close it with **Skip the guide** or Esc, and it doesn't come back on its own. To see it again: command palette (Ctrl+K) → **Five-step guide**.
