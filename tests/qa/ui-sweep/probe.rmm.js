const h = require('./h.js'); const fs=require('fs');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 for (const f of process.argv.slice(2)) { const p = await c.newPage(); const r = await p.goto(h.BASE + '/' + f).catch(e=>null); await p.waitForTimeout(500);
  const t = (await p.innerText('body')).replace(/\s+/g,' ').slice(0,110); console.log(f, r&&r.status(), p.url().replace(h.BASE,''), '|', await p.title(), '|', t.slice(0,0)); await p.close(); }
 await b.close(); })();
