/*
 * Histeon review bar (docs/spec/review.md): on a page opened with a signed preview link, comment on an element
 * or approve the version. Everything typed is sent as plain text and shown with textContent only.
 */
(() => {
  const cfg = window.xbReview;
  if (!cfg || !cfg.token) return;
  // Texts in the reviewer's language, sent by the server (Review\Comments::texts()).
  const I18N = cfg.i18n && typeof cfg.i18n === 'object' ? cfg.i18n : {};
  const T = (text, ...args) => { let i = 0; return (I18N[text] || text).replace(/%s/g, () => String(args[i++] ?? '')); };
  const NAME_KEY = 'xb-review-name';
  const remembered = () => {
    try {
      return localStorage.getItem(NAME_KEY) || '';
    } catch {
      return '';
    }
  };
  const remember = (name) => {
    try {
      localStorage.setItem(NAME_KEY, name);
    } catch {
      /* private window: asked again next time */
    }
  };
  const el = (tag, attrs = {}, text = '') => {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
    if (text) n.textContent = text;
    return n;
  };
  const send = async (url, body) => {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token: cfg.token, email: '', ...body }), credentials: 'omit' });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message || T('Non inviato'));
    return data;
  };

  const bar = el('div', { class: 'xb-review', role: 'region', 'aria-label': T('Revisione') });
  const comment = el('button', { type: 'button', class: 'xb-review__btn', 'aria-pressed': 'false' }, T('Commenta'));
  const approve = el('button', { type: 'button', class: 'xb-review__btn xb-review__btn--ok' }, T('Approva'));
  const note = el('span', { class: 'xb-review__note', role: 'status', 'aria-live': 'polite' });
  bar.append(comment, approve, note);
  document.body.append(bar);
  const say = (text) => {
    note.textContent = text;
    clearTimeout(say.t);
    say.t = setTimeout(() => (note.textContent = ''), 5000);
  };

  // Comment mode: hover outlines an element, a click opens the box for it.
  let on = false;
  let hovered = null;
  const target = (e) => (e.target instanceof Element && !bar.contains(e.target) && !box.contains(e.target) ? e.target.closest('[data-xb-uid]') : null);
  const outline = (node) => {
    hovered?.classList.remove('xb-review-hover');
    hovered = node;
    hovered?.classList.add('xb-review-hover');
  };
  const setMode = (value) => {
    on = value;
    comment.setAttribute('aria-pressed', String(on));
    comment.textContent = on ? T('Scegli un elemento… (Esc)') : T('Commenta');
    document.documentElement.classList.toggle('xb-review-picking', on);
    if (!on) outline(null);
  };
  comment.addEventListener('click', () => setMode(!on));
  document.addEventListener('mouseover', (e) => on && outline(target(e)));
  document.addEventListener(
    'click',
    (e) => {
      if (!on) return;
      const node = target(e);
      if (!node) return;
      e.preventDefault();
      e.stopPropagation();
      open(node);
    },
    true,
  );
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || e.defaultPrevented) return;
    if (!box.hidden) {
      e.preventDefault();
      close();
    } else if (on) {
      e.preventDefault();
      setMode(false);
    }
  });

  // The comment box.
  const box = el('form', { class: 'xb-review__box', 'aria-label': T('Commento') });
  box.hidden = true;
  const text = el('textarea', { rows: '4', maxlength: '2000', 'aria-label': T('Commento'), placeholder: T('Cosa cambieresti qui?') });
  const name = el('input', { type: 'text', maxlength: '80', 'aria-label': T('Il tuo nome'), placeholder: T('Il tuo nome') });
  // Never visible: a bot that fills every field fills this one too.
  const trap = el('input', { type: 'email', name: 'email', tabindex: '-1', autocomplete: 'off', class: 'xb-review__trap', 'aria-hidden': 'true' });
  const sendBtn = el('button', { type: 'submit', class: 'xb-review__btn xb-review__btn--ok' }, T('Invia'));
  const cancel = el('button', { type: 'button', class: 'xb-review__btn' }, T('Annulla'));
  const row = el('div', { class: 'xb-review__row' });
  row.append(sendBtn, cancel);
  box.append(text, name, trap, row);
  document.body.append(box);
  let uid = '';
  const open = (node) => {
    uid = node.getAttribute('data-xb-uid') || '';
    const r = node.getBoundingClientRect();
    box.style.top = `${Math.max(8, window.scrollY + r.top)}px`;
    box.style.left = `${Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - 340)}px`;
    name.value = remembered();
    box.hidden = false;
    text.focus();
  };
  const close = () => {
    box.hidden = true;
    text.value = '';
  };
  cancel.addEventListener('click', close);
  box.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!text.value.trim()) return;
    sendBtn.disabled = true;
    try {
      await send(cfg.comments, { uid, text: text.value, name: name.value, email: trap.value });
      remember(name.value);
      close();
      setMode(false);
      say('Commento inviato. Grazie!');
    } catch (err) {
      say(err.message);
    } finally {
      sendBtn.disabled = false;
    }
  });

  approve.addEventListener('click', async () => {
    const who = window.prompt('Approvi questa versione della pagina? Scrivi il tuo nome:', remembered());
    if (!who || !who.trim()) return;
    try {
      await send(cfg.approve, { name: who, head: cfg.head || '', email: trap.value });
      remember(who);
      say(T('Approvata da %s.', who));
    } catch (err) {
      say(err.message);
    }
  });
})();
