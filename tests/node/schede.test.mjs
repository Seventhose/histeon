// scripts/schede.mjs on a throwaway repository: a set of cards in order passes, and each broken rule is named.
import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('../../scripts/schede.mjs', import.meta.url));

function repo() {
  const root = mkdtempSync(join(tmpdir(), 'schede-'));
  const git = (...args) => execFileSync('git', ['-C', root, '-c', 'user.name=t', '-c', 'user.email=t@example.com', ...args], { encoding: 'utf8' }).trim();
  git('init', '--quiet');
  mkdirSync(join(root, 'includes'));
  writeFileSync(join(root, 'includes/class-a.php'), '<?php\n');
  writeFileSync(join(root, 'includes/class-b.php'), '<?php\n');
  git('add', '.');
  git('commit', '--quiet', '-m', 'primo');
  const sha = git('rev-parse', '--short', 'HEAD');
  const write = (path, text) => {
    mkdirSync(join(root, 'docs/ricostruire', path, '..'), { recursive: true });
    writeFileSync(join(root, 'docs/ricostruire', path), text);
  };
  const card = (num, title, file, before = 'niente', body = 'Una frase breve.') => `# ${num} · ${title}\n\n- Tipo: scheda\n- File: ${file}\n- Prima leggi: ${before}\n- Verificata sul commit: \`${sha}\`\n\n## In breve\n\n${body}\n\n## Regole da non rompere\n\n- Non rompere niente.\n\n## Come provarlo\n\n\`\`\`bash\nphp -l includes/class-a.php\n\`\`\`\n`;
  write('schede.json', JSON.stringify({ progetto: 'Prova', copertura: ['includes/*.php'] }));
  write('LEGGIMI.md', '# Leggimi\n\nComincia dalla mappa.\n');
  write('COME-SI-SCRIVE.md', '# Come si scrive\n\nFrasi brevi.\n');
  write('GLOSSARIO.md', '# Glossario\n\n- **Gruppo**: un originale e le sue traduzioni.\n- **Bozza**: un contenuto non pubblicato.\n');
  write('MAPPA.md', '# Mappa\n\n- [01 · A](schede/01-a.md)\n- [02 · B](schede/02-b.md)\n- [01 · Da zero](ricette/01-da-zero.md)\n');
  write('schede/01-a.md', card('01', 'A', '`includes/class-a.php`', 'niente', 'Il gruppo vive qui.'));
  write('schede/02-b.md', card('02', 'B', '`includes/class-b.php`', '[01 · A](01-a.md)'));
  write('ricette/01-da-zero.md', `# 01 · Da zero\n\n- Tipo: ricetta\n- Prima leggi: [01 · A](../schede/01-a.md)\n- Verificata sul commit: \`${sha}\`\n\n## Quando usarla\n\nSempre.\n\n## Passi\n\n1. Scrivi \`includes/class-a.php\`.\n\n## Fatto quando\n\nLa prova passa.\n`);
  const run = (...args) => spawnSync(process.execPath, [script, ...args], { env: { ...process.env, SCHEDE_ROOT: root, XB_PUBLIC_DENY: join(root, 'deny.txt') }, encoding: 'utf8' });
  return { root, write, card, run, sha, cleanup: () => rmSync(root, { recursive: true, force: true }) };
}

