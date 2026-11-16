// Small-model bench (I8, docs/qa/banco-modelli-piccoli.md): the checks that say whether a task was done, read on
// the document as saved (block markup) and on the site's styles. Pure functions: no network, no WordPress.

/** WordPress block comments as a tree: {name, attrs, children}. Text between blocks is ignored. */
export function parseBlocks(markup) {
  const root = { name: 'root', attrs: {}, children: [] };
  const stack = [root];
  const re = /<!--\s+(\/)?wp:([a-z0-9_-]+\/[a-z0-9_-]+|[a-z0-9_-]+)(\s+(\{[\s\S]*?\}))?\s*(\/)?-->/g;
  for (const m of markup.matchAll(re)) {
    const [, closing, name, , json, selfClosing] = m;
    if (closing) {
      if (stack.length > 1 && stack.at(-1).name === name) stack.pop();
      continue;
    }
    let attrs = {};
    try {
      attrs = json ? JSON.parse(json) : {};
    } catch {
      attrs = { unreadable: true };
    }
    const block = { name, attrs, children: [] };
    stack.at(-1).children.push(block);
    if (!selfClosing) stack.push(block);
  }
  return root.children;
}

export function* walk(blocks) {
  for (const b of blocks) {
    yield b;
    yield* walk(b.children);
  }
}

const classes = (b) => (typeof b.attrs?.attributes?.class === 'string' ? b.attrs.attributes.class.split(/\s+/) : []);
/** The text inside a block: its own and its children's, tags stripped. */
export function textOf(block) {
  if (block.name === 'xb/text') return String(block.attrs.content ?? '').replace(/<[^>]*>/g, '');
  return block.children.map(textOf).join(' ').replace(/\s+/g, ' ').trim();
}

/**
 * An element matching a small selector: tag, classes and attributes, e.g. {tag: 'a', class: 'x', attr: {href: '/c/'}}.
 * Attribute values and `text` are regular expressions (the whole attribute must match).
 */
export function matches(block, want) {
  if (block.name !== 'xb/element') return false;
  if (want.tag && block.attrs.tag !== want.tag) return false;
  if (want.class && !classes(block).includes(want.class)) return false;
  for (const [key, value] of Object.entries(want.attr ?? {})) {
    const got = block.attrs.attributes?.[key];
    if (typeof got !== 'string' || !new RegExp(`^(?:${value})$`).test(got)) return false;
  }
  if (want.text && !new RegExp(want.text, 'i').test(textOf(block))) return false;
  return true;
}

/** Declarations of a selector in the site's styles (styles and global sheets), lowercased. */
export function declarations(styles, selector) {
  const out = {};
  for (const entry of [...(styles.styles ?? []), ...(styles.globals ?? [])]) {
    const css = String(entry.css ?? '');
    const sel = String(entry.selector ?? '').trim();
    const blocks = sel ? [[sel, css]] : [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map((m) => [m[1].trim(), m[2]]);
    for (const [s, body] of blocks) {
      if (s !== selector) continue;
      for (const part of body.split(';')) {
        const at = part.indexOf(':');
        if (at > 0) out[part.slice(0, at).trim().toLowerCase()] = part.slice(at + 1).trim().toLowerCase();
      }
    }
  }
  return out;
}

/**
 * One criterion of a task, evaluated: {ok, why}. Kinds:
 * - element {select, min?}: at least `min` elements match;
 * - inside {select, has}: an element matching `select` contains one matching `has`;
 * - loop {source?, as?, has?}: a loop whose source matches (`{kind, id?, expr?}` as regexes) and, inside, `has`;
 * - else {loop?, text}: a loop with an «else» branch (a last branch without a test) showing `text`;
 * - component {ref, min?, props?}: instances of a component, each with these props set;
 * - style {selector, has: {property: regex}}: declarations in the site's styles;
 * - no-raw: no raw HTML blocks (what the parser could not turn into elements).
 */
export function criterion(blocks, styles, c) {
  const all = [...walk(blocks)];
  const el = (want) => all.filter((b) => matches(b, want));
  switch (c.kind) {
    case 'element': {
      const n = el(c.select).length;
      return { ok: n >= (c.min ?? 1), why: `${n} elementi ${JSON.stringify(c.select)}` };
    }
    case 'inside': {
      const ok = el(c.select).some((b) => [...walk(b.children)].some((x) => matches(x, c.has)));
      return { ok, why: ok ? 'trovato' : `nessun ${JSON.stringify(c.has)} dentro ${JSON.stringify(c.select)}` };
    }
    case 'loop': {
      const loops = all.filter((b) => b.name === 'xb/loop').filter((b) => {
        const s = b.attrs.source ?? {};
        return Object.entries(c.source ?? {}).every(([k, re]) => new RegExp(re, 'i').test(String(s[k] ?? '')));
      });
      const ok = loops.some((b) => !c.has || [...walk(b.children)].some((x) => (c.has.text ? new RegExp(c.has.text).test(x.name === 'xb/text' ? String(x.attrs.content) : '') || matches(x, c.has) : matches(x, c.has))));
      return { ok, why: `${loops.length} loop con la sorgente richiesta` };
    }
    case 'else': {
      const ok = all.some((b) => b.name === 'xb/loop' && b.children.some((x) => x.name === 'xb/branch' && x.attrs.test === undefined && new RegExp(c.text, 'i').test(textOf(x))));
      return { ok, why: ok ? 'ramo «altrimenti» trovato' : 'nessun loop con {:else} e quel testo' };
    }
    case 'component': {
      const uses = all.filter((b) => b.name === 'xb/component' && b.attrs.ref === c.ref && (c.props ?? []).every((p) => p in (b.attrs.props ?? {})));
      return { ok: uses.length >= (c.min ?? 1), why: `${uses.length} istanze di ${c.ref}` };
    }
    case 'style': {
      const got = declarations(styles, c.selector);
      const missing = Object.entries(c.has).filter(([p, re]) => !new RegExp(re, 'i').test(got[p] ?? ''));
      return { ok: missing.length === 0, why: missing.length ? `mancano ${missing.map(([p]) => p).join(', ')} in ${c.selector}` : 'stile trovato' };
    }
    case 'no-raw': {
      const n = all.filter((b) => b.name === 'xb/raw').length;
      return { ok: n === 0, why: `${n} blocchi di HTML grezzo` };
    }
    default:
      return { ok: false, why: `criterio sconosciuto: ${c.kind}` };
  }
}

/** A task's result: every criterion, and the share passed. */
export function score(task, markup, styles) {
  const blocks = parseBlocks(markup);
  const results = task.criteria.map((c) => ({ ...criterion(blocks, styles, c), label: c.label ?? c.kind }));
  return { task: task.id, passed: results.filter((r) => r.ok).length, total: results.length, results };
}
