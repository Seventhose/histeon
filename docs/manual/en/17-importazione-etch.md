# Importing an Etch site

If your site was built with Etch, you don't have to rebuild it from scratch. Histeon reads the pages, components, templates, styles and queries saved with Etch and turns them into Histeon content. The result arrives as a **development version**: you check it, publish it when you're happy with it, and you can always go back.

## Before you start

A few things to know:

- **Etch's data is not touched.** Etch's options, meta and content stay where they are; the import only writes to Histeon's development versions.
- **Nothing goes live by itself.** The public site only changes when you publish a release.
- **The site works even without importing.** If Etch isn't active, Histeon renders the content saved with Etch by itself, at request time, and the site stays as it was. Importing is still recommended, but it's never mandatory.
- **The import has been tested on a test site** built with Etch 1.7.1 and Automatic.css: the imported pages turned out the same as the original, apart from the small differences described further on, with working scripts.

> Back up the site before you start, as with any major change. The import is reversible, but a backup costs nothing.

## The reminder

As long as the site has content saved by Etch that hasn't been imported yet, administrators see the **Histeon: import recommended** notice on the admin screens, with two buttons:

- **Import now** takes you to the import section;
- **Later** hides the notice for 14 days, just for you.

The same notice also appears when Automatic.css isn't active and the stylesheets already on the site stay loaded (see further on).

Histeon's **First run** screen, too, at the “Where you're coming from” step, points out Etch content with the **Prepare the import** button.

## Importing step by step

1. Open **Histeon → Settings** and go to the **Import from Etch** section. It appears only if Histeon finds Etch data on the site.
2. If the site uses Automatic.css, choose where to take its design system (see below).
3. Press **Import preview**. Histeon reads everything and shows you the report, **without changing anything**.
4. Read the report, especially the **To review** column.
5. Press **Prepare in development**. Now the import is written, but only in development.
6. Open the pages in the builder and check them in preview.
7. Publish from the builder with **Publish…**. In the publish window you'll find **Differences** and **Compare** to see what changes.

After publishing, the pages are rendered by Histeon.

## The report

The report summarizes how many documents, components, queries and bytes of CSS were found, then lists each document:

| Column | What it says |
|---|---|
| **Document** | Title, type and ID of the content |
| **Trust** | `trusted` if the author and the last person to edit it could write code, otherwise `restricted` |
| **Converted** | How many blocks were converted |
| **Script** | How many element scripts were carried over |
| **To review** | What Histeon couldn't convert and needs fixing by hand |

Below the table are the general warnings, for example the non-public content types that the imported queries use and that Histeon has allowed in loops.

> **Why trust matters.** In Histeon each piece of content keeps the trust level of **whoever wrote it**, not of whoever imports it. JavaScript from authors without the `unfiltered_html` permission is not imported. That way dangerous content saved by an author doesn't become “trusted” just because an administrator imports it.

## What gets converted

| From Etch | In Histeon |
|---|---|
| Pages, posts | Histeon documents, with the same structure |
| Components | Histeon components, with a key derived from the name in Etch (`EventCard` becomes `event-card`) |
| Templates and template parts | Histeon templates, with versions like everything else |
| Elements and texts | Histeon elements, with tags, attributes and labels |
| Element scripts | The document's JavaScript (only from trusted authors) |
| Loops and queries | Predefined loop queries, usable like the others |
| Dynamic images | Images with dimensions and responsive versions read from the media library |
| Per-selector styles | Histeon per-selector styles |
| Global stylesheets | Histeon global stylesheets |
| Expressions in curly braces | Histeon expressions |
| WordPress and other plugins' blocks | They stay as they are, with the Etch blocks inside them converted |

Classes added by scripts are protected, so CSS on demand doesn't discard them.

If Histeon doesn't have a CSS framework chosen yet and finds Automatic.css (or Core Framework), it picks that one. The import also turns on the Histeon CSS reset.

## What doesn't get converted

Histeon never guesses: what it doesn't recognize stays as it was and ends up in the **To review** column.

