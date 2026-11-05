// usage: node ev.js <url> '<js expr returning json>' [WxH] [dark]
const h = require('./h.js');
(async () => { const [url, expr, wh='1920x1080'] = process.argv.slice(2); const [W,H]=wh.split('x').map(Number);
 const b = await h.launch(); const c = await b.newContext({ viewport: { width: W, height: H }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 await p.goto(h.BASE+'/'+url); await p.waitForLoadState('networkidle').catch(()=>{}); await p.waitForTimeout(400);
 console.log(JSON.stringify(await p.evaluate(expr),null,1)); await b.close(); })();
