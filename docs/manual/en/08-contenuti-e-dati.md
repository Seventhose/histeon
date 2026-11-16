# Content and data

A Histeon page doesn't contain only hand-written text: it can show the title of the content being viewed, the latest posts, this week's events, the price of a product. This chapter explains where data comes from and how to put it on the page.

## Expressions: placeholders in curly braces

A piece of data is written in curly braces, inside a text or an attribute: `{this.title}` becomes the title of the current content, `{site.name}` the name of the site. In the HTML panel you write them by hand; in the data panels there is always a ready-made expression to copy or to insert into the selected element.

The roots available on every page:

| Root | What it contains | Examples |
|---|---|---|
| `this` | The content being viewed (post, page, product…) | `{this.title}`, `{this.url}`, `{this.excerpt}`, `{this.date}`, `{this.image.url}`, `{this.author.name}`, `{this.categories}` |
| `site` | The site | `{site.name}`, `{site.description}`, `{site.url}`, `{site.year}`, `{site.logo}` |
| `user` | The visitor | `{user.loggedIn}`, `{user.name}` |
| `url` | The page address | `{url.path}`, `{url.query.q}` |
| `data` | Queries and JSON sources read without a loop | `{data.hours[0].day}` |
| `facets` | The loop filters | `{facets.events.total}` |

Inside a loop you also get the variable you chose (usually `item`) and `index`, the position in the loop; inside a component there are also `props` and `slots`.

Only `url.path` travels with Histeon Publish (from Histeon 0.5.4): in Publish it is the path of the page being built, on the site the path of the request, the same on a static site. `url.query`, `user` and `facets` stay on the site: content that uses them is not sent to Histeon Publish (chapter 12).

Some useful rules:

- **No errors on screen.** A name that doesn't exist, an empty field or an error count as "nothing": the page stays up and that spot stays empty.
- **Fallback values.** `{this.excerpt ?? "No excerpt"}` uses the text in quotes when the value is missing; `.default("…")` also kicks in on an empty text or on zero.
- **Comparisons.** `==`, `!=`, `<`, `>`, `&&`, `||`, `!` and the conditional `cond ? "yes" : "no"`. There are no calculations or free function calls: it is a language for showing data, not for programming.
- **Security.** Every value comes out sanitized: a piece of data never becomes markup, so it can't break the page or inject code.

### Modifiers

A modifier transforms the value and is written after a dot: `{this.title.upper()}`.

| Modifier | What it does |
|---|---|
| `inline()` | Prints the text with inline formats only (bold, italics, links): for rich text props |
| `upper()`, `lower()`, `capitalize()`, `trim()`, `stripTags()` | Uppercase, lowercase, capital initial, spaces, tags |
| `truncateWords(20)`, `truncateChars(120)` | Shorten the text and add "…" |
| `date("j F Y")` | Formats a date; without a format it uses the site's |
| `number(2)` | Number with the separators of the site's language |
| `length()`, `first()`, `last()`, `join(", ")`, `slice(0, 3)`, `includes(x)`, `pluck("name")` | Lists and texts |
| `groupBy("date", "Y")`, `groupBy("title", "letter")` | Splits a list into groups (`key` and `items`) by year, by initial or by a field's value, for a heading per group |
| `default(x)` | `x` if the value is empty, false or zero |
| `json()`, `slug()` | JSON text, URL-friendly text |

A list split into groups, with a heading per group:

```html
{#loop data.ultimiArticoli.groupBy("date", "Y") as year}
  <h2>{year.key}</h2>
  {#loop year.items as post}<p><a href={post.url}>{post.title}</a></p>{/loop}
{/loop}
```

The same on a query's result, with a single query: `.groupBy(…)` right after the call, with written texts (the field and, if needed, the mode).

```html
{#loop notizie(limit: 50).groupBy("date", "Y") as year}
  <h2>{year.key}</h2>
  {#loop year.items as post}<p><a href={post.url}>{post.title}</a></p>{/loop}
{/loop}
```

After the query only one `groupBy` is allowed: no other modifiers. In Gutenberg the same is chosen in the loop's panel, under **In groups**; in the builder, in **Data and queries**, under **In groups** in **Use it on the page** (see “Using a query on the page”).

