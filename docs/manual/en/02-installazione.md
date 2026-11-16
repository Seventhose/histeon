# Installation

This chapter takes you from the downloaded package to a Histeon that is ready to use: file verification, installation, first run, theme, modules and updates.

## The packages

Every Histeon release is made of a few files:

| File | What it is |
|---|---|
| `xb-<version>-<commit>.zip` | The Histeon plugin, with the included modules (Histeon CSS, Lingua Bridge, Static Bridge) |
| `fondamenta-<version>-<commit>.zip` | The Fondamenta block theme (optional, recommended) |
| `xb-blank-<version>-<commit>.zip` | The XB Blank theme, only for sites that already use it |
| `histos-css-<version>-<commit>.zip` | Histeon CSS as a standalone plugin, **only** for sites that don't use the builder |
| `SHA256SUMS` | The SHA-256 fingerprints of the packages |
| `….zip.minisig` | The signature of each package |

The plugin's name in the files is `xb`: it is Histeon's technical prefix, kept for compatibility with existing sites.

> If you use Histeon, don't also install the `histos-css` package: Histeon CSS is already inside Histeon. If you install it anyway, it stays idle and tells you so.

## Verifying the packages

Before uploading a package to a site, it is worth checking that it really is the one that was published. It takes two minutes.

### The SHA-256 fingerprint

Put the packages and `SHA256SUMS` in the same folder, then from a terminal:

| System | Command |
|---|---|
| Linux | `sha256sum -c SHA256SUMS` |
| macOS | `shasum -a 256 -c SHA256SUMS` |
| Windows (PowerShell) | `Get-FileHash .\xb-0.1.0-….zip -Algorithm SHA256` |

On Linux and macOS every file must show `OK`. On Windows, compare the fingerprint shown with the one written in `SHA256SUMS` by eye.

### The signature

The fingerprint tells you the file wasn't damaged during the download; the signature tells you it was produced by whoever holds the Histeon release key. Signatures use the standard **minisign** format, a small free program available for every system.

The Histeon public release key (id `7245FEACC0E94D12`) is:

```
RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

With the package and its `.minisig` in the same folder:

```bash
minisign -Vm xb-0.1.0-….zip -P RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

If the signature is valid, minisign replies `Signature and comment signature verified`. Any other message means the file must not be installed.

> It is the same key you enter in the update settings (later in this chapter): from then on Histeon verifies every update by itself.

## Installing and activating

1. Check the requirements: WordPress 6.9 or later, PHP 8.3 or later.
2. In WordPress open **Plugins → Add New Plugin → Upload Plugin**, choose the Histeon ZIP and install it.
3. Activate the plugin.
4. If you want the Fondamenta theme, upload it from **Appearance → Themes → Add New Theme → Upload Theme** and activate it.
5. Follow the **Histeon → First run** screen (see the next section).
6. Open a page with **Edit with Histeon**.

On activation, Histeon prepares its tables for versions and gives administrators the permissions to build and publish. The **Histeon** item appears in the WordPress menu, with **Overview**, **Pages**, **Posts**, **Components**, **Templates**, **Releases**, **Versions**, **Modules** and **Settings**.

## The first run

Until you have completed or skipped the first run, an admin notice takes you to **Histeon → First run**. It is a single page, with three choices.

1. **The CSS framework.** These are the variables and classes the builder suggests to you. **Histeon CSS** is recommended and included. Core Framework and Automatic.css can be chosen if they are installed on the site; otherwise they show as “not installed”.
2. **The modules.** Lingua Bridge and Static Bridge, to turn on only if you need them. A module that is active as a separate plugin, or that requires a newer PHP, is shown but cannot be changed from here.
3. **Where you are coming from.** If the site has Etch content or uses Automatic.css, the page takes you to where the import is prepared. No import starts from here: everything happens in development, with a preview, and the site keeps working even without importing.

Then choose:

- **Save and start**: applies the chosen framework and modules;
- **Skip for now**: changes nothing.

All choices can be changed at any time. The first run remains reachable from **Histeon → Settings → First run**.

> Until you choose a framework, Histeon uses Histeon CSS. If one day the chosen framework is no longer installed, Histeon falls back to Histeon CSS instead of leaving the site without styles.

