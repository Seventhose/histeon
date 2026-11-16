#!/usr/bin/env node
// Small-model bench (I8, docs/qa/banco-modelli-piccoli.md).
//
//   node scripts/bench/run.mjs prepare [--tasks hero,stile]   one fresh draft page per task, and the prompts
//   node scripts/bench/run.mjs check   [--run bench-run.json] the checks on what the agent saved in development
//
// The site and the account come from the environment, never from a file: XB_BENCH_URL (a disposable test site),
// XB_BENCH_USER and XB_BENCH_PASSWORD (an application password of a test administrator). Nothing goes elsewhere.
import { readFileSync, writeFileSync } from 'node:fs';
import { score } from './checks.mjs';

const args = process.argv.slice(2);
const command = args[0];
const option = (name, fallback) => {
  const at = args.indexOf(`--${name}`);
  return at === -1 ? fallback : args[at + 1];
};
const base = (process.env.XB_BENCH_URL ?? '').replace(/\/+$/, '');
const user = process.env.XB_BENCH_USER ?? '';
const password = process.env.XB_BENCH_PASSWORD ?? '';
if (!['prepare', 'check'].includes(command) || !base || !user || !password) {
  console.error('Uso: XB_BENCH_URL=… XB_BENCH_USER=… XB_BENCH_PASSWORD=… node scripts/bench/run.mjs prepare|check [--tasks a,b] [--run file]');
  process.exit(2);
}
const auth = `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`;
async function api(method, route, body) {
  const response = await fetch(`${base}/wp-json/${route}`, {
    method,
    headers: { Authorization: auth, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await response.json().catch(() => null);
  if (!response.ok) throw new Error(`${method} ${route}: ${response.status} ${data?.message ?? ''}`);
  return data;
}

const all = JSON.parse(readFileSync(new URL('./tasks.json', import.meta.url), 'utf8'));
const wanted = (option('tasks', '') || '').split(',').filter(Boolean);
const tasks = all.tasks.filter((t) => !wanted.length || wanted.includes(t.id));
const runFile = option('run', 'bench-run.json');

if (command === 'prepare') {
  const run = { version: all.version, site: base, created: new Date().toISOString(), pages: {} };
  for (const task of tasks) {
    const page = await api('POST', 'wp/v2/pages', { title: `Banco ${task.id} ${Date.now().toString(36)}`, status: 'draft', content: '' });
    run.pages[task.id] = page.id;
    console.log(`\n## ${task.id} — ${task.title}\n${task.prompt.replaceAll('{page}', `«${page.title.raw ?? page.title.rendered}» (id ${page.id})`)}`);
  }
  writeFileSync(runFile, JSON.stringify(run, null, 2));
  console.log(`\nPagine in ${runFile}. Dopo il lavoro dell'agente: node scripts/bench/run.mjs check --run ${runFile}`);
} else {
  const run = JSON.parse(readFileSync(runFile, 'utf8'));
  const styles = await api('GET', 'xb/v1/styles');
  const report = [];
  for (const task of tasks) {
    const id = run.pages[task.id];
    if (!id) continue;
    const doc = await api('GET', `xb/v1/documents/${id}`);
    const result = score(task, String(doc.content ?? ''), styles);
    report.push(result);
    console.log(`\n${task.id}: ${result.passed}/${result.total}`);
    for (const r of result.results) console.log(`  ${r.ok ? '✓' : '✗'} ${r.label} — ${r.why}`);
  }
  const passed = report.reduce((n, r) => n + r.passed, 0);
  const total = report.reduce((n, r) => n + r.total, 0);
  console.log(`\nTotale: ${passed}/${total}`);
  writeFileSync(runFile.replace(/\.json$/, '') + '-report.json', JSON.stringify({ version: all.version, checked: new Date().toISOString(), passed, total, tasks: report }, null, 2));
  process.exit(passed === total ? 0 : 1);
}
