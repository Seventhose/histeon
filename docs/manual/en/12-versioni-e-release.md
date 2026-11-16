# Versions and releases

In Histeon you work on a copy of the site that only you can see, and you put it live when it's ready, all at once. If something goes wrong, you go back with one click. This chapter explains how it works, from saving to publishing, all the way to moving an entire site from one server to another.

## Development and live

Every page, template, component, stylesheet and setting in Histeon exists in two versions:

| Version | Who sees it | How it changes |
|---|---|---|
| **In development** | You, the builder canvas and anyone with a preview link | Every time you save in the builder |
| **Live** | Site visitors | Only when you publish a release |

**Save** ([[Ctrl+S]]) writes to development: the public site doesn't change. You can save as often as you like, close the builder, come back tomorrow. **Published**, in the top bar, opens the page as visitors see it right now.

> Posts, the content of your own types and media have no Histeon versions: as in WordPress, their changes go live immediately. Versions cover the structure of pages, templates, components, styles, JavaScript and settings (queries, sources, filters, content types).

### Text written by the client

While you work in development, an editor can correct a text on the same page in the block editor. That change goes live immediately. Histeon doesn't lose it: when you load the document in the builder, and again when you publish, it merges the texts.

- If you only changed the structure, the client's text stays.
- If you both changed the same text, the builder's version wins and the publish window flags it as a conflict.

## Publishing a release

A **release** puts everything you chose live in one go. It has a version number (1.0.0, 1.0.1, 1.1.0…), notes and an author, and it is a complete snapshot of the site: that's why you can always go back to an earlier release.

### From the builder

1. Press **Publish…** in the top bar.
2. Under **What goes live** you find every document saved in development and not yet published. Uncheck the ones you don't want to publish now.
3. Optional: **Differences** next to a document shows the lines that changed compared with the published site.
4. Optional: the **Checks before publishing** and the **Visual comparison** (see below).
5. Check the proposed **Version** (the next one, automatically) and write in the **Notes** what changes.
6. Press **Publish**.

The approval of a reviewer, if there is one, also appears at the top of the window (see the chapter on interactions and code). Publishing is all or nothing: if one document can't be applied, none goes live.

### From Histeon → Releases in wp-admin

The **Histeon → Releases** screen shows the same thing outside the builder:

- **To publish**, with the list of documents and **the next release will be …**. The version number can be left empty (**Leave empty for the next one automatically.**); the **Notes** are optional. Then **Publish the selected documents**.
- **Release history**, with past releases, the one that is **live**, the **restore** entries, and the **Restore** and **Export** buttons.
- The **Static site**, **Import a release** and **Site move** sections, described further on.

The **Releases** menu item shows a number when there are documents waiting.

> To publish you need Histeon's publish permission. Administrators have it; you can give it to other roles with a role editor. Anyone who builds without that permission saves in development, and someone else publishes.

### Scheduling a release

Instead of publishing right away you can choose a day and a time: the release goes out by itself, but **only if nothing changed** since you scheduled it.

1. In the builder press **Publish…** and choose the documents, the version and the notes as usual.
2. Under **When** choose **Schedule for…** and enter the day and time. The time is always the site's (the time zone is shown next to the field, for example *site time, Europe/Rome*), even if you are in another country. At least 5 minutes ahead, at most 60 days.
3. Press **Schedule**. With **Block publishing if the checks find errors** on, the checks must first end without errors, as for **Publish**.

From then on the **Publish** window and the **Histeon → Releases** screen show the line **Scheduled for … at … by … · version … · N documents**, with **Cancel the schedule**. Whoever scheduled it, or anyone who can publish, can cancel it; if someone else cancels it, whoever scheduled it gets an email. There is **one scheduled release at a time**; **Publish now** is always possible. The chosen version stays reserved: no other release takes it.

**At the chosen time** Histeon publishes with the permissions of whoever scheduled it, and only if:

- that person still exists, can still publish and edit every chosen page;
- every document is still as it was in development (the same save);
- no other release touched those documents, and the version is still free;
- no new contents arrived from Histeon CMS;
- the client did not change, in Gutenberg, a text that the release changes too.

If anything does not match, **nothing goes online**: the release is **skipped**, the reason goes into the Log and whoever scheduled it gets an email. After the time the line becomes, for a few days, **Version … published at …** or **Skipped the release scheduled for …: reason**, until someone presses **Close**.

Good to know:

