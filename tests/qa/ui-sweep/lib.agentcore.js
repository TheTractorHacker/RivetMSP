// UI sweep harness (agentcore). Test-only.
const pw = require('/home/sysadmin/.npm/_npx/db89d7302a373f10/node_modules/playwright-core');
const fs = require('fs');
const CHROME = '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome';
const BASE = 'http://127.0.0.1:8212';
const SCR = '/home/sysadmin/ui-sweep-agentcore-scratch';
const AXE = fs.readFileSync('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/axe.min.js', 'utf8');
const USERS = { admin: ['alex.morgan@northwind-it.example', 'DemoAdmin#2026'], tech: ['priya.nair@northwind-it.example', 'DemoPass#2026'] };
async function launch() { return pw.chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] }); }
async function ensureLogin(b, user) {
  const sf = `${SCR}/state_${user}.json`;
  if (fs.existsSync(sf)) return sf;
  const ctx = await b.newContext(); const p = await ctx.newPage(); const [e, pw_] = USERS[user];
  await p.goto(BASE + '/login.php'); await p.locator('input[name=email]:visible').first().fill(e);
  await p.locator('input[name=password]:visible').first().fill(pw_);
  await Promise.all([p.waitForLoadState('networkidle').catch(() => {}), p.locator('button[type=submit]:visible').first().click()]);
  console.log('login', user, p.url()); await ctx.storageState({ path: sf }); await ctx.close(); return sf;
}
async function ctxFor(b, user, vp) { const sf = await ensureLogin(b, user); return b.newContext({ storageState: sf, viewport: vp || { width: 1920, height: 1080 } }); }
const CHECK = `() => {
  const r = {}; const de = document.documentElement;
  r.hscroll = de.scrollWidth > de.clientWidth + 1 ? [de.scrollWidth, de.clientWidth] : null;
  const small = [], clipped = [], noname = [], nolabel=[];
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    const rc = el.getBoundingClientRect(); if (!rc.width || !rc.height) continue;
    if ([...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) {
      if (parseFloat(cs.fontSize) < 11.5) small.push((el.className||el.tagName).toString().slice(0,30)+':'+cs.fontSize);
      if (el.scrollWidth > el.clientWidth + 2 && cs.overflowX !== 'visible' && cs.textOverflow !== 'ellipsis' && !el.closest('.table-responsive,.dropdown-menu,pre,code,textarea,.visually-hidden')) clipped.push((el.className||el.tagName).toString().slice(0,30)+':'+el.textContent.trim().slice(0,20));
    }
    if (el.matches('button, a.btn, a[role=button], a.nav-link') && !el.textContent.trim() && !el.getAttribute('aria-label') && !el.getAttribute('title') && !el.getAttribute('aria-labelledby') && !el.getAttribute('data-bs-original-title')) noname.push(el.outerHTML.slice(0,90));
  }
  r.small = [...new Set(small)].slice(0,5); r.clipped = [...new Set(clipped)].slice(0,5); r.noname = noname.slice(0,4);
  const t = document.body.innerText; const m = /(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|mysqli_sql_exception|Parse error)/.exec(t); r.rawerr = m ? m[0] : null;
  r.theme = de.getAttribute('data-bs-theme'); return r; }`;
async function audit(page, axe) {
  const r = await page.evaluate("(" + CHECK + ")()");
  if (axe) { try { await page.evaluate(AXE); r.axe = await page.evaluate(`(async()=>{const x=await axe.run(document,{runOnly:['wcag2a','wcag2aa']});return x.violations.map(v=>({id:v.id,impact:v.impact,n:v.nodes.length,ex:(v.nodes[0]&&v.nodes[0].target||[]).join(' ').slice(0,70)}))})()`); } catch (e) { r.axe = 'ERR'; } }
  return r;
}
function watch(page, log) {
  page.on('console', m => { if (m.type() === 'error') log.push('console: ' + m.text().slice(0, 140)); });
  page.on('pageerror', e => log.push('pageerror: ' + String(e).slice(0, 140)));
  page.on('response', r => { if (r.status() >= 400) log.push(`http ${r.status()} ${r.url().replace(BASE, '').slice(0, 100)}`); });
}
module.exports = { pw, launch, ctxFor, audit, watch, BASE, SCR };
// set a TomSelect/select by visible label substring
module.exports.setSel = async (p, scope, name, label) => p.evaluate(([scope, name, label]) => {
  const el = document.querySelector(scope + ' select[name="' + name + '"]'); if (!el) return 'noel';
  const opt = [...el.options].find(o => o.textContent.includes(label)); if (!opt) return 'noopt:' + [...el.options].map(o => o.textContent).slice(0, 5).join('|');
  if (el.tomselect) el.tomselect.setValue(opt.value); else { el.value = opt.value; el.dispatchEvent(new Event('change', { bubbles: true })); } return 'ok';
}, [scope, name, label]);
