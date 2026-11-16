# Permissions and security

Different people work on a site: whoever builds, whoever publishes, whoever only writes the texts. Histeon uses WordPress roles and permissions to decide who can do what, and adds a few permissions of its own. This chapter explains them, and describes in plain words what Histeon does to keep the site safe.

## Histeon permissions

Histeon adds four permissions (in WordPress they're called *capabilities*):

| Permission | What it allows |
|---|---|
| `xb_build` | Using the builder: editing pages, styles, components and predefined queries, and saving in development |
| `xb_publish` | Publishing releases and restoring earlier ones: that is, changing the live site |
| `xb_manage_deploy` | Managing the static site destinations (Cloudflare) and their credentials |
| `xb_edit_content` | **Client mode**: changes texts and images of the pages one may edit, in the builder, in development. It is the permission of the **Histeon client** role. |
| `xb_edit_props` | In client mode, also changes the simple props of the components on those pages (text, number, yes/no, link, image, a choice from a list), never expressions or code. **Off by default**: no role receives it, not even Histeon client; you grant it, with a role editor or with `wp cap add xb_client xb_edit_props`. |

When Histeon is activated, **administrators** receive all of them. Histeon also creates the **Histeon client** role: it can edit (not publish or delete) pages and posts, upload images and use the builder only in client mode. It changes pages made with Histeon only from the builder, never from the block editor. Other roles have no Histeon permissions until you give them.

## Who can do what

Besides the Histeon permissions, the normal WordPress ones count too:

| To… | You need |
|---|---|
| Open a page in the builder | `xb_build` and permission to edit that page |
| Edit templates and template parts | Also permission to edit the theme (`edit_theme_options`) |
| Publish with **Publish…** | `xb_publish` |
| Upload images and files | The WordPress permission to upload files |
| Write JavaScript and free-form code | `unfiltered_html` (below) |
| Settings, Modules, local fonts | Being an administrator (with `xb_build`) |
| Export and import releases, move the site | Being an administrator |
| Configure Cloudflare | Being an administrator, with `xb_manage_deploy` |

Without `xb_publish` you can build and save in development, but not put anything live: the **Releases** screen tells you so (“Publishing requires the xb_publish permission: ask whoever manages the site.”). It's a simple way to let a collaborator or an agency work while you keep the last word.

Anyone who only edits texts (for example a client) can keep using the WordPress block editor, which shows Histeon pages as they appear on the site.

## What each client may do

By default a client (the **Histeon client** role) changes texts and images of every page they may edit. In **Histeon → Clients** (or from the **Histeon permissions** link in the list of users) whoever builds or administers the site chooses, client by client:

- **what they may change**: texts and images (always), the **component props** (like `xb_edit_props`; if the role already gives it, the box stays ticked) and the **look of the page**, with the groups of the Aspetto panel they may use (Layout, Spacing, Size, Text, Colours and borders, Position);
- **on which pages**: every page they may edit, or only a list. In the list each page has a level: **None** (they do not open it, nor edit it in WordPress), **View only, in the builder**, **Texts and pictures**, **Also the look**. The pages you do not choose stay without access.

**The look of the page applies there only.** If the client changes the colour of a heading on the «Contacts» page, it changes on that page only: the same class on the other pages stays as it is. They do it from the **Histeon · Aspetto** panel in Gutenberg or from the **Look of this page** section in the builder, with the buttons of the groups you gave them only; they save in development and it goes live with the next release, like texts. You too, in Gutenberg, can write a style for the page only with the **This page only** switch.

> No client gets new permissions with the update: until you choose something, what applied before still applies. The client does not see this screen and cannot change their own permissions or anyone else's: the server refuses every save beyond what you gave them.

## Giving permissions to other roles

Histeon has no roles screen of its own: it uses WordPress's. To give `xb_build` or `xb_publish` to a role (for example Editor), use any role and capability management plugin, look for the permissions starting with `xb_` and assign them.

An example of how to split the work:

| Person | WordPress role | Histeon permissions |
|---|---|---|
| Whoever manages the site | Administrator | All |
| External developer | Editor | `xb_build` |
| Content manager | Editor | `xb_build`, `xb_publish` |
| Client who changes the texts | Author or Editor | None: uses the block editor |

> Give everyone only what they need. This also applies to AI agents: they work with the permissions of the user they connect as, and a user without `xb_publish` can't publish anything. See [Histeon and artificial intelligence](#en-intelligenza-artificiale).

## Who can write code

In WordPress, the `unfiltered_html` permission decides who can insert free-form code: scripts, event handlers, any tag. On a single site, administrators and editors usually have it; authors and contributors don't. Histeon respects it everywhere.

| Who | Level in Histeon | What they can write |
|---|---|---|
| Has `unfiltered_html` | **trusted** | Any element and attribute, the JavaScript of pages and components |
| Doesn't have it | **restricted** | Content elements, static SVGs, styles; no scripts, no `onclick` and the like, no `javascript:` links |

What you see in practice:

- the JavaScript panel is **read-only** for anyone without `unfiltered_html`, with a message explaining why;
- if a “restricted” person edits a page containing code written by a “trusted” person, that code stays **intact and locked**: they can neither change it nor duplicate it;
- conversely, content written by a restricted person **stays restricted** even when an administrator saves, publishes or restores the page that contains it. Code never becomes “trusted” just because it passes through the right hands.

The rules apply on every route: the builder, the block editor, release and Etch site imports, AI agents.

> You don't write PHP in the builder, by design: it would be code run on the server within reach of anyone who edits content. The site's PHP, in the theme's or a plugin's files, is used in the builder as data and dynamic blocks.

## What Histeon does for security

Here are the main protections, in plain words.

**The builder is protected.**

- The builder page has a strict content security policy (CSP): only Histeon code runs, and the page can't be embedded in other sites.
- The preview in the canvas is isolated: the page's scripts don't run, links don't navigate and forms aren't submitted.
- The canvas loads images, video, fonts and stylesheets only from the site (its media CDN included) and from the origins written in **Histeon → Settings → External origins allowed in the canvas**: so the address of whoever is working never reaches third-party sites. An image from another origin shows as a grey rectangle with the origin in its tooltip; on the published page it stays as it is. Fonts of an external stylesheet (Google Fonts, for example) do not reach the canvas either: better to download them to the site (Design system → Site fonts).
- The builder's requests to the site are checked against the user's permissions and WordPress's security codes.

**Nothing goes live by mistake.**

- Everything you save goes **to development**. The site only changes with a release, and every release can be restored.
- Every saved version has a fingerprint (SHA-256) that is checked again when it's read. A tampered release is not restored.
- Histeon keeps an **operations log** of important events: publishes and restores, imports, exports, configuration, changes made by AI agents. Administrators read it in **Histeon → Log**, filtered by operation, person and days, and export it as CSV.

**Previews and the static site don't let anything slip out.**

- Preview links are signed, they expire, they're valid only for that page and that user, and they aren't indexed. They can all be revoked at once.
- Before publishing the static copy to Cloudflare or downloading it as a zip, Histeon checks it: if it finds private or session data (server paths, security codes, preview tokens), it stops.

**Credentials and personal data.**

- The Cloudflare token goes in `wp-config.php` or is saved encrypted, and it's never shown or returned.
- Form messages are visible only to administrators, are kept for 180 days and can be exported or erased with the WordPress privacy tools.
- Fonts can be hosted on the site, so pages don't ask Google's servers for the fonts.

**Signed updates, no license.**

- No license key and no telemetry.
- Updates are off until you turn them on, and they're installed only if signed with the Histeon key. See [Modules and updates](#en-moduli-e-aggiornamenti).

**No external calls you didn't ask for.** Histeon connects to external services only for the features you turn on:

| Feature | Where to | When |
|---|---|---|
| Updates | GitHub | Only if you turn them on, at most every 12 hours |
| Local fonts | Google Fonts | Only when you import a font, once |
| Static site | Cloudflare | Only if you configure publishing |
| Machine translation (Lingua Bridge) | The service you choose | Only with your own key, on request |
| YouTube or Vimeo video | YouTube or Vimeo | When you insert a video: title and preview image, once |
| JSON source **From an address** | The address you write | Only if you create a source of this kind |
| Sending from staging to production | The paired production site | Only when you send |
| Names suggested by a model in **Convert an HTML page** | Anthropic, or a compatible server you choose | Only if you set your own key or server |

**AI agents on a leash.** The connector accepts only a closed list of operations, listens only on your computer and can't publish. WordPress abilities work with the user's permissions, and an agent doesn't publish on its own: the request to publish leads to the Releases page, where a person publishes.

**On uninstall**, Histeon removes its tables, options, secrets and permissions. The pages' content stays.

## Hardened installation

Every WordPress plugin, Histeon's modules included, runs in the same PHP process with the same rights on the database and the files: a weak spot in any of them concerns the whole site. So an effective way to reduce the risk is not to split plugins apart, but to **take WordPress out of the visitors' way**. When the site allows it, this setup is the one to choose.

1. **Static public site.** Publish the static copy to Cloudflare or serve it with Static Bridge (see [The static site](#en-sito-statico)). Visitors get only HTML and files: they run no PHP and never touch the database. Only loop filters and forms stay dynamic, if you use them.
2. **Private administration.** Make `wp-admin` and `wp-login.php` reachable only by the people who work on the site: a server rule for trusted IP addresses, a VPN, or an access service such as Cloudflare Access in front of WordPress's address. With publishing to Cloudflare, WordPress can live at an address the public doesn't know.
3. **Only the modules you use.** Turn off in **Histeon → Modules** the ones you don't need: a module that is off registers no pages, routes or tasks. To be sure even if someone flips the switch back, write it in `wp-config.php`:

```php
define( 'XB_DISABLE_MODULES', 'lingua-bridge,static-bridge' );
```

4. **Credentials out of the database.** Put the Cloudflare token in `wp-config.php` (`XB_CLOUDFLARE_API_TOKEN`) rather than in the settings.
5. **No file editing from wp-admin.** With `define( 'DISALLOW_FILE_EDIT', true );` in `wp-config.php` nobody can edit theme and plugin code from the administration.
6. **Few users with strong permissions.** Give `unfiltered_html` and `xb_publish` only to those who need them (see [Who can write code](#en-permessi-e-sicurezza-who-can-write-code)).
7. **Signed updates.** Turn them on and let Histeon install only signed versions (see [Modules and updates](#en-moduli-e-aggiornamenti)).

> You need neither Docker nor a dedicated server: all of this works on ordinary hosting too, with the static site on Cloudflare. Self-hosters can go one step further and keep WordPress on an internal network.
