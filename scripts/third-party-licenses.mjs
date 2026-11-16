#!/usr/bin/env node
// The licences of the third-party code bundled in the builder (editor/): every runtime dependency of editor/package.json
// and theirs, with the licence text each package ships. scripts/package.sh writes it next to the bundles, because MIT,
// BSD and Apache-2.0 ask that the notice travel with the code.
//
//   node scripts/third-party-licenses.mjs <out.md> [workspace dir, default editor]
import { existsSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const [out, workspace = "editor"] = process.argv.slice(2);
if (!out) {
  console.error("Uso: node scripts/third-party-licenses.mjs <out.md> [workspace]");
  process.exit(1);
}

function locate(name, from) {
  for (let dir = from; ; dir = path.dirname(dir)) {
    const candidate = path.join(dir, "node_modules", name);
    if (existsSync(path.join(candidate, "package.json"))) return candidate;
    if (dir === root || dir === path.dirname(dir)) return null;
  }
}

const seen = new Map();
const missing = [];
function visit(name, from) {
  const dir = locate(name, from);
  if (!dir) {
    missing.push(name);
    return;
  }
  if (seen.has(dir)) return;
  const meta = JSON.parse(readFileSync(path.join(dir, "package.json"), "utf8"));
  seen.set(dir, meta);
  for (const dependency of Object.keys(meta.dependencies ?? {})) visit(dependency, dir);
}

const base = path.join(root, workspace);
const own = JSON.parse(readFileSync(path.join(base, "package.json"), "utf8"));
for (const dependency of Object.keys(own.dependencies ?? {})) visit(dependency, base);
if (missing.length) {
  console.error(`Dipendenze non installate: ${missing.join(", ")}`);
  process.exit(1);
}

const lines = [
  "# Codice di terzi nel builder di Histeon",
  "",
  "Le librerie che il builder (`assets/builder/`, `assets/gutenberg/`) contiene impacchettate, con le loro dipendenze: versione, licenza e testo della licenza come lo distribuisce il pacchetto. Tutte sono compatibili con la GPL-3.0-or-later di Histeon. I codec delle immagini hanno le loro licenze in `assets/builder/media/licenses/`.",
  "",
];
for (const [dir, meta] of [...seen].sort((a, b) => a[1].name.localeCompare(b[1].name))) {
  const license = typeof meta.license === "string" ? meta.license : JSON.stringify(meta.license ?? "non dichiarata");
  const author = typeof meta.author === "string" ? meta.author : meta.author?.name;
  lines.push(`## ${meta.name} ${meta.version}`, "", `Licenza: ${license}.${author ? ` Autore: ${author}.` : ""}`, "");
  const files = readdirSync(dir).filter((f) => /^(licen[cs]e|copying|notice)/i.test(f)).sort();
  if (files.length === 0) lines.push("Il pacchetto non distribuisce un file di licenza: vale la licenza dichiarata sopra.", "");
  for (const file of files) lines.push("```", readFileSync(path.join(dir, file), "utf8").trim(), "```", "");
}
writeFileSync(out, `${lines.join("\n")}\n`);
console.log(`${seen.size} pacchetti in ${out}`);
