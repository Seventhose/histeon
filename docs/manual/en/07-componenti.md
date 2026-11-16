# Components

A component is a piece of a page you build once and use wherever you like: an event card, a header, a button with an icon, a contact block. It has its own **props** (the values that change from one use to another) and its own **slots** (the places where other content goes). When you edit it, it changes everywhere it is used.

## How it works

Every component has:

- a **name**, the one you read in the menus, for example “Event card”;
- a stable **key**, lowercase with hyphens, for example `event-card` or `ui/card-hero`. The key doesn't change when you move the site, export it or import it elsewhere;
- a **definition**: the markup, the styles of its classes, possibly some JavaScript, the props and the slots.

In the HTML panel a component is written with the key's name in capitals: `event-card` becomes `<EventCard>`, `ui/card-hero` becomes `<Ui.CardHero>`. The capital initial is what distinguishes a component from an HTML tag.

The site's components are in the builder's **Components** section and in **Histeon → Components**.

## Creating a component

### From scratch

1. In the top bar press **New component** (or **New component…** in the command palette).
2. Enter the **Key** (for example `ui/card-hero`) and the **Name**.
3. Press **Create and open**.

The empty component opens for editing right away, over the document you were working on.

### From an element on the page

A component often starts from something you have already built:

1. Select the element, for example the card you want to reuse.
2. In the **Properties** press **Create component…**, or choose the same item in the Structure's context menu.
3. Enter the **Name**: the **Key** is suggested automatically (“Event card” becomes `event-card`), and you can change it.
4. Press **Create**.

The element and all its content become the component's definition, and an instance of the component stays in its place on the page.

> **Create component…** is disabled when the selected part uses values that exist only there, such as the variable of an outer loop (`post`). A component sees only the general data (`this`, `site`, `user`, `url`, `data`) and its props: values that come from outside must be passed as props. Variables of loops that are inside the selection are fine.

## Props

Props are the values that change from one use to another: the card's title, the image, the link. While you edit a component, the **Component props** icon in the left column opens their panel:

- **Component key**, with an example of how it is written in code;
- the list of props, each with its **type**, an optional **default** value and an optional **sample**; ✕ removes it;
- a **new prop** field with the **Add** button.

| Type | What for |
|---|---|
| `string` | A short text |
| `number` | A number |
| `boolean` | True or false, for example a highlighted variant |
| `url` | An address |
| `image` | An image |
| `rich-text` | A formatted text |
| `enum` | A choice among fixed values |
| `array` | A list, to repeat with a loop |
| `object` | A group of values |

In the component's code, props are read with `{props.name}`:

```html
<article class="card">
  <img src={props.image.url} alt={props.image.alt}>
  <h3 class="card__title">{props.title}</h3>
  {#if props.featured}<span class="card__badge">Featured</span>{/if}
  {@slot}
</article>
```

### Sample content

A component open by itself has no page to give it its props, and it is often made to be filled: without help the canvas would be blank. So, only in the builder's canvas:

- each prop takes its **sample**, if any, else its default, else a value from its type: the prop's name in words for a text (“mainTitle” becomes “Main title”), a placeholder image, the first choice of an `enum`, “true” for a `boolean` (so what the prop shows is visible);
- elements with the `hidden` attribute are shown, with a dashed outline and the label “hidden on the page”;
- an empty box shows a placeholder: “empty”, or “empty: a script fills it” when the component has JavaScript (for example a table of contents a script fills in).

None of this ends up in the saved component or in pages: pages use the default, or the value they give.

### Variants: switches for whoever uses the component

A “dark card” or a “large card” does not need a second component: it is a **variant**. In the component panel, under **Variants**, type the name (for example *Dark*) and press **Add a variant**. Histeon:

- adds a yes/no prop `dark`, off by default;
- gives the first element the class `card--dark` when the prop is on (`class="card {props.dark ? "card--dark" : ""}"`);
- prepares the CSS of `.card--dark` with the variables `.card` declares, to redefine: for example `--background: black;` instead of `--background: white;`.

The variant's CSS is in the **CSS** panel, among the element's pills. In the pages the variant is a switch among the props, in the builder and in the block editor; in the builder it can be turned on right away, before the component is published. The component's first element needs a class: the variant's class is made from it.

## Slots

A slot is the place where whoever uses the component can put whatever content they want: a paragraph, a button, another component.

