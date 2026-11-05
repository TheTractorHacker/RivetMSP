// Node harness for the RivetMSP scan (Playwright python is unavailable: pip is blocked). Test copies only.
const pw = require('/home/sysadmin/.npm/_npx/db89d7302a373f10/node_modules/playwright-core');
const fs = require('fs');
const CHROME = '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome';
const BASE = process.env.QA_BASE || 'http://127.0.0.1:8218';
const AXE = fs.readFileSync(process.env.QA_AXE||'/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/axe.min.js','utf8');
const USERS = { admin: ['alex.morgan@northwind-it.example', 'DemoAdmin#2026'], tech: ['priya.nair@northwind-it.example', 'DemoPass#2026'], portal: ['office@hdg.example', 'ShotsPortal#2026'] };
async function launch() { return pw.chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] }); }
async function login(page, user, portal) {
  const [e, p] = USERS[user]; await page.goto(BASE + (portal ? '/client/login.php' : '/login.php'));
  await page.fill('input[name=email]', e); await page.fill('input[name=password]', p);
  await Promise.all([page.waitForLoadState('networkidle').catch(() => {}), page.locator('button[type=submit]').first().click()]);
}
const CHECK = `() => {
  const r = {}; const de = document.documentElement;
  r.hscroll = de.scrollWidth > de.clientWidth + 1 ? [de.scrollWidth, de.clientWidth] : null;
  r.h1 = document.querySelectorAll('h1').length; r.main = document.querySelectorAll('main, [role=main]').length;
  const small = [], clipped = [], noname = [], nolabel = [];
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    const rc = el.getBoundingClientRect(); if (!rc.width || !rc.height) continue;
    if ([...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) {
      if (parseFloat(cs.fontSize) < 11.5) small.push((el.className||el.tagName).toString().slice(0,30)+':'+cs.fontSize);
      if (el.scrollWidth > el.clientWidth + 2 && !el.classList.contains('visually-hidden') && cs.overflowX !== 'visible' && cs.textOverflow !== 'ellipsis' && !el.closest('.table-responsive,.dropdown-menu,pre,code,textarea')) clipped.push((el.className||el.tagName).toString().slice(0,30)+':'+el.textContent.trim().slice(0,20));
    }
    if (el.matches('button, a.btn, a[role=button]') && !el.textContent.trim() && !el.getAttribute('aria-label') && !el.getAttribute('title') && !el.getAttribute('aria-labelledby')) noname.push(el.outerHTML.slice(0,80));
  }
  r.small = [...new Set(small)].slice(0,5); r.clipped = [...new Set(clipped)].slice(0,5); r.noname = noname.slice(0,4);
  const t = document.body.innerText; const m = /(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|mysqli_sql_exception|Parse error)/.exec(t); r.rawerr = m ? m[0] : null;
  r.themeattr = de.getAttribute('data-bs-theme'); return r; }`;
async function audit(page, withAxe) {
  const r = await page.evaluate("(" + CHECK + ")()");
  if (withAxe) { try { await page.evaluate(AXE); r.axe = await page.evaluate(`(async()=>{const x=await axe.run(document,{runOnly:['wcag2a','wcag2aa','wcag22aa','best-practice']});return x.violations.map(v=>({id:v.id,impact:v.impact,n:v.nodes.length}))})()`); } catch (e) { r.axe = 'ERR ' + String(e).slice(0, 60); } }
  return r;
}
module.exports = { pw, launch, login, audit, BASE, USERS };
