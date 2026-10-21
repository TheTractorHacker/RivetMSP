"""BFS-discover navigable GET pages for a role. Skips state-changing/outbound links. Keeps <=2 samples per script path (id variants)."""
import os
import sys, json, re, collections
from ux_common import *
BAD = re.compile(r'logout|delete|archive|restore|send|download|export|pdf|\.zip|cron|backup|post\.php|force_update|do_update|switch_|impersonat|unarchive|mark_|clear|reset|toggle|approve|cancel|enable|disable|sync|odoo|check_update|run_', re.I)
def norm(u):
    path = u.split('?')[0]; return path
def main(user, prefix, start, limit=500):
    p, b, ctx, pg = start_(user)
    seen = {}; per = collections.Counter(); q = [start] + ([] if os.environ.get('QA_PORTAL') else ['/admin/', '/admin/settings.php', '/agent/reports/'])
    while q and len(seen) < limit:
        u = q.pop(0)
        if u in seen: continue
        try: r = pg.goto(BASE + u, wait_until='domcontentloaded'); pg.wait_for_timeout(700)
        except Exception as e: seen[u] = 'ERR'; continue
        seen[u] = r.status if r else None
        if not pg.url.startswith(BASE + prefix) and not pg.url.startswith(BASE+'/agent') and not pg.url.startswith(BASE+'/admin'): continue
        for h in pg.evaluate("[...document.querySelectorAll('a[href]')].map(a=>a.getAttribute('href'))"):
            if not h or h[0] in '#' or h.startswith(('javascript','mailto','tel','http')): continue
            from urllib.parse import urljoin, urlparse
            full = urljoin(BASE + urlparse(pg.url).path, h); pu = urlparse(full)
            if pu.netloc != urlparse(BASE).netloc: continue
            path = pu.path + ('?' + pu.query if pu.query else '')
            if not pu.path.startswith(prefix) and not (prefix=='/agent' and pu.path.startswith('/admin')): continue
            if not pu.path.endswith(('.php', '/')): continue
            if BAD.search(path): continue
            key = pu.path + '|' + ','.join(sorted(k for k in dict(x.split('=')[0:1]*2 for x in pu.query.split('&') if x)))
            if per[key] >= int(os.environ.get('QA_PER','1')) or path in seen: continue
            per[key] += 1; q.append(path)
    json.dump(seen, open(f"{OUT}/discover_{user}.json", 'w'), indent=1)
    print(len(seen)); b.close(); p.stop()
def start_(user): return start(user, portal=bool(os.environ.get('QA_PORTAL')))
if __name__ == '__main__': main(sys.argv[1], sys.argv[2], sys.argv[3])
