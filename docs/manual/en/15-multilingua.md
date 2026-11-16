# Multilingual sites

Histeon builds sites in several languages together with **Lingua Bridge**, the free (GPL) multilingual plugin designed to work with Histeon. This chapter explains how to turn it on, how to translate pages, components and site texts, and how to put a new language live without surprises.

## How it works

Each language is a **regular WordPress page**, linked to the original by Lingua Bridge. The English version of the “About us” page is a piece of content in its own right, which opens in the builder like any other page.

A few useful things follow from this:

- **Everything you know applies to every language:** saving in development, releases, versions, review and preview work the same way on every translation.
- **Each language can be different:** one more image, one less section, a text rewritten for a different audience.
- **A structural change doesn't spread by itself:** if you add a section to the original, it has to be added in the translations (Lingua Bridge tells you what changed, see below).
- **Styles, settings and queries** are the same for all languages: you change them only once.
- **Deactivating Lingua Bridge** leaves the pages as regular WordPress content, and they remain editable.

> Histeon doesn't depend on any particular language plugin: it offers hooks that Lingua Bridge uses. Lingua Bridge is, however, the only engine that is tested and recommended.

## Turning on Lingua Bridge

Lingua Bridge is included in Histeon as a **module**, off by default.

1. Open **Histeon → Modules**.
2. Turn on **Lingua Bridge** with its switch.
3. The Lingua Bridge menu appears under **Histeon**. Open its **Settings**.
4. In the **Languages** tab, choose the default language (the language of the original) and the translation languages.

If Lingua Bridge is already installed as a separate plugin, the module stays idle and the plugin keeps working as before: the data is the same. You'll find the details in the chapter [Modules and updates](#en-moduli-e-aggiornamenti).

> **Warning:** after the first linked translation, the default language can no longer be changed and languages already in use can't be removed. Choose them carefully.

The Lingua Bridge dashboard has a **Getting started** list that guides you through the first steps. The translations to do (to translate, needing an update, in draft) also appear in **Histeon → Overview**, in the site status.

## Language addresses

In the **URLs and SEO** tab of the Lingua Bridge settings you'll find **Language addresses**: “Each language in its own folder: /en/, /en/about-us/…”. This is the recommended setting:

- the original stays at the site root (`/chi-siamo/`), translations in their language's folder (`/en/about-us/`);
- search engines, the language switcher and the static copy always know which language they're looking at;
- Lingua Bridge adds `hreflang` to the linked pages and the language sitemap;
- lists, searches and loops show the content in the page's language, in the builder canvas too.