### Without writing them: Insert a piece of data

In the **Properties** panel, under **Content**, the **{ } Insert a piece of data** button composes the expression for you:

1. **Data**: what to show. The list follows the place in the page: inside a loop the fields of its item come first (title, date, author, image…), in a component its props, then the page, the site and the visitor.
2. **Format**: how to show it. A date as “4 November 2026” or “04/11/2026”, a number with decimals, the first words of a text, a list with its separator, an image's address or alternative text.
3. **If empty, show**: the fallback text.
4. **Text before** and **Text after**: the words around it, for example “Published on ”.

Below you see how it is written in code, for example `Published on {this.date.date("j F Y")}`; **Add to the content** puts it at the end of the text. For a component prop in **Expression** mode, **Compose the expression** does the same and reads back the expression already there, when the composer wrote it.

## Conditions

A **Condition** shows part of the page only if an expression is true. You add it from the **Add** menu ([[Ctrl+I]]) → **Condition**, or in the HTML panel:

```html
{#if this.image}
  <img src={this.image.url} alt={this.image.alt}>
{:else if user.loggedIn}
  <p>Add a featured image.</p>
{:else}
  <p>No image.</p>
{/if}
```

In the Structure panel the condition appears as **Condition**, with its branches: **if …** for each test and **else** for the last one. `false`, nothing, `0`, an empty text and an empty list are false; everything else is true.

> There is no "empty" branch for a loop with no results: wrap the loop in a condition, for example `{#if data.events.length()}`.

## Loops and queries

A **loop** repeats a piece of the page for each result: a card for each post, a row for each opening time. The results come from a **query**, that is, a search saved with a name in the builder's **Data and queries** section.

The default queries are always available and don't need to be created:

| Query | What it returns |
|---|---|
| `posts` | Content (posts, pages, custom types) |
| `terms` | Categories and terms |
| `users` | Authors with published content |
| `main` | The query of the page being viewed: an archive, a search |

### Creating a query

1. Open **Data and queries** and press **New query**. Choose the type: **Content**, **Categories and terms**, **Users** or **Of the page**.
2. In the **Visual** view, choose what to show: content type, **Filters** by category, tag or taxonomy (**is one of**, **is not**, **has all**), authors, text, **Order** (by custom field too), **How many**, specific content or content to exclude.
3. On the right you see the data right away: the first 20 records as cards and, for each one, the keys with their value and the ready-made expression (`{item.title}`, `{item.image.url}`, `{item.author.name}`).
4. Save: the query is saved in development and reaches the site with the next release.

The **Code** view shows the same arguments in JSON. Arguments the visual view doesn't know about stay intact and are edited there.

### Ready queries: the gallery

In the **Queries** section, **From the gallery…** adds a query already written with one click, to save and then edit like the others:

- **Latest posts**: the most recent, sticky posts left out;
- **Related posts**: other posts in the page's category, without the page itself (the loop passes `this.id` and the category by itself);
- **Featured products**: the products marked “featured” in WooCommerce (shown only when the shop is there);
- **Upcoming events**: content of the “evento” type whose “data” field is today or later, nearest first; type and field can be changed in the query.

### Using a query on the page

- **Wrap in a loop**: select the element on the page to repeat (a card, a table row) and press the button: the element becomes the template, repeated for each result.
- **Insert a new loop**: adds a ready-made loop, for you to edit.

**In groups**, above the two buttons, splits the query's result into groups: **By year of publication**, **By month of publication** or **By first letter of the title** (for content queries and the page's own query). It stays one query: the loop splits its result. With a grouping chosen, **Wrap in a loop** puts a heading for each group (`{gruppo.key}`, for example “2026”) and, below it, the selected element repeated for each content of the group; **Insert a new loop** does the same with a ready card. On the right the preview says how many groups there are and how many contents each holds. It is the same loop chosen in Gutenberg and written by hand:

```html
{#loop latest_events(limit: 3).groupBy("date", "Y") as gruppo}
  <section>
    <h2>{gruppo.key}</h2>
    {#loop gruppo.items as item}<article><h3><a href={item.url}>{item.title}</a></h3></article>{/loop}
  </section>
{/loop}
```