- **If you save a page of the scheduled release**, that release will no longer go out. The builder says so with a bar above the page.
- **A release by hand** (or a send from Histeon Publish) **on the same documents cancels the scheduled one**: the **Publish** window says so first. A release on other documents leaves it alone.
- **A restore** cancels the scheduled release, after a confirmation that says so.
- **Deactivating Histeon** cancels the scheduled release; reactivating it does not start it again.
- **The cron.** WordPress runs scheduled things only when someone visits the site. If the site has no system cron (or is served as static files, with Static Bridge or on Cloudflare) the release can go out late; **more than 2 hours late it does not go out** and is skipped. The window and the Releases screen say so and show the line to add to the hosting's cron, plus the line `define( 'DISABLE_WP_CRON', true );` for `wp-config.php`.
- **If you change the site's time zone** after scheduling, the moment stays the same: the line shows it in the new time and recalls the time written then.
- An AI agent cannot schedule releases, nor can the REST API with an application password: releases are scheduled only from the builder.
- The same goes for publishing now, restoring a release and putting the static site online or rolling it back: with an application password the REST API refuses. A person does it from the builder or wp-admin; the signed links with Histeon Publish and staging stay as the administrator created them.

### Seeing the page as another visitor

In the builder's **Preview**, **See as** shows the page to someone not logged in, to a subscriber, to a shop customer (the site's roles that do not write). The conditions written with `user` change, for example `{#if user.loggedIn}` or `{#if user.role == "customer"}`. It is a test visitor: no account is used, and what other plugins show by role stays what a visitor without login sees.

### Proposals for the client: A and B

To let a client choose between two versions, in **Histeon → Releases**, section **Proposals for the client**:

1. prepare the first version in the builder and save it in development;
2. type the name (*Proposal A*), choose the page it opens on and press **Save development as a proposal**;
3. change what you need, save, and save the second proposal (*Proposal B*).

Each proposal has its own preview link, valid for 7 days: it shows the live site with that proposal's changes, also to people without an account. **Compare with** opens a link with the two proposals side by side, to send to the client. Once they have decided, **Choose this one** brings the chosen proposal back into development (the other changes in development go back to what is live and stay in the history): check it and publish it like any release. **Delete** turns a proposal's link off.

## Restoring

You put an earlier release back live from **Histeon → Releases** (**Restore** in the history) or in the builder's **Versions** section.

- Restoring **creates a new release**: history is never rewritten, and you can always go back to the release you started from, too.
- Changes in development that haven't been published yet stay where they are.
- If a release is scheduled, restoring cancels it (the confirmation says so).
- In the builder, the option **When restoring, keep the texts written after that version** decides what happens to the texts: if it's on, the structure goes back to what it was then but the texts corrected in the meantime stay; if it's off, the texts from back then return as well.

## A document's history

**Histeon → Versions** lists the documents with their status (**to publish** or **live**). Open one and you see all its versions, newest first: every save in the builder, every change made in the block editor and every release.

- **Compare** shows the differences between that version and the current one in development.
- **Restore** brings that version back into development. It doesn't go live by itself: it will go with the next release.
- The **untrusted** mark indicates a version written by someone without the `unfiltered_html` permission: it is sanitized again on every restore.

From the builder you get there from the **Versions** section with **All versions of this page, with comparison and restore ↗**.

### How much the history weighs, and how to lighten it

In **Histeon → Overview**, the **Version history** box says how many versions are saved, how much space they take in the database and since when. An administrator can choose **Remove versions older than** 6, 12 or 24 months and press **Clean up the history**: after a confirmation, the versions older than that date are gone and can no longer be restored. What is in development and live, the versions they start from and every release always stay, and every release can still be restored. The operation goes into the activity log.

Likewise Histeon forgets by itself, after 30 days, the reports of finished sends from Histeon Publish.

## Checks before publishing

The builder's **Checks** section looks at the page as it is drawn in the canvas and flags the most common problems. The icon shows the number of errors; **Show** selects the element and returns to the canvas.

| Area | Examples of checks |
|---|---|
| **Accessibility** | Image without alternative text, link or button without text, field without a label, iframe without a title, skipped heading levels, insufficient text contrast, link with vague text (“click here”) |
| **SEO** | Page without an H1, or with two H1s |
| **Performance** | Image without width and height, image much larger than it appears |

Each finding is an **Error**, **To review** or a **Tip**. Anything deliberately hidden from screen readers is not flagged.

