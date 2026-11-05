const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 } }); const p = await c.newPage(); await h.login(p, 'admin');
 console.log(p.url()); await c.storageState({ path: '/home/sysadmin/ui-sweep-rmm/shots/state.json' }); await b.close(); })();
