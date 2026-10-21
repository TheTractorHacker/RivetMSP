"""Phase B: click each (deduped) visible control on each page, classify the result.
Skips anything that looks destructive/outbound (never sends mail, never deletes seeded data)."""
import sys, json, re, os, time
from urllib.parse import urlparse
from ux_common import *

SKIP = re.compile(r'delete|archive|remove|trash|send|e-?mail|pay|mark|resolve|close ticket|restore|logout|sign out|sync|odoo|backup|update|run now|restart|test|reset|enable|disable|merge|convert|purge|clear|revoke|regenerate|rotate|import|upload|cancel|approve|reject|unsubscribe|impersonat|force|install|apply|resend|reopen|void|refund|charge|bill|invoice it|generate|cron|toggle|start|stop|lock|unlock|clone|duplicate|copy', re.I)
LIST_JS = r"""() => {
  const out = []; const seen = {};
  const els = document.querySelectorAll('.page-body a, .page-body button, .page-body [role=tab], .page-body [data-bs-toggle], .page-body .ajax-modal, .navbar a.dropdown-toggle');
  els.forEach((el, i) => {
    const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
    if (!r.width || !r.height || cs.visibility === 'hidden' || cs.display === 'none' || el.disabled) return;
    if (el.closest('.modal') ) return;
    const txt = (el.getAttribute('aria-label') || el.textContent || el.title || '').trim().replace(/\s+/g,' ').slice(0,40);
    const href = el.getAttribute('href') || ''; const murl = el.getAttribute('data-modal-url') || '';
    const key = el.tagName + '|' + txt + '|' + href.replace(/\d+/g,'N').split('?')[0] + '|' + murl.replace(/\d+/g,'N').split('?')[0];
    seen[key] = (seen[key]||0) + 1; if (seen[key] > 2) return;
    el.setAttribute('data-qa-idx', i);
    out.push({i, tag: el.tagName, txt, href, murl, type: el.type||'', form: !!el.closest('form'), cls: (el.className||'').toString().slice(0,60)});
  });
  return out;
}"""

def run(pages, user='admin', cap=int(os.environ.get("QA_CAP","14")), portal=False):
    p, b, ctx, pg = start(user, 1920, 1080, portal=portal)
    cons, bad = [], []
    pg.on('console', lambda m: cons.append(m.text[:140]) if m.type == 'error' else None)
    pg.on('response', lambda r: bad.append((r.status, r.url[:100])) if r.status >= 400 else None)
    pg.on('dialog', lambda d: d.dismiss())
    res = []
    for path in pages:
        pg.goto(BASE + path, wait_until='domcontentloaded'); pg.wait_for_timeout(1000)
        try: items = pg.evaluate(LIST_JS)
        except Exception as e: res.append({'page': path, 'error': str(e)[:80]}); continue
        n = 0
        for it in items:
            label = f"{it['txt']} {it['href']} {it['murl']}"
            if SKIP.search(label) or it['href'].startswith(('mailto', 'tel', 'javascript')) or 'post.php' in label: 
                res.append({'page': path, **it, 'result': 'skipped(unsafe/outbound)'}); continue
            if n >= cap: break
            n += 1
            cons.clear(); bad.clear()
            before = pg.url
            try:
                pg.locator(f"[data-qa-idx='{it['i']}']").first.click(timeout=3000)
                pg.wait_for_timeout(700)
            except Exception as e:
                res.append({'page': path, **it, 'result': 'CLICK-FAIL ' + str(e)[:60]}); continue
            r = {'page': path, **it}
            modal = pg.locator('.modal.show')
            if pg.url != before and not pg.url.endswith('#'):
                r['result'] = 'nav'; r['to'] = urlparse(pg.url).path + ('?' + urlparse(pg.url).query if urlparse(pg.url).query else '')
                txt = pg.evaluate("document.body.innerText.slice(0,4000)")
                m = re.search(r'(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|mysqli_sql_exception|Parse error|Stack trace|Whoops|404 Not Found|403 Forbidden)', txt)
                if m: r['rawerr'] = m.group(0)
                pg.goto(BASE + path, wait_until='domcontentloaded'); pg.wait_for_timeout(700)
                pg.evaluate(LIST_JS)
            elif modal.count():
                r['result'] = 'modal'; r['modal'] = (modal.first.locator('.modal-title').first.inner_text(timeout=1500) if modal.first.locator('.modal-title').count() else '')[:50]
                txt = modal.first.inner_text()
                m = re.search(r'(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|mysqli_sql_exception|Parse error|Stack trace)', txt)
                if m: r['rawerr'] = m.group(0)
                if not txt.strip(): r['result'] = 'modal-EMPTY'
                pg.keyboard.press('Escape'); pg.wait_for_timeout(400)
                if pg.locator('.modal.show').count():
                    pg.goto(BASE + path, wait_until='domcontentloaded'); pg.wait_for_timeout(700); pg.evaluate(LIST_JS)
            else:
                exp = pg.evaluate("(i)=>{const e=document.querySelector(`[data-qa-idx='${i}']`);return e?e.getAttribute('aria-expanded')||(e.nextElementSibling&&e.nextElementSibling.classList.contains('show')?'menu':''):''}", it['i'])
                r['result'] = 'toggle' if exp else 'no-visible-effect'
                if it['href'] == '#' or not it['href']: pass
            if cons: r['console'] = cons[:2]
            if bad: r['http'] = bad[:2]
            res.append(r)
    json.dump(res, open(f"{OUT}/click_{user}{'_portal' if portal else ''}.json", 'w'), indent=1)
    b.close(); p.stop()

if __name__ == '__main__':
    pages = json.load(open(sys.argv[1]))
    run(pages, sys.argv[2] if len(sys.argv) > 2 else 'admin', portal=(len(sys.argv) > 3 and sys.argv[3] == 'portal'))