In the **Publish** window, **Check** runs the development version of every page that changes (6 at most) through the same checks.

There it adds checks that make no sense in the canvas:

| Check | Severity |
|---|---|
| Page without a title (`<title>`) | Error |
| Page without a language (`lang`) | Error |
| WordPress asks search engines not to index the site (**Settings → Reading**, “Discourage search engines”): said only once, and it doesn't block publishing | To review |
| Page without a description | To review |
| Page that asks not to be indexed (`noindex`) | To review |
| Link to a page of the same site that doesn't exist (answer 404 or 410) | To review |
| Page heavier than 1.5 MB | To review |
| Page already online without a canonical address | Tip |

Links are tried from your browser, without cookies, and only towards the same site: at most 200 per release, each for 5 seconds. A link to a page that exists only in development doesn't count as broken, and neither does a slow answer or one other than 404 and 410. Links to other sites aren't tried. The weight counts the HTML, style sheets, fonts and loaded images: scripts and images loaded further down are left out, so the real weight can be higher.

If the checks have finished when you press **Publish**, the release remembers what they found: **Versions** shows “Released with N errors and M warnings”. It is a reminder, not a proof, and it changes nothing about what can be done. A release published without finished checks, from **Histeon → Releases**, from a file or by Histeon CMS, has no such line.

### Blocking publishing on errors

In **Histeon → Settings** you can turn on **Block publishing if the checks find errors**. From then on:

