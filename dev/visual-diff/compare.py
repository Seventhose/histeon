"""Before/after: page height, pixel difference, DOM differences (text, geometry, computed styles)."""
import json, sys, difflib, pathlib
from PIL import Image, ImageChops
a, b = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
detail = len(sys.argv) > 3
rows = []
for fa in sorted(a.glob('*.json')):
    fb = b / fa.name
    if not fb.exists(): rows.append((fa.stem, 'MISSING')); continue
    A, B = json.loads(fa.read_text()), json.loads(fb.read_text())
    ia, ib = Image.open(fa.with_suffix('.png')).convert('RGB'), Image.open(fb.with_suffix('.png')).convert('RGB')
    w, h = min(ia.width, ib.width), min(ia.height, ib.height)
    diff = ImageChops.difference(ia.crop((0,0,w,h)), ib.crop((0,0,w,h))).convert('L').point(lambda v: 255 if v > 24 else 0)
    px = sum(diff.histogram()[255:]) / (w*h) * 100
    ka = [(n['t'], n['c'], n['text']) for n in A['nodes']]; kb = [(n['t'], n['c'], n['text']) for n in B['nodes']]
    sm = difflib.SequenceMatcher(None, ka, kb, autojunk=False)
    geo = sty = 0; samples = []; seen = set()
    for tag, i1, i2, j1, j2 in sm.get_opcodes():
        if tag == 'equal':
            for x, y in zip(A['nodes'][i1:i2], B['nodes'][j1:j2]):
                g = any(abs(x[k]-y[k]) > 2 for k in ('x','y','w','h')); s = x['s'] != y['s']
                geo += g; sty += s
                key = (x['t'], x['c'][:40], g, s)
                if (g or s) and key not in seen and len(samples) < 14:
                    seen.add(key); samples.append(('geo' if g else '', 'style' if s else '', x['t'], x['c'][:40], x['text'][:30], (x['x'],x['y'],x['w'],x['h']), (y['x'],y['y'],y['w'],y['h']), [ (p, q) for p, q in zip(x['s'].split('|'), y['s'].split('|')) if p != q ][:3]))
        elif len(samples) < 14:
            samples.append((tag, A['nodes'][i1:i2][:2] and [(n['t'], n['c'][:30], n['text'][:30]) for n in A['nodes'][i1:i2][:2]], [(n['t'], n['c'][:30], n['text'][:30]) for n in B['nodes'][j1:j2][:2]]))
    struct = sum(max(i2-i1, j2-j1) for t,i1,i2,j1,j2 in sm.get_opcodes() if t != 'equal')
    rows.append((fa.stem, A['status'], B['status'], ia.height, ib.height, round(px,2), len(ka), struct, geo, sty, samples))
print(f"{'pagina':70} st  altezza(prima→dopo)  px%  nodi  strutt  geom  stile")
for r in rows:
    if r[1] == 'MISSING': print(r[0], 'MANCA'); continue
    print(f"{r[0][:70]:70} {r[1]}→{r[2]} {r[3]:6}→{r[4]:6} {r[5]:6} {r[6]:5} {r[7]:6} {r[8]:5} {r[9]:5}")
    if detail and (r[7] or r[8] or r[9] or r[5] > 0.5):
        for s in r[10]: print('      ', s)

# Summary
vals = sorted((r[5], r[0]) for r in rows if r[1] != 'MISSING')
if vals:
    import statistics
    print(f"\nRIEPILOGO: {len(vals)} catture, mediana {statistics.median(v for v,_ in vals):.2f}%, "
          f"sotto 1%: {sum(1 for v,_ in vals if v < 1)}, sopra 5%: {sum(1 for v,_ in vals if v > 5)}")
    for v, n in vals:
        if v > 1: print(f"  {v:6.2f}%  {n}")
