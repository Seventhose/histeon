# Troubleshooting and FAQ

The situations that come up most often, with the cause and what to do. If a problem isn't here, look at the chapter on that topic: the answer is often in the notes.

## The builder and publishing

### The builder doesn't open, or I don't see “Edit with Histeon”

The message “You don't have permission to edit this content with Histeon.” appears, or the link is missing from the list of pages.

To open a page in the builder you need **two** things: Histeon's `xb_build` permission and WordPress's permission to edit **that** page. By default only administrators have `xb_build`. For templates you also need permission to edit the theme.

What to do: ask whoever administers the site to give you `xb_build` (see [Permissions and security](#en-permessi-e-sicurezza)). The builder opens on pages, posts, components and templates: not on other content types.

### I saved, but nothing changes on the site

That's the normal behavior. **Save** (or [[Ctrl+S]]) puts the changes **in development**: you see them in the builder and in **Preview**, but site visitors still see the live version. To put them live, use **Publish…** in the top bar.

If the problem persists after publishing, check:

- **the page in WordPress:** if it's still a draft, visitors don't see it;
- **translated components:** a component's translation is used only when it's published (see [Multilingual sites](#en-multilingua));
- **the static copy:** if the site is served as a static copy, see further on;
- **the browser or site cache:** try reloading the page with a forced refresh.

### I don't see the “Publish…” button

The button appears only for those with the `xb_publish` permission. Without it, you can build and save in development, and someone else publishes: the **Releases** screen reminds you of this.

If the button is there but disabled, there are no changes to publish. If the **Publish** button stays grayed out in the publish window, the page checks found errors: fix them, then try again.

### The HTML panel is read-only

This happens when an element contains content written outside Histeon (for example in the block editor or by an import) that Histeon code can't represent faithfully. It's a protection: editing it could save something different from what you see. The message in the panel explains this. Fix the element from the structure or from the other panels.

### The JavaScript panel is read-only

Only users with the WordPress `unfiltered_html` permission can write JavaScript. This is by design: see [Who can write code](#en-permessi-e-sicurezza-who-can-write-code).

### I published the wrong version

Open **Histeon → Releases**, find the right release in the **Release history** and press **Restore**. The restored release goes live again as a new release, and the changes you have in development stay where they are. See [Versions and releases](#en-versioni-e-release).

## The static site

### The static copy is out of date

The static copy is a snapshot of the site: it changes only when it's regenerated (see [Static site](#en-sito-statico)).

- **With Static Bridge:** in **Histeon → Releases**, **Static site** section, check the status. If it says “updates itself on every Histeon release”, after a publish Static Bridge updates the pages involved within a short time (you see them as “pages queued for regeneration”). If it says “automatic updating turned off: regenerate from Static Bridge”, regenerate by hand from **Open Static Bridge**.
- **With Cloudflare:** if automatic publishing is on, every release schedules a deploy in the background, which starts shortly after with WordPress's scheduled tasks. Otherwise, in the builder open the **Versions** section and press **Publish to Cloudflare now**.
- **Translated strings:** a Lingua Bridge string applies to all the pages of a language. After changing one, regenerate the complete static copy.

### The deploy or the zip stops with a private content error

Before publishing to Cloudflare or creating the zip, Histeon checks every page. If it finds data that must not leave the site (server paths, security codes, preview links), it stops. The cause is usually a plugin that prints session data into pages even for anonymous visitors. Find the page indicated and fix the cause: the check can't be skipped.

### Static Bridge won't turn on

Static Bridge requires **PHP 8.4**. With an older PHP, the **Modules** screen shows “Requires PHP 8.4”. If PHP is fine, on the other hand, the reasons for the refusal appear on the same screen (memory, file functions, WordPress version).

## Modules and updates

### A module turned itself off

The notice “Histeon turned off a module after a fatal error” appears. Histeon stopped the module because it was causing an error, and the rest of the site works. Update Histeon (or fix the cause indicated in the notice), then turn the module back on in **Histeon → Modules**. See [When a module fails](#en-moduli-e-aggiornamenti-when-a-module-fails).

### The admin no longer opens

If you suspect a module, someone with access to the site's files can add this line to `wp-config.php`:

```php
define( 'XB_SAFE_MODE', true );
```

No module will be loaded. When the problem is solved, remove the line.

### I can't turn on Lingua Bridge (or Static Bridge)

If the message says “It is active as a separate plugin”, the same software is already active as a plugin: that's fine as it is, or deactivate the plugin and turn on the module. The data is the same.

If the screen says “Turned off by wp-config.php”, someone turned it off with `XB_SAFE_MODE` or `XB_DISABLE_MODULES`: that line has to be removed.

### Histeon doesn't find updates

Below the update settings, “No signed release found (or GitHub unreachable)” appears. Check that the repository is spelled correctly and that it's **public**: a private repository doesn't respond. Histeon tries again by itself every 12 hours.

### An update was rejected

Histeon installs only packages signed with the configured public key. If the signature doesn't match, the update stops and the downloaded file is deleted. Check that the key in the settings is Histeon's (you'll find it in [Modules and updates](#en-moduli-e-aggiornamenti-why-releases-are-signed)); if it is, do **not** force the installation by hand.

## Imports

### Importing a release or a site is rejected

The message says, for example, “Invalid release file: content altered or incomplete (hash)”, or “Invalid site file: …”.

Every file exported by Histeon contains a fingerprint of its content. If the file was changed after the export, even by a comma, or the download was interrupted, the fingerprint doesn't match and Histeon rejects the whole file. Export it again from the source site and don't open or edit it before importing.

Other reasons for rejection: the file exceeds the size limits, or it exceeds the server's upload limit (“File not received (it may exceed the server's upload limit).”).

### Content is missing after the site move

If the source site has content types created in Histeon, on the first pass their content is skipped with a warning: WordPress recognizes them only after the settings are published. Publish the release, then import **the same file again**: the second import doesn't duplicate anything.

### A translated XLIFF file won't import

The original changed after the export. Export again: the translation tool will find the sentences already translated in its memory.

### I imported an Etch site and some JavaScript is missing

Histeon imports JavaScript only from content written by people who had permission to write code (`unfiltered_html`). The import report shows this in the **Trust** column. If the script is really needed, someone with that permission rewrites it in the builder. See [Importing an Etch site](#en-importazione-etch).

### I turned off Etch: will the site change?

No. With Etch turned off, Histeon renders the content saved with Etch by itself and the site stays as it was. Importing is still recommended, because it makes everything editable in the builder.

### I turned off (or removed) Automatic.css

As long as the stylesheets Automatic.css generated stay in the site's uploads folder, they stay loaded until you import: classes and variables keep working. A notice reminds you that importing into Histeon CSS is recommended. **Don't delete those stylesheets** before importing: without them the pages would lose their styles.

## Languages and AI agents

### The language switcher doesn't appear

It appears only when the page has at least **two** published versions. Translations in draft don't count.

### The translation made by the AI isn't live

This is by design: agents, machine translation and XLIFF create **drafts only**, never published pages. Review the draft and publish it yourself.

### The AI connector won't connect

Check, in order:

1. that the computer has **Node 18** or later;
2. that the builder is open over **HTTPS** or on `localhost`;
3. that the connector was started with the command copied **after** the last regeneration of the secret: if you regenerated the secret, copy the instructions again and restart the connector;
4. that you pressed **Connect this tab** in the ✦ panel.

Reloading the tab reopens the connection by itself; closing it closes the connection.

## Frequently asked questions

**Do I need a license?** No. Histeon is free (GPL), with no license keys. So are Histeon CSS, Lingua Bridge and Static Bridge.

**Does Histeon send data to anyone?** Not on its own: no telemetry. It connects to external services only for the features you turn on (for example updates, font imports, Cloudflare, machine translation with your own key), and an AI agent you connect receives what it reads from the site. See [Permissions and security](#en-permessi-e-sicurezza).

**Can an AI agent break the live site?** Not from the connector: it works only in development and can't publish. Not with WordPress abilities either: it can ask to publish, but a person publishes, from the **Releases** page.

**Can I use Histeon in English?** Yes: choose English in your WordPress profile. See [The interface in English and Italian](#en-lingue-interfaccia).

**Can I change the shortcuts?** Yes, for every palette command: [[Ctrl+K]] → **Keyboard shortcuts…**. See [Keyboard shortcuts](#en-scorciatoie).

**Does turning off a module delete data?** No, never.
