# Templates

With a block theme, WordPress builds every page of the site starting from a **template**: one for posts, one for pages, one for archives, one for the 404 page and so on. **Template parts** are the pieces shared by several templates, such as the header and the footer. In Histeon, templates and parts are edited in the builder exactly like a page.

## What you need

- **A block theme.** Histeon works on the templates of the active theme; with a classic theme the **Templates** item doesn't appear.
- **The right permissions.** You need access to the builder and permission to change the site's appearance (usually administrators).

> The recommended theme is **Fondamenta**, distributed with Histeon (see below). Another block theme works too: its templates open in the builder and the WordPress blocks they contain stay where they are.

## Where to find them

There are three ways to open a template.

| Where | How |
|---|---|
| **Histeon → Templates** in wp-admin | The list of the active theme's templates and parts, divided into groups |
| The builder's **Templates** section | The same list inside the builder, with group filters and the **Search for a template…** search |
| Admin bar on the site | While you are looking at a page, **Edit template with Histeon: …** opens the template that page uses (if it has already been customized) |

The groups follow WordPress's logic: **General** (index, home, front page, 404, search), **Pages**, **Posts**, **Archives**, a group for each custom content type (for example "Events", with the single item and the archive), **Categories, tags and taxonomies**, custom templates and, for parts, **Headers**, **Footer** and the other parts. Each item has a line explaining where it applies.

### Theme templates and customized templates

Each item carries a label:

- **Theme file**: the template exists only as a theme file. In wp-admin the button is **Edit with Histeon**.
- **Custom**: the template has already been copied into the database. The button is **Open in Histeon**.

The first time you open a "theme file" template, Histeon makes a copy of it in the database and opens that copy, as the WordPress Site Editor does when you customize a template. It happens only once; the theme file is not touched.

## Creating a template

In **Histeon → Templates** open **New template**. From the builder's Templates section the same panel opens with **+ New template**, in a new tab.

1. Choose the **type**: **Full template** or **Template part**. For a part, also choose the **area**: general, header or footer.
2. Type the **technical name**. The field suggests the WordPress template hierarchy names the theme doesn't have yet: for example `single-event` for every single event, `archive-event` for the list of events, `category`, `search`, `404`.
3. Type a **title**, if you like, and choose where to begin in **Start from**: empty, or a copy of an existing template.
4. Press **Create and open in Histeon**: the new template opens in the builder.

> A hierarchy name applies by itself where expected. Any other name creates a **custom template**: you assign it to pages and posts from their editor, in the template panel.

## Editing a template

A template is built like a page: elements, classes, components, loops and conditions (see the chapter on content and data). Two differences matter.

**The data comes from the page being visited.** In a template, `this` is the content the visitor is looking at: `{this.title}` in the post template becomes the title of each post. In an archive you use the page's query:

```html
{#loop main() as post}
  <article>
    <h2><a href={post.url}>{post.title}</a></h2>
    {#if post.excerpt}<p>{post.excerpt}</p>{/if}
  </article>
{/loop}
```

**The pages of an archive.** WordPress splits an archive into pages (how many items per page is set in Settings → Reading). In an archive template `props.page` tells you where you are and already gives the right addresses: `props.page.page` is the page shown, `props.page.totalPages` how many there are, `props.page.totalRecords` how many items the archive has, `props.page.prevUrl` and `props.page.nextUrl` the addresses of the previous and next page (empty when there is none), `props.page.pageLinks` one entry per page with `number`, `url` and `current`:

```html
<nav aria-label="Pagination">
  {#if props.page.prevUrl}<a href={props.page.prevUrl}>Previous</a>{/if}
  {#loop props.page.pageLinks as l}
    {#if l.current}<span aria-current="page">{l.number}</span>{:else}<a href={l.url}>{l.number}</a>{/if}
  {/loop}
  {#if props.page.nextUrl}<a href={props.page.nextUrl}>Next</a>{/if}
</nav>
```

The addresses follow the site's permalinks (`/books/page/2/`, or `?paged=2` with plain permalinks) and work on the archives of types and terms, the posts page and the author and date archives.

**The content written in the editor has its own place.** In post and page templates, the **Post content** element (**Add** menu, or `{@content}` in the HTML panel) marks where the text that editors write in the block editor goes.

**Every template has its Post content, list templates too.** When a template lacks it, the builder says so in a strip above the canvas while you edit it: with Histeon Publish a template without this block does not travel, and reading and sending the site stop on it. The **Add the post content** button adds it (where you selected, or at the end): move it where the text goes and save. On a list template (archives, search, 404) it stays empty and does not show, on the site as in Histeon Publish. The warning blocks nothing: you can save and publish anyway. Template parts do not need it.

In the builder's top bar, next to the name, **Template** or **Template part** appears, so you always know what you are editing.

### Template parts

Header and footer are documents of their own: they open from the same list and are edited once for all the pages that use them. In the Fondamenta templates they are pulled in with WordPress's "template part" block, which the builder keeps as it is.

## Preview with sample content

A template has no page of its own: to show it, the canvas and the preview use sample content.

| Template | Sample content |
|---|---|
| Single post, single item of a type (`single`, `single-event`…) | A published item of that type |
| Page, front page, template parts | A page |
| Archives, index, home, search, 404 | A list: the latest posts |

Above the canvas, the **Preview with** menu chooses the sample content among the latest 20 published items you can read. For lists, the note **list of the latest posts** appears instead. Changing the sample is useful to see how the template behaves with a long title, without an image or with many categories.

The **Preview** button in the top bar also opens the real page on the chosen sample content.

> A template's preview link opens any page of the site with the changes in development, because a template applies to many addresses. Share it only with people who need to see the not-yet-published version.

