# Keyboard shortcuts

In the builder almost everything can also be done from the keyboard. This chapter lists the default shortcuts, the keys that work in particular places (the canvas, the structure, the code) and explains how to change them.

On a Mac, use [[⌘]] instead of [[Ctrl]] and [[⌥]] instead of [[Alt]]. The tables give the Windows and Linux version.

## The command palette

The most useful shortcut is [[Ctrl+K]]: it opens the **command palette**, from which you can reach the whole builder. Type a few letters, choose with [[↑]] and [[↓]], confirm with [[Enter]]. [[Esc]] closes it. The **Commands** button in the top bar opens it too.

Next to each command the palette shows its shortcut, if it has one. That way you learn them while you work.

## Default shortcuts

| Command | Windows and Linux | Mac |
|---|---|---|
| Open commands | [[Ctrl+K]] | [[⌘+K]] |
| Save in development | [[Ctrl+S]] | [[⌘+S]] |
| Add an element | [[Ctrl+I]] | [[⌘+I]] |
| Undo | [[Ctrl+Z]] | [[⌘+Z]] |
| Redo | [[Ctrl+Shift+Z]] or [[Ctrl+Y]] | [[⌘+Shift+Z]] or [[⌘+Y]] |
| Duplicate the selected element | [[Ctrl+D]] | [[⌘+D]] |
| Delete the selected element | [[Delete]] | [[Delete]] |
| Move up | [[Alt+↑]] | [[⌥+↑]] |
| Move down | [[Alt+↓]] | [[⌥+↓]] |
| Move out of the container | [[Alt+←]] | [[⌥+←]] |
| Move into the element before | [[Alt+→]] | [[⌥+→]] |
| Cut the selected element | [[Ctrl+X]] | [[⌘+X]] |

[[Esc]] is fixed and can't be changed: it closes only the open popover (History, More, AI connector), otherwise the open section, then the framework panel, then clears the selection.

> **While you type**, shortcuts don't steal your keys. In text fields and code editors only “Open commands” and “Save in development” work. “Add an element” also works in fields, but not in code. All the others work only outside fields and editors.

Shortcuts also work after a click inside the canvas: the preview passes the keys on to the builder.

## Copying and pasting elements

Outside text fields and code:

| Keys | What it does |
|---|---|
| [[Ctrl+C]] | Copies the selected element with the styles of its classes |
| [[Ctrl+V]] | Pastes: an element copied from Histeon (even from another site) opens the paste window; HTML copied from elsewhere is cleaned up and inserted as elements |

## Keys in particular places

These keys work only in one specific place in the builder and can't be changed.

**Text typed in the canvas** (double-click on a heading, a paragraph, a link or a button):

| Keys | What it does |
|---|---|
| [[Enter]] | Confirms the text (so does a click outside) |
| [[Shift+Enter]] | Adds a line break within the same element |
| [[Esc]] | Puts the text back as it was |
| [[Ctrl+B]] / [[Ctrl+I]] | Bold / italic |
| [[Ctrl+S]] | Confirms and saves in development |

**Structure panel:**

| Keys | What it does |
|---|---|
| [[F2]] | Renames the element (so does a double-click on the name) |
| [[Shift+F10]] or the Menu key | Opens the element's context menu |

**Context menu, palette and Add menu:** [[↑]] and [[↓]] to move (in the Add menu also [[←]] and [[→]]), [[Enter]] to choose, [[Esc]] to close. In the context menu [[Home]] and [[End]] go to the first and last item.

**HTML and code panel:**

| Keys | What it does |
|---|---|
| [[Tab]] | Expands an Emmet abbreviation (for example `ul>li*3`), or indents the line |
| [[Esc]], then [[Tab]] | Leaves the code editor with the keyboard (the hint appears at the bottom right while the editor has the focus) |
| [[F12]] or [[Ctrl]]+click on a class or an id | Selects the element and opens the CSS panel on that selector |

**Canvas width handles** (on the sides of the preview, after reaching them with [[Tab]]):

| Keys | What it does |
|---|---|
| [[←]] / [[→]] | Narrows or widens by 10 pixels |
| [[Shift+←]] / [[Shift+→]] | Narrows or widens by 100 pixels |
| [[Home]] | Minimum width |
| [[End]] or [[Esc]] | Back to full width (so does a double-click on the handle) |

**Preview:** [[Esc]] goes back to the builder.

**Lingua Bridge's editor on the site:** [[Ctrl+Enter]] saves the text's translation and moves to the next one.

## Changing shortcuts

Every palette command can have up to **three** combinations: actions, sections, panels, snippets, components. Only the “Select” entries are left out, since they change from one page to another.

1. Open the palette with [[Ctrl+K]] and choose **Keyboard shortcuts…**.
2. Search for the command by name, by group or by combination.
3. Press **+** next to the command. “Press the combination… (Esc cancels)” appears.
4. Press the combination you want. It's saved immediately.

To remove a combination, press **✕** next to it. To go back to a command's default shortcuts, press **↺**; to go back to the defaults for the whole builder, **Restore all**.

Your shortcuts are saved in your **profile**: they apply to you, on every browser and computer, and don't change your colleagues' shortcuts.

## Rules for combinations

- **Form:** [[Ctrl]], [[Alt]] and [[Shift]] (in this order, whichever you want) plus one key. The key can be a letter, a digit, [[F1]] to [[F12]], an arrow, [[Delete]], [[Home]], [[End]], [[Page Up]], [[Page Down]] or one of `, . / ; [ ] = -`.
- **One combination, one command.** If it's already in use, Histeon tells you, with the name of the command that uses it (“… already belongs to …”): remove that one first.
- **Reserved combinations**, which stay with the browser or with typing and can't be assigned: [[Ctrl+C]], [[Ctrl+V]], [[Ctrl+X]], [[Ctrl+A]], [[Ctrl+W]], [[Ctrl+T]], [[Ctrl+N]], [[Ctrl+Q]], [[Ctrl+R]], [[Ctrl+L]], [[Ctrl+F]], [[Ctrl+P]], [[Ctrl+Shift+T]], [[Ctrl+Shift+N]], [[Ctrl+Shift+W]] and [[Backspace]].
- **The physical key.** Letters, digits and punctuation are recognized by the key's position, not by the character it produces: that's why [[Alt]] (or [[⌥]] on a Mac) doesn't change them.

> With a keyboard whose layout is very different (for example AZERTY), if a shortcut doesn't respond as you expect, record it again by pressing it: Histeon saves the key you actually press.

A shortcut runs the command exactly as the palette does, with the same checks: it doesn't allow anything the command doesn't already allow.
