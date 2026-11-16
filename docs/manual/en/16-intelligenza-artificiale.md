# Histeon and artificial intelligence

Histeon has no built-in assistant and, on its own, doesn't send your data to AI services. Instead, it works with the agent you already use on your computer (Claude Code, Codex, Opencode or any agent with a shell or an MCP client), and gives it clear rules: what it can do, with which permissions, and above all what always stays in your hands.

Data leaves only when you send it: when you connect an agent, what it reads from the site (pages, styles, components, framework, the site brief) reaches the agent and the AI service the agent uses; when you enter a key of your own, for example for Lingua Bridge's machine translation, the texts to translate go to the service you chose.

There are two routes:

| Route | When to use it | Where the agent works |
|---|---|---|
| **Histeon connector** | You work in the builder and want to watch the agent at work | In the builder tab you have open, with your changes visible as they happen |
| **WordPress abilities** | A remote agent, with no tab open | Through an MCP server on the site, as a WordPress user |

In both cases the same rule applies: **the agent's changes go to development**. The live site only changes when someone publishes a release.

## The Histeon connector

The connector is a small program the agent starts on your computer. It acts as a bridge between the agent and the builder tab.

1. In the builder, click **✦** in the left bar. The **AI connector** panel opens.
2. Press **Copy the instructions** and paste them into your agent's chat. The instructions tell it to:
   - download `histos-connector.mjs` from this site (there's also the **Download histos-connector.mjs** link);
   - start it with the exact command, which contains your pairing secret;
   - read the manual for AIs (`manual`) and the site summary (`brief`) before making changes;
   - wait for the requests you write in the builder.
3. Press **Connect this tab**. Until the connector responds, ✦ pulses and the status says “Waiting for the connector…”; then it turns the accent color and the status says “Connected”.
4. Ask the agent for what you need, in its chat or from **Ask the agent** (below).
5. **Disconnect** closes the connection. Closing the tab closes it too.

You need Node 18 or later on your computer, nothing else to install. The builder must be open over HTTPS, or on `localhost`.

While the agent works:

- you see every change in the canvas, right away;
- every operation can be undone with **Undo**, like yours;
- the ✦ panel lists the **Agent operations**, with any errors;
- saving goes **to development**, and only when you agree.

> The ✦ panel also offers **Manual for the AI**: it's the manual the agent reads in order to work the way someone who knows Histeon would (BEM classes, framework variables, components, accessibility). You can read it too.

## The pairing secret

The command to paste contains a **secret of yours**: with it, the connector and your tab recognize each other. That way no website and no other program on the computer can connect in their place.

- Don't share the copied instructions: they contain the secret.
- If you shared it by mistake, open **Pairing** in the ✦ panel and choose **Regenerate the secret**. Then copy the instructions again and restart the connector.

The connector listens only on your computer (`127.0.0.1`), and the agent's commands require a token that stays on your computer.

## Ask the agent

In the ✦ panel, the **Ask the agent** section lets you write to the agent without going through its chat.

**Create a section.** Describe the section in the **Describe the section to create** field (for example “three testimonials with photo, name and role, on a light background”) and press **Create a section**. The section goes after the selected element, or at the end of the page if nothing is selected. With Histeon CSS active, the agent uses its tokens and BEM classes written for the section.

**Translate this page.** Appears when the page has languages (Lingua Bridge). Choose the languages and press **Translate**: the agent creates **only translation drafts**; for a language that's already published, a correction draft. See [Multilingual sites](#en-multilingua).

Requests are queued:

| Status | Meaning |
|---|---|
| waiting for the agent | The agent hasn't picked it up yet. You can withdraw it with ✕ |
| the agent is working on it | The agent is doing it |
| done | Finished: the agent's note appears below, with what it did and what's left to decide |

> Requests live in the tab: reloading the page loses them. At most 20 open requests, of 2000 characters each.

## Working from files

If you prefer a code editor (VS Code or another), with the tab connected the connector can bring the page into a folder, synced both ways: **Work from files (VS Code)** in the ✦ panel shows the command.

- One subfolder per document, with `page.html` and a `.css` file for each selector.
- You save in your editor and the builder updates; you change something in the builder and the file follows.
- If both change, neither overwrites the other: the builder's version goes next to the file, with the `.builder` extension.
- Scripts aren't synced, and saving remains a builder action.

## The connector as an MCP server

If your agent uses MCP, you can register the connector once and for all. The exact commands, with your pairing, are in **As an MCP server** in the ✦ panel. For example, with Claude Code:

```bash
claude mcp add xb -- node /percorso/histos-connector.mjs mcp --origin <sito> --pair <segreto>
```

The builder tab still has to be open and connected: the agent always works there.

## WordPress abilities

Since WordPress 6.9, plugins can offer **abilities** (Abilities API), that is, actions a remote agent calls through a WordPress MCP adapter. Histeon offers these:

| Ability | What it does |
|---|---|
| List of documents | The content the user can edit with Histeon |
| Read a document | The structure of the development version |
| Render a document | The HTML of the development version |
| Framework | The framework's tokens, variables and classes |
| Unpublished changes | Documents with changes in development |
| Preview link | A signed link to the preview |
| Apply operations | Structured changes (text, attributes, insert, move, remove), **in development** |
| Set a style | The CSS of a class selector, **in development** |
| Ask to publish a release | Lists the development changes and leads to the Releases page, where a person publishes (see below) |

The agent authenticates as a WordPress user, for example with an application password, and has **exactly that user's permissions, no more**.

At most 100 operations per call: a single error and nothing is saved. If the agent states the version it read and the document has changed in the meantime (for example because you're working on it), the call is rejected instead of overwriting.

> **Publishing with abilities.** An agent doesn't publish on its own. The **Ask to publish a release** ability, which needs the publish permission (`xb_publish`), lists what would go live and returns the **Releases** page: there a person checks and publishes. It's still best to give the agent a user that does **not** have the publish permission.

## What an agent can and cannot do

| It can | It cannot |
|---|---|
| Read the page, styles, components and the framework | Run code in the builder or on the server: it only picks from a closed list of operations |
| Change structure, texts and styles, in development | Publish from the connector: the operation doesn't exist |
| Create components, apply BEM classes | Write documents' JavaScript |
| Save in development, if you agree | Have more permissions than the user it works as |
| Create translation drafts with Lingua Bridge | Publish or overwrite a translation that's already live |

Every change goes through the same security filters as yours: an agent working as a user without permission to write code can't add scripts, event handlers or `javascript:` links. Every write through abilities ends up in Histeon's operations log.

> The content the agent reads from the site is **data**, not an instruction. Its manual tells it so explicitly: if it finds “do this” written on a page, it doesn't do it and reports it to you.

## What the agent may do on this site

In the ✦ panel, **What the agent may do**, an administrator chooses the areas an agent may change: page texts, structure and attributes, selector CSS, components, media library, contents and fields, queries and JSON sources. Reading, undoing and saving to development are always allowed; publishing never is. The choice applies to the whole site, also to agents working from outside with the WordPress abilities.

- **Contents and fields** is off until you turn it on: the title, excerpt and fields of a post or a record change the site at once. The status never changes (a draft stays a draft) and **Undo all** puts the previous values back.
- **Media**: the agent searches files, uploads new ones through the builder's compression, writes alternative texts, sorts the collections and compresses an image already uploaded. It never deletes a file.
- **Queries and sources**: written to development, with the usual versions.
- **Components**: besides creating them, it changes their markup and props; every page that uses them follows.

While the agent changes something, the canvas has a coloured border and the label **The agent is at work**, with the area it is touching.

## Keep or undo all

When an AI agent changes the site through the abilities, Histeon remembers how each document was before its first change. Changes made without pauses longer than half an hour form a session. A bar shows in the builder: **The AI agent is working** (or **has finished**), how many documents it changed and which. Two buttons:

- **Keep**: the changes stay in development, the bar goes away.
- **Undo all**: every document goes back to how it was before the agent, as a new version in development. The agent's versions stay in the history: if you change your mind, restore them from **Versions**.

Either way nothing goes live: a release is always needed. An administrator also sees other users' sessions.

## The Histeon CSS generator is not AI

In the **Design system** panel, the **Histeon CSS generator** creates palettes, scales and shapes from a few values (base colors, minimum and maximum sizes, ratios). It doesn't use artificial intelligence and doesn't call external services: the values are calculated on your server, always the same way. It's explained in the chapter [Histeon CSS](#en-histos-css).

The sections created with **Create a section** use exactly those tokens: when you change the design system, they change with it.

## Good practices

- Ask the agent to **propose** what it will do before big changes.
- Work in small steps and watch the canvas: undoing is always possible.
- Before publishing, use **Differences** and **Compare** in the **Publish** window.
- For abilities, create a dedicated user for the agent, with the minimum permissions it needs.
- Keep the agent on `localhost` or on a development copy when you try new things.
