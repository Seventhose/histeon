# Introduction

Welcome to Histeon. This chapter explains what it is, who it is for, what sets it apart from other builders and what it needs to run.

## What Histeon is

Histeon is a visual builder for WordPress made for people who love clean code. You build pages by watching them grow on a canvas, but underneath there is always real HTML, with the tags and classes you chose, and CSS written per selector, nested as in SCSS.

The name comes from the Greek *histós* (ἱστός): the upright loom of ancient Greece, and also the cloth woven on it. A woven fabric, in other words: the *web*. In the logo, two uprights and a coral weft form an H.

Histeon saves pages in WordPress's standard format, the Gutenberg block format. Gutenberg is the format your content is stored in, not the interface you work in: that is why revisions, export, the REST API and SEO plugins keep working as always.

With Histeon you can:

- write and edit the structure in the HTML, CSS and JavaScript panels, or with the mouse on the canvas and in the Structure;
- create reusable **components**, with properties and slots;
- show dynamic content with loops, conditions and visual queries;
- work in **development** and take your changes live with a **release**, which can be restored;
- choose your CSS framework, starting with **Histeon CSS**, the free framework included.

## Who it is for

Histeon is made for people who build websites for a living:

- **designers and front-end developers**, who want full control over markup and CSS without giving up a visual interface;
- **agencies**, who need reusable components, versions, previews to send to the client and controlled publishing;
- **editors and clients**, who after handover edit the text directly in the WordPress block editor, with the page looking just as it does on the site.

You don't need to know how to program in PHP. You do need to know a little HTML and CSS: Histeon helps you write them, it doesn't hide them.

## What sets it apart

### Clean code

What you write is what ends up on the page: no hidden wrappers, no automatically generated classes. Styles are tied to the selector (a class or an id) and a page gets **only the CSS it actually uses**, both for your styles and for the framework's. The result is lightweight pages and code anyone can read.

### Versions and releases

Every save in the builder goes to **development**: the public site doesn't change. When you are ready, **Publish…** takes the chosen changes live as a release with a version number and notes. Before that you can look at the differences, make a visual comparison between the live page and the development one, and send the client a signed preview link that expires on its own. If something goes wrong, you restore the previous release.

### Security

- All content is filtered with the permissions of the person who saves it, as in WordPress: someone who cannot write unfiltered HTML cannot insert scripts.
- Untrusted content does not become trusted just because an administrator saves or publishes it.
- JavaScript can be written and published only with WordPress's unfiltered HTML permission.
- The builder canvas does not run the page's scripts.
- Updates are signed and verified before installation.

### Open source, no licenses

Histeon is free software, licensed GPL-3.0-or-later. There are no license keys to enter, no “pro” versions to unlock, no telemetry and no mandatory external services. Updates come from the public releases on GitHub: the site asks which release is the latest and downloads its package and signature, without sending content, users or settings.

## The Histeon family

Histeon is a single plugin, with some parts you can turn on and off (the **modules**). Some also exist as separate plugins.

| Name | What it does | How it is used |
|---|---|---|
| **Histeon** | The builder: canvas, structure, panels, components, versions and releases | Main plugin |
| **Histeon CSS** | The free CSS framework and design system: colors, fluid type and spacing scales, utility classes, recipes, popup panel | Module included and turned on; also available as a standalone plugin for sites without the builder |
| **Lingua Bridge** | Multilingual: translations as normal WordPress pages, translation memory, updating of changed sentences | Module included, off until you turn it on; also available as a separate plugin |
| **Static Bridge** | The static copy of the site: export, publishing to Cloudflare, forms and languages | Module included, off until you turn it on; requires PHP 8.4 |
| **Fondamenta** | The base block theme, with no Gutenberg presets or visual controls, with templates built with Histeon; it takes the place of XB Blank | Theme installed separately (optional) |

> A module that is active as a separate plugin is not loaded twice: Histeon recognizes it and lets the plugin do the work. Turning off a module never deletes its data.

## Requirements

| What | Requirement |
|---|---|
| WordPress | 6.9 or later |
| PHP | 8.3 or later |
| Static Bridge (module) | PHP 8.4 or later |
| Histeon CSS as a standalone plugin | WordPress 6.6 and PHP 8.3 or later |
| Database | MySQL or MariaDB, like WordPress |
| Theme | Any; for site templates you need a block theme, such as Fondamenta |

Histeon also works with WordPress on **SQLite**, through the official *SQLite Database Integration* plugin: it is handy for working locally with a single-file database, or for a site published as a static copy. WordPress officially supports only MySQL and MariaDB, so for a dynamic production site they remain the recommended choice.

On activation, **administrators** receive the permissions to build with Histeon and to publish releases.

## How to read this manual

The manual follows the path of someone building a site. If this is your first time, read the first chapters in order:

1. [Installation](#en-installazione): the packages, signature verification, first run and modules.
2. [The builder](#en-il-builder): how the interface is laid out, the canvas, preview, saving and publishing.
3. [Elements](#en-elementi): inserting, moving, writing text, the HTML panel and properties.
4. [Styles](#en-stili): classes, selectors, Mini GUI, media queries and the CSS that reaches your pages.
5. [Histeon CSS](#en-histos-css): the framework and the design system.
6. [Components](#en-componenti): creating them, giving them props and slots, using and publishing them.

Read the other chapters when you need them: content and data, templates, versions and releases, static site, multilingual, importing from Etch, permissions and security. The contents on the left lists them all, and the search at the top finds a word anywhere in the manual.

A few conventions:

- Interface labels are in **bold**, exactly as you see them on screen: **Publish…**, **Save**, **Styles and selectors**.
- Paths in the WordPress menu use an arrow: **Histeon → Settings**.
- Keys are written like this: [[Ctrl+S]]. On a Mac, use ⌘ instead of Ctrl.
- Code to type is in a monospaced font: `class="card"`.

> Boxes like this one contain tips and warnings: they are worth reading.
