# Interactions and code

Many pages need something that moves: tabs, FAQs that open, a menu for phones. Histeon offers these interactions ready-made, without writing code. When you need more, you can add JavaScript to the page or use your theme's PHP. This chapter also covers static site forms and page review by clients.

## Interactions without code

In the **Add** menu ([[Ctrl+I]]), **Interactions** group, you find:

| Interaction | What it does | Without JavaScript |
|---|---|---|
| **Tabs** | Buttons that show one panel at a time. The arrows, Home and End move from one tab to another. | All panels visible, one below the other |
| **Accordion** | Questions and answers: one section open at a time. It uses the browser's native element, so keyboard and screen readers work on their own. | Works the same: it uses no script |
| **Mobile menu** | A **Menu** button opens and closes the navigation on narrow screens; [[Esc]] closes it and returns focus to the button. | Menu always open |
| **Reveal on scroll** | The block appears when it comes into view. | Everything visible; with reduced motion turned on, visible right away |
| **Language switcher** | Links to the page's other languages, if the site is multilingual. | Works the same |

Each insertion is normal Histeon HTML, with BEM-style classes (`tabs`, `tabs__tab`, `accordion__item`…) and its base styles. You can change text, structure and appearance as for any other element. Base styles are added only to classes that don't have a style on the site yet, so a second insertion doesn't overwrite what you have already customized.

> Scripts don't run on the builder canvas: tabs and menus are shown open, so every part stays editable. You try out the behavior with **Preview**.

The interactions script weighs a few KB and loads only on pages that use at least one of them. It reads only the interactions' attributes: it never runs anything written in the page.

## Page JavaScript

Every page, template or component can have its own script, in the builder's **JavaScript** panel. The script goes into the page as `<script type="module">` after the content.

- **It doesn't run on the canvas or in the builder's preview.** You see it in action on the published page, or by opening the preview in a **New tab**.
- It is saved and published with the document, and it is versioned like everything else.
- A component's script reaches every page that uses that component.

### Who can write code

JavaScript is the most sensitive content on a site: whoever can write it can do anything in the visitor's browser, administrators included. That is why Histeon follows WordPress's rule:

- **Only those with the `unfiltered_html` permission** can write or edit JavaScript. In a standard installation, administrators have it (and, on multisite, only super admins).
- Others see the panel read-only, with the note **Read-only: only users with the `unfiltered_html` permission can edit the JavaScript.**
- In the same way, the markup of those without that permission is sanitized on save, as WordPress does with authors' content.
- **Trust isn't "recycled".** If an editor without `unfiltered_html` edits a page and then an administrator saves or publishes it, that content is sanitized again: an administrator doesn't make it trusted just by pressing a button.
- **Protected preview.** When the development version contains untrusted content, the preview runs with scripts blocked.

> The same rule applies when you import a release from another site: those without `unfiltered_html` get sanitized markup and no JavaScript.

### Site scripts

A library or file the whole site needs (a map, a booking form, an analytics tool) is added once: in the **JavaScript** panel, open **Site scripts** and press **Add a script**. For each script choose:

