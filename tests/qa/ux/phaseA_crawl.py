"""Phase A: discover nav links and sweep each page at a viewport, light+dark, with programmatic checks."""
import sys, json, re, time
from ux_common import *

def nav_links(pg):
    return pg.evaluate("""()=>{const s=new Set();document.querySelectorAll('a[href]').forEach(a=>{
      const h=a.getAttribute('href'); if(!h||h.startsWith('#')||h.startsWith('javascript')||h.startsWith('http')||h.startsWith('mailto')) return;
      if(/logout|delete|archive|restore|send|download|export|pdf|\\.zip|cron|backup|post\\.php|update_check|force_update|do_update|switch/.test(h)) return;
      s.add(new URL(h,location.href).pathname+new URL(h,location.href).search)});return [...s]}""")

def main(w, h, area='agent', user='admin'):
    p, b, ctx, pg = start(user, w, h, portal=(area=='portal'))
    errs = {}
    pg.on('console', lambda m: errs.setdefault(pg.url, []).append(m.text[:140]) if m.type == 'error' else None)
    fails = []
    pg.on('response', lambda r: fails.append((r.status, r.url[:110])) if r.status >= 400 else None)
    start_url = BASE + ('/client/index.php' if area == 'portal' else '/agent/dashboard.php')
    pg.goto(start_url); pg.wait_for_load_state('networkidle')
    seeds = set(nav_links(pg))
    # include top-menu dropdown targets too (already in DOM)
    import os as _os
    lst = _os.environ.get('QA_PAGES')
    pages = json.load(open(lst)) if lst else sorted(x for x in seeds if x.startswith('/' + ('client' if area=='portal' else area)) or area=='any')
    print(len(pages), 'pages to sweep', file=sys.stderr)
    results = []
    for path in pages:
        fails.clear()
        try:
            resp = pg.goto(BASE + path, wait_until='domcontentloaded'); pg.wait_for_timeout(900)
            try: pg.wait_for_load_state('networkidle', timeout=4000)
            except Exception: pass
        except Exception as e:
            results.append({'path': path, 'error': str(e)[:100]}); continue
        for dark in (False, True):
            set_theme(pg, dark)
            tag = re.sub(r'[^a-z0-9]+', '_', path.lower()).strip('_')[:60] + f"__{w}x{h}__{'dark' if dark else 'light'}"
            pg.screenshot(path=f"{OUT}/{area}/{tag}.png", full_page=True)
            r = audit(pg, axe=(not dark)) if True else {}
            r.update(path=path, theme='dark' if dark else 'light', status=resp.status if resp else None,
                     console=errs.get(pg.url, [])[:3], failed=list(fails)[:4], title=pg.title()[:50])
            results.append(r)
        set_theme(pg, False)
    json.dump(results, open(f"{OUT}/{area}_{w}x{h}.json", 'w'), indent=1)
    b.close(); p.stop()

if __name__ == '__main__':
    import os
    area = sys.argv[3] if len(sys.argv) > 3 else 'agent'
    os.makedirs(f"{OUT}/{area}", exist_ok=True)
    main(int(sys.argv[1]), int(sys.argv[2]), area, sys.argv[4] if len(sys.argv) > 4 else 'admin')
