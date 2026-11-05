// usage: node clicks.js <url> <tag> [W x H] ; clicks each visible btn/a[data-bs-toggle] "New ..." type opener; prints modal info and screenshots
const h = require('./h.js');
(async () => { const [url, tag, wh='1920x1080', sel] = process.argv.slice(2); const [W,H]=wh.split('x').map(Number);
 const b = await h.launch(); const c = await b.newContext({ viewport: { width: W, height: H }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,120))); p.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,120))});
 await p.goto(h.BASE+'/'+url); await p.waitForLoadState('networkidle').catch(()=>{});
 const els = await p.locator(sel||'.page-body [data-bs-toggle=modal], .page-body a[href*="new"], .page-body button:visible').evaluateAll(e=>e.map((x,i)=>i+': '+x.tagName+' '+(x.innerText||x.getAttribute('aria-label')||'').trim().slice(0,30)+' | '+(x.getAttribute('data-bs-target')||x.getAttribute('href')||x.getAttribute('type')||'')));
 console.log(els.join('\n')); console.log('errs',errs);
 await b.close(); })();
