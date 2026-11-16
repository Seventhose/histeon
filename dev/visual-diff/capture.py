"""Screenshots + DOM signatures of a list of URLs (desktop and mobile), for before/after comparison."""
import json, sys, re, hashlib, pathlib
from playwright.sync_api import sync_playwright

out = pathlib.Path(sys.argv[1]); out.mkdir(parents=True, exist_ok=True)
urls = [u.strip() for u in open(sys.argv[2]) if u.strip()]
FREEZE = """*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}
#wpadminbar{display:none!important}"""
SIGNATURE = r"""() => {
  const props = ['display','position','color','background-color','font-family','font-size','font-weight','line-height','margin-top','margin-bottom','padding-top','padding-left','gap','border-top-width','border-radius','text-align'];
  const out = [];
  for (const el of document.body.querySelectorAll('*')) {
    if (['SCRIPT','STYLE','NOSCRIPT','TEMPLATE','LINK','META'].includes(el.tagName)) continue;
    const r = el.getBoundingClientRect(); const s = getComputedStyle(el);
    if (s.display === 'none') continue;
    const text = [...el.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join(' ').slice(0, 80);
    out.push({t: el.tagName.toLowerCase(), c: (el.getAttribute('class')||'').trim(), x: Math.round(r.x), y: Math.round(r.y + scrollY), w: Math.round(r.width), h: Math.round(r.height), text, s: props.map(p => s.getPropertyValue(p)).join('|')});
  }
  return out;
}"""
with sync_playwright() as p:
    browser = p.chromium.launch()
    for name, vp in (('desktop', {'width': 1366, 'height': 900}), ('mobile', {'width': 390, 'height': 844})):
        ctx = browser.new_context(viewport=vp, reduced_motion='reduce', locale='it-IT', timezone_id='Europe/Rome')
        page = ctx.new_page()
        for url in urls:
            slug = re.sub(r'[^a-z0-9]+', '-', url.split('//',1)[-1].split('/',1)[-1].lower()).strip('-') or 'home'
            try:
                resp = page.goto(url, wait_until='networkidle', timeout=60000)
                page.add_style_tag(content=FREEZE)
                page.wait_for_timeout(400)
                page.screenshot(path=str(out / f'{slug}.{name}.png'), full_page=True)
                sig = page.evaluate(SIGNATURE)
                (out / f'{slug}.{name}.json').write_text(json.dumps({'url': url, 'status': resp.status if resp else 0, 'title': page.title(), 'nodes': sig}, ensure_ascii=False))
                print(name, resp.status if resp else '?', slug, len(sig), flush=True)
            except Exception as e:
                print(name, 'ERROR', slug, e, flush=True)
        ctx.close()
    browser.close()
