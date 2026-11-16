#!/usr/bin/env node
// The rebuild documentation as small cards (docs/ricostruire/), written so that a model with a few billion parameters
// and a short context window can rebuild, fix and change the project: one card per piece, each under a word limit,
// with the files it covers, at most three cards to read first, and how to test it. This script checks the rules,
// prints the context a task needs, finds the card of a file and rebuilds docs/RICOSTRUIRE-DA-ZERO.md (the whole
// documentation in one file, for models with a long context) from the cards. Node only, no dependencies: the same
// file lives in every repository that has the cards.
//
//   node scripts/schede.mjs controlla              every rule; exit 1 on the first broken one
//   node scripts/schede.mjs contesto <scheda>...   the cards to read first, then the card, with the glossary terms
//   node scripts/schede.mjs dove <file|parola>     which cards cover a file, or name a word
//   node scripts/schede.mjs unisci                 rewrites docs/RICOSTRUIRE-DA-ZERO.md from the cards
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, normalize, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = process.env.SCHEDE_ROOT ? resolve(process.env.SCHEDE_ROOT) : resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DIR = join(ROOT, 'docs/ricostruire');
const MERGED = join(ROOT, 'docs/RICOSTRUIRE-DA-ZERO.md');
const KINDS = { schede: 'scheda', ricette: 'ricetta' };
const SECTIONS = {
  scheda: ['In breve', 'Regole da non rompere', 'Come provarlo'],
  ricetta: ['Quando usarla', 'Passi', 'Fatto quando'],
};
const TOP = { 'LEGGIMI.md': 1200, 'MAPPA.md': 3000, 'GLOSSARIO.md': 3000, 'COME-SI-SCRIVE.md': 1800 };
// Words that send the reader somewhere it cannot see: a card is read alone, often without the one before it.
const VAGUE = /\b(vedi sopra|vedi sotto|qui sopra|qui sotto|più sopra|più sotto|come detto|come già detto|di cui sopra|di cui sotto|il capitolo precedente|la sezione precedente|la scheda precedente)\b|§\s*\d/iu;

