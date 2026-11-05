const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 const errs=[];
 async function go(url, fn, shot) { const p = await c.newPage(); p.on('pageerror',e=>errs.push(url+' '+String(e).slice(0,100))); p.on('console',m=>{if(m.type()==='error')errs.push(url+' '+m.text().slice(0,100))});
  await p.goto(h.BASE+'/'+url); await p.waitForLoadState('networkidle').catch(()=>{}); await fn(p); await p.waitForLoadState('networkidle').catch(()=>{}); await p.waitForTimeout(700);
  console.log(url,'->',p.url().replace(h.BASE,''),'|',(await p.locator('.alert,.toast,.invalid-feedback').allInnerTexts()).join(' // ').slice(0,260)); await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/s1_'+shot+'.png'}); await p.close(); }
 const sub = p => p.locator('.page-body form [type=submit]').last().click();
 await go('agent/rmm_schedules.php?new=1', async p=>{ await p.fill('[name=name]','QA-UI-schedule'); await p.locator('[name=enabled]').uncheck(); await sub(p); }, 'sched_ok');
 await go('agent/rmm_policies.php?new=1', async p=>{ await p.fill('[name=name]','QA-UI-policy'); await p.fill('[name=description]','qa'); await sub(p); }, 'pol_ok');
 await go('agent/rmm_patch_policies.php?new=1', async p=>{ await p.fill('[name=name]','QA-UI-patchpolicy'); await sub(p); }, 'patchpol_ok');
 await go('agent/rmm_patch_policies.php', async p=>{ const f=p.locator('form:has([name=delay_days])'); await f.locator('[name=name]').fill('QA-UI-ring'); await f.locator('[name=delay_days]').fill('3'); await f.locator('[type=submit]').click(); }, 'ring_ok');
 await go('agent/rmm_patch_policies.php', async p=>{ const f=p.locator('form:has([name=after_days])'); await f.locator('[name=name]').fill('QA-UI-rule'); await f.locator('[type=submit]').click(); }, 'rule_ok');
 await go('agent/rmm_software_catalog.php?new=1', async p=>{ const f=p.locator('form:has([name=publisher])'); await f.locator('[name=name]').fill('QA-UI-package'); await f.locator('[name=publisher]').fill('QA Pub'); await f.locator('[type=submit]').click(); }, 'pkg_ok');
 await go('agent/rmm_software_catalog.php', async p=>{ const f=p.locator('form:has([name=host])'); await f.locator('[name=host]').fill('qa-ui.example.com'); await f.locator('[type=submit]').click(); }, 'host_ok');
 console.log(errs); await b.close(); })();
