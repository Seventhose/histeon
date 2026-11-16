#!/usr/bin/env node
// V5.2, «Importa una pagina di questo sito»: the fixture of ImportAddPageTest. A page of blocks, as a visitor downloads it
// (index.html + the theme's sheet + one image), converted with the converter the plugin ships
// (plugin/assets/html-import/histos-html-import.js, the copy of Histeon Publish) and added to the base in base.json.
// The test puts the site in the state base.json describes (project, styles id, styles and settings in development),
// then imports pagina-aggiunta.xbsite: no conflict; with the styles changed after the base, a conflict.
//
// To make it again (after a new copy of the converter, or a change to base.json):
//   node plugin/tests/Fixtures/add-page/make.mjs
// It writes base.json (when missing) and pagina-aggiunta.xbsite next to this file, and prints the report.
import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
const assets = path.resolve(here, "../../../assets/html-import");
const { convertToXbsite } = await import(path.join(assets, "histos-html-import.js"));
const sqlite3InitModule = (await import(path.join(assets, "sqlite3.mjs"))).default;
// In Node the module cannot fetch its WebAssembly: it is handed the bytes. OPFS is a browser's: its warnings are muted.
const sqlite = await sqlite3InitModule({ print: () => undefined, printErr: () => undefined, wasmBinary: readFileSync(path.join(assets, "sqlite3.wasm")) });

/** RFC 8785 for what the base holds (strings, integers, objects, lists), as PageProfile::canonical() writes it. */
function canonical(value) {
  if (value === null || typeof value === "boolean" || typeof value === "number") return JSON.stringify(value);
  if (typeof value === "string") return JSON.stringify(value);
  if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
  return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`).join(",")}}`;
}
const revision = (kind, payload) => createHash("sha256").update(canonical({ kind, schemaVersion: 1, payload })).digest("hex");

const basePath = path.join(here, "base.json");
if (!existsSync(basePath)) {
  const payload = {
    styles: [{ id: "style-1", selector: ".sito-testata", css: "padding:1rem" }],
    globals: [{ id: "global-1", css: "body{margin:0}" }],
    compiledCss: "",
    design: {},
    tokenOverrides: {},
    queries: {},
  };
  const base = {
    projectId: "6f1c2b9e-3d4a-4b5c-8d6e-7f8091a2b3c4",
    styles: { id: "5e0b1a8d-2c39-4a4b-9c5d-6e7f8091a2b3", kind: "styles", schemaVersion: 1, revisionHash: revision("styles", payload), payload },
    slugs: [],
    entityIds: [],
    assets: [],
    files: {},
  };
  writeFileSync(basePath, `${JSON.stringify(base, null, 2)}\n`);
}
const base = JSON.parse(readFileSync(basePath, "utf8"));

const encoder = new TextEncoder();
// A 1×1 PNG.
const png = Uint8Array.from(Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==", "base64"));
const html = `<!doctype html><html lang="it"><head><meta charset="utf-8"><title>Pagina aggiunta di prova</title>
<link rel="canonical" href="https://sito.example/pagina-aggiunta-di-prova/">
<link rel="stylesheet" href="wp-content/themes/tema/style.css"></head>
<body class="page wp-theme-tema"><main class="wp-block-group">
<header class="sito-testata"><p class="sito-testata__nome">Bottega</p></header>
<section class="wp-block-group apertura"><h1 class="wp-block-heading apertura__titolo">Corsi di falegnameria</h1>
<p class="apertura__testo">Impara a costruire un tavolo.</p>
<figure class="wp-block-image"><img src="wp-content/uploads/banco.png" alt="Il banco da lavoro" width="1" height="1"></figure></section>
</main></body></html>`;
const css = `.sito-testata{padding:2rem}.apertura{display:grid;gap:1rem}.apertura__titolo{color:#234}.apertura__testo{max-width:60ch}`;
const files = [
  { path: "index.html", bytes: encoder.encode(html) },
  { path: "wp-content/themes/tema/style.css", bytes: encoder.encode(css) },
  { path: "wp-content/uploads/banco.png", bytes: png },
];
const result = await convertToXbsite({ files, name: "Pagina aggiunta di prova", framework: "histos-css", recipes: true, addPage: { base, sourcePath: "/pagina-aggiunta-di-prova/" } }, sqlite);
writeFileSync(path.join(here, "pagina-aggiunta.xbsite"), result.archive);
process.stdout.write(`${JSON.stringify(result.addPage, null, 1)}\n`);