/** Italian text averages about 3.2 characters per token in the tokenizers of the small open models. */
const tokens = (text) => Math.round(text.length / 3.2);
const words = (text) => (text.match(/[\p{L}\p{N}_][\p{L}\p{N}_'’.\-/]*/gu) || []).length;

function config() {
  const file = join(DIR, 'schede.json');
  if (!existsSync(file)) throw new Error(`Manca ${relative(ROOT, file)}`);
  const c = JSON.parse(readFileSync(file, 'utf8'));
  return {
    progetto: c.progetto,
    limite_parole: c.limite_parole ?? 2000,
    limite_contesto: c.limite_contesto ?? 7000,
    limite_frase: c.limite_frase ?? 45,
    copertura: c.copertura ?? [],
    ignora: c.ignora ?? [],
    percorsi_ammessi: c.percorsi_ammessi ?? [],
    funzioni: c.funzioni ?? [],
  };
}

/** A shell-like glob (*, **, ?) as a regular expression over a path relative to the root. */
function glob(pattern) {
  let re = '';
  for (let i = 0; i < pattern.length; i++) {
    const ch = pattern[i];
    if (ch === '*' && pattern[i + 1] === '*') { re += pattern[i + 2] === '/' ? '(?:.*/)?' : '.*'; i += pattern[i + 2] === '/' ? 2 : 1; }
    else if (ch === '*') re += '[^/]*';
    else if (ch === '?') re += '[^/]';
    else re += ch.replace(/[.+^${}()|[\]\\]/g, '\\$&');
  }
  return new RegExp(`^${re}$`);
}

/** The files of the repository: tracked, plus the new ones not ignored yet, so the cards pass before their commit. */
function tracked() {
  try {
    return execFileSync('git', ['-C', ROOT, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], { encoding: 'utf8' }).split('\0').filter(Boolean);
  } catch {
    return [];
  }
}

function commitExists(sha) {
  try {
    execFileSync('git', ['-C', ROOT, 'cat-file', '-e', `${sha}^{commit}`], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

/** The text outside fenced code blocks, line by line, with the line numbers kept. */
function prose(text) {
  let fence = false;
  return text.split('\n').map((line, i) => {
    if (/^\s*(```|~~~)/.test(line)) { fence = !fence; return [i + 1, '', line]; }
    return [i + 1, fence ? '' : line.replace(/`[^`]*`/g, 'X'), line];
  });
}

function parseCard(kind, name) {
  const path = join(DIR, kind, name);
  const text = readFileSync(path, 'utf8');
  const lines = text.split('\n');
  const head = lines[0].match(/^# (\d{2,3}) · (.+)$/u);
  const field = (label) => {
    const line = lines.slice(1, 12).find((l) => l.startsWith(`- ${label}:`));
    return line === undefined ? undefined : line.slice(label.length + 3).trim();
  };
  const links = (value) => [...(value || '').matchAll(/\]\(([^)#\s]+)(?:#[^)]*)?\)/g)].map((m) => m[1]);
  const id = name.replace(/\.md$/, '');
  return {
    kind,
    type: KINDS[kind],
    id,
    key: `${kind}/${id}`,
    name,
    path,
    text,
    lines,
    head,
    title: head ? head[2] : lines[0].replace(/^#\s*/, ''),
    tipo: field('Tipo'),
    files: (field('File') || '').match(/`[^`]+`/g)?.map((f) => f.slice(1, -1)) ?? [],
    fileField: field('File'),
    before: links(field('Prima leggi')).map((l) => normalize(join(kind, l)).replace(/\.md$/, '')),
    beforeField: field('Prima leggi'),
    commit: (field('Verificata sul commit') || '').replace(/`/g, ''),
  };
}

function load() {
  const cards = [];
  for (const kind of Object.keys(KINDS)) {
    const dir = join(DIR, kind);
    if (!existsSync(dir)) continue;
    for (const name of readdirSync(dir).filter((n) => n.endsWith('.md')).sort()) cards.push(parseCard(kind, name));
  }
  return cards;
}

/** Glossary entries: one per line, `- **Term**: definition` (several spellings of the term separated by « / »). */
function glossary() {
  const file = join(DIR, 'GLOSSARIO.md');
  if (!existsSync(file)) return [];
  return readFileSync(file, 'utf8').split('\n').flatMap((line) => {
    const m = line.match(/^- \*\*(.+?)\*\*:\s*(.+)$/u);
    return m ? [{ terms: m[1].split(' / ').map((t) => t.trim()), line }] : [];
  });
}

function check() {
  const c = config();
  const errors = [];
  const err = (file, line, msg) => errors.push(`${relative(ROOT, file)}${line ? `:${line}` : ''}: ${msg}`);
  const files = tracked();
  const fileSet = new Set(files);
  const tops = new Set(files.map((f) => f.split('/')[0]));
  const allowed = c.percorsi_ammessi.map(glob);
  const cards = load();
  const byKey = new Map(cards.map((card) => [card.key, card]));

  for (const [name, limit] of Object.entries(TOP)) {
    const file = join(DIR, name);
    if (!existsSync(file)) { err(file, 0, 'manca'); continue; }
    const n = words(readFileSync(file, 'utf8'));
    if (n > limit) err(file, 0, `${n} parole, il limite è ${limit}`);
  }
  if (!cards.some((card) => card.kind === 'schede')) err(join(DIR, 'schede'), 0, 'nessuna scheda');
  if (!cards.some((card) => card.kind === 'ricette')) err(join(DIR, 'ricette'), 0, 'nessuna ricetta');

  const numbers = new Map();
  for (const card of cards) {
    const { path } = card;
    if (!/^\d{2,3}-[a-z0-9-]+\.md$/.test(card.name)) err(path, 0, 'il nome va scritto NN-parole-minuscole.md');
    if (!card.head) err(path, 1, 'la prima riga va scritta «# NN · Titolo»');
    else if (!card.name.startsWith(`${card.head[1]}-`)) err(path, 1, `il numero del titolo (${card.head[1]}) non è quello del file`);
    const num = `${card.kind}/${card.name.split('-')[0]}`;
    if (numbers.has(num)) err(path, 1, `stesso numero di ${numbers.get(num)}`);
    numbers.set(num, card.name);
    if (card.tipo !== card.type) err(path, 0, `manca la riga «- Tipo: ${card.type}»`);
    if (card.type === 'scheda' && card.fileField === undefined) err(path, 0, 'manca la riga «- File:» (scrivi «nessuno» se la scheda non ha file)');
    if (card.beforeField === undefined) err(path, 0, 'manca la riga «- Prima leggi:» (scrivi «niente» se si legge da sola)');
    if (card.before.length > 3) err(path, 0, `«Prima leggi» ha ${card.before.length} schede, al massimo 3`);
    for (const b of card.before) if (!byKey.has(b)) err(path, 0, `«Prima leggi» nomina ${b}.md, che non esiste`);
    if (!/^[0-9a-f]{7,40}$/.test(card.commit)) err(path, 0, 'manca la riga «- Verificata sul commit: `abc1234`»');
    else if (!commitExists(card.commit)) err(path, 0, `il commit ${card.commit} non esiste in questo repository`);
    for (const f of card.files) {
      if (f.includes('*')) { if (!files.some((t) => glob(f).test(t))) err(path, 0, `«File» nomina ${f}, che non trova nessun file`); }
      else if (!fileSet.has(f) && !files.some((t) => t.startsWith(f.endsWith('/') ? f : `${f}/`))) err(path, 0, `«File» nomina ${f}, che non è nel repository`);
    }
    const n = words(card.text);
    if (n > c.limite_parole) err(path, 0, `${n} parole, il limite è ${c.limite_parole}: dividila in due schede`);
    const headings = card.lines.filter((l) => l.startsWith('## ')).map((l) => l.slice(3).trim());
    for (const s of SECTIONS[card.type]) if (!headings.includes(s)) err(path, 0, `manca la sezione «## ${s}»`);
  }

  // The context of a card (it and every card it needs, transitively) must fit a short window; no cycles.
  for (const card of cards) {
    const seen = new Set();
    const stack = [];
    let total = 0;
    const visit = (key) => {
      if (stack.includes(key)) { err(card.path, 0, `giro chiuso in «Prima leggi»: ${[...stack, key].join(' → ')}`); return; }
      if (seen.has(key) || !byKey.has(key)) return;
      stack.push(key);
      byKey.get(key).before.forEach(visit);
      stack.pop();
      seen.add(key);
      total += words(byKey.get(key).text);
    };
    visit(card.key);
    if (total > c.limite_contesto) err(card.path, 0, `con le schede da leggere prima fa ${total} parole, il limite è ${c.limite_contesto}`);
  }

  // Every rule about the prose, for all the files: links resolve, paths exist, no vague pointers, short sentences.
  const docs = [...Object.keys(TOP).map((n) => join(DIR, n)).filter(existsSync), ...cards.map((card) => card.path)];
  const deny = denyWords();
  for (const file of docs) {
    const raw = readFileSync(file, 'utf8');
    // Links and paths inside code blocks are examples, not references.
    const text = prose(raw).map(([, line, original]) => (line === '' && original !== '' ? '' : original)).join('\n');
    for (const m of text.matchAll(/\]\(([^)\s]+)\)/g)) {
      const target = m[1].split('#')[0];
      if (!target || /^[a-z]+:/.test(target)) continue;
      const line = text.slice(0, m.index).split('\n').length;
      const abs = resolve(dirname(file), target);
      if (!existsSync(abs)) err(file, line, `il collegamento ${m[1]} non porta a nessun file`);
    }
    for (const m of text.matchAll(/`([^`\s]+)`/g)) {
      const p = m[1].replace(/[:#(].*$/, '');
      if (!p.includes('/') || /[<>{}~$*]/.test(p) || p.startsWith('/')) continue;
      const rel = p.replace(/^\.\//, '');
      if (!tops.has(rel.split('/')[0]) || allowed.some((a) => a.test(rel))) continue;
      if (fileSet.has(rel) || files.some((t) => t.startsWith(rel.endsWith('/') ? rel : `${rel}/`))) continue;
      err(file, text.slice(0, m.index).split('\n').length, `il percorso ${p} non è nel repository`);
    }
    for (const [n, line, original] of prose(raw)) {
      if (VAGUE.test(line)) err(file, n, `rimando che chi legge una scheda da sola non può seguire: «${line.match(VAGUE)[0]}»`);
      if (/\/home\/[a-z][a-z0-9_-]+\/|\/Users\/[A-Za-z][A-Za-z0-9_-]+\//.test(original) && !/\/home\/(user|you|tu|nome|example)\//.test(original)) err(file, n, 'percorso con la cartella home: scrivi ~');
      const lower = original.toLowerCase();
      for (const w of deny) if (lower.includes(w)) err(file, n, 'contiene una parola della lista vietata');
      if (line.startsWith('|') || line.startsWith('#')) continue;
      for (const sentence of line.split(/(?<=[.!?;:])\s+/)) {
        const k = words(sentence);
        if (k > c.limite_frase) err(file, n, `una frase di ${k} parole, il limite è ${c.limite_frase}: spezzala`);
      }
    }
  }

  // The map names every card; every file the configuration asks for belongs to a card.
  const map = existsSync(join(DIR, 'MAPPA.md')) ? readFileSync(join(DIR, 'MAPPA.md'), 'utf8') : '';
  for (const card of cards) if (!map.includes(`](${card.kind}/${card.name})`)) err(join(DIR, 'MAPPA.md'), 0, `non porta a ${card.kind}/${card.name}`);
  const owned = cards.flatMap((card) => card.files);
  const ignore = c.ignora.map(glob);
  const cover = c.copertura.map(glob);
  for (const f of files) {
    if (!cover.some((g) => g.test(f)) || ignore.some((g) => g.test(f))) continue;
    const ok = owned.some((o) => o === f || (o.includes('*') ? glob(o).test(f) : f.startsWith(o.endsWith('/') ? o : `${o}/`)));
    if (!ok) err(join(DIR, 'schede.json'), 0, `il file ${f} non è nella riga «File» di nessuna scheda`);
  }

  // Every named function of the files in «funzioni» is named in a card: a small model rewrites only what it reads.
  const cardText = cards.map((card) => card.text).join('\n');
  const named = new Set(cardText.match(/[A-Za-z_$][\w$]*/g));
  const want = c.funzioni.map(glob);
  for (const f of files) {
    if (!want.some((g) => g.test(f)) || !existsSync(join(ROOT, f))) continue;
    for (const name of functionNames(f, readFileSync(join(ROOT, f), 'utf8'))) {
      if (!named.has(name)) err(join(ROOT, f), 0, `la funzione ${name} non è nominata in nessuna scheda: scrivi che cosa riceve, che cosa restituisce e che cosa scarta`);
    }
  }

  const terms = glossary();
  if (existsSync(join(DIR, 'GLOSSARIO.md')) && terms.length === 0) err(join(DIR, 'GLOSSARIO.md'), 0, 'nessuna voce scritta «- **Termine**: definizione»');
  if (existsSync(MERGED) && readFileSync(MERGED, 'utf8') !== merged(cards)) err(MERGED, 0, 'non è aggiornato: node scripts/schede.mjs unisci');
  else if (!existsSync(MERGED)) err(MERGED, 0, 'manca: node scripts/schede.mjs unisci');

  if (errors.length) {
    for (const e of errors) console.log(`errore  ${e}`);
    console.log(`\n${errors.length} errori.`);
    return 1;
  }
  const total = cards.reduce((sum, card) => sum + words(card.text), 0);
  console.log(`Schede in regola: ${cards.filter((x) => x.kind === 'schede').length} schede, ${cards.filter((x) => x.kind === 'ricette').length} ricette, ${total} parole.`);
  return 0;
}

/** The named functions and methods of a PHP, JavaScript or Python file (not the magic ones like __construct). */
function functionNames(file, src) {
  const patterns = /\.php$/.test(file)
    ? [/^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+&?([A-Za-z_]\w*)\s*\(/gm]
    : /\.py$/.test(file)
      ? [/^\s*(?:async\s+)?def\s+([A-Za-z_]\w*)\s*\(/gm]
      : /\.(m?js|ts)$/.test(file)
        ? [/^\s*(?:export\s+)?(?:default\s+)?(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*)\s*\(/gm, /^\s*(?:export\s+)?const\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>/gm]
        : [];
  const names = new Set();
  for (const re of patterns) for (const m of src.matchAll(re)) if (!m[1].startsWith('__')) names.add(m[1]);
  return [...names];
}

function denyWords() {
  const file = process.env.XB_PUBLIC_DENY || join(homedir(), '.config/histos/public-deny.txt');
  if (!existsSync(file)) return [];
  return readFileSync(file, 'utf8').split('\n').map((l) => l.trim().toLowerCase()).filter((l) => l && !l.startsWith('#'));
}

function find(cards, query) {
  const q = query.replace(/\.md$/, '');
  const hits = cards.filter((card) => card.id === q || card.key === q || card.id.startsWith(`${q}-`) || card.key.startsWith(`${q}-`));
  if (hits.length === 1) return hits[0];
  // A bare number is a card: a recipe is asked for as ricette/NN.
  const cardsOnly = hits.filter((card) => card.kind === 'schede');
  if (cardsOnly.length === 1 && !q.includes('/')) return cardsOnly[0];
  if (hits.length > 1) throw new Error(`«${query}» è ambiguo: ${hits.map((h) => h.key).join(', ')}`);
  throw new Error(`Nessuna scheda «${query}». Le schede sono elencate in docs/ricostruire/MAPPA.md`);
}

function context(queries) {
  const cards = load();
  const byKey = new Map(cards.map((card) => [card.key, card]));
  const order = [];
  const visit = (key) => {
    if (order.includes(key) || !byKey.has(key)) return;
    byKey.get(key).before.forEach(visit);
    order.push(key);
  };
  queries.forEach((q) => visit(find(cards, q).key));
  const body = order.map((key) => byKey.get(key).text.trim()).join('\n\n---\n\n');
  const lower = body.toLowerCase();
  const terms = glossary().filter((g) => g.terms.some((t) => lower.includes(t.toLowerCase()))).map((g) => g.line);
  const parts = [];
  if (terms.length) parts.push(`# Parole da sapere\n\n${terms.join('\n')}`);
  parts.push(body);
  const out = parts.join('\n\n---\n\n');
  console.log(`<!-- ${order.join(', ')}: ${words(out)} parole, circa ${tokens(out)} token -->\n`);
  console.log(out);
}

function where(query) {
  const cards = load();
  const owners = cards.filter((card) => card.files.some((o) => o === query || (o.includes('*') ? glob(o).test(query) : query.startsWith(o.endsWith('/') ? o : `${o}/`))));
  if (owners.length) {
    console.log(`${query} è nella riga «File» di:`);
    for (const card of owners) console.log(`  ${card.key}  ${card.title}`);
    return;
  }
  const q = query.toLowerCase();
  const hits = cards.map((card) => [card, card.text.toLowerCase().split(q).length - 1]).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]);
  if (!hits.length) { console.log(`Nessuna scheda nomina «${query}».`); return; }
  console.log(`Schede che nominano «${query}» (le più fitte prima):`);
  for (const [card, n] of hits.slice(0, 10)) console.log(`  ${card.key}  ${card.title}  (${n})`);
}

/** The whole documentation in one file: the top files, then every card, with the links turned into anchors. */
function merged(cards) {
  const c = config();
  const sources = [
    ...Object.keys(TOP).map((name) => join(DIR, name)).filter(existsSync).map((path) => ({ path, text: readFileSync(path, 'utf8') })),
    ...cards,
  ];
  const anchors = new Map(sources.map(({ path }) => [path, relative(DIR, path).replace(/\.md$/, '').replace(/\//g, '-').toLowerCase()]));
  // A link to a card or a top file becomes its anchor; a link to any other file is rewritten from docs/.
  const relink = (text, from) => text.replace(/\]\(([^)\s#]+)(#[^)]*)?\)/g, (m, target) => {
    if (/^[a-z]+:/.test(target)) return m;
    const abs = resolve(dirname(from), target);
    if (anchors.has(abs)) return `](#${anchors.get(abs)})`;
    return `](${relative(dirname(MERGED), abs)})`;
  });
  // One level down, so the merged file has a single title; headings inside code blocks are not headings.
  const demote = (text) => {
    let fence = false;
    return text.split('\n').map((line) => {
      if (/^\s*(```|~~~)/.test(line)) fence = !fence;
      return !fence && /^#{1,5} /.test(line) ? `#${line}` : line;
    }).join('\n');
  };
  const parts = [
    `# ${c.progetto}: come ricostruirlo da zero\n\n`
    + 'Questo file è generato da `node scripts/schede.mjs unisci` con le schede in `docs/ricostruire/`: non modificarlo a mano.\n'
    + 'Un modello con poco contesto legge le schede una alla volta, partendo da `docs/ricostruire/LEGGIMI.md`. '
    + 'Un modello con molto contesto può leggere qui tutto insieme.',
  ];
  for (const { path, text } of sources) parts.push(`<a id="${anchors.get(path)}"></a>\n\n${demote(relink(text.trim(), path))}`);
  return `${parts.join('\n\n---\n\n')}\n`;
}

function main(argv) {
  const [command, ...rest] = argv;
  switch (command) {
    case 'controlla': return check();
    case 'contesto': if (!rest.length) throw new Error('Uso: node scripts/schede.mjs contesto <scheda>...'); context(rest); return 0;
    case 'dove': if (!rest.length) throw new Error('Uso: node scripts/schede.mjs dove <file|parola>'); where(rest.join(' ')); return 0;
    case 'unisci': writeFileSync(MERGED, merged(load())); console.log(`Scritto ${relative(ROOT, MERGED)}.`); return 0;
    default:
      console.log(readFileSync(fileURLToPath(import.meta.url), 'utf8').split('\n').slice(1, 13).map((l) => l.replace(/^\/\/ ?/, '')).join('\n'));
      return command ? 1 : 0;
  }
}

try {
  process.exitCode = main(process.argv.slice(2));
} catch (e) {
  console.error(e.message);
  process.exitCode = 1;
}