It requires pretty permalinks (WordPress's Settings → Permalinks). The feature stays off until you turn it on.

> If there's still another multilingual plugin on the site (Polylang, WPML, TranslatePress), first complete the import from **Import from plugin** and check that the two aren't competing for the same addresses. Don't use two language plugins on the same paths.

With the static site, each language is a folder of the static copy (`en/…`), with no redirects based on the browser.

## Translating a page in Histeon

When the page has languages, the builder's top bar shows the **language bar**, next to the title:

| What you see | What it means |
|---|---|
| The highlighted code (for example **IT**) | The language of the page you're editing |
| A plain code (**EN**) | A translation that already exists: one click opens it in the builder |
| A code with a plus sign (**+DE**) | A missing language: one click creates the translation and opens it in Histeon |

The translation created with **+DE** starts as a **draft**, a copy of the original, and Lingua Bridge tells you which fields it copied and which are still to be translated. Then you translate by typing directly in the canvas (double-click on a text) or in the HTML panel, as on any page.

While you work on a translation, the builder's **checks** flag texts of at least 12 characters that are still identical to the original, with the message “Same as the original, maybe not translated”. A proper name or a brand can stay the same on purpose: in that case ignore the warning.

> In a component's sessions there is no language bar: components are translated as content in their own right (below).

## Translating components

Header, footer and the other components are WordPress content too, and they're translated the same way: you create the component's translation and translate it.

On the English page, Histeon uses the English version of the component **only if it's published**. While it's a draft, or if it doesn't exist, the original is shown. That way a half-done translation never ends up on the site by mistake.

## Translating on the site

Lingua Bridge also has a **visual editor on the site**: you translate on the real page, with the real theme.

1. On a page of the site, from the admin bar choose **Translate this page** and the language.
2. If the translation is missing, it's created as a draft and opens right away.
3. Hover over the texts: those to translate are highlighted in yellow. Click a text (or an image, for its alternative text) and write the translation in the panel.
4. [[Ctrl+Enter]] saves and moves to the next text. **Next to translate** takes you to the next text still to be translated.
5. **Leave as is** marks as done a text that stays the same by choice.
6. **Publish the translation** puts it live, if you have permission.

For each text, the panel shows the sentence from the original and the glossary terms it contains.

## Strings: texts outside the pages

A page also shows texts that aren't part of its content: the site title and tagline, menu labels, theme and plugin texts. You translate them in **Lingua Bridge → Strings**.

- Title, tagline and menu labels appear by themselves the first time you open the screen.
- Other texts are added by writing them exactly as they are in the original language, or with one click in the editor on the site.
- Only whole texts are replaced, and only as plain text: a string can't add code to a page.
- Strings are translated by whoever administers the site.

Themes and plugins with a WordPress translation pack translate themselves: Lingua Bridge uses the right WordPress language for each language of the site, and can install missing packs from the **Languages** tab.

## Machine translation and memory

**Machine translation.** In **Settings → AI translation** you can connect a translation service with **your own** key: DeepL, or an OpenAI-compatible service (including a model on your own server). **Save and test** translates a test sentence. Then:

- in the editor on the site, **Machine translate** translates the sentences still in the original language and leaves the ones already translated alone;
- in the dashboard, the bulk action **Machine translate (drafts)** creates the missing drafts and translates them.

Machine translation works **only on drafts**: a published translation is never touched. The key is never shown.

**Translation memory.** Lingua Bridge remembers sentences already translated and suggests them again: a new translation starts with the known sentences already in place, and in the editor on the site they appear as “From memory”, with **Use** or **Apply the memory**. The memory learns from a machine translation only when someone publishes it, that is, after reviewing it.

**When the original changes.** The translation becomes “needs update” and the editor on the site shows only the new or changed sentences. On a draft, **Update the draft** rebuilds it, keeping the sentences already translated; on a published translation you correct the sentences by hand and then choose **Mark as updated**.

## Translating with an AI agent

With the Histeon connector connected (chapter [Histeon and artificial intelligence](#en-intelligenza-artificiale)), the ✦ panel shows **Translate this page (Lingua Bridge drafts)**: choose the languages and press **Translate**. The agent goes through the Lingua Bridge connector and leaves **drafts only**. For a language that's already published it creates a correction draft, without touching the live page.

Lingua Bridge also has a guided path of its own, in **Lingua Bridge → Translate with AI**.

## XLIFF for translators and agencies

In **Lingua Bridge → XLIFF (agencies)** you export the content to translate to an XLIFF file, the format of professional translation tools (Trados, memoQ, Phrase, Smartcat, OmegaT…).

1. Choose the language, the content type and what to export (to translate, needing an update, or everything for a review).
2. Press **Export XLIFF** and send the file to the translator. Each text is a segment; code doesn't go into it.
3. When the translated file comes back, upload it on the same screen.

Each piece of content becomes a **draft** with the original's structure and the translated texts; a translation that's already published gets a correction draft. Nothing is published.

> If the original changed after the export, the file won't import: export again, and the translation tool will find the sentences already done in its memory.

## The language switcher

The simplest way, in Histeon, is the **Language switcher** snippet: open **Add an element** ([[Ctrl+I]]), **Interactions** group, and put it in the header (usually in its component).

- It appears only when the page has at least two published languages.
- It leads to the published versions of the page, with `hreflang`, `lang` and the current language marked for screen readers.
- Its classes follow the `lang-switch` BEM: style it like any other element.

Alternatively, Lingua Bridge can add it by itself at the end of the menu: **Settings → URLs and SEO → Language switcher in the menu**.

## Before putting a language live

A new language is visible on the site as soon as its pages are published. It's good practice not to switch on a language on a site that's already live without having checked it:

1. Prepare the translations as drafts, including those of the components (header and footer) and of the strings.
2. Check every page in preview, with the builder's checks showing no unexpected “maybe not translated” warnings.
3. If you can, try the per-language addresses on a copy of the site first: turning them on changes the translations' URLs, and with a static site or a CDN the outputs need to be regenerated and redirects from the old addresses prepared.
4. Publish the translations together, then add the language switcher.
5. With the static site, after changing the strings regenerate the complete static copy: a string applies to all pages.
