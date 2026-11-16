// The components a writer adds to an article (Histeon Publish doc 24): written here in Histeon's own language, compiled
// into plugin/assets/framework/article-components.json, which the plugin serves and Publish copies.
//   node scripts/build-article-components.mjs [--check]
import { readFile, writeFile } from "node:fs/promises";
import { rolldown } from "rolldown";

// The language's own parser is TypeScript: bundled here, so this runs on plain Node.
const bundle = await rolldown({ input: new URL("../packages/dsl/src/index.ts", import.meta.url).pathname, platform: "node", logLevel: "silent" });
const { output: [{ code }] } = await bundle.generate({ format: "esm" });
const { parse, serializeXb } = await import(`data:text/javascript;base64,${Buffer.from(code).toString("base64")}`);

const text = (label, extra = {}) => ({ type: "string", label, ...extra });
const rich = (label) => ({ type: "rich-text", label, format: "area" });
const link = (label) => ({ type: "url", label });
const image = (label) => ({ type: "image", label });

const COMPONENTS = [
  {
    name: "riquadro", title: "Riquadro",
    props: { variant: { type: "enum", label: "Tipo", options: ["nota", "avviso", "approfondimento"], default: "nota" }, title: text("Titolo"), text: rich("Testo") },
    source: `<aside class="xb-art-riquadro xb-art-riquadro-{props.variant}">{#if props.title}<p class="xb-art-titolo">{props.title}</p>{/if}<p class="xb-art-testo">{props.text.inline()}</p></aside>`,
  },
  {
    name: "citazione", title: "Citazione grande",
    props: { text: text("Testo", { format: "area" }), author: text("Autore") },
    source: `<figure class="xb-art-citazione"><blockquote>{props.text}</blockquote>{#if props.author}<figcaption>{props.author}</figcaption>{/if}</figure>`,
  },
  {
    name: "libro", title: "Scheda libro",
    props: { image: image("Copertina"), title: text("Titolo"), author: text("Autore"), publisher: text("Editore"), link: link("Collegamento"), linkLabel: text("Testo del collegamento") },
    source: `<div class="xb-art-libro">{#if props.image}<img src={props.image} alt={props.title} loading="lazy"/>{/if}<div class="xb-art-corpo"><p class="xb-art-titolo">{props.title}</p>{#if props.author}<p class="xb-art-autore-nome">{props.author}</p>{/if}{#if props.publisher}<p class="xb-art-editore">{props.publisher}</p>{/if}{#if props.link}<a class="xb-art-link" href={props.link}>{props.linkLabel}</a>{/if}</div></div>`,
  },
  {
    name: "autore", title: "Scheda autore",
    props: { image: image("Ritratto"), name: text("Nome"), bio: rich("Biografia"), link: link("Collegamento"), linkLabel: text("Testo del collegamento") },
    source: `<div class="xb-art-autore">{#if props.image}<img src={props.image} alt={props.name} loading="lazy"/>{/if}<div class="xb-art-corpo"><p class="xb-art-titolo">{props.name}</p>{#if props.bio}<p class="xb-art-testo">{props.bio.inline()}</p>{/if}{#if props.link}<a class="xb-art-link" href={props.link}>{props.linkLabel}</a>{/if}</div></div>`,
  },
  {
    name: "documento", title: "Documento da scaricare",
    props: { label: text("Nome del documento"), href: link("Indirizzo del documento") },
    source: `<a class="xb-art-documento" href={props.href}>{props.label}</a>`,
  },
  {
    name: "fonte", title: "Fonte",
    props: { label: text("Testo del collegamento", { default: "Leggi l'articolo originale" }), link: link("Collegamento") },
    source: `<p class="xb-art-fonte"><a href={props.link}>{props.label}</a></p>`,
  },
  {
    name: "video", title: "Video",
    props: { url: link("Indirizzo del video"), title: text("Titolo") },
    source: `<a class="xb-art-video" href={props.url} rel="noopener">{props.title}</a>`,
  },
  // A block with rows: its slot holds one instance of `rows` for each, so every row has its own texts and translations.
  { name: "galleria", title: "Galleria", rows: "foto", props: {}, source: `<div class="xb-art-galleria">{@slot}</div>` },
  { name: "foto", title: "Foto", row: true, props: { src: image("Immagine"), alt: text("Descrizione"), caption: rich("Didascalia") }, source: `<figure class="xb-art-foto"><img src={props.src} alt={props.alt} loading="lazy"/>{#if props.caption}<figcaption>{props.caption.inline()}</figcaption>{/if}</figure>` },
  {
    name: "pulsante", title: "Pulsante",
    props: { label: text("Testo del pulsante"), link: link("Collegamento") },
    source: `<p class="xb-art-azione"><a class="xb-art-pulsante" href={props.link}>{props.label}</a></p>`,
  },
  {
    name: "evento", title: "Scheda evento",
    props: { title: text("Titolo"), date: text("Data e ora"), place: text("Luogo"), link: link("Collegamento"), linkLabel: text("Testo del collegamento") },
    source: `<div class="xb-art-evento"><p class="xb-art-titolo">{props.title}</p>{#if props.date}<p class="xb-art-data">{props.date}</p>{/if}{#if props.place}<p class="xb-art-luogo">{props.place}</p>{/if}{#if props.link}<a class="xb-art-link" href={props.link}>{props.linkLabel}</a>{/if}</div>`,
  },
  {
    name: "confronto", title: "Confronto prima e dopo",
    props: { before: image("Prima"), after: image("Dopo"), caption: rich("Didascalia") },
    source: `<figure class="xb-art-confronto">{#if props.before}<img src={props.before} alt="Prima" loading="lazy"/>{/if}{#if props.after}<img src={props.after} alt="Dopo" loading="lazy"/>{/if}{#if props.caption}<figcaption>{props.caption.inline()}</figcaption>{/if}</figure>`,
  },
  {
    name: "audio", title: "Audio",
    props: { url: link("Indirizzo dell'audio"), title: text("Titolo") },
    source: `<a class="xb-art-audio" href={props.url} rel="noopener">{props.title}</a>`,
  },
  {
    name: "mappa", title: "Mappa",
    props: { place: text("Luogo"), link: link("Indirizzo della mappa") },
    source: `<a class="xb-art-mappa" href={props.link} rel="noopener">{props.place}</a>`,
  },
  { name: "domande", title: "Domande e risposte", rows: "domanda", props: {}, source: `<div class="xb-art-domande">{@slot}</div>` },
  { name: "domanda", title: "Domanda", row: true, props: { question: text("Domanda"), answer: rich("Risposta") }, source: `<details><summary>{props.question}</summary><p>{props.answer.inline()}</p></details>` },
  { name: "leggi-anche", title: "Leggi anche", rows: "lettura", props: { title: text("Titolo", { default: "Leggi anche" }) }, source: `<aside class="xb-art-leggi"><p class="xb-art-titolo">{props.title}</p><ul>{@slot}</ul></aside>` },
  { name: "lettura", title: "Lettura", row: true, props: { title: text("Titolo"), link: link("Collegamento") }, source: `<li><a href={props.link}>{props.title}</a></li>` },
  { name: "cronologia", title: "Cronologia", rows: "tappa", props: {}, source: `<ol class="xb-art-cronologia">{@slot}</ol>` },
  { name: "tappa", title: "Tappa", row: true, props: { date: text("Data"), text: rich("Che cosa accade") }, source: `<li><span class="xb-art-data">{props.date}</span> {props.text.inline()}</li>` },
  // Its rows are made by Publish from the headings of the article, each time it is saved.
  { name: "indice", title: "Indice", rows: "voce-indice", auto: true, props: { title: text("Titolo", { default: "In questo articolo" }) }, source: `<nav class="xb-art-indice" aria-label={props.title}><p class="xb-art-titolo">{props.title}</p><ol>{@slot}</ol></nav>` },
  { name: "voce-indice", title: "Voce dell'indice", row: true, props: { title: text("Titolo"), link: link("Collegamento") }, source: `<li><a href={props.link}>{props.title}</a></li>` },
  { name: "bibliografia", title: "Bibliografia", rows: "voce", props: {}, source: `<ol class="xb-art-bibliografia">{@slot}</ol>` },
  { name: "voce", title: "Voce", row: true, props: { text: rich("Riferimento") }, source: `<li>{props.text.inline()}</li>` },
];

const compiled = COMPONENTS.map((component) => {
  // Ids fixed by position: the file changes only when a component does.
  let next = 0;
  const { blocks } = parse(component.source, { uid: () => `${component.name.slice(0, 3)}${(next += 1).toString(36).padStart(5, "0")}` });
  const schema = { props: Object.fromEntries(Object.entries(component.props).map(([name, { format: _format, ...definition }]) => [name, definition])) };
  const areas = Object.entries(component.props).filter(([, definition]) => definition.format === "area").map(([name]) => name);
  return { ref: `articolo/${component.name}`, title: component.title, schema, areas, ...(component.rows ? { rows: `articolo/${component.rows}` } : {}), ...(component.row ? { row: true } : {}), ...(component.auto ? { auto: true } : {}), content: serializeXb(blocks) };
});

const target = new URL("../plugin/assets/framework/article-components.json", import.meta.url);
const output = `${JSON.stringify(compiled, null, "\t")}\n`;
if (process.argv.includes("--check")) {
  if ((await readFile(target, "utf8")) !== output) { process.stderr.write("article-components.json non è aggiornato: rigeneralo.\n"); process.exitCode = 1; }
} else await writeFile(target, output);