## Saving and publishing

Templates and parts work like any Histeon document:

- **Save** ([[Ctrl+S]]) saves them to development: the public site doesn't change.
- **Publish…** takes them live with a release, together with the other changes.
- You restore an earlier version from the document's history.

If someone edits a template outside Histeon, for example in the WordPress Site Editor, the change still goes into the document's history, as with pages. For releases, restores and history, see the chapter on versions and releases.

## The Fondamenta theme

**Fondamenta** is the base block theme designed for Histeon. With Histeon active it adds nothing of its own: everything you see is drawn by Histeon.

- **No presets**: no WordPress palettes, gradients, font sizes or patterns getting in the way of your CSS framework.
- **No remote patterns** downloaded from the WordPress library.
- **Four templates** already written with Histeon elements: **index** (the list of posts), **singular** (posts and single items), **page** and **404**. All of them have the "Post content" block, index and 404 too, where it stays empty: so every template travels with Histeon Publish.
- **Two parts**: **header**, with the site name and the navigation menu, and **footer**.

It is the recommended starting point: open the **Templates** section, customize the header and footer, then the template for single items and the one for lists.

> Need an extra template, for example the one for a content type of your own? Create it with **New template** (see above).

The name doesn't contain "Histeon" because the theme stays afterwards: it is the base on which a site will one day be able to leave Histeon for blocks that work on their own. **Without Histeon** Fondamenta is "stopped": the pages show nothing, and in wp-admin a notice says which plugin is missing, how many contents are left without a page and which content types are not registered. For now the fix is to activate Histeon again.

### From XB Blank to Fondamenta

XB Blank stays as a compatibility theme, with the same templates as Fondamenta: a site that uses it keeps looking as before. On the **Themes** and **Dashboard** screens a notice suggests the switch.

1. Make a backup of the site.
2. Upload `fondamenta-<version>-<commit>.zip` from the same Histeon release via **Appearance → Themes → Add New Theme → Upload Theme**.
3. Activate Fondamenta (from the notice or from the Themes screen).

When the theme changes, Histeon carries to Fondamenta the templates, parts, global styles and additional CSS you customized with XB Blank: they stay the same documents, with their history. If Fondamenta already has a template with the same name, Fondamenta's stays and the **Log** says so. Activating XB Blank again brings everything back.

### The exit report

If one day a site has to leave Histeon, its pages will become Fondamenta blocks for the structure and WordPress blocks for the text. To know beforehand how much would come out of it, an administrator can read a report from WP-CLI:

```
wp xb export-core --user=<administrator>
```

It changes nothing. For every page, template and component it says what would become an editable block, what would stay as it is today in an HTML block (for example an SVG or a list of categories), what would be lost (for example a Histeon form or a condition on the visitor) and why. At the end, the share of elements that become real blocks. With `--file=report.json` it saves everything to a file, with `--doc=<id>` it reads a single document.

### Fondamenta's blocks

Since Fondamenta 0.2 the theme has the blocks pages will be converted into. You also find them in the block editor's inserter:

- **Element** (with the variations Section, Container, Image, Link, Button): an HTML element with its tag and classes, holding other blocks, a text or nothing. The markup stays the one you built, so your CSS keeps working. An element can show values of the content: the title, the date, a field, the link of a post;
- **Loop**: repeats its blocks for each post of a query, each term, each value of a field or each page of an archive; **If the loop is empty** says what to show when there are no items;
- **If present**: shows its blocks only if a value is there, or if a comparison holds (for example the menu item of the section you are in);
- **Post content**.

To see how a converted page would look, without touching it:

```
wp xb export-core --user=<administrator> --compare
```

For every page, template and part it draws the page twice, with Histeon and with the converted blocks, and says whether the HTML is the same. Components become WordPress synced patterns when they can: the definition stays one, and each use keeps its own texts and links as its own values. A component with slots, or with props in a class, a condition or a loop, is instead copied into every page that uses it. Loop filters (search, topics, sorting, pages) move to Fondamenta too: their definitions go into the site theme and pages filter as before, even with Histeon switched off. Content fields, repeaters included, move to the field plugin you choose (Secure Custom Fields, ACF, Meta Box, Pods, JetEngine or Open Fields): `--theme-zip=theme.zip --campi=scf` puts the field groups into the site theme, and pages show them as before. The static site's forms (those posting to `/xb-form`) become forms of the plugin you choose too (Fluent Forms, Contact Form 7, Forminator, JetFormBuilder, Ninja Forms or SureForms), with the messages already received: `--forms --form=fluentforms` shows what they would become.

When the site is ready, the exit is made from **Histeon → Settings → Exit to Gutenberg**: choose the field plugin and the form plugin, look at the preview, download and install the site theme, then «Write the exit» (with a full copy of the site made first). Pages are converted in place, with a backup: «Restore» puts them back as they were. After checking the pages, «Finish the exit» activates the site theme and turns Histeon off, without deleting it. From then on do not publish Histeon releases any more. With `--convert --doc=<id>` the command shows the converted blocks of one document, without writing into the contents: «Write the exit» is what writes them.

### The site theme

On the day of the exit, a theme made for your site goes on top of Fondamenta: its stylesheet, its templates and parts already converted, the scripts of its pages. It is generated with:

```
wp xb export-core --user=<administrator> --theme-zip=theme.zip
```

It changes nothing on the site: it only writes the ZIP file, to upload by hand in **Appearance → Themes → Add New Theme → Upload Theme** when the time comes. The theme is named after your site and, once installed, it is yours: you edit it in the Site Editor or in its files. If Histeon is ever switched off, Fondamenta still registers your content types with the same addresses and keeps the interactions (tabs, menus, reveal) working.
