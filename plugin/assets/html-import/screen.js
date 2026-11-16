// Histeon → Release → Histeon CMS → «Importa HTML» (doc 18 F6 of Histeon Publish). A page, a site's ZIP or its folder is
// converted in this browser by Histeon Publish's own converter and prototype import (histos-html-import.js, on SQLite
// for WebAssembly, in memory). Nothing of the site runs here: its files are read as data. The `.xbsite` it makes goes
// to the form «Controlla» of the package section, so the server checks it and shows what it would write, as for any
// package; nothing is written before the administrator confirms there.
//
// «Rifinisci i nomi con un modello» (doc 27 of Histeon Publish, G4): the conversion's choices (generic names such as
// `section__div-2`) go to the plugin's route POST /xb/v1/import/scelte, in batches; the server asks the site's model and
// checks every answer. The names are tried with the converter (trialChoices), shown «prima → dopo», and applied only
// when the administrator says so; alternative texts are accepted one by one. Every text of the site is shown as text.
//
// «Claude dal connettore»: the Claude of the person's plan answers, through the Histeon Connector on this computer. A
// PHP request cannot wait the minutes that takes, so this page waits: it links to the connector as a tab of kind
// «importa-html» (the builder's pairing, docs/spec/connector.md §3), hands the question to the agent (importChoices),
// takes its answer (importChoicesAnswer), and sends it to POST /xb/v1/import/scelte/risposta to be checked with the
// shared rules. Up to 200 choices in one question, 15 minutes at most, «Annulla» at any time.
//
// V5.6, the colours of a page of this site: the person chooses before converting (addPage.colors: «pagina», the default,
// «sito», or «sostituisci» where the site uses Histeon CSS). With «sostituisci» the variables that change in the whole
// site are shown «prima → dopo» and the package reaches «Controlla» only after an explicit confirmation.
import { convertToXbsite, readSiteZip, siteFilePath, trialChoices } from "./histos-html-import.js";
import sqlite3InitModule from "./sqlite3.mjs";

