const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 const seen=new Set();
 for (const f of process.argv.slice(2)) { const p = await c.newPage(); await p.goto(h.BASE + '/' + f); await p.waitForLoadState('networkidle').catch(()=>{});
  const l = await p.locator('.page-body a[href], .page-body [data-bs-toggle]').evaluateAll(e=>e.map(x=>(x.getAttribute('href')||'')+' ['+(x.getAttribute('data-bs-toggle')||'')+':'+(x.getAttribute('data-bs-target')||'')+'] '+x.innerText.trim().slice(0,25).replace(/\n/g,' ')));
  console.log('== '+f); for (const x of [...new Set(l)]) console.log('  '+x); await p.close(); }
 await b.close(); })();