- **File address**: a file of this site (for example `/wp-content/uploads/js/map.js`) or an `https` address.
- **Integrity hash**: required for a file of another site (`sha384-…`, usually shown next to the library's link). If that file changes, the browser does not run it.
- **Where**: the whole site, or only the pages of one content type (and its archive).
- **Position** (head or end of the page) and **Loading**: *defer* (after the page, the usual choice), *async*, *module* or *right away* (blocks the page until it arrives: only when really needed).

Site scripts are saved in development and reach the site with the next release, like the settings. The rules of page JavaScript apply: only `unfiltered_html` changes and publishes them, an import or an agent never touches them, and they do not run in the builder.

## PHP: in the theme files, not in the editor

Histeon **never runs PHP written in the builder**, in any form. The reason is simple: a stolen builder account, a release imported from another site or a preview link must not be able to run code on the server. The right permission for writing PHP is the one for editing theme files, which many sites keep turned off, and Histeon doesn't reopen it through another door.

PHP is therefore written in a file of the theme (for example `functions.php`) or of a plugin, where it has its own defenses: it is versioned, reviewed, saved with backups. Pages use it in three safe ways.

| What | In PHP | On the page |
|---|---|---|
| **Data** | The `xb_data_providers` filter registers a function with a name | `{data.weather.today}` or `{#loop data.weather.days as d}` |
| **Modifiers** | The `xb_modifiers` filter registers a transformation | `{item.product.price.euro()}` |
| **Dynamic blocks** | `register_block_type` with a render function | **Add** menu, **Site PHP blocks** group |

Example of a modifier, to put in the theme's `functions.php`:

```php
add_filter( 'xb_modifiers', static fn ( array $m ): array => $m + array(
	'euro' => static fn ( mixed $v ): string => number_format( (float) $v, 2, ',', '.' ) . ' €',
) );
```

The rules that protect the site:

- Data values come out sanitized like any expression: a function can't print HTML through `{data.…}`.
- Content, terms and users returned by a function follow the usual visibility rules: a draft stays hidden from visitors.
- An error in the function leaves the value empty; the page stays up.
- A dynamic block produces the HTML its code decides, with the same trust as a theme template.

In the HTML panel, inside `{…}`, after `data.` the completion suggests the data registered by the site; after another dot, [[Ctrl+Space]] suggests the modifiers.

## Builder JavaScript API

For people who build tools or automations, the builder page exposes `window.xb`: the same operations as the builder's controls, on the same state. It doesn't grant new permissions: every save goes through the same filters and the same versions.

| Method | What it does |
|---|---|
| `getHtml()`, `setHtml(html)` | Reads and replaces the text of the HTML panel |
| `getSelection()`, `select(uid)` | Selection |
| `insert(html)`, `remove(uid)` | Adds inside or after the selection, removes an element |
| `getCss(selector)`, `setCss(selector, css)` | CSS of a style entry |
| `render()` | The page's HTML and CSS as the server renders them |
| `undo()`, `redo()`, `save()`, `isDirty()` | Like the builder's commands |
| `on(event, fn)` | Listens for an event: `change`, `select` or `save` |

```js
xb.on('select', ({ uid }) => console.log(xb.getElementHtml(uid)));
xb.insert('<p class="note">Added by a tool</p>');
await xb.save();
```


## Forms with Fluent Forms

Histeon doesn't build forms: for contacts, sign-ups and quotes it uses **Fluent Forms**, when it is active on the site, right from the builder.

1. In the **Add** menu ([[Ctrl+I]]) you find the **Forms (Fluent Forms)** group with the site's forms: pick one and it appears in the page, drawn by Fluent Forms as on the site.
2. Select the form and, in the **Properties** panel, press **Edit the form**: the Fluent Forms editor opens inside the builder. There you change fields, messages and notifications.
3. **Close and refresh the preview** takes you back to the page, with the updated form.
4. **New Fluent Forms form…**, in the same menu group, creates a form without leaving the builder.

> Submissions, notifications and permissions stay Fluent Forms': the group appears only to those who can manage forms in Fluent Forms. On a static site, Fluent Forms forms work with Static Bridge.

## Static site forms

A static site has no server to receive forms. Histeon solves this for sites published on Cloudflare: the form sends the data to the site's Worker, which checks it and forwards it to WordPress.

How to build a form:

1. The form submits with `method="post"` to the `/xb-form` address.
2. It contains the **Cloudflare Turnstile** widget (the anti-bot check), with your site key.
3. Two optional hidden fields: `xb_form`, the name of the form (for example `contact`), and `xb_redirect`, the page of the site to take the sender to (for example `/thank-you/`).

```html
<form method="post" action="/xb-form">
  <input type="hidden" name="xb_form" value="contact">
  <input type="hidden" name="xb_redirect" value="/thank-you/">
  <label>Name <input name="name" required></label>
  <label>Email <input name="email" type="email" required></label>
  <label>Message <textarea name="message"></textarea></label>
  <div class="cf-turnstile" data-sitekey="YOUR-SITE-KEY"></div>
  <button>Send</button>
</form>
```

The Turnstile widget needs Cloudflare's script. If your site doesn't load it already, you can add it from the page's **JavaScript** panel:

```js
const s = document.createElement('script');
s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
s.async = true;
document.head.append(s);
```

What happens on each submission:

- the Worker verifies Turnstile, keeps at most 50 fields and redirects only to pages of the same site;
- WordPress accepts only recent submissions signed by the Worker, and rejects duplicates;
- the message appears in wp-admin in **Form messages**, visible only to administrators, and an email is sent to the site administrator's address;
- messages are personal data: they are deleted after 180 days and are covered by WordPress's privacy tools (export and erasure).

The Turnstile secret key is entered in **Histeon → Settings → Static site**, **Static site forms (Turnstile)** section, with **Send to the Worker**: it is sent to Cloudflare and doesn't stay on the site.

> The `/xb-form` path exists only on the static site published on Cloudflare. On the dynamic WordPress site, use a forms plugin. See also the chapter on the static site.

## Review and reviewer comments

A client or a colleague can comment on a page before it goes live, without a WordPress account.

1. In the builder press **Preview**, then **New tab**: the address that opens is a signed, expiring preview link.
2. Send that link to whoever needs to review.
3. Whoever opens the link sees the **Review** bar at the bottom left:
   - **Comment**: hovering over the page highlights the elements (**Pick an element… (Esc)**); a click opens the box, where you write the comment and your name, then **Send**;
   - **Approve**: asks for a name and records the approval of the version being viewed.

In the builder, the **Comments** section gathers the document's comments, split into **To do** and **Resolved**, with author and date. **Show the element** selects the commented element; then **Resolved**, **Reopen** or **Delete**. The section's icon shows how many comments are to do.

The approval appears at the top of the Comments and in the **Publish** window. It remembers the version that was seen: if you changed the page after the approval, the builder says so and it's worth asking for a new one.

> The preview link is the reviewer's credential: whoever has it can comment on that document as long as the link is valid. If the content in development was written by someone without `unfiltered_html`, the preview runs with scripts blocked and the review bar doesn't appear.

Comments accept only plain text (at most 2000 characters) and have limits against repeated submissions. Reviewers' names are personal data and are deleted together with the comment.
