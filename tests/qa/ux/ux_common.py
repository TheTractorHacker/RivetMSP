"""Shared helpers for the Rivet MSP UI/UX QA scripts (own server on 127.0.0.1:8191, demo data only)."""
import sys, os, json, re
sys.path.insert(0, os.environ.get('QA_PYLIBS', './pylibs'))
from playwright.sync_api import sync_playwright

BASE = os.environ.get('QA_BASE', 'http://127.0.0.1:8191')
OUT = os.environ.get('QA_OUT', './shots')
AXE = os.environ.get('QA_AXE', './axe.min.js')
USERS = {
    'admin': ('alex.morgan@northwind-it.example', 'DemoAdmin#2026'),
    'tech': ('priya.nair@northwind-it.example', 'DemoPass#2026'),
    'tech2': ('marcus.lee@northwind-it.example', 'DemoPass#2026'),
    'portal': ('qa251@qa.example', 'QaPortal#2026x'),
}

def start(user='admin', w=1920, h=1080, dark=False, portal=False):
    p = sync_playwright().start()
    b = p.chromium.launch()
    ctx = b.new_context(viewport={'width': w, 'height': h})
    pg = ctx.new_page()
    login(pg, user, portal)
    return p, b, ctx, pg

def login(pg, user, portal=False):
    email, pw = USERS[user]
    pg.goto(BASE + ('/client/login.php' if portal else '/login.php'))
    pg.fill('input[name=email]', email); pg.fill('input[name=password]', pw)
    pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('networkidle')

def set_theme(pg, dark):
    pg.evaluate("d=>{document.documentElement.setAttribute('data-bs-theme', d?'dark':'light'); document.body.classList.toggle('dark-mode', !!d)}", dark)

CHECK_JS = r"""() => {
  const r = {};
  const de = document.documentElement;
  r.hscroll = de.scrollWidth > de.clientWidth + 1 ? [de.scrollWidth, de.clientWidth] : null;
  r.h1 = document.querySelectorAll('h1').length;
  r.main = document.querySelectorAll('main, [role=main]').length;
  const small = []; const clipped = []; const noname = []; const nolabel = [];
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    const rc = el.getBoundingClientRect();
    if (!rc.width || !rc.height) continue;
    if (el.childNodes.length && [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) {
      if (parseFloat(cs.fontSize) < 11.5) small.push((el.className||el.tagName).toString().slice(0,40) + ':' + cs.fontSize + ':' + el.textContent.trim().slice(0,20));
      if (el.scrollWidth > el.clientWidth + 2 && cs.overflowX !== 'visible' && cs.textOverflow !== 'ellipsis' && !el.closest('.table-responsive,.dropdown-menu,pre,code,textarea'))
        clipped.push((el.className||el.tagName).toString().slice(0,40) + ':' + el.textContent.trim().slice(0,25));
    }
    if (el.matches('button, a.btn, a[role=button]') && !el.textContent.trim() && !el.getAttribute('aria-label') && !el.getAttribute('title') && !el.getAttribute('aria-labelledby'))
      noname.push((el.outerHTML).slice(0,90));
    if (el.matches('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea') && !el.closest('.ts-wrapper,.dropdown-menu')) {
      const id = el.id; const lab = (id && document.querySelector('label[for="'+id+'"]')) || el.closest('label');
      if (!lab && !el.getAttribute('aria-label') && !el.getAttribute('title') && !el.getAttribute('aria-labelledby') && !el.getAttribute('placeholder'))
        nolabel.push(el.outerHTML.slice(0,90));
    }
  }
  r.small = [...new Set(small)].slice(0,6); r.clipped = [...new Set(clipped)].slice(0,6);
  r.noname = noname.slice(0,6); r.nolabel = nolabel.slice(0,6);
  r.nameCounts = [noname.length, nolabel.length];
  // raw errors
  const t = document.body.innerText;
  r.rawerr = /(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|mysqli_sql_exception|Parse error)/.exec(t)?.[0] || null;
  return r;
}"""

def audit(pg, axe=True):
    res = pg.evaluate(CHECK_JS)
    if axe:
        try:
            pg.add_script_tag(path=AXE)
            ax = pg.evaluate("async()=>{const r=await axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag22aa','best-practice']});return r.violations.map(v=>({id:v.id,impact:v.impact,n:v.nodes.length}))}")
            res['axe'] = ax
        except Exception as e:
            res['axe'] = 'ERR ' + str(e)[:80]
    return res