## The Fondamenta theme

Fondamenta is a deliberately empty block theme:

- no color, font or spacing presets;
- no Gutenberg visual controls for colors, borders and spacing, so styling stays in your classes;
- templates for pages, posts, archives and the 404 page, with header and footer built with Histeon.

It isn't required: any theme will do. To edit the site templates from the builder (the **Templates** section), however, you need a block theme. Fondamenta takes the place of XB Blank: if the site still uses XB Blank, the chapter on templates explains the switch.

## Modules

**Histeon → Modules** shows a card for each module, with icon, status, description, version and requirements, the toggle and the link to its settings.

| Status | What it means |
|---|---|
| **Active** | The module is on and working |
| **Off** | The module is included but not loaded |
| **Active as a separate plugin** | The same software is active from the Plugins page: the module stays idle and the plugin is managed from there |
| **Requires PHP …** | The server's PHP version is not enough |
| **Turned off after a fatal error** | The module caused an error and was stopped (see below) |
| **Turned off by wp-config.php** | A constant in `wp-config.php` keeps it off |

To turn a module on or off, use the **Turn on …** or **Turn off …** button on its card. Turning off a module **never deletes its data**: languages, translations, static copies and settings stay where they are.

When a module that is turned on has pages of its own, its menus appear at the bottom of the **Histeon** menu.

Notes on the individual modules:

- **Histeon CSS** is on by default. If you turn it off, only the base `--xb-*` tokens remain (components use them), while classes, palettes and the popup panel disappear.
- **Static Bridge** requires PHP 8.4. Before turning on, it checks PHP, WordPress, memory and file access: if something is missing, nothing changes and the reasons appear on the screen.
- **Lingua Bridge** and **Static Bridge**, if also installed as separate plugins, cannot be activated from the Plugins page while the module is on: the page shows “Active as a Histeon module”.

### If a module fails

If a module causes a fatal error, Histeon turns it off by itself: the next request starts without that module and the rest of the site works. Administrators see a notice with the module, the file and the line of the error, and the **Open Histeon → Modules** button. After fixing or updating the module, turn it back on.

If the admin area doesn't open at all, you can turn modules off from `wp-config.php`:

```php
define( 'XB_SAFE_MODE', true );                               // no modules
define( 'XB_DISABLE_MODULES', 'lingua-bridge,static-bridge' ); // only these
```

When you remove the constant, the module goes back to the state you had chosen.

## The Overview

**Histeon → Overview** is the starting point after installation: it shows what is live, what is waiting to go live and where to pick up again. The **Site status** card gathers what deserves a look, with a green dot (all good) or a coral one (worth a look) and the link to where to fix it: modules, static copy, recommended imports.

## Updates

Histeon updates from the public releases on GitHub, like any plugin from the WordPress admin: no license, no telemetry. The site only asks which release is the latest and downloads its package and signature, without sending content, users or settings.

Automatic updates are **off by default**. To turn them on:

1. Open **Histeon → Settings → Updates**.
2. In **Repository** enter the GitHub repository of the releases, in the form `owner/name`.
3. In **Minisign public key** paste the Histeon release key (the one from the “Verifying the packages” section).
4. Save. With **Check for updates on GitHub** you can check right away; the screen shows the latest signed release found and the installed version.

Alternatively, you can set the same values in `wp-config.php`, where they take precedence over the settings:

```php
define( 'XB_UPDATE_REPOSITORY', 'owner/name' );
define( 'XB_UPDATE_PUBLIC_KEY', 'RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W' );
```

How it works:

- Histeon checks for releases at most every 12 hours. Only a published release (not a draft or pre-release) that contains the package and its signature counts.
- If the version is newer than the installed one, WordPress shows it among the updates like any plugin.
- Before installing, Histeon downloads the package and the signature and verifies them with the configured key. If something doesn't match, the update stops with the reason and the downloaded file is deleted.
- The included modules (Lingua Bridge, Static Bridge) update together with Histeon, with the release that contains them. A separate plugin, on the other hand, keeps its own updates.

> Updates only work if the repository's releases are public: a private repository doesn't answer the anonymous requests Histeon uses.
