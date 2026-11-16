#!/usr/bin/env node
// The manual in one HTML file (docs/manual/README.md): the chapters in docs/manual/it/ and docs/manual/en/
// (same file names), rendered from a small Markdown subset into docs/histos-manual.html — no external resources,
// works offline, Italian and English in the same file.
//   node scripts/build-manual.mjs [--check]   (--check: fails if the committed file is out of date)
import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const dir = `${root}docs/manual/`;
const out = `${root}docs/histos-manual.html`;
const LANGS = { it: 'Italiano', en: 'English' };
const UI = {
  it: { title: 'Manuale di Histeon', search: 'Cerca nel manuale…', contents: 'Indice', none: 'Nessun risultato.', top: 'Torna su', lang: 'Lingua', version: 'versione' },
  en: { title: 'Histeon manual', search: 'Search the manual…', contents: 'Contents', none: 'No results.', top: 'Back to top', lang: 'Language', version: 'version' },
};

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const slug = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/<[^>]+>/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/** Inline Markdown: code, links (http(s), mailto and in-page only), bold, italic, keys. */
function inline(text) {
  const codes = [];
  let s = text.replace(/`([^`]+)`/g, (_, c) => `\u0000${codes.push(`<code>${esc(c)}</code>`) - 1}\u0000`);
  s = esc(s);
  s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (all, label, href) => {
    const raw = href.replace(/&amp;/g, '&');
    if (!/^(https?:\/\/|mailto:|#)/.test(raw)) return label;
    const external = /^https?:/.test(raw) ? ' rel="noopener noreferrer" target="_blank"' : '';
    return `<a href="${esc(raw)}"${external}>${label}</a>`;
  });
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?=[^*\w]|$)/g, '$1<em>$2</em>');
  s = s.replace(/\[\[([^\]]+)\]\]/g, (_, k) => k.split('+').map((p) => `<kbd>${p}</kbd>`).join('+'));
  return s.replace(/\u0000(\d+)\u0000/g, (_, i) => codes[Number(i)]);
}

/** Block Markdown: headings, paragraphs, lists (two levels), fenced code, notes (> ), tables. */
function render(md, prefix, toc) {
  const lines = md.replace(/\r/g, '').split('\n');
  const html = [];
  let i = 0;
  let title = '';
  while (i < lines.length) {
    const line = lines[i];
    if (/^```/.test(line)) {
      const lang = line.slice(3).trim();
      const body = [];
      for (i++; i < lines.length && !/^```/.test(lines[i]); i++) body.push(lines[i]);
      i++;
      html.push(`<pre${lang ? ` data-lang="${esc(lang)}"` : ''}><code>${esc(body.join('\n'))}</code></pre>`);
      continue;
    }
    const h = /^(#{1,4})\s+(.*)$/.exec(line);
    if (h) {
      const level = h[1].length;
      const text = inline(h[2]);
      const id = level === 1 ? prefix : `${prefix}-${slug(h[2])}`;
      if (level === 1) title = h[2];
      if (level <= 2) toc.push({ level, id, text: h[2] });
      html.push(`<h${level} id="${id}">${text}${level > 1 ? ` <a class="anchor" href="#${id}" aria-hidden="true" tabindex="-1">#</a>` : ''}</h${level}>`);
      i++;
      continue;
    }
    if (/^\|/.test(line)) {
      const rows = [];
      for (; i < lines.length && /^\|/.test(lines[i]); i++) rows.push(lines[i].replace(/^\||\|$/g, '').split('|').map((c) => c.trim()));
      const [head, , ...body] = rows;
      html.push(`<div class="table"><table><thead><tr>${head.map((c) => `<th scope="col">${inline(c)}</th>`).join('')}</tr></thead><tbody>${body.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
      continue;
    }
    if (/^>\s?/.test(line)) {
      const body = [];
      for (; i < lines.length && /^>\s?/.test(lines[i]); i++) body.push(lines[i].replace(/^>\s?/, ''));
      html.push(`<aside class="note">${render(body.join('\n'), `${prefix}-n${i}`, []).html}</aside>`);
      continue;
    }
    if (/^\s*([-*]|\d+\.)\s+/.test(line)) {
      const ordered = /^\s*\d+\./.test(line);
      const items = [];
      for (; i < lines.length && (/^\s*([-*]|\d+\.)\s+/.test(lines[i]) || (/^\s{2,}\S/.test(lines[i]) && items.length)); i++) {
        const m = /^(\s*)([-*]|\d+\.)\s+(.*)$/.exec(lines[i]);
        if (m && m[1].length < 2) items.push({ text: m[3], sub: [] });
        else if (m) items[items.length - 1].sub.push({ text: m[3], ordered: /\d/.test(m[2]) });
        else items[items.length - 1].text += ' ' + lines[i].trim();
      }
      const tag = ordered ? 'ol' : 'ul';
      html.push(`<${tag}>${items.map((it) => `<li>${inline(it.text)}${it.sub.length ? `<${it.sub[0].ordered ? 'ol' : 'ul'}>${it.sub.map((s) => `<li>${inline(s.text)}</li>`).join('')}</${it.sub[0].ordered ? 'ol' : 'ul'}>` : ''}</li>`).join('')}</${tag}>`);
      continue;
    }
    if (line.trim() === '') {
      i++;
      continue;
    }
    const para = [];
    for (; i < lines.length && lines[i].trim() !== '' && !/^(#{1,4}\s|```|\||>|\s*([-*]|\d+\.)\s)/.test(lines[i]); i++) para.push(lines[i].trim());
    html.push(`<p>${inline(para.join(' '))}</p>`);
  }
  return { html: html.join('\n'), title };
}

function chapters(lang) {
  const folder = `${dir}${lang}/`;
  if (!existsSync(folder)) return [];
  return readdirSync(folder).filter((f) => /^\d+-[a-z0-9-]+\.md$/.test(f)).sort().map((file) => {
    const toc = [];
    const { html, title } = render(readFileSync(folder + file, 'utf8'), `${lang}-${file.replace(/^\d+-|\.md$/g, '')}`, toc);
    return { file, html, title, toc };
  });
}

const version = /\* Version:\s*(\S+)/.exec(readFileSync(`${root}plugin/xb.php`, 'utf8'))?.[1] ?? '';
const mark = readFileSync(`${root}plugin/assets/brand/histos-mark.svg`, 'utf8').replace(/<\?xml[^>]*>/, '').replace(/<title[^>]*>.*?<\/title>/s, '').replace(/aria-labelledby="[^"]*"/, 'aria-hidden="true"').replace(/role="img"/, '').trim();

const books = Object.keys(LANGS).map((lang) => ({ lang, chapters: chapters(lang) })).filter((b) => b.chapters.length);
const it = books.find((b) => b.lang === 'it');
for (const book of books) {
  if (book.lang !== 'it' && it && book.chapters.map((c) => c.file).join() !== it.chapters.map((c) => c.file).join()) {
    console.error(`docs/manual/${book.lang}/: chapters differ from docs/manual/it/`);
    process.exit(1);
  }
}

const nav = (book) => `<nav class="toc" lang="${book.lang}" data-lang="${book.lang}" aria-label="${UI[book.lang].contents}"><ol>${book.chapters.map((c) => {
  const top = c.toc[0];
  const subs = c.toc.filter((t) => t.level === 2);
  return `<li><a href="#${top.id}">${inline(top.text)}</a>${subs.length ? `<ol>${subs.map((s) => `<li><a href="#${s.id}">${inline(s.text)}</a></li>`).join('')}</ol>` : ''}</li>`;
}).join('')}</ol></nav>`;

const page = `<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Histeon — manuale / manual</title>
<style>
:root{--ink:#171A33;--paper:#FBF8F3;--card:#fff;--line:#E4DDD2;--muted:#5D6078;--accent:#C2412F;--accent-bg:#FFE9E5;--code:#F3ECE1;--focus:#3858E9}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--ink:#ECE7F0;--paper:#12142A;--card:#1B1E38;--line:#2E3254;--muted:#A9ABC4;--accent:#FF8A7A;--accent-bg:#3A2230;--code:#23264A;--focus:#8FA3FF}}
:root[data-theme="dark"]{--ink:#ECE7F0;--paper:#12142A;--card:#1B1E38;--line:#2E3254;--muted:#A9ABC4;--accent:#FF8A7A;--accent-bg:#3A2230;--code:#23264A;--focus:#8FA3FF}
*{box-sizing:border-box}
html{scroll-padding-top:16px}
body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.65 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--accent)}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
.skip{position:absolute;left:-9999px}.skip:focus{left:16px;top:16px;z-index:9;background:var(--card);padding:8px 12px}
.layout{display:grid;grid-template-columns:300px minmax(0,1fr);min-height:100vh}
.side{position:sticky;top:0;height:100vh;overflow:auto;border-right:1px solid var(--line);background:var(--card);padding:20px 18px 40px}
.brand{display:flex;gap:12px;align-items:center;margin-bottom:16px}.brand svg{width:40px;height:40px;flex:none}
.brand strong{display:block;font-size:18px}.brand span{color:var(--muted);font-size:13px}
.controls{display:grid;gap:8px;margin-bottom:16px}
.controls input,.controls select{width:100%;font:inherit;font-size:14px;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:var(--paper);color:var(--ink)}
.toc ol{list-style:none;margin:0;padding:0}.toc>ol>li{margin:2px 0}
.toc a{display:block;padding:4px 8px;border-radius:6px;color:var(--ink);text-decoration:none;font-size:14px}
.toc a:hover{background:var(--accent-bg)}.toc ol ol a{padding-left:20px;color:var(--muted);font-size:13px}
.toc a[aria-current="true"]{background:var(--accent-bg);color:var(--accent);font-weight:600}
main{padding:32px clamp(16px,5vw,64px) 80px;max-width:900px}
article{margin-bottom:56px;padding-bottom:24px;border-bottom:1px solid var(--line)}
h1{font-size:30px;line-height:1.2;margin:0 0 16px}h2{font-size:22px;margin:36px 0 10px}h3{font-size:18px;margin:26px 0 8px}h4{font-size:16px;margin:20px 0 6px}
.anchor{opacity:0;text-decoration:none;color:var(--muted);font-weight:400}h2:hover .anchor,h3:hover .anchor,.anchor:focus{opacity:1}
code{font:0.9em ui-monospace,Menlo,Consolas,monospace;background:var(--code);padding:1px 5px;border-radius:4px}
pre{background:var(--code);padding:14px 16px;border-radius:10px;overflow:auto;font-size:14px;line-height:1.5}pre code{background:none;padding:0}
kbd{font:12px ui-monospace,Menlo,Consolas,monospace;border:1px solid var(--line);border-bottom-width:2px;border-radius:4px;padding:1px 5px;background:var(--card)}
.note{border-left:4px solid var(--accent);background:var(--accent-bg);padding:4px 16px;border-radius:0 10px 10px 0;margin:16px 0}
.table{overflow:auto}table{border-collapse:collapse;width:100%;font-size:14px;margin:12px 0}th,td{border:1px solid var(--line);padding:6px 10px;text-align:left;vertical-align:top}th{background:var(--code)}
[hidden]{display:none!important}
.empty{color:var(--muted)}
.menu{display:none}
@media (max-width:860px){.layout{display:block}.side{position:static;height:auto;border-right:0;border-bottom:1px solid var(--line)}.toc{max-height:50vh;overflow:auto}main{padding-top:20px}}
@media print{.side,.anchor{display:none}.layout{display:block}main{max-width:none;padding:0}article{break-after:page;border:0}a{color:inherit}}
</style>
</head>
<body>
<a class="skip" href="#main">Vai al contenuto / Skip to content</a>
<div class="layout">
<aside class="side">
<div class="brand">${mark}<div><strong>Histeon</strong><span>${version ? `${UI.it.version} / ${UI.en.version} ${esc(version)}` : ''}</span></div></div>
<div class="controls">
<select id="lang" aria-label="Lingua / Language">${books.map((b) => `<option value="${b.lang}">${LANGS[b.lang]}</option>`).join('')}</select>
<input id="q" type="search" autocomplete="off" aria-label="${UI.it.search}" placeholder="${UI.it.search}">
</div>
${books.map(nav).join('\n')}
<p class="empty" id="none" hidden></p>
</aside>
<main id="main" tabindex="-1">
${books.map((b) => `<div class="book" lang="${b.lang}" data-lang="${b.lang}">${b.chapters.map((c) => `<article>${c.html}</article>`).join('\n')}</div>`).join('\n')}
</main>
</div>
<script>
(() => {
  const UI = ${JSON.stringify(UI)};
  const langs = ${JSON.stringify(books.map((b) => b.lang))};
  const select = document.getElementById('lang');
  const q = document.getElementById('q');
  const none = document.getElementById('none');
  const store = { get(k) { try { return localStorage.getItem(k); } catch { return null; } }, set(k, v) { try { localStorage.setItem(k, v); } catch {} } };
  const fromHash = () => { const l = location.hash.slice(1, 3); return langs.includes(l) && location.hash[3] === '-' ? l : null; };
  let lang = fromHash() || store.get('histos-manual-lang') || (navigator.language || '').slice(0, 2);
  if (!langs.includes(lang)) lang = langs.includes('en') && !(navigator.language || '').startsWith('it') ? 'en' : langs[0];
  function show(next) {
    lang = next;
    select.value = lang;
    document.documentElement.lang = lang;
    document.title = UI[lang].title;
    q.placeholder = UI[lang].search;
    q.setAttribute('aria-label', UI[lang].search);
    for (const el of document.querySelectorAll('[data-lang]')) el.hidden = el.dataset.lang !== lang;
    store.set('histos-manual-lang', lang);
    filter();
  }
  function filter() {
    const term = q.value.trim().toLowerCase();
    const book = document.querySelector('.book[data-lang="' + lang + '"]');
    const toc = document.querySelector('.toc[data-lang="' + lang + '"]');
    let shown = 0;
    for (const article of book.querySelectorAll('article')) {
      const hit = !term || article.textContent.toLowerCase().includes(term);
      article.hidden = !hit;
      const id = article.querySelector('h1').id;
      const link = toc.querySelector('a[href="#' + id + '"]');
      if (link) link.parentElement.hidden = !hit;
      if (hit) shown++;
    }
    none.hidden = shown > 0;
    none.textContent = UI[lang].none;
  }
  select.addEventListener('change', () => { show(select.value); history.replaceState(null, '', '#'); });
  q.addEventListener('input', filter);
  window.addEventListener('hashchange', () => { const l = fromHash(); if (l && l !== lang) show(l); });
  // The chapter being read, marked in the contents.
  const seen = new IntersectionObserver((entries) => {
    for (const e of entries) {
      if (!e.isIntersecting) continue;
      for (const a of document.querySelectorAll('.toc a[aria-current]')) a.removeAttribute('aria-current');
      const a = document.querySelector('.toc a[href="#' + e.target.id + '"]');
      if (a) a.setAttribute('aria-current', 'true');
    }
  }, { rootMargin: '0px 0px -70% 0px' });
  for (const h of document.querySelectorAll('main h1, main h2')) seen.observe(h);
  show(lang);
  if (location.hash) document.getElementById(location.hash.slice(1))?.scrollIntoView();
})();
</script>
</body>
</html>
`;

if (process.argv.includes('--check')) {
  if (!existsSync(out) || readFileSync(out, 'utf8') !== page) {
    console.error('docs/histos-manual.html is out of date: run node scripts/build-manual.mjs');
    process.exit(1);
  }
  console.log('manual up to date');
} else {
  writeFileSync(out, page);
  console.log(`docs/histos-manual.html: ${books.map((b) => `${b.lang} ${b.chapters.length} chapters`).join(', ')}`);
}
