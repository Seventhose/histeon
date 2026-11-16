#!/usr/bin/env node
// Measures built pages as a visitor's browser sees them (doc 26 of Histeon Publish, voce 223): the same page made
// with Etch, with Histeon, through Static Bridge or Histeon Publish, side by side. Chromium from the e2e workspace,
// a new context per run (cold cache), the median of the runs.
//
//   node dev/bench-front.mjs [--runs 5] [--mobile] [--json out.json] label=https://… [label=https://…] …
//
// For each page: TTFB, first contentful paint, largest contentful paint, layout shift, DOM elements, requests,
// the bytes sent for HTML, CSS and JavaScript (compressed when the server compresses), the CSS and JavaScript the
// browser reads (files plus what is inline in the page, not compressed), and the rest. Only public pages:
// no login, no cookies. A local site with a self-signed certificate is accepted. One unmeasured visit first warms
// the server (opcache, object cache), as on a site that has visitors. PHP time and database queries come from the
// comment that dev/bench-server.php writes on a test copy; without it they stay empty.
import { chromium, devices } from 'playwright';
import { writeFileSync } from 'node:fs';

const args = process.argv.slice(2);
let runs = 5;
let mobile = false;
let json = null;
const pages = [];
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--runs') runs = Math.max(1, Math.min(20, Number(args[++i]) || 5));
  else if (a === '--mobile') mobile = true;
  else if (a === '--json') json = args[++i];
  else if (/^[^=]+=https?:\/\//.test(a)) pages.push({ label: a.slice(0, a.indexOf('=')), url: a.slice(a.indexOf('=') + 1) });
  else {
    process.stderr.write(`Argomento non capito: ${a}\n`);
    process.exit(1);
  }
}
if (!pages.length) {
  process.stderr.write('Uso: node dev/bench-front.mjs [--runs 5] [--mobile] [--json out.json] etichetta=https://… …\n');
  process.exit(1);
}

/** What the page itself reports once it has settled. */
async function measure(browser, url) {
  const context = await browser.newContext({ ...(mobile ? devices['Pixel 7'] : { viewport: { width: 1366, height: 900 } }), ignoreHTTPSErrors: true, serviceWorkers: 'block' });
  const page = await context.newPage();
  await page.addInitScript(() => {
    window.__bench = { lcp: 0, cls: 0 };
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) window.__bench.lcp = e.startTime;
    }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) if (!e.hadRecentInput) window.__bench.cls += e.value;
    }).observe({ type: 'layout-shift', buffered: true });
  });
  // Bytes as sent (compressed when the server compresses) and, for CSS and JavaScript, as the browser reads them.
  const sent = { html: 0, css: 0, js: 0, other: 0 };
  const read = { css: 0, js: 0 };
  const pending = [];
  let requests = 0;
  page.on('response', (response) => {
    requests += 1;
    pending.push((async () => {
      const type = response.request().resourceType();
      const key = type === 'document' ? 'html' : type === 'stylesheet' ? 'css' : type === 'script' ? 'js' : 'other';
      const body = await response.body().then((b) => b.length).catch(() => 0);
      const sizes = await response.request().sizes().catch(() => null);
      sent[key] += sizes ? sizes.responseBodySize : body;
      if (key in read) read[key] += body;
    })());
  });
  await page.goto(url, { waitUntil: 'load', timeout: 60_000 });
  await page.waitForTimeout(1500); // LCP and late shifts settle.
  await Promise.all(pending);
  const result = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const fcp = performance.getEntriesByName('first-contentful-paint')[0];
    const inlineCss = [...document.querySelectorAll('style')].reduce((n, s) => n + (s.textContent?.length ?? 0), 0);
    const inlineJs = [...document.querySelectorAll('script:not([src])')].reduce((n, s) => n + (s.textContent?.length ?? 0), 0);
    const walker = document.createTreeWalker(document, NodeFilter.SHOW_COMMENT);
    let server = null;
    while (walker.nextNode()) server = /^ xb-bench php=([\d.]+) query=(\d+) $/.exec(walker.currentNode.data) ?? server;
    return {
      ttfb: nav ? nav.responseStart - nav.requestStart : 0,
      fcp: fcp ? fcp.startTime : 0,
      lcp: window.__bench.lcp,
      cls: window.__bench.cls,
      dom: document.getElementsByTagName('*').length,
      inlineCss,
      inlineJs,
      php: server ? Number(server[1]) : null,
      queries: server ? Number(server[2]) : null,
    };
  });
  await context.close();
  const { php, queries, ...rest } = result;
  return {
    ...rest,
    php,
    queries,
    requests,
    sentBytes: sent.html + sent.css + sent.js,
    htmlBytes: sent.html,
    cssBytes: read.css + result.inlineCss,
    jsBytes: read.js + result.inlineJs,
    otherBytes: sent.other,
  };
}

const median = (values) => {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
};

const browser = await chromium.launch();
const results = [];
try {
  for (const { label, url } of pages) {
    await measure(browser, url);
    const samples = [];
    for (let i = 0; i < runs; i++) samples.push(await measure(browser, url));
    const out = { label, url, runs };
    for (const key of Object.keys(samples[0])) out[key] = samples[0][key] === null ? null : median(samples.map((s) => s[key]));
    results.push(out);
    process.stderr.write(`${label}: fatto (${runs} misure)\n`);
  }
} finally {
  await browser.close();
}

const kb = (n) => `${(n / 1024).toFixed(1)} KB`;
const ms = (n) => `${Math.round(n)} ms`;
const rows = [
  ['TTFB', (r) => ms(r.ttfb)],
  ['Tempo PHP', (r) => (r.php === null ? '—' : ms(r.php))],
  ['Query al database', (r) => (r.queries === null ? '—' : String(r.queries))],
  ['Primo disegno (FCP)', (r) => ms(r.fcp)],
  ['Elemento più grande (LCP)', (r) => ms(r.lcp)],
  ['Spostamenti (CLS)', (r) => r.cls.toFixed(3)],
  ['Elementi del DOM', (r) => String(r.dom)],
  ['Richieste', (r) => String(r.requests)],
  ['Trasferiti: HTML, CSS e JS', (r) => kb(r.sentBytes)],
  ['di cui HTML (con CSS e JS in linea)', (r) => kb(r.htmlBytes)],
  ['CSS da leggere (file + in linea, non compresso)', (r) => kb(r.cssBytes)],
  ['JavaScript da eseguire (file + in linea, non compresso)', (r) => kb(r.jsBytes)],
  ['Altro trasferito (immagini, font…)', (r) => kb(r.otherBytes)],
];
const table = [
  `| ${mobile ? 'Telefono' : 'Desktop 1366'}, mediana di ${runs} | ${results.map((r) => r.label).join(' | ')} |`,
  `|---|${results.map(() => '---').join('|')}|`,
  ...rows.map(([name, f]) => `| ${name} | ${results.map(f).join(' | ')} |`),
].join('\n');
process.stdout.write(`${table}\n`);
if (json) writeFileSync(json, `${JSON.stringify(results, null, 2)}\n`);