- the checks start by themselves when you open the **Publish** window, and the **Publish** button stays disabled as long as they find errors (warnings don't block);
- **Histeon → Releases** no longer publishes and sends you to the builder.

> The checks are an aid for whoever builds, not a full audit: they don't replace testing with a screen reader.

### Visual comparison

Also in the **Publish** window, **Compare** puts the **Live** page and the **In development** page side by side, on a large screen and on a phone, and marks the differences: in coral what is new or changes, in blue what disappears. **Next difference** jumps from one to the next.

It compares the open page and the pages or templates that change, 6 at most. Pages are shown without their scripts, so whatever a script draws after loading isn't part of the comparison. The comparison is optional and doesn't block publishing.

If you don't want to open the comparison page by page, the **Appearance** line in the page checks says how many pages change on a large screen and how many on a phone. It is worked out only when you press **Calculate**, because it loads every page four more times; **View** opens the visual comparison. It is information, not a warning.

## Exporting and importing a release

You can carry a release over to another Histeon site with an `.xbrelease` file: typically from the local or staging site to the production one.

**Exporting.** In **Histeon → Releases**, **Export** next to a release (or **Export** in the builder's **Versions** section) downloads the file. It contains the pages, components, templates, styles and settings of that release. Pages and posts are matched by slug, components by key, templates by name.

**Importing.**

1. In **Import a release** choose the file and press **Check the file**.
2. Histeon shows what would change, without writing anything yet.
3. **Import into development** writes everything as changes in development. The source site's address becomes the current site's address.
4. Check in preview, then publish a release as usual.

Missing pages and posts are created as drafts. Whatever can't be applied (a framework that isn't installed, an invalid query) ends up in the report.

### File signatures

The release and site-move files you export are **signed** with this site's key, and the preview of an import says who signed them:
- **Signed by this site**, or **by a site you trust**: it imports as usual.
- **Signed by a key this site does not know**: the preview shows the key's fingerprint (16 groups of 4 characters). Compare it with the one the source site shows in **Histeon → Settings → Export signing**, for example on the phone with whoever sent it. If it matches, tick **I trust this key**: from then on that site's files import without asking.
- **Not signed** (an older file, or one from a site without the keys of `wp-config.php`): it imports only after ticking **Import even without a signature**, if you know where it comes from. In Settings, **Require a signature** always refuses them.
- **The signature does not match**, or the key is **revoked**: the file is refused.

In **Settings → Export signing** you find this site's fingerprint, **Generate a new key** (sites that trust the old one move to the new one by themselves) and the list of sites you trust, each with **Revoke**.

> Exporting and importing releases is reserved for administrators. Imported content has the trust level of whoever imports it: without `unfiltered_html`, the markup is sanitized and the JavaScript isn't imported.


## From staging to production

If you work on a staging site, that is a test copy, you can send what you published to production, images included, without files to download and upload.

1. **On the production site**, in **Histeon → Releases → Receive from a staging site**, choose whether the staging site may also publish and press **Create the pairing code**. Copy the code: it is shown only once.
2. **On the staging site**, in **Histeon → Releases → Send to production**, paste the code and press **Link**.
3. Choose **What the latest release changed** or **The whole site**, then press **Preview on production**: you see what would change there, without anything being written.
4. Press **Send to production**. If the code doesn't allow publishing, everything arrives in development on production and is published from there with a release.

> The code contains the link's secret: don't send it by plain email. Every request is signed and works only once. On production you can **revoke the link** at any time, and the **Log** of both sites records every sending.

## Site move

A release carries only Histeon documents. The **Site move**, in **Histeon → Releases**, carries **the whole site** in an `.xbsite` file: pages, posts, components, templates, menus, styles, settings, the content of your own types and the media they use.

Not included: users, comments, other plugins' options, revisions and version history.

**Exporting.** **Export the whole site** downloads the file with what is live; changes in development are not included.

**Importing.**

1. Upload the **Site file (.xbsite)** and press **Check the file**: you see what would be created, updated or skipped.
2. Choose:
   - **Import into development**: media uploaded, documents as changes in development, new content as drafts. Nothing goes live until you publish;
   - **Import and publish**: puts everything live, with the status each piece of content had on the source site.
3. **Cancel** deletes the uploaded file. A pending file stays available for 30 minutes.

Importing the same file twice doesn't duplicate anything: media and content are found again. This is useful when the file contains new content types: the first import creates them, the second imports their content.

> Only administrators can move a site. Very large files depend on the server's upload limit. Content deleted on the source site is not deleted on the destination site.

## The Histeon CMS package as a file

The **Histeon CMS package** is the one Histeon Publish uses: pages, **posts** with categories and tags, components, templates with their **role** (home, single post, archives, 404, search…), styles, contents and media, all in one `.xbsite` file. It is not the site move: it holds the development copies and can be sent back with the changes.

In **Histeon → Releases → Histeon CMS → The package as a file**:

- **Download the package** saves it to your computer, to open in Histeon Publish even without a link;
- **Package to import** + **Check** verifies a package exported by Histeon Publish and shows what it would create or update, without writing anything. Then **Import into development** (it goes live with the next release) or **Import and publish**. **Cancel** deletes the file; a pending file stays available for 30 minutes.

A template with a role becomes WordPress's template for that case (for example `single` for posts): if the theme already has a Site Editor customization with that name, the import stops and does not overwrite it. Only posts made with Histeon travel; those written with the block editor stay out.

> Only administrators download and upload the package. A package of another project is refused: the site stays tied to a single Histeon CMS project.

### What stops a send from Histeon Publish

Before it receives a send the site reads its own content back. Content that cannot travel (for example an image from another site or an expression with `url.query`) stays out and is named, but if Histeon Publish already knows it the send stops with its name: left out, it would look deleted. Fix it on the site, then try again.

- `url.path` travels (Histeon 0.5.4): a menu that marks the active item with `{#if url.path == "/festival/"}` is fine. Histeon Publish does not send a project that uses it to an older site, and says so.
- A library image written with the other scheme (`http://` on a site moved to `https`) is the same image and travels.
- Every template has its page content block, archive templates too: on an archive it stays empty, on the site and in Histeon Publish. The builder warns when a template lacks it (chapter 9).

### The home page after the first send

On a new site WordPress opens `/` on the list of posts, and the project's home is at `/home/`. After the first send with publishing, Histeon Publish asks whether the site should open on the project's home and, if the project has a “blog” page, list the posts there. Only a yes changes the site's **Settings → Reading** (home page and posts page), live at once; it needs a link with the publish permission. The Log records it as `cms_front_page`. The addresses of posts do not change. It can always be changed again in Settings → Reading.

### Converting an HTML page: the report

In **Histeon → Releases**, **Convert an HTML page** turns a page, a site's ZIP or its folder into a Histeon project. The conversion's report is split by what happened:

- **Removed**: what does not go into a Histeon site (scripts, iframes, event handlers, files too large, styles that cannot be carried over);
- **Moved into the page's JavaScript**: event handlers and `<dialog>` windows that work again through the page's JavaScript;
- **Media queries and breakpoints**: the widths of the site's media queries, how often, and whether they match a named media query (`--mobile`, `--tablet`, `--desktop`) or which is nearest;
- **To check**: the rest, for example images without alternative text or forms without an address to send to.

Histeon Publish shows the same report when it converts a site.

A **Histeon prototype in a single file** (the HTML with `data-histos-prototype="1"`, as an AI agent writes it) is not converted: it is imported as it is, with templates, components, contents and loops, into the site's Histeon project. Images written as `data:` come with the file; those given by an `https` address are downloaded by the browser when the site hosting them allows it, otherwise the import stops and says which one to upload to WordPress.

### Importing a page of this site

In the same panel, **Import a page of this site** copies into Histeon a page the site already has, made with another tool (for example with WordPress blocks). A new Histeon page is created, in development; the original page stays as it is.

What to expect:

- the **look** is copied: the structure is left to refine in the builder, with the original tool's classes;
- **only the page's content comes in**: the theme's header, menu, footer and admin bar stay out, with their scripts (below);
- **parts and components are not created**: repeated cards stay inside the page;
- **fonts and files of other sites do not come in** (for example a font service): the site's font is shown;
- Histeon CSS fonts and sizes stay the site's; the colours are your choice (below).

How to do it:

1. press **Import a page of this site**: the list of the published pages you can edit appears, with a box to search them. Pages already made with Histeon and password-protected pages are not there, and the panel says how many;
2. choose the page and, in **Colours of the page**, which colours you want (below); then press **Import this page**. The browser downloads it as a visitor sees it, without your cookies, with the style sheets, images and fonts **of this site**: never from other sites, never from the administration or the API. Nothing of the page is run. The site's own Histeon CSS and the admin bar stay out;
3. the new page takes the first free address after the original one (`/about-2/`, then `-3`…);
4. the report says what comes in, what the site already had the same, what it already had **different** (the site's styles win, the page's do not come in) and what stays out;
5. **Go on to the check** and confirm: as for any package, nothing is written before.

One page is imported at a time, and more can be imported later: the styles the site already has do not change. If someone changes the site's styles between the reading and the confirmation, the import stops with a conflict: convert again.

**The page's styles apply only to that page.** Its rules, even those written on `body` (background, text colour), do not touch the other pages of the site: its main elements carry a mark (`data-histos-pagina="about-2"`) and every rule of the page applies only where the mark is. An animation or a font the site already had with the same name comes in with a new name, and the report says so. Limits to know:

- a **new** element you add in the builder at the top level of the page has no mark and does not take the page's styles: put it inside a section that is already there;
- the site's header and footer, on that page, no longer take the page's rules;
- of the original page only the **content** comes in: its `<main>` (which becomes a `<div>`, because the site has its own), with a block theme without `<main>` what stands between the theme's header and footer, with a classic theme `#main`, `#content` or `.site-main`. The theme's header, menu, footer, sidebar and admin bar stay out, with their scripts and sheets; the report says where the content was taken from. If the content is not recognised, the page comes in whole and a warning says so: remove the copied header and footer in the builder.

**The page's colours.** The conversion writes the page's two brand colours as Histeon CSS variables, chosen only among the page's own sheets and styles: those of the theme, of WordPress (the `--wp--preset--*` palette) and of the plugins do not count. In the site a variable has the site's value. Before importing, choose:

- **The page keeps its colours** (checked on opening): the site does not change and the page has the original's colours;
- **The page takes the site's colours**: the site does not change, and the page's brand colours become the site's;
- **The site's colours become the page's**: **this changes the look of the whole site, in its colours only**. It is offered only when the site uses Histeon CSS (Histeon's framework with the Histeon CSS module on); otherwise a line says why it is missing. Before going on, the list of the variables that change appears, "variable: before → after", for the light and dark themes, with a sample of each colour: press **Yes, change the site's colours** to go on, or **Cancel** to go back to the choice. Fonts, sizes and the rest of the design do not change; the pages change in development, and online with the release.

The page's other colours are values written in the page and stay its own with every choice. The report says which of the page's colours became variables and what was kept.

The site must already have a Histeon project: if it has none, the first page is converted with **Convert** and becomes the project's home.

## Overview and site status

**Histeon → Overview** is the page to start from. It shows:

- whether **everything is live** or what is **To publish**, with **Review and publish**;
- the live release and since when, the number of pages, posts and components;
- **Recently modified**, with **Open in Histeon**;
- **Latest releases**. The first publish creates version 1.0.0.

The **Site status** card gathers what deserves a look, with a green dot (fine) or a coral one (needs a look) and the link to fix it: the status of the modules (on, off, active as a separate plugin, stopped after an error), the static copy (static pages and pages to regenerate), the recommended imports. When there's nothing to report it says **all good**.
