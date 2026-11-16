#!/usr/bin/env node
// Copies plugin/connector/HISTOS-AI.md into histos-connector.mjs (between the MANUAL markers), so the manual
// travels with the connector even when only the .mjs is downloaded. editor/test/connector.test.ts fails when
// the two differ: run `node scripts/sync-manual.mjs` after editing the manual.
// It also embeds the documentation for agents (between the DOCS markers) from plugin/tests/fixtures/
// histos-agent-docs-v1.json, which Histeon Publish generates and this repository copies byte for byte.
//
// Both blocks are text from outside this file written into JavaScript, and found again by their markers on the next
// run, so a text must never be able to end its block or a comment. In the literals `/` is written `\/`, so no
// `// MANUAL:end` or `// DOCS:end` can appear inside one, and U+2028/U+2029, which end a `//` comment, are escaped.
// The values stay the same. A file whose markers are not exactly one pair each is refused, not patched: an older
// version of this script may have left a marker inside a literal, and replacing up to it would run the rest as code.
import { readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const MANUAL = ['// MANUAL:start', '// MANUAL:end'];
const DOCS = ['// DOCS:start', '// DOCS:end'];
const BRIDGE = '// ---------------------------------------------------------------------------------------------- bridge';
/** `/` and the two line terminators JSON leaves as they are; built from code points so this file holds neither. */
const UNSAFE = new RegExp(`[/${String.fromCharCode(0x2028, 0x2029)}]`, 'g');

/** A JSON value as a JavaScript literal with no `/` and no line terminator in it: see above. */
export function embed(value) {
  return JSON.stringify(value).replace(UNSAFE, (c) => (c === '/' ? '\\/' : `\\u${c.charCodeAt(0).toString(16)}`));
}

const count = (text, marker) => text.split(marker).length - 1;

/** `js` with `body` between the two markers; the first time `insert` places the block. */
function place(js, [start, end], body, insert) {
  const block = `${start}\n${body}\n${end}`;
  if (!count(js, start) && !count(js, end)) return insert(js, block);
  const from = js.indexOf(start);
  const to = js.indexOf(end);
  if (count(js, start) !== 1 || count(js, end) !== 1 || to < from) {
    throw new Error(`histos-connector.mjs non ha una sola coppia di marcatori ${start} … ${end}: ripristina il file dalla versione salvata e riesegui lo script.`);
  }
  return `${js.slice(0, from)}${block}${js.slice(to + end.length)}`;
}

/** The connector source `js` with the manual `md` and the documentation bundle `docs` embedded between their markers. */
export function syncConnector(js, md, docs) {
  if (typeof md !== 'string') throw new Error('HISTOS-AI.md non è un testo.');
  if (docs?.format !== 'histos-agent-docs' || docs.version !== 1 || !Array.isArray(docs.topics)) throw new Error('histos-agent-docs-v1.json non ha il formato atteso.');
  const withManual = place(js, MANUAL, `export const MANUAL = ${embed(md)};`, (text, block) => text.replace(BRIDGE, () => `${block}\n\n${BRIDGE}`));
  const next = place(withManual, DOCS, `export const DOCS = ${embed(docs.topics)};`, (text, block) => text.replace(MANUAL[1], () => `${MANUAL[1]}\n\n${block}`));
  for (const marker of [...MANUAL, ...DOCS]) {
    if (count(next, marker) !== 1) throw new Error(`histos-connector.mjs: dopo la sincronizzazione il marcatore ${marker} non c'è una volta sola.`);
  }
  return next;
}

// Run as a program (node scripts/sync-manual.mjs), not when the tests import syncConnector.
// Real paths on both sides: node runs a module from its real path, so a call through a symlink must still count.
if (process.argv[1] && realpathSync(process.argv[1]) === realpathSync(fileURLToPath(import.meta.url))) {
  const dir = fileURLToPath(new URL('../plugin/connector/', import.meta.url));
  const file = `${dir}histos-connector.mjs`;
  const docs = JSON.parse(readFileSync(fileURLToPath(new URL('../plugin/tests/fixtures/histos-agent-docs-v1.json', import.meta.url)), 'utf8'));
  writeFileSync(file, syncConnector(readFileSync(file, 'utf8'), readFileSync(`${dir}HISTOS-AI.md`, 'utf8'), docs));
  console.log('manuale e documentazione sincronizzati');
}
