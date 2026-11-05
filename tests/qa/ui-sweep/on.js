const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 await p.goto(h.BASE + '/admin/settings_endpoint_agent.php'); await p.waitForTimeout(1000);
 await p.locator('button[name=rmm_module_switch][value=on]').click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1500);
 console.log(p.url(), (await p.innerText('.alert').catch(()=>'')).slice(0,200));
 await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/ea_after.png', fullPage:false});
 await b.close(); })();