const root = document.getElementById("xb-html-import");
if (root) {
  const config = JSON.parse(root.dataset.config || "{}");
  const strings = config.strings || {};
  const $ = (selector) => root.querySelector(selector);
  const pages = $("[data-pages]");
  const folder = $("[data-folder]");
  const framework = $("[data-framework]");
  const recipes = $("[data-recipes]");
  const convert = $("[data-convert]");
  const status = $("[data-status]");
  const error = $("[data-error]");
  const result = $("[data-result]");
  const refineToggle = $("[data-refine]");
  const refineView = $("[data-refine-view]");
  const cancel = $("[data-refine-cancel]");
  const refineConfig = config.refine || {};
  const words = strings.refine || {};
  let sqlite = null;
  /** The last conversion: its files and options, the model's report and the answers applied. */
  let current = null;
  let busy = false;

  const MAX_FILES = 5000;
  const MAX_BYTES = 200 * 1024 * 1024;

  const fail = (message) => {
    error.textContent = message;
    error.hidden = false;
    status.textContent = "";
  };

  /** The files chosen, as the converter takes them: a ZIP read with its checks, a folder without its top name. */
  async function chosenFiles() {
    const fromFolder = [...(folder.files || [])];
    const fromPages = [...(pages.files || [])];
    if (fromFolder.length && fromPages.length) throw new Error(strings.oneSource);
    if (fromPages.length === 1 && /\.zip$/iu.test(fromPages[0].name)) {
      const read = readSiteZip(new Uint8Array(await fromPages[0].arrayBuffer()));
      return { files: read.files, name: fromPages[0].name.replace(/\.zip$/iu, ""), refused: read.refused };
    }
    const list = fromFolder.length ? fromFolder : fromPages;
    if (!list.length) throw new Error(strings.noFiles);
    if (list.length > MAX_FILES || list.reduce((total, file) => total + file.size, 0) > MAX_BYTES) throw new Error(strings.tooBig);
    const files = [];
    let refused = 0;
    for (const file of list) {
      const relative = fromFolder.length ? (file.webkitRelativePath || file.name).split("/").slice(1).join("/") : file.name;
      const path = siteFilePath(relative);
      if (path === null) {
        refused++;
        continue;
      }
      files.push({ path, bytes: new Uint8Array(await file.arrayBuffer()) });
    }
    // A single page chosen alone is the home.
    if (files.length === 1 && /\.html?$/iu.test(files[0].path)) files[0] = { ...files[0], path: "index.html" };
    const name = fromFolder.length ? (fromFolder[0].webkitRelativePath || "").split("/")[0] : list[0].name.replace(/\.html?$/iu, "");
    return { files, name: name || "Sito importato", refused };
  }

  const format = (text, ...values) => values.reduce((out, value, index) => out.replace(`%${index + 1}$d`, String(value)).replace(`%${index + 1}$s`, String(value)), String(text || "")).replace("%d", String(values[0] ?? "")).replace("%s", String(values[0] ?? ""));
  const element = (tag, props = {}, ...children) => {
    const node = Object.assign(document.createElement(tag), props);
    node.append(...children);
    return node;
  };

  /** The model's names for the conversion's choices, asked batch by batch through the plugin's route. */
  async function askModel(choices, signal) {
    const size = Number(refineConfig.batch) || 20;
    const asked = choices.slice(0, Number(refineConfig.max) || 200);
    const batches = [];
    for (let start = 0; start < asked.length; start += size) batches.push(asked.slice(start, start + size));
    const report = { provider: refineConfig.provider, model: refineConfig.model, apply: {}, suggestions: {}, rejected: [], batches: [], skipped: choices.length - asked.length, ms: 0 };
    const started = Date.now();
    for (const [index, batch] of batches.entries()) {
      if (signal.aborted) {
        report.batches.push({ size: batch.length, ok: false, error: words.stopped });
        continue;
      }
      status.textContent = format(words.asking, index + 1, batches.length);
      try {
        const response = await fetch(refineConfig.url, { method: "POST", credentials: "same-origin", signal, headers: { "content-type": "application/json", "X-WP-Nonce": refineConfig.nonce }, body: JSON.stringify({ choices: batch }) });
        const body = await response.json().catch(() => null);
        if (!response.ok) throw new Error((body && typeof body.message === "string" && body.message) || `HTTP ${response.status}`);
        Object.assign(report.apply, body.apply || {});
        Object.assign(report.suggestions, body.suggestions || {});
        report.rejected.push(...(Array.isArray(body.rejected) ? body.rejected : []));
        report.batches.push(...(Array.isArray(body.batches) ? body.batches : []));
        report.provider = body.provider || report.provider;
        report.model = body.model || report.model;
      } catch (problem) {
        report.batches.push({ size: batch.length, ok: false, error: signal.aborted ? words.stopped : problem instanceof Error ? problem.message : String(problem) });
      }
    }
    report.ms = Date.now() - started;
    return report;
  }

  const CONNECTOR_WAIT_MS = 15 * 60_000;
  const encoder = new TextEncoder();
  const base64url = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/u, "");
  /** HMAC-SHA256 with the pairing secret, parts joined by "|", as the connector computes it (pairProof). */
  async function pairProof(secret, ...parts) {
    const key = await crypto.subtle.importKey("raw", encoder.encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
    return base64url(await crypto.subtle.sign("HMAC", key, encoder.encode(parts.join("|"))));
  }
  const same = (a, b) => typeof a === "string" && a.length === b.length && [...a].reduce((diff, char, index) => diff | (char.charCodeAt(0) ^ b.charCodeAt(index)), 0) === 0;
  /** The choices as data, as Choices::user_message() writes them for a provider. */
  const userMessage = (batch) => `<<<DATI\n${JSON.stringify(batch.map((choice) => ({ id: choice.id, tipo: choice.kind, valore: choice.value, contesto: choice.context })), null, 1).replaceAll("<<<", "< < <").replaceAll(">>>", "> > >")}\nDATI>>>`;
  /** What the agent may send back, checked here only in its shape: the plugin checks every name. */
  function answerList(text, count) {
    let parsed;
    try {
      parsed = JSON.parse(String(text));
    } catch {
      throw new Error('scelte non è JSON: serve {"scelte":[{"id":"…","valore":"…"}]}.');
    }
    const list = parsed && typeof parsed === "object" && Array.isArray(parsed.scelte) ? parsed.scelte : null;
    if (!list) throw new Error('Manca l\'elenco «scelte»: serve {"scelte":[{"id":"…","valore":"…"}]}.');
    if (list.length > Math.max(count, 1)) throw new Error(`Troppe voci: la domanda ha ${count} scelte.`);
    if (!list.every((item) => item && typeof item === "object" && typeof item.id === "string" && typeof item.valore === "string")) throw new Error("Ogni voce ha id e valore, tutti e due testo.");
    return list.map((item) => ({ id: item.id, valore: item.valore }));
  }

  /**
   * One question to Claude through the connector: links this page as a tab, answers the agent's importChoices with the
   * question and its importChoicesAnswer with the end of the wait. Resolves with the answer's entries.
   */
  async function waitForClaude(asked, site, signal) {
    const connector = refineConfig.connector;
    if (!window.isSecureContext || !globalThis.crypto?.subtle) throw new Error(words.insecure);
    const base = `http://127.0.0.1:${Number(connector.port) || 7341}`;
    const origin = window.location.origin;
    const question = { requestId: crypto.randomUUID(), site: site.slice(0, 120), count: asked.length, expiresAt: new Date(Date.now() + CONNECTOR_WAIT_MS).toISOString(), system: connector.system, choices: userMessage(asked), answer: 'Rispondi con xb_import_choices_answer: requestId e scelte, il testo JSON {"scelte":[{"id":"…","valore":"…"}]} con una voce per ogni id.' };
    const stop = new AbortController();
    const late = setTimeout(() => stop.abort(new Error(words.late)), CONNECTOR_WAIT_MS);
    const quit = () => stop.abort(new Error(words.stopped));
    signal.addEventListener("abort", quit, { once: true });
    let tab = null;
    const hello = async () => {
      const nonce = base64url(crypto.getRandomValues(new Uint8Array(18)));
      const time = String(Date.now());
      const proof = await pairProof(connector.secret, "tab", nonce, origin, time);
      const res = await fetch(`${base}/tab/hello`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ site, title: `Importa HTML: ${site}`.slice(0, 300), doc: null, kind: "importa-html", nonce, time, proof }), signal: stop.signal });
      if (res.status === 401 || res.status === 403) throw Object.assign(new Error(words.otherPair), { final: true });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (typeof data.tab !== "string" || typeof data.key !== "string" || !same(data.proof, await pairProof(connector.secret, "bridge", nonce, data.tab, data.key))) throw Object.assign(new Error(words.impostor), { final: true });
      return { id: data.tab, key: data.key };
    };
    const reply = (body) => fetch(`${base}/tab/result?tab=${encodeURIComponent(tab.id)}`, { method: "POST", headers: { "Content-Type": "application/json", "X-XB-Tab-Key": tab.key }, body: JSON.stringify(body) });
    try {
      try {
        tab = await hello();
      } catch (problem) {
        // Not running: said at once, as Histeon Publish does with its connector off.
        throw stop.signal.aborted ? stop.signal.reason : problem?.final ? problem : new Error(words.off);
      }
      status.textContent = words.waiting;
      for (;;) {
        try {
          if (!tab) tab = await hello();
          const res = await fetch(`${base}/tab/next?tab=${encodeURIComponent(tab.id)}`, { headers: { "X-XB-Tab-Key": tab.key }, signal: stop.signal });
          if (res.status === 204) continue;
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          const message = await res.json();
          const args = message.args && typeof message.args === "object" ? message.args : {};
          if (message.method === "importChoices") {
            await reply({ id: message.id, ok: true, result: question });
            continue;
          }
          if (message.method === "importChoicesAnswer" && args.requestId === question.requestId) {
            let list;
            try {
              list = answerList(args.scelte, question.count);
            } catch (problem) {
              await reply({ id: message.id, ok: false, error: problem.message });
              continue;
            }
            await reply({ id: message.id, ok: true, result: { received: list.length, next: "La persona vede i nomi in una tabella e applica quelli che vuole: non chiedere altro." } });
            return list;
          }
          await reply({ id: message.id, ok: false, error: message.method === "importChoicesAnswer" ? "Nessuna domanda in attesa con questo id: forse è scaduta o la persona l'ha annullata. Chiedi di nuovo con xb_import_choices." : `Operazione non ammessa: ${message.method}` });
        } catch (problem) {
          if (stop.signal.aborted) throw stop.signal.reason;
          if (problem?.final) throw problem;
          // The connector restarted: say hello again, until the time is up.
          tab = null;
          await new Promise((resolve) => setTimeout(resolve, 2000));
        }
      }
    } finally {
      clearTimeout(late);
      signal.removeEventListener("abort", quit);
      if (tab) {
        const body = JSON.stringify({ key: tab.key });
        const url = `${base}/tab/bye?tab=${encodeURIComponent(tab.id)}`;
        if (!(navigator.sendBeacon && navigator.sendBeacon(url, body))) void fetch(url, { method: "POST", keepalive: true, headers: { "Content-Type": "text/plain" }, body }).catch(() => undefined);
      }
    }
  }

  /** «Claude dal connettore»: one question for up to 200 choices; the answer is checked by the plugin, 20 choices at a time. */
  async function askConnector(choices, site, signal) {
    const asked = choices.slice(0, Number(refineConfig.max) || 200);
    const report = { provider: "connettore", model: refineConfig.model, apply: {}, suggestions: {}, rejected: [], batches: [], skipped: choices.length - asked.length, ms: 0 };
    const started = Date.now();
    try {
      const list = await waitForClaude(asked, site, signal);
      status.textContent = words.checking;
      const size = Number(refineConfig.batch) || 20;
      const groups = [];
      const where = new Map(asked.map((choice, index) => [choice.id, Math.floor(index / size)]));
      for (let start = 0; start < asked.length; start += size) groups.push({ choices: asked.slice(start, start + size), scelte: [] });
      // An id that was not asked goes with the first group, where the plugin says why it is left out.
      for (const item of list) groups[where.get(item.id) ?? 0].scelte.push(item);
      for (const group of groups) {
        const begun = Date.now();
        try {
          if (!group.scelte.length) {
            report.batches.push({ size: group.choices.length, ok: true, ms: 0 });
            continue;
          }
          const response = await fetch(refineConfig.answer, { method: "POST", credentials: "same-origin", signal, headers: { "content-type": "application/json", "X-WP-Nonce": refineConfig.nonce }, body: JSON.stringify({ choices: group.choices, scelte: JSON.stringify({ scelte: group.scelte }) }) });
          const body = await response.json().catch(() => null);
          if (!response.ok) throw new Error((body && typeof body.message === "string" && body.message) || `HTTP ${response.status}`);
          Object.assign(report.apply, body.apply || {});
          Object.assign(report.suggestions, body.suggestions || {});
          report.rejected.push(...(Array.isArray(body.rejected) ? body.rejected : []));
          report.batches.push({ size: group.choices.length, ok: true, ms: Date.now() - begun });
        } catch (problem) {
          report.batches.push({ size: group.choices.length, ok: false, error: signal.aborted ? words.stopped : problem instanceof Error ? problem.message : String(problem), ms: Date.now() - begun });
        }
      }
    } catch (problem) {
      report.batches.push({ size: asked.length, ok: false, error: signal.aborted ? words.stopped : problem instanceof Error ? problem.message : String(problem) });
    }
    report.ms = Date.now() - started;
    return report;
  }

  /** The rows of the table: the model's names the converter would accept, the others left out with the reason. */
  function tableOf(report, choices, input) {
    const byId = new Map(choices.map((choice) => [choice.id, choice]));
    const answers = { ...report.apply, ...report.suggestions };
    const refused = new Map(trialChoices(input, answers).map((item) => [item.id, item.motivo]));
    const rows = [];
    const suggestions = [];
    const rejected = report.rejected.map((item) => ({ ...item }));
    for (const [list, values] of [[rows, report.apply], [suggestions, report.suggestions]]) {
      for (const [id, after] of Object.entries(values)) {
        const choice = byId.get(id);
        if (!choice) continue;
        if (refused.has(id)) rejected.push({ id, motivo: refused.get(id), after });
        else list.push({ id, kind: choice.kind, before: choice.value, after, context: choice.context });
      }
    }
    return { ...report, rows, suggestions, rejected: rejected.slice(0, 200), asked: Math.min(choices.length, Number(refineConfig.max) || 200) };
  }

  /** The table «prima → dopo», the alternative texts, what was left out and what is applied now. */
  function showRefine() {
    const view = current?.refine;
    if (!view) {
      refineView.hidden = true;
      refineView.replaceChildren();
      return;
    }
    const applied = current.applied;
    const nodes = [element("legend", { textContent: refineToggle.parentElement?.textContent?.trim() || "" })];
    if (view.empty) {
      nodes.push(element("p", { className: "xb-muted", textContent: words.nothing }));
      refineView.replaceChildren(...nodes);
      refineView.hidden = false;
      return;
    }
    nodes.push(element("p", { textContent: format(words.summary, view.model, words[view.provider] || view.provider, Math.max(1, Math.round(view.ms / 1000)), view.rows.length, view.suggestions.length) }));
    const failed = view.batches.filter((batch) => !batch.ok);
    if (failed.length) nodes.push(element("p", { className: "notice notice-warning inline", textContent: format(words.failed, failed.length, view.batches.length, failed[0]?.error || "") }));
    if (view.skipped > 0) nodes.push(element("p", { className: "xb-muted", textContent: format(words.skipped, view.skipped, view.asked) }));
    if (!view.rows.length && !view.suggestions.length && !failed.length) nodes.push(element("p", { className: "xb-muted", textContent: words.none }));
    const anyApplied = view.rows.some((row) => applied[row.id] === row.after);
    const rowBoxes = [];
    if (view.rows.length) {
      const body = element("tbody");
      for (const row of view.rows) {
        const box = element("input", { type: "checkbox", checked: anyApplied ? applied[row.id] === row.after : true, disabled: busy });
        box.dataset.id = row.id;
        box.setAttribute("aria-label", format(words.applyRow, row.after, row.before));
        rowBoxes.push(box);
        body.append(element("tr", {}, element("td", {}, box), element("td", {}, element("code", { textContent: row.before })), element("td", {}, element("code", { textContent: row.after })), element("td", {}, element("span", { className: "xb-refine__where", textContent: `${(words.kinds || {})[row.kind] || row.kind} · ${row.context}` }))));
      }
      const head = element("thead", {}, element("tr", {}, element("th", { scope: "col" }, element("span", { className: "screen-reader-text", textContent: words.apply })), element("th", { scope: "col", textContent: words.before }), element("th", { scope: "col", textContent: words.after }), element("th", { scope: "col", textContent: words.where })));
      const table = element("table", { className: "xb-refine__table" }, head, body);
      table.dataset.refineTable = "";
      nodes.push(element("div", { className: "xb-refine__scroll" }, table));
    }
    const altBoxes = [];
    if (view.suggestions.length) {
      nodes.push(element("p", { textContent: words.alts }));
      const list = element("ul", { className: "xb-refine__alts" });
      list.dataset.refineAlts = "";
      for (const row of view.suggestions) {
        const box = element("input", { type: "checkbox", checked: applied[row.id] === row.after, disabled: busy });
        box.dataset.id = row.id;
        altBoxes.push(box);
        list.append(element("li", {}, element("label", {}, box, ` ${words.accept} «${row.after}»`), " ", element("small", { className: "xb-muted", textContent: row.context })));
      }
      nodes.push(list);
    }
    if (view.rejected.length) {
      const list = element("ul", {}, ...view.rejected.map((item) => element("li", {}, element("code", { textContent: item.id }), item.after ? ` → ${item.after}` : "", `: ${item.motivo}`)));
      nodes.push(element("details", {}, element("summary", { textContent: format(words.rejected, view.rejected.length) }), list));
    }
    const chosen = (all) => {
      const out = {};
      for (const row of view.rows) if (all || rowBoxes.find((box) => box.dataset.id === row.id)?.checked) out[row.id] = row.after;
      for (const row of view.suggestions) if (altBoxes.find((box) => box.dataset.id === row.id)?.checked) out[row.id] = row.after;
      return out;
    };
    if (view.rows.length || view.suggestions.length) {
      const all = element("button", { type: "button", className: "xb-btn", textContent: words.applyAll, disabled: busy || !view.rows.length });
      all.dataset.refineApplyAll = "";
      all.addEventListener("click", () => void reconvert(chosen(true)));
      const picked = element("button", { type: "button", className: "xb-btn xb-btn--primary", textContent: words.applyPicked, disabled: busy });
      picked.dataset.refineApply = "";
      picked.addEventListener("click", () => void reconvert(chosen(false)));
      nodes.push(element("p", { className: "xb-refine__actions" }, all, picked));
    }
    const count = Object.keys(applied).length;
    if (count > 0) {
      const reset = element("button", { type: "button", className: "button-link", textContent: words.reset, disabled: busy });
      reset.addEventListener("click", () => void reconvert({}));
      const note = element("p", {}, format(words.applied, count), " ", reset);
      note.dataset.refineApplied = "";
      nodes.push(note);
    }
    const notApplied = current.converted.choicesRejected || [];
    if (notApplied.length) {
      nodes.push(element("details", { open: true }, element("summary", { textContent: format(words.notApplied, notApplied.length) }), element("ul", {}, ...notApplied.map((item) => element("li", {}, element("code", { textContent: item.id }), `: ${item.motivo}`)))));
    }
    refineView.replaceChildren(...nodes);
    refineView.hidden = false;
  }

  /** The same files converted again with the answers applied; the model is not asked again. */
  async function reconvert(choices) {
    if (!current || busy) return;
    busy = true;
    error.hidden = true;
    convert.disabled = true;
    showRefine();
    status.textContent = words.applying;
    try {
      const input = { ...current.input, choices };
      const converted = await convertToXbsite(input, sqlite);
      current = { ...current, converted, applied: choices };
      showAdded(converted.addPage, current.extra || []);
      show(converted, current.source);
    } catch (problem) {
      fail(`${strings.failed} ${failure(problem)}`);
    } finally {
      busy = false;
      convert.disabled = false;
      showRefine();
    }
  }

  function show(converted, source) {
    const list = $("[data-notes]");
    const item = (note) => Object.assign(document.createElement("li"), { textContent: note });
    // One report by what happened (lista della spesa 163): removed, moved into the page's JavaScript, breakpoints, to check.
    const groups = Array.isArray(converted.groups) ? converted.groups : [];
    list.replaceChildren(...(groups.length
      ? groups.map((group) => {
          const li = document.createElement("li");
          const items = document.createElement("ul");
          items.append(...group.items.map(item));
          li.append(Object.assign(document.createElement("strong"), { textContent: `${(strings.groups || {})[group.kind] || group.kind} (${group.items.length})` }), items);
          return li;
        })
      : [...converted.notes, ...converted.warnings].map(item)));
    $("[data-measure]").textContent = converted.selectable
      ? strings.measure.replace("%1$s", String(Math.round(converted.selectable.share * 1000) / 10)).replace("%2$s", String(converted.selectable.selectable)).replace("%3$s", String(converted.selectable.original)).replace("%%", "%")
      : "";
    $("[data-refused]").textContent = source.refused ? strings.refused.replace("%d", String(source.refused)) : "";
    const prototype = $("[data-prototype]");
    URL.revokeObjectURL(prototype.href);
    // A download, never a page of wp-admin: the converted HTML is the imported site's, not ours (note 11, audit of 3 November 2026).
    prototype.href = URL.createObjectURL(new Blob([converted.prototype], { type: "application/octet-stream" }));
    prototype.download = `${source.name}-prototipo.html`;
    const transfer = new DataTransfer();
    transfer.items.add(new File([converted.archive], `${source.name}.xbsite`, { type: "application/zip" }));
    $("[data-package]").files = transfer.files;
    result.hidden = false;
    status.textContent = strings.ready;
    $("[data-continue]").focus();
  }

  // ------------------------------------------------------------------ V5.2: «Importa una pagina di questo sito»
  //
  // The page is downloaded here as a visitor sees it: same origin only (mode "same-origin" refuses a redirect to another
  // site before anything is asked of it), no cookies, never wp-admin, the REST API or the login. Its sheets, images and
  // fonts of this same site come with it, within the limits above; the site's own Histeon CSS and the admin bar stay
  // out. The HTML is parsed with DOMParser, which runs nothing and loads nothing. The site's base (AddPageRest) lets
  // the converter add the page to the project without touching what the site has (`addPage`).
  const site = config.site || {};
  const siteWords = strings.site || {};
  const siteOpen = $("[data-site-open]");
  const sitePicker = $("[data-site-picker]");
  const siteSearch = $("[data-site-search]");
  const siteList = $("[data-site-list]");
  const siteCount = $("[data-site-count]");
  const siteLeft = $("[data-site-left]");
  const siteImport = $("[data-site-import]");
  const added = $("[data-added]");
  const colorsConfirm = $("[data-colors-confirm]");
  const colorWords = siteWords.colors || {};
  let sitePages = [];

  /**
   * A colour value safe to paint as a sample: a hex colour or a colour function with numbers only, which the browser
   * reads as a colour. Anything else of the site (a var(), a url(), a declaration) gets no sample, only its text.
   */
  function sampleColor(value) {
    const text = String(value ?? "").trim();
    if (text.length > 120) return null;
    const call = /^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(([^()]*)\)$/iu.exec(text);
    const safe = /^#[0-9a-f]{3,8}$/iu.test(text) || (call !== null && /^[0-9.%,\s/+-]*$/u.test(call[1].replace(/none|deg|grad|rad|turn/giu, "")));
    return safe && globalThis.CSS?.supports?.("color", text) ? text : null;
  }
  /** A value of a colour as text, with its sample in front when it is a colour (set through the CSSOM, never as markup). */
  function colorValue(value) {
    const node = element("span", { className: "xb-site-colors__value" });
    const color = sampleColor(value);
    if (color) {
      const sample = element("span", { className: "xb-site-colors__sample" });
      sample.setAttribute("aria-hidden", "true");
      sample.style.backgroundColor = color;
      node.append(sample);
    }
    node.append(element("code", { textContent: String(value ?? "") }));
    return node;
  }
  /** «variabile: prima → dopo», one item. */
  const changeItem = (name, before, after) => element("li", {}, element("code", { textContent: name }), ": ", colorValue(before), " → ", colorValue(after));

  /** Whether an address may be downloaded: this origin, never the administration, the REST API or the login. */
  function allowedHere(url) {
    if (url.origin !== window.location.origin || !/^https?:$/u.test(url.protocol)) return false;
    const path = url.pathname.toLowerCase();
    if (url.searchParams.has("rest_route")) return false;
    if (/(^|\/)(wp-admin|wp-json)(\/|$)|(^|\/)(wp-login|xmlrpc|wp-cron|wp-signup|wp-activate)\.php$/u.test(path)) return false;
    return !(site.forbidden || []).some((prefix) => prefix && (path === prefix.toLowerCase() || path.startsWith(`${prefix.toLowerCase()}/`)));
  }

  /** A GET of this site as a visitor: no cookies, no cache, no redirect to another origin, at most `room` bytes. */
  async function fetchHere(url, room) {
    if (!allowedHere(url)) throw new Error(url.origin === window.location.origin ? siteWords.forbidden : siteWords.otherSite);
    let response;
    try {
      response = await fetch(url.href, { credentials: "omit", mode: "same-origin", redirect: "follow", cache: "no-store", referrerPolicy: "no-referrer" });
    } catch {
      throw new Error(siteWords.otherSite);
    }
    if (!allowedHere(new URL(response.url || url.href))) throw new Error(siteWords.forbidden);
    if (!response.ok) throw Object.assign(new Error(format(siteWords.status, response.status)), { status: response.status });
    const declared = Number(response.headers.get("content-length") || 0);
    if (declared > room) throw new Error(strings.tooBig);
    const bytes = new Uint8Array(await response.arrayBuffer());
    if (bytes.byteLength > room) throw new Error(strings.tooBig);
    return { bytes, type: (response.headers.get("content-type") || "").toLowerCase(), url: new URL(response.url || url.href) };
  }

  /** The converter's slug rules (add-page.ts): lower case, no accents, words joined by «-», at most 63 characters. */
  const slugify = (value, max = 63) => String(value).toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/gu, "").replace(/[^a-z0-9]+/gu, "-").replace(/^-+|-+$/gu, "").slice(0, max).replace(/-+$/u, "");

  /**
   * The address of the new page: the page's own slug is taken by the page itself, so the first free «slug-2», «slug-3»…
   * of the site's slugs, as WordPress names a copy.
   */
  function freeSlug(page, taken) {
    let wanted = "";
    try {
      wanted = slugify(decodeURIComponent(page.slug || ""));
    } catch {
      wanted = slugify(page.slug || "");
    }
    wanted ||= slugify(page.title || "") || "pagina";
    const used = new Set(taken.map((slug) => {
      try {
        return slugify(decodeURIComponent(slug));
      } catch {
        return slugify(slug);
      }
    }));
    for (let n = 2; n < 10_000; n++) {
      const suffix = `-${n}`;
      const candidate = `${wanted.slice(0, 63 - suffix.length).replace(/-+$/u, "")}${suffix}`;
      if (!used.has(candidate)) return candidate;
    }
    return `${wanted.slice(0, 50)}-${Date.now()}`;
  }

  /** A path of the site as a file of the converter («wp-content/…/style.css»), or null when it cannot be one. */
  function localPath(url) {
    let path;
    try {
      path = decodeURIComponent(url.pathname);
    } catch {
      return null;
    }
    if (path.endsWith("/")) return null;
    const checked = siteFilePath(path.replace(/^\/+/u, ""));
    return checked && checked !== "index.html" ? checked : null;
  }
  const encodePath = (path) => path.split("/").map(encodeURIComponent).join("/");
  /** `to` written from the folder of `from` («a/b/c.css» → «../img/x.png»). */
  function relativePath(from, to) {
    const base = from.split("/").slice(0, -1);
    const target = to.split("/");
    let common = 0;
    while (common < base.length && common < target.length - 1 && base[common] === target[common]) common++;
    return [...base.slice(common).map(() => ".."), ...target.slice(common)].map(encodeURIComponent).join("/");
  }
  const STYLE_FILE = /\.css$/iu;
  const MEDIA_FILE = /\.(png|jpe?g|gif|webp|avif|svg|woff2?|ttf|otf)$/iu;

  /**
   * The page's files: index.html, then every sheet, image and font of this same site it names, with their addresses
   * rewritten as paths of the converter. Others (other sites, refused, missing, too big) keep their address.
   */
  async function downloadPage(page) {
    status.textContent = siteWords.fetching;
    const pageUrl = new URL(page.url, window.location.href);
    const fetched = await fetchHere(pageUrl, MAX_BYTES);
    if (!/^text\/html|^application\/xhtml\+xml/u.test(fetched.type)) throw new Error(siteWords.notHtml);
    const html = new TextDecoder("utf-8").decode(fetched.bytes);
    const doc = new DOMParser().parseFromString(html, "text/html");
    const files = new Map();
    let total = fetched.bytes.byteLength;
    let skipped = 0;
    /** One file of this site, fetched once: its path, or null when it stays out (its address is kept). */
    const pending = new Map();
    const take = (absolute, kind) => {
      let url;
      try {
        url = new URL(absolute);
      } catch {
        return null;
      }
      if (url.origin !== window.location.origin) return null;
      const path = localPath(url);
      if (!path || !(kind === "style" ? STYLE_FILE : MEDIA_FILE).test(path) || !allowedHere(url)) {
        skipped++;
        return null;
      }
      if (!pending.has(path)) pending.set(path, { url, kind });
      return path;
    };
    /** The url() and @import of a sheet, to files of this site written from `fromPath` (the page is at the root). */
    const rewriteCss = (css, baseUrl, fromPath) =>
      css
        .replace(/@import\s+(?:url\(\s*)?(["']?)([^"')\s;]+)\1\s*\)?/giu, (match, quote, ref) => {
          if (/^data:/iu.test(ref)) return match;
          const path = take(new URL(ref, baseUrl).href, "style");
          return path ? `@import url("${relativePath(fromPath, path)}")` : match;
        })
        .replace(/url\(\s*(["']?)([^"')]+)\1\s*\)/giu, (match, quote, ref) => {
          if (/^(data:|#)/iu.test(ref.trim())) return match;
          let absolute;
          try {
            absolute = new URL(ref.trim(), baseUrl).href;
          } catch {
            return match;
          }
          const path = take(absolute, "media");
          return path ? `url("${relativePath(fromPath, path)}")` : match;
        });

    // Nothing that would change what addresses mean or what runs; the site's own Histeon CSS and the admin bar stay out.
    doc.querySelectorAll("base, #wpadminbar").forEach((node) => node.remove());
    doc.querySelectorAll("link[id], style[id], script[id]").forEach((node) => {
      if (/^(xb-|admin-bar)/iu.test(node.id)) node.remove();
    });
    // The canonical address is the page of origin's, which stays: the new page gets its own (addPage.sourcePath).
    doc.querySelectorAll('link[rel~="canonical"]').forEach((node) => node.remove());
    for (const link of doc.querySelectorAll('link[rel~="stylesheet"][href]')) {
      const path = take(new URL(link.getAttribute("href"), pageUrl).href, "style");
      if (path) link.setAttribute("href", encodePath(path));
    }
    for (const style of doc.querySelectorAll("style")) style.textContent = rewriteCss(style.textContent || "", pageUrl, "index.html");
    for (const node of doc.querySelectorAll("[style]")) {
      const value = node.getAttribute("style") || "";
      if (/url\(/iu.test(value)) node.setAttribute("style", rewriteCss(value, pageUrl, "index.html"));
    }
    for (const node of doc.querySelectorAll("img[src], source[src], video[poster], input[type=image][src]")) {
      const attribute = node.localName === "video" ? "poster" : "src";
      const value = node.getAttribute(attribute) || "";
      if (/^data:/iu.test(value)) continue;
      let absolute;
      try {
        absolute = new URL(value, pageUrl).href;
      } catch {
        continue;
      }
      const path = take(absolute, "media");
      if (path) node.setAttribute(attribute, encodePath(path));
    }
    for (const node of doc.querySelectorAll("img[srcset], source[srcset]")) {
      const rewritten = (node.getAttribute("srcset") || "").split(",").map((candidate) => {
        const [ref, ...descriptor] = candidate.trim().split(/\s+/u);
        if (!ref) return candidate;
        let absolute;
        try {
          absolute = new URL(ref, pageUrl).href;
        } catch {
          return candidate.trim();
        }
        const path = take(absolute, "media");
        return [path ? encodePath(path) : ref, ...descriptor].join(" ");
      });
      node.setAttribute("srcset", rewritten.join(", "));
    }

    // The files, a few at a time; a sheet may name more files, which join the queue.
    const done = new Set();
    for (;;) {
      const batch = [...pending.entries()].filter(([path]) => !done.has(path)).slice(0, 4);
      if (!batch.length) break;
      status.textContent = format(siteWords.assets, done.size + batch.length);
      await Promise.all(batch.map(async ([path, { url, kind }]) => {
        done.add(path);
        if (files.size + 1 >= MAX_FILES) {
          skipped++;
          return;
        }
        try {
          const got = await fetchHere(url, MAX_BYTES - total);
          total += got.bytes.byteLength;
          const bytes = kind === "style" ? new TextEncoder().encode(rewriteCss(new TextDecoder("utf-8").decode(got.bytes), got.url, path)) : got.bytes;
          files.set(path, bytes);
        } catch {
          skipped++;
        }
      }));
    }
    // A file that did not come stays named by its path: the converter finds nothing there and leaves it out.
    const serialized = `<!doctype html>\n${doc.documentElement.outerHTML}`;
    return { html: serialized, files, skipped, title: (doc.title || page.title || "").trim() };
  }

  /** The base of AddPageRest, with the media bytes decoded for the converter. */
  async function siteBase() {
    status.textContent = siteWords.base;
    const response = await fetch(site.base, { credentials: "same-origin", cache: "no-store", headers: { "X-WP-Nonce": site.nonce } });
    const body = await response.json().catch(() => null);
    if (!response.ok || !body || typeof body !== "object") throw new Error(format(siteWords.baseFailed, (body && typeof body.message === "string" && body.message) || `HTTP ${response.status}`));
    const files = {};
    for (const [path, data] of Object.entries(body.files || {})) files[path] = Uint8Array.from(atob(String(data)), (char) => char.charCodeAt(0));
    return { ...body, files };
  }

  /** The words of a contract error (AddPageError), from its code; the converter's own message otherwise. */
  function failure(problem) {
    const code = problem && typeof problem === "object" ? problem.code : null;
    const said = (siteWords.codes || {})[code];
    if (said) return format(said, problem.detail || "");
    return problem instanceof Error ? problem.message : String(problem);
  }

  function showPages() {
    const query = slugify(siteSearch.value || "", 200);
    const shown = sitePages.filter((page) => !query || slugify(`${page.title} ${page.path}`, 400).includes(query)).slice(0, 500);
    siteList.replaceChildren(...shown.map((page) => {
      const option = element("option", { value: String(page.id), textContent: `${page.title || page.path} — ${page.path}` });
      return option;
    }));
    if (shown.length) siteList.selectedIndex = 0;
    siteCount.textContent = sitePages.length ? format(siteWords.shown, shown.length) : siteWords.empty;
    siteImport.disabled = busy || !shown.length;
  }

  siteOpen?.addEventListener("click", async () => {
    if (busy) return;
    error.hidden = true;
    sitePicker.hidden = false;
    siteCount.textContent = siteWords.loading;
    siteImport.disabled = true;
    try {
      const response = await fetch(site.list, { credentials: "same-origin", cache: "no-store", headers: { "X-WP-Nonce": site.nonce } });
      const body = await response.json().catch(() => null);
      if (!response.ok || !body || !Array.isArray(body.pages)) throw new Error((body && typeof body.message === "string" && body.message) || `HTTP ${response.status}`);
      sitePages = body.pages.filter((page) => page && typeof page.url === "string").map((page) => ({ id: Number(page.id), title: String(page.title || ""), url: page.url, path: String(page.path || ""), slug: String(page.slug || "") }));
      const left = [];
      if (body.histeon > 0) left.push(format(siteWords.histeon, body.histeon));
      if (body.protected > 0) left.push(format(siteWords.protected, body.protected));
      if (body.more) left.push(format(siteWords.more, sitePages.length));
      siteLeft.replaceChildren(...left.map((text) => element("li", { textContent: text })));
      showPages();
      siteSearch.focus();
    } catch (problem) {
      siteCount.textContent = "";
      fail(format(siteWords.listFailed, problem instanceof Error ? problem.message : String(problem)));
    }
  });
  siteSearch?.addEventListener("input", showPages);

  /** What the addition did, in plain words; every name of the site enters as text. */
  function showAdded(report, extra) {
    if (!report) {
      added.hidden = true;
      added.replaceChildren();
      return;
    }
    const line = (text) => element("li", { textContent: text });
    const lines = [...extra.map(line), line(format(siteWords.slug, report.slug))];
    lines.push(line(format(siteWords.entered, report.stylesAdded.length, report.sheetsAdded.length, report.mediaAdded.length, report.queriesAdded.length)));
    if (report.stylesSame.length || report.mediaReused.length) lines.push(line(format(siteWords.same, report.stylesSame.length, report.mediaReused.length)));
    const kept = [...report.stylesKept, ...report.variablesKept, ...report.queriesKept];
    if (kept.length) {
      const item = line(format(siteWords.kept, report.stylesKept.length, report.variablesKept.length, report.queriesKept.length));
      item.append(element("details", {}, element("summary", { textContent: siteWords.keptList }), element("ul", {}, ...kept.slice(0, 200).map((name) => element("li", {}, element("code", { textContent: name }))))));
      lines.push(item);
    }
    if (report.mark) {
      lines.push(line(format(siteWords.scoped, `data-histos-pagina="${report.mark}"`)));
      lines.push(line(siteWords.scopeLimit));
    }
    if (report.colors) lines.push(...colorLines(report.colors));
    if (report.designKept) lines.push(line(siteWords.design));
    for (const renamed of report.mediaRenamed) lines.push(line(format(siteWords.renamed, renamed.from, renamed.to)));
    for (const renamed of Array.isArray(report.renamed) ? report.renamed : []) lines.push(line(format(siteWords.renamedCss, (siteWords.renamedKinds || {})[renamed.kind] || renamed.kind, renamed.from, renamed.to)));
    for (const left of report.leftOut) lines.push(line(format(siteWords.leftOut, left.reason)));
    added.replaceChildren(...lines);
    added.hidden = false;
  }

  /** The colours of the report in plain words: the choice, the page's brand colours with their samples, the rest. */
  function colorLines(colors) {
    const out = [];
    const changes = Array.isArray(colors.changes) ? colors.changes : [];
    if (colors.mode === "pagina") out.push(element("li", { textContent: format(colorWords.pagina, Number(colors.kept) || 0) }));
    else if (colors.mode === "sito") out.push(element("li", { textContent: colorWords.sito || "" }));
    else if (colors.mode === "sostituisci") out.push(element("li", { textContent: format(colorWords.sostituisci, changes.length) }));
    const page = Array.isArray(colors.page) ? colors.page : [];
    if (page.length) {
      const item = element("li", { textContent: colorWords.page || "" });
      item.dataset.pageColors = "";
      item.append(element("ul", {}, ...page.map((color) => element("li", {}, `${(colorWords.families || {})[color.family] || String(color.family)}: `, element("code", { textContent: `var(${String(color.variable)})` }), " = ", colorValue(color.value)))));
      out.push(item);
    } else out.push(element("li", { textContent: colorWords.none || "" }));
    out.push(element("li", { textContent: colorWords.others || "" }));
    return out;
  }

  /**
   * «sostituisci»: before anything reaches «Controlla», the variables that change in the whole site, «prima → dopo», by
   * theme, with a sample of each colour; a confirmation goes on, «Annulla» goes back to the choice.
   */
  function showColorChanges(colors) {
    if (!colorsConfirm) return;
    if (!colors) {
      colorsConfirm.hidden = true;
      colorsConfirm.replaceChildren();
      return;
    }
    const nodes = [element("h3", { id: "xb-site-colors-title", textContent: colorWords.title || "" }), element("p", {}, element("strong", { textContent: colorWords.whole || "" }))];
    const settings = Array.isArray(colors.settings) ? colors.settings : [];
    if (settings.length) {
      nodes.push(element("p", { textContent: colorWords.settings || "" }));
      nodes.push(element("ul", { className: "xb-site-colors__list" }, ...settings.map((row) => changeItem(String(row.name), row.before, row.after))));
    }
    const changes = Array.isArray(colors.changes) ? colors.changes : [];
    for (const [theme, label] of [["chiaro", colorWords.light], ["scuro", colorWords.dark]]) {
      const rows = changes.filter((row) => row.theme === theme);
      if (!rows.length) continue;
      const list = element("ul", { className: "xb-site-colors__list" }, ...rows.map((row) => changeItem(String(row.variable), row.before, row.after)));
      list.dataset.colorsTheme = theme;
      nodes.push(element("h4", { textContent: `${label || theme} (${rows.length})` }), list);
    }
    for (const row of Array.isArray(colors.covered) ? colors.covered : []) nodes.push(element("p", { className: "notice notice-warning inline", textContent: format(colorWords.covered, String(row.variable), String(row.sheet)) }));
    const yes = element("button", { type: "button", className: "xb-btn xb-btn--primary", textContent: colorWords.confirm || "" });
    yes.dataset.colorsYes = "";
    const no = element("button", { type: "button", className: "xb-btn", textContent: colorWords.cancel || "" });
    no.dataset.colorsNo = "";
    yes.addEventListener("click", () => {
      if (!current?.held || busy) return;
      current.held = false;
      showColorChanges(null);
      showAdded(current.converted.addPage, current.extra || []);
      show(current.converted, current.source);
      showRefine();
    });
    no.addEventListener("click", () => {
      if (busy) return;
      current = null;
      showColorChanges(null);
      showRefine();
      showAdded(null);
      status.textContent = colorWords.cancelled || "";
      root.querySelector('[data-site-colors] input[type="radio"]:checked')?.focus();
    });
    nodes.push(element("p", { className: "xb-refine__actions" }, yes, no));
    colorsConfirm.replaceChildren(...nodes);
    colorsConfirm.hidden = false;
    yes.focus();
  }

  /** The colours the person chose; «pagina» when nothing else is checked (it changes nothing of the site). */
  function chosenColors() {
    const checked = root.querySelector('[data-site-colors] input[type="radio"]:checked');
    const value = checked ? checked.value : "pagina";
    return value === "sostituisci" && !site.replace ? "pagina" : ["pagina", "sito", "sostituisci"].includes(value) ? value : "pagina";
  }

  siteImport?.addEventListener("click", () => {
    const page = sitePages.find((item) => String(item.id) === siteList.value);
    if (!page) {
      fail(siteWords.choose);
      return;
    }
    const colors = chosenColors();
    void run(async () => {
      const downloaded = await downloadPage(page);
      const base = await siteBase();
      const slug = freeSlug(page, base.slugs || []);
      const files = [{ path: "index.html", bytes: new TextEncoder().encode(downloaded.html) }, ...[...downloaded.files].map(([path, bytes]) => ({ path, bytes }))];
      status.textContent = siteWords.converting;
      const extra = [format(siteWords.address, `/${slug}/`)];
      if (downloaded.skipped) extra.push(format(siteWords.skipped, downloaded.skipped));
      return { files, name: downloaded.title || page.title || slug, refused: 0, framework: site.framework || "none", addPage: { base, sourcePath: `/${slug}/`, colors }, extra };
    });
  });

  convert.addEventListener("click", () => void run(chosenFiles));

  /** One conversion: the files from `source()`, the names a model proposes when asked, then the result to check. */
  async function run(source) {
    if (busy) return;
    busy = true;
    error.hidden = true;
    result.hidden = true;
    convert.disabled = true;
    if (siteImport) siteImport.disabled = true;
    current = null;
    showRefine();
    showAdded(null);
    showColorChanges(null);
    // The form «Controlla» never keeps the package of a conversion before this one.
    const empty = $("[data-package]");
    if (empty) empty.files = new DataTransfer().files;
    status.textContent = strings.working;
    let controller = null;
    try {
      const chosen = await source();
      if (!chosen.files.some((file) => /\.html?$/iu.test(file.path))) throw new Error(strings.noPage);
      sqlite ??= await sqlite3InitModule({ print: () => undefined, printErr: () => undefined });
      const input = { files: chosen.files, name: chosen.name.slice(0, 120), ...(config.projectId ? { projectId: config.projectId } : {}), framework: chosen.framework || framework.value, recipes: recipes.checked, ...(chosen.addPage ? { addPage: chosen.addPage, projectId: chosen.addPage.base.projectId } : {}) };
      const info = { name: chosen.addPage ? chosen.addPage.sourcePath.replace(/^\/|\/$/gu, "") : chosen.name, refused: chosen.refused };
      const converted = await convertToXbsite(input, sqlite);
      current = { input, source: info, converted, applied: {}, refine: null, extra: chosen.extra || [] };
      if (refineToggle?.checked && !refineToggle.disabled) {
        if (!converted.choices.length) current.refine = { empty: true };
        else {
          controller = new AbortController();
          cancel.hidden = false;
          cancel.onclick = () => controller?.abort();
          const report = refineConfig.connector ? await askConnector(converted.choices, input.name, controller.signal) : await askModel(converted.choices, controller.signal);
          current.refine = tableOf(report, converted.choices, input);
        }
      }
      if (chosen.addPage?.colors === "sostituisci") {
        // The whole site changes: what changes is shown first, and nothing goes to «Controlla» before the confirmation.
        current.held = true;
        showColorChanges(converted.addPage?.colors || null);
        status.textContent = colorWords.waiting || "";
      } else {
        showAdded(converted.addPage, chosen.extra || []);
        show(converted, info);
      }
    } catch (problem) {
      fail(`${strings.failed} ${failure(problem)}`);
    } finally {
      cancel.hidden = true;
      cancel.onclick = null;
      busy = false;
      convert.disabled = false;
      if (siteImport) siteImport.disabled = !siteList?.options.length;
      showRefine();
    }
  }
}
