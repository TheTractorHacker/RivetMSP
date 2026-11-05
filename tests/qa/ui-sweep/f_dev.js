const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' }); const p = await c.newPage();
 const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,120))); p.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,120))});
 await p.goto(h.BASE+'/agent/rmm_agent_device.php?device_id=1'); await p.waitForLoadState('networkidle');
 const btn = p.locator('button:has-text("Queue job")'); console.log('queue btn', await btn.count());
 await btn.click(); await p.waitForTimeout(800);
 console.log('after empty queue', await p.evaluate(()=>document.activeElement.name+':'+document.activeElement.validationMessage), (await p.locator('#ea-msg,.alert,.toast').allInnerTexts()).join('|').slice(0,200));
 await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/f_dev_queue.png'});
 // mesh mapping invalid
 await p.fill('#ea-node','garbage'); await p.locator('#ea-mesh-form button').click(); await p.waitForTimeout(1000);
 console.log('mesh invalid', (await p.locator('#ea-msg').allInnerTexts()).join('|'), await p.locator('#ea-msg').getAttribute('class'));
 await p.locator('#ea-msg').scrollIntoViewIfNeeded(); await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/f_dev_mesh.png'});
 console.log(errs); await b.close(); })();