- In the definition, `{@slot}` marks the main slot. You also find it in the **Add** menu as **Slot (in components)**.
- You can have several named slots: `{@slot media}`, `{@slot actions}`.
- A slot that nobody fills shows nothing. To hide an empty container, check `{#if !slots.actions.empty}…{/if}`.

## Using a component

To insert a component into a page:

- from the **Add** menu, in the **Components** group (you can search for it by name or by key);
- or by writing it in the HTML panel.

In code, props are passed as attributes, and the content between the opening and closing tags goes into the main slot:

```html
<Card title="Summer concert" featured>
  <p>Saturday at 9 pm, free entry.</p>
</Card>
```

To fill a named slot, use `{#slot name}`:

```html
<Card title={post.title}>
  {#slot media}<img src={post.image.url} alt={post.image.alt}>{/slot}
  <p>{post.excerpt}</p>
</Card>
```

A value in quotes is a text (`title="Hello"`), even with expressions inside (`title="Hello {user.name}"`); a value in curly braces is an expression (`title={post.title}`, `count={3}`); a prop written without a value counts as “true” (`featured`). Inside a loop, pass the loop's values to the component: `<Card title={post.title}>`.

If you select an instance, the **Properties** panel shows its **Props**: for each one, choose whether it is a **Text** or an **Expression** and enter the value.

## Editing a component

A component is edited **in place**, without leaving the page:

- double-click the component on the canvas or in the Structure;
- **Edit the component** in the Structure's context menu;
- the **Edit the component** button in the instance's Properties.

While you edit, all the panels (Structure, HTML, CSS, JavaScript, Props) work on the component's definition. At the top the path appears, for example **Home › Component Card**, along with two buttons:

- **Save and exit** saves the component to development, styles included, and goes back to the page: the canvas shows the new version right away and the instance you started from is selected again;
- **Exit without saving** discards the changes (after a confirmation, if there are any) and goes back.

[[Ctrl+S]] saves without exiting. A component inside another component opens over the first one, and the path at the top lets you go back up one or more levels.

While the component is open, the page stays in memory exactly as it was: unsaved changes, selection and undo history. When you exit you find everything again, including [[Ctrl+Z]] on the changes made before.

> Changes to a component apply everywhere the component is used. Before changing its structure, check which pages it appears on.

### A component in use is not deleted

If a component is used by a page, a template or another component, live or only in development, it cannot be moved to the trash or deleted: not from wp-admin, not from the block editor. The message says where it is used, for example *The component “Card” is in use and cannot be deleted. Remove it first from: Home, About us (in development).* Remove it from those pages, save, then delete it.

## Styles and JavaScript

A component's styles are those of its classes, written in the CSS panel as for any other element (see the [Styles](#en-stili) chapter). They apply across the whole site and reach a page only if the page uses the component. For styles that work with any framework, use the Histeon CSS `--xb-*` tokens.

In the **JavaScript** panel you can write a JavaScript module for the component: it goes into the page as `<script type="module">`, after the content, and doesn't run on the canvas. JavaScript can be written only with WordPress's unfiltered HTML permission; for other users the panel is read-only.

## How it is published

A component follows the same rules as pages:

1. **Save and exit** (or [[Ctrl+S]]) saves the component to **development**. The public site doesn't change.
2. The page's **Preview** already shows the new version of the component, so you can check it where it is used.
3. **Publish…** lists the component among the changes to take live, together with pages and styles. With the release, the component changes on all the pages that use it at the same moment.

In the **Publish…** window, a component has no page of its own to compare: the visual comparison shows it through the page open in the builder. If something goes wrong, restoring the previous release also brings back the component.

> A component created from an element exists right away, but no live page uses it until you save and publish the page you created it in.

## The section library

A successful section, such as a hero, a card grid or a footer, can be saved to reuse in other pages or on another site.

1. Select the element on the canvas or in the structure.
2. Open the **Library** section in the left bar, type a name and press **Save to the library**. The styles of its classes are saved with the element.
3. To reuse it press **Insert**: the paste dialog opens, the same as for copy and paste between sites, showing new, identical or conflicting classes.
4. With **Export** you download the whole library as a `.xblibrary` file; on another Histeon site you load it with **Import…**. Sections already there are not duplicated.

> Images stay at the source site's addresses. To really move them use the site move, or upload them to the new site's media library.

### Having the agent rebuild it

**Have the agent rebuild it**, next to a library section, sends the connected AI agent a request: rebuild the section in this page with the project's classes and tokens. The agent does not get the markup to copy but its description: the parts, their role, and the layout with the project token nearest to each size and colour. The request shows in the ✦ panel.