In the HTML panel a loop is written like this:

```html
{#loop latest_events(limit: 3) as item}
  <article>
    <h3><a href={item.url}>{item.title}</a></h3>
  </article>
{/loop}
```

### Queries with parameters

A query can leave the choice of some values to the loop. In the arguments, `"$limit ?? 10"` means "the loop's `limit` parameter, or else 10". That way the same `latest_events` query serves both the home page (`latest_events(limit: 3)`) and the archive (`latest_events(limit: 12)`). The visual view shows the values the loop can change and lets you edit their default value. `-1` means the maximum allowed, that is 100.

> Loops show only published content, without a password and readable by visitors, even if the query asks for something else. Content types without public pages are included in loops only if an administrator allows them in **Histeon → Settings**, **Non-public content in loops** section.

A result can also be read outside a loop, with the `data` root: `{data.latest_events[0].title}`.

## JSON sources

A **JSON source** contains data you write yourself: opening hours, locations, price list items. You create it in **Data and queries** → **New JSON source** and edit it as a table (rows and columns), as a list of keys or in the **Code** view.

- In a loop: `{#loop data.hours as h}{h.day}: {h.time}{/loop}`.
- Without a loop: `{data.hours[0].day}`.

Sources are versioned like the rest of the site and go live with the next release. A source and a query can't have the same name, and `posts`, `terms`, `main` and `users` are reserved.

### Data from an address

A source can also read the JSON of another service (a calendar, a price list kept elsewhere): choose **From an address**, write the `https://` address, if needed the part to use (for example `items`) and every how many minutes to read it again, then **Test**. It is read like a source written here. For safety the site only calls the hosts an administrator allowed, next to the source; it keeps a copy for the chosen time and, when the service does not answer, shows the last good copy.

## Content types, taxonomies and fields

The builder's **Types and fields** section creates the structure of your content without other plugins:

- **+ Content type**: Events, Locations, People… with singular and plural name, technical name, address, own pages yes or no, parents (like pages), editor features (title, content, featured image, excerpt, author, comments, revisions, order and parent) and who manages it (like posts or like pages).
- **+ Taxonomy**: **Categories (nested)** or **Tags**, with the types it applies to.
- **+ Field**: the fields of a type, of posts, pages, terms or users.

The field types are **Text**, **Long text**, **Number**, **Date**, **Time**, **Yes / no**, **Choice**, **Image**, **File** (a PDF or another file of the library), **Web address**, **Email**, **Linked content** and **Repeater** (rows with the same fields: opening hours, stages, price list items). A row can have a repeater of its own, for example a trip's stages, each with its own times: two levels at most, and no further repeater in the second.

A choice can have **several values (checkboxes)** or show as **radio buttons** instead of the menu; an image can become a **gallery**, and a file or a linked content can be more than one. Several values are read with a loop: `{#loop this.fields.foto as f}<img src={f.url} alt={f.alt}>{/loop}`.

> A new type exists in WordPress only after publishing: save, then **Publish…**. The builder shows the status of each type. After publishing, the technical name can no longer be changed. Only an administrator creates types and fields; other users see the fields and use their expressions.

A type **without its own pages** (testimonials, price list items) has no address and doesn't appear in search or in the public API: it shows only where you put it with a loop.

### Filling in and reading fields

Fields are filled in in the block editor, in the **Fields** panel of the sidebar. For a repeater there is **Add row** and the controls to move or remove rows. Term and user fields appear in the WordPress term screens and in the profile, in the “Profile fields” section.

On pages:

- `{this.fields.price}` in the template of a single item;
- `{item.fields.price}` in a loop;
- `{#loop this.fields.hours as r}{r.day} {r.time}{/loop}` for a repeater;
- `{#loop this.fields.stages as s}{s.place}: {#loop s.times as t}{t.day}{/loop}{/loop}` for a repeater inside another one's rows;
- `{this.categories[0].fields.color}` for a field of a category, `{this.author.fields.role}` for a field of the author.

The **Use them on the page** block lists the expressions for the fields of the selected type, with the button to insert them.

### Field groups