test('schede in regola: unisci, poi controlla passa; contesto e dove trovano le schede', () => {
  const r = repo();
  try {
    assert.equal(r.run('controlla').status, 1, 'senza il file unito il controllo non passa');
    assert.equal(r.run('unisci').status, 0);
    const ok = r.run('controlla');
    assert.equal(ok.status, 0, ok.stdout);
    const merged = readFileSync(join(r.root, 'docs/RICOSTRUIRE-DA-ZERO.md'), 'utf8');
    assert.match(merged, /<a id="schede-02-b"><\/a>/);
    assert.match(merged, /\]\(#schede-01-a\)/, 'i collegamenti tra schede diventano ancore');
    assert.match(merged, /^## 01 · A$/m, 'i titoli scendono di un livello');
    const ctx = r.run('contesto', '02');
    assert.equal(ctx.status, 0, ctx.stderr);
    assert.ok(ctx.stdout.indexOf('# 01 · A') < ctx.stdout.indexOf('# 02 · B'), 'prima le schede da leggere prima');
    assert.match(ctx.stdout, /\*\*Gruppo\*\*/, 'la voce del glossario nominata');
    assert.doesNotMatch(ctx.stdout, /\*\*Bozza\*\*/, 'non le voci che nessuna scheda nomina');
    assert.match(r.run('dove', 'includes/class-b.php').stdout, /schede\/02-b/);
  } finally {
    r.cleanup();
  }
});

test('ogni regola rotta ha il suo errore', () => {
  const r = repo();
  try {
    const long = Array.from({ length: 50 }, (_, i) => `parola${i}`).join(' ');
    r.write('schede/02-b.md', `${r.card('02', 'B', '`includes/class-c.php`', '[09 · X](09-x.md)', `${long}. Come detto prima, vale.`)}\nVedi sopra e \`includes/manca.php\`.\n`);
    r.write('schede/03-c.md', r.card('04', 'C', 'nessuno').replace('## Come provarlo', '## Altro'));
    writeFileSync(join(r.root, 'deny.txt'), 'clienteacme\n');
    r.write('schede/05-d.md', r.card('05', 'D', 'nessuno', 'niente', `Per Clienteacme in ${'/ho'}me/mario/x.`));
    r.run('unisci');
    const out = r.run('controlla');
    assert.equal(out.status, 1);
    for (const expected of [
      /02-b.md: «Prima leggi» nomina schede\/09-x.md, che non esiste/,
      /02-b.md: «File» nomina includes\/class-c.php/,
      /una frase di 5\d parole/,
      /rimando .*«Come detto»/i,
      /rimando .*«Vedi sopra»/i,
      /il percorso includes\/manca.php non è nel repository/,
      /03-c.md:1: il numero del titolo \(04\)/,
      /03-c.md: manca la sezione «## Come provarlo»/,
      /05-d.md:\d+: contiene una parola della lista vietata/,
      /05-d.md:\d+: percorso con la cartella home/,
      /il file includes\/class-b.php non è nella riga «File»/,
      /MAPPA.md: non porta a schede\/03-c.md/,
    ]) assert.match(out.stdout, expected);
  } finally {
    r.cleanup();
  }
});

test('un file nuovo, non ancora nel commit, conta come file del repository; uno ignorato da git no', () => {
  const r = repo();
  try {
    writeFileSync(join(r.root, '.gitignore'), 'includes/ignorato.php\n');
    writeFileSync(join(r.root, 'includes/class-nuova.php'), '<?php\n');
    writeFileSync(join(r.root, 'includes/ignorato.php'), '<?php\n');
    r.write('schede/03-c.md', r.card('03', 'C', '`includes/class-nuova.php` e `.gitignore`', 'niente', 'Descrive `docs/ricostruire/MAPPA.md`.'));
    r.write('MAPPA.md', '# Mappa\n\n- [01 · A](schede/01-a.md)\n- [02 · B](schede/02-b.md)\n- [03 · C](schede/03-c.md)\n- [01 · Da zero](ricette/01-da-zero.md)\n');
    r.run('unisci');
    const ok = r.run('controlla');
    assert.equal(ok.status, 0, ok.stdout);
    r.write('schede/03-c.md', r.card('03', 'C', '`includes/class-nuova.php` e `includes/ignorato.php`'));
    r.run('unisci');
    assert.match(r.run('controlla').stdout, /«File» nomina includes\/ignorato.php, che non è nel repository/);
  } finally {
    r.cleanup();
  }
});

test('una funzione del codice che nessuna scheda nomina', () => {
  const r = repo();
  try {
    writeFileSync(join(r.root, 'includes/class-a.php'), '<?php\nclass A {\n\tpublic static function nominata() {}\n\tprivate static function dimenticata() {}\n\tpublic function __construct() {}\n}\n');
    r.write('schede.json', JSON.stringify({ progetto: 'Prova', copertura: ['includes/*.php'], funzioni: ['includes/*.php'] }));
    r.write('schede/01-a.md', r.card('01', 'A', '`includes/class-a.php`', 'niente', '`A::nominata()` non riceve niente e non restituisce niente.'));
    r.run('unisci');
    const out = r.run('controlla').stdout;
    assert.match(out, /class-a.php: la funzione dimenticata non è nominata/);
    assert.doesNotMatch(out, /funzione nominata|__construct/);
  } finally {
    r.cleanup();
  }
});

test('troppe parole da leggere insieme e un giro chiuso in «Prima leggi»', () => {
  const r = repo();
  try {
    r.write('schede.json', JSON.stringify({ progetto: 'Prova', copertura: ['includes/*.php'], limite_contesto: 60 }));
    r.write('schede/01-a.md', r.card('01', 'A', '`includes/class-a.php`', '[02 · B](02-b.md)'));
    r.run('unisci');
    const out = r.run('controlla').stdout;
    assert.match(out, /giro chiuso in «Prima leggi»/);
    assert.match(out, /con le schede da leggere prima fa \d+ parole, il limite è 60/);
  } finally {
    r.cleanup();
  }
});
