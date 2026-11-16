# The interface in English and Italian

Histeon is written in Italian and is also available in English. This chapter explains how it chooses the language it speaks to you in, and what is left out of that choice.

> This is about the language of the **interface**: menus, buttons, messages. To publish the **site** in several languages you need Lingua Bridge: see [Multilingual sites](#en-multilingua).

## How the language is chosen

Histeon uses the same language WordPress uses for you:

1. the language of your **profile** (**Users → Profile → Language**);
2. if the profile doesn't set one, the language of the **site** (**Settings → General → Site Language**).

Then the rule is simple:

| Your language in WordPress | Histeon speaks to you in |
|---|---|
| Italian | Italian |
| English | English |
| Any other (German, French, Spanish…) | English |

So anyone using WordPress in German or French reads English, not Italian: it's the language they're most likely to understand. If the site has a Histeon translation for your own language, Histeon uses that.

## Changing the language

Each person can have their own language, on the same site:

1. Open **Users → Profile**.
2. Under **Language**, choose the one you prefer.
3. Save the profile and reload the builder.

That way a collaborator can work in English while you carry on in Italian, or the other way around. The choice doesn't change anything on the public site.

## Where it applies

The same rule applies to every part of Histeon, each with its own translations:

- **Histeon**: the admin screens, the builder and the Histeon panels in the block editor;
- **Histeon CSS**, the framework and its panel;
- **Lingua Bridge**, the languages module;
- **Static Bridge**, the static site module;
- **Fondamenta**, the base theme, and **XB Blank**, the compatibility theme.

This holds both when you use them as Histeon modules and as separate plugins.

## What doesn't change with the language

Some things stay the same in every language, because they aren't interface texts:

- **Technical names:** CSS classes, variables, permission names (`xb_build`, `xb_publish`), the syntax of expressions and code.
- **Product names:** Histeon, Histeon CSS, Lingua Bridge, Static Bridge.
- **Instructions for AI agents:** the manual and the instructions the connector hands to the agent stay in their original form.
- **The site's content:** the texts of your pages, and also the ready-made texts of the Fondamenta and XB Blank themes (such as “Pagina non trovata”, Italian for “Page not found”), are content, not interface. Whoever manages the site translates them, with Lingua Bridge too.

## The words in Italian

If you work with colleagues who use the interface in Italian, here's what the main things are called:

| English | Italian |
|---|---|
| development, in development | sviluppo, in sviluppo |
| live | online, produzione |
| publish, release | pubblicare, release |
| draft | bozza |
| component | componente |
| canvas | tela |
| stylesheet | foglio di stile |
| selector, class | selettore, classe |
| global styles | stili globali |
| content type, field | tipo di contenuto, campo |
| data source | sorgente dati |
| named media query | media query con nome |
| loop query | query dei loop |
| preview | anteprima |
| restore | ripristina |
| Undo, Redo | Annulla, Ripeti |
| checks | controlli |
| site move | trasloco del sito |
| static site, static copy | sito statico, copia statica |
| translation memory | memoria di traduzione |
| language switcher | selettore di lingua |

> Keyboard shortcuts don't change with the interface language: [[Ctrl+K]] opens the commands in both English and Italian. See [Keyboard shortcuts](#en-scorciatoie).
