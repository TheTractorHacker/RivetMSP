const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 await p.goto(h.BASE + '/admin/settings_endpoint_agent.php'); await p.waitForTimeout(600);
 await p.evaluate(()=>{ const a=document.getElementById('lg_on'); a.checked=true; a.dispatchEvent(new Event('change',{bubbles:true})); const k=document.getElementById('lg_accept'); if(k){k.checked=true;} document.querySelector('button[name=save_logs_settings]').click(); });
 await p.waitForLoadState('networkidle'); await p.waitForTimeout(800);
 console.log(await p.locator('#lg_on').isChecked(), (await p.locator('.alert').allInnerTexts()).join('|').slice(0,200));
 await b.close(); })();
