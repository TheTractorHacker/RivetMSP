const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 await p.goto(h.BASE+'/agent/rmm_fleet.php'); await p.waitForLoadState('networkidle').catch(()=>{});
 await p.locator('[data-bs-target="#rmmInstallerModal"]').first().click(); await p.waitForTimeout(800);
 await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/modal_installer.png'}); await b.close(); })();