- **Etch conditions, slots and SVG blocks:** they aren't supported yet and aren't converted. They have to be redone by hand in the builder.
- **Expressions that can't be translated:** they stay as text, visible on the page, and appear in the report. Etch modifiers with an equivalent are translated, including the newest ones (`toSlug`, `isTruthy`, `isFalsy`, comparisons with values for true and false); those without one, such as `round` or `toInt`, are named in the report.
- **JavaScript from untrusted authors:** it isn't imported (see above).
- **Fields added by other plugins to Etch's data** (for example `this.extra.field`): they only work if the plugin also offers them to Histeon. It's one line of code for whoever maintains that plugin.
- **The CSS reset:** it isn't copied. The import uses Histeon's reset, which leaves links placed directly in a container inline. If they were blocks on the original site and you need the same effect, add a rule to your CSS.

Check the imported pages in the preview: small differences may appear in texts, for example where the original showed an HTML entity encoded twice and Histeon shows it once.

## The design system: Automatic.css

If the site uses Automatic.css and its generated stylesheets are on the site, the **Import from Etch** section asks “The site uses Automatic.css: where should its design system go?”:

| Choice | What happens |
|---|---|
| **Into Histeon CSS** (recommended) | Histeon's free framework, already included: its tokens take Automatic.css's values, the palette starts from your colors, equivalent classes are renamed, the rest keeps working. No external plugin |
| **Into Core Framework** | Appears if Core Framework is installed: a project with the colors and all of Automatic.css's variables, plus its rules that the site uses |
| **Stay on Automatic.css** | Nothing changes: the site keeps using Automatic.css |

The choice reads the values from the generated stylesheets on the site.

## From Automatic.css to Histeon CSS, without Etch

A site that doesn't use Etch can also move from Automatic.css to Histeon CSS: you do it from **Histeon → Settings → Framework switch**, with the same preview and the same “Prepare in development”. The full procedure, with the table of colors and names, is in the chapter [Histeon CSS](#en-histos-css).

> **If Automatic.css isn't active.** The stylesheets already in the site's uploads folder stay loaded until you import, and the site stays as it was, without running any Automatic.css code. Don't delete that folder before importing.

## After publishing

- Check the public site, on a computer and on a phone.
- Etch's data stays in the database: should you need to go back, restore the previous release in **Histeon → Releases**.
- The “import recommended” notice stays as long as some content outside the trash still contains Etch blocks: check which pages you haven't published, or hide it with **Later**.

## Exporting to Etch

If a site must stay on Etch, you can take what you built with Histeon back to Etch: published pages, posts, components, templates, styles and loops go back into Etch's data **on this site**, with the same addresses and the same components. You do it from **Histeon → Settings → Export to Etch**, which appears when the site has Etch data or the Etch plugin.

1. Choose the token names. **Histeon CSS** (recommended) adds two global stylesheets with the `--xb-*` tokens and the Histeon CSS classes, and the site stays editable through the tokens. **Automatic.css names** only appears if Automatic.css is already installed on the site: the tokens that have an ACSS name take it (`--xb-space-m` becomes `--space-m`).
2. Press **Export preview**. Nothing changes: you see every document, how many blocks go through, how many are left out, whether it replaces content that was already Etch's, and which Etch entries (queries, stylesheets, styles) are added or replaced.
3. If you like, press **Download the JSON file** to keep a copy of what would be written. Etch does not import this file.
4. Tick the confirmation and press **Write to this site**. Histeon writes only what you saw in the preview; if something changed in the meantime, it stops and asks you to run the preview again. Before writing it makes a backup.
5. If something is wrong, **Restore** puts content, component keys and Etch's options back as they were before the last write. Histeon's versions stay too: a release brings them back.

From WP-CLI: `wp xb export-etch --user=<administrator>` for the preview and the fingerprint, then `--apply --confirm=<fingerprint>` to write and `--restore --yes` to go back.

**What is left out**, and shown in the preview: conditions and slots (not supported yet), Histeon content types and records and the loops that list them, loops over terms or users, Histeon CMS templates, component prop schemas, and Histeon settings with no Etch equivalent. JavaScript from authors without the `unfiltered_html` permission is not exported, just as it is not imported. Writing needs an administrator with `unfiltered_html`.

For another Etch site: first bring the project to that site with Histeon installed (through the bridge or an `.xbsite` file), then export there. The final check belongs on a copy of the site with Etch active.
