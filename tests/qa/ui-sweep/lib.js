const pw = require('/home/sysadmin/.npm/_npx/db89d7302a373f10/node_modules/playwright-core');
const fs = require('fs');
const CHROME = '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome';
const BASE = 'http://127.0.0.1:8211';
const IMG = '/home/sysadmin/ui-sweep-portal-img';
const AXE = fs.readFileSync('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/axe.min.js', 'utf8');
async function launch() { return pw.chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] }); }
const CHECK = `() => { const r={}; const de=document.documentElement;
 r.hscroll = de.scrollWidth > de.clientWidth+1 ? [de.scrollWidth,de.clientWidth]:null;
 const small=[],clipped=[],noname=[];
 for (const el of document.querySelectorAll('body *')) { const cs=getComputedStyle(el); if(cs.display==='none'||cs.visibility==='hidden')continue; const rc=el.getBoundingClientRect(); if(!rc.width||!rc.height)continue;
  if([...el.childNodes].some(n=>n.nodeType===3&&n.textContent.trim())){ if(parseFloat(cs.fontSize)<11.5)small.push((el.className||el.tagName).toString().slice(0,30)+':'+cs.fontSize);
   if(el.scrollWidth>el.clientWidth+2&&cs.overflowX!=='visible'&&cs.textOverflow!=='ellipsis'&&!el.closest('.table-responsive,.dropdown-menu,pre,code,textarea'))clipped.push((el.className||el.tagName).toString().slice(0,30)+':'+el.textContent.trim().slice(0,20));}
  if(el.matches('button,a.btn,a[role=button]')&&!el.textContent.trim()&&!el.getAttribute('aria-label')&&!el.getAttribute('title'))noname.push(el.outerHTML.slice(0,90)); }
 r.small=[...new Set(small)].slice(0,5); r.clipped=[...new Set(clipped)].slice(0,5); r.noname=noname.slice(0,4);
 const m=/(Fatal error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught|mysqli_sql_exception|Parse error|Undefined (variable|array|index))/.exec(document.body.innerText); r.rawerr=m?m[0]:null; return r; }`;
async function audit(page, axe) {
  const r = await page.evaluate("(" + CHECK + ")()");
  if (axe) { try { await page.evaluate(AXE); r.axe = await page.evaluate(`(async()=>{const x=await axe.run(document,{runOnly:['wcag2a','wcag2aa','best-practice']});return x.violations.map(v=>v.id+'/'+v.impact+'x'+v.nodes.length+':'+(v.nodes[0]&&v.nodes[0].target.join(' ').slice(0,50)))})()`); } catch (e) { r.axe = 'ERR'; } }
  return r;
}
module.exports = { pw, launch, audit, BASE, IMG, fs };
