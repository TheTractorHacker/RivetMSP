const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 for (const f of process.argv.slice(2)) { const p = await c.newPage(); await p.goto(h.BASE + '/' + f); await p.waitForLoadState('networkidle').catch(()=>{});
  const r = await p.locator('.page-body form').evaluateAll(fs=>fs.map(fm=>({action:fm.getAttribute('action'),method:fm.method,els:[...fm.querySelectorAll('input:not([type=hidden]),select,textarea,button')].map(e=>e.tagName[0]+':'+(e.type||'')+':'+(e.name||e.id)+(e.required?'*':'')+(e.tagName==='SELECT'?'['+[...e.options].slice(0,4).map(o=>o.value).join('|')+']':''))})));
  console.log('== '+f); for (const x of r) console.log(JSON.stringify(x)); await p.close(); }
 await b.close(); })();
