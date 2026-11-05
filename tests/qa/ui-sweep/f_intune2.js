const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 await p.goto(h.BASE+'/agent/intune_settings.php?client_id=1'); await p.waitForLoadState('networkidle');
 await p.locator('a.confirm-link:has-text("Remove")').click(); await p.waitForTimeout(800);
 await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/f_intune_confirm.png'});
 const ok = p.locator('.modal.show button:has-text("Yes"), .modal.show a:has-text("Yes"), .modal.show .btn-danger, .modal.show .btn-primary').first();
 console.log('modal btn', await ok.count()); if (await ok.count()) { await ok.click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(600); }
 console.log(p.url().replace(h.BASE,''), await p.inputValue('#msTenantId')); await b.close(); })();
