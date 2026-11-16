// XB facets in a static copy (docs/spec/facets.md §5, P9): no WordPress answers here, so the page carries every
// result (marked data-xb-facet-item="name:id") and an index (script#xb-facets-index-<name>, JSON) with each
// result's values. This script filters, sorts and counts in the browser, from the form and from the address, so
// the filter links of the page work too. Without it, every result stays visible.
(() => {
  'use strict';

  const read = (name) => {
    try {
      return JSON.parse(document.getElementById(`xb-facets-index-${name}`)?.textContent || 'null');
    } catch {
      return null;
    }
  };

  /** The chosen values, from the address or from a form: lists joined with commas, as the server writes them. */
  function chosen(index, params) {
    const out = {};
    for (const f of index.filters) {
      if (f.kind === 'range') {
        const both = params.get(f.key);
        const [min, max] = both && both.includes('..') ? both.split('..') : [params.get(`${f.key}_min`) || '', params.get(`${f.key}_max`) || ''];
        out[f.key] = min || max ? [min, max] : null;
      } else if (f.kind === 'taxonomy') {
        const list = params.getAll(`${f.key}[]`).concat((params.get(f.key) || '').split(',')).filter(Boolean);
        out[f.key] = list.length ? list : null;
      } else {
        out[f.key] = (params.get(f.key) || '').trim() || null;
      }
    }
    return out;
  }

  const comparable = (value, type) => (type === 'number' ? Number(value) : String(value));

  function matches(index, item, values) {
    for (const f of index.filters) {
      const want = values[f.key];
      if (want === null || f.kind === 'sort') continue;
      const have = item.v[f.key];
      if (f.kind === 'search') {
        if (!want.toLowerCase().split(/\s+/).every((w) => item.t.includes(w))) return false;
      } else if (f.kind === 'taxonomy') {
        const set = new Set(have || []);
        if (f.match === 'all' ? !want.every((s) => set.has(s)) : !want.some((s) => set.has(s))) return false;
      } else if (f.kind === 'field') {
        if (String(have ?? '') !== want) return false;
      } else if (f.kind === 'range') {
        if (have === undefined || have === '') return false;
        const [min, max] = want;
        const v = comparable(have, f.type);
        if (min !== '' && v < comparable(min, f.type)) return false;
        if (max !== '' && v > comparable(max, f.type)) return false;
      }
    }
    return true;
  }

  function apply(index, params) {
    const values = chosen(index, params);
    const sort = index.filters.find((f) => f.kind === 'sort');
    const order = sort ? values[sort.key] : null;
    const byId = new Map(index.items.map((item) => [String(item.id), item]));
    const nodes = Array.from(document.querySelectorAll('[data-xb-facet-item]')).filter((el) => el.getAttribute('data-xb-facet-item').startsWith(`${index.name}:`));
    let shown = 0;
    for (const el of nodes) {
      const item = byId.get(el.getAttribute('data-xb-facet-item').slice(index.name.length + 1));
      const keep = !!item && matches(index, item, values);
      el.hidden = !keep;
      if (keep) shown++;
    }
    if (order && Object.prototype.hasOwnProperty.call(index.sorts, order)) {
      const dir = index.sorts[order] === 'desc' ? -1 : 1;
      const key = (el) => byId.get(el.getAttribute('data-xb-facet-item').slice(index.name.length + 1))?.s?.[order] ?? '';
      const parents = new Set(nodes.map((el) => el.parentNode));
      for (const parent of parents) {
        const mine = nodes.filter((el) => el.parentNode === parent);
        mine.sort((a, b) => (key(a) < key(b) ? -dir : key(a) > key(b) ? dir : 0)).forEach((el) => parent.appendChild(el));
      }
    }
    for (const el of document.querySelectorAll(`[data-xb-facet-total="${index.name}"]`)) el.textContent = String(shown);
    // The form shows what is chosen.
    for (const form of document.querySelectorAll(`form[data-xb-facet="${index.name}"], [data-xb-facet="${index.name}"] form`)) {
      for (const f of index.filters) {
        for (const field of form.querySelectorAll(`[name="${f.key}"], [name="${f.key}[]"]`)) {
          if (field.type === 'checkbox' || field.type === 'radio') field.checked = (Array.isArray(values[f.key]) ? values[f.key] : [values[f.key]]).includes(field.value);
          else if (f.kind !== 'range') field.value = typeof values[f.key] === 'string' ? values[f.key] : '';
        }
      }
    }
    document.dispatchEvent(new CustomEvent('xb:facets', { detail: { name: index.name, total: shown } }));
  }

  function formParams(form) {
    const params = new URLSearchParams();
    for (const [key, value] of new FormData(form)) if (typeof value === 'string' && value !== '') params.append(key, value);
    return params;
  }

  for (const script of document.querySelectorAll('script[id^="xb-facets-index-"]')) {
    const index = read(script.id.slice('xb-facets-index-'.length));
    if (!index || !Array.isArray(index.items) || !Array.isArray(index.filters)) continue;
    apply(index, new URLSearchParams(location.search));
    for (const form of document.querySelectorAll(`form[data-xb-facet="${index.name}"], [data-xb-facet="${index.name}"] form`)) {
      const run = () => {
        const params = formParams(form);
        apply(index, params);
        history.replaceState(null, '', `${location.pathname}${params.toString() ? `?${params}` : ''}${location.hash}`);
      };
      let timer;
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        run();
      });
      form.addEventListener('change', run);
      form.addEventListener('input', (e) => {
        if (e.target instanceof HTMLInputElement && e.target.type === 'search') {
          clearTimeout(timer);
          timer = setTimeout(run, 250);
        }
      });
    }
  }
})();
