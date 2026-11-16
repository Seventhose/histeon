// XB facets (docs/spec/facets.md): filters, sorting and pages without reloading. The forms and links already
// work without this script; here the same address is fetched and the parts marked data-xb-facet="name" are
// swapped with those of the new page. Same origin only; nothing from the response runs (DOMParser documents
// are inert and their scripts never execute once moved).
(() => {
  'use strict';
  const controllers = new Map();

  const sameOrigin = (url) => url.origin === location.origin;
  const regions = (doc, name) => Array.from(doc.querySelectorAll('[data-xb-facet]')).filter((el) => el.getAttribute('data-xb-facet') === name);

  /** A form's address: its fields as parameters, lists (name[]) joined with commas, empty ones left out. */
  function formUrl(form) {
    const url = new URL(form.getAttribute('action') || location.pathname, location.href);
    url.search = '';
    const lists = new Map();
    for (const [key, value] of new FormData(form)) {
      if (typeof value !== 'string' || value === '') continue;
      if (key.endsWith('[]')) {
        const name = key.slice(0, -2);
        lists.set(name, [...(lists.get(name) || []), value]);
      } else {
        url.searchParams.set(key, value);
      }
    }
    for (const [name, values] of lists) url.searchParams.set(name, values.join(','));
    return url;
  }

  /** What had focus, to give it back after the swap (a search field keeps its caret). */
  function focusState() {
    const el = document.activeElement;
    if (!el || !el.closest('[data-xb-facet]')) return null;
    return {
      name: el.getAttribute('name'),
      value: el.type === 'checkbox' || el.type === 'radio' ? el.value : null,
      start: typeof el.selectionStart === 'number' ? el.selectionStart : null,
    };
  }

  function restoreFocus(state, scope) {
    if (!state || !state.name) return;
    const el = Array.from(scope.querySelectorAll('[name]')).find((n) => n.getAttribute('name') === state.name && (state.value === null || n.value === state.value));
    if (!el) return;
    el.focus({ preventScroll: true });
    if (state.start !== null && typeof el.setSelectionRange === 'function') {
      try {
        el.setSelectionRange(state.start, state.start);
      } catch {
        // Not a text field.
      }
    }
  }

  async function update(name, url, push) {
    // Never fetched, and never followed by the fallback below: a changed form cannot take the visitor elsewhere.
    if (!sameOrigin(url)) return;
    if (controllers.has(name)) controllers.get(name).abort();
    const controller = new AbortController();
    controllers.set(name, controller);
    const current = regions(document, name);
    current.forEach((r) => r.setAttribute('aria-busy', 'true'));
    try {
      const response = await fetch(url.href, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'text/html' } });
      if (!response.ok || !sameOrigin(new URL(response.url))) throw new Error(String(response.status));
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      const next = regions(doc, name);
      if (next.length !== current.length) throw new Error('regions');
      const focus = focusState();
      const fresh = next.map((r) => document.importNode(r, true));
      current.forEach((r, i) => r.replaceWith(fresh[i]));
      if (push) history.pushState({ xbFacet: name }, '', url.href);
      fresh.forEach((r) => restoreFocus(focus, r));
      document.dispatchEvent(new CustomEvent('xb:facets', { detail: { name, url: url.href } }));
    } catch (error) {
      if (error && error.name === 'AbortError') return;
      location.assign(url.href); // Whatever went wrong, the normal page load still works.
    }
  }

  function regionName(el) {
    const region = el.closest('[data-xb-facet]');
    return region ? region.getAttribute('data-xb-facet') : null;
  }

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || (form.method || 'get').toLowerCase() !== 'get') return;
    const name = regionName(form);
    if (!name) return;
    const url = formUrl(form);
    if (!sameOrigin(url)) return;
    event.preventDefault();
    void update(name, url, true);
  });

  let typing = 0;
  function onChange(event) {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement) || !field.form) return;
    const name = regionName(field.form);
    if (!name || (field.form.method || 'get').toLowerCase() !== 'get') return;
    const text = field instanceof HTMLInputElement && (field.type === 'search' || field.type === 'text');
    if (event.type === 'input' && !text) return;
    if (event.type === 'change' && text) return; // Text: after a pause while typing, or on Enter.
    if (!sameOrigin(formUrl(field.form))) return; // As on submit: only this site's own addresses.
    clearTimeout(typing);
    const go = () => update(name, formUrl(field.form), true);
    if (text) typing = setTimeout(go, 450);
    else void go();
  }
  document.addEventListener('change', onChange);
  document.addEventListener('input', onChange);

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link || link.target || link.hasAttribute('download')) return;
    const name = regionName(link);
    if (!name) return;
    const url = new URL(link.href, location.href);
    if (!sameOrigin(url) || url.pathname !== location.pathname) return;
    event.preventDefault();
    void update(name, url, true);
  });

  window.addEventListener('popstate', () => {
    const names = new Set(Array.from(document.querySelectorAll('[data-xb-facet]')).map((r) => r.getAttribute('data-xb-facet')));
    names.forEach((name) => void update(name, new URL(location.href), false));
  });
})();
