# Modules and updates

Histeon is a single plugin, but some of its parts can be turned on and off: these are the **modules**. This chapter explains how to manage them, what happens if one fails, and how Histeon updates itself safely, without licenses.

## What a module is

A module is a part of Histeon that does a specific job and that not every site uses. Today there are three modules:

| Module | What it does | Default |
|---|---|---|
| **Histeon CSS** | Histeon's free CSS framework: fluid tokens, palettes, on-demand classes, recipes, the pop-up panel and the import from Automatic.css | On |
| **Static Bridge** | The site as a static copy: export, publishing to Cloudflare, forms and languages | Off |
| **Lingua Bridge** | Translations as regular WordPress pages: editor on the site, memory, updates of changed sentences, AI | Off |

Static Bridge and Lingua Bridge also exist as **separate plugins**, with their own releases. Inside Histeon they're the same code, in the version included in the Histeon release.

## Turning a module on and off

1. Open **Histeon → Modules** (you need to be an administrator).
2. Each module has a card with an icon, status, description, version and requirements.
3. Use the switch to turn it on or off. The **Settings** link takes you to its settings.

> **Turning off a module never deletes its data.** Languages, translations, memory, static copies and settings stay: when you turn the module back on, you find them as they were.

What happens when you turn on a module:

- **Lingua Bridge** does what activating the plugin would do: it prepares its tables and its address rules. Its menu appears under **Histeon**.
- **Static Bridge** first checks that the server is suitable (PHP version, WordPress, memory). If something's wrong, nothing changes and the reasons appear on the screen. Its menu also appears under **Histeon**.
- **Histeon CSS**, when off, leaves only the base tokens that components use; the classes, the panel and the palettes in Gutenberg disappear.

The statuses you may see:

| Status | What it means |
|---|---|
| **Active** | The module is on and working |
| **Off** | The module is included but turned off |
| **Active as a separate plugin** | The same software is active as a plugin: the module stays idle |
| **Requires PHP …** | The server has a PHP version that's too old (Static Bridge requires PHP 8.4) |
| **Coming as a module** | Not included yet: today it's used as a separate plugin |
| **Turned off after a fatal error** | Histeon stopped it because it was failing (below) |
| **Turned off by wp-config.php** | Whoever manages the server turned it off from `wp-config.php` |

## Module or separate plugin

The same code can't run twice: it would bring the site down. That's why:

- if Lingua Bridge or Static Bridge are active as plugins, the module doesn't load and can't be turned on. The message says “It is active as a separate plugin: deactivate that to use the module”;
- with the module on, on the **Plugins** page the separate plugin shows “Active as a Histeon module” instead of **Activate**.

To move from the plugin to the module: deactivate the plugin from the **Plugins** page, then turn on the module in **Histeon → Modules**. The data is the same (same options, same tables), so there's nothing to migrate.

A module is updated together with the Histeon release that contains it. The separate plugin, on the other hand, keeps getting its own updates.

## When a module fails

If a module causes a fatal error, **Histeon stops it**, and the rest of the site keeps working.

1. The request in which the error happens is interrupted, as would happen with any plugin.
2. From the next request on, the site starts again **without that module**.
3. Every admin screen shows the notice **Histeon turned off a module after a fatal error**, with the module's name, the file, the line and the error message, and the **Open Histeon → Modules** button.
4. On the Modules screen the status becomes **Turned off after a fatal error**.

What to do: update Histeon (or fix the cause, if you know it), then turn the module back on. Turning it back on clears the recorded error.

> Only errors that originate inside a module turn it off. An error in Histeon itself, or in another plugin, doesn't turn off any module.

## Safe mode

If the admin no longer opens, someone with access to the site's files can turn off the modules from `wp-config.php`:

```php
define( 'XB_SAFE_MODE', true );                               // nessun modulo
define( 'XB_DISABLE_MODULES', 'lingua-bridge,static-bridge' ); // solo questi
```

A module turned off this way can't be turned back on from the screen, which shows “Turned off by wp-config.php”. Removing the line returns the module to the state you had chosen.

## Histeon updates

Histeon updates itself from the releases published on GitHub, like any plugin, from the WordPress admin: **no license, no telemetry**. The site only asks which release is the latest and downloads its package and signature, without sending content, users or settings.

Updates are **off by default**. To turn them on:

1. Open **Histeon → Settings**, **Updates** section.
2. Check **Check for updates on GitHub**.
3. In **Repository**, write the releases repository, in the form `owner/name`.
4. In **Minisign public key**, paste the release public key (below).
5. Press **Save**.

If you prefer, you can set them in `wp-config.php` with `XB_UPDATE_REPOSITORY` and `XB_UPDATE_PUBLIC_KEY`: in that case those values apply and the screen says so.

Histeon checks at most every 12 hours. When it finds a newer, signed version, WordPress offers it as a normal plugin update. Below the settings you see the latest signed release found and the installed version.

> Updates only work with a public repository: a private repository doesn't respond to anonymous requests.

## The copy before an update

Before WordPress replaces Histeon with a new version (from the plugins page, with automatic updates, with WP-CLI or by uploading a ZIP), Histeon copies its own folder and its tables (versions, releases, log, deploys). If the copy fails, the update stops and says why.

In **Histeon → Settings → Histeon safety copies** you see the last two copies, can make one now and, after a confirmation, **go back to the previous version**. The copy does not include the site's content (pages, posts, media, menus), Histeon's settings, the modules' data, other plugins or the theme: the site backup covers those. On nginx add `location ~ /xb-backups/ { deny all; }` to the configuration.

## Why releases are signed

Every Histeon package is **signed** with a key that stays with whoever publishes the releases, outside the repository and the packages. Only the **public** key, used for verification, goes onto sites.

Before installing an update, Histeon downloads the package and its signature and checks two things:

- that the signature matches the public key you configured;
- that the signature names that very package.

If something doesn't add up, the update stops, the reason is shown and the downloaded file is deleted. That way, even if someone managed to slip a fake package among the releases, your site wouldn't install it.

Histeon's release public key, from version 0.1.0 on, is:

```
RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

## Verifying a release by hand

If you install Histeon by uploading the ZIP by hand, you can verify it yourself with [minisign](https://jedisct1.github.io/minisign/), a small free program.

1. From the release, download both the ZIP (`xb-X.Y.Z-….zip`) and its signature (the same name with `.minisig` at the end), into the same folder.
2. Open a terminal in that folder and run, with the real file name:

```bash
minisign -Vm xb-X.Y.Z-sha.zip -P RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

3. If the package is intact and signed with the Histeon key, minisign replies that the signature and the trusted comment are valid, and shows the trusted comment: it must name the same file.
4. If minisign reports an error, **don't install** that package.

> Always compare the public key with the one written in this manual or in the official documentation, not with a key found next to the downloaded file.
