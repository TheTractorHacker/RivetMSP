const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 await p.goto(h.BASE + '/admin/settings_endpoint_agent.php'); await p.waitForLoadState('networkidle');
 await p.locator('button[name=rmm_module_switch][value=off]').click(); await p.waitForTimeout(700);
 await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/off_confirm.png'});
 const ok = p.locator('.modal.show .btn-primary, .modal.show .btn-danger').first(); if (await ok.count()) { await ok.click(); await p.waitForLoadState('networkidle'); await p.waitForTimeout(800); }
 console.log((await p.locator('.alert').first().innerText().catch(()=>'')).slice(0,100));
 for (const u of ['agent/rmm_fleet.php','agent/rmm_policies.php','agent/rmm_agent_device.php?device_id=1']) { const r = await p.goto(h.BASE+'/'+u); console.log(u, r.status(), (await p.innerText('.page-body')).replace(/\s+/g,' ').slice(0,120)); await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/off_'+u.replace(/[^a-z]/g,'_')+'.png'}); }
 await b.close(); })();
