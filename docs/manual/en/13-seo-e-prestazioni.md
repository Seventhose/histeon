# SEO and performance

A beautiful site also has to be found and load quickly. Histeon works with the SEO plugins you already know and sends visitors only what the page really uses: CSS, scripts, images at the right size.

## SEO with your favorite plugin

Histeon doesn't replace your SEO plugin: titles, descriptions, social data and sitemaps stay with **Yoast SEO**, **Rank Math**, **Slim SEO** or **The SEO Framework**. Histeon makes sure these plugins see pages the way a visitor sees them.

### Why it's needed

Histeon saves text in a structured way, inside blocks. An SEO plugin reading the "raw" content of a Histeon page would find zero words, no headings and no images. That's why Histeon hands it the page **already rendered**, with real text, headings and images.

### What you get

| Where | What happens |
|---|---|
| **Yoast SEO and Rank Math analysis** in the block editor | The plugin analyzes the page's real text, with headings and images, and updates shortly after every change |
| **Yoast SEO and Rank Math sitemaps** | Images on Histeon pages, including those that come from content, go into the sitemap |
| **Slim SEO** | Reads Histeon pages for the description and title, and adds their images to the sitemap |
| **The SEO Framework** | Uses the page text for generated descriptions and its images for social, after the featured image |
| **WordPress's automatic excerpt** | If a page has no written excerpt, it takes the first words of the page. It is used by variables such as Yoast's `%%excerpt%%`, Rank Math's `%excerpt%` and by feeds |

> What you write by hand always wins: an excerpt or meta description written by the author takes precedence over text taken from the page.

### What SEO plugins see

The page Histeon hands to SEO plugins is the **live** one, rendered as an **anonymous visitor** sees it:

- changes in development don't make it into descriptions and sitemaps until you publish them;
- nothing personal (the logged-in user's name, restricted content) can end up in a public description;
- password-protected pages produce nothing.

The result is cached and rebuilt when the page changes, when you publish a release or when you update Histeon.

> In the block editor, on the other hand, the analysis uses the page with the permissions of whoever is editing it: you see the effect of what you write right away.

### The builder's SEO checks

The builder's **Checks** section also flags the most common SEO problems: a page without an H1 heading, or with two H1s. Components and template parts don't need an H1. See the chapter on versions and releases.

> Development preview links explicitly ask search engines not to index them.

## CSS on demand

Many sites send every page hundreds of KB of CSS, almost all of it useless for that page. Histeon reads the final HTML of each page and includes only the rules for the classes and IDs that are really there.

- **Histeon styles** reach the page only if an element uses them.
- **The CSS framework** (Histeon CSS, Core Framework, Automatic.css): Histeon can do the same with the framework's rules too. You choose this in **Histeon → Settings**, **CSS loading** section:
  - **Framework on demand** (recommended): each page receives only the framework rules it uses. Variables and general rules always stay.
  - **Full framework**: the whole stylesheet on every page, as the framework loads it.
- When in doubt, a rule is kept: one extra byte is better than a broken page.

The CSS arrives inside the page itself, with no separate files to download. With large global sheets, **Global sheets in a cached file** in the same section is worth it: those sheets come in a file the browser reuses from page to page.

### Classes added by scripts

Histeon only sees the classes present in the page's HTML. If a script adds a class after loading (a menu that opens, a dialog that appears, results loaded later), its rules might be missing.

In that case, write those classes in **Classes and IDs always included** (**Histeon → Settings**, **CSS loading** section), separated by spaces. A trailing `*` works as a prefix: `is-*` includes all classes that start with `is-`.

> If the plugin of a framework other than the one you chose is also active, that plugin may load its own stylesheet on every page, outside Histeon's on-demand loading: the settings point this out.

## Scripts only where needed

Histeon doesn't load scripts "just in case" on every page:

- the **interactions** script (tabs, mobile menu, reveal on scroll) weighs a few KB and arrives only on pages that use one;
- the **loop filters** script arrives only on pages that use filters;
- the **page JavaScript** and the components' JavaScript arrive only where needed, once, after the content.

Without JavaScript, pages remain usable: interactions are shown open and filters work by reloading the page.

## Images that load well

Images are almost always the heaviest part of a page. Histeon helps in three places.

- **On upload.** The media manager resizes images and converts them to AVIF or WebP in the browser, before sending them (see the chapter on media and fonts).
- **In the page.** An image picked from the library gets `srcset`, `sizes`, `width` and `height`. An image that comes from content (`<img src={item.image.url}>`) gets the same attributes at visit time, without touching the ones you wrote yourself.
- **No jumps.** `width` and `height` make the browser reserve the image's space before it arrives: the page doesn't shift while it loads.

If an image has `loading="lazy"`, Histeon adds `auto` to `sizes`, so the browser picks the file based on the image's real space. `loading`, `decoding` and `fetchpriority` are handled by WordPress.

The builder's **Checks** flag images without width and height and those much larger than they appear.

## Fonts without external requests

**Local fonts** live on your site: no requests to Google or other servers when someone visits the pages. It's faster (no extra connection) and more respectful of privacy. Text shows up right away in a system font and switches to the chosen font as soon as it has arrived. See the chapter on media and fonts.

## Checklist for a fast page

1. Leave **Framework on demand** on.
2. Upload images with **Web** or **Light** compression and insert them from the media manager.
3. Use local fonts instead of remote ones.
4. Prefer Histeon interactions over heavy external libraries.
5. Before publishing, look at the **Checks** section and fix the errors.
6. If the site allows it, serve it as a static site (see the chapter on the static site).