When several types share the same fields (contacts, prices, opening hours), a **field group** writes them once: in **Field groups** give it a name, choose **Only these types** or **All but**, and add the fields. Every chosen type gets them and reads them as its own (`{this.fields.email}`). When a type already has a field with the same name, its own wins; a type created later gets the fields when you save.

### Terms

For a taxonomy, for categories and for tags, the **Terms** view shows the terms in a table: name, slug, parent, description and fields change in the cell, **+ Add** creates a term (also inside another), **Delete** removes it after a confirmation. Changes go live at once.

### The content table

For each type (and for posts and pages) the section has two views: **Definition** and **Content**. The **Content** view is a table with search, a **Status** filter and 20 rows per page. Title and simple fields are edited directly in the cell; images, linked content and rows are edited in the WordPress editor with **Edit ↗**. **+ Add (draft)** creates a new item as a draft.

> Changes to content go straight to the site, as in WordPress: content has no Histeon versions. The pages and templates that show it do.

### Fields from ACF, Secure Custom Fields, JetEngine and Meta Box

If you already use one of these plugins, its field groups appear in **Fields from other plugins**. For each field you see whether pages can read it and a toggle to make it readable (it also works for the whole group). A field from another plugin can contain internal data, which is why it is readable only if an administrator allows it or if the plugin already exposes it in the REST API. Password fields are never readable.

The fields of **options pages** of Secure Custom Fields or ACF (opening hours, contacts, footer texts) appear there too, with their page. Once readable, they are used in any page as `{site.options.dati_sito.orari}`: the options page's name, with `_` instead of dashes, then the field's. They are read only: you change them from the options page in WordPress.

You read them with `this.fields.name` or, to read only from one plugin, with `this.acf.name`, `this.jetengine.name`, `this.metabox.name`. Histeon doesn't run the plugin's formatting: rich text loses its markup and dates become `YYYY-MM-DD`.

## Loop filters

**Loop filters** let visitors choose what to see in a list: search, categories, value of a field, order and pages. They work even without JavaScript and, if it is available, they update the results without reloading the page.

1. Open the **Loop filters** section and create a group with a name, for example `events`.
2. Choose the content (**Content of a type** or one of your queries), the results **Per page** and the **Page parameter**.
3. Add the filters: **Search**, **Taxonomy** (with **multiple choices** and the combination **at least one** or **all**), **Field**, **Sorting**. Each filter has a **Label** and a **Parameter**, the name that appears in the address.
4. **Try it as a visitor**, then **Save the filters**.
5. **Insert into the page** writes the complete markup: the form, the results with their count, a link to reset the filters and the pages. From there it is edited like any other element.

In expressions, filters are read as `facets.events`: `{#loop facets.events.items as item}` for the results, `{facets.events.total}` for the count, `facets.events.pageLinks` for the pages. Each option has its own count, which takes into account the other filters already chosen.

> A field can be filtered only if it is readable by pages and has a simple value. On the static site the filters stay dynamic: see the chapter on the static site.

## E-commerce: WooCommerce and FluentCart

With WooCommerce or FluentCart active, a product is read with `this.product.*` in the product template and with `item.product.*` in a product loop (type `product` for WooCommerce, `fluent-products` for FluentCart).

| Key | What it contains |
|---|---|
| `priceText` | The price formatted by the store (in FluentCart also a range “€10 – €25”) |
| `price`, `regularPrice`, `salePrice`, `onSale` | Prices as numbers, and whether it is on sale |
| `inStock`, `stockStatus` | Availability |
| `sku`, `type`, `shortDescription` | Code, type, summary without markup |
| `addToCartUrl`, `addToCartText` | Link and text of the “add to cart” button |
| `gallery`, `categories` | The product's images and categories |
| `rating`, `reviewCount` | Rating and reviews (WooCommerce only) |

FluentCart adds `minPrice`, `maxPrice`, `variations` (the active variations), `subscription`, `fulfillment`, `brands`, `buyNowUrl`, `cartUrl` and `checkoutUrl`.

> Histeon reads only the catalog: never orders, customers or carts. Hidden products don't appear. Cart and checkout remain dynamic store pages.
