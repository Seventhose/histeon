# Static site

A static site is made of plain HTML files: no PHP or database on every visit. It's faster, it copes better with traffic spikes and it offers fewer points of attack. With Histeon you keep working in WordPress as always, and visitors receive pages that are already built.

There are three routes, which you can also combine:

| Route | Where the pages live | When to choose it |
|---|---|---|
| **Static zip** | Wherever you like: you download it and upload it yourself | For an archive, a handover, file-only hosting |
| **Static Bridge** | On the same server as WordPress | The site stays on its hosting, but visitors receive static HTML |
| **Cloudflare** | On Cloudflare Workers, in your account | The public site lives on Cloudflare, with versions and rollback to an earlier version |

## What goes into the static copy

Each page is generated as an **anonymous visitor** sees it: no admin bar, no data about the logged-in user, no changes in development. Included are:

- the home page, public published content (without a password), the archives of public content types and taxonomies;
- the assets the pages use (CSS, scripts, images, fonts), copied directly from the server's disk;
- a `404.html` page.

The site's links become relative, so the copy works on any domain.

### The security check

Before creating a zip or publishing to Cloudflare, Histeon checks all the generated pages. It stops if it finds something that must never leave the site:

- server paths;
- development preview links;
- internal builder annotations;
- WordPress session codes (the "nonces"), which expire and would break the page.

Instead, it flags with a warning, without stopping, links to the WordPress admin or API and the administrator's email.

> Histeon blocks the static copy if a page contains a session code, which can't end up in files that are the same for every visitor. Histeon doesn't make a static copy of a FluentCart store: the store stays dynamic, or it has to be served with Static Bridge, excluding the store pages.

## The static zip

1. Open **Histeon → Releases**, **Static site** section.
2. Press **Download the static site (zip)**.
3. Histeon generates each page, checks it and, if all is well, downloads the zip.

On large sites it can take a few minutes. If the check finds blocking problems, the zip is not created and a message explains why. The zip contains only the pages and their assets: no PHP, no hidden files. No copy stays on the server: the temporary files are deleted after the download.

> Only administrators can download the static site.

## Static Bridge

**Static Bridge** is an open source (GPL) plugin that serves pages as static HTML **from the same server** as WordPress. The admin, the login, requests with parameters and the paths you declare as dynamic keep going to WordPress.

It's included in Histeon as a **module**: you turn it on in **Histeon → Modules**. It requires PHP 8.4. If you already installed it as a separate plugin, Histeon recognizes it and uses that one, with the same data. Once it's on, its pages (**Generate**, **Settings**, **Diagnostics**, **Activity**…) appear at the bottom of the **Histeon** menu.

### First run

1. Turn on the module and open the Static Bridge settings: confirm the domain and check the dynamic paths.
2. Configure the web server following the Static Bridge documentation: with Nginx, manual work is needed.
3. Run the **Diagnostics** and fix the errors.
4. From **Generate**, start the first full generation.
5. Run the Diagnostics again and try a few public pages, the login and the admin.

> Do the first trial run on a test site with a backup, with fallback to WordPress on and "strict" mode off, as the Static Bridge documentation recommends.

### What Histeon does for Static Bridge

On every release, Histeon tells Static Bridge which pages to regenerate:

- a modified **component** updates only the pages that use it;
- **styles** and **settings** update the whole site;
- a modified **post** also updates the pages with a loop, `data.` data or loop filters that might show it.

After publishing in the builder, the message says that Static Bridge will shortly update the static copy of the pages involved. In **Histeon → Releases**, the **Static Bridge** card shows whether the copy is being served, how many static pages there are, whether it updates by itself on every release, the pages in the queue and the latest activity, with **Open Static Bridge**. The same status appears in **Histeon → Overview**, in the **Site status** card.

## Publishing to Cloudflare

Histeon publishes the complete static site (pages and assets) to **Cloudflare Workers** in your account. Every publish is a version: you can go back to an earlier one without regenerating anything.

### Setup

1. In Cloudflare, create an **API token** with only the *Account › Workers Scripts › Edit* permission.
2. In **Histeon → Settings**, **Static site** section → **Publishing to Cloudflare**, enter the **Account ID**, **Worker name** and **API token**.
3. If you like, check **Automatic**: **Publish to Cloudflare on every release, in the background**.
4. Press **Save Cloudflare settings**.

> Even better: ask whoever manages the server to write the token in `wp-config.php` (constant `XB_CLOUDFLARE_API_TOKEN`), so it isn't stored in the database. If you save it in the settings, it is encrypted and never shown again.

### Publishing and rolling back

In the builder, the **Versions** section has a **Cloudflare** part:

- **Publish to Cloudflare now** generates the static site of what is published and uploads it. Only changed files are sent.
- The list shows the latest publishes, with their status (**live**, **blocked** if the security check found problems).
- **Put live again** brings back an earlier version immediately.

With the automatic option, every Histeon release goes to Cloudflare by itself, in the background, without slowing down publishing. Publishing and rolling back require the publish permission.

Cloudflare limits the number of files per version and the size of each file (20,000 files and 25 MB when this manual was written): the current limits, paid plans included, are in Cloudflare's documentation.

## What stays dynamic

A static site can't respond to each visitor differently. Here's what happens to the dynamic parts.

| Feature | Static Bridge | Static zip | Cloudflare |
|---|---|---|---|
| **Loop filters** | The page without filters is static; picking a filter changes the address and the request goes to WordPress | Only the first page of results remains | Only the first page of results remains |
| **Histeon forms** (`/xb-form`) | — | — | They work: the Worker receives them and forwards them to WordPress |
| **Other plugins' forms** | They go to WordPress; Static Bridge can handle Fluent Forms | Not handled by Histeon: check case by case | Not handled by Histeon: check case by case |
| **Cart and checkout** | They stay dynamic in the store | “Add to cart” links lead to the site | “Add to cart” links lead to the site |
| **Logged-in users** | They get WordPress | — | — |

Other things to know:

- Pages that read address parameters (`url.query`) or loop filters depend on the request: with Static Bridge they always go to WordPress when the address has parameters.
- Data that changes on its own (for example an external service read with `data.`) reaches the static copy at the next regeneration.
- Development previews never end up in the static copy.

### Forms on the Cloudflare site

The site published on Cloudflare has only one dynamic address, `/xb-form`. The Worker verifies the **Turnstile** anti-bot check, forwards the fields to WordPress with a signature, and the message appears in **Form messages** in wp-admin, with an email notification to the administrator.

To turn it on, in **Histeon → Settings**, **Static site forms (Turnstile)** section, enter the Turnstile secret key and press **Send to the Worker**: the key goes to Cloudflare and doesn't stay on the site. How to build the form is explained in the chapter on interactions and code.

### Static Bridge and Cloudflare together

The two kinds of publishing don't exclude each other: both react to the same Histeon release. Static Bridge keeps the site fast on its hosting; Cloudflare publishes a self-contained copy, for example as the main site or as a fallback.
