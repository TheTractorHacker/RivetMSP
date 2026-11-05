const h = require('./h.js');
(async () => { const b = await h.launch(); const c = await b.newContext({ viewport: { width: 1920, height: 1080 }, storageState:'/home/sysadmin/ui-sweep-rmm/shots/state.json' });
 const errs=[];
 async function go(url, fn, shot) { const p = await c.newPage(); p.on('pageerror',e=>errs.push(url+' '+String(e).slice(0,100))); p.on('console',m=>{if(m.type()==='error')errs.push(url+' '+m.text().slice(0,100))});
  await p.goto(h.BASE+'/'+url); await p.waitForLoadState('networkidle').catch(()=>{}); await fn(p); await p.waitForLoadState('networkidle').catch(()=>{}); await p.waitForTimeout(700);
  console.log(url,'->',p.url().replace(h.BASE,''),'|',(await p.locator('.alert,.toast,.invalid-feedback').allInnerTexts()).join(' // ').slice(0,260)); await p.screenshot({path:'/home/sysadmin/ui-sweep-rmm/shots/s1_'+shot+'.png'}); await p.close(); }
 const sub = p => p.locator('.page-body form [type=submit]').last().click();
 await go('agent/rmm_fields.php', async p=>{ await p.fill('[name=name]','Bad Name'); await p.fill('[name=label]','QA'); await p.locator('.page-body form [type=submit]').first().click(); }, 'field_bad');
 await go('agent/rmm_fields.php', async p=>{ await p.fill('[name=name]','qa_ui_field'); await p.fill('[name=label]','QA-UI field'); await p.fill('[name=default]','hello'); await p.locator('.page-body form [type=submit]').first().click(); }, 'field_ok');
 await go('agent/rmm_escalations.php?new=1', async p=>{ await p.fill('[name=name]','QA-UI-escalation'); await p.fill('[name="steps[0][after_min]"]','15'); await p.selectOption('[name="steps[0][targets][0][type]"]','email'); await p.fill('[name="steps[0][targets][0][ref_email]"]','qa@qa.example'); await sub(p); }, 'esc_ok');
 await go('agent/rmm_maintenance.php?new=1', async p=>{ await p.fill('[name=name]','QA-UI-window'); await p.fill('[name=starts_local]','2026-11-20T01:00'); await p.fill('[name=ends_local]','2026-11-20T03:00'); await sub(p); }, 'win_ok');
 await go('agent/rmm_log_rules.php?new=1', async p=>{ await p.fill('[name=name]','QA-UI-logrule'); await p.fill('[name=contains]','disk failure'); await sub(p); }, 'rule_ok');
 await go('agent/rmm_schedules.php?new=1', async p=>{ await p.fill('[name=name]','QA-UI-schedule'); await sub(p); }, 'sched_ok');
 console.log(errs); await b.close(); })();
